#!/usr/bin/env python3
"""Narrow authenticated relay from FBCTF to one configured Hermes profile."""

from __future__ import annotations

import json
import os
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any


MAX_REQUEST_BYTES = 64 * 1024
MAX_MESSAGES = 25
MAX_CONTENT_CHARS = 6_000


def required_env(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"{name} must be configured")
    return value


def read_secret(env_name: str) -> str:
    path = Path(required_env(env_name))
    value = path.read_text(encoding="utf-8").strip()
    if not value:
        raise RuntimeError(f"secret file for {env_name} is empty")
    return value


PROFILE = required_env("MIGI_HERMES_PROFILE")
if PROFILE != "migi-fbctf":
    raise RuntimeError("relay is restricted to the migi-fbctf profile")

HERMES_ENDPOINT = required_env("MIGI_HERMES_ENDPOINT")
HERMES_MODEL = required_env("MIGI_HERMES_MODEL")
HERMES_KEY = read_secret("MIGI_HERMES_API_KEY_FILE")
REQUEST_TIMEOUT = max(5, min(180, int(os.environ.get("MIGI_REQUEST_TIMEOUT", "60"))))
OUTPUT_TOKENS = max(64, min(2_048, int(os.environ.get("MIGI_OUTPUT_MAX_TOKENS", "768"))))


def error_payload(message: str) -> bytes:
    return json.dumps({"error": message}, separators=(",", ":")).encode("utf-8")


def validate_messages(raw: Any) -> list[dict[str, str]]:
    if not isinstance(raw, list) or not raw or len(raw) > MAX_MESSAGES:
        raise ValueError("invalid conversation history")
    messages: list[dict[str, str]] = []
    for item in raw:
        if not isinstance(item, dict) or item.get("role") not in {"system", "user", "assistant"}:
            raise ValueError("invalid conversation message")
        content = item.get("content")
        if not isinstance(content, str) or not content or len(content) > MAX_CONTENT_CHARS:
            raise ValueError("invalid conversation content")
        messages.append({"role": item["role"], "content": content})
    return messages


class RelayHandler(BaseHTTPRequestHandler):
    server_version = "FBCTFMigiRelay/1"

    def log_message(self, format: str, *args: object) -> None:
        # Never log request bodies, model output, prompts, or credentials.
        print(f"migi-relay: {self.address_string()} {format % args}", flush=True)

    def send_json(self, status: int, payload: bytes) -> None:
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def do_GET(self) -> None:  # noqa: N802
        if self.path != "/health":
            self.send_json(404, error_payload("not found"))
            return
        payload = json.dumps(
            {"status": "ok", "profile": PROFILE, "model": HERMES_MODEL},
            separators=(",", ":"),
        ).encode("utf-8")
        self.send_json(200, payload)

    def do_POST(self) -> None:  # noqa: N802
        if self.path != "/chat":
            self.send_json(404, error_payload("not found"))
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            length = 0
        if length < 1 or length > MAX_REQUEST_BYTES:
            self.send_json(413, error_payload("invalid request size"))
            return
        try:
            body = json.loads(self.rfile.read(length))
            messages = validate_messages(body.get("messages") if isinstance(body, dict) else None)
        except (json.JSONDecodeError, UnicodeDecodeError, ValueError) as exc:
            self.send_json(400, error_payload(str(exc)))
            return

        upstream_body = json.dumps(
            {
                "model": HERMES_MODEL,
                "messages": messages,
                "stream": False,
                "max_tokens": OUTPUT_TOKENS,
            },
            separators=(",", ":"),
        ).encode("utf-8")
        request = urllib.request.Request(
            HERMES_ENDPOINT,
            data=upstream_body,
            headers={
                "Authorization": f"Bearer {HERMES_KEY}",
                "Content-Type": "application/json",
                "User-Agent": "fbctf-migi-relay/1",
            },
            method="POST",
        )
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        try:
            with opener.open(request, timeout=REQUEST_TIMEOUT) as response:
                upstream = json.loads(response.read(MAX_REQUEST_BYTES))
            choices = upstream.get("choices") if isinstance(upstream, dict) else None
            message = choices[0].get("message") if isinstance(choices, list) and choices else None
            content = message.get("content") if isinstance(message, dict) else None
            if not isinstance(content, str) or not content.strip():
                raise ValueError("Hermes returned no message")
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, ValueError, KeyError) as exc:
            # The exception is deliberately not serialized: upstream bodies can contain model output.
            print(f"migi-relay: upstream request failed ({type(exc).__name__})", flush=True)
            self.send_json(502, error_payload("Migi is unavailable right now"))
            return

        payload = json.dumps({"message": content}, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        self.send_json(200, payload)


if __name__ == "__main__":
    server = ThreadingHTTPServer(("0.0.0.0", 8090), RelayHandler)
    server.serve_forever()
