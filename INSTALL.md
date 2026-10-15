# Installing AmpliRail

Three ways to run it. Pick one:

| Option | Use it for | Time |
| --- | --- | --- |
| A. Local Docker | Developer laptops | 10 minutes |
| B. Single Ubuntu server | Development / pilot-staging box (e.g. dev.amplirail.com) | 30 minutes |
| B2. CWP server (AlmaLinux/Rocky) with PostgreSQL 17 | Our server1 dev box: follow [deploy/server/INSTALL_CWP_ALMALINUX.md](deploy/server/INSTALL_CWP_ALMALINUX.md) | 30 minutes |
| C. AWS (ECS Fargate + RDS + ElastiCache + S3) | Staging and production (master document 4.3, 16, 17) | Platform Owner + Lead PHP Developer |

Rules that apply to every option (AGENTS.md, SECURITY.md): no real card numbers anywhere (hosted fields and tokens only);
no secrets in git, tickets, chat or logs; development uses the simulator connector only; production deploys need Platform
Owner authorization.

---

## Requirements

- PHP 8.3+ with `pdo_pgsql`, `mbstring`, `intl`, `zip`, `bcmath`, `openssl`, `fileinfo`, `redis` (or use `REDIS_CLIENT=predis`)
- Composer 2, PostgreSQL 16, Redis 7
- PostgreSQL 16 or 17 (MySQL/MariaDB is not supported). Roles: an owner role that is **not a superuser** (default `amplirail`) and a runtime role (default `amplirail_app`; any names work: the runtime role is `DB_APP_ROLE`, defaulting to `DB_USERNAME`).
  Superusers bypass row-level security, so never run the app as a superuser. `deploy/postgres/01-roles.sql` creates both.

Verify the package before anything else:

```bash
composer install
composer core                     # expect: 43/43 passed
cd packages/amplirail-core && sha256sum -c SHA256SUMS && cd ../..
```

---

## A. Local Docker

```bash
cp .env.example deploy/.env.local
```

Edit `deploy/.env.local`:

```
APP_ENV=local
APP_KEY=base64:<run: openssl rand -base64 32>
DB_HOST=db
DB_PASSWORD=local-only-app
DB_OWNER_PASSWORD=local-only-owner
REDIS_HOST=redis
AMPLIRAIL_SECRET_FIELD_ENCRYPTION_KEY=<openssl rand -base64 32>
AMPLIRAIL_SECRET_FINGERPRINT_KEY=<openssl rand -base64 32>
AMPLIRAIL_SECRET_LINK_SIGNING_KEY=<openssl rand -base64 32>
FEATURE_CRM_HANDOFF=true
```

```bash
docker compose -f deploy/docker-compose.yml up --build
docker compose -f deploy/docker-compose.yml exec app php artisan amplirail:admin:create you@company.com "Your Name" --role=platform_owner
docker compose -f deploy/docker-compose.yml exec app php artisan amplirail:demo     # optional synthetic demo data
```

Open the printed set-password link (http://localhost:8000/admin/invitation/...), set a password, sign in, scan the QR code
with an authenticator app. Admin: http://localhost:8000/admin · Merchant: http://localhost:8000/app · API: http://localhost:8000/v1

Without Docker (PHP installed locally): `cp .env.example .env`, set the DB values, then
`php artisan key:generate && php artisan amplirail:secrets:local && php artisan migrate --database=pgsql_owner && php artisan db:seed && php artisan serve`
and in other terminals `php artisan queue:work`, `php artisan amplirail:outbox:relay`, `php artisan schedule:work`.

---

## B. Single Ubuntu 24.04 server (development / pilot-staging)

1. Point DNS for your host (for example `dev.amplirail.com`) at the server. Open ports 80 and 443 only.
2. Copy this repository to the server (git clone from the company GitHub organization, or unzip the package).
3. Run the installer as root:

```bash
sudo APP_DOMAIN=dev.amplirail.com ADMIN_EMAIL=you@company.com ADMIN_NAME="Your Name" bash deploy/server/install-ubuntu.sh
```

It installs PHP 8.3, PostgreSQL 16, Redis, nginx and supervisor; creates the two database roles with random passwords;
installs the app in `/var/www/amplirail`; migrates as the owner role; seeds reference data; starts the queue worker, the
outbox relay and the scheduler; and prints a one-time set-password link for the Platform Owner plus the database
passwords (store them in your password manager immediately; they are not shown again).

4. Add TLS: `sudo apt install certbot python3-certbot-nginx && sudo certbot --nginx -d dev.amplirail.com`,
   then set `SESSION_SECURE_COOKIE=true` (already set by the installer) and `php artisan config:cache`.
5. Open the set-password link, sign in at `https://dev.amplirail.com/admin`, enroll your authenticator app.
6. Check health: `curl https://dev.amplirail.com/health` returns `{"status":"ok","database":"ok",...}`.

The installer keeps platform secrets in `.env` (`SECRETS_DRIVER=env`), which is acceptable on a development box only.
For staging and production switch to AWS Secrets Manager (Option C, step 3).

Updating the server later:

```bash
cd /var/www/amplirail && git pull   # or rsync the new release
composer install --no-dev --optimize-autoloader
php artisan migrate --database=pgsql_owner --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo supervisorctl restart 'amplirail:*' && sudo systemctl reload php8.3-fpm
```

---

## C. AWS staging and production

Terraform is ticket AR-006 (separate accounts per environment, private networking, no public database). The application
expects:

1. **RDS PostgreSQL 16** (Multi-AZ, PITR 35 days, KMS). Create `amplirail` (owner, not superuser) and `amplirail_app`
   with `deploy/postgres/01-roles.sql`, passwords in Secrets Manager. `DB_SSLMODE=require`.
2. **ElastiCache Redis 7** (TLS, auth token). Sessions, cache, locks, queues and the event stream.
3. **Secrets Manager** under `SECRETS_PREFIX` (`amplirail/staging`, `amplirail/prod`). Set `SECRETS_DRIVER=aws`. Create:
   - `field-encryption-key` = 32 random bytes, base64 (`openssl rand -base64 32`)
   - `fingerprint-key` = random string (`openssl rand -base64 32`)
   - `link-signing-key` = random string (`openssl rand -base64 32`)
   - one secret per live MID for provider credentials, e.g. `mid/EDP-M-00012871/nmi` (the NMI security key). Operations
     record only the *name* in the admin console ("Record credentials"); the value never passes through AmpliRail.
   - `APP_KEY`, `DB_PASSWORD`, `DB_OWNER_PASSWORD`, Redis auth: injected into the task definition as ECS secrets.
4. **S3** private bucket with SSE-KMS for KYB documents: `DOCUMENTS_DISK=documents_s3`, `DOCUMENTS_BUCKET`, `DOCUMENTS_KMS_KEY_ID`.
   Block public access; the task role gets `s3:PutObject/GetObject` on that bucket and `kms:Encrypt/Decrypt` on the key.
5. **ECS services** from the one image (`deploy/Dockerfile`):

   | Service | Command | Scale |
   | --- | --- | --- |
   | api | default (Octane on 8000), behind the ALB + WAF | 2+ tasks, autoscale on CPU |
   | worker | `php artisan queue:work redis --tries=3 --max-time=3600` | 2+ |
   | relay | `php artisan amplirail:outbox:relay` | 1-2 (safe to run several: rows are locked with SKIP LOCKED) |
   | scheduler | `php artisan schedule:work` | exactly 1 |
   | migrate (one-off task) | `php artisan migrate --database=pgsql_owner --force && php artisan amplirail:partitions:ensure` | per deploy |

6. **Environment** (non-secret): copy `.env.example` values into the task definition with
   `APP_ENV=production APP_DEBUG=false LOG_STACK=stderr LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter
   SESSION_SECURE_COOKIE=true OUTBOX_PUBLISHER=redis_streams CACHE_STORE=redis SESSION_DRIVER=redis QUEUE_CONNECTION=redis
   MAIL_MAILER=ses AMPLIRAIL_ENVIRONMENT_BADGE=live`, hosts `ADMIN_HOST=admin.amplirail.com MERCHANT_HOST=app.amplirail.com
   PAY_HOST=pay.amplirail.com` and matching `*_URL` values, `TRUSTED_PROXIES` = VPC CIDR.
7. **CI/CD**: `.github/workflows/ci.yml` (tests on SQLite and PostgreSQL, PHPStan, architecture, gitleaks, OpenAPI lint),
   `deploy-dev.yml` (main -> development), `deploy-prod.yml` (published release, GitHub `production` environment needs the
   Platform Owner and the Lead PHP Developer). Set `vars.AWS_DEPLOY_ROLE_ARN` (GitHub OIDC role) and `vars.ECS_NETWORK_CONFIG`.
8. **Backups** (SECURITY.md): AWS Backup copy to the backup account with vault lock, monthly restore test with a
   ledger balance check (`docs/ACCEPTANCE.md` has the SQL).

---

## After installing (every option)

1. **First admin**: `php artisan amplirail:admin:create you@company.com "Name" --role=platform_owner`. Invite the rest of
   the staff from Management in the admin console (roles: owner, operations, finance, underwriter, support_agent,
   developer, read_only).
2. **Connect the CRM** (AR-168):
   ```bash
   php artisan amplirail:service-client:create "Fintech CRM" --scopes=crm.handoff,merchants.read
   ```
   Give the client ID and secret to the CRM team through the secrets manager (never email or chat). Turn on the
   `crm_handoff` flag in Developers and system (reason required; audited). The CRM then calls:
   ```bash
   curl -u CLIENT_ID:CLIENT_SECRET -d grant_type=client_credentials -d scope=crm.handoff https://HOST/v1/oauth/token
   curl -H "Authorization: Bearer arsvt_..." -H "Idempotency-Key: deal-D-10482" -H "Content-Type: application/json" \
        -d @handoff.json https://HOST/v1/merchants/handoff
   ```
   The request body is `MerchantHandoff` in `openapi/amplirail-v1.yaml`.
3. **Run the Milestone #1 acceptance test** in `docs/ACCEPTANCE.md` (about 15 minutes).
4. **Daily operations**: Overview shows the activation pipeline and every blocker; Approvals is the dual-control queue.

## Going live with the NMI bridge (after Adam's prerequisites)

1. Put the NMI documentation in `docs/providers/nmi/` and verify every field name and response code used by
   `packages/amplirail-core/src/Connectors/Nmi/NmiBridgeConnector.php` and `public/js/pay.js` (tickets AR-016 to AR-019).
2. Set `NMI_TRANSACT_URL`, `NMI_COLLECT_JS_URL`, `NMI_PUBLIC_TOKENIZATION_KEY`, `CONNECTOR_NMI_ENABLED=true`.
3. For each pilot merchant in Merchant 360: submit to the route, record the processor approval, record the production
   MID/TID, store the NMI security key in Secrets Manager and record its name, run validation, complete the go-live
   configuration, request go-live. A second authorized person approves in Approvals (authenticator code + note). Live
   API keys are issued at that moment and the merchant reveals the secret once in the portal.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| Every page or API call returns empty data / 404 on PostgreSQL | Tenant context not set or the app connects as the owner without context. Check `DB_USERNAME=amplirail_app` and that requests go through the portal/API middleware. RLS is doing its job. |
| `Secret [field-encryption-key] is unavailable` | Local: run `php artisan amplirail:secrets:local`. AWS: create the secret under `SECRETS_PREFIX` and grant the task role `secretsmanager:GetSecretValue`. |
| Webhooks never arrive | Is the relay running (`amplirail:outbox:relay`) and a queue worker? Developers and system shows the outbox backlog and delivery errors. URLs must be HTTPS on a public address. |
| `go_live_gate_unavailable` | The `go_live_gate` flag is off or unreadable. This is fail-closed by design: no merchant can be activated until it is on. |
| Migration error about permissions | Run migrations with `--database=pgsql_owner` (owner role), never with the runtime role. |
