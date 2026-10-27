#!/usr/bin/env python3
"""End-to-end smoke test for the local FBCTF compatibility deployment."""

from __future__ import annotations

import http.cookiejar
import json
import os
from pathlib import Path
import re
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request


ROOT = Path(__file__).resolve().parents[2]
RUNTIME = ROOT / ".runtime"
PORT = "8080"
for line in (RUNTIME / "env").read_text().splitlines():
    if line.startswith("FBCTF_PORT="):
        PORT = line.split("=", 1)[1]
BASE = f"http://127.0.0.1:{PORT}"
COMPOSE = [
    "docker",
    "compose",
    "--env-file",
    str(RUNTIME / "env"),
    "-f",
    str(ROOT / "compose.compat.yml"),
]


class Client:
    def __init__(self) -> None:
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cookies)
        )

    def get(self, path: str) -> tuple[int, bytes, object]:
        response = self.opener.open(BASE + path, timeout=15)
        return response.status, response.read(), response.headers

    def post(self, path: str, values: dict[str, object]) -> dict[str, object]:
        data = urllib.parse.urlencode(values).encode()
        request = urllib.request.Request(BASE + path, data=data, method="POST")
        response = self.opener.open(request, timeout=15)
        return json.loads(response.read())


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def expect(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)
    print(f"PASS: {message}")


def csrf_from(page: bytes) -> str:
    match = re.search(rb'name="csrf_token" value="([^"]+)"', page)
    expect(match is not None, "authenticated page contains a CSRF token")
    return match.group(1).decode()


def sql(query: str) -> list[list[str]]:
    command = COMPOSE + [
        "exec",
        "-T",
        "db",
        "sh",
        "-c",
        'exec mariadb -ufbctf --password="$(cat /run/secrets/db_password)" '
        "--batch --skip-column-names fbctf",
    ]
    result = subprocess.run(
        command,
        cwd=ROOT,
        input=query,
        text=True,
        capture_output=True,
        check=True,
    )
    return [line.split("\t") for line in result.stdout.splitlines() if line]


def admin_action(client: Client, csrf: str, action: str, **values: object) -> None:
    payload: dict[str, object] = {"action": action, "csrf_token": csrf}
    payload.update(values)
    response = client.post("/index.php?p=admin&ajax=true", payload)
    expect(response.get("result") == "OK", f"admin action {action} succeeds")


def wait_for_http() -> None:
    last_error: urllib.error.URLError | None = None
    for _ in range(60):
        try:
            if urllib.request.urlopen(BASE + "/", timeout=3).status == 200:
                return
        except urllib.error.URLError as error:
            last_error = error
        time.sleep(1)
    raise AssertionError("application did not return after restart") from last_error


def verify_frontend() -> None:
    """Check the public proxy, generated assets, and anonymous admin boundary."""
    status, body, headers = Client().get("/")
    expect(
        status == 200 and b"Capture the Flag Competition" in body,
        "customized landing page renders",
    )
    expect(headers.get("X-Frame-Options") == "DENY", "clickjacking header is set")
    expect("object-src 'none'" in headers.get("Content-Security-Policy", ""), "CSP is set")
    for asset in ("/static/css/fb-ctf.css", "/static/dist/js/app.js"):
        asset_status, asset_body, _ = Client().get(asset)
        expect(asset_status == 200 and len(asset_body) > 10_000, f"asset {asset} is built")

    # Anonymous users must not reach administration.
    no_redirect = urllib.request.build_opener(NoRedirect())
    request = urllib.request.Request(BASE + "/index.php?p=admin")
    try:
        no_redirect.open(request, timeout=10)
        raise AssertionError("anonymous admin request unexpectedly succeeded")
    except urllib.error.HTTPError as error:
        expect(error.code == 302, "anonymous admin request is rejected")


def login_admin() -> tuple[Client, str]:
    """Authenticate with the generated local credential and return its CSRF token."""
    admin = Client()
    admin_password = (RUNTIME / "secrets" / "admin_password").read_text().strip()
    login = admin.post(
        "/index.php?p=index&ajax=true",
        {"action": "login_team", "team_name": "admin", "password": admin_password},
    )
    expect(login.get("result") == "OK" and login.get("redirect") == "admin", "admin authenticates")
    _, admin_page, _ = admin.get("/index.php?p=admin&page=controls")
    return admin, csrf_from(admin_page)


def configure_game(admin: Client, admin_csrf: str) -> None:
    """Verify CSRF rejection, then enable registration, scoring, and gameplay."""
    bad_csrf = admin.post(
        "/index.php?p=admin&ajax=true",
        {
            "action": "change_configuration",
            "csrf_token": "invalid",
            "field": "scoring",
            "value": "0",
        },
    )
    expect(bad_csrf.get("result") == "ERROR", "invalid admin CSRF token is rejected")

    for field in ("registration", "scoring", "game"):
        admin_action(admin, admin_csrf, "change_configuration", field=field, value="1")


def create_levels(admin: Client, admin_csrf: str, suffix: str) -> dict[str, str]:
    """Create and activate one quiz and one flag through the admin interface."""
    quiz_title = f"Halloween Trivia {suffix}"
    flag_title = f"Haunted Flag {suffix}"
    admin_action(
        admin,
        admin_csrf,
        "create_quiz",
        title=quiz_title,
        question="What vegetable is traditionally carved into a jack-o-lantern?",
        answer="pumpkin",
        entity_id="0",
        points="100",
        hint="It grows on a vine.",
        penalty="0",
    )
    admin_action(
        admin,
        admin_csrf,
        "create_flag",
        title=flag_title,
        description="Find the hidden haunted-house flag.",
        flag="FBCTF{BOO}",
        entity_id="0",
        category_id="1",
        points="200",
        hint="Look behind the portrait.",
        penalty="0",
    )

    ids = sql(
        "SELECT id,type FROM levels "
        f"WHERE title IN ('{quiz_title}','{flag_title}') ORDER BY id;"
    )
    expect(len(ids) == 2, "admin-created quiz and flag persist in MariaDB")
    level_ids = {kind: level_id for level_id, kind in ids}
    for level_id in level_ids.values():
        admin_action(
            admin,
            admin_csrf,
            "toggle_status_level",
            level_id=level_id,
            status="1",
        )
    return level_ids


def register_player(suffix: str) -> tuple[Client, str, str, str]:
    """Register a unique team and return its authenticated client and CSRF token."""
    player = Client()
    player_name = f"Pumpkin {suffix}"
    player_password = f"spooky-{suffix}"
    registration = player.post(
        "/index.php?p=index&ajax=true",
        {
            "action": "register_team",
            "team_name": player_name,
            "password": player_password,
            "logo": "4chan-2",
            "isCustomLogo": "false",
            "logoType": "",
            "token": "",
        },
    )
    expect(
        registration.get("result") == "OK" and registration.get("redirect") == "game",
        "new player registers and authenticates",
    )
    _, game_page, _ = player.get("/index.php?p=game")
    return player, player_name, player_password, csrf_from(game_page)


def solve_levels(player: Client, player_csrf: str, level_ids: dict[str, str]) -> None:
    """Solve each challenge once and prove duplicate submissions do not rescore."""
    submissions = ((level_ids["quiz"], "pumpkin"), (level_ids["flag"], "FBCTF{BOO}"))
    for level_id, answer in submissions:
        first = player.post(
            "/index.php?p=game&ajax=true",
            {
                "action": "answer_level",
                "csrf_token": player_csrf,
                "level_id": level_id,
                "answer": answer,
            },
        )
        duplicate = player.post(
            "/index.php?p=game&ajax=true",
            {
                "action": "answer_level",
                "csrf_token": player_csrf,
                "level_id": level_id,
                "answer": answer,
            },
        )
        expect(first.get("message") == "Success", f"correct answer scores level {level_id}")
        expect("Double score" in str(duplicate.get("message")), f"duplicate solve {level_id} is rejected")


def verify_score_and_password(player_name: str, player_password: str, level_ids: dict[str, str]) -> None:
    """Confirm exact scoring and password hashing directly in persistent storage."""
    score_rows = sql(
        "SELECT COUNT(*),SUM(s.points),MAX(t.points) FROM scores_log s "
        "JOIN teams t ON t.id=s.team_id "
        f"WHERE t.name='{player_name}' AND s.level_id IN ({level_ids['quiz']},{level_ids['flag']});"
    )
    expect(score_rows == [["2", "360", "360"]], "exactly two solves award exactly 360 points")
    hash_rows = sql(f"SELECT password_hash FROM teams WHERE name='{player_name}';")
    expect(
        len(hash_rows) == 1
        and hash_rows[0][0] != player_password
        and hash_rows[0][0].startswith("$2y$"),
        "player password is stored as a bcrypt hash",
    )


def verify_restart(player: Client, player_name: str, player_password: str) -> None:
    """Restart every service and prove the score and credentials remain usable."""
    _, leaderboard_body, _ = player.get("/data/teams.php")
    leaderboard = json.loads(leaderboard_body)
    expect(leaderboard[player_name]["points"]["total"] == 360, "leaderboard shows awarded points")

    subprocess.run(COMPOSE + ["restart"], cwd=ROOT, check=True, capture_output=True, text=True)
    wait_for_http()
    _, persisted_body, _ = player.get("/data/teams.php")
    persisted = json.loads(persisted_body)
    expect(persisted[player_name]["points"]["total"] == 360, "session and score survive a full restart")

    relogin = Client().post(
        "/index.php?p=index&ajax=true",
        {"action": "login_team", "team_name": player_name, "password": player_password},
    )
    expect(relogin.get("result") == "OK", "player can authenticate after restart")


def main() -> None:
    suffix = str(int(time.time()))
    verify_frontend()
    admin, admin_csrf = login_admin()
    configure_game(admin, admin_csrf)
    level_ids = create_levels(admin, admin_csrf, suffix)
    player, player_name, player_password, player_csrf = register_player(suffix)
    solve_levels(player, player_csrf, level_ids)
    verify_score_and_password(player_name, player_password, level_ids)
    verify_restart(player, player_name, player_password)
    print("PASS: FBCTF end-to-end smoke test complete")


if __name__ == "__main__":
    os.chdir(ROOT)
    main()
