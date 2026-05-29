# Distributor and Deal Manager - CRUD Behavior

This document summarizes current CRUD behavior and key business rules.

## Scope

- Entities: `distributors`, `providers`, `deals`, `agent`
- Shared CRUD view: list/add/edit/delete via `views/entity/list.php`
- Core business logic: `src/Controllers/EntityController.php`
- Data access and helper methods: `src/Repositories/EntityRepository.php`

## General CRUD Behavior

- List views support search with query parameter `q`.
- Search query is preserved on create, update, and delete redirects.
- Active list filters are preserved on create, update, and delete redirects.
- Entity list sorting:
  - Distributors: name ascending
  - Providers: name ascending
  - Deals: deal name ascending
  - Agents: last name ascending (first name tie-break)

## Distributors

### Fields and Defaults

- `contract_term_years` is supported with allowed values: `1`, `2`, `3`.
- Default contract term is `1` year (schema + migration behavior).

### Term Change Behavior

- On distributor edit, term changes are detected.
- UI confirmation is shown when term changes (increase or reduction).
- If confirmed (`sync_distributor_provider_dates=1`), protected providers linked to that distributor are recalculated:
  - `providers.end_date = providers.start_date + contract_term_years`
  - Applies only to protected providers (`status=1`) with non-null `start_date`.
- If not confirmed, distributor term updates but provider end dates are not recalculated.

## Providers

### Status Model

- Provider status meanings:
  - `0` = Reserved
  - `1` = Protected
  - `2` = Open

### End Date Rule on Provider Save

- If provider status is Protected and start date is present, end date is set from start date plus distributor contract term years.
- If status is not Protected, end date is cleared.

### Distributor-Specific Rule (Diablo Data)

- Providers assigned to distributor name `Diablo Data` are always enforced as Protected.
- This protection enforcement applies across provider/deal flows and prevents reserve fallback.

### Providers List Filter

- Providers page supports filter by distributor (`distributor_id` query parameter).
- Distributor filter is preserved through add/edit/delete actions and redirects.

## Deals

### Stage and Close Date

- Deals stage options: Pending, Closed, Cancelled.
- On transition to Closed, `close_date` is set to today.

### Provider Updates from Deals

- On confirmed sync (`sync_provider_end_date=1`) during close transition:
  - Provider end date is set from deal close date plus distributor term years for that provider.
- Closing a deal can set a Reserved provider to Protected.

### Deals List Filter

- Deals page supports filter by agent (`agent_id` query parameter).
- Agent filter is preserved through add/edit/delete actions and redirects.

### Deal Import Rule

- Deals imported from the Agent import workflow are always created with stage `pending`.

### Reserve Reconciliation

- When a deal leaves Closed state (for example Closed -> Pending/Cancelled), and no closed deals remain for that provider:
  - Provider is set to Reserved and end date aligned to start date.
- Same reconciliation runs after deal delete when applicable.
- For `Diablo Data` providers, reserve fallback is skipped; provider remains Protected.

## UI Behavior Notes

- Provider table includes stacked combined columns for readability:
  - Distributor/Status
  - Start/End date
  - Phone/Phone Alt
  - Point of Contact (Name/Phone)
- Providers page includes responsive compact/detail behaviors and a details toggle.
- Distributors page includes responsive compact/detail behaviors and a details toggle.
- Distributors, Providers, and Deals list tables are rendered in separate list cards.
- Distributor, Provider, Deals, and Agent list tables are collapsible and default to closed.
- Distributor list auto-opens when a distributor search query is present.
- Deals list auto-opens when a deals search query is present or an agent filter is selected.
- Provider list auto-opens when provider search or distributor filter is present.
- Light/Dark theme toggle is available globally and persisted in local storage.

## Authentication and Access Control

- Login now authenticates against `users` table accounts (`email` + `password_hash`).
- Supported roles:
  - `internal_admin`: full read/write access to all views and actions.
  - `internal_read_only`: read-only access to internal views and reports.
  - `agent_viewer`: read-only access scoped to one distributor; no Reports view.
- All blocked access returns HTTP `403`.
- Session stores role and scoped distributor context for policy enforcement.
- Agent viewer accounts must be linked to one distributor and cannot be linked to internal-only distributors.

## User Administration

- Internal admins can manage accounts at `/users`.
- User admin supports:
  - Search users by email, display name, or role.
  - Create users with role, status, optional display name, and optional distributor scope.
  - Edit users (email, display name, role, distributor scope, status).
  - Optional password reset on edit (leave blank to keep current password).
- Role behavior in user admin:
  - `agent_viewer` requires a distributor scope and is blocked from internal-only distributors.
  - Internal roles (`internal_admin`, `internal_read_only`) are stored with no distributor scope.
- User admin is enforced server-side and only available to `internal_admin`.
- Distributor lookups in User admin are schema-compatible:
  - If `distributors.internal_only` exists, it is used directly.
  - If it does not exist yet, User admin falls back to `0 AS internal_only` to avoid runtime errors until migration is applied.

### Provisioning Notes

- Internal users that need full control should be created as `internal_admin`.
- Internal users that need read-only visibility (including internal-only distributors) should be created as `internal_read_only`.
- `agent_viewer` is intended for distributor-scoped external/agent visibility and is blocked from internal-only distributors.

## Multi-Tenancy Scope Rules

- Agent viewer users are scoped to their linked distributor only.
- Scope is enforced server-side for page views, API list endpoints, and CSV exports.
- Distributor records marked `internal_only=1` are internal-only and blocked for non-internal users.
- `Diablo Data` distributor is expected to be marked internal-only.

## List CSV Export

- Scoped list CSV export endpoints:
  - `/distributors/download-csv`
  - `/providers/download-csv`
  - `/deals/download-csv`
- Export honors active list query parameters (`q`, `distributor_id`, `agent_id`) and tenant scope.

## Agents

### Agent Imports Card

- Agents view includes a dedicated `Agent Imports` card.
- The card supports both deal import and provider import workflows.

### Deal Import

- Deal import accepts CSV uploads through `/agents/import-deals`.
- Template is downloadable from `/downloads/agent_deal_import_template.csv` and is Excel-friendly.
- Import supports these columns:
  - `deal_name`, `stage`, `revenue`, `deal_date`, `close_date`, `distributor_name`, `provider_name`, `agent_name`
- `deal_name` is required for each imported row.
- Date fields must be in `YYYY-MM-DD` format.
- Name-based lookups map distributor/provider/agent names to IDs when matches exist.

### Provider Import

- Provider import accepts CSV uploads through `/agents/import-providers`.
- Template is downloadable from `/downloads/agent_provider_import_template.csv` and is Excel-friendly.
- Provider import supports these columns:
  - `name`, `address`, `city`, `state`, `country_code`, `postal_code`, `phone`, `phone_alt`, `email`, `segment`, `distributor_name`, `point_of_contact_name`, `point_of_contact_phone`, `point_of_contact_email`, `customer_name`, `start_date`, `end_date`, `status`
- `name` is required for each imported row.
- Date fields must be in `YYYY-MM-DD` format.
- Imported providers are always created with Reserved status (`status=0`).
- Duplicate provider names are skipped (case-insensitive) and conflict reasons are shown to the user after import.

## Reports

### Provider Report

- Reports page includes a Provider Report section viewable on-screen.
- Provider Report supports filtering by distributor (`provider_distributor_id` query parameter).
- Provider Report supports CSV download from `/reports/download?type=providers`.
- Provider CSV download respects the current distributor filter.

### Deals Report

- Reports page includes a Deals Report section viewable on-screen.
- Deals Report supports filtering by agent (`report_agent_id` query parameter).
- Deals Report supports CSV download from `/reports/download?type=deals`.
- Deals CSV download respects the current agent filter.

## Related SQL Artifacts

- Init schema: `docker/mysql/init/001_schema.sql`
- Startup migrations: `migrations/`
- Migration runner: `tools/migrate.php`
- Production import schema: `sql/distdb_production_import.sql`
- Contract term migration: `sql/migration_add_distributor_contract_term.sql`

## Deployment Migration Notes

- Release `v0.2.0` runs migrations automatically before the container starts the PHP server.
- Applied migrations are stored in `schema_migrations`.
- Normal deployments should leave `DDM_RUN_MIGRATIONS=true`.
- Set `DDM_RUN_MIGRATIONS=false` only for emergency/manual migration control.
- Check container logs for `migrations complete` after deployment.

## Quick Validation Checklist

- Create/update distributor with term `1`, `2`, `3`.
- Confirm term change prompt appears on distributor edit.
- Verify provider end-date recalculation only when confirmed.
- Verify term reduction recalc works when confirmed.
- Verify providers distributor filter narrows results and survives CRUD redirects.
- Verify deals agent filter narrows results and survives CRUD redirects.
- Verify distributors Show Details / Hide Details toggles optional columns.
- Verify Distributor list card is collapsed by default and auto-opens on search.
- Verify Providers list card is collapsed by default and auto-opens on search/filter.
- Verify Deals list card is collapsed by default and auto-opens on search or agent filter.
- Verify Agent list is collapsible and default closed.
- Verify Agent Imports card renders separately in Agents view.
- Verify deal import template downloads and CSV deal import succeeds.
- Verify provider import template downloads and CSV provider import succeeds.
- Verify duplicate provider rows are skipped and conflict reasons are displayed.
- Verify imported providers are created in Reserved status.
- Verify imported deals are always created in Pending stage.
- Verify Provider Report distributor filter updates on-screen rows.
- Verify Provider Report CSV download works and matches selected distributor filter.
- Verify Deals Report agent filter updates on-screen rows.
- Verify Deals Report CSV download works and matches selected agent filter.
- Verify Diablo Data providers remain Protected regardless of deal transitions.
- Verify login with valid users table credentials succeeds.
- Verify agent_viewer cannot access `/reports` and receives `403`.
- Verify agent_viewer cannot add/edit/delete/import and receives `403` for direct POST attempts.
- Verify internal_read_only can view internal pages/reports but cannot perform write actions.
- Verify agent_viewer sees only one distributor scope across lists/API/download-csv endpoints.
- Verify internal-only distributors (`internal_only=1`) are blocked for non-internal users.
