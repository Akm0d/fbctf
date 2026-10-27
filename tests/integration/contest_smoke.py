#!/usr/bin/env python3
"""End-to-end smoke test for contest levels, session voting, and scoring."""

from __future__ import annotations

import http.cookiejar
import json
from pathlib import Path
import re
import secrets
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


def expect(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)
    print(f"PASS: {message}")


class Client:
    def __init__(self) -> None:
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cookies),
        )

    def get(self, path: str) -> tuple[int, bytes]:
        response = self.opener.open(BASE + path, timeout=15)
        return response.status, response.read()

    def get_without_redirect(self, path: str) -> int:
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, headers, newurl):
                return None

        opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cookies), NoRedirect(),
        )
        try:
            return opener.open(BASE + path, timeout=15).status
        except urllib.error.HTTPError as error:
            return error.code

    def post(self, path: str, values: dict[str, object]) -> dict[str, object]:
        request = urllib.request.Request(
            BASE + path,
            data=urllib.parse.urlencode(values).encode(),
            method="POST",
        )
        return json.loads(self.opener.open(request, timeout=15).read())

    def post_file(
        self,
        path: str,
        values: dict[str, object],
        field: str,
        filename: str,
        content_type: str,
        content: bytes,
    ) -> dict[str, object]:
        boundary = f"fbctf-{secrets.token_hex(12)}"
        chunks: list[bytes] = []
        for name, value in values.items():
            chunks.extend([
                f"--{boundary}\r\n".encode(),
                f'Content-Disposition: form-data; name="{name}"\r\n\r\n'.encode(),
                str(value).encode(), b"\r\n",
            ])
        chunks.extend([
            f"--{boundary}\r\n".encode(),
            f'Content-Disposition: form-data; name="{field}"; filename="{filename}"\r\n'.encode(),
            f"Content-Type: {content_type}\r\n\r\n".encode(),
            content, b"\r\n", f"--{boundary}--\r\n".encode(),
        ])
        request = urllib.request.Request(
            BASE + path, data=b"".join(chunks), method="POST",
            headers={"Content-Type": f"multipart/form-data; boundary={boundary}"},
        )
        return json.loads(self.opener.open(request, timeout=15).read())


def sql(query: str) -> list[list[str]]:
    command = COMPOSE + [
        "exec", "-T", "db", "sh", "-c",
        'exec mariadb -ufbctf --password="$(cat /run/secrets/db_password)" '
        "--batch --skip-column-names fbctf",
    ]
    result = subprocess.run(
        command, cwd=ROOT, input=query, text=True, capture_output=True, check=True,
    )
    return [line.split("\t") for line in result.stdout.splitlines() if line]


def csrf(page: bytes) -> str:
    match = re.search(rb'name="csrf_token" value="([^"]+)"', page)
    expect(match is not None, "authenticated page contains a CSRF token")
    return match.group(1).decode()


def login(name: str, password: str) -> tuple[Client, str]:
    client = Client()
    result = client.post(
        "/index.php?p=index&ajax=true",
        {"action": "login_team", "team_name": name, "password": password},
    )
    expect(result.get("result") == "OK", f"{name} authenticates")
    _, page = client.get("/index.php?p=contests")
    return client, csrf(page)


def register(name: str, password: str) -> tuple[Client, str]:
    client = Client()
    result = client.post(
        "/index.php?p=index&ajax=true",
        {
            "action": "register_team", "team_name": name, "password": password,
            "logo": "4chan-2", "isCustomLogo": "false", "logoType": "", "token": "",
        },
    )
    expect(result.get("result") == "OK", f"{name} registers")
    _, page = client.get("/index.php?p=contests")
    return client, csrf(page)


def action(client: Client, endpoint: str, token: str, name: str, **values: object) -> dict[str, object]:
    payload: dict[str, object] = {"action": name, "csrf_token": token}
    payload.update(values)
    result = client.post(endpoint, payload)
    expect(result.get("result") == "OK", f"{name} succeeds")
    return result


def main() -> None:
    suffix = str(int(time.time()))
    admin, admin_token = login(
        "admin", (RUNTIME / "secrets" / "admin_password").read_text().strip(),
    )
    action(
        admin, "/index.php?p=admin&ajax=true", admin_token,
        "change_configuration", field="registration", value="1",
    )
    action(
        admin, "/index.php?p=admin&ajax=true", admin_token,
        "change_configuration", field="game", value="1",
    )

    team_a = f"Contest A {suffix}"
    team_b = f"Contest B {suffix}"
    password_a = f"a-{suffix}"
    password_b = f"b-{suffix}"
    player_a, token_a = register(team_a, password_a)
    player_a2, token_a2 = login(team_a, password_a)
    player_a3, token_a3 = login(team_a, password_a)
    player_b, token_b = register(team_b, password_b)
    expect(
        player_b.get_without_redirect("/index.php?p=admin&page=contests") == 302,
        "non-admin team cannot access contest administration",
    )
    team_rows = sql(
        "SELECT id,name FROM teams WHERE name IN "
        f"('{team_a}','{team_b}') ORDER BY name"
    )
    ids = {name: team_id for team_id, name in team_rows}
    expect(len(ids) == 2, "test teams persist in the existing database")
    category_id = sql("SELECT id FROM categories ORDER BY id LIMIT 1")[0][0]
    country_ids = [row[0] for row in sql(
        "SELECT id FROM countries WHERE enabled=1 AND used=0 ORDER BY id LIMIT 2"
    )]
    expect(len(country_ids) == 2, "two unused countries are available for contest levels")

    title = f"Best Costume {suffix}"
    action(
        admin, "/index.php?p=contest-admin&ajax=true", admin_token, "create_contest",
        title=title, description="Choose the best costume.",
        entity_id=country_ids[0], category_id=category_id,
        submission_type="text", vote_points="10", winner_points="100",
        voting_open="", voting_close="",
        visible="1", active="1",
    )
    contest_id = sql(f"SELECT id FROM contests WHERE title='{title}'")[-1][0]
    for client, token, text in (
        (player_a, token_a, "Moon Witch"),
        (player_b, token_b, "Clockwork Ghost"),
    ):
        action(
            client, "/index.php?p=contests&ajax=true", token, "submit_entry",
            contest_id=contest_id, submission_type="text", text_content=text,
        )
    action(
        player_b, "/index.php?p=contests&ajax=true", token_b, "submit_entry",
        contest_id=contest_id, submission_type="text",
        text_content="Clockwork Ghost, updated",
    )
    updated = sql(
        "SELECT COUNT(*),MAX(e.text_content) FROM contest_entries e "
        f"WHERE e.contest_id={contest_id} AND e.team_id={ids[team_b]}"
    )
    expect(
        updated == [["1", "Clockwork Ghost, updated"]],
        "updating a team entry does not create a duplicate",
    )
    entry_b = sql(
        "SELECT e.id FROM contest_entries e JOIN teams t ON t.id=e.team_id "
        f"WHERE e.contest_id={contest_id} AND t.name='{team_b}'"
    )[0][0]

    action(
        player_a, "/index.php?p=contests&ajax=true", token_a, "vote",
        contest_id=contest_id, entry_id=entry_b,
    )
    action(
        player_a2, "/index.php?p=contests&ajax=true", token_a2, "vote",
        contest_id=contest_id, entry_id=entry_b,
    )
    duplicate = action(
        player_a, "/index.php?p=contests&ajax=true", token_a, "vote",
        contest_id=contest_id, entry_id=entry_b,
    )
    expect("already awarded" in str(duplicate.get("message")), "repeat vote does not award points again")
    third_session = player_a3.post(
        "/index.php?p=contests&ajax=true",
        {
            "action": "vote", "csrf_token": token_a3,
            "contest_id": contest_id, "entry_id": entry_b,
        },
    )
    expect(
        third_session.get("result") == "ERROR" and "both voting sessions" in str(third_session.get("message")),
        "a team cannot vote from more than two sessions",
    )
    vote_rows = sql(
        "SELECT COUNT(*),COUNT(DISTINCT voter_session_key),COUNT(DISTINCT voter_team_id) "
        "FROM contest_session_votes "
        f"WHERE contest_id={contest_id}"
    )
    expect(vote_rows == [["2", "2", "1"]], "database enforces one vote per session and two per team")
    points_a = sql(f"SELECT points FROM teams WHERE id={ids[team_a]}")[0][0]
    expect(points_a == "20", "two player sessions earn two voting rewards for one team")

    level_row = sql(
        "SELECT l.type,l.entity_id FROM levels l JOIN contests c ON c.level_id=l.id "
        f"WHERE c.id={contest_id}"
    )
    expect(level_row == [["poll", country_ids[0]]], "contest is a poll level attached to its country")
    _, country_data_body = player_a.get("/data/country-data.php")
    country_data = json.loads(country_data_body)
    expect(
        any(level.get("level_id") == int(sql(f"SELECT level_id FROM contests WHERE id={contest_id}")[0][0])
            and level.get("type") == "poll" for level in country_data.values()),
        "contest level is present on the existing game board",
    )

    action(
        admin, "/index.php?p=contest-admin&ajax=true", admin_token, "update_contest",
        contest_id=contest_id, title=title, description="Choose the best costume.",
        entity_id=country_ids[0], category_id=category_id,
        submission_type="text", vote_points="10", winner_points="100",
        voting_open="", voting_close="",
    )
    action(
        admin, "/index.php?p=contest-admin&ajax=true", admin_token, "finalize_contest",
        contest_id=contest_id, entry_id=entry_b,
    )
    duplicate_winner = action(
        admin, "/index.php?p=contest-admin&ajax=true", admin_token, "finalize_contest",
        contest_id=contest_id, entry_id=entry_b,
    )
    expect("already finalized" in str(duplicate_winner.get("message")), "winner reward is idempotent")
    points_b = sql(f"SELECT points FROM teams WHERE id={ids[team_b]}")[0][0]
    expect(points_b == "100", "winner reward is awarded once at team level")

    image_title = f"Funniest Picture {suffix}"
    action(
        admin, "/index.php?p=contest-admin&ajax=true", admin_token, "create_contest",
        title=image_title, description="Upload a party picture.",
        entity_id=country_ids[1], category_id=category_id,
        submission_type="image", vote_points="0", winner_points="0",
        voting_open="", voting_close="",
        visible="1", active="1",
    )
    image_contest_id = sql(f"SELECT id FROM contests WHERE title='{image_title}'")[-1][0]
    upload = player_b.post_file(
        "/index.php?p=contests&ajax=true",
        {
            "action": "submit_entry", "csrf_token": token_b,
            "contest_id": image_contest_id, "submission_type": "image",
        },
        "contest_image", "party.png", "image/png",
        (ROOT / "src/static/img/favicon.png").read_bytes(),
    )
    expect(upload.get("result") == "OK", "validated image entry uploads")
    image_entry = sql(
        "SELECT id,image_filename FROM contest_entries "
        f"WHERE contest_id={image_contest_id} AND team_id={ids[team_b]}"
    )[0]
    expect(
        re.fullmatch(r"[a-f0-9]{32}\.png", image_entry[1]) is not None,
        "image receives a safe generated filename",
    )
    image_response = player_b.opener.open(
        BASE + f"/data/contest-image.php?id={image_entry[0]}", timeout=15,
    )
    expect(
        image_response.headers.get_content_type() == "image/png"
        and image_response.read(8) == b"\x89PNG\r\n\x1a\n",
        "stored image is served as authenticated non-executable media",
    )

    action(
        admin, "/index.php?p=admin&ajax=true", admin_token,
        "change_configuration", field="game", value="0",
    )
    closed_submission = player_a.post(
        "/index.php?p=contests&ajax=true",
        {
            "action": "submit_entry", "csrf_token": token_a,
            "contest_id": image_contest_id, "submission_type": "text",
            "text_content": "too late",
        },
    )
    expect(
        closed_submission.get("result") == "ERROR"
        and "game is running" in str(closed_submission.get("message")),
        "team submissions close when the game ends",
    )

    subprocess.run(COMPOSE + ["restart", "app", "web"], cwd=ROOT, check=True)
    time.sleep(4)
    persisted = sql(
        "SELECT COUNT(*),COUNT(DISTINCT voter_session_key) FROM contest_session_votes "
        f"WHERE contest_id={contest_id}"
    )
    expect(persisted == [["2", "2"]], "contest data survives an application restart")
    _, leaderboard_body = player_a.get("/data/teams.php")
    leaderboard = json.loads(leaderboard_body)
    expect(leaderboard[team_a]["points"]["total"] == 20, "leaderboard includes contest voting points")
    expect(leaderboard[team_b]["points"]["total"] == 100, "leaderboard includes winner points")
    print("PASS: session-voting contest end-to-end smoke test complete")


if __name__ == "__main__":
    main()
