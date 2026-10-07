# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

BaninaPRO is an invoice-tracking web app (incoming/outgoing invoices, bank transfers) for a Hungarian company. Stack: **PHP 8 + MySQL/MariaDB, no framework, no Composer, no npm, no build step**. It is deployed by uploading files to cPanel shared hosting (Apache/LiteSpeed with `.htaccess`). The UI is mobile-first (iPhone, 375 px) and supports only a light theme.

All identifiers, comments, UI text, log codes and DB columns are **in Hungarian**. Keep new code in Hungarian to match. `TELEPITES.md` is the authoritative install and user guide; it documents every feature and every version change. Update it when behavior changes. `APP_VERSION` lives in `includes/config.php`, and `TELEPITES.md` ends with a matching version line.

Glossary: `ceg` = company, `kotes` = deal/batch grouping incoming invoices, `bejovo`/`kimeno` = incoming/outgoing invoice, `szamla` = invoice, `szamlaszam` = invoice number, `utalas` = bank transfer, `reszteljesites` = partial payment, `hatralek` = outstanding balance, `BESZÁM` = free-text warehouse reference field, `naplo` = log, `mentes` = backup, `visszaallit` = restore, `jog` = permission, `szerep` = role, `be_*` = input parsing, `hiba` = error.

## Commands

There is no build, lint or test tooling. PHP/MySQL are not installed on the host. Run everything through the Docker environment (`docker-compose.yml` + `docker/`): PHP 8.3 + Apache, MySQL `latest`, and phpMyAdmin.

```sh
docker compose up -d --build                 # app http://localhost:8080 · phpMyAdmin http://localhost:8081 · MySQL localhost:3307
docker compose down                          # stop (data kept)   ·   down -v also wipes the DB + log/backup volume
docker exec baninapro-app php -l includes/api_bejovo.php          # syntax-check a file
docker exec baninapro-app php -q cron_mentes.php                  # full DB backup now (prod cron runs this at 03:00)
docker exec baninapro-app php -q cron_mentes.php lista            # list backups
docker exec baninapro-app php -q cron_mentes.php vissza <file>.sql   # restore (an automatic backup is taken first)
docker exec baninapro-app tail -f /var/lib/baninapro/LOG/$(date +%Y%m%d).txt   # activity/error log
```

Docker details:
- The container mounts `docker/config.php` over `includes/config.php`, with DB host `db`, a local password and `APP_DEBUG = true`. **The production `includes/config.php` is never used in Docker.** If you add a constant to `config.php`, add it to `docker/config.php` and `includes/config.example.php` too.
- Logs and backups go to the `adatok` volume (`/var/lib/baninapro/{LOG,DBBCKP}`), not to the project's `LOG/` and `DBBCKP/`, which hold real production data.
- On first start (empty DB volume) MySQL runs `sql/schema.sql` from `docker-entrypoint-initdb.d`. The initial login is `admin` / `BaninaPRO-2026!`. To re-run it, `docker compose down -v` first.
- PHP is pinned to 8.3: PHP 8.4 emits deprecations for the implicit nullable params (`string $x = null`), and with `APP_DEBUG` these break JSON responses.

### Frontend tests (Playwright, `tests/e2e/`)

```sh
tests/e2e/futtat.sh                                  # all tests: desktop Chrome + iPhone X (375 px)
tests/e2e/futtat.sh tests/bejovo.spec.ts:44          # one file / one test (args go to `playwright test`)
tests/e2e/futtat.sh --project=iphone                 # only the mobile run
WEBKIT=1 tests/e2e/futtat.sh --project=iphone-safari # real Safari engine (slow in Docker, opt-in)
```

The tests run entirely in Docker via `docker-compose.teszt.yml` (separate project `baninapro-teszt`). The Playwright image tag must match the `@playwright/test` version in `tests/e2e/package.json`. Each run gets a **fresh, empty DB on tmpfs**, never the dev DB, and the environment is torn down afterwards. The HTML report goes to `tests/e2e/playwright-report/`; on failure, screenshots and traces go to `tests/e2e/test-results/`.
- Browsers reach the app at `http://baninapro`, a network alias. **Never use the service name `app` as the host**: `.app` is a real TLD, so Chrome forces it to HTTPS (HSTS preload) and WebKit rejects session cookies on it.
- Tests import `test`/`expect` and helpers from `tests/segedek.ts`:
  - `belepes(page, felh?)` logs in through the API into the page's cookie jar;
  - `Api.kell()` / `Api.hiv()` call `api.php` directly, handling CSRF, for quick test-data setup;
  - `egyedi()` makes run-unique names, because both browser projects share one DB;
  - `modal()` and `toast()` locate the app's dialogs and toasts.
- Locators rely on the app's own hooks: `data-act`, `data-form`, `[data-ell=<field>]` live-check messages, `.badge.<STATUS>`, `[data-szamla-id]`, and FAB `aria-label`s such as "Új cég" / "Új kötés" / "Új számla".
- Tests run with `reducedMotion: 'reduce'`. The app honors it, and otherwise clicks wait for animated elements to settle.
- Amounts render with NBSP/NNBSP separators, so match them with `osszegMinta('12 500,50 EUR')` instead of literal strings.

Set `APP_DEBUG = true` in `includes/config.php` to get detailed errors in API responses. It must be `false` in production. `includes/config.php` holds the real production DB credentials, so never copy them elsewhere.

## Architecture

### Request flow
- `index.php` renders an empty shell. It embeds an init JSON blob (`#banina-init`: user, CSRF token, settings, WebAuthn config) and loads `assets/qr.js` and `assets/app.js`, with cache-busting by `filemtime`. It sends a strict CSP (`script-src 'self'`), so there are no inline scripts and no external resources.
- `api.php` is the single JSON endpoint: `POST {"action": "...", ...}` → `{"ok": true, "adat": ..., "csrf": ...}` or `{"ok": false, "hiba", "kod", "extra"}`. It dispatches `action` to a global function **`act_<action>(array $be): array`** defined in one of `includes/api_*.php`. Before dispatch it does the following for non-public actions:
  1. checks login and CSRF;
  2. checks the role via `muvelet_jog($action)`;
  3. blocks requests while a DB restore is running (`karbantartas_aktiv`);
  4. runs a missed nightly backup if one is due (`mentes_ha_esedekes`).
- Public (no-login) actions are listed in `$nyilvanos` in `api.php`. PDOException code 23000 is translated into friendly messages by matching unique-index names (`uq_bejovo_kotes_szamlaszam`, `uq_ceg_nev`, …). Keep those names in sync if you change indexes.
- The other top-level PHP files are standalone endpoints that bootstrap the same includes:
  - `pdf.php`: print basket → A4 PDF, built by `includes/nyomtatas.php` + `includes/pdf.php`;
  - `import.php`: xlsx upload/parse;
  - `mentes_letoltes.php` / `mentes_feltoltes.php`: admin backup download/upload;
  - `cron_mentes.php`: CLI, or URL with `?kulcs=`;
  - `setup.php`: one-time installer, to be deleted after install.

### Adding an API action (checklist)
1. Write `function act_xyz(array $be): array` in the relevant `includes/api_*.php`. If it is a new file, `require_once` it in `api.php`.
2. **Add `'xyz' => 'nez'|'megj'|'ir'|'torol'|'admin'` to `MUVELET_JOGOK` in `includes/jogok.php`.** Unlisted actions default to admin-only.
3. Parse input only with the `be_*` helpers in `includes/helpers.php`. Signal user-facing errors with `hiba($msg, $kod, $extra, $http)`, which throws `ApiError`.
4. Do multi-statement writes inside `db_tx(fn)`. Use `db_row`/`db_all`/`db_val`/`db_exec` with prepared params.
5. Record two trails:
   - `naplo('MUVELET_KOD', 'details')` writes to the daily text log `LOG/YYYYMMDD.txt`, which rolls over at 03:00;
   - `audit_ir(...)` / `audit_diff(...)` (`includes/audit.php`) writes the per-record change history in the `valtozasnaplo` table, shown by the "book" button in the UI.
6. Wire the frontend: call `api('xyz', {...})` in `app.js`.

### Roles and field-level rules (`includes/jogok.php`)
Roles have levels: `admin` 4 > `irodavezeto` 3 > `rogzito` 2 > `uzletkoto` 1. Each permission needs a minimum level: `nez`/`megj` 1, `ir` 2, `torol` 3, `admin` 4.

`megj` actions (the `*_modosit` actions) can be called by everyone, so the action itself must enforce field rules:
- `megjegyzes_szabaly()`: a lower role can only *append* to a note written by someone else. The note author is tracked in `megjegyzes_irta`.
- `beszam_szabaly()`: an `uzletkoto` may set or change BESZÁM but not clear it.

The server is the source of truth. The client only hides UI using `App.user` / `jog('ir')`.

### Domain invariants
- **IDs never repeat, even after deletes.**
  - `kovetkezo_sorszam(tipus, ev, penznem)` uses the `sorszamok` table with `LAST_INSERT_ID` and must run inside a transaction.
  - A deal (kötés) code is `YYYY-CUR-000001`; its incoming invoices get `-K0001…`, from `kotesek.utolso_k` locked with `FOR UPDATE`.
  - A transfer UID is `U-CUR-YYYY-000001`; an outgoing invoice code is `YYYY-CUR-000001`. The formatters are in `helpers.php`.
- **Invoice-number uniqueness** (`includes/ids.php`) compares numbers via `norm_azonosito()` (uppercase, letters/digits only) and the `*_norm` columns:
  - an incoming number must be unique *within one kötés* (blocked, backed by a UNIQUE index); a duplicate elsewhere only gives a warning;
  - outgoing numbers are unique among outgoing invoices.
- **Status flows:**
  - incoming: `FIZETENDO → UTALASHOZ_ADVA → FIZETVE`, or `BESZAMITVA` for a negative amount, which is equivalent to paid;
  - kötés: `NYITOTT/FIZETETT`, derived from its invoices;
  - outgoing: `NYITOTT/FIZETVE`, paid by assigning a bank-statement ID;
  - utalás: `NYITOTT/UTALVA`, plus a `lezarva` lock (1.13) that freezes adding and removing invoices. `utalva_datum` is the transfer date the user enters. `utalva_at` and `utalta` (the API returns `utalta_nev`) record when and by whom it became UTALVA, and since 1.17 both are shown in the header and the list.
- **One currency per utalás** (HUF or EUR), enforced server-side.
- Amounts are DECIMAL, handled as 2-decimal strings (`be_osszeg`, `dec_sum`) and may be negative. Partial payments (`reszteljesitesek`) reduce the outstanding balance (`hatralek`). Lists, deadlines, comparison and search all use the balance, not the original amount.

### Frontend (`assets/app.js`, one IIFE, vanilla JS)
- A hash router: `route()` / `render()` near the end of the file map `#/bejovo/ceg/:id/kotes/:id`, `#/kimeno/ceg/:id`, `#/utalas/:id`, `#/utalasok`, `#/hataridok`, `#/osszevetes`, `#/import`, `#/admin`, `#/profil`, `#/qr/:token`, `#/regisztral/:kod` to `view*()` functions. These functions build HTML with template strings; always escape interpolated data with `esc()`.
- Clicks use **event delegation**: elements carry `data-act="name"`, and the handler is looked up in the `ACT` object. To add a button, add a `data-act` attribute plus an `ACT` entry; do not attach inline listeners.
- Client-side "baskets" persist per browser: `Kosar` (print basket → `pdf.php`), `UtalasKosar` (current transfer collector), `BankKosar` (outgoing invoices awaiting a bank ID). The floating FAB buttons appear when these baskets are non-empty.
- Session heartbeat: the page pings `ping` every `ULES_JELENLET_MP` seconds and sends `lap_zaras` (with keepalive) when the tab closes. The server expires sessions per the `ULES_*` constants (`includes/auth.php`). Heartbeats don't count as user activity.
- **The print basket has no size cap** (user requirement, 1.18.1). Don't reintroduce a limit anywhere on the path:
  - the client has no `KOSAR_MAX`, and `pdf.php` has no `NY_MAX_TETEL`;
  - bulk adds take every match: when the period list is truncated for display (`BEJOVO_IDOSZAK_MAX`), `bejovo_szamlak_idoszak` returns all matches in `osszes`. `kimeno_szamlak` has no `LIMIT`;
  - `pdf.php` raises `memory_limit` to at least 1 GB (≈11 MB per 1000 rows; shared hosts often default to 128M).
- The bejövő kötések page (`viewKotesek`, 1.18) is a full filter: tab (NYITOTT/FIZETETT/MIND) + period.
  - The period lives in `App.kotesIdoszak`. The server applies it in `bejovo_szamlak_idoszak`, with the tab as `statusz`.
  - With a period, tabs work at invoice level: NYITOTT = FIZETENDO + UTALASHOZ_ADVA, FIZETETT = FIZETVE + BESZAMITVA. Without one, they use the kötés status.
  - Tab switches and filtering re-render in place via `kotesekFrissit()`: no skeleton flash, and the scroll position is kept.
  - The company balance at the top uses `BEJOVO_OSSZESITO_MEZOK`, with the same definitions as the kötés card: nyitott = balance of open invoices; fizetett = paid invoices + partial payments of open ones; teljes = nyitott + fizetett.
- **Állapot vizsgálat (1.19)**: the 4th option (`allapot`) of the period filter's date select on the bejövő company page, the kötés page and the kimenő page. It adds a `nap` (examined day) field.
  - One data source: `allapot_vizsgalat_adat()` in `includes/api_riport.php` (action `allapot_vizsgalat`, and the `allapot` print-basket type). It filters by **teljesítés dátuma** in tol–ig (the user's choice, 1.19.1) and derives each invoice's state on `nap` from existing dates, with no schema change: teljesites_datum > nap → `MEG_NEM_LETEZETT`; paid with payment date ≤ nap → `FIZETVE`/`BESZAMITVA`; otherwise `FIZETETLEN`, with the balance reduced only by partial payments dated ≤ nap. The payment date is `COALESCE(b.fizetve_datum, u.utalva_datum)` for bejövő and `fizetve_datum` for kimenő. Old imported paid bejövő invoices have none, so the due date stands in (`fizetve_becsult`).
  - The summaries (`egyenleg` per currency, `kotesek` per kötés) use the same field names as `bejovo_osszesito_szamok()` plus `nem_letezett_db`, so `cegEgyenlegDoboz` and `KOTES_FUL_SZAMLA` work unchanged.
  - The per-invoice rule lives in `allapot_napon()` and the row loader in `allapot_sorok()`. The Összevetés page (1.20) uses both too: `osszevetes_reszletek_adat()` and `act_osszevetes_egyenleg()` take a `nap` (default today), so the comparison, its PDF and the company balance at the top all show the state on that day. With today's date the result equals the current state, except for invoices whose teljesítés is in the future.
  - Applying it switches the tab to MIND. After that the tabs filter by the state on the examined day (`ALLAPOT_FUL`). On the company page it lives in `App.kotesIdoszak` (`mezo: 'allapot'`); on the kötés and kimenő pages it lives in `App.allapotSzuro`, those views fetch it alongside their data, and they replace the card list with the state list. A `?szamla=` jump leaves the mode.
- Design tokens are CSS variables at the top of `assets/app.css`. Animate only `transform`/`opacity`, and respect `prefers-reduced-motion`.
  - An animation with fill mode `both` keeps its last keyframe and overrides later `transform`/`opacity` rules (`:hover`, `:active`, state classes). Use `backwards` when the element must react afterwards (see `.fab-urit`).

### No external libraries: self-written components
- `includes/pdf.php`: PDF writer with embedded TTF fonts from `assets/fonts/`.
- `includes/xlsx.php`: xlsx reader that needs no zip extension.
- `includes/webauthn.php`: passkey verification (CBOR, COSE→PEM).
- `assets/qr.js`: QR generator.
- `includes/backup.php`: pure-PHP dump/restore with no mysqldump. Before a restore it saves `…_visszaallitas_elott.sql`, then rolls back automatically on failure.

Fix bugs in these components in place; do not swap in libraries.

`includes/import.php` (~1000 lines) handles Excel import. It detects two formats: the accounting "számlanapló" export and the legacy "kötéskönyv". It fuzzy-matches companies (≥ 55 % similarity), commits in 400-row batches with one transaction per batch, and takes a backup first.

### Database and migrations
- `sql/schema.sql` is the full current schema (utf8mb4, `CREATE TABLE IF NOT EXISTS`) and creates the initial admin.
  - It is tracked in git (the migrations in `sql/` are not). The server installer (`SERVER SETUP AND UPDATE/szerver_beallitas.sh`) re-applies it to an existing database to fill in missing tables, so keep it idempotent: only `CREATE TABLE IF NOT EXISTS` and `INSERT … ON DUPLICATE KEY UPDATE`, never `DROP`, `DELETE`, `ALTER` or plain `INSERT`.
- Schema changes ship as **incremental `sql/frissites_<version>.sql`** files that users run manually in phpMyAdmin. When you change the schema, update `schema.sql` **and** add a `frissites_X.Y.sql` file. Also add a troubleshooting row to `TELEPITES.md` §10 for the "Unknown column" error users see when they forget the migration. Nothing applies migrations automatically.
- The DB session is forced to Budapest time and strict `sql_mode` (`includes/db.php`).

### Security and privacy conventions
- `includes/`, `sql/`, `LOG/` and `DBBCKP/` each have a deny-all `.htaccess` plus an `index.php` returning 403. Keep both when adding directories.
- Login mode (1.15) is the `beallitasok.qr_belepes` setting, toggled in Admin → Belépés; `qr_belepes_aktiv()` is in `jogok.php`.
  - **On** (the default; a missing row counts as on): login is QR/passkey, and a password works only for users with no registered device.
  - **Off**: everyone logs in with a password. `qr_belepes_kell()` blocks the QR/passkey login actions with `QR_KIKAPCSOLVA`. Passkeys are kept.
- Every response sends `noindex` headers (search engines and AI crawlers are deliberately blocked).
- The main root `.htaccess` described in `TELEPITES.md` is **not present in this working copy**. Do not assume it is deployed from here.
- `LOG/` and `DBBCKP/` hold real production logs and DB dumps containing business data. Don't publish or paste their contents anywhere.

## Internal server installer (`SERVER SETUP AND UPDATE/szerver_beallitas.sh`)

One bash script turns a fresh Ubuntu Server (24.04 / 26.04) into the company's internal BaninaPRO server, and every later run updates it: DB backup → `git pull` → system upgrade → container rebuild. Run it as `sudo bash szerver_beallitas.sh` from the repo. If it is run as a lone file, it clones the repo into `~/BaninaPRO` and re-executes from there.

Goals the user set. Keep them when changing anything here:
- **Self-healing, no human intervention.** The service must stay reachable on the LAN whatever happens.
- **License-clean.** No paid or proprietary dependencies, no extra modules where a built-in tool works. For example, e-mail goes through `curl`'s SMTP support, not msmtp.
  - The one exception is AnyDesk, kept by the user's decision. Its free edition is for private use only, so the business license is the user's responsibility. Don't replace it without asking.
  - Everything else installed is open source and free for business use: Ubuntu, Docker Engine, MySQL Community, phpMyAdmin, PHP, Apache, Firefox, LXQt, curl, avahi.

Install and repair behavior:
- **apt self-repair.** `apt_` reads the failure from the log, repairs and retries; `apt_nyers` is a single raw call.
  - `csomagkezelo_rendbe` finishes half-configured packages. It removes a broken package only when that package is optional (`NEM_KOTELEZO_CSOMAGOK`).
  - Known trap: AnyDesk's postinst exits 1 when `xdg-utils` is missing. That leaves dpkg half-configured and breaks every later apt call (this is what once stopped the Docker step). So `xdg-utils` is installed first.
- **Failure severity.** Non-essential steps warn with `figy` and continue; essential ones stop with `hiba`. Any other failing command stops the run through the ERR trap ("Váratlan hiba"), so wrap non-essential writes in `if …; then …; else figy …; fi`.
- **Command substitution.** The script's stdout is the log, but inside `$(...)` it is captured. A function used as `x="$(f)"` must print only its result and send everything else, including package installs and `ok`/`info` lines, to stderr (`>&2`). `asztal_mappa` once returned apt's output as the desktop path ("File name too long") on a machine without `xdg-user-dirs`.
- **Summary.** It is printed at the end, and also after a failure (`kilepeskor`). It is saved to `~/BaninaPRO-osszegzes.txt`, mode 600.
- **No automatic system updates at all.** The user asked for this because storage is tight: the server must not search for, download or install updates by itself. Step 1 (`lepes_auto_frissites_ki`) does the following:
  - writes `/etc/apt/apt.conf.d/99baninapro-nincs-automatikus-frissites` (every `APT::Periodic` setting 0);
  - masks the timers in `AUTO_FRISSITO_IDOZITOK` (apt-daily, apt-daily-upgrade, fwupd-refresh, update-notifier, motd-news, ua-timer) and `unattended-upgrades.service`;
  - masks the apt-daily services but doesn't stop them, so a running install can finish;
  - sets `Prompt=never` for release upgrades and holds snaps;
  - Firefox's policy has `DisableAppUpdate`.

  The system updates only when someone runs the installer by hand: `full-upgrade`, then `autoremove --purge` (old kernels), `apt-get clean`, and `docker image prune` after the rebuild.

Network and secrets:
- **The repo is public.** The server pulls `https://github.com/Sarokin/BaninaPRO.git` over HTTPS without keys, and `repo_cim_beallit` switches an old SSH remote to it.
- **Ports.** The installer writes `docker-compose.override.yml` on every run; it stays out of git via `.git/info/exclude`:
  - app on `0.0.0.0:80`;
  - phpMyAdmin on `0.0.0.0:8081`, with a login form and no auto-login. The login user is `PMA_FELH` (`BaninaPRO`), with all privileges on the app database. The user asked for the weak initial password `BaninaPRO1234` and will change it. The account is created only when missing and its password is never reset, so a changed password survives updates. root keeps a random password.
  - MySQL only on `127.0.0.1:3307`.
  - The file uses Compose `!override`, which needs Compose 2.24.4 or newer (`compose_eleg_uj`).
- **Server secrets live in `/etc/baninapro`, never in git:**
  - `titkok`: random DB root and app passwords;
  - `config.php`: `docker/config.php` with the server's `DB_PASS` and `APP_DEBUG=false`, mounted over `includes/config.php`;
  - `email` (sender address, SMTP host and port) and `smtp.curl` (sender credentials as a quoted curl config, read with `curl -K`);
  - `ntfy`: the push server and the secret topic (see Push notifications below).
- **Notifications are push first (ntfy); e-mail is optional.** The user cannot give access to the fixed recipient `JELENTES_CIMZETT`, and there is no keyless way to e-mail it: Gmail rejects or spams unauthenticated direct delivery, and ntfy.sh refuses anonymous e-mail forwarding (error 40053). So e-mail needs a separate sender mailbox, such as a company cPanel mailbox or a Gmail account with an app password. The installer asks for one only with `sudo bash szerver_beallitas.sh --email`, and the flag survives the self-update re-exec.
  - On an old volume, `db_root_atallitas` replaces the public default root password.
- **Schema repair.** `adatbazis_rendbe` re-applies `sql/schema.sql` when tables or the initial admin are missing. That is why the schema must stay idempotent (see Database and migrations).

Runtime pieces, all regenerated on every run. Never edit them on the server:
- **Watchdog** `/usr/local/sbin/baninapro-orszem` (systemd timer, every 2 min). When the app or DB check fails, it escalates:
  1. `compose up -d`
  2. `compose restart`
  3. Docker restart, at most every 30 min
  4. priority-5 push, plus an e-mail alert if a sender is configured
  5. machine reboot after 1 h of failure, at most every 6 h

  It also keeps Docker, AnyDesk and cron running (`figyel`), keeps automatic updates off (re-masks the timers), restarts stopped containers, and watches free disk space and `reboot-required`. It pushes only when a component's state changes (`valtozott`, state in `/var/lib/baninapro-orszem`), never on every run. It shares `/run/baninapro-orszem.lock` with the installer, which holds the lock while it runs.
- **Daily report** `/usr/local/sbin/baninapro-jelentes` (systemd timer 03:30, the DB backup cron runs at 03:00).
  - Modes: `napi`, `kezi` (desktop icon, terminal window, sudoers rule for exactly this command), `proba`, `riasztas`.
  - It pushes a short summary, except in `riasztas` mode, where the watchdog has already pushed. The full report is e-mailed only if a sender is configured.
  - Reports are kept in `/var/log/baninapro-jelentes/`.
- **Nightly backup** `/usr/local/sbin/baninapro-mentes` (`/etc/cron.d/baninapro`, 03:00) wraps `cron_mentes.php`, logs to `/var/log/baninapro-mentes.log` and pushes the result.
- **Push notifications (ntfy).** The user subscribes to one secret topic in the ntfy phone app. The installer creates `baninapro-<24 random chars>` once in `/etc/baninapro/ntfy` and keeps it. It shows the topic in the summary and in `~/Asztal/BaninaPRO-ertesitesek.txt`, and sends one test push the first time (`NTFY_PROBA_KESZ=1`).
  - The user wants a push for everything except user activity. Logins and logouts are the only user events reported.
  - `/usr/local/sbin/baninapro-ertesites [-p 1-5] [-t tags] "Cím" "Üzenet"` publishes JSON to the server root. Unsent messages wait in `/var/spool/baninapro-ertesites` (at most 300), and the watchdog flushes them every 2 min. `--sorbol` is serialized with `flock`, so a message is never sent twice. 4xx responses other than 429 are dropped.
  - Boot and shutdown: `baninapro-leallas.service` (`ExecStop`) writes the `tiszta-leallas` marker on a clean shutdown. At boot, `baninapro-indulas.service` reports a reboot, or a power cut if the marker is missing, and estimates the outage from the watchdog's `eletjel` heartbeat. It is `Type=simple` so its retry loop never delays boot.
  - Logins: `baninapro-belepesfigyelo.service` tails the app's daily log in the `baninapro_adatok` volume. It reacts only to `BELEPES`, `KILEPES` and `KILEPTETES` lines, and only to complete lines. If `naplo()` in `includes/logger.php` changes its line format or these codes, update its regex.
  - The installer pushes its own end result (`vegeredmeny_ertesites`), success or failure.
- **Power settings.**
  - Sleep targets are masked, plus `sleep.conf.d` and `logind.conf.d`.
  - No screensaver or DPMS: Xorg `ServerFlags`, LightDM `-s 0 -dpms`, session `xset`, LXQt power management.
  - AnyDesk runs with `Restart=always`.
  - Power-on after AC loss is set through `/sys/class/firmware-attributes` where the firmware allows it; otherwise the installer prints BIOS instructions.

Testing: reproduce the server in a privileged systemd Ubuntu container:
- `--privileged --cgroupns=private --tmpfs /run`;
- anonymous volumes for `/var/lib/docker` and `/var/lib/containerd`;
- unmount the bind-mounted `/etc/hosts` and `/etc/hostname`;
- run the script as a sudo user, without a TTY.
- Point pushes at a local ntfy server (`binwiederhier/ntfy serve`; `sed` `NTFY_SZERVER` in the test copy) and read them back with `GET /<topic>/json?poll=1&since=all`. Don't send test pushes to the public ntfy.sh.
- `docker restart -t 180 <container>` simulates a clean reboot. `docker kill` followed by `docker start` simulates a power cut.

## Light installer (`SERVER SETUP AND UPDATE/szerver_beallitas_light.sh`)

A trimmed copy of the full installer for the real server: a minimized Ubuntu Server with only LightDM, the minimal Xorg and AnyDesk, Docker already installed and running, and 1.7 GB free on the internal disk. Everything must fit in 1 GB. Run it as `sudo bash szerver_beallitas_light.sh`; flags are `--email`, `--usb=/dev/sdX` and `--usb-formazas`.

Scope (the user's decision):
- It does only the BaninaPRO side: containers, DB, nightly backup, watchdog, pushes, daily report, hostname/timezone, avahi, and never-sleep (including USB autosuspend off).
- It never installs, configures or watches the desktop, LightDM, Xorg, AnyDesk, SSH or the language, and never installs Docker.
- No system update of any kind (no `full-upgrade`, no `autoremove`), on the user's request. Automatic updates are switched off as in the full script, so the OS updates only when someone runs apt by hand.
- Half-finished installs are never continued (no `dpkg --configure -a`, no `apt-get -f install`). `felbemaradt_lezaras` closes them by purging the package with `dpkg --purge --force-remove-reinstreq`, at the start of step 2 and before every install. This is the user's decision.
  - It purges without `--force-depends`, so dpkg refuses anything other packages depend on.
  - It never purges `VEDETT_CSOMAGOK` (Docker/containerd, LightDM/Xorg, the kernel, grub, systemd, libc, apt/dpkg, sudo, SSH, networking, `ubuntu-*`) or anything matching the running kernel. For these it only warns, once per run.
  - AnyDesk has its own rule (`anydesk_futo_csomagjai`): it checks which package owns `/proc/<pid>/exe` of the running `anydesk` processes.
    - If the half-finished package is the one running, it is the working AnyDesk, not a second copy. Its postinst fails at the xdg-desktop-menu step without `xdg-utils`, so the program runs but dpkg shows it half-configured. It is kept, and the warning suggests `sudo apt-get install xdg-utils`.
    - If the working AnyDesk runs from elsewhere, the package is a surplus duplicate. It is purged with its maintainer scripts moved aside to `/var/backups/baninapro-dpkg`, because its prerm would stop the running AnyDesk service.
    - If AnyDesk isn't running, it is kept.
  - Pending triggers (`W`/`t`) don't count as half-finished.
- Every apt run would try to finish a half-finished install, because apt runs `dpkg --configure --pending` at the end. So `dpkg_rendben` gates every install: if anything half-finished remains, or dpkg was interrupted, apt is not run at all.
  - In that case a missing optional package is only a warning, and a missing required tool stops the run with a message.
  - `apt-get update` runs only when something must be installed, at most once per run.
- Space thresholds are in MB (`HELY_*`, `KEVES_HELY_MB`). It cleans up first, but only downloaded package files, journals and the Docker cache.
- No pre-run DB backup and no `git pull` or self-update (the user removed them: that step seemed to hang). Code updates are manual: `cd ~/BaninaPRO && git pull`, then rerun. The nightly backup still runs.
- **Speed: as much in RAM as possible.** This is the user's request, and it touches only the generated override, never the app or `docker/`.
  - All three containers get a tmpfs `/tmp`. That holds the PHP sessions (`session.save_path=/tmp`), uploads and MySQL temp files.
  - `$TITOK_MAPPA/php-gyorsitas.ini` is mounted into `conf.d` and turns on opcache and the realpath cache. `validate_timestamps=1` and `revalidate_freq=2`, so code from a `git pull` shows within 2 s.
  - MySQL gets `--innodb-buffer-pool-size` at a quarter of RAM (256 MB–4 GB), plus `--innodb-flush-log-at-trx-commit=2` and `--skip-log-bin`. A power cut can lose up to 1 s of commits, which the user accepted.
  - The override's db `command` replaces the base one, so it repeats the charset options. Keep them in sync with `docker-compose.yml`.
  - The DB data itself stays on the stick.
- Never call the docker CLI without a timeout while the stick might be missing. `docker.socket` accepts the connection, but `docker.service` can't start (`RequiresMountsFor=`), so a plain `docker inspect` blocks forever with no spinner. Use `docker_valaszol` (checks `systemctl is-active docker.service`, then runs `docker info` under `timeout`), and wrap the other calls in `timeout`.

USB stick (default `/dev/sdb`, found later by label, never by name):
- **Layout.** GPT with two partitions:
  - `BANINAPRO` (FAT32, 1/10 of the stick, 1–16 GiB) holds `BaninaPRO-mentesek/`, a mirror of every backup, plus `OLVASSEL.txt`. It is FAT so the user (on a Mac) can read the backups after pulling the stick; exFAT would need linux-modules-extra on a minimized kernel.
  - `BANINAPRO-ADAT` (ext4) is bind-mounted onto `/var/lib/docker` and `/var/lib/containerd`, so images, containers, the MySQL volume and the app's LOG/DBBCKP all live on the stick. Docker 29 uses the containerd image store, so images are in `/var/lib/containerd` and both directories must move.
  - `baninapro/` on the ext4 partition holds copies of `titkok` and `ntfy`, so the DB opens on a new server. The e-mail password is not copied.
- **No stick, no Docker.** fstab has a managed block by UUID with `nofail`; `/etc/fstab.baninapro-elott` is the original. Docker and containerd get `RequiresMountsFor=` drop-ins. The empty mountpoints and placeholder dirs are `chattr +i`, so without the stick nothing can write to the small internal disk.
- **Formatting safety.** It formats only a whole disk that is:
  - USB (`TRAN=usb`), unmounted and unused (no LVM, RAID or crypt), and at least 8 GB;
  - empty: each filesystem is mounted read-only and checked, and an unreadable filesystem counts as not empty.

  Anything else needs `--usb-formazas`. It also refuses to prepare a new stick while `/etc/baninapro/usb` names one that is missing, because the DB would start empty.
- **Migration.** Stop Docker and containerd, copy with `cp -a`, bind-mount, start, then compare the image/volume/container inventory. Only then delete the internal copy. On any failure it rolls back completely.
  - The marker `baninapro/docker-athelyezve` means a finished move. A stick that has it is adopted: its data and secrets win, and the internal data is set aside, not deleted. A stick without it but with content is a half-finished copy and is wiped and redone.
- **`/usr/local/sbin/baninapro-usb`** has the subcommands `ellenoriz`, `tukor`, `allapot`, `levalaszt` and `csatol`.
  - The watchdog calls `ellenoriz` first. It remounts a stick that reappeared, possibly under a new name; a missing stick gives one push and no further recovery, so no reboot loop.
  - The watchdog also calls `tukor` on every run, and the nightly wrapper calls it after the backup. `tukor` copies the newest files first and never deletes a newer copy to make room for an older one. The two newest backups also go to `/var/backups/baninapro` on the internal disk, in case the stick dies.
  - The watchdog logs only failures, with a date. The daily report lists the last 24 hours of the watchdog log as interventions, and undated lines sort after the date filter and flood it.
- **Adoption order.** An adopted stick's containers start by themselves as soon as Docker starts. So `titkok` and `/etc/baninapro/config.php` must exist before `docker_inditas`. Otherwise Docker creates a *directory* at the missing bind-mount source and the app container fails with "not a directory". `szerver_config` (in both scripts) removes such a directory.

Testing: this Mac (Intel, macOS 15) has no Docker, so the installer is tested in a Lima VM.
- Use `limactl` with `--vm-type=vz` and an extra raw disk (`limactl disk create`, `additionalDisks: format: false`) as the stick. Virtio disks are not `TRAN=usb`, so run with `BANINA_USB_LEMEZ=/dev/vdb BANINA_USB_TESZT=1`. In test mode, loop devices also pass the disk checks, which is how the formatting-safety cases are tested.
- Scenarios worth repeating after changes:
  - fresh run with an existing container and volume (data must survive the move);
  - rerun;
  - reboot;
  - boot without the stick (`limactl edit --set '.additionalDisks=[]'`): Docker must not start, and the internal disk must not grow;
  - `levalaszt` / `csatol`, and unmounting under a running system (the watchdog remounts);
  - a "new server": move `/etc/baninapro` and the drop-ins aside, give Docker a fresh internal root, and the stick must be adopted with its data.
- Claude Code's safety check blocks a script piped into the VM (`limactl shell … bash -s`) if it contains `rm`. Write test scripts without deletions.
- `LIMA_HOME` must be a short path: socket paths are limited to 104 characters.
- Pushes go to a local fake ntfy, set by pre-creating `/etc/baninapro/ntfy` with `NTFY_SZERVER=http://127.0.0.1:…`.
- The light script is a copy. Fix the shared parts in both scripts: the apt helpers, the notifier, the login watcher, the watchdog, the report and the DB functions.

## Working notes (lessons learned)

How to work with this user and this codebase. Keep this section current.

Workflow:
- **Communication.** The user writes in Hungarian and often sends several requests in a row while work is running. Address each one.
- **Commit and push after verification.** Push to `main` as soon as a change is verified. The internal server updates itself with `git pull` from the public repo, so a push is how a change reaches it.
- **Bump the version for every user-visible change:**
  - `APP_VERSION` in `includes/config.php` (local and ignored: it is the production config), `includes/config.example.php` and `docker/config.php`;
  - in `TELEPITES.md`: a feature bullet in the matching section, a `**Frissítés X-ről Y-ra (…):**` entry listing the files to upload (newest entry first), and the closing `Verzió: X (date)` line.
- **`BaninaPRO_WEB/` is a local reference copy of the deployed site** (gitignored). After versions once got mixed up, it held the authoritative 1.15 code.
  - It contains production backups, logs and the production `config.php`. Never commit it, and never paste its contents.
  - Merge from it file by file (`diff -rq BaninaPRO_WEB .`), and keep the repo's sanitized `sql/schema.sql`.
- **Never discard uncommitted work without a backup.** Save a patch and copies of untracked files to the scratchpad first. Once, 1.15 was discarded on request and had to be restored the next day.

Verification:
- **Frontend tests.** `tests/e2e/futtat.sh` must stay green: 30 tests, run in 2 browser projects. The 600-row basket test runs on desktop only, so a full run reports 59 passed and 1 skipped.
  - The print FAB builds the PDF directly. The basket sheet opens via Menü → Nyomtatási kosár.
  - The red X on the print FAB (`.fab-urit`, 1.18) empties the basket at once. The user asked for no undo.
  - Keep accessible names unique. `osszevetes.spec.ts` clicks `getByRole('button', { name: /^Nyomtatási kosár/ })` (the menu item), so the X is named „Kosár ürítése”.
  - New UI features get a spec in `tests/e2e/tests/`. Tests are local only (gitignored).
- **PDF layout.** Save a PDF to a file: either `testInfo.outputPath(...)` in a test, or `page.request.post('/pdf.php', { form: { csrf, tetelek } })`. Render it with `pdftoppm -png` (poppler-utils in an ubuntu container) and look at the image.
- **Syntax checks.**
  - PHP: `docker exec baninapro-app php -l <file>`.
  - JS: `docker run --rm -v "$PWD/assets:/a:ro" node:22-alpine node --check /a/app.js`. Node is not installed on the Mac.
  - The Mac's `/bin/bash` is 3.2, so run bash checks in an Ubuntu container.
- **Server installer.**
  - Run `bash -n` and `shellcheck -S warning -e SC1111` (the `koalaman/shellcheck` image) on the script and on its generated helper scripts.
  - Then run a full end-to-end test from the server's real broken state (see the installer section).
- **Destructive commands get blocked.** The auto-mode permission check blocks commands such as `DROP DATABASE`, even on a throwaway database. Verify non-destructively instead: diffs, or a separate throwaway container.

PDF output (1.20, `includes/pdf.php` + `includes/nyomtatas.php`):
- Table body rows are 10 pt pure black (#000000) with 7 pt English below (the user's choice in 1.20.1; 1.20.0 had 12 / 9 pt), on portrait A4 only (the user's decision: no landscape); whatever does not fit wraps. `tordel()` breaks long words after `-`, `.`, `/` and `_` before it falls back to single characters. Headers are 9 pt and wrap as well.
- In color mode only the status column is colored: mark it with `'st' => true` in the column definition. Summary rows (`osszes`) keep their colors. The English line takes the color of its Hungarian cell.
- Black-and-white mode (`PdfIro::$ff`, from the `szin=ff` form field) forces every text and line to black, drops light fills and turns dark fills black. It is toggled by `.fab-szin`, a two-state button at the bottom right of the print FAB, mirroring the red X. The state is per user in `localStorage` (`PdfSzin`), and `pdfKuldes()` sends it.
- Column widths were tuned for 12 pt and kept unchanged at 10 pt (the user asked for no other change). Measured 12 pt text widths: a date takes 20.4 mm on one line and needs 15 mm columns to wrap cleanly as `2026.` / `01.01.`; BESZÁMÍTVA takes 22.3 mm.

Pattern: adding a print-basket item type (1.16 `osszevetes`, 1.19 `allapot`):
- **JS.** Add the type to `KOSAR_TIPUS` (its order is the basket order). Any extra fields, such as `q`, must survive `Kosar.lista()`, `Kosar.hozzaad()` and `pdfKuldes()`. A `q`-carrying type also needs a cleaner in `KOSAR_Q`, and its id comes from `lekerdezesId()` over the query.
- **PHP.** Add the type to `NY_TIPUSOK`, add validation and a dedupe key in `pdf.php`, and add a renderer in `includes/nyomtatas.php`.
- **One data source.** Reuse the API's data function (for example `osszevetes_reszletek_adat()`) so the page and the PDF always agree.
- **Row styles.** `PdfIro::tablazat` supports `normal`, `al` (sub-row), `osszes` (total) and `csoport` (bold group header). Text is bilingual: Hungarian, with English below.

Environment quirks:
- Ubuntu 26.04 ships uutils coreutils. `chown user:` keeps the old group there; this is cosmetic.
- In containers, `/etc/hosts` and `/etc/hostname` are bind mounts, so `sed -i` and `hostnamectl` fail on them. The test harness unmounts them first.
- mailpit's `--smtp-auth-file` cannot store passwords that contain `:`. That is a limit of the test tool, not of the installer.
