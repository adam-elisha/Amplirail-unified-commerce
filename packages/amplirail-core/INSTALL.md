# Install AmpliRail Core

Requirements: PHP 8.3 or newer (with json and mbstring), Git. Composer optional for step 3.

## 1. Put it in the company repository
```
unzip AmpliRail_Core_Code_v1.0.0.zip
cd amplirail-core
git init && git add . && git commit -m "AR-003 AmpliRail core v1.0.0-launch"
git remote add origin git@github.com:amplipay/<your-repository>.git
git push -u origin main
```
Use the company GitHub organization only (Mohit: amplirail-unified-commerce; Dhaval: amplirail-dhaval).

## 2. Verify (must pass before anything else)
```
php tests/run.php
```
Expected last line: `43/43 passed`. Optional: `sha256sum -c SHA256SUMS` to confirm the files are unchanged.

## 3. Optional: Composer autoload
```
composer dump-autoload
composer test
```

## 4. Wire into the Laravel 12 application (days 1 to 4 of the plan)
- Create the Laravel 12 app in the same repository and require this package by path, or move src/ into the module folders listed in LARAVEL_INTEGRATION.md (in the launch pack).
- Convert database/001_partitioned_core.sql into migrations.
- Bind connectors with secrets read from AWS Secrets Manager (never from code or committed .env files).
- deploy/Dockerfile and .github/workflows/deploy-*.yml are used once the Laravel app exists (they run `php artisan`).

## 5. Local run of the full app (after step 4)
```
cp deploy/.env.example deploy/.env.local
docker compose -f deploy/docker-compose.yml up --build
```
Local and development use the simulator connector only. No real cards, live keys or merchant data in development.

## Rules
Follow AGENTS.md and SECURITY.md from the launch pack. No card numbers anywhere; tokens only. Provider documentation stays in docs/providers/<provider>/ only.
