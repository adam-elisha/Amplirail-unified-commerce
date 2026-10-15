-- AmpliRail PostgreSQL roles (SECURITY.md "Database"). Run ONCE as a superuser / RDS master user.
-- In AWS, Terraform creates these and stores the passwords in Secrets Manager; replace the placeholders below.
--   amplirail      owner role: owns the schema, runs migrations and partition maintenance. NOT a superuser
--                  (superusers bypass row-level security).
--   amplirail_app  runtime role used by the application: DML only, row-level security applies, no UPDATE/DELETE on
--                  ledger_postings, audit_log, auth_events (grants are applied by the migrations).
-- Local Docker uses the 'local-only' passwords below. NEVER use these anywhere else.

CREATE ROLE amplirail LOGIN PASSWORD 'local-only-owner' NOSUPERUSER NOCREATEROLE;
CREATE ROLE amplirail_app LOGIN PASSWORD 'local-only-app' NOSUPERUSER NOCREATEROLE NOCREATEDB;
ALTER DATABASE amplirail OWNER TO amplirail;
\connect amplirail
ALTER SCHEMA public OWNER TO amplirail;
GRANT CONNECT ON DATABASE amplirail TO amplirail_app;
