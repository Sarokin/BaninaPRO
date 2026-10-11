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
  - It is tracked in git (the migrations in `sql/` are not). The server installer (`SERVER SETUP AND UPDATE/szerver_beallitas.sh`) runs it whole on a database with no users, and loads it into a scratch database to diff against the live one (`sema_egyeztetes`), so keep it idempotent: only `CREATE TABLE IF NOT EXISTS` and `INSERT … ON DUPLICATE KEY UPDATE`, never `DROP`, `DELETE`, `ALTER` or plain `INSERT`.
- Schema changes ship as **incremental `sql/frissites_<version>.sql`** files that users run manually in phpMyAdmin. When you change the schema, update `schema.sql` **and** add a `frissites_X.Y.sql` file. Also add a troubleshooting row to `TELEPITES.md` §10 for the "Unknown column" error users see when they forget the migration.
  - On cPanel nothing applies migrations automatically. On the internal server the installer adds what the live database lacks compared to `schema.sql`: tables, columns, indexes, foreign keys, and non-lossy column changes (new ENUM values, wider types, NULL allowed, new defaults). It never drops or narrows anything and never moves data; it only warns about such differences, so those still need the `frissites` file.
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

One bash script turns a fresh Ubuntu Server (24.04 / 26.04, the minimized install too) into the company's internal BaninaPRO server, and every later run updates BaninaPRO: DB backup → `git pull` → container rebuild. It never updates the system (see below). Run it as `sudo bash szerver_beallitas.sh` from the repo. If it is run as a lone file, it clones the repo into `~/BaninaPRO` and re-executes from there, passing its flags on (`--email`, `--nincs-visszaallitas`).

It is the only installer. A trimmed "light" copy (USB stick, no system updates) existed until 2026-10-09; the user had it deleted.

Goals the user set. Keep them when changing anything here:
- **Self-healing, no human intervention.** The service must stay reachable on the LAN whatever happens.
- **Rerunning repairs everything** (2026-10-09). Whatever broke, running the installer again must leave an environment where BaninaPRO runs. It installs or repairs everything it needs itself, from the base tools to Docker, the code and the database, and stops only when something truly can't be fixed.
- **License-clean.** No paid or proprietary dependencies, no extra modules where a built-in tool works. For example, e-mail goes through `curl`'s SMTP support, not msmtp.
  - The one exception is AnyDesk, kept by the user's decision. Its free edition is for private use only, so the business license is the user's responsibility. Don't replace it without asking.
  - Everything else installed is open source and free for business use: Ubuntu, Docker Engine, MySQL Community, phpMyAdmin, PHP, Apache, Firefox, Xfce, curl, avahi.

Install and repair behavior:
- **The desktop is always Xfce** (the user's decision, never LXQt or anything else). On every run `lepes_autologin` sets the LightDM session to `xfce`, so a machine installed earlier with LXQt switches too (it asks for a reboot); the old LXQt packages are left installed.
  - Packages: `xfce4 xfce4-terminal xorg lightdm` in one mandatory transaction, without recommends. Keep `xfce4-terminal` in that transaction: the desktop packages depend on "a terminal", and without a named one apt on 24.04 picks `gnome-terminal` and its GNOME dependencies.
  - Hungarian: Xfce has no `-l10n` packages; its translations are `.mo` files inside its own packages, and a minimized Ubuntu drops those at install time (`/etc/dpkg/dpkg.cfg.d/excludes`). `magyar_forditasok_engedve` writes `zz-baninapro-magyar` (a `path-include` for `hu`) before the install, and `magyar_forditasok_potlasa` reinstalls desktop packages whose Hungarian `.mo` is listed by `dpkg -L` but missing on disk.
  - The offline pendrive maker (`offline_pendrive_keszito.txt`) carries the same package names and checks; change both together, and update the sha256 on its last line.
- **The server's database is always MariaDB** (`DB_KEP_SZERVER="mariadb:10.11"`), whatever the CPU; `docker-compose.yml` (dev) still says `mysql:latest`. The official MySQL images need x86-64-v2 and die on the real server's CPU with "Fatal glibc error: CPU does not support x86-64-v2".
  - `db_kep_valasztas` sets `DB_KEP`; `override_iras` writes it as the db image, and `kepek_listaja` substitutes it for `mysql:*`. The one exception: a DB volume that already holds MySQL-written data (`mysql.ibd`) stays on the MySQL image, because MariaDB cannot open it (`cpu_v2` only decides whether that is a note or a warning).
  - Once the db container runs on MariaDB, `regi_db_kepek_torlese` removes leftover `mysql:*` images (over 1 GB) without force.
  - The installer's `mysql` / `mysqladmin` calls rely on MariaDB 10.11 still shipping those names; MariaDB 11 images do not.
  - If the DB image is neither on the machine nor downloadable, `lepes_baninapro` stops with a message that says so. `kontener_naplok` prints each container's image, because the log of a stale MySQL container once looked like a failure of the new code.
  - The pendrive maker reads `DB_KEP_SZERVER` from the installer on GitHub and ships that image instead of MySQL. On every run it refreshes the code bundle; images younger than 12 h are reused, and the apt cache is reused only when the requested package list is unchanged (the marker file holds its hash). Before 2026-10-11 a pendrive re-made within 12 h silently kept the old code and images.
- **Tuned for a weak CPU** (the real server is an AMD G-T48E: 2 cores at 1.4 GHz, no x86-64-v2, slow storage; the user asked for this on 2026-10-11). Everything is adaptive, nothing checks the model name:
  - `gep_gyorsitas`: `baninapro-cpu.service` sets the `performance` governor at boot, and `/etc/sysctl.d/60-baninapro.conf` sets `vm.swappiness=10`.
  - `xfce_gyors`: xfwm4 compositing off, as a locked system xfconf property (per-property `locked="*"` works; the rest of the channel stays writable).
  - `override_iras`: the app gets a 256 MB tmpfs `/tmp` (PHP sessions and uploads, so logins are lost when the container restarts). The db `command` adds `--innodb-buffer-pool-size` (RAM/8, 128 MB–1 GB, `db_pool_mb`), `--innodb-flush-log-at-trx-commit=2` (a power cut can lose the last second of commits), `--skip-log-bin` and `--performance-schema=OFF`; all four work on both `mysql:latest` and `mariadb:10.11`. The override's `command` replaces the base one, so it repeats the two charset options from `docker-compose.yml`; keep them in sync.
  - Don't add an opcache ini: the `php:8.3-apache` image already loads and enables opcache, and a second `zend_extension=opcache` only prints "Cannot load Zend OPcache - it was already loaded".
- **apt self-repair.** `apt_` reads the failure from the log, repairs and retries; `apt_nyers` is a single raw call.
  - `csomagkezelo_rendbe` finishes half-configured packages. It removes a broken package only when that package is optional (`NEM_KOTELEZO_CSOMAGOK`).
  - Known trap: AnyDesk's postinst exits 1 when `xdg-utils` is missing. That leaves dpkg half-configured and breaks every later apt call (this is what once stopped the Docker step). So `xdg-utils` is installed first.
- **Failure severity.** Non-essential steps warn with `figy` and continue; essential ones stop with `hiba`. Any other failing command stops the run through the ERR trap ("Váratlan hiba"), so wrap non-essential writes in `if …; then …; else figy …; fi`.
- **Command substitution.** The script's stdout is the log, but inside `$(...)` it is captured. A function used as `x="$(f)"` must print only its result and send everything else, including package installs and `ok`/`info` lines, to stderr (`>&2`). `asztal_mappa` once returned apt's output as the desktop path ("File name too long") on a machine without `xdg-user-dirs`.
- **Summary.** It is printed at the end, and also after a failure (`kilepeskor`). It is saved to `~/BaninaPRO-osszegzes.txt`, mode 600.
- **One installer at a time.** `main` takes `/run/baninapro-telepito.lock` with `flock -n`; `fut` closes fds 7 and 8 (the two locks) in its children.
- **The watchdog never blocks the installer.** If it holds `/run/baninapro-orszem.lock`, the installer stops `baninapro-orszem.service` and runs `reset-failed` on it. A recovery round can last 15 min, and the installer once waited all that time with nothing on screen. The timer starts the watchdog again afterwards.
- **Bootstrap before anything else** (`elofeltetelek`):
  - `internet_van` works without curl (bash `/dev/tcp`). When the check fails, `halozat_javit` reapplies netplan / restarts networkd and NetworkManager if there is no default route, and `dns_javit` adds fallback resolvers in `/etc/systemd/resolved.conf.d/90-baninapro-dns.conf` if names don't resolve. `ido_javit` restarts systemd-timesyncd and chrony (25.10+ ships chrony).
  - `alapeszkozok_biztosit` installs whatever `ALAP_PARANCSOK` lists as missing (curl, git, psmisc, iproute2, procps, cron, locales…), plus ca-certificates and tzdata.
  - `cel_felhasznalo` picks the user: `SUDO_USER`, else the repo owner, else the first regular user (UID 1000+); if there is none, it creates `baninapro`.
  - `felh` runs commands as that user with `runuser`, not sudo (Ubuntu 25.10+ defaults to sudo-rs). It also sets a git identity, because `git stash` fails without one on a fresh machine.
- **Minimized Ubuntu Server** (the user's real server is one). Its `/etc/dpkg/dpkg.cfg.d/excludes` drops every `/usr/share/locale/*/LC_MESSAGES/*.mo`, so the desktop came up half English (`~/Desktop` instead of `~/Asztal`).
  - `minimal_rendszer_forditasok` adds `zz-baninapro-magyar` with `path-include=/usr/share/locale/hu/*` before the language and desktop packages. Ubuntu ships some desktop translations (`xdg-user-dirs`, which names `~/Asztal`) only in `language-pack-gnome-hu-base`, so `lepes_nyelv` installs that too (8 MB). Never run `unminimize`: it reinstalls everything and costs space.
  - If there is no `/dev/dri/card*`, `lepes_asztal` installs `linux-modules-extra-$(uname -r)` (same kernel version, so not an update). Xorg still works on the framebuffer without it.
  - The test image (`ubuntu:24.04` from Docker Hub) is itself minimized, with the same excludes file, so container tests cover this.
- **The code is always the GitHub version.**
  - `repo_rendbe` runs before the update. It chowns the repo back to the user, removes a stale `.git/index.lock`, and aborts half-done merges and rebases. A corrupt `.git` goes to `/var/backups/baninapro-repo/`, and a non-git folder (for example, a copied one) is re-initialised from GitHub.
  - `git_frissit` fetches, then stashes local edits and deletions of tracked files (the user can get them back with `git stash list`). It switches back to `main` and fast-forwards. If the local branch has diverged, it keeps a `baninapro-helyi-*` branch and resets to `origin/main`. A local branch that is *ahead* of origin is left alone; the test harness relies on this.
- **Docker repair.**
  - `docker_inditas` unmasks the units and checks `daemon.json` with `dockerd --validate` (only where that flag exists, Docker 23+). If Docker still won't start, `docker_ujratelepites` reinstalls the packages.
  - `buildx_biztosit` adds buildx.
  - `kepek_biztosit` pulls the images from `docker-compose.yml` and the Dockerfile's `FROM`. If Docker Hub fails (outage or rate limit), it pulls from `KEP_TUKROK` (`public.ecr.aws/docker/library`, `mirror.gcr.io/library`) and retags. Existing images are never re-pulled, so `mysql:latest` doesn't jump versions by itself.
  - `alkalmazas_epites` tries four builds in turn: compose `build --pull`, compose `build`, compose with `COMPOSE_BAKE=false`, then plain `docker build -t baninapro-app`.
  - `idegen_kontenerek_le` removes containers that use our names but belong to another compose project. Their volumes are kept.
  - `port_felszabadit` frees 80, 8081 and 3307: it stops foreign containers, disables services (including systemd `.socket` units) and kills stray processes. Port 80 is mandatory. If 8081 or 3307 can't be freed, the override gives that service `ports: !reset []` and the run continues.
- **Other system pieces:** the journal is capped at 200 MB (`journald.conf.d/50-baninapro.conf`); a 2 GB `/swapfile` is created when there is no swap, RAM < 4 GB and ≥ 12 GB free; ufw (if someone enabled it) gets 22, 80, 8081 and 5353/udp; a broken or empty set of Ubuntu apt sources is rewritten as `ubuntu.sources` (`forrasok_rendbe`); `universe` is enabled in both the deb822 and the one-line format; another display manager (gdm3, sddm…) is disabled and `lightdm` enabled with `--force`.
- **No automatic system updates at all.** The user asked for this because storage is tight: the server must not search for, download or install updates by itself. Step 1 (`lepes_auto_frissites_ki`) does the following:
  - writes `/etc/apt/apt.conf.d/99baninapro-nincs-automatikus-frissites` (every `APT::Periodic` setting 0);
  - masks the timers in `AUTO_FRISSITO_IDOZITOK` (apt-daily, apt-daily-upgrade, fwupd-refresh, update-notifier, motd-news, ua-timer) and `unattended-upgrades.service`;
  - masks the apt-daily services but doesn't stop them, so a running install can finish;
  - sets `Prompt=never` for release upgrades and holds snaps;
  - Firefox's policy has `DisableAppUpdate`.

  **The installer never updates the system either, not even on the first install** (the user's decision, 2026-10-10). There is no `upgrade`, `full-upgrade` or `autoremove`. `apt-get update` refreshes the lists once per run, so missing packages can be installed.
  - `telepit` and `telepit_min` pass `--no-upgrade`, so an installed package is never upgraded. A new package may still pull a newer dependency when it requires one.
  - Repairs reinstall the exact installed version (`ujratelepit_azonos`, `pkg=version`), never a newer one. If that version is gone from the archive, the script warns instead of upgrading.

Network and secrets:
- **The repo is public.** The server pulls `https://github.com/Sarokin/BaninaPRO.git` over HTTPS without keys, and `repo_cim_beallit` switches an old SSH remote to it.
- **Ports.** The installer writes `docker-compose.override.yml` on every run (`override_iras`); it stays out of git via `.git/info/exclude`:
  - app on `0.0.0.0:80`;
  - phpMyAdmin on `0.0.0.0:8081`, with a login form and no auto-login. The login user is `PMA_FELH` (`BaninaPRO`), with all privileges on the app database. The user asked for the weak initial password `BaninaPRO1234` and will change it. The account is created only when missing and its password is never reset, so a changed password survives updates. root keeps a random password.
  - MySQL only on `127.0.0.1:3307`.
  - The file uses Compose `!override` and `!reset`, which need Compose 2.24.4 or newer (`compose_eleg_uj`).
  - The file holds the DB passwords. It is mode 600 and owned by the user, so Apache in the container (www-data) gets 403 for it, and `lepes_ellenorzes` checks that.
- **Server secrets live in `/etc/baninapro`, never in git:**
  - `titkok`: random DB root and app passwords;
  - `config.php`: `docker/config.php` with the server's `DB_PASS` and `APP_DEBUG=false`, mounted over `includes/config.php`. It is rewritten **in place** (`cat > f`), never replaced: the running container bind-mounted the old inode and would keep reading it until restarted;
  - `docker-compose.override.yml`: the master copy of the repo's override, which the watchdog restores from;
  - `email` (sender address, SMTP host and port) and `smtp.curl` (sender credentials as a quoted curl config, read with `curl -K`);
  - `ntfy`: the push server and the secret topic (see Push notifications below).
- **Notifications are push first (ntfy); e-mail is optional.** The user cannot give access to the fixed recipient `JELENTES_CIMZETT`, and there is no keyless way to e-mail it: Gmail rejects or spams unauthenticated direct delivery, and ntfy.sh refuses anonymous e-mail forwarding (error 40053). So e-mail needs a separate sender mailbox, such as a company cPanel mailbox or a Gmail account with an app password. The installer asks for one only with `sudo bash szerver_beallitas.sh --email`, and the flag survives the self-update re-exec.

Database repair (`adatbazis_rendbe`, in this order):
1. **Wait for MySQL** (`db_var_inditasra`, restarts it once). If `compose up` fails and MySQL won't start, `adatbazis_ujraepites` rebuilds it, but only if a backup with business data exists and the old files fit on the disk. It copies the raw datadir (about 140 MB even for a tiny database) to `/var/backups/baninapro/serult-adatbazis-<date>`, removes the volume and lets it start empty; step 4 then restores. `kontenerek_inditasa` stops retrying as soon as `db_osszeomlik` sees the DB container restarting or exited; otherwise every attempt waits out the 150 s healthcheck.
2. **Root password:** the server's; else the old public `baninapro_root` (`db_root_atallitas`); else `db_jelszo_helyreallitas`. That last one starts the MySQL image once on the volume with `--init-file` (`--skip-networking`) to reset root to the server's password, which covers a lost `titkok`. Data is untouched.
3. **Tables:** the whole `schema.sql`, with the initial admin, runs only when there are no users. It never goes into a live database: there the INSERT would recreate `admin` with the public password next to a renamed admin.
4. **Users and restore:** the app user's password is always reset to the server's, and the PMA user is created if missing. If the database has no business data (`adatbazis_ures`: ≤ 1 user and no company, kötés, invoice or utalás), `mentesbol_visszaallitas` restores the newest complete backup (footer `-- Mentés vége: N tábla, M sor`) that *contains* business data. `mentesek_keresese` reads it from the per-table headers (`-- Tábla: \`cegek\` (2 sor)`) and counts companies, kötések, invoices, utalások and users beyond the initial admin. Choosing by total rows was a bug: the app's own `…_visszaallitas_elott.sql`, taken of the empty database just before the restore, becomes the newest file. The search covers the `adatok` volume's `DBBCKP` and `/var/backups/baninapro`; a host backup is copied into `DBBCKP` (www-data, uid 33), then `cron_mentes.php vissza` runs. `--nincs-visszaallitas` skips this.
5. **Schema sync** (`sema_egyeztetes`): loads `schema.sql` into the scratch database `baninapro_sema_minta`, then runs PHP in the app container as root (`sema_osszevetes`, a heredoc). The PHP compares the scratch and live databases with `SHOW CREATE TABLE` and `information_schema`, applies the additive changes (see Database and migrations) and prints `UJ`/`FIGY`/`HIBA` lines ending with `VEGE`. Finally the scratch database is dropped.

Runtime pieces, all regenerated on every run. Never edit them on the server:
- **Watchdog** `/usr/local/sbin/baninapro-orszem` (systemd timer, every 2 min). When the app or DB check fails, it escalates:
  1. `kod_rendben`: if tracked files are missing or modified, it restores them from git (edits go to `git stash`). If the repo is gone, it re-clones it from GitHub and restarts the app container. It does this only while the app is down, so edits made while debugging on a working server are left alone.
     - Bind mounts follow the inode. Moving or deleting the repo doesn't affect a running app; a restarted container gets a skeleton that Docker creates in its place (`includes/config.php` as an empty mountpoint), and Apache answers 403. So the check is "no `docker-compose.yml` and no `.git`", not "empty dir": the skeleton is moved aside to `BaninaPRO.hianyos-<date>`, and the fresh clone needs the container restart.
  2. `compose up -d`
  3. `compose restart`
  4. Docker restart, at most every 30 min
  5. priority-5 push, plus an e-mail alert if a sender is configured
  6. machine reboot after 1 h of failure, at most every 6 h

  It also keeps Docker, AnyDesk and cron running (`figyel`), keeps automatic updates off (re-masks the timers), restarts stopped containers, and watches free disk space and `reboot-required`. On every run `beallitasok_rendben` restores a missing or changed override from `/etc/baninapro` and rebuilds a missing `config.php` (Docker leaves a directory in its place) from `titkok`, and `baninapro-mentes masol` copies new backups. It pushes only when a component's state changes (`valtozott`, state in `/var/lib/baninapro-orszem`), never on every run. It shares `/run/baninapro-orszem.lock` with the installer, which holds the lock while it runs. Every docker CLI call runs under `timeout`. Command output reaches the log only through `naplora`, which dates every line: the daily report selects the last 24 hours by the date at the start of each line, and undated `docker compose` output used to slip through that filter.
- **Daily report** `/usr/local/sbin/baninapro-jelentes` (systemd timer 03:30, the DB backup cron runs at 03:00).
  - Modes: `napi`, `kezi` (desktop icon, terminal window, sudoers rule for exactly this command), `proba`, `riasztas`.
  - It pushes a short summary, except in `riasztas` mode, where the watchdog has already pushed. The full report is e-mailed only if a sender is configured.
  - Reports are kept in `/var/log/baninapro-jelentes/`.
- **Nightly backup** `/usr/local/sbin/baninapro-mentes` (`/etc/cron.d/baninapro`, 03:00) wraps `cron_mentes.php`, logs to `/var/log/baninapro-mentes.log` and pushes the result. `baninapro-mentes masol` copies the newest complete backups from the volume to `/var/backups/baninapro` (outside Docker, newest 14 kept, only with ≥ 1 GB to spare), so a lost Docker data root can still be restored. The nightly run, the installer and the watchdog all call it.
- **Push notifications (ntfy).** The user subscribes to one secret topic in the ntfy phone app. The installer creates `baninapro-<24 random chars>` once in `/etc/baninapro/ntfy` and keeps it. It shows the topic in the summary and in `~/Asztal/BaninaPRO-ertesitesek.txt`, and sends one test push the first time (`NTFY_PROBA_KESZ=1`).
  - The user wants a push for everything except user activity. Logins and logouts are the only user events reported.
  - `/usr/local/sbin/baninapro-ertesites [-p 1-5] [-t tags] "Cím" "Üzenet"` publishes JSON to the server root. Unsent messages wait in `/var/spool/baninapro-ertesites` (at most 300), and the watchdog flushes them every 2 min. `--sorbol` is serialized with `flock`, so a message is never sent twice. 4xx responses other than 429 are dropped.
  - Boot and shutdown: `baninapro-leallas.service` (`ExecStop`) writes the `tiszta-leallas` marker on a clean shutdown. At boot, `baninapro-indulas.service` reports a reboot, or a power cut if the marker is missing, and estimates the outage from the watchdog's `eletjel` heartbeat. It is `Type=simple` so its retry loop never delays boot.
  - Logins: `baninapro-belepesfigyelo.service` tails the app's daily log in the `baninapro_adatok` volume. It reacts only to `BELEPES`, `KILEPES` and `KILEPTETES` lines, and only to complete lines. If `naplo()` in `includes/logger.php` changes its line format or these codes, update its regex.
  - The installer pushes its own end result (`vegeredmeny_ertesites`), success or failure.
- **Power settings.**
  - Sleep targets are masked, plus `sleep.conf.d` and `logind.conf.d`.
  - No screensaver or DPMS: Xorg `ServerFlags`, LightDM `-s 0 -dpms`, session `xset`, and locked system-wide xfconf defaults for the Xfce power manager and screensaver (`/etc/xdg/xfce4/xfconf/xfce-perchannel-xml/`).
  - AnyDesk runs with `Restart=always`.
  - Power-on after AC loss is set through `/sys/class/firmware-attributes` where the firmware allows it; otherwise the installer prints BIOS instructions.

Testing: Docker Desktop runs on this Mac, so reproduce the server in a privileged systemd Ubuntu container:
- The image is `ubuntu:24.04` (or `26.04`) plus `systemd systemd-sysv dbus sudo iproute2 kmod udev tzdata locales systemd-resolved` and a `baninapro` user with passwordless sudo. Leave curl, git, ca-certificates and cron out, so the bootstrap is tested too.
- `--privileged --cgroupns=private --tmpfs /run --tmpfs /run/lock`;
- anonymous volumes for `/var/lib/docker` and `/var/lib/containerd`;
- unmount the bind-mounted `/etc/hosts` and `/etc/hostname`, then write real ones;
- run the script as the sudo user without a TTY: `docker exec -u baninapro bp-szerver sudo bash …`.
- **Test the working tree, not GitHub.** `git clone` the local repo into the scratchpad, copy the changed script in, commit there, then point `origin` at GitHub and fetch. The clone is then *ahead* of `origin/main`, which `git_frissit` leaves alone. Never `sed` the script in the test copy: the stash logic would revert it.
- Point pushes at a local ntfy server (`binwiederhier/ntfy serve` on a shared docker network) by pre-creating `/etc/baninapro/ntfy` with `NTFY_SZERVER=http://bp-ntfy`. Read them back with `GET /<topic>/json?poll=1&since=all`. Don't send test pushes to the public ntfy.sh.
- `docker restart -t 180 <container>` simulates a clean reboot. `docker kill` followed by `docker start` simulates a power cut.
- In a container, LightDM and systemd-timesyncd don't run. The summary then asks for a reboot, which a run without a TTY skips.

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
- **Frontend tests.** `tests/e2e/futtat.sh` must stay green: 32 tests, run in 2 browser projects. The 600-row basket test and the long-utalás PDF test run on desktop only, so a full run reports 62 passed and 2 skipped.
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
  - Extract the PHP heredoc of `sema_osszevetes` (from `<?php` to the `PHP` line) and run `php -l` on it (`php:8.3-cli` image).
  - Then run a full end-to-end test from the server's real broken state (see the installer section).
  - Under `set -o pipefail`, `tr … </dev/urandom | head -c N` exits 141 (SIGPIPE) and `x="$(… | head -n 1)"` can too. Add `|| true`, or read the whole output into a variable or array first.
- **Destructive commands get blocked.** The auto-mode permission check blocks commands such as `DROP DATABASE`, even on a throwaway database. Verify non-destructively instead: diffs, or a separate throwaway container.

PDF output (1.21, `includes/pdf.php` + `includes/nyomtatas.php`):
- Table body rows are 9.5 pt pure black (#000000) with 7 pt English below (1.21, the user left the size to me; 1.20.1 had 10 pt, 1.20.0 12 pt), headers 8.5 pt with 6.5 pt English, on portrait A4 only (the user's decision: no landscape).
- Dates and amounts never wrap. Mark such columns `'nt' => true`: the cell stays on one line per `\n` and shrinks (to 75 % at most) only if it cannot fit. `ny_osszeg()` uses NBSP (U+00A0) as the thousands separator and before the currency, and `tordel()` breaks only at ASCII spaces, so a number never splits. Other text wraps; `tordel()` breaks long words after `-`, `.`, `/` and `_` before it falls back to single characters.
- Column widths are measured, not guessed (Lato, 9.5 pt, 1.1 mm padding per side): a date is 16.1 mm (column ≥ 18.5), `2026-HUF-000001-` 25.8 mm (column 28.3 → the ID breaks as `…-000001-` / `K0001`), `U-EUR-2026-000001` 27.8 mm, `UTALÁSHOZ ADVA` 25.1 mm, `12 500,50 EUR` 19.8 mm. Measure with `PdfIro::szovegSzelesseg()` before changing a width. The bejövő / kimenő lists (10 columns) stack the dates in pairs (`Kelt / Teljesítés`, `Határidő / Utalva|Fizetve`) because four date columns do not fit.
- **Main row (`'stilus' => 'fo'`, 1.21, user request):** the utalás row (also the banki kivonat row and the kötés row in the Összevetés) is a dark band with white text: deep green `PdfIro::$foSzin` in color, black in B&W. The rows after it, up to the next `fo` or `osszes` row, belong to it: a spine in the band color on their left edge, indented first cell, a gap before the next band, and the band repeats with „(folytatás)” after a page break. Row options: `'jelveny' => [k => rgb]` (status in a white rounded pill), `'vastag' => [k => true]` (bold cells; default all bold), `'igazit' => [k => 'L|R|C']`. The utalás print has no „Mód” (1.21) and no „Hivatkozás” column (1.21.1), both user requests; a partially paid invoice gets an `al` sub-row with its original amount and partial payments instead.
- Page-break rules: a `fo` row never ends a page without its first child; a block of `osszes` rows stays together and takes the preceding row with it.
- In color mode only the status column is colored: mark it with `'st' => true` in the column definition. Summary rows (`osszes`) keep their colors. The English line takes the color of its Hungarian cell.
- Black-and-white mode (`PdfIro::$ff`, from the `szin=ff` form field) forces every text and line to black, drops light fills and turns dark fills black; the only white is the text and pill on the `fo` band (`szoveg()`'s `$ffRgb`, `lekerekitett()`'s `$ffKitoltes`). The table header gets a top rule instead of its fill. It is toggled by `.fab-szin`, a two-state button at the bottom right of the print FAB, mirroring the red X. The state is per user in `localStorage` (`PdfSzin`), and `pdfKuldes()` sends it.

Pattern: adding a print-basket item type (1.16 `osszevetes`, 1.19 `allapot`):
- **JS.** Add the type to `KOSAR_TIPUS` (its order is the basket order). Any extra fields, such as `q`, must survive `Kosar.lista()`, `Kosar.hozzaad()` and `pdfKuldes()`. A `q`-carrying type also needs a cleaner in `KOSAR_Q`, and its id comes from `lekerdezesId()` over the query.
- **PHP.** Add the type to `NY_TIPUSOK`, add validation and a dedupe key in `pdf.php`, and add a renderer in `includes/nyomtatas.php`.
- **One data source.** Reuse the API's data function (for example `osszevetes_reszletek_adat()`) so the page and the PDF always agree.
- **Row styles.** `PdfIro::tablazat` supports `normal`, `fo` (main row: dark band, its rows below it), `al` (sub-row) and `osszes` (total). Text is bilingual: Hungarian, with English below.

Environment quirks:
- Ubuntu 26.04 ships uutils coreutils. `chown user:` keeps the old group there; this is cosmetic.
- In containers, `/etc/hosts` and `/etc/hostname` are bind mounts, so `sed -i` and `hostnamectl` fail on them. The test harness unmounts them first.
- mailpit's `--smtp-auth-file` cannot store passwords that contain `:`. That is a limit of the test tool, not of the installer.
