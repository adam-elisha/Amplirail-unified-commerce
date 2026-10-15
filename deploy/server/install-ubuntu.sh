#!/usr/bin/env bash
# AmpliRail single-server install for a DEVELOPMENT / PILOT-STAGING box (Ubuntu 24.04 LTS).
# Production target remains AWS (ECS Fargate + RDS + ElastiCache) per master document 4.3 / 17; see DEPLOY.md.
#
# Run as root (or with sudo) on a fresh server:
#   sudo APP_DOMAIN=dev.amplirail.com ADMIN_EMAIL=you@company.com bash deploy/server/install-ubuntu.sh
#
# What it does: installs PHP 8.3 + extensions, PostgreSQL 16, Redis, nginx, supervisor, Composer; creates the two
# database roles with random passwords; installs the app into /var/www/amplirail; runs migrations as the owner role;
# seeds reference data; configures nginx, the queue worker, the outbox relay and the scheduler.
# It prints the generated database passwords ONCE: put them in your password manager / Secrets Manager.
set -euo pipefail

APP_DOMAIN="${APP_DOMAIN:?set APP_DOMAIN, e.g. dev.amplirail.com}"
ADMIN_EMAIL="${ADMIN_EMAIL:?set ADMIN_EMAIL for the first admin invitation}"
ADMIN_NAME="${ADMIN_NAME:-Platform Owner}"
APP_DIR="/var/www/amplirail"
SRC_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

echo "==> Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y software-properties-common curl unzip git rsync openssl ca-certificates gnupg lsb-release
add-apt-repository -y ppa:ondrej/php
install -d /usr/share/postgresql-common/pgdg
curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list
apt-get update -y
apt-get install -y php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl \
  php8.3-bcmath php8.3-redis php8.3-opcache postgresql-16 redis-server nginx supervisor
if ! command -v composer >/dev/null; then
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
fi

echo "==> Database roles"
OWNER_PW="$(openssl rand -base64 30 | tr -d '/+=' | head -c 32)"
APP_PW="$(openssl rand -base64 30 | tr -d '/+=' | head -c 32)"
sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='amplirail') THEN CREATE ROLE amplirail LOGIN NOSUPERUSER NOCREATEROLE; END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='amplirail_app') THEN CREATE ROLE amplirail_app LOGIN NOSUPERUSER NOCREATEROLE NOCREATEDB; END IF;
END \$\$;
ALTER ROLE amplirail PASSWORD '${OWNER_PW}';
ALTER ROLE amplirail_app PASSWORD '${APP_PW}';
SQL
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='amplirail'" | grep -q 1 || sudo -u postgres createdb -O amplirail amplirail
sudo -u postgres psql -d amplirail -c "ALTER SCHEMA public OWNER TO amplirail; GRANT CONNECT ON DATABASE amplirail TO amplirail_app;"

echo "==> Application files"
mkdir -p "$APP_DIR"
rsync -a --delete --exclude vendor --exclude .env --exclude 'storage/logs/*' --exclude .git "$SRC_DIR/" "$APP_DIR/"
cd "$APP_DIR"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
cp -n .env.example .env
sed -i "s|^APP_ENV=.*|APP_ENV=development|; s|^APP_DEBUG=.*|APP_DEBUG=false|; s|^APP_URL=.*|APP_URL=https://${APP_DOMAIN}|" .env
sed -i "s|^ADMIN_URL=.*|ADMIN_URL=https://${APP_DOMAIN}|; s|^MERCHANT_URL=.*|MERCHANT_URL=https://${APP_DOMAIN}|; s|^PAY_URL=.*|PAY_URL=https://${APP_DOMAIN}|" .env
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${APP_PW}|; s|^DB_OWNER_PASSWORD=.*|DB_OWNER_PASSWORD=${OWNER_PW}|; s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|; s|^LOG_LEVEL=.*|LOG_LEVEL=info|" .env
php artisan key:generate --force
# Development box only: platform secrets in .env. For staging/production use SECRETS_DRIVER=aws (see DEPLOY.md).
php artisan amplirail:secrets:local
chown -R www-data:www-data storage bootstrap/cache
chmod 640 .env && chown root:www-data .env

echo "==> Migrate (owner role) and seed reference data"
php artisan migrate --database=pgsql_owner --force
php artisan db:seed --force
php artisan config:cache && php artisan route:cache && php artisan view:cache

echo "==> nginx, PHP-FPM, supervisor"
sed "s|__DOMAIN__|${APP_DOMAIN}|g; s|__ROOT__|${APP_DIR}/public|g" deploy/server/nginx-amplirail.conf > /etc/nginx/sites-available/amplirail.conf
ln -sf /etc/nginx/sites-available/amplirail.conf /etc/nginx/sites-enabled/amplirail.conf
rm -f /etc/nginx/sites-enabled/default
sed "s|__APP__|${APP_DIR}|g" deploy/server/supervisor-amplirail.conf > /etc/supervisor/conf.d/amplirail.conf
nginx -t && systemctl reload nginx
systemctl restart php8.3-fpm
supervisorctl reread && supervisorctl update && supervisorctl restart 'amplirail:*'

echo "==> First admin (Platform Owner). The link below is shown once."
php artisan amplirail:admin:create "$ADMIN_EMAIL" "$ADMIN_NAME" --role=platform_owner

cat <<EOF

AmpliRail is installed at https://${APP_DOMAIN}  (admin: /admin, merchant: /app, API: /v1, health: /health)
Next steps:
  1. TLS: sudo apt install certbot python3-certbot-nginx && sudo certbot --nginx -d ${APP_DOMAIN}
  2. Open the set-password link above, then enroll your authenticator app.
  3. Create the CRM service client:  php artisan amplirail:service-client:create "Fintech CRM"
  4. Store these database passwords in your password manager NOW (they are not shown again):
       owner (amplirail):      ${OWNER_PW}
       runtime (amplirail_app): ${APP_PW}
EOF
