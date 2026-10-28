#!/usr/bin/env python3
"""Live Migi Vault checks with temporary records and exact cleanup."""

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
CHAT_ENDPOINT = "/index.php?p=migi&ajax=true"
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


def compose(*args: str) -> None:
    subprocess.run(COMPOSE + list(args), cwd=ROOT, check=True, capture_output=True)


def login(name: str, password: str) -> tuple[Client, str]:
    client = Client()
    result = client.post("/index.php?p=index&ajax=true", {
        "action": "login_team", "team_name": name, "password": password,
    })
    expect(result.get("result") == "OK", f"{name} authenticates")
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
        f"http://127.0.0.1:{port}{CHAT_ENDPOINT}",
        data=urllib.parse.urlencode(values).encode(),
        method="POST",
    )
    raw = client.opener.open(request, timeout=90).read()
    return json.loads(raw), raw


def capture(client: Client, csrf: str, level_id: int, code: str) -> dict[str, object]:
    return client.post(GAME_ENDPOINT, {
        "action": "answer_level",
        "csrf_token": csrf,
        "level_id": level_id,
        "answer": code,
    })


def main() -> None:
    expect(
        "MIGI_CHAT_ENABLED=1" in (RUNTIME / "env").read_text().splitlines(),
        "Migi chat is enabled in protected runtime configuration",
    )
    expect(
        (RUNTIME / "secrets" / "migi_fbctf_api_key").stat().st_mode & 0o777 == 0o600,
        "shared-profile API key has protected permissions",
    )
    score_before = sql("CHECKSUM TABLE teams,scores_log,failures_log")
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
    suffix = str(os.getpid())
    title = f"Migi Smoke {suffix}"
    safe_title = title.replace("'", "''")
    initial_code = "MIGI-SMOKE-INITIAL"
    sql(
        "INSERT INTO levels(active,type,title,description,entity_id,category_id,"
        "points,bonus,bonus_dec,bonus_fix,flag,hint,penalty,created_ts) VALUES"
        f"(1,'base','{safe_title}','Temporary Migi Vault transaction test',"
        f"{country_id},{category_id},3,7,0,7,'{initial_code}','',0,NOW())",
    )
    level_id = int(sql(f"SELECT id FROM levels WHERE title='{safe_title}'")[0][0])

    team_password = f"MigiSmoke-{suffix}"
    password_hash = subprocess.run(
        COMPOSE + [
            "exec", "-T", "app", "hhvm", "--php", "-r",
            f'echo password_hash("{team_password}", PASSWORD_DEFAULT);',
        ],
        cwd=ROOT, text=True, capture_output=True, check=True,
    ).stdout.strip()
    team_names = [f"Migi Smoke A {suffix}", f"Migi Smoke B {suffix}"]
    for name in team_names:
        safe_name = name.replace("'", "''")
        safe_hash = password_hash.replace("'", "''")
        sql(
            "INSERT INTO teams(active,name,password_hash,points,last_score,logo,"
            "admin,protected,visible,created_ts) VALUES"
            f"(1,'{safe_name}','{safe_hash}',0,NOW(),'1',0,0,1,NOW())",
        )
    team_ids = [
        int(sql(f"SELECT id FROM teams WHERE name='{name}' LIMIT 1")[0][0])
        for name in team_names
    ]

    config_before = dict(sql(
        "SELECT field,value FROM configuration "
        "WHERE field IN ('game','game_paused','scoring')",
    ))
    activity_floor = int(sql("SELECT COALESCE(MAX(id),0) FROM activity_log")[0][0])
    compose("restart", "cache")

    first: Client | None = None
    second: Client | None = None
    api_secret = (RUNTIME / "secrets" / "migi_fbctf_api_key").read_bytes().strip()
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
                    f"http://127.0.0.1:{port}{CHAT_ENDPOINT}",
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

        first, csrf_first = login(team_names[0], team_password)
        second, csrf_second = login(team_names[1], team_password)
        country_data_raw = first.get("/data/country-data.php")
        observed.append(country_data_raw)
        country_data = json.loads(country_data_raw)
        migi_row = next(row for row in country_data.values() if row.get("level_id") == level_id)
        expect(
            migi_row.get("type") == "base"
            and migi_row.get("category") == "Migi"
            and migi_row.get("migi_vault") is True,
            "temporary challenge uses the existing Base and category systems",
        )
        expect(migi_row.get("migi_chat") is True, "Migi Vault advertises chat availability")
        expect(
            all(
                not row.get("migi_chat")
                for row in country_data.values()
                if row.get("level_id") != level_id
                and not (row.get("type") == "base" and str(row.get("category", "")).lower() == "migi")
            ),
            "ordinary challenges do not advertise Migi chat",
        )

        invalid, raw = chat(first, "invalid", level_id, "history")
        observed.append(raw)
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

        sql("UPDATE configuration SET value='1' WHERE field IN ('game','scoring')")
        sql("UPDATE configuration SET value='0' WHERE field='game_paused'")
        compose("restart", "cache")
        captured_first = capture(first, csrf_first, level_id, initial_code)
        expect(captured_first.get("result") == "OK", "first team captures Migi's Vault")
        owner = sql(f"SELECT owner_team_id,ownership_version FROM manual_base_state WHERE level_id={level_id}")[0]
        expect(owner == [str(team_ids[0]), "1"], "capture transfers Base ownership and advances its version")
        rotated_once = sql(f"SELECT flag FROM levels WHERE id={level_id}")[0][0]
        expect(rotated_once != initial_code and rotated_once.startswith("MIGI-"), "capture rotates the authoritative code")
        expect(sql(f"SELECT points FROM teams WHERE id={team_ids[0]}") == [["7"]],
               "configured capture reward is awarded once")

        reset_history, raw = chat(first, csrf_first, level_id, "history")
        observed.append(raw)
        expect(reset_history.get("history") == [], "conversation resets when the secret version changes")
        stale = capture(second, csrf_second, level_id, initial_code)
        expect(stale.get("result") == "ERROR", "stale code is rejected")
        expect(sql(f"SELECT points FROM teams WHERE id={team_ids[1]}") == [["0"]],
               "stale code awards no points")

        captured_second = capture(second, csrf_second, level_id, rotated_once)
        expect(captured_second.get("result") == "OK", "second team recaptures the Vault")
        owner = sql(f"SELECT owner_team_id,ownership_version FROM manual_base_state WHERE level_id={level_id}")[0]
        expect(owner == [str(team_ids[1]), "2"], "recapture transfers ownership atomically")
        rotated_twice = sql(f"SELECT flag FROM levels WHERE id={level_id}")[0][0]
        expect(rotated_twice != rotated_once, "recapture rotates the code again")
        duplicate = capture(second, csrf_second, level_id, rotated_twice)
        expect(duplicate.get("result") == "ERROR", "current owner cannot duplicate-capture the Vault")
        expect(sql(f"SELECT points FROM teams WHERE id={team_ids[1]}") == [["7"]],
               "duplicate capture does not award the reward again")
        expect(sql(
            f"SELECT COUNT(*) FROM manual_base_points_ledger "
            f"WHERE level_id={level_id} AND reward_type='capture'",
        ) == [["2"]], "capture ledger contains one idempotent reward per ownership version")
        sql(
            "UPDATE manual_base_state SET accrual_seconds=1,"
            "last_accrual_ts=DATE_SUB(NOW(),INTERVAL 2 SECOND) "
            f"WHERE level_id={level_id}",
        )
        capture(second, csrf_second, level_id, "DELIBERATELY-WRONG")
        expect(
            int(sql(f"SELECT points FROM teams WHERE id={team_ids[1]}")[0][0]) >= 13,
            "current owner earns passive points through existing Base accrual",
        )
        expect(sql(
            f"SELECT COUNT(*) FROM manual_base_points_ledger "
            f"WHERE level_id={level_id} AND reward_type='hold'",
        ) == [["1"]], "passive Base interval is recorded idempotently")
        expect(all(api_secret not in payload for payload in observed),
               "Hermes API credential never appears in browser responses")

        compose("stop", "migi-relay")
        unavailable, _ = chat(first, csrf_first, level_id, "send", "This request should fail safely.")
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
        for field, value in config_before.items():
            safe_value = value.replace("'", "''")
            sql(f"UPDATE configuration SET value='{safe_value}' WHERE field='{field}'")
        sql(f"DELETE FROM activity_log WHERE id>{activity_floor}")
        sql(f"DELETE FROM failures_log WHERE level_id={level_id}")
        sql(f"DELETE FROM manual_base_points_ledger WHERE level_id={level_id}")
        sql(f"DELETE FROM scores_log WHERE level_id={level_id}")
        sql(f"DELETE FROM manual_base_state WHERE level_id={level_id}")
        sql(f"DELETE FROM levels WHERE id={level_id}")
        for team_id in team_ids:
            sql(f"DELETE FROM teams WHERE id={team_id}")
        if created_category:
            sql(f"DELETE FROM categories WHERE id={category_id}")
        compose("restart", "cache")

    expect(sql(f"SELECT COUNT(*) FROM levels WHERE id={level_id}") == [["0"]],
           "temporary Migi Vault was removed")
    expect(sql("CHECKSUM TABLE teams,scores_log,failures_log") == score_before,
           "live scoring state was restored exactly")


if __name__ == "__main__":
    main()
