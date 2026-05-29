# Distributor and Deal Manager

Starter PHP app for distdb with these tables:
- agent
- deals
- distributors
- providers

## Project Location
C:\Users\jimcl\Documents\Diablo Data\test\projects\Distributor-Deal-Manager\distributor-deal-manager

## Quick Start
1. Open a terminal in the project folder.
2. Copy `.env.sample` to `.env` and set local credentials.
3. Start project MySQL (first-time setup):

PowerShell:
docker compose up -d mysql

4. Run migrations:

PowerShell:
docker compose run --rm -e DDM_RUN_MIGRATIONS=false app php tools/migrate.php

5. Run app:

PowerShell:
./start.ps1

Then open:
http://127.0.0.1:8080

## Environment Setup Checklist
1. Run docker compose up -d mysql in this project folder.
2. Wait about 10-20 seconds for MySQL initialization on first run.
3. Copy .env.sample to .env and set local database credentials.
4. Keep DB_HOST set to 127.0.0.1 and DB_PORT set to 3306.
5. Run `php tools/migrate.php` inside the app container after schema changes.
6. Restart the app after any .env change.

Notes:
- The start script is Docker-only.
- It auto-builds image diablo-php-cli from the shared PHP CLI Dockerfile if needed.

Direct Docker command:
docker run --rm -p 8080:8080 -v "C:/Users/jimcl/Documents/Diablo Data/test/projects/Distributor-Deal-Manager/distributor-deal-manager:/app" -w /app diablo-php-cli php -S 0.0.0.0:8080 -t public

## Release Container
Tagged pushes publish a container image to GitHub Container Registry:

```text
ghcr.io/jimatdiablo/distributor-deal-manager:<tag>
ghcr.io/jimatdiablo/distributor-deal-manager:latest
```

Current release:

```text
ghcr.io/jimatdiablo/distributor-deal-manager:v0.2.2
```

`v0.2.2` includes deployment-safe startup migrations, the standard DB_* database environment names, and the Docker Compose app-container DB host fix. The release image runs `php tools/migrate.php` before the PHP server starts, then skips already-applied migration versions on later restarts.

The image intentionally does not include `.env`; pass runtime database settings through environment variables or an env file when the container is started.

The GHCR package is intended to be public so deployments can pull the release image without GitHub authentication.

## Database Configuration
Environment variables used by the app:
- DB_HOST
- DB_PORT
- DB_NAME
- DB_USER
- DB_PASSWORD
- DDM_MYSQL_ROOT_PASSWORD

Default DB name is distdb.
Use unique non-sample passwords for production or shared environments.

Docker note:
- Use DB_HOST=127.0.0.1 and DB_PORT=3306 for the standard local and deployment configuration.
- If an environment intentionally runs MySQL in a separate Docker network without host networking, override DB_HOST to that network hostname for that deployment only.
- In this repo's Docker Compose setup, the app container overrides DB_HOST to `mysql` because `127.0.0.1` inside a container points to the app container itself.
- Legacy DDM_DB_* variables are still accepted as fallbacks, but DB_* is the supported configuration.

## Included MySQL Setup
This project includes docker-compose.yml, auto-init SQL at docker/mysql/init/001_schema.sql, and idempotent startup migrations at migrations/.

Database migration behavior:
- MySQL runs docker/mysql/init/001_schema.sql only when the database volume is empty.
- The app image runs `php tools/migrate.php` on startup unless `DDM_RUN_MIGRATIONS=false`.
- Applied migration versions are tracked in `schema_migrations`.
- Migrations create or update the existing tables without dropping data.
- Optional first admin seed values can be provided through `DDM_ADMIN_EMAIL`, `DDM_ADMIN_PASSWORD`, and `DDM_ADMIN_NAME`.
- Deployment verification should include checking app logs for `migrations complete`.

Provisioned objects:
- database: distdb
- tables: agent, deals, distributors, providers, users, schema_migrations
- app user: configured by DB_USER

Docker Compose binds MySQL to 127.0.0.1:3306 on the host by default. Do not expose the database port publicly in production.

## First Admin Bootstrap

Fresh installs need one internal admin before `/users` can manage accounts.

Either set these before first startup:

```env
DDM_ADMIN_EMAIL=admin@example.com
DDM_ADMIN_PASSWORD=change-this-password
DDM_ADMIN_NAME=Initial Admin
```

or run this from the project folder after the database schema is loaded:

```powershell
docker run --rm -v "${PWD}:/app" -w /app diablo-php-cli php tools/bootstrap_admin.php --email="admin@example.com" --password="change-this-password" --name="Initial Admin"
```

The tool creates an `internal_admin` only when no active internal admin exists. If one already exists, it exits without changing users.

## Routes
- / Dashboard
- /login Auth placeholder
- /distributors CRUD starter list/create
- /deals CRUD starter list/create
- /providers CRUD starter list/create
- /agents CRUD starter list/create
- /reports Starter report page
- /users Internal admin user management

## API
- /api/distributors
- /api/deals
- /api/providers
- /api/agents

## Provider Status Mapping
- Reserved = `0`
- Protected = `1`
- Open = `2`

In the Providers UI, status labels and badges use Reserved/Protected/Open while other tables continue to use Active/Inactive/Pending.

## CSV Import Guardrails

Agent spreadsheet imports are limited to:
- CSV/TXT extension
- 2 MB maximum upload size
- 5,000 data rows per file

Deal and provider imports run inside database transactions. If an import fails mid-file or exceeds the row limit, inserted rows from that file are rolled back.

## Maintenance Notes

### Release update (2026-05-29)

- Tagged and published `v0.2.2` with the Docker Compose app-container database host fix.
- Tagged and published `v0.2.1` with standard DB_* database environment variables:
  `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_NAME=distdb`, `DB_USER=ddm`, and `DB_PASSWORD`.
- Tagged and published `v0.2.0` with deployment-safe startup migrations.
- Added idempotent startup migrations under `migrations/`, tracked in `schema_migrations`.
- Added `tools/migrate.php` and image startup wiring in `docker/app-entrypoint.sh`.
- Made `docker/mysql/init/001_schema.sql` additive for first-start database initialization.
- Added optional first-admin seed environment variables.
- Verified a fresh throwaway deployment applied `001_existing_schema`, served HTTP 200, and skipped the migration on repeat.
- Fresh restore point created after documentation refresh: `restorepoints/DistributorDealManager_2026-05-29_174140.zip`.
