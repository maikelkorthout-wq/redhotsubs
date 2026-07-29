# AGENTS.md

## Cursor Cloud specific instructions

RedHotSubs is a single PHP web app (the **Asatru PHP** MVC framework) with a Vanilla-JS/Webpack frontend and a MySQL/MariaDB database. There is one product/service: the web app served from `public/` via `public/index.php`. See `README.md` for the product overview and `app/config/routes.php` for all routes.

The startup update script keeps dependencies fresh (`composer install`, `npm install`, `npm run build`). Everything below is runtime setup that is NOT in the update script and must be done per session before the app/tests will work.

### Services & how to run them

- **MariaDB** is not started automatically. Start it (data dir is initialized on first boot):
  - `sudo mariadbd --user=mysql &` (if `/var/lib/mysql/mysql` is missing, first run `sudo mariadb-install-db --user=mysql --datadir=/var/lib/mysql`).
  - The app connects as `root` with an empty password over TCP+socket (`mysql_native_password`). If root auth fails, run: `sudo mariadb -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('');"`.
- **Web app (dev server):** `php asatru serve 8000` (framework command; binds localhost) or `php -S 0.0.0.0:8000 -t public public/index.php`. Both route deep paths correctly. The `asatru` CLI only works with `APP_DEBUG=true` (already set in `.env`).
- **Frontend assets:** `npm run build` (or `npm run watch` during dev) produces `public/js/app.js`. `public/js/app.js` is tracked in git — do not commit rebuilt/minified diffs of it unless intended.

### Required one-time-per-fresh-DB setup (non-obvious gotchas)

- **`.env` is gitignored.** Create it once: `cp .env.example .env`. `.env.example`/`.env.testing` include `DB_CHARSET=utf8mb4`, which the pinned framework (v1.5) requires; without it the app and PHPUnit bootstrap crash with `Unknown character set`.
- **Databases:** create `redhotsubs` (app) and `asatru` (used by the test suite via `.env.testing`), e.g. `CREATE DATABASE redhotsubs CHARACTER SET utf8mb4;`.
- **Migrations:** `php asatru migrate:fresh` migrates the DB named in `.env` (`redhotsubs`). The test DB `asatru` needs the same schema; copy it with `mysqldump -u root --no-data redhotsubs | mysql -u root asatru`.
- **Seed an `AppSettingsModel` row (id=1) or every page throws** `Call to a member function get() on null` (`app/models/AppSettingsModel.php`). There is no installer; insert one row with non-null `imprint, privacy, app, about, age_consent, info, info_style, head_code, categories`.
- **Login/auth:** `APP_PRIVATEMODE=true` (default) makes `/` redirect to `/auth` and disables web registration. To log in, seed a confirmed user directly: `INSERT INTO AuthModel (email, password, account_confirm, privileged) VALUES ('tester@example.com', '<php password_hash bcrypt>', '_confirmed', 1);`. Login requires `account_confirm = '_confirmed'`.

### Tests / lint

- Run the suite with `./vendor/bin/phpunit` (config `phpunit.xml`, bootstrap `app/tests/bootstrap.php` which reads `.env.testing`).
- `app/tests/IndexTest.php` is the unmodified Asatru skeleton test: it asserts `<h1>Welcome to the Asatru PHP framework</h1>` and `<h1>Error 404</h1>`, which do not exist in the RedHotSubs views, so both cases fail regardless of environment. This is a pre-existing repo condition, not an environment problem — the harness (PHP + PDO + DB) runs correctly.
- There is no separate PHP linter configured; `npm test` is a no-op placeholder.

### Reddit content

Browsing real subreddit content needs `REDDIT_CLIENT_ID`/`REDDIT_CLIENT_SECRET` in `.env` (Reddit OAuth app). Without them the UI shell, auth, and pages render, but no Reddit posts load. SMTP and the Twitter bot are optional and off by default.
