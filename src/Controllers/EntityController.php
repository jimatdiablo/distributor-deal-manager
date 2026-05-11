<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Repositories\EntityRepository;

final class EntityController
{
    private const IMPORT_MAX_BYTES = 2097152;
    private const IMPORT_MAX_ROWS = 5000;

    public function list(string $table, string $title, string $view, array $request, array $config): string
    {
        $authUser = Auth::requireUser($config);
        if (!Auth::canViewTable($authUser, $table)) {
            Auth::forbidden();
        }
        if ($view === 'reports/index' && !Auth::canViewReports($authUser)) {
            Auth::forbidden();
        }

        $repo = new EntityRepository(Database::pdo($config));
        $scopeDistributorId = Auth::scopeDistributorId($authUser);
        $searchQuery = trim((string)($request['query']['q'] ?? ''));
        $distributorFilter = isset($request['query']['distributor_id']) ? (int)$request['query']['distributor_id'] : 0;
        $agentFilter = isset($request['query']['agent_id']) ? (int)$request['query']['agent_id'] : 0;
        $providerReportDistributorId = isset($request['query']['provider_distributor_id']) ? (int)$request['query']['provider_distributor_id'] : 0;
        $dealsReportAgentId = isset($request['query']['report_agent_id']) ? (int)$request['query']['report_agent_id'] : 0;
        $providerReservedNotice = trim((string)($request['query']['provider_reserved_notice'] ?? ''));

        if ($scopeDistributorId > 0 && $table === 'providers' && $distributorFilter > 0 && $distributorFilter !== $scopeDistributorId) {
            Auth::forbidden();
        }

        $rows = $repo->all($table, $searchQuery);
        $rows = $this->applyScopeToRows($repo, $table, $rows, $authUser);
        if ($table === 'providers' && $distributorFilter > 0) {
            $rows = array_values(array_filter(
                $rows,
                static function (array $row) use ($distributorFilter): bool {
                    $rowDistributorId = (int)($row['distributor_id'] ?? ($row['distID'] ?? ($row['distributorID'] ?? 0)));
                    return $rowDistributorId === $distributorFilter;
                }
            ));
        } elseif ($table === 'deals' && $agentFilter > 0) {
            if ($scopeDistributorId > 0 && !$this->agentBelongsToDistributor($repo, $agentFilter, $scopeDistributorId)) {
                Auth::forbidden();
            }

            $rows = array_values(array_filter(
                $rows,
                static function (array $row) use ($agentFilter): bool {
                    $rowAgentId = (int)($row['agent_id'] ?? ($row['agentID'] ?? 0));
                    return $rowAgentId === $agentFilter;
                }
            ));
        }

        $contractAlerts = [];
        $providerReportRows = [];
        $dealsReportRows = [];
        if ($view === 'reports/index') {
            $contractAlerts = $repo->distributorContractsEndingWithinDays(60);
            $providerReportRows = $repo->providersReportRows($providerReportDistributorId);
            $dealsReportRows = $repo->dealsReportRowsByAgent($dealsReportAgentId);
        }

        $columns = $repo->columns($table);
        $editId = isset($request['query']['edit']) ? (int)$request['query']['edit'] : 0;
        $editRow = $editId > 0 ? $repo->find($table, $editId) : null;
        if (is_array($editRow) && !$this->rowVisibleToUser($repo, $table, $editRow, $authUser)) {
            Auth::forbidden();
        }

        $lookups = [];
        if ($table === 'deals') {
            $lookups = $repo->dealLookups();
        } elseif ($table === 'agent') {
            $lookups = ['distributors' => $repo->distributorLookups()];
        } elseif ($table === 'providers') {
            $dealLookups = $repo->dealLookups();
            $lookups = [
                'distributors' => $repo->distributorLookups(),
                'agents' => $dealLookups['agents'] ?? [],
            ];
        }
        $lookups = $this->applyScopeToLookups($repo, $lookups, $authUser);

        if ($scopeDistributorId > 0 && $table === 'providers') {
            $distributorFilter = $scopeDistributorId;
        }

        $canWrite = Auth::canWrite($authUser);
        $canExportCsv = in_array($table, ['distributors', 'providers', 'deals'], true);

        return View::render($view, [
            'title' => $title,
            'rows' => $rows,
            'columns' => $columns,
            'tableName' => $table,
            'editRow' => $editRow,
            'lookups' => $lookups,
            'searchQuery' => $searchQuery,
            'distributorFilter' => $distributorFilter,
            'agentFilter' => $agentFilter,
            'providerReportDistributorId' => $providerReportDistributorId,
            'dealsReportAgentId' => $dealsReportAgentId,
            'providerReservedNotice' => $providerReservedNotice,
            'contractAlerts' => $contractAlerts,
            'providerReportRows' => $providerReportRows,
            'dealsReportRows' => $dealsReportRows,
            'currentUser' => $authUser,
            'canWrite' => $canWrite,
            'canExportCsv' => $canExportCsv,
        ]);
    }

    public function create(string $table, string $redirectPath, array $request, array $config): void
    {
        $authUser = Auth::requireUser($config);
        if (!Auth::canWrite($authUser) || !Auth::canViewTable($authUser, $table)) {
            Auth::forbidden();
        }

        $repo = new EntityRepository(Database::pdo($config));

        $payload = $request['body'] ?? [];
        if ($table === 'providers') {
            if ((!isset($payload['start_date']) || trim((string)$payload['start_date']) === '')
                && (!isset($payload['sdate']) || trim((string)$payload['sdate']) === '')) {
                if (array_key_exists('start_date', $payload)) {
                    $payload['start_date'] = date('Y-m-d');
                }
                if (array_key_exists('sdate', $payload)) {
                    $payload['sdate'] = date('Y-m-d');
                }
            }

            $this->enforceProtectedStatusForDiabloDataProviderPayload($repo, $payload, null);
            $this->applyProviderEndDateRule($repo, $payload);
        } elseif ($table === 'distributors') {
            $this->applyDistributorContractDateRule($repo, $payload, null);
        } elseif ($table === 'deals') {
            if ((!isset($payload['deal_date']) || trim((string)$payload['deal_date']) === '')
                && (!isset($payload['date']) || trim((string)$payload['date']) === '')) {
                if (array_key_exists('deal_date', $payload)) {
                    $payload['deal_date'] = date('Y-m-d');
                }
                if (array_key_exists('date', $payload)) {
                    $payload['date'] = date('Y-m-d');
                }
            }

            $this->applyDealDefaults($payload);
            $this->applyDealCloseDateRule($payload);
        }

        $repo->create($table, $payload);
        if ($table === 'deals') {
            $this->enforceDiabloDataProviderAlwaysProtected($repo, $this->resolveDealProviderId($payload, null));
            $this->ensureProviderProtectedOnClosedDeal($repo, $payload, null);
            $this->syncProviderEndDateFromDealIfConfirmed($repo, $payload, null);
        }

        header('Location: ' . $this->buildRedirectPath($redirectPath, $request));
        exit;
    }

    public function update(string $table, string $redirectPath, array $request, array $config): void
    {
        $authUser = Auth::requireUser($config);
        if (!Auth::canWrite($authUser) || !Auth::canViewTable($authUser, $table)) {
            Auth::forbidden();
        }

        $repo = new EntityRepository(Database::pdo($config));
        $payload = $request['body'] ?? [];
        $id = isset($payload['id']) ? (int)$payload['id'] : 0;

        if ($id > 0) {
            if ($table === 'providers') {
                $existing = $repo->find($table, $id);
                $this->enforceProtectedStatusForDiabloDataProviderPayload($repo, $payload, $existing);
                $this->applyProviderEndDateRule($repo, $payload, $existing);
            } elseif ($table === 'distributors') {
                $existing = $repo->find($table, $id);
                $this->applyDistributorContractDateRule($repo, $payload, $existing);
                $repo->update($table, $id, $payload);
                $extendFlag = trim((string)($payload['extend_distributor_contract'] ?? '0'));
                if ($extendFlag === '1') {
                    $repo->extendDistributorContractEndDateByTerm($id);
                }
                $this->maybeRecalcProviderDatesForDistributorTermChange($repo, $id, $payload, $existing);
                header('Location: ' . $this->buildRedirectPath($redirectPath, $request));
                exit;
            } elseif ($table === 'deals') {
                $this->applyDealDefaults($payload);
                $existing = $repo->find($table, $id);
                $this->applyDealCloseDateRule($payload, $existing);
                $this->ensureProviderProtectedOnClosedDeal($repo, $payload, $existing);
                $this->syncProviderEndDateFromDealIfConfirmed($repo, $payload, $existing);
                $repo->update($table, $id, $payload);
                $reservedApplied = $this->reserveProviderIfNoClosedDealsRemainAfterDealChange($repo, $payload, $existing, true);
                $this->enforceDiabloDataProviderAlwaysProtected($repo, $this->resolveDealProviderId($payload, $existing));
                $flags = $reservedApplied ? ['provider_reserved_notice' => '1'] : [];
                header('Location: ' . $this->buildRedirectPath($redirectPath, $request, $flags));
                exit;
            }
            $repo->update($table, $id, $payload);
        }

        header('Location: ' . $this->buildRedirectPath($redirectPath, $request));
        exit;
    }

    public function delete(string $table, string $redirectPath, array $request, array $config): void
    {
        $authUser = Auth::requireUser($config);
        if (!Auth::canWrite($authUser) || !Auth::canViewTable($authUser, $table)) {
            Auth::forbidden();
        }

        $repo = new EntityRepository(Database::pdo($config));
        $payload = $request['body'] ?? [];
        $id = isset($payload['id']) ? (int)$payload['id'] : 0;

        if ($id > 0) {
            $existingDeal = null;
            $reservedApplied = false;
            if ($table === 'deals') {
                $existingDeal = $repo->find($table, $id);
            }
            $repo->delete($table, $id);
            if ($table === 'deals' && is_array($existingDeal)) {
                $reservedApplied = $this->reserveProviderIfNoClosedDealsRemainAfterDealDelete($repo, $existingDeal);
                $this->enforceDiabloDataProviderAlwaysProtected($repo, $this->resolveDealProviderId([], $existingDeal));
            }

            if ($reservedApplied) {
                header('Location: ' . $this->buildRedirectPath($redirectPath, $request, ['provider_reserved_notice' => '1']));
                exit;
            }
        }

        header('Location: ' . $this->buildRedirectPath($redirectPath, $request));
        exit;
    }

    public function listJson(string $table, array $request, array $config): array
    {
        $authUser = Auth::requireUser($config);
        if (!Auth::canViewTable($authUser, $table)) {
            Auth::forbidden();
        }

        $repo = new EntityRepository(Database::pdo($config));
        $searchQuery = trim((string)($request['query']['q'] ?? ''));
        $rows = $repo->all($table, $searchQuery);
        $rows = $this->applyScopeToRows($repo, $table, $rows, $authUser);

        return [
            'table' => $table,
            'query' => $searchQuery,
            'data' => $rows,
        ];
    }

    public function importDealsFromSpreadsheet(array $request, array $config): void
    {
        $authUser = Auth::requireUser($config);
        if (!Auth::canWrite($authUser)) {
            Auth::forbidden();
        }

        $pdo = Database::pdo($config);
        $repo = new EntityRepository($pdo);
        $upload = $request['files']['deal_import_file'] ?? null;
        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            header('Location: /agents?import_status=error&import_message=' . urlencode('Please choose a CSV file to import.'));
            exit;
        }

        $tmpName = (string)($upload['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            header('Location: /agents?import_status=error&import_message=' . urlencode('Uploaded file is unavailable.'));
            exit;
        }

        if ((int)($upload['size'] ?? 0) > self::IMPORT_MAX_BYTES) {
            header('Location: /agents?import_status=error&import_message=' . urlencode('CSV file is too large. Imports are limited to 2 MB.'));
            exit;
        }

        $ext = strtolower((string)pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            header('Location: /agents?import_status=error&import_message=' . urlencode('Only CSV files are supported for import.'));
            exit;
        }

        $handle = fopen($tmpName, 'rb');
        if ($handle === false) {
            header('Location: /agents?import_status=error&import_message=' . urlencode('Unable to read uploaded file.'));
            exit;
        }

        $headerRow = fgetcsv($handle);
        if (!is_array($headerRow) || $headerRow === []) {
            fclose($handle);
            header('Location: /agents?import_status=error&import_message=' . urlencode('CSV header row is missing.'));
            exit;
        }

        $headerMap = $this->buildImportHeaderMap($headerRow);
        if (!isset($headerMap['deal_name'])) {
            fclose($handle);
            header('Location: /agents?import_status=error&import_message=' . urlencode('CSV must include deal_name column.'));
            exit;
        }

        $lookups = $repo->dealLookups();
        $distByName = $this->nameToIdMap((array)($lookups['distributors'] ?? []));
        $providerByName = $this->nameToIdMap((array)($lookups['providers'] ?? []));
        $agentByName = $this->nameToIdMap((array)($lookups['agents'] ?? []));

        $imported = 0;
        $skipped = 0;
        $conflicts = [];
        $rowNumber = 1;
        $dataRowCount = 0;
        $publicError = 'Unable to import deals CSV. Please check the file and try again.';

        $existingDistributorNames = [];
        foreach ($repo->all('distributors') as $existingDistributor) {
            $nameKey = $this->normalizeNameKey((string)($existingDistributor['name'] ?? ''));
            if ($nameKey !== '') {
                $existingDistributorNames[$nameKey] = true;
            }
        }

        try {
            $pdo->beginTransaction();
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $dataRowCount++;
                if ($dataRowCount > self::IMPORT_MAX_ROWS) {
                    $publicError = 'CSV row limit exceeded. Imports are limited to ' . self::IMPORT_MAX_ROWS . ' data rows.';
                    throw new \RuntimeException($publicError);
                }

                if (!is_array($row) || $this->rowIsBlank($row)) {
                    continue;
                }

                $dealPayload = $this->dealPayloadFromImportRow($row, $headerMap, $distByName, $providerByName, $agentByName);
                if ($dealPayload === null) {
                    $skipped++;
                    continue;
                }

                $repo->create('deals', $dealPayload);
                $imported++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fclose($handle);
            error_log('DDM deal CSV import failed: ' . $e->getMessage());
            header('Location: /agents?import_status=error&import_message=' . urlencode($publicError));
            exit;
        }

        fclose($handle);

        header('Location: /agents?import_status=ok&imported=' . $imported . '&skipped=' . $skipped);
        exit;
    }

    public function importProvidersFromSpreadsheet(array $request, array $config): void
    {
        $authUser = Auth::requireUser($config);
        if (!Auth::canWrite($authUser)) {
            Auth::forbidden();
        }

        $pdo = Database::pdo($config);
        $repo = new EntityRepository($pdo);
        $upload = $request['files']['provider_import_file'] ?? null;
        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            header('Location: /agents?provider_import_status=error&provider_import_message=' . urlencode('Please choose a CSV file to import.'));
            exit;
        }

        $tmpName = (string)($upload['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            header('Location: /agents?provider_import_status=error&provider_import_message=' . urlencode('Uploaded file is unavailable.'));
            exit;
        }

        if ((int)($upload['size'] ?? 0) > self::IMPORT_MAX_BYTES) {
            header('Location: /agents?provider_import_status=error&provider_import_message=' . urlencode('CSV file is too large. Imports are limited to 2 MB.'));
            exit;
        }

        $ext = strtolower((string)pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            header('Location: /agents?provider_import_status=error&provider_import_message=' . urlencode('Only CSV files are supported for import.'));
            exit;
        }

        $handle = fopen($tmpName, 'rb');
        if ($handle === false) {
            header('Location: /agents?provider_import_status=error&provider_import_message=' . urlencode('Unable to read uploaded file.'));
            exit;
        }

        $headerRow = fgetcsv($handle);
        if (!is_array($headerRow) || $headerRow === []) {
            fclose($handle);
            header('Location: /agents?provider_import_status=error&provider_import_message=' . urlencode('CSV header row is missing.'));
            exit;
        }

        $headerMap = $this->buildProviderImportHeaderMap($headerRow);
        if (!isset($headerMap['name'])) {
            fclose($handle);
            header('Location: /agents?provider_import_status=error&provider_import_message=' . urlencode('CSV must include name column.'));
            exit;
        }

        $lookups = $repo->dealLookups();
        $distByName = $this->nameToIdMap((array)($lookups['distributors'] ?? []));

        $imported = 0;
        $skipped = 0;
        $conflicts = [];
        $rowNumber = 1;
        $dataRowCount = 0;
        $publicError = 'Unable to import providers CSV. Please check the file and try again.';

        $existingProviderNames = [];
        foreach ($repo->all('providers') as $existingProvider) {
            $nameKey = $this->normalizeNameKey((string)($existingProvider['name'] ?? ''));
            if ($nameKey !== '') {
                $existingProviderNames[$nameKey] = true;
            }
        }

        try {
            $pdo->beginTransaction();
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $dataRowCount++;
                if ($dataRowCount > self::IMPORT_MAX_ROWS) {
                    $publicError = 'CSV row limit exceeded. Imports are limited to ' . self::IMPORT_MAX_ROWS . ' data rows.';
                    throw new \RuntimeException($publicError);
                }

                if (!is_array($row) || $this->rowIsBlank($row)) {
                    continue;
                }

                $payload = $this->providerPayloadFromImportRow($row, $headerMap, $distByName);
                if ($payload === null) {
                    $skipped++;
                    if (count($conflicts) < 20) {
                        $conflicts[] = 'Row ' . $rowNumber . ': missing required provider name.';
                    }
                    continue;
                }

                $nameKey = $this->normalizeNameKey((string)($payload['name'] ?? ''));
                if ($nameKey === '' || isset($existingProviderNames[$nameKey])) {
                    $skipped++;
                    if (count($conflicts) < 20) {
                        $conflicts[] = 'Row ' . $rowNumber . ': provider "' . (string)($payload['name'] ?? '') . '" already exists.';
                    }
                    continue;
                }

                // Imported providers always start in Reserved status (stored as 0).
                $payload['status'] = '0';

                $repo->create('providers', $payload);
                $existingProviderNames[$nameKey] = true;
                $imported++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fclose($handle);
            error_log('DDM provider CSV import failed: ' . $e->getMessage());
            header('Location: /agents?provider_import_status=error&provider_import_message=' . urlencode($publicError));
            exit;
        }

        fclose($handle);

        $query = [
            'provider_import_status' => 'ok',
            'providers_imported' => (string)$imported,
            'providers_skipped' => (string)$skipped,
        ];
        if ($conflicts !== []) {
            $query['provider_import_conflicts'] = implode('||', $conflicts);
        }

        header('Location: /agents?' . http_build_query($query));
        exit;
    }

    public function downloadEntityCsv(string $table, array $request, array $config): void
    {
        $authUser = Auth::requireUser($config);
        if (!Auth::canViewTable($authUser, $table) || !in_array($table, ['distributors', 'providers', 'deals'], true)) {
            Auth::forbidden();
        }

        $repo = new EntityRepository(Database::pdo($config));
        $searchQuery = trim((string)($request['query']['q'] ?? ''));
        $distributorFilter = isset($request['query']['distributor_id']) ? (int)$request['query']['distributor_id'] : 0;
        $agentFilter = isset($request['query']['agent_id']) ? (int)$request['query']['agent_id'] : 0;

        $rows = $repo->all($table, $searchQuery);
        $rows = $this->applyScopeToRows($repo, $table, $rows, $authUser);

        if ($table === 'providers' && $distributorFilter > 0) {
            $rows = array_values(array_filter(
                $rows,
                static function (array $row) use ($distributorFilter): bool {
                    $rowDistributorId = (int)($row['distributor_id'] ?? ($row['distID'] ?? ($row['distributorID'] ?? 0)));
                    return $rowDistributorId === $distributorFilter;
                }
            ));
        } elseif ($table === 'deals' && $agentFilter > 0) {
            if (Auth::scopeDistributorId($authUser) > 0 && !$this->agentBelongsToDistributor($repo, $agentFilter, Auth::scopeDistributorId($authUser))) {
                Auth::forbidden();
            }

            $rows = array_values(array_filter(
                $rows,
                static function (array $row) use ($agentFilter): bool {
                    $rowAgentId = (int)($row['agent_id'] ?? ($row['agentID'] ?? 0));
                    return $rowAgentId === $agentFilter;
                }
            ));
        }

        $columns = $repo->columns($table);
        $exportColumns = array_values(array_filter(
            $columns,
            static function (string $column): bool {
                return !in_array($column, ['created_at', 'updated_at'], true);
            }
        ));

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $table . '_list_' . date('Ymd_His') . '.csv"');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            http_response_code(500);
            echo 'Failed to open CSV output stream.';
            exit;
        }

        fputcsv($out, $exportColumns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($exportColumns as $column) {
                $line[] = (string)($row[$column] ?? '');
            }
            fputcsv($out, $line);
        }

        fclose($out);
        exit;
    }

    public function downloadReportCsv(array $request, array $config): void
    {
        $authUser = Auth::requireUser($config);
        if (!Auth::canViewReports($authUser)) {
            Auth::forbidden();
        }

        $repo = new EntityRepository(Database::pdo($config));
        $type = strtolower(trim((string)($request['query']['type'] ?? '')));

        if ($type === 'providers') {
            $distributorId = isset($request['query']['provider_distributor_id']) ? (int)$request['query']['provider_distributor_id'] : 0;
            $rows = $repo->providersReportRows($distributorId);

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="provider_report_' . date('Ymd_His') . '.csv"');

            $out = fopen('php://output', 'wb');
            if ($out === false) {
                http_response_code(500);
                echo 'Failed to open CSV output stream.';
                exit;
            }

            fputcsv($out, ['Provider ID', 'Provider Name', 'Distributor ID', 'Distributor', 'Status', 'Start Date', 'End Date', 'Phone', 'Email', 'Segment']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    (int)($row['provider_id'] ?? 0),
                    (string)($row['provider_name'] ?? ''),
                    (int)($row['distributor_id'] ?? 0),
                    (string)($row['distributor_name'] ?? ''),
                    $this->providerStatusLabel($row['status'] ?? ''),
                    (string)($row['start_date'] ?? ''),
                    (string)($row['end_date'] ?? ''),
                    (string)($row['phone'] ?? ''),
                    (string)($row['email'] ?? ''),
                    (string)($row['segment'] ?? ''),
                ]);
            }

            fclose($out);
            exit;
        }

        if ($type === 'deals') {
            $agentId = isset($request['query']['report_agent_id']) ? (int)$request['query']['report_agent_id'] : 0;
            $rows = $repo->dealsReportRowsByAgent($agentId);

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="deals_report_' . date('Ymd_His') . '.csv"');

            $out = fopen('php://output', 'wb');
            if ($out === false) {
                http_response_code(500);
                echo 'Failed to open CSV output stream.';
                exit;
            }

            fputcsv($out, ['Deal ID', 'Deal Name', 'Stage', 'Revenue', 'Deal Date', 'Close Date', 'Distributor', 'Provider', 'Agent']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    (int)($row['deal_id'] ?? 0),
                    (string)($row['deal_name'] ?? ''),
                    (string)($row['stage'] ?? ''),
                    number_format((float)($row['revenue'] ?? 0), 2, '.', ''),
                    (string)($row['deal_date'] ?? ''),
                    (string)($row['close_date'] ?? ''),
                    (string)($row['distributor_name'] ?? ''),
                    (string)($row['provider_name'] ?? ''),
                    (string)($row['agent_name'] ?? ''),
                ]);
            }

            fclose($out);
            exit;
        }

        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid report type.';
        exit;
    }

    private function buildRedirectPath(string $redirectPath, array $request, array $extraQuery = []): string
    {
        $q = trim((string)(($request['body']['q'] ?? '') ?: ($request['query']['q'] ?? '')));
        $distributorIdRaw = (($request['body']['distributor_id'] ?? '') ?: ($request['query']['distributor_id'] ?? ''));
        $distributorId = (int)$distributorIdRaw;
        $agentIdRaw = (($request['body']['agent_id'] ?? '') ?: ($request['query']['agent_id'] ?? ''));
        $agentId = (int)$agentIdRaw;
        $query = [];
        if ($q !== '') {
            $query['q'] = $q;
        }
        if ($distributorId > 0) {
            $query['distributor_id'] = (string)$distributorId;
        }
        if ($agentId > 0) {
            $query['agent_id'] = (string)$agentId;
        }
        foreach ($extraQuery as $key => $value) {
            $query[$key] = (string)$value;
        }

        if ($query === []) {
            return $redirectPath;
        }

        return $redirectPath . '?' . http_build_query($query);
    }

    private function applyProviderEndDateRule(EntityRepository $repo, array &$payload, ?array $existing = null): void
    {
        $statusRaw = array_key_exists('status', $payload)
            ? (string)$payload['status']
            : (string)($existing['status'] ?? '');
        $status = strtolower(trim($statusRaw));
        $isProtected = in_array($status, ['1', 'protected'], true);

        $distributorIdRaw = $payload['distributor_id']
            ?? $payload['distID']
            ?? ($existing['distributor_id'] ?? null)
            ?? ($existing['distID'] ?? null);
        $distributorId = (int)$distributorIdRaw;
        $termYears = $repo->distributorContractTermYears($distributorId);

        $pairs = [
            ['start_date', 'end_date'],
            ['sdate', 'enddate'],
        ];

        foreach ($pairs as [$startKey, $endKey]) {
            if (!$isProtected) {
                if (array_key_exists($endKey, $payload)) {
                    $payload[$endKey] = '';
                }
                continue;
            }

            $newStart = trim((string)($payload[$startKey] ?? ''));
            if ($newStart === '') {
                if (array_key_exists($endKey, $payload)) {
                    $payload[$endKey] = '';
                }
                continue;
            }

            $ts = strtotime($newStart);
            if ($ts === false) {
                continue;
            }

            $payload[$endKey] = date('Y-m-d', strtotime('+' . $termYears . ' years', $ts));
        }
    }

    private function applyDealDefaults(array &$payload): void
    {
        $defaults = [
            'revenue' => '0',
        ];

        foreach ($defaults as $key => $defaultValue) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            if (trim((string)$payload[$key]) === '') {
                $payload[$key] = $defaultValue;
            }
        }
    }

    private function applyDistributorContractDateRule(EntityRepository $repo, array &$payload, ?array $existing = null): void
    {
        $termRaw = $payload['contract_term_years'] ?? ($existing['contract_term_years'] ?? 1);
        $termYears = (int)$termRaw;
        if (!in_array($termYears, [1, 2, 3], true)) {
            $termYears = 1;
        }

        $startDate = trim((string)($payload['contract_start_date'] ?? ($existing['contract_start_date'] ?? '')));
        if ($startDate === '') {
            $startDate = date('Y-m-d');
            $payload['contract_start_date'] = $startDate;
        }

        $endDate = trim((string)($payload['contract_end_date'] ?? ($existing['contract_end_date'] ?? '')));
        if ($endDate === '') {
            $startTs = strtotime($startDate);
            if ($startTs !== false) {
                $payload['contract_end_date'] = date('Y-m-d', strtotime('+' . $termYears . ' years', $startTs));
            }
        }
    }

    private function applyDealCloseDateRule(array &$payload, ?array $existing = null): void
    {
        if (!array_key_exists('stage', $payload)) {
            return;
        }

        $newStage = strtolower(trim((string)$payload['stage']));
        if ($newStage !== 'closed') {
            return;
        }

        $existingStage = strtolower(trim((string)($existing['stage'] ?? '')));
        $changedToClosed = $existing === null || $existingStage !== 'closed';
        if (!$changedToClosed) {
            return;
        }

        $payload['close_date'] = date('Y-m-d');
    }

    private function syncProviderEndDateFromDealIfConfirmed(
        EntityRepository $repo,
        array $payload,
        ?array $existingDeal
    ): void {
        $syncFlag = trim((string)($payload['sync_provider_end_date'] ?? '0'));
        if ($syncFlag !== '1') {
            return;
        }

        $stage = strtolower(trim((string)($payload['stage'] ?? ($existingDeal['stage'] ?? ''))));
        if ($stage !== 'closed') {
            return;
        }

        $providerIdRaw = $payload['provider_id']
            ?? $payload['provID']
            ?? ($existingDeal['provider_id'] ?? null)
            ?? ($existingDeal['provID'] ?? null);
        $providerId = (int)$providerIdRaw;
        if ($providerId <= 0) {
            return;
        }

        // Always derive provider end date from the deal close_date being submitted.
        // Never extend from existing provider end_date or inferred historical values.
        $closeDate = trim((string)($payload['close_date'] ?? ''));
        if ($closeDate === '') {
            return;
        }

        $closeTs = strtotime($closeDate);
        if ($closeTs === false) {
            return;
        }

        $termYears = $repo->distributorContractTermYearsForProvider($providerId);
        $providerEndDate = date('Y-m-d', strtotime('+' . $termYears . ' years', $closeTs));
        $repo->updateProviderEndDate($providerId, $providerEndDate);
    }

    private function maybeRecalcProviderDatesForDistributorTermChange(
        EntityRepository $repo,
        int $distributorId,
        array $payload,
        ?array $existingDistributor
    ): void {
        if ($distributorId <= 0 || !is_array($existingDistributor)) {
            return;
        }

        $oldTerm = (int)($existingDistributor['contract_term_years'] ?? 1);
        if (!in_array($oldTerm, [1, 2, 3], true)) {
            $oldTerm = 1;
        }

        $newTermRaw = $payload['contract_term_years'] ?? ($existingDistributor['contract_term_years'] ?? 1);
        $newTerm = (int)$newTermRaw;
        if (!in_array($newTerm, [1, 2, 3], true)) {
            $newTerm = $oldTerm;
        }

        $termChanged = $newTerm !== $oldTerm;
        if (!$termChanged) {
            return;
        }

        $syncFlag = trim((string)($payload['sync_distributor_provider_dates'] ?? '0'));
        if ($syncFlag !== '1') {
            return;
        }

        $repo->recalcProtectedProviderEndDatesForDistributor($distributorId, $newTerm);
    }

    private function ensureProviderProtectedOnClosedDeal(
        EntityRepository $repo,
        array $payload,
        ?array $existingDeal
    ): void {
        $stage = strtolower(trim((string)($payload['stage'] ?? ($existingDeal['stage'] ?? ''))));
        if ($stage !== 'closed') {
            return;
        }

        $providerIdRaw = $payload['provider_id']
            ?? $payload['provID']
            ?? ($existingDeal['provider_id'] ?? null)
            ?? ($existingDeal['provID'] ?? null);
        $providerId = (int)$providerIdRaw;
        if ($providerId <= 0) {
            return;
        }

        $provider = $repo->find('providers', $providerId);
        $providerStatus = strtolower(trim((string)($provider['status'] ?? '')));
        $isReserved = in_array($providerStatus, ['0', 'reserved'], true);
        if (!$isReserved) {
            return;
        }

        $repo->updateProviderStatus($providerId, 1);
    }

    private function reserveProviderIfNoClosedDealsRemainAfterDealDelete(
        EntityRepository $repo,
        array $deletedDeal
    ): bool {
        $providerId = $this->resolveDealProviderId([], $deletedDeal);
        if ($providerId <= 0) {
            return false;
        }

        if ($this->isDiabloDataProvider($repo, $providerId)) {
            $repo->updateProviderStatus($providerId, 1);
            return false;
        }

        if ($repo->countClosedDealsForProvider($providerId) === 0) {
            $repo->reserveProviderAndAlignEndDateToStartDate($providerId);
            return true;
        }

        return false;
    }

    private function reserveProviderIfNoClosedDealsRemainAfterDealChange(
        EntityRepository $repo,
        array $payload,
        ?array $existingDeal,
        bool $afterUpdate
    ): bool {
        if (!$afterUpdate) {
            return false;
        }

        $oldStage = strtolower(trim((string)($existingDeal['stage'] ?? '')));
        $newStage = strtolower(trim((string)($payload['stage'] ?? ($existingDeal['stage'] ?? ''))));
        $leftClosedStage = $oldStage === 'closed' && $newStage !== 'closed';
        if (!$leftClosedStage) {
            return false;
        }

        $providerId = $this->resolveDealProviderId($payload, $existingDeal);
        if ($providerId <= 0) {
            return false;
        }

        if ($this->isDiabloDataProvider($repo, $providerId)) {
            $repo->updateProviderStatus($providerId, 1);
            return false;
        }

        if ($repo->countClosedDealsForProvider($providerId) === 0) {
            $repo->reserveProviderAndAlignEndDateToStartDate($providerId);
            return true;
        }

        return false;
    }

    private function resolveDealProviderId(array $payload, ?array $deal): int
    {
        $providerIdRaw = $payload['provider_id']
            ?? $payload['provID']
            ?? ($deal['provider_id'] ?? null)
            ?? ($deal['provID'] ?? null);

        return (int)$providerIdRaw;
    }

    private function enforceProtectedStatusForDiabloDataProviderPayload(
        EntityRepository $repo,
        array &$payload,
        ?array $existingProvider
    ): void {
        $distributorIdRaw = $payload['distributor_id']
            ?? $payload['distID']
            ?? ($existingProvider['distributor_id'] ?? null)
            ?? ($existingProvider['distID'] ?? null);
        $distributorId = (int)$distributorIdRaw;
        if ($distributorId <= 0) {
            return;
        }

        $distributor = $repo->find('distributors', $distributorId);
        $distributorName = strtolower(trim((string)($distributor['name'] ?? '')));
        if ($distributorName !== 'diablo data') {
            return;
        }

        $payload['status'] = 'protected';
    }

    private function enforceDiabloDataProviderAlwaysProtected(EntityRepository $repo, int $providerId): void
    {
        if ($providerId <= 0) {
            return;
        }

        if ($this->isDiabloDataProvider($repo, $providerId)) {
            $repo->updateProviderStatus($providerId, 1);
        }
    }

    private function isDiabloDataProvider(EntityRepository $repo, int $providerId): bool
    {
        $provider = $repo->find('providers', $providerId);
        if (!is_array($provider)) {
            return false;
        }

        $distributorIdRaw = $provider['distributor_id'] ?? ($provider['distID'] ?? null);
        $distributorId = (int)$distributorIdRaw;
        if ($distributorId <= 0) {
            return false;
        }

        $distributor = $repo->find('distributors', $distributorId);
        $distributorName = strtolower(trim((string)($distributor['name'] ?? '')));

        return $distributorName === 'diablo data';
    }

    private function applyScopeToRows(EntityRepository $repo, string $table, array $rows, array $authUser): array
    {
        $scopeDistributorId = Auth::scopeDistributorId($authUser);
        if ($scopeDistributorId <= 0) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            function (array $row) use ($repo, $table, $scopeDistributorId): bool {
                $rowDistributorId = $this->rowDistributorId($repo, $table, $row);
                if ($rowDistributorId <= 0 || $rowDistributorId !== $scopeDistributorId) {
                    return false;
                }

                return !$repo->distributorIsInternalOnly($rowDistributorId);
            }
        ));
    }

    private function applyScopeToLookups(EntityRepository $repo, array $lookups, array $authUser): array
    {
        $scopeDistributorId = Auth::scopeDistributorId($authUser);
        if ($scopeDistributorId <= 0) {
            return $lookups;
        }

        foreach (['distributors', 'providers', 'agents'] as $key) {
            $items = (array)($lookups[$key] ?? []);
            $lookups[$key] = array_values(array_filter(
                $items,
                function (array $item) use ($repo, $key, $scopeDistributorId): bool {
                    $itemId = (int)($item['id'] ?? 0);
                    if ($itemId <= 0) {
                        return false;
                    }

                    $table = $key === 'agents' ? 'agent' : $key;
                    $row = $repo->find($table, $itemId);
                    if (!is_array($row)) {
                        return false;
                    }

                    $rowDistributorId = $this->rowDistributorId($repo, $table, $row);
                    return $rowDistributorId > 0 && $rowDistributorId === $scopeDistributorId;
                }
            ));
        }

        return $lookups;
    }

    private function rowVisibleToUser(EntityRepository $repo, string $table, array $row, array $authUser): bool
    {
        $scopeDistributorId = Auth::scopeDistributorId($authUser);
        if ($scopeDistributorId <= 0) {
            return true;
        }

        $rowDistributorId = $this->rowDistributorId($repo, $table, $row);
        if ($rowDistributorId <= 0 || $rowDistributorId !== $scopeDistributorId) {
            return false;
        }

        return !$repo->distributorIsInternalOnly($rowDistributorId);
    }

    private function rowDistributorId(EntityRepository $repo, string $table, array $row): int
    {
        if ($table === 'distributors') {
            return (int)($row['id'] ?? 0);
        }

        if ($table === 'providers') {
            return (int)($row['distributor_id'] ?? ($row['distID'] ?? ($row['distributorID'] ?? 0)));
        }

        if ($table === 'agent') {
            return (int)($row['distributor_id'] ?? 0);
        }

        if ($table === 'deals') {
            $dealDistributorId = (int)($row['distributor_id'] ?? ($row['distID'] ?? 0));
            if ($dealDistributorId > 0) {
                return $dealDistributorId;
            }

            $providerId = (int)($row['provider_id'] ?? ($row['provID'] ?? 0));
            if ($providerId > 0) {
                $provider = $repo->find('providers', $providerId);
                if (is_array($provider)) {
                    return (int)($provider['distributor_id'] ?? ($provider['distID'] ?? ($provider['distributorID'] ?? 0)));
                }
            }

            $agentId = (int)($row['agent_id'] ?? ($row['agentID'] ?? 0));
            if ($agentId > 0) {
                $agent = $repo->find('agent', $agentId);
                if (is_array($agent)) {
                    return (int)($agent['distributor_id'] ?? 0);
                }
            }
        }

        return 0;
    }

    private function agentBelongsToDistributor(EntityRepository $repo, int $agentId, int $distributorId): bool
    {
        if ($agentId <= 0 || $distributorId <= 0) {
            return false;
        }

        $agent = $repo->find('agent', $agentId);
        if (!is_array($agent)) {
            return false;
        }

        return (int)($agent['distributor_id'] ?? 0) === $distributorId;
    }

    private function providerStatusLabel(mixed $rawValue): string
    {
        $value = strtolower(trim((string)$rawValue));
        return match ($value) {
            '0', 'reserved' => 'Reserved',
            '1', 'protected' => 'Protected',
            '2', 'open' => 'Open',
            default => (string)$rawValue,
        };
    }

    private function buildImportHeaderMap(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $index => $header) {
            $normalized = strtolower(trim((string)$header));
            if ($normalized === '') {
                continue;
            }

            $key = match ($normalized) {
                'deal name', 'deal_name', 'deal' => 'deal_name',
                'stage' => 'stage',
                'revenue', 'amount' => 'revenue',
                'deal date', 'deal_date', 'date' => 'deal_date',
                'close date', 'close_date' => 'close_date',
                'distributor', 'distributor_name' => 'distributor_name',
                'provider', 'provider_name' => 'provider_name',
                'agent', 'agent_name' => 'agent_name',
                default => '',
            };

            if ($key !== '' && !isset($map[$key])) {
                $map[$key] = (int)$index;
            }
        }

        return $map;
    }

    private function buildProviderImportHeaderMap(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $index => $header) {
            $normalized = strtolower(trim((string)$header));
            if ($normalized === '') {
                continue;
            }

            $key = match ($normalized) {
                'name', 'provider', 'provider_name' => 'name',
                'address' => 'address',
                'city' => 'city',
                'state' => 'state',
                'country', 'country_code' => 'country_code',
                'postal_code', 'postalcode', 'zip' => 'postal_code',
                'phone' => 'phone',
                'phone_alt', 'alternate_phone', 'phone_alt_number' => 'phone_alt',
                'email' => 'email',
                'segment' => 'segment',
                'distributor', 'distributor_name' => 'distributor_name',
                'point_of_contact_name' => 'point_of_contact_name',
                'point_of_contact_phone' => 'point_of_contact_phone',
                'point_of_contact_email' => 'point_of_contact_email',
                'customer_name' => 'customer_name',
                'status' => 'status',
                'start_date', 'sdate' => 'start_date',
                'end_date', 'enddate' => 'end_date',
                default => '',
            };

            if ($key !== '' && !isset($map[$key])) {
                $map[$key] = (int)$index;
            }
        }

        return $map;
    }

    private function nameToIdMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $name = strtolower(trim((string)($row['name'] ?? '')));
            if ($id <= 0 || $name === '') {
                continue;
            }
            if (!isset($map[$name])) {
                $map[$name] = $id;
            }
        }

        return $map;
    }

    private function rowIsBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string)$cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function dealPayloadFromImportRow(
        array $row,
        array $headerMap,
        array $distByName,
        array $providerByName,
        array $agentByName
    ): ?array {
        $read = static function (string $key) use ($row, $headerMap): string {
            if (!isset($headerMap[$key])) {
                return '';
            }

            $value = $row[$headerMap[$key]] ?? '';
            return trim((string)$value);
        };

        $dealName = $read('deal_name');
        if ($dealName === '') {
            return null;
        }

        $payload = [
            'deal_name' => $dealName,
            // Imported deals always begin in Pending status.
            'stage' => 'pending',
        ];

        $revenue = $read('revenue');
        if ($revenue !== '') {
            $payload['revenue'] = preg_replace('/[^0-9.\-]/', '', $revenue) ?? '';
        }

        $dealDate = $read('deal_date');
        if ($this->isIsoDate($dealDate)) {
            $payload['deal_date'] = $dealDate;
        }

        $closeDate = $read('close_date');
        if ($this->isIsoDate($closeDate)) {
            $payload['close_date'] = $closeDate;
        }

        $distName = strtolower($read('distributor_name'));
        if ($distName !== '' && isset($distByName[$distName])) {
            $payload['distributor_id'] = $distByName[$distName];
        }

        $providerName = strtolower($read('provider_name'));
        if ($providerName !== '' && isset($providerByName[$providerName])) {
            $payload['provider_id'] = $providerByName[$providerName];
        }

        $agentName = strtolower($read('agent_name'));
        if ($agentName !== '' && isset($agentByName[$agentName])) {
            $payload['agent_id'] = $agentByName[$agentName];
        }

        return $payload;
    }

    private function providerPayloadFromImportRow(array $row, array $headerMap, array $distByName): ?array
    {
        $read = static function (string $key) use ($row, $headerMap): string {
            if (!isset($headerMap[$key])) {
                return '';
            }

            return trim((string)($row[$headerMap[$key]] ?? ''));
        };

        $name = $read('name');
        if ($name === '') {
            return null;
        }

        $payload = [
            'name' => $name,
        ];

        foreach (['address', 'city', 'state', 'postal_code', 'phone', 'phone_alt', 'email', 'segment', 'point_of_contact_name', 'point_of_contact_phone', 'point_of_contact_email', 'customer_name'] as $key) {
            $value = $read($key);
            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        $country = strtoupper($read('country_code'));
        if ($country !== '') {
            $payload['country_code'] = $country;
        }

        $distName = strtolower($read('distributor_name'));
        if ($distName !== '' && isset($distByName[$distName])) {
            $payload['distributor_id'] = $distByName[$distName];
        }

        $startDate = $read('start_date');
        if ($startDate !== '' && $this->isIsoDate($startDate)) {
            $payload['start_date'] = $startDate;
        }

        $endDate = $read('end_date');
        if ($endDate !== '' && $this->isIsoDate($endDate)) {
            $payload['end_date'] = $endDate;
        }

        return $payload;
    }

    private function isIsoDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $parts = explode('-', $value);
        if (count($parts) !== 3) {
            return false;
        }

        return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
    }

    private function normalizeNameKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }
}
