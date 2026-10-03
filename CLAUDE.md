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
  - utalás: `NYITOTT/UTALVA`, plus a `lezarva` lock (1.13) that freezes adding and removing invoices.
- **One currency per utalás** (HUF or EUR), enforced server-side.
- Amounts are DECIMAL, handled as 2-decimal strings (`be_osszeg`, `dec_sum`) and may be negative. Partial payments (`reszteljesitesek`) reduce the outstanding balance (`hatralek`). Lists, deadlines, comparison and search all use the balance, not the original amount.

### Frontend (`assets/app.js`, one IIFE, vanilla JS)
- A hash router: `route()` / `render()` near the end of the file map `#/bejovo/ceg/:id/kotes/:id`, `#/kimeno/ceg/:id`, `#/utalas/:id`, `#/utalasok`, `#/hataridok`, `#/osszevetes`, `#/import`, `#/admin`, `#/profil`, `#/qr/:token`, `#/regisztral/:kod` to `view*()` functions. These functions build HTML with template strings; always escape interpolated data with `esc()`.
- Clicks use **event delegation**: elements carry `data-act="name"`, and the handler is looked up in the `ACT` object. To add a button, add a `data-act` attribute plus an `ACT` entry; do not attach inline listeners.
- Client-side "baskets" persist per browser: `Kosar` (print basket → `pdf.php`), `UtalasKosar` (current transfer collector), `BankKosar` (outgoing invoices awaiting a bank ID). The floating FAB buttons appear when these baskets are non-empty.
- Session heartbeat: the page pings `ping` every `ULES_JELENLET_MP` seconds and sends `lap_zaras` (with keepalive) when the tab closes. The server expires sessions per the `ULES_*` constants (`includes/auth.php`). Heartbeats don't count as user activity.
- Design tokens are CSS variables at the top of `assets/app.css`. Animate only `transform`/`opacity`, and respect `prefers-reduced-motion`.

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
- Every response sends `noindex` headers (search engines and AI crawlers are deliberately blocked).
- The main root `.htaccess` described in `TELEPITES.md` is **not present in this working copy**. Do not assume it is deployed from here.
- `LOG/` and `DBBCKP/` hold real production logs and DB dumps containing business data. Don't publish or paste their contents anywhere.
