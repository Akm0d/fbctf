#!/usr/bin/env python3
"""Live music workflow smoke test with targeted cleanup."""

from __future__ import annotations

import http.cookiejar
import json
from pathlib import Path
import re
import subprocess
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


class Client:
    def __init__(self) -> None:
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cookies),
        )

    def get(self, path: str) -> bytes:
        return self.opener.open(BASE + path, timeout=15).read()

    def post(self, path: str, values: dict[str, object]) -> dict[str, object]:
        request = urllib.request.Request(
            BASE + path,
            data=urllib.parse.urlencode(values).encode(),
            method="POST",
        )
        return json.loads(self.opener.open(request, timeout=15).read())


def token(page: bytes) -> str:
    match = re.search(rb'name="csrf_token" value="([^"]+)"', page)
    expect(match is not None, "authenticated music page contains CSRF token")
    return match.group(1).decode()


def login(name: str, password: str, page: str) -> tuple[Client, str]:
    client = Client()
    result = client.post(
        "/index.php?p=index&ajax=true",
        {"action": "login_team", "team_name": name, "password": password},
    )
    expect(result.get("result") == "OK", f"{name} authenticates")
    return client, token(client.get(page))


def action(client: Client, endpoint: str, csrf: str, name: str, **values: object) -> dict[str, object]:
    payload: dict[str, object] = {"action": name, "csrf_token": csrf}
    payload.update(values)
    result = client.post(endpoint, payload)
    expect(
        result.get("result") == "OK",
        f"{name} succeeds: {result.get('message', result)}",
    )
    return result


def main() -> None:
    expect(sql("SELECT COUNT(*) FROM music_queue") == [["0"]], "music queue starts empty")
    expect(sql("SELECT COUNT(*) FROM music_requests") == [["0"]], "request list starts empty")
    stage_before = sql(
        "SELECT performance_points,passive_points_per_minute,enabled,"
        "ownership_version FROM karaoke_stage_state WHERE id=1"
    )[0]
    teams = sql(
        "SELECT id,name,points FROM teams WHERE name IN "
        "('Contest A 1793123484','Contest B 1793123484') ORDER BY name"
    )
    expect(len(teams) == 2, "existing smoke teams are available")
    team_data = {name: (team_id, points) for team_id, name, points in teams}
    team_a_id, team_a_points = team_data["Contest A 1793123484"]
    team_b_id, team_b_points = team_data["Contest B 1793123484"]

    admin, admin_csrf = login(
        "admin", (RUNTIME / "secrets" / "admin_password").read_text().strip(),
        "/index.php?p=admin&page=music",
    )
    player_a, csrf_a = login(
        "Contest A 1793123484", "a-1793123484", "/index.php?p=music",
    )
    player_b, csrf_b = login(
        "Contest B 1793123484", "b-1793123484", "/index.php?p=music",
    )
    endpoint = "/index.php?p=music-admin&ajax=true"

    try:
        playback_before = sql("SELECT desired_state FROM music_playback_state WHERE id=1")
        try:
            player_a.post(
                endpoint,
                {"action": "playback", "csrf_token": csrf_a, "command": "play"},
            )
        except json.JSONDecodeError:
            pass
        expect(
            sql("SELECT desired_state FROM music_playback_state WHERE id=1") == playback_before,
            "non-admin team cannot issue playback commands",
        )

        invalid_request = player_a.post(
            "/index.php?p=music&ajax=true",
            {
                "action": "request_song", "csrf_token": csrf_a,
                "youtube_url": "https://example.com/not-youtube",
                "display_title": "Rejected input", "request_type": "regular",
            },
        )
        expect(
            invalid_request.get("result") == "ERROR"
            and sql("SELECT COUNT(*) FROM music_requests") == [["0"]],
            "non-YouTube URLs are rejected without creating a request",
        )

        action(
            admin, endpoint, admin_csrf, "add_playlist",
            playlist_text=(
                "API Demo | https://www.youtube.com/watch?v=M7lc1UVf-VE\n"
                "Never Gonna Give You Up | https://youtu.be/dQw4w9WgXcQ"
            ),
        )
        queue = sql("SELECT id,display_title,position FROM music_queue ORDER BY position")
        expect([row[1] for row in queue] == ["API Demo", "Never Gonna Give You Up"], "bulk playlist preserves configured order")
        action(admin, endpoint, admin_csrf, "move_queue", queue_id=queue[1][0], direction="up")
        expect(
            sql("SELECT display_title FROM music_queue ORDER BY position")[0][0]
            == "Never Gonna Give You Up",
            "administrator can reorder playlist songs",
        )

        action(
            player_a, "/index.php?p=music&ajax=true", csrf_a, "request_song",
            youtube_url="https://www.youtube.com/watch?v=9bZkp7q19f0",
            display_title="Team A Karaoke", request_type="karaoke",
        )
        action(
            player_b, "/index.php?p=music&ajax=true", csrf_b, "request_song",
            youtube_url="https://youtu.be/kJQP7kiw5Fk",
            display_title="Team B Karaoke", request_type="karaoke",
        )
        requests = sql("SELECT id,display_title FROM music_requests ORDER BY id")
        expect(len(requests) == 2, "two authenticated teams submit guest requests")
        for request_id, _ in requests:
            action(admin, endpoint, admin_csrf, "moderate_request", request_id=request_id, status="approved")
            action(admin, endpoint, admin_csrf, "schedule_request", request_id=request_id, mode="end")
        expect(sql("SELECT COUNT(*) FROM music_queue WHERE is_karaoke=1") == [["2"]], "approved karaoke requests join the one playing queue")

        first_id = sql("SELECT id FROM music_queue ORDER BY position LIMIT 1")[0][0]
        action(admin, endpoint, admin_csrf, "play_queue", queue_id=first_id)
        action(admin, endpoint, admin_csrf, "playback", command="pause")
        action(admin, endpoint, admin_csrf, "playback", command="play")
        expect(sql("SELECT desired_state FROM music_playback_state WHERE id=1") == [["playing"]], "remote play and pause update server playback state")

        tv_key = "0123456789abcdef0123456789abcdea"
        tv_data = json.loads(urllib.request.urlopen(
            BASE + "/data/music-tv.php?" + urllib.parse.urlencode({"client_key": tv_key}),
            timeout=15,
        ).read())
        expect(tv_data.get("active_tv") is True, "one TV client acquires the active-player lease")
        current_id = int(tv_data["playback"]["current_queue_id"])
        finished = urllib.request.Request(
            BASE + "/data/music-tv.php",
            data=urllib.parse.urlencode({
                "action": "finished", "client_key": tv_key,
                "queue_id": current_id,
            }).encode(), method="POST",
        )
        expect(json.loads(urllib.request.urlopen(finished, timeout=15).read())["result"] == "OK", "TV completion advances the queue")
        duplicate = urllib.request.Request(
            BASE + "/data/music-tv.php",
            data=urllib.parse.urlencode({
                "action": "finished", "client_key": tv_key,
                "queue_id": current_id,
            }).encode(), method="POST",
        )
        expect(json.loads(urllib.request.urlopen(duplicate, timeout=15).read())["result"] == "IGNORED", "duplicate TV completion cannot advance twice")

        action(admin, endpoint, admin_csrf, "configure_karaoke", performance_points=50, passive_points=5, enabled="1", activity_country=0, stage_country=0, category_id=0)
        karaoke_rows = sql("SELECT id,request_team_id FROM music_queue WHERE is_karaoke=1 ORDER BY id")
        karaoke_by_team = {team_id: queue_id for queue_id, team_id in karaoke_rows}
        action(admin, endpoint, admin_csrf, "play_queue", queue_id=karaoke_by_team[team_a_id])
        action(admin, endpoint, admin_csrf, "confirm_performance", queue_id=karaoke_by_team[team_a_id])
        duplicate_award = action(admin, endpoint, admin_csrf, "confirm_performance", queue_id=karaoke_by_team[team_a_id])
        expect("already completed" in str(duplicate_award.get("message")), "performance reward is idempotent")
        expect(sql("SELECT owner_team_id FROM karaoke_stage_state WHERE id=1") == [[team_a_id]], "first performance captures Karaoke Stage")

        sql("UPDATE karaoke_stage_state SET last_accrual_ts=DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE id=1")
        accrue = COMPOSE + [
            "exec", "-T", "app", "hhvm", "--php", "-r",
            r'require "vendor/autoload.php"; HH\Asio\join(Music::genAccrueStage());',
        ]
        subprocess.run(accrue, cwd=ROOT, check=True, capture_output=True, text=True)
        subprocess.run(accrue, cwd=ROOT, check=True, capture_output=True, text=True)
        stage_points = sql(
            f"SELECT COALESCE(SUM(points),0) FROM music_points_ledger WHERE team_id={team_a_id} AND reward_type='stage'"
        )[0][0]
        expect(stage_points == "10", "two passive intervals accrue once across repeated scorer calls")

        action(admin, endpoint, admin_csrf, "play_queue", queue_id=karaoke_by_team[team_b_id])
        action(admin, endpoint, admin_csrf, "confirm_performance", queue_id=karaoke_by_team[team_b_id])
        expect(sql("SELECT owner_team_id FROM karaoke_stage_state WHERE id=1") == [[team_b_id]], "later performance transfers Karaoke Stage ownership")
        expect(sql("SELECT COUNT(*) FROM karaoke_performances") == [["2"]], "distinct performances have distinct audit records")

        subprocess.run(COMPOSE + ["restart", "app", "web"], cwd=ROOT, check=True)
        for _ in range(30):
            try:
                urllib.request.urlopen(BASE + "/tv", timeout=2).read(1)
                break
            except Exception:
                import time
                time.sleep(1)
        tv_after = json.loads(urllib.request.urlopen(
            BASE + "/data/music-tv.php?" + urllib.parse.urlencode({"client_key": tv_key}),
            timeout=15,
        ).read())
        expect(len(tv_after["queue"]) >= 1 and tv_after["stage"]["owner_team_id"] == team_b_id, "queue and stage state survive restart")
    finally:
        sql(
            "UPDATE music_playback_state SET current_queue_id=NULL,desired_state='paused',"
            "active_tv_key_hash=NULL,active_tv_lease_until=NULL WHERE id=1;"
            "UPDATE karaoke_stage_state SET owner_team_id=NULL,last_accrual_ts=NULL,captured_ts=NULL,"
            f"performance_points={stage_before[0]},passive_points_per_minute={stage_before[1]},"
            f"enabled={stage_before[2]},ownership_version={stage_before[3]} WHERE id=1;"
            f"DELETE FROM music_points_ledger WHERE team_id IN ({team_a_id},{team_b_id});"
            f"DELETE FROM karaoke_performances WHERE team_id IN ({team_a_id},{team_b_id});"
            "DELETE FROM music_queue WHERE display_title IN ('API Demo','Never Gonna Give You Up',"
            "'Team A Karaoke','Team B Karaoke');"
            f"DELETE FROM music_requests WHERE team_id IN ({team_a_id},{team_b_id});"
            "DELETE FROM activity_log WHERE message LIKE '%completed karaoke:%' "
            "AND (message LIKE '%Team A Karaoke%' OR message LIKE '%Team B Karaoke%');"
            f"UPDATE teams SET points={team_a_points} WHERE id={team_a_id};"
            f"UPDATE teams SET points={team_b_points} WHERE id={team_b_id};"
        )
        subprocess.run(
            COMPOSE + [
                "exec", "-T", "app", "hhvm", "--php", "-r",
                r'require "vendor/autoload.php"; MultiTeam::invalidateMCRecords(); ActivityLog::invalidateMCRecords();',
            ],
            cwd=ROOT, check=True, capture_output=True, text=True,
        )
    expect(sql("SELECT COUNT(*) FROM music_queue") == [["0"]], "test music rows are cleaned up")
    expect(sql(f"SELECT points FROM teams WHERE id={team_a_id}") == [[team_a_points]], "existing team points are restored")
    print("PASS: music, TV, karaoke, and stage smoke test complete")


if __name__ == "__main__":
    main()
