#!/usr/bin/env python3
"""Exercise manual-base ownership while scoring is safely disabled."""

from __future__ import annotations

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


def sql_value(value: str) -> str:
    if value == "NULL":
        return "NULL"
    return "'" + value.replace("'", "''") + "'"


def main() -> None:
    config = dict(sql(
        "SELECT field,value FROM configuration "
        "WHERE field IN ('game','scoring')",
    ))
    expect(config == {"game": "0", "scoring": "0"},
           "game and scoring are stopped before ownership smoke test")
    state = sql(
        "SELECT level_id,IFNULL(owner_team_id,'NULL'),ownership_version,"
        "IFNULL(last_accrual_ts,'NULL'),IFNULL(captured_ts,'NULL'),"
        "IFNULL(updated_by_admin_id,'NULL') FROM manual_base_state "
        "ORDER BY level_id LIMIT 1",
    )[0]
    level_id = int(state[0])
    team_id, team_name, points_before = sql(
        "SELECT id,name,points FROM teams WHERE active=1 AND visible=1 "
        "ORDER BY id LIMIT 1",
    )[0]
    score_count = sql("SELECT COUNT(*) FROM scores_log")[0][0]
    ledger_count = sql("SELECT COUNT(*) FROM manual_base_points_ledger")[0][0]
    activity_floor = int(sql("SELECT COALESCE(MAX(id),0) FROM activity_log")[0][0])

    admin = Client()
    try:
        login = admin.post("/index.php?p=index&ajax=true", {
            "action": "login_team", "team_name": "admin",
            "password": (RUNTIME / "secrets" / "admin_password").read_text().strip(),
        })
        expect(login.get("result") == "OK", "administrator authenticates")
        page = admin.get("/index.php?p=admin&page=bases")
        expect(b"Set current winner" in page,
               "manual-base owner control renders")
        csrf = token(page)
        assigned = admin.post("/index.php?p=admin&ajax=true", {
            "action": "set_manual_base_owner", "csrf_token": csrf,
            "level_id": level_id, "team_id": team_id,
        })
        expect(assigned.get("result") == "OK",
               "administrator can assign the current Uno winner")
        owner = sql(
            f"SELECT owner_team_id FROM manual_base_state WHERE level_id={level_id}",
        )[0][0]
        expect(owner == team_id, "manual base stores the selected team")
        country_data = json.loads(admin.get("/data/country-data.php"))
        uno = next(
            country for country in country_data.values()
            if country.get("level_id") == level_id
        )
        expect(uno.get("type") == "base" and uno.get("owner") == team_name,
               "gameboard data shows the current Uno owner as a base")
        cleared = admin.post("/index.php?p=admin&ajax=true", {
            "action": "set_manual_base_owner", "csrf_token": csrf,
            "level_id": level_id, "team_id": 0,
        })
        expect(cleared.get("result") == "OK",
               "administrator can return the base to Unclaimed")
        expect(sql("SELECT COUNT(*) FROM scores_log")[0][0] == score_count,
               "ownership changes do not score while the game is stopped")
        expect(sql(
            "SELECT COUNT(*) FROM manual_base_points_ledger",
        )[0][0] == ledger_count, "no reward ledger rows were created")
        expect(sql(
            f"SELECT points FROM teams WHERE id={team_id}",
        )[0][0] == points_before, "team points remain unchanged")
    finally:
        restore = (
            "UPDATE manual_base_state SET "
            f"owner_team_id={sql_value(state[1])},"
            f"ownership_version={state[2]},"
            f"last_accrual_ts={sql_value(state[3])},"
            f"captured_ts={sql_value(state[4])},"
            f"updated_by_admin_id={sql_value(state[5])} "
            f"WHERE level_id={level_id};"
            "DELETE FROM activity_log WHERE id>"
            f"{activity_floor} AND message='Manual base ownership changed to %s';"
        )
        sql(restore)
    print("PASS: manual-base smoke test restored all live state")


if __name__ == "__main__":
    main()
