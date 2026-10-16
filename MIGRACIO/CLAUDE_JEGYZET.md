# Handoff note for Claude – DOS + IB System migration (updated 2026-10-16, done)

Read this first in a session about the migration. The user writes Hungarian; answer in Hungarian. The how-to is
`eszkozok/FUTTATAS.md`; this note records what was decided and why.

## Status (2026-10-16)

The migration is **done**. The result is a fresh BaninaPRO database (`MIGRALT_SQL_EREDMENY_ADATBAZIS/BaninaPRO_migralt_*.sql`,
gitignored), built by `eszkozok/migral.sh` from the DOS state of 2026-10-16 and the IB System kimenő export. The user loads it
on the internal server **with phpMyAdmin** (their choice); `OLVASS_EL.txt` in that folder has the steps. Rerunning `migral.sh`
rebuilds everything from the inputs in about 3 minutes, identically (two independent runs gave the same database).

## Decisions the user made (2026-10-16) – keep them

- **Fresh database.** The BaninaPRO database used so far was a test; nothing in it is needed. Users and passwords are recreated by
  hand; the result has only the schema's initial `admin`. So no live dump is needed and the delta path (`dos_atvetel.php`) was not
  used for the delivery.
- **All invoices from 2026-01-01, no upper date limit** („semmi ne maradjon ki”): future-dated DOS records are included.
- **Bejövő = DOS only** (no IB bejövő export). **Kimenő = IB for number, amount, dates and customer; DOS for payment.**
  IB-only kimenő → NYITOTT. A DOS-paid invoice stays paid even if the IB amount differs by cents (the 10-07 rule „ha ki lett
  fizetve, ne szerepeljen nyitottként”).
- **Company merges:** by identical tax number automatically; by name **only with the user's approval**. The approved pairs and the
  „keep separate” list are in `4_EREDMENY/ceg_osszevonasok_teljes.txt` (business data – never in the repo). The user rejected some
  similar-name pairs (different tax numbers, or two partners with the same brand); do not propose them again without new evidence.
- **Kimenő invoice under the IB customer** when the DOS recorded it under another customer (approved). Implemented per IB partner:
  tax number → where its invoices are (unless contradicted by tax number on few invoices) → name → new company.
- **DOS duplicates:** an unpaid DOS record with a mistyped number that the IB does not know, whose paid twin (same company, amount,
  day) the IB matched, is deleted (approved).
- KE = EUR, KH = HUF kimenő (the user's note). Company names the user can rename in the app.

## What was found on the way (no business data here; the details are in the generated JELENTES.txt)

- The original 2026-10-07 DOS importer exists on the MacBook: `~/VS CODE PROJECTS/BANINA_ADATOK/DOS_KINYERES` with `INFO.md`
  (field maps verified against the DOS program's own printed lists). It is copied, sanitized, into `eszkozok/dos/`. The memory
  notes of those sessions are in `~/.claude/projects/-Users-sarokin-VS-CODE-PROJECTS-BANINA-ADATOK/memory/`.
- The app's IB import identifies a kimenő invoice by number alone, even at another company. A DOS-mistyped number can therefore
  pull IB data onto the wrong invoice; the migration frees such numbers first (`ib_import.php` pre-step, „ (DOS)” marker).
- The app's company grouping by invoice votes can merge two IB partners into one company when the DOS put an invoice under the
  wrong customer; the migration regroups per IB partner code before importing (`--vevo=ib`).
- `includes/import.php` read the amount only from column Z (Bruttó dev); IB leaves Z empty for HUF rows (BH/KH) and puts the
  amount in W. Fixed in 1.24.4 (reads W for HUF when Z is empty).
- DOS data files: TurboPower B-Tree Filer; check header record count vs file size before trusting a copy (done in this run).

## Rules for this work

- **Never commit or push business data. The repo is public.** Inputs and outputs live in gitignored folders (`DOS_ADATOK*`,
  `IB_SYSTEM*`, `MIGRALT_SQL_EREDMENY_ADATBAZIS`, `1_…`–`4_…`). No real names, tax numbers, account numbers or invoice numbers in
  code or comments; data-dependent report text reads from the gitignored files at run time.
- Before every commit: `git status --short --untracked-files=all`, and grep the diff for names and numbers.
- Always verify with several independent methods (09 DOS check, `ellenorzes.php`, independent byte-level DOS checker, IB
  re-analysis, reload test, phpMyAdmin import test, app API smoke test, rebuild comparison).
- `MIGRACIO/.htaccess` (deny all) and `index.php` (403) protect the folder on the server; the PHP tools refuse non-CLI SAPIs.

## Gotchas

- The Bash tool runs **zsh**: an unquoted `$VAR` does not word-split. macOS `/bin/bash` is 3.2; `grep` here is ugrep.
- `python -I` hides the script's own folder from imports: run the exporter with `python -B` from `eszkozok/dos`.
- PHP: `"$k×$n"` treats `×` as part of the variable name; use `"{$k}×{$n}"`.
- The mysql client in a container needs `--default-character-set=utf8mb4`.
- The app's import strips a trailing `[…]` suffix when comparing invoice numbers; use `(DOS)` (round brackets) to really free a number.
- A fresh clone has no `includes/config.php`; `kornyezet.sh` creates the empty mountpoint.
