# FBCTF restoration and local operations

## Current result

The restored application runs locally at `http://127.0.0.1:8080/`. It retains
the original Hack backend, XHP-rendered pages, game board, administrator UI,
challenge model, scoring rules, and visual assets.

This is deliberately an **interim compatibility deployment**. HHVM 3.30.12 is
unsupported and is confined to an immutable Docker image. It is not installed
on the host and is not suitable for direct public exposure. MariaDB 11.8,
memcached 1.6, nginx 1.28, and the Node 24 asset builder are pinned by image
digest. The web port is published only on `127.0.0.1`.

## Why this path was selected

Three approaches were evaluated:

1. **Isolated compatibility runtime — selected for restoration.** The official
   HHVM 3.30.12 image still provides the async MySQL, memcached, XHP, password,
   and PHP-compatibility surface used by FBCTF. It runs the original application
   with only a loopback-cookie compatibility adjustment. This is the fastest
   path to a real system and preserves the original experience.
2. **Port to current Hack/HHVM — rejected for the immediate goal.** FBCTF is
   Hack (`<?hh`), not PHP, but its Composer graph is old PHP. HHVM stopped PHP
   compatibility after 3.30 and later removed or changed several APIs and
   legacy collection/array behaviors used here. This route requires migrating
   both the application and third-party dependencies before it can produce a
   working game.
3. **Incremental backend migration — recommended long term.** Preserve the
   schema, routes, UI behavior, and scoring contracts while replacing bounded
   endpoints behind integration tests. This costs more initially but leads to a
   maintained native runtime. No target framework has been selected yet; that
   choice should follow endpoint and data-contract capture, not precede it.

No obsolete package repository, unauthenticated package, global host runtime,
TLS bypass, firewall change, Tailscale change, or unrelated service change is
used by this deployment.

## Local operation

Prerequisites are Docker with the Compose plugin, `openssl`, `curl`, and
Python 3. From the repository root:

```bash
./scripts/fbctf-local start
./scripts/fbctf-local credentials
./scripts/fbctf-local status
./scripts/fbctf-local logs
./scripts/fbctf-local restart
./scripts/fbctf-local stop
```

`start` generates random database and administrator credentials under
`.runtime/` on first use, builds pinned images, initializes MariaDB, and waits
for HTTP readiness. `.runtime/` is excluded from version control and restricted
to the local account. Do not delete it while retaining the database volume: the
stored administrator hash and displayed password must remain paired.

To use another loopback port, edit `.runtime/env` after `setup`, then restart:

```bash
./scripts/fbctf-local setup
sed -i 's/^FBCTF_PORT=.*/FBCTF_PORT=8081/' .runtime/env
./scripts/fbctf-local start
```

## Verification

Run the automated end-to-end workflow against the live application:

```bash
./scripts/fbctf-local smoke
```

It verifies the landing page and built assets, administrator login, rejection
of anonymous administration and invalid CSRF tokens, configuration changes,
quiz and flag creation, player registration/login, correct submissions,
duplicate-solve rejection, exact scoring, bcrypt password storage, leaderboard
data, session/database persistence across a full restart, and post-restart
login.

Run the contest workflow after applying its additive migrations:

```bash
./scripts/fbctf-local contest-smoke
```

This creates uniquely named test teams and a contest in the live game, then
verifies country-attached contest levels, team-level entry updates, separate
browser sessions for one team earning vote rewards up to the configured team
size, duplicate-vote idempotency, image validation, explicit winner
finalization, winner-reward idempotency, leaderboard totals, and persistence
after an app/web restart. The records are retained as audit evidence; close or
hide them through the administrator interface after use.

Contest voting credits one vote per browser session. The per-team cap uses the
number of stored team-member registration rows when present and otherwise uses
Admin Configuration > Registration > Players Per Team.

The nginx boundary supplies CSP, anti-framing, MIME-sniffing, referrer-policy,
and request-rate controls. Sessions are regenerated at login, stored in the
database, and marked HttpOnly. Cookies are Secure when nginx reports HTTPS and
remain usable on loopback HTTP during local development.

## Backup, restore, upgrade, and rollback

Create a timestamped backup containing the database, administrator credential,
attachments, and custom logos:

```bash
./scripts/fbctf-local backup
```

Restore a selected backup (the command requires typing `RESTORE`):

```bash
./scripts/fbctf-local restore "$PWD/backups/20261027T160000Z"
```

Before an upgrade, create a backup and record the current Git revision. Update
the pinned image digests and dependency locks in a reviewable commit, rebuild
with `start`, and rerun `smoke`. Roll back by returning to the recorded revision,
running `start` to rebuild those images, and restoring the pre-upgrade backup if
the schema or stored data changed.

Existing databases are upgraded explicitly, after the backup has been checked:

```bash
./scripts/fbctf-local backup
test -s backups/<timestamp>/fbctf.sql
./scripts/fbctf-local migrate
docker compose --env-file .runtime/env -f compose.compat.yml up -d --build --no-deps app web
```

Migrations are additive and idempotent. The database volume is never removed or
recreated by this procedure. A fresh database receives the same migrations from
the MariaDB initialization directory.

## Mobile input and contests

The game board defaults to its original list view below 960 pixels while the
desktop map and administrator interface remain available. Challenge answer
dialogs include **Scan QR** and **Scan NFC** controls. A scanned value only fills
the existing answer field; the player must still submit it through FBCTF's
unchanged validation and scoring path. URLs containing `?answer=...` prefill the
same field when a challenge is opened. Camera and Web NFC APIs require a secure
browser context; loopback works for desktop testing, while phones require HTTPS.

**Admin → Contests** creates a contest as an FBCTF `poll` level attached to an
unused country. Active, visible contests therefore appear on the original map
and list; selecting one opens its mobile-friendly entry and voting page. Team
submissions open with the FBCTF game and close when the game ends. Each team has
one updatable entry.

Voting uses the existing authenticated browser session as its lightweight
identity. One session can vote once per contest, and at most two distinct
sessions from a team can vote. A vote change does not award points again. The
database stores a SHA-256 session identifier, never the session cookie itself.
This is a practical two-player party rule, not proof of human identity: one
person could deliberately use two browsers, but a team cannot exceed two
credited sessions. Vote and winner rewards use an idempotent ledger and
transactional increments to the existing team score, so the original
leaderboard remains the source players see.

Voting schedule fields are UTC, matching both app and database containers.
Leaving a voting boundary empty keeps that side of the window open. Uploaded
images are verified by decoded image type, limited to 10 MiB, renamed randomly,
stored in the persistent attachments volume, and served through an authenticated
non-executable media endpoint.

Web NFC availability is browser-dependent (primarily Chromium-based Android
browsers). iPhone browsers fall back to manual input or can use the native
camera/NFC reader with an `?answer=` URL. Scans never auto-submit.

## Party music, karaoke, and TV

The administrator music console is at **Admin → Party Music**. Paste one
YouTube URL per line to preload the queue. To supply a title, use
`Display title | YouTube URL`. URLs are reduced to validated 11-character
YouTube video IDs; arbitrary iframe or HTML input is never stored or rendered.
The console can reorder and remove queue entries, play a selected item, pause,
resume, skip, moderate guest requests, and schedule a request at the end, next,
or immediately.

Authenticated teams use **Music** on the gameboard to submit regular or
karaoke requests. Requests remain separate from the playing queue until an
administrator schedules them. Only the administrator endpoints can change
playback or award karaoke points.

Open `/tv` in Chrome, press **Start TV Audio** once, then enter fullscreen or
cast that browser tab. The TV uses the YouTube IFrame Player API and polls the
server for commands without reloading the page. A renewable server lease makes
one TV the active player; other open TV pages remain read-only standby displays.
End-of-video reports include the current queue ID and are transactionally
ignored after the first successful advance. If embedding fails, both the TV and
administrator pages provide a direct YouTube link and the administrator can
skip the item.

Karaoke configuration can optionally create two four-character FBCTF map level
types: `actv` for Karaoke Performance and `stag` for Karaoke Stage. Selecting
either country sends the team to the Music page. Confirming a distinct karaoke
queue entry awards the configured performance points once, records the team,
song, administrator, and timestamp, and transfers stage ownership. The existing
singleton autorun process awards full passive-minute intervals through an
idempotent ledger. It does not use Base endpoint polling and stops its cursor
while the game is paused, ended, or scoring is disabled.

Run the cleanup-safe music integration workflow with:

```bash
./scripts/fbctf-local music-smoke
```

The test exercises playlist ordering, two team requests, remote commands, TV
leasing and advancement, performance idempotency, passive scoring, ownership
transfer, and restart persistence. It restores the existing teams' points and
removes only the music and activity records it created.

For a non-destructive production readiness check covering the existing
challenge, contest, music, karaoke, runtime configuration, and restart paths:

```bash
./scripts/fbctf-local party-smoke
```

This check creates and removes only its administrator session. It does not add
teams, challenges, contests, songs, votes, performances, or points.

## Security and public-exposure gate

Do not enable Tailscale Funnel yet. Before public exposure:

- terminate trusted HTTPS and confirm `X-Forwarded-Proto: https` reaches nginx,
  so session cookies are Secure;
- add an explicit SameSite cookie policy (HHVM 3.30 predates the modern cookie
  options API used by maintained runtimes);
- replace the compatibility backend or complete a focused security audit of
  HHVM 3.30 and all locked Composer/browser dependencies;
- add account-level login throttling/lockout in addition to nginx IP limiting;
- review upload handling and disable unused OAuth, LiveSync, import/export, and
  base-scoring surfaces for the event;
- establish off-host encrypted backups and test recovery;
- run the smoke test through the exact Funnel/TLS route without changing the
  existing Tailscale or firewall configuration during this phase.

## Future extension points (not implemented)

Keep new capabilities outside the legacy model until a maintained service
boundary exists:

- QR and NFC tags should resolve opaque, revocable challenge URLs to existing
  level IDs; tags must not contain flags or award points directly.
- Host-awarded performance rewards should issue short-lived, single-use codes
  recorded as auditable completion events.
- Cooperative/social challenges should use a completion record with actor,
  team, challenge, evidence/status, and idempotency key rather than overloading
  `scores_log`.
- Per-player AI interactions should store conversation references separately
  from authoritative scoring data and never let model output award points.
- `migi-fbctf` should integrate as an external least-privilege service through
  authenticated, versioned APIs/webhooks with replay protection and an audit
  log.
- Completion webhooks should be emitted from committed completion events and
  retried idempotently; consumers must not sit inside the scoring transaction.

The existing `(team_id, level_id)` uniqueness rule is the idempotency anchor for
classic quiz/flag solves. A future completion-event table can generalize that
contract without changing current gameplay.

## License and attribution

The original Facebook attribution, branding, and CC BY-NC 4.0 `LICENSE` remain
in the repository and application. Any distribution or event use must continue
to comply with the noncommercial and attribution terms.
