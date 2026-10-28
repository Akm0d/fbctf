#!/usr/bin/env python3
"""Live Migi chat checks with temporary challenge data and targeted cleanup."""

from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import urllib.error
import urllib.parse
import urllib.request

from music_smoke import Client, token


ROOT = Path(__file__).resolve().parents[2]
RUNTIME = ROOT / ".runtime"
COMPOSE = [
    "docker", "compose", "--env-file", str(RUNTIME / "env"),
    "-f", str(ROOT / "compose.compat.yml"),
]
ENDPOINT = "/index.php?p=migi&ajax=true"


def expect(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)
    print(f"PASS: {message}")


def sql(query: str) -> list[list[str]]:
    result = subprocess.run(
        COMPOSE + [
            "exec", "-T", "db", "sh", "-c",
            'exec mariadb -ufbctf --password="$(cat /run/secrets/db_password)" '
            "--batch --skip-column-names fbctf",
        ],
        cwd=ROOT, input=query, text=True, capture_output=True, check=True,
    )
    return [line.split("\t") for line in result.stdout.splitlines() if line]


def compose(*args: str) -> None:
    subprocess.run(COMPOSE + list(args), cwd=ROOT, check=True, capture_output=True)


def login() -> tuple[Client, str]:
    client = Client()
    result = client.post("/index.php?p=index&ajax=true", {
        "action": "login_team",
        "team_name": "admin",
        "password": (RUNTIME / "secrets" / "admin_password").read_text().strip(),
    })
    expect(result.get("result") == "OK", "administrator team authenticates")
    return client, token(client.get("/index.php?p=game"))


def chat(
    client: Client,
    csrf: str,
    level_id: int,
    action: str,
    message: str = "",
    **extra: object,
) -> tuple[dict[str, object], bytes]:
    values: dict[str, object] = {
        "action": action,
        "csrf_token": csrf,
        "level_id": level_id,
        "message": message,
    }
    values.update(extra)
    port = next(
        line.split("=", 1)[1]
        for line in (RUNTIME / "env").read_text().splitlines()
        if line.startswith("FBCTF_PORT=")
    )
    request = urllib.request.Request(
        f"http://127.0.0.1:{port}{ENDPOINT}",
        data=urllib.parse.urlencode(values).encode(),
        method="POST",
    )
    raw = client.opener.open(request, timeout=90).read()
    return json.loads(raw), raw


def main() -> None:
    expect(
        "MIGI_CHAT_ENABLED=1" in (RUNTIME / "env").read_text().splitlines(),
        "Migi chat is enabled in protected runtime configuration",
    )
    category = sql("SELECT id FROM categories WHERE LOWER(category)='migi' LIMIT 1")
    created_category = not category
    if created_category:
        sql("INSERT INTO categories(category,protected,created_ts) VALUES('Migi',0,NOW())")
        category = sql("SELECT id FROM categories WHERE category='Migi' LIMIT 1")
    category_id = int(category[0][0])
    country_id = int(sql(
        "SELECT id FROM countries WHERE id NOT IN "
        "(SELECT entity_id FROM levels) ORDER BY id LIMIT 1",
    )[0][0])
    title = f"Migi Smoke {os.getpid()}"
    safe_title = title.replace("'", "''")
    sql(
        "INSERT INTO levels(active,type,title,description,entity_id,category_id,"
        "points,bonus,bonus_dec,bonus_fix,flag,hint,penalty,created_ts) VALUES"
        f"(1,'flag','{safe_title}','Temporary non-scoring Migi relay test',"
        f"{country_id},{category_id},0,0,0,0,'MIGI-SMOKE-NOT-SUBMITTED','',0,NOW())",
    )
    level_id = int(sql(f"SELECT id FROM levels WHERE title='{safe_title}'")[0][0])
    compose("restart", "cache")

    first: Client | None = None
    second: Client | None = None
    score_before = sql("CHECKSUM TABLE teams,scores_log,failure_log")
    secret = (RUNTIME / "secrets" / "migi_hermes_api_key").read_bytes().strip()
    observed: list[bytes] = []
    try:
        port = next(
            line.split("=", 1)[1]
            for line in (RUNTIME / "env").read_text().splitlines()
            if line.startswith("FBCTF_PORT=")
        )

        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, headers, newurl):  # type: ignore[no-untyped-def]
                return None

        anonymous = urllib.request.build_opener(NoRedirect())
        try:
            anonymous.open(
                urllib.request.Request(
                    f"http://127.0.0.1:{port}{ENDPOINT}",
                    data=urllib.parse.urlencode({
                        "action": "history", "csrf_token": "invalid", "level_id": level_id,
                    }).encode(),
                    method="POST",
                ),
                timeout=15,
            )
            raise AssertionError("unauthenticated Migi request succeeded")
        except urllib.error.HTTPError as exc:
            expect(exc.code == 302, "unauthenticated chat request is rejected")

        first, csrf_first = login()
        second, csrf_second = login()

        gameboard = first.get("/index.php?p=game")
        country_data_raw = first.get("/data/country-data.php")
        observed.extend((gameboard, country_data_raw))
        country_data = json.loads(country_data_raw)
        migi_row = next(row for row in country_data.values() if row.get("level_id") == level_id)
        expect(
            migi_row.get("type") == "flag" and migi_row.get("category") == "Migi",
            "temporary challenge uses the ordinary flag and category systems",
        )
        expect(migi_row.get("migi_chat") is True, "Migi flag advertises chat availability")
        expect(
            all(
                not row.get("migi_chat")
                for row in country_data.values()
                if row.get("level_id") != level_id
                and not (row.get("type") == "flag" and str(row.get("category", "")).lower() == "migi")
            ),
            "ordinary challenges do not advertise Migi chat",
        )
        modal = first.get("/index.php?p=country&modal=capture")
        observed.append(modal)
        modal_source = (
            ROOT / "src/controllers/modals/CountryModalController.php"
        ).read_bytes()
        built_javascript_has_chat = subprocess.run(
            COMPOSE + [
                "exec", "-T", "web", "grep", "-q", "MigiChat.configure",
                "/var/www/fbctf/src/static/dist/js/app.js",
            ],
            cwd=ROOT,
        ).returncode == 0
        expect(
            b"Chat with Migi" in modal_source
            and b'name="answer"' in modal_source
            and b"Submit" in modal_source
            and built_javascript_has_chat,
            "built challenge window contains chat alongside the normal answer controls",
        )

        invalid, invalid_raw = chat(first, "invalid", level_id, "history")
        observed.append(invalid_raw)
        expect(invalid.get("result") == "ERROR", "CSRF-invalid chat request is rejected")

        first_history, raw = chat(first, csrf_first, level_id, "history")
        observed.append(raw)
        expect(first_history.get("history") == [], "first browser starts with empty history")

        first_reply, raw = chat(
            first,
            csrf_first,
            level_id,
            "send",
            "Introduce yourself briefly without sharing any codes or secrets.",
            team_id=999999,
            profile="default",
        )
        observed.append(raw)
        expect(
            first_reply.get("result") == "OK" and len(first_reply.get("history", [])) == 2,
            "first browser receives a Migi reply",
        )

        second_history, raw = chat(second, csrf_second, level_id, "history")
        observed.append(raw)
        expect(second_history.get("history") == [], "second browser session has independent history")

        follow_up, raw = chat(
            first,
            csrf_first,
            level_id,
            "send",
            "What did I ask in my previous message? Do not include codes or secrets.",
        )
        observed.append(raw)
        expect(
            follow_up.get("result") == "OK" and len(follow_up.get("history", [])) == 4,
            "first browser retains bounded multi-turn context",
        )
        expect(sql("CHECKSUM TABLE teams,scores_log,failure_log") == score_before,
               "chat alone does not alter scores or failures")
        expect(all(secret not in payload for payload in observed),
               "Hermes API credential never appears in browser responses")

        cleared, raw = chat(first, csrf_first, level_id, "clear")
        observed.append(raw)
        expect(cleared.get("history") == [], "player can clear the browser-session conversation")

        compose("stop", "migi-relay")
        unavailable, _ = chat(
            second,
            csrf_second,
            level_id,
            "send",
            "This request should fail safely.",
        )
        expect(
            unavailable.get("result") == "ERROR"
            and "unavailable" in str(unavailable.get("message", "")).lower(),
            "offline relay produces a friendly unavailable response",
        )
        expect(first.get("/index.php?p=game"), "offline relay does not break the gameboard")
    finally:
        compose("up", "-d", "migi-relay")
        if first is not None:
            first.logout()
        if second is not None:
            second.logout()
        sql(f"DELETE FROM levels WHERE id={level_id}")
        if created_category:
            sql(f"DELETE FROM categories WHERE id={category_id}")
        compose("restart", "cache")

    expect(sql(f"SELECT COUNT(*) FROM levels WHERE id={level_id}") == [["0"]],
           "temporary Migi challenge was removed")
    expect(sql("CHECKSUM TABLE teams,scores_log,failure_log") == score_before,
           "live scoring state was restored exactly")


if __name__ == "__main__":
    main()
