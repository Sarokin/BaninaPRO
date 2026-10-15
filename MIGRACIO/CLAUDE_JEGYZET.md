# Handoff note for Claude – DOS + IB System migration (written 2026-10-16, on the iMac)

Read this first when the user opens a session about the migration. On the iMac the context lived in session
transcripts and local memory, which don't exist on the MacBook. This note replaces them. The user writes Hungarian;
answer in Hungarian.

## The task

The user's request (2026-10-15):
- They bring the **whole DOS program folder** (more data was entered into DOS after 2026-10-06) and the **IB System
  "Számla napló" xlsx exports** (bejövő + kimenő).
- I migrate both "perfectly" into the live BaninaPRO database and **hand back the whole migrated database**. The
  deliverable is one `.sql` in the app's own backup format, loaded on the internal server with the "SQL IMPORT" desktop
  icon (`SERVER SETUP AND UPDATE/SQL_IMPORT.sh`).

They do it on **2026-10-16 at the site with their MacBook** (Docker Desktop is installed there). The internet at the
site is unknown, so `kornyezet.sh elokeszit` has to run with internet beforehand.

## What is known (don't overclaim)

- **I have never seen the DOS program's files.** No session had them. The user believes I "know the DOS program". What
  I know is the DOS data *as it landed in BaninaPRO*:
  - On 2026-10-07 12:53–12:55 an external program (user name "DOS import", written on another machine, not available)
    imported the DOS state of 2026-10-06 21:00.
  - It left the DOS keys and raw values on every record (in `megjegyzes`). The rules are reverse-engineered in
    `eszkozok/DOS_LEKEPEZES.md`.
  - If the user still has that importer, it would shortcut everything: ask for it.
- The DOS records are partners (VEVO / UGYF), kötések (`0-26-1234`), tételek (kötésszám/kisszám `0-26-1234/12A3`),
  forintszámlák (`F-…`), deviza- / forintutalások (`#012345`), kimenő számlák (`KE-26 1234`) with befolyás.
  - Likely file or table names: VEVO and UGYF (the old importer labelled codes so).
  - Dates were stored as text (`26.04.31`, `00.00.00`).
- **IB System export:** the app's import (`includes/import.php`) handles it fully. `php/ib_import.php` runs that code
  from the CLI with the Import page's default selection.

## What is ready and tested (all in `MIGRACIO/eszkozok/`)

| Tool | Does | Tested |
|---|---|---|
| `kornyezet.sh` | MariaDB 10.11 (as on the server) + the app container + Python in Docker; `elokeszit`, `onteszt`, `betolt`, `sema` (the installer's own schema sync), `php`, `sql`, `felderit`, `py`, `mentes`, `web` (localhost:8090) | yes, also from a fresh GitHub clone on an empty Docker (2026-10-16, self-test 12/12 in 11 s) |
| `dos_felderito.py` | inventory, DBF (dBase/Clipper/FoxPro, DBT/FPT memo) → CSV, code page detection (CP852 / CWI-2 / …), program detection from the EXE, **field auto-mapping against the known values**, byte search + record length for unknown binaries | synthetic DBFs built from real data: 100% |
| `php/ismert_dos.php` | known DOS keys/values of every record in a DB → `ismert_dos.json` (the yardstick) | yes |
| `php/dos_atvetel.php` | applies `dos_koztes.json`: new records in the 10-07 format, fixes, payments, terhelés fill, IB-archive moves; field-level conflict rules from `valtozasnaplo`; `--szaraz` = full run + rollback; idempotent | 10-06 rebuild: 15788 egyezik / 0 diff; simulated delta: all cases; rerun: 0 changes; after IB import: IB values kept, 0 conflicts |
| `php/ib_import.php` | the app's own IB import from the CLI (`--szaraz`, `--felulir`, `--osszevon`, `--csoportok`) | full-year kimenő: same numbers as the 10-13 measurement |
| `php/ellenorzes.php` | integrity (FK, codes, norm, sorszámok, statuses, utalás currency…); exit 1 on HIBA | 10-06 data: HIBA 0 |
| `php/osszevet.php` | two DBs, record by record and field by field, + balances | yes |
| `teszt/minta_koztes.json` + `onteszt` | the whole chain on **made-up data** (no business data), 12 checks, about 11 s | MINDEN RENDBEN |

**The only missing piece is the DOS-format converter** (DOS files → `dos_koztes.json`, format in
`DOS_LEKEPEZES.md` → „Köztes formátum”). Write it once the files are seen:
1. `./kornyezet.sh betolt <live dump> elo_HHMM`
2. `./kornyezet.sh felderit elo_HHMM`
3. Read `4_EREDMENY/dos_felderites/felderites.txt`: program, tables, code page, which field is which (match ratios), and
   the header dates (which tables changed since 10-06).
4. Write `eszkozok/dos_koztes.py` from the CSVs. Run it with `./kornyezet.sh py`.
5. `dos_atvetel.php --szaraz`. **The converter is correct only when every old record is „egyezik”** apart from the real
   DOS changes since 10-06, which show up as fixes or payments; spot-check those against DOS.

Then follow `eszkozok/FUTTATAS.md` steps 4–6.

## Rules for this work

- **Never commit or push business data. The repo is public**: `api.github.com/repos/Sarokin/BaninaPRO` answers without
  auth, checked on 2026-10-16.
  - `.gitignore` excludes the contents of `1_DOS_PROGRAM`, `2_IB_SYSTEM`, `3_BANINAPRO_ELO_ADATBAZIS`, `4_EREDMENY`, plus
    `eszkozok/teszt/probak/`, `teszt/*.xlsx` and `teszt/*.json` (except `minta_koztes.json`).
  - On 2026-10-16 the user asked to "push everything, I'll delete it later". Only tools and docs were pushed: a public
    push can't be undone by deleting. If they want the data on another machine, use AirDrop, a pendrive or a private repo.
  - Before every commit: `git status --short --untracked-files=all`, and look at the paths.
- Code you add for the migration (the converter, fixes) goes to git after it is verified. Never put real names, tax
  numbers or bank accounts in code or comments. Tests read real values from data files at run time.
- Always dry-run first. Deliver only with `ellenorzes` HIBA 0 and a reload test with 0 differences.
- The freeze window: nobody works in BaninaPRO from the live dump until the result is loaded. The pipeline is
  re-runnable, so do a trial on an early dump, then the final run on a fresh one (a few minutes).
- `MIGRACIO/.htaccess` (deny all) and `index.php` (403) protect the folder on the server, where the repo is the web
  root. The PHP tools refuse non-CLI SAPIs. Keep both.

## Open decisions – ask the user (asked on 2026-10-15, no answer yet)

1. Where do they work now: the internal server or the cPanel web version? The live dump comes from there, and the result
   goes back there.
2. Since 10-07, did they also record transfers or payments in BaninaPRO, and has the office manager already imported IB
   files into the live DB? Both are handled; this only tells how many conflicts to expect.
3. Status of invoices that are in IB but not in DOS. Default: FIZETENDŐ / NYITOTT, as the Import page does; they show
   up in the deadline list. Proposed: list them by month in the report, so older ones can be marked settled.
4. Conflicts default to "the BaninaPRO value stays, listed". IB differences on invoices inside a transfer are not
   changed but listed: the user's earlier rule, see the 1.23 notes in `CLAUDE.md`.

## Gotchas met while building

- The Bash tool runs **zsh**: an unquoted `$VAR` does not word-split; use explicit lists or arrays.
- macOS `/bin/bash` is 3.2; `kornyezet.sh` is 3.2-safe. Check it with `bash -n` in `ubuntu:24.04` and with shellcheck.
- PHP: `"$k×$n"` treats `×` as part of the variable name. Use `"{$k}×{$n}"`.
- A DBF header can hold a `0x0D` byte inside a field descriptor (a field of length 13). Look for the terminator only at
  32-byte descriptor starts.
- The mysql client in a container needs `--default-character-set=utf8mb4`, or accents print as `�`.
- `audit_ertek()` and `AUDIT_CIMKEK` give the labels the UI expects. The app's own audit text decides "touched by IB":
  `leiras` starts with "Excel import".
- A fresh clone has no `includes/config.php` (gitignored), and the app container mounts the repo read-only, so Docker
  could not create the mountpoint for the migration config ("read-only file system"). `app_inditas` now creates the
  empty file first, as the dev `docker compose` does. Found by the fresh-clone test on 2026-10-16.
- On a new volume MariaDB first runs a temporary server without networking, then restarts. `db_var` waits on TCP, so it
  returns only once the final server is up.
- To test the environment as a brand-new machine without touching the existing one:
  `MIG_ELOTAG=bp-migproba MIG_PORT=8091 ./kornyezet.sh elokeszit` from a fresh clone. It gets its own containers,
  volumes and network; remove them afterwards.
