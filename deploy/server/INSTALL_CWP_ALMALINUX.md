# Install on the CWP server (AlmaLinux / Rocky, PostgreSQL 17): ns1.amplipay.online

For servers managed with Control Web Panel. Do **not** run `install-ubuntu.sh` here. MariaDB stays for CWP and other apps;
AmpliRail uses PostgreSQL only. On CWP the PHP 8.3 binary is `php83`: use it for every command below.

## The two rules that prevent the errors seen so far

1. **Install into a new, empty folder.** Never unzip AmpliRail on top of another project. Leftover files from an older
   Laravel app (for example `config/fortify.php`) break AmpliRail with errors like
   `Class "Laravel\Fortify\Features" not found`.
2. **Keep the app outside `public_html`.** Only the app's `public/` folder may be reachable from the web. If the whole app
   sits in `public_html/ns1`, files such as `.env` (database passwords) can be downloaded from the internet.

Layout used below (site user `amplidev`):

```
/home/amplidev/amplirail/amplirail-unified-commerce      <- the app (NOT web-reachable)
/home/amplidev/public_html/ns1  ->  .../amplirail-unified-commerce/public   <- what ns1.amplipay.online serves
```

## What the server admin prepares

- PostgreSQL 17, service `postgresql-17`, listening on 127.0.0.1 only, `scram-sha-256` passwords.
- Database `amplirail_dev`, owner role `amplirail_dev_owner` (NOT a superuser; owns the database and schema `public`),
  runtime role `amplirail_dev_app` (login, no create rights, CONNECT on the database).
- A second database `amplirail_dev_test` for the test run, owned by `amplirail_dev_owner`, with CONNECT for
  `amplirail_dev_app`. The suite drops and recreates every table and refuses any database not ending in `_test`.
- The subdomain `ns1.amplipay.online` with an SSL certificate (CWP AutoSSL / Let's Encrypt).

## 0. Move the old ns1 folder out of the way (do this first)

```bash
cd ~
ls -la public_html/ns1          # whatever is there now is NOT AmpliRail
mv public_html/ns1 ~/ns1_old_$(date +%Y%m%d)      # keep it as a backup; do not delete without asking its owner
```

If an earlier attempt left a `.env` inside `public_html/ns1`, treat the database passwords in it as exposed: ask the
server admin to change them.

## 1. PHP 8.3 extensions

Enable for PHP 8.3 in CWP: `pgsql`, `pdo_pgsql`, `mbstring`, `intl`, `zip`, `bcmath`, `fileinfo`, `openssl`, `sodium`,
and `pdo_sqlite` (tests only). Check:

```bash
php83 -v
php83 -m | grep -iE 'pgsql|intl|zip|bcmath|sodium'
php83 -r "echo defined('PASSWORD_ARGON2ID') ? 'argon2id ok' : 'argon2id MISSING';"
```

## 2. Unpack into a new folder (as amplidev, not root)

The zip already contains `vendor/` (all PHP libraries), so `composer install` is not needed.

```bash
mkdir -p ~/amplirail && cd ~/amplirail
unzip ~/AmpliRail_Unified_Commerce_v1.1.3.zip       # creates ~/amplirail/amplirail-unified-commerce
cd amplirail-unified-commerce
cat VERSION                                         # must print 1.1.3
ls vendor/autoload.php                              # must exist
php83 artisan --version                             # must print Laravel Framework 12.x
cp .env.example .env
php83 artisan key:generate
php83 artisan amplirail:secrets:local               # dev box only
```

`ls config/` must NOT contain `fortify.php`, `jetstream.php` or `sanctum.php`. If it does, the folder was not empty:
start this step again in a new folder.

## 3. Point ns1.amplipay.online at the public folder

Preferred (server admin, in CWP as root): set the document root of `ns1.amplipay.online` to
`/home/amplidev/amplirail/amplirail-unified-commerce/public`.

Or, as amplidev, with a symlink (after step 0 moved the old folder):

```bash
ln -s ~/amplirail/amplirail-unified-commerce/public ~/public_html/ns1
```

Check from your computer: `https://ns1.amplipay.online/.env` and `https://ns1.amplipay.online/composer.json` must both
return "Not Found". `https://ns1.amplipay.online/health` must return JSON (after step 5).

## 4. `.env` (never commit it, never put it in Drive or chat)

```
APP_ENV=development
APP_DEBUG=false
APP_URL=https://ns1.amplipay.online
ADMIN_URL=https://ns1.amplipay.online
MERCHANT_URL=https://ns1.amplipay.online
PAY_URL=https://ns1.amplipay.online

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=amplirail_dev
DB_USERNAME=amplirail_dev_app
DB_PASSWORD=<app password>
DB_MIGRATION_CONNECTION=pgsql_owner
DB_OWNER_USERNAME=amplirail_dev_owner
DB_OWNER_PASSWORD=<owner password>

SESSION_SECURE_COOKIE=true
# If Redis is not installed on this server:
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Then: `chmod 600 .env`

## 5. Database

```bash
php83 artisan config:clear
php83 artisan migrate --database=pgsql_owner --force     # as the OWNER; grants amplirail_dev_app automatically
php83 artisan db:seed --force
php83 artisan config:cache && php83 artisan route:cache && php83 artisan view:cache
```

## 6. Background processes and scheduler (root for systemd, amplidev for cron)

```bash
sudo cp deploy/server/systemd/amplirail-*.service /etc/systemd/system/
sudo sed -i "s|__APP__|/home/amplidev/amplirail/amplirail-unified-commerce|g; s|__USER__|amplidev|g; s|__PHP__|$(command -v php83)|g" /etc/systemd/system/amplirail-*.service
sudo systemctl daemon-reload && sudo systemctl enable --now amplirail-worker amplirail-relay
( crontab -l 2>/dev/null; echo "* * * * * cd /home/amplidev/amplirail/amplirail-unified-commerce && $(command -v php83) artisan schedule:run >> /dev/null 2>&1" ) | crontab -
```

## 7. First admin and checks

```bash
php83 artisan amplirail:admin:create you@company.com "Your Name" --role=platform_owner   # prints a one-time link
curl -s https://ns1.amplipay.online/health        # {"status":"ok","database":"ok","version":"1.1.3"}
```

Admin console: `https://ns1.amplipay.online/admin` · Merchant portal: `https://ns1.amplipay.online/app` · API: `/v1`.

## 8. Tests to send to the server admin

```bash
php83 packages/amplirail-core/tests/run.php            # expect 43/43 passed
php83 artisan test                                     # SQLite suite, expect 220 passed (needs pdo_sqlite)

DB_PORT=5432 DB_DATABASE=amplirail_dev_test \
DB_USERNAME=amplirail_dev_owner DB_PASSWORD='<owner password>' \
DB_APP_ROLE=amplirail_dev_app DB_APP_PASSWORD='<app password>' \
php83 vendor/bin/pest --configuration=phpunit.pgsql.xml  # expect 67 passed
```

Afterwards, on the live dev site: `php83 artisan config:cache`.

## Troubleshooting

| Error | Cause and fix |
| --- | --- |
| `Class "Laravel\Fortify\Features" not found` (or any Jetstream/Sanctum class) | AmpliRail was unpacked over another project. Start again in an empty folder (step 2). |
| `Failed opening required '.../vendor/autoload.php'` | `vendor/` is missing: the package was not fully unzipped, or an old download was used. Unzip v1.1.3 again into a new empty folder (step 2). |
| `CREATE command denied` / `Connection: mysql` | `.env` points at MySQL. AmpliRail uses only PostgreSQL (step 4). |
| `permission denied` during migrate | Run it with `--database=pgsql_owner`. |
| `Runtime role [...] does not exist` | `DB_USERNAME` in `.env` must be `amplirail_dev_app`, and that role must exist. |
| Blank page or 500 on the site | Document root is not the `public` folder (step 3), or `storage/` is not writable by the web server user. |
| `Refusing to run tests against database [amplirail_dev]` | Correct behaviour: point the tests at `amplirail_dev_test`. |

## SELinux note (AlmaLinux)

CWP usually runs with SELinux disabled. If it is enforcing: `chcon -R -t httpd_sys_rw_content_t storage bootstrap/cache`
and `setsebool -P httpd_can_network_connect_db 1`.
