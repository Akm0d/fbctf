#!/usr/bin/env python3
"""Live prerequisite checks using temporary teams and levels with exact cleanup."""

from __future__ import annotations

import os
import json
from pathlib import Path
import subprocess

from music_smoke import Client, token


ROOT = Path(__file__).resolve().parents[2]
RUNTIME = ROOT / ".runtime"
COMPOSE = [
    "docker", "compose", "--env-file", str(RUNTIME / "env"),
    "-f", str(ROOT / "compose.compat.yml"),
]
GAME_ENDPOINT = "/index.php?p=game&ajax=true"


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


def restart_cache() -> None:
    subprocess.run(COMPOSE + ["restart", "cache"], cwd=ROOT, check=True,
                   capture_output=True)


def login(name: str, password: str) -> tuple[Client, str]:
    client = Client()
    result = client.post("/index.php?p=index&ajax=true", {
        "action": "login_team", "team_name": name, "password": password,
    })
    expect(result.get("result") == "OK", f"{name} authenticates")
    return client, token(client.get("/index.php?p=game"))


def capture(client: Client, csrf: str, level_id: int, answer: str) -> dict[str, object]:
    return client.post(GAME_ENDPOINT, {
        "action": "answer_level", "csrf_token": csrf,
        "level_id": level_id, "answer": answer,
    })


def main() -> None:
    suffix = str(os.getpid())
    password = f"PrereqSmoke-{suffix}"
    password_hash = subprocess.run(
        COMPOSE + ["exec", "-T", "app", "hhvm", "--php", "-r",
                   f'echo password_hash("{password}", PASSWORD_DEFAULT);'],
        cwd=ROOT, text=True, capture_output=True, check=True,
    ).stdout.strip().replace("'", "''")
    team_names = [f"Prereq Smoke A {suffix}", f"Prereq Smoke B {suffix}"]
    level_ids: list[int] = []
    team_ids: list[int] = []
    countries = sql(
        "SELECT id,iso_code FROM countries WHERE enabled=1 AND id NOT IN "
        "(SELECT entity_id FROM levels) ORDER BY id LIMIT 4"
    )
    expect(len(countries) == 4, "four isolated test countries are available")
    qr_category = int(sql(
        "SELECT id FROM categories WHERE LOWER(category)='qr' LIMIT 1"
    )[0][0])
    titles = [f"Prereq Stage {index} {suffix}" for index in range(1, 4)]
    titles.append(f"Prereq Ordinary {suffix}")
    answers = [f"PREREQ-{suffix}-{index}" for index in range(1, 5)]
    config_before = dict(sql(
        "SELECT field,value FROM configuration "
        "WHERE field IN ('game','game_paused','scoring')"
    ))
    try:
        sql("UPDATE configuration SET value='1' WHERE field IN ('game','scoring');"
            "UPDATE configuration SET value='0' WHERE field='game_paused';")
        for name in team_names:
            safe_name = name.replace("'", "''")
            sql(
                "INSERT INTO teams(active,name,password_hash,points,last_score,logo,"
                "admin,protected,visible,created_ts) VALUES"
                f"(1,'{safe_name}','{password_hash}',0,NOW(),'1',0,0,1,NOW())"
            )
            team_ids.append(int(sql(
                f"SELECT id FROM teams WHERE name='{safe_name}' LIMIT 1"
            )[0][0]))

        previous = "NULL"
        for index, ((country_id, _), title, answer) in enumerate(
            zip(countries, titles, answers), start=1
        ):
            safe_title = title.replace("'", "''")
            sql(
                "INSERT INTO levels(active,type,title,description,entity_id,category_id,"
                "points,bonus,bonus_dec,bonus_fix,flag,hint,penalty,requires_level_id,"
                "completion_message,created_ts) VALUES"
                f"(1,'flag','{safe_title}','PROTECTED DESCRIPTION {index}',"
                f"{country_id},{qr_category},10,0,0,0,'{answer}',"
                f"'PROTECTED HINT {index}',0,{previous},NULL,NOW())"
            )
            level_id = int(sql(
                f"SELECT id FROM levels WHERE title='{safe_title}' LIMIT 1"
            )[0][0])
            level_ids.append(level_id)
            previous = str(level_id) if index < 3 else "NULL"
        restart_cache()

        team_a, csrf_a = login(team_names[0], password)
        team_b, csrf_b = login(team_names[1], password)
        data_a = json.loads(team_a.get("/data/country-data.php"))
        stage1_name = next(name for name, row in data_a.items()
                           if row.get("title") == titles[0])
        expect(data_a[stage1_name].get("locked") is False,
               "Team A can initially access Stage 1")
        locked_rows = [row for row in data_a.values()
                       if row.get("locked") is True and row.get("category") == "QR"]
        expect(len(locked_rows) >= 2 and "PROTECTED DESCRIPTION 2" not in str(data_a),
               "locked payload exposes no protected challenge content")
        list_html = team_a.get("/inc/gameboard/listview.php")
        expect(titles[1].encode() not in list_html and b"Locked" in list_html,
               "mobile list hides locked titles and shows a lock state")
        blocked = capture(team_a, csrf_a, level_ids[1], answers[1])
        expect(blocked.get("result") == "ERROR" and blocked.get("message") == "Challenge is locked",
               "direct valid-answer submission cannot bypass the prerequisite")

        first = capture(team_a, csrf_a, level_ids[0], answers[0])
        expect(first.get("result") == "OK" and "Next destination:" in str(first.get("message")),
               "Stage 1 capture announces the newly unlocked country")
        data_a = json.loads(team_a.get("/data/country-data.php"))
        data_b = json.loads(team_b.get("/data/country-data.php"))
        stage2_name = next(name for name, row in data_a.items()
                           if row.get("title") == titles[1])
        expect(stage2_name.upper() in str(first.get("message")) and
               data_a[stage2_name].get("title") == titles[1],
               "Stage 2 unlocks for Team A")
        expect(data_b[stage2_name].get("locked") is True,
               "Stage 2 remains locked for Team B")
        expect(titles[1] not in str(data_b[stage2_name]),
               "cached responses do not leak Team A content to Team B")

        second = capture(team_a, csrf_a, level_ids[1], answers[1])
        data_a = json.loads(team_a.get("/data/country-data.php"))
        stage3_name = next(name for name, row in data_a.items()
                           if row.get("title") == titles[2])
        expect(second.get("result") == "OK" and stage3_name.upper() in str(second.get("message")),
               "the sequence unlocks exactly its intended next stage")
        points_before = int(sql(f"SELECT points FROM teams WHERE id={team_ids[0]}")[0][0])
        duplicate = capture(team_a, csrf_a, level_ids[1], answers[1])
        points_after = int(sql(f"SELECT points FROM teams WHERE id={team_ids[0]}")[0][0])
        expect("Double score" in str(duplicate.get("message")) and points_after == points_before,
               "repeated submissions do not award duplicate points")

        ordinary = capture(team_b, csrf_b, level_ids[3], answers[3])
        expect(ordinary.get("result") == "OK" and ordinary.get("message") == "Success",
               "ordinary levels without prerequisites retain existing behavior")

        sql(f"UPDATE levels SET active=0 WHERE id={level_ids[2]}")
        restart_cache()
        disabled_data = json.loads(team_a.get("/data/country-data.php"))
        expect(stage3_name not in disabled_data,
               "globally disabled levels remain unavailable")
        disabled = capture(team_a, csrf_a, level_ids[2], answers[2])
        expect(disabled.get("result") == "ERROR",
               "a direct submission cannot capture a globally disabled level")

        sql(f"UPDATE levels SET active=1,flag='' WHERE id={level_ids[2]}")
        restart_cache()
        unconfigured = capture(team_a, csrf_a, level_ids[2], "")
        expect(unconfigured.get("result") == "ERROR",
               "an unconfigured empty flag cannot be captured")
    finally:
        if level_ids or team_ids:
            level_csv = ",".join(map(str, level_ids)) or "0"
            team_csv = ",".join(map(str, team_ids)) or "0"
            sql(
                f"DELETE FROM failures_log WHERE level_id IN ({level_csv}) OR team_id IN ({team_csv});"
                f"DELETE FROM hints_log WHERE level_id IN ({level_csv}) OR team_id IN ({team_csv});"
                f"DELETE FROM scores_log WHERE level_id IN ({level_csv}) OR team_id IN ({team_csv});"
                f"DELETE FROM levels WHERE id IN ({level_csv});"
                f"DELETE FROM teams WHERE id IN ({team_csv});"
            )
        for field, value in config_before.items():
            sql(f"UPDATE configuration SET value='{value}' WHERE field='{field}'")
        restart_cache()
        leftovers = sql(
            "SELECT COUNT(*) FROM teams WHERE name LIKE 'Prereq Smoke %'"
        )
        expect(leftovers == [["0"]], "temporary test teams are removed")


if __name__ == "__main__":
    main()
