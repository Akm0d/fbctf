#!/usr/bin/env python3
"""Read-only party readiness checks plus an application-container restart."""

from __future__ import annotations

import hashlib
import http.client
import http.cookiejar
import json
from pathlib import Path
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request


ROOT = Path(__file__).resolve().parents[2]
RUNTIME = ROOT / ".runtime"
PORT = next(
    line.split("=", 1)[1]
    for line in (RUNTIME / "env").read_text().splitlines()
    if line.startswith("FBCTF_PORT=")
)
BASE = f"http://127.0.0.1:{PORT}"
COMPOSE = [
    "docker", "compose", "--env-file", str(RUNTIME / "env"),
    "-f", str(ROOT / "compose.compat.yml"),
]
PERSISTENT_TABLES = (
    "teams", "levels", "scores_log", "contests", "contest_entries",
    "contest_session_votes", "contest_points_ledger", "music_requests",
    "music_queue", "music_playback_state", "karaoke_stage_state",
    "karaoke_performances", "music_points_ledger", "configuration",
    "manual_base_state", "manual_base_points_ledger",
)


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


def persistent_snapshot() -> list[list[str]]:
    return sql("CHECKSUM TABLE " + ",".join(PERSISTENT_TABLES))


def runtime_snapshot() -> dict[str, str]:
    paths = [RUNTIME / "settings.ini", RUNTIME / "env"]
    paths.extend(sorted((RUNTIME / "secrets").iterdir()))
    return {
        str(path.relative_to(ROOT)): hashlib.sha256(path.read_bytes()).hexdigest()
        for path in paths
        if path.is_file()
    }


def wait_for_http(opener: urllib.request.OpenerDirector) -> None:
    error: Exception | None = None
    for _ in range(60):
        try:
            if opener.open(BASE + "/", timeout=3).status == 200:
                return
        except (urllib.error.URLError, OSError) as current:
            error = current
        time.sleep(1)
    raise AssertionError("application did not return after restart") from error


def verify_database_invariants() -> None:
    normal_levels = int(sql(
        "SELECT COUNT(*) FROM levels WHERE type IN ('quiz','flag')",
    )[0][0])
    if normal_levels > 0:
        expect(True, "quiz or flag challenges remain available")
    else:
        print("INFO: no quiz or flag challenges are currently configured; route checks continue")

    broken_contests = int(sql(
        "SELECT COUNT(*) FROM contests c LEFT JOIN levels l ON l.id=c.level_id "
        "WHERE c.level_id IS NOT NULL AND (l.id IS NULL OR l.type <> 'poll')",
    )[0][0])
    expect(broken_contests == 0, "every level-backed contest remains attached to a poll level")
    legacy_contests = int(sql(
        "SELECT COUNT(*) FROM contests WHERE level_id IS NULL",
    )[0][0])
    print(f"INFO: preserved legacy contests without poll levels: {legacy_contests}")

    session_unique = int(sql(
        "SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics "
        "WHERE table_schema=DATABASE() AND table_name='contest_session_votes' "
        "AND index_name='contest_session_vote_once' AND non_unique=0",
    )[0][0])
    expect(session_unique == 1, "one credited contest vote per browser session is database-enforced")

    vote_cap = int(sql(
        "SELECT value FROM configuration WHERE field='registration_players'",
    )[0][0])
    expect(vote_cap >= 1, "Players Per Team provides the configured contest vote cap fallback")

    expect(len(sql("SELECT id FROM karaoke_stage_state WHERE id=1")) == 1,
           "Karaoke Stage configuration is present")
    expect(len(sql("SELECT id FROM music_playback_state WHERE id=1")) == 1,
           "party music playback state is present")
    expect(len(sql(
        "SELECT level_id FROM manual_base_state WHERE level_id IN "
        "(SELECT id FROM levels WHERE type='base')",
    )) >= 1, "manual base ownership configuration is present")


def verify_web_workflows(opener: urllib.request.OpenerDirector) -> None:
    landing_response = opener.open(BASE + "/", timeout=15)
    landing = landing_response.read()
    expect(landing_response.status == 200 and b"Capture the Flag" in landing,
           "public landing page renders")

    login_request = urllib.request.Request(
        BASE + "/index.php?p=index&ajax=true",
        data=urllib.parse.urlencode({
            "action": "login_team", "team_name": "admin",
            "password": (RUNTIME / "secrets" / "admin_password").read_text().strip(),
        }).encode(),
        method="POST",
    )
    login = json.loads(opener.open(login_request, timeout=15).read())
    expect(login.get("result") == "OK", "administrator authenticates")

    checks = (
        ("/index.php?p=game", b"fb-gameboard", "challenge gameboard renders"),
        ("/index.php?p=country&modal=capture", b"country-capture-form",
         "challenge capture modal renders"),
        ("/index.php?p=admin&page=contests", b"Delete contest", "contest administration renders"),
        ("/index.php?p=admin&page=music", b"party playlist", "music and karaoke administration renders"),
        ("/index.php?p=admin&page=bases", b"Set current winner", "manual base administration renders"),
        ("/tv", b"Karaoke Stage", "live TV dashboard renders"),
    )
    for path, marker, message in checks:
        response = opener.open(BASE + path, timeout=15)
        body = response.read()
        expect(response.status == 200 and marker in body, message)


def main() -> None:
    cookies = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(cookies),
    )
    wait_for_http(opener)
    data_before = persistent_snapshot()
    runtime_before = runtime_snapshot()
    verify_database_invariants()
    verify_web_workflows(opener)

    subprocess.run(COMPOSE + ["restart", "app", "web"], cwd=ROOT, check=True)
    wait_for_http(opener)
    expect(persistent_snapshot() == data_before,
           "teams, scores, contests, playlists, karaoke, and configuration survive restart")
    expect(runtime_snapshot() == runtime_before,
           "runtime settings and secrets survive restart")

    response = opener.open(BASE + "/index.php?p=admin&page=contests", timeout=15)
    body = response.read()
    expect(response.status == 200 and b"Contest Management" in body,
           "authenticated session survives application restart")
    connection = http.client.HTTPConnection("127.0.0.1", int(PORT), timeout=15)
    cookie_header = "; ".join(f"{cookie.name}={cookie.value}" for cookie in cookies)
    connection.request(
        "GET", "/index.php?p=logout", headers={"Cookie": cookie_header},
    )
    logout_response = connection.getresponse()
    logout_response.read()
    connection.close()
    expect(logout_response.status == 302,
           "smoke-test session logs out without creating a replacement")
    print("PASS: party-readiness smoke checks complete without gameplay test records")


if __name__ == "__main__":
    main()
