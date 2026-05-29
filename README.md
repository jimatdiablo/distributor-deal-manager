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
4. Keep DDM_DB_HOST set to host.docker.internal when app runs in Docker.
5. Run `php tools/migrate.php` inside the app container after schema changes.
6. Restart the app after any .env change.

Notes:
- The start script is Docker-only.
- It auto-builds image diablo-php-cli from the shared PHP CLI Dockerfile if needed.

Direct Docker command:
docker run --rm --add-host "host.docker.internal:host-gateway" -p 8080:8080 -v "C:/Users/jimcl/Documents/Diablo Data/test/projects/Distributor-Deal-Manager/distributor-deal-manager:/app" -w /app diablo-php-cli php -S 0.0.0.0:8080 -t public

## Release Container
Tagged pushes publish a container image to GitHub Container Registry:

```text
ghcr.io/jimatdiablo/distributor-deal-manager:<tag>
ghcr.io/jimatdiablo/distributor-deal-manager:latest
```

The image intentionally does not include `.env`; pass runtime database settings through environment variables or an env file when the container is started.

The GHCR package is intended to be public so deployments can pull the release image without GitHub authentication.

## Database Configuration
Environment variables used by the app:
- DDM_DB_HOST
- DDM_DB_PORT
- DDM_DB_NAME
- DDM_DB_USER
- DDM_DB_PASS
- DDM_MYSQL_ROOT_PASSWORD
- DDM_DB_HOST_PORT

Default DB name is distdb.
Use unique non-sample passwords for production or shared environments.

Docker note:
- When running in Docker, use DDM_DB_HOST=host.docker.internal for a MySQL server running on your Windows host.
- If MySQL is in another container, use that container network hostname instead.
- The start script adds host.docker.internal via host-gateway for consistent routing.

## Included MySQL Setup
This project includes docker-compose.yml, auto-init SQL at docker/mysql/init/001_schema.sql, and idempotent startup migrations at migrations/.

Database migration behavior:
- MySQL runs docker/mysql/init/001_schema.sql only when the database volume is empty.
- The app image runs `php tools/migrate.php` on startup unless `DDM_RUN_MIGRATIONS=false`.
- Applied migration versions are tracked in `schema_migrations`.
- Migrations create or update the existing tables without dropping data.
- Optional first admin seed values can be provided through `DDM_ADMIN_EMAIL`, `DDM_ADMIN_PASSWORD`, and `DDM_ADMIN_NAME`.

Provisioned objects:
- database: distdb
- tables: agent, deals, distributors, providers, users, schema_migrations
- app user: configured by DDM_DB_USER

Docker Compose binds MySQL to 127.0.0.1 on the Windows host by default. Do not expose the database port publicly in production.

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
docker run --rm --add-host "host.docker.internal:host-gateway" -v "${PWD}:/app" -w /app diablo-php-cli php tools/bootstrap_admin.php --email="admin@example.com" --password="change-this-password" --name="Initial Admin"
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
