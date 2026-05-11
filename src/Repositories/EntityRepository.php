<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class EntityRepository
{
    private PDO $pdo;
    private array $tableColumnsCache = [];
    private array $tableColumnMetaCache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function all(string $table, ?string $search = null): array
    {
        $safeTable = $this->safeTable($table);
        $search = trim((string)$search);
        $orderBy = $this->orderByForTable($table);

        if ($search === '') {
            $stmt = $this->pdo->query('SELECT * FROM ' . $safeTable . ' ORDER BY ' . $orderBy);
            return $stmt->fetchAll();
        }

        $columnMeta = $this->tableColumnMeta($table);
        $whereParts = [];
        $params = [];
        $i = 0;

        foreach ($columnMeta as $column => $meta) {
            if (in_array($column, ['created_at', 'updated_at'], true)) {
                continue;
            }

            $param = ':q' . $i;
            $whereParts[] = 'CAST(' . $column . ' AS CHAR) LIKE ' . $param;
            $params[$param] = '%' . $search . '%';
            $i++;
        }

        if ($whereParts === []) {
            $stmt = $this->pdo->query('SELECT * FROM ' . $safeTable . ' ORDER BY ' . $orderBy);
            return $stmt->fetchAll();
        }

        $sql = 'SELECT * FROM ' . $safeTable . ' WHERE (' . implode(' OR ', $whereParts) . ') ORDER BY ' . $orderBy;
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function columns(string $table): array
    {
        $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . $this->safeTable($table));
        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!isset($row['Field'])) {
                continue;
            }
            $columns[] = (string)$row['Field'];
        }

        return $columns;
    }

    public function find(string $table, int $id): ?array
    {
        $sql = 'SELECT * FROM ' . $this->safeTable($table) . ' WHERE id = :id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function create(string $table, array $data): void
    {
        $data = $this->sanitizeWriteData($table, $data);
        if ($data === []) {
            return;
        }

        $columns = array_keys($data);
        $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->safeTable($table),
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
    }

    public function update(string $table, int $id, array $data): void
    {
        $data = $this->sanitizeWriteData($table, $data);
        if ($data === []) {
            return;
        }

        $assignments = [];
        foreach (array_keys($data) as $column) {
            $assignments[] = $column . ' = :' . $column;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE id = :id',
            $this->safeTable($table),
            implode(', ', $assignments)
        );

        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function delete(string $table, int $id): void
    {
        $sql = 'DELETE FROM ' . $this->safeTable($table) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function updateProviderEndDate(int $providerId, string $endDate): void
    {
        if ($providerId <= 0) {
            return;
        }

        $sql = 'UPDATE providers SET end_date = :end_date WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':end_date', $endDate);
        $stmt->bindValue(':id', $providerId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function updateProviderStatus(int $providerId, int $status): void
    {
        if ($providerId <= 0) {
            return;
        }

        $sql = 'UPDATE providers SET status = :status WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':status', $status, PDO::PARAM_INT);
        $stmt->bindValue(':id', $providerId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function countClosedDealsForProvider(int $providerId): int
    {
        if ($providerId <= 0) {
            return 0;
        }

        $providerCol = $this->resolveColumn('deals', ['provider_id', 'provID']);
        if ($providerCol === null) {
            return 0;
        }

        $stageCol = $this->resolveColumn('deals', ['stage']);
        if ($stageCol === null) {
            return 0;
        }

        $sql = 'SELECT COUNT(*) AS total FROM deals WHERE ' . $providerCol . ' = :provider_id AND LOWER(TRIM(' . $stageCol . ')) = :closed_stage';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':provider_id', $providerId, PDO::PARAM_INT);
        $stmt->bindValue(':closed_stage', 'closed', PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();

        return (int)($row['total'] ?? 0);
    }

    public function reserveProviderAndAlignEndDateToStartDate(int $providerId): void
    {
        if ($providerId <= 0) {
            return;
        }

        $sql = 'UPDATE providers SET status = 0, end_date = start_date WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $providerId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function distributorContractTermYears(int $distributorId): int
    {
        if ($distributorId <= 0) {
            return 1;
        }

        $termCol = $this->resolveColumn('distributors', ['contract_term_years']);
        if ($termCol === null) {
            return 1;
        }

        $sql = 'SELECT ' . $termCol . ' AS term_years FROM distributors WHERE id = :id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $distributorId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        $termYears = (int)($row['term_years'] ?? 1);

        return in_array($termYears, [1, 2, 3], true) ? $termYears : 1;
    }

    public function distributorContractTermYearsForProvider(int $providerId): int
    {
        if ($providerId <= 0) {
            return 1;
        }

        $distributorCol = $this->resolveColumn('providers', ['distributor_id', 'distID', 'distributorID']);
        if ($distributorCol === null) {
            return 1;
        }

        $sql = 'SELECT ' . $distributorCol . ' AS distributor_id FROM providers WHERE id = :id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $providerId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        $distributorId = (int)($row['distributor_id'] ?? 0);

        return $this->distributorContractTermYears($distributorId);
    }

    public function recalcProtectedProviderEndDatesForDistributor(int $distributorId, int $termYears): void
    {
        if ($distributorId <= 0) {
            return;
        }

        if (!in_array($termYears, [1, 2, 3], true)) {
            $termYears = 1;
        }

        $sql = 'UPDATE providers
                SET end_date = DATE_ADD(start_date, INTERVAL :term_years YEAR)
                WHERE distributor_id = :distributor_id
                  AND status = 1
                  AND start_date IS NOT NULL';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':term_years', $termYears, PDO::PARAM_INT);
        $stmt->bindValue(':distributor_id', $distributorId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function distributorContractsEndingWithinDays(int $days): array
    {
        if ($days < 0) {
            $days = 0;
        }

                $endCol = $this->resolveColumn('distributors', ['contract_end_date']);
                $startCol = $this->resolveColumn('distributors', ['contract_start_date']);
                $termCol = $this->resolveColumn('distributors', ['contract_term_years']);
                if ($endCol === null || $startCol === null || $termCol === null) {
                        return [];
                }

                $sql = 'SELECT id, name, ' . $termCol . ' AS contract_term_years, ' . $startCol . ' AS contract_start_date, ' . $endCol . ' AS contract_end_date,
                                             DATEDIFF(' . $endCol . ', CURRENT_DATE()) AS days_remaining
                FROM distributors
                                WHERE ' . $endCol . ' IS NOT NULL
                                    AND DATEDIFF(' . $endCol . ', CURRENT_DATE()) BETWEEN 0 AND :days
                                ORDER BY ' . $endCol . ' ASC, name ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function extendDistributorContractEndDateByTerm(int $distributorId): void
    {
        if ($distributorId <= 0) {
            return;
        }

        $endCol = $this->resolveColumn('distributors', ['contract_end_date']);
        $startCol = $this->resolveColumn('distributors', ['contract_start_date']);
        $termCol = $this->resolveColumn('distributors', ['contract_term_years']);
        if ($endCol === null || $startCol === null || $termCol === null) {
            return;
        }

        $sql = 'UPDATE distributors
                SET ' . $endCol . ' = DATE_ADD(
                        COALESCE(' . $endCol . ', COALESCE(' . $startCol . ', CURRENT_DATE())),
                        INTERVAL ' . $termCol . ' YEAR
                    )
                WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $distributorId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function dealLookups(): array
    {
        return [
            'distributors' => $this->idNamePairs('distributors'),
            'providers' => $this->idNamePairs('providers'),
            'agents' => $this->agentPairs(),
        ];
    }

    public function distributorLookups(): array
    {
        return $this->idNamePairs('distributors');
    }

    public function providersReportRows(int $distributorId = 0): array
    {
        $distCol = $this->resolveColumn('providers', ['distributor_id', 'distID', 'distributorID']);
        $statusCol = $this->resolveColumn('providers', ['status']);
        $startCol = $this->resolveColumn('providers', ['start_date', 'sdate']);
        $endCol = $this->resolveColumn('providers', ['end_date', 'enddate']);
        $phoneCol = $this->resolveColumn('providers', ['phone']);
        $emailCol = $this->resolveColumn('providers', ['email']);
        $segmentCol = $this->resolveColumn('providers', ['segment']);

        $sql = 'SELECT p.id AS provider_id,
                       p.name AS provider_name,
                       ' . ($distCol !== null ? 'p.' . $distCol : 'NULL') . ' AS distributor_id,
                       COALESCE(d.name, "(Unassigned)") AS distributor_name,
                       ' . ($statusCol !== null ? 'p.' . $statusCol : 'NULL') . ' AS status,
                       ' . ($startCol !== null ? 'p.' . $startCol : 'NULL') . ' AS start_date,
                       ' . ($endCol !== null ? 'p.' . $endCol : 'NULL') . ' AS end_date,
                       ' . ($phoneCol !== null ? 'p.' . $phoneCol : 'NULL') . ' AS phone,
                       ' . ($emailCol !== null ? 'p.' . $emailCol : 'NULL') . ' AS email,
                       ' . ($segmentCol !== null ? 'p.' . $segmentCol : 'NULL') . ' AS segment
                FROM providers p
                LEFT JOIN distributors d ON d.id = ' . ($distCol !== null ? 'p.' . $distCol : 'NULL');

        if ($distributorId > 0 && $distCol !== null) {
            $sql .= ' WHERE p.' . $distCol . ' = :distributor_id';
        }

        $sql .= ' ORDER BY distributor_name ASC, provider_name ASC, provider_id ASC';

        $stmt = $this->pdo->prepare($sql);
        if ($distributorId > 0 && $distCol !== null) {
            $stmt->bindValue(':distributor_id', $distributorId, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function dealsReportRowsByAgent(int $agentId = 0): array
    {
        $dealNameCol = $this->resolveColumn('deals', ['deal_name', 'dealname', 'title', 'name']) ?? 'id';
        $stageCol = $this->resolveColumn('deals', ['stage']);
        $revenueCol = $this->resolveColumn('deals', ['revenue', 'amount']);
        $dealDateCol = $this->resolveColumn('deals', ['deal_date', 'date']);
        $closeDateCol = $this->resolveColumn('deals', ['close_date']);
        $distCol = $this->resolveColumn('deals', ['distributor_id', 'distID']);
        $providerCol = $this->resolveColumn('deals', ['provider_id', 'provID']);
        $agentCol = $this->resolveColumn('deals', ['agent_id', 'agentID']);

        $agentFirstCol = $this->resolveColumn('agent', ['first_name', 'first']) ?? 'id';
        $agentLastCol = $this->resolveColumn('agent', ['last_name', 'last']) ?? 'id';

        $sql = 'SELECT d.id AS deal_id,
                       CAST(d.' . $dealNameCol . ' AS CHAR) AS deal_name,
                       ' . ($stageCol !== null ? 'd.' . $stageCol : '""') . ' AS stage,
                       ' . ($revenueCol !== null ? 'd.' . $revenueCol : '0') . ' AS revenue,
                       ' . ($dealDateCol !== null ? 'd.' . $dealDateCol : 'NULL') . ' AS deal_date,
                       ' . ($closeDateCol !== null ? 'd.' . $closeDateCol : 'NULL') . ' AS close_date,
                       COALESCE(dist.name, "(Unassigned)") AS distributor_name,
                       COALESCE(p.name, "(Unassigned)") AS provider_name,
                       TRIM(CONCAT(COALESCE(a.' . $agentFirstCol . ', ""), " ", COALESCE(a.' . $agentLastCol . ', ""))) AS agent_name,
                       ' . ($agentCol !== null ? 'd.' . $agentCol : 'NULL') . ' AS agent_id
                FROM deals d
                LEFT JOIN distributors dist ON dist.id = ' . ($distCol !== null ? 'd.' . $distCol : 'NULL') . '
                LEFT JOIN providers p ON p.id = ' . ($providerCol !== null ? 'd.' . $providerCol : 'NULL') . '
                LEFT JOIN agent a ON a.id = ' . ($agentCol !== null ? 'd.' . $agentCol : 'NULL');

        if ($agentId > 0 && $agentCol !== null) {
            $sql .= ' WHERE d.' . $agentCol . ' = :agent_id';
        }

        $sql .= ' ORDER BY d.id DESC';

        $stmt = $this->pdo->prepare($sql);
        if ($agentId > 0 && $agentCol !== null) {
            $stmt->bindValue(':agent_id', $agentId, PDO::PARAM_INT);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $agentName = trim((string)($row['agent_name'] ?? ''));
            if ($agentName === '') {
                $row['agent_name'] = '(Unassigned)';
            }
        }
        unset($row);

        return $rows;
    }

    public function distributorIsInternalOnly(int $distributorId): bool
    {
        if ($distributorId <= 0) {
            return false;
        }

        $internalOnlyCol = $this->resolveColumn('distributors', ['internal_only']);
        if ($internalOnlyCol !== null) {
            $sql = 'SELECT ' . $internalOnlyCol . ' AS internal_only FROM distributors WHERE id = :id LIMIT 1';
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $distributorId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
            return ((int)($row['internal_only'] ?? 0)) === 1;
        }

        $sql = 'SELECT name FROM distributors WHERE id = :id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $distributorId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return strtolower(trim((string)($row['name'] ?? ''))) === 'diablo data';
    }

    public function dashboardCounts(int $distributorScopeId = 0): array
    {
        $scope = max(0, $distributorScopeId);
        return [
            'deals' => $this->countByTable('deals', $scope),
            'distributors' => $this->countByTable('distributors', $scope),
            'providers' => $this->countByTable('providers', $scope),
            'agents' => $this->countByTable('agent', $scope),
        ];
    }

    public function dealPipelineByStage(int $distributorScopeId = 0): array
    {
        $revenueCol = $this->resolveColumn('deals', ['revenue', 'amount']);
        $stageCol = $this->resolveColumn('deals', ['stage']);
        $distCol = $this->resolveColumn('deals', ['distributor_id', 'distID']);

        $stageExpr = $stageCol !== null ? 'COALESCE(d.' . $stageCol . ', "unknown")' : '"unknown"';
        $revenueExpr = $revenueCol !== null ? 'd.' . $revenueCol : '0';

        $sql = 'SELECT ' . $stageExpr . ' AS stage, COUNT(*) AS deal_count, COALESCE(SUM(' . $revenueExpr . '), 0) AS total_amount FROM deals d';
        if ($distributorScopeId > 0 && $distCol !== null) {
            $sql .= ' WHERE d.' . $distCol . ' = :distributor_id';
        }

        $sql .= ' GROUP BY ' . $stageExpr . ' ORDER BY total_amount DESC';
        $stmt = $this->pdo->prepare($sql);
        if ($distributorScopeId > 0 && $distCol !== null) {
            $stmt->bindValue(':distributor_id', $distributorScopeId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function recentDeals(int $limit = 8, int $distributorScopeId = 0): array
    {
        $titleCol = $this->resolveColumn('deals', ['deal_name', 'dealname', 'title']) ?? 'id';
        $stageCol = $this->resolveColumn('deals', ['stage']);
        $amountCol = $this->resolveColumn('deals', ['revenue', 'amount']) ?? 'id';
        $dateCol = $this->resolveColumn('deals', ['deal_date', 'date', 'close_date']);
        $distCol = $this->resolveColumn('deals', ['distributor_id', 'distID']);
        $provCol = $this->resolveColumn('deals', ['provider_id', 'provID']);
        $agentCol = $this->resolveColumn('deals', ['agent_id', 'agentID']);

        $agentFirst = $this->resolveColumn('agent', ['first_name', 'first']) ?? 'id';
        $agentLast = $this->resolveColumn('agent', ['last_name', 'last']) ?? 'id';

        $stageExpr = $stageCol !== null ? 'COALESCE(d.' . $stageCol . ', "")' : '""';
        $dateExpr = $dateCol !== null ? 'd.' . $dateCol : 'NULL';

        $sql = 'SELECT d.id, d.' . $titleCol . ' AS title, ' . $stageExpr . ' AS stage, d.' . $amountCol . ' AS amount, ' . $dateExpr . ' AS close_date, dist.name AS distributor_name, p.name AS provider_name, CONCAT(COALESCE(a.' . $agentFirst . ', ""), " ", COALESCE(a.' . $agentLast . ', "")) AS agent_name
                FROM deals d
                LEFT JOIN distributors dist ON dist.id = ' . ($distCol !== null ? 'd.' . $distCol : 'NULL') . '
                LEFT JOIN providers p ON p.id = ' . ($provCol !== null ? 'd.' . $provCol : 'NULL') . '
                LEFT JOIN agent a ON a.id = ' . ($agentCol !== null ? 'd.' . $agentCol : 'NULL');
        if ($distributorScopeId > 0 && $distCol !== null) {
            $sql .= ' WHERE d.' . $distCol . ' = :distributor_id';
        }

        $sql .= ' ORDER BY d.id DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        if ($distributorScopeId > 0 && $distCol !== null) {
            $stmt->bindValue(':distributor_id', $distributorScopeId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function sanitizeWriteData(string $table, array $data): array
    {
        unset($data['submit'], $data['_action'], $data['id'], $data['created_at'], $data['updated_at']);

        $columnMeta = $this->tableColumnMeta($table);
        $clean = [];
        foreach ($data as $key => $value) {
            if (!isset($columnMeta[$key])) {
                continue;
            }

            if ($value === '') {
                $type = strtolower((string)($columnMeta[$key]['Type'] ?? ''));
                $isNullable = strtoupper((string)($columnMeta[$key]['Null'] ?? 'YES')) === 'YES';
                if (!$isNullable) {
                    if (str_contains($type, 'int')) {
                        $clean[$key] = 0;
                        continue;
                    }

                    if (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
                        $clean[$key] = '0';
                        continue;
                    }
                }
                $clean[$key] = null;
                continue;
            }

            $type = strtolower((string)($columnMeta[$key]['Type'] ?? ''));
            $normalized = is_string($value) ? trim($value) : $value;

            if (str_contains($type, 'int')) {
                if (is_string($normalized)) {
                    $mapped = [
                        'active' => 1,
                        'inactive' => 0,
                        'pending' => 2,
                        'enabled' => 1,
                        'disabled' => 0,
                        'yes' => 1,
                        'no' => 0,
                        'true' => 1,
                        'false' => 0,
                    ];
                    if ($table === 'providers' && $key === 'status') {
                        $mapped['reserved'] = 0;
                        $mapped['protected'] = 1;
                        $mapped['open'] = 2;
                    }
                    $lower = strtolower($normalized);
                    if (array_key_exists($lower, $mapped)) {
                        $clean[$key] = $mapped[$lower];
                        continue;
                    }
                }

                if (is_numeric((string)$normalized)) {
                    $clean[$key] = (int)$normalized;
                }
                continue;
            }

            if (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
                if (is_numeric((string)$normalized)) {
                    $clean[$key] = (string)$normalized;
                }
                continue;
            }

            if (in_array($key, ['country', 'country_code', 'countryCode'], true)) {
                $country = strtoupper((string)$normalized);
                $clean[$key] = in_array($country, ['US', 'CA'], true) ? $country : 'US';
                continue;
            }

            if (in_array($key, ['zip', 'postal_code', 'postalCode'], true)) {
                $country = strtoupper((string)($data['country_code'] ?? $data['country'] ?? 'US'));
                $postal = strtoupper(trim((string)$normalized));

                if ($country === 'US') {
                    $digits = preg_replace('/[^0-9]/', '', $postal) ?? '';
                    if (strlen($digits) > 5) {
                        $postal = substr($digits, 0, 5) . '-' . substr($digits, 5, 4);
                    } else {
                        $postal = substr($digits, 0, 5);
                    }
                } else {
                    $compact = preg_replace('/[^A-Z0-9]/', '', $postal) ?? '';
                    if (strlen($compact) >= 6) {
                        $postal = substr($compact, 0, 3) . ' ' . substr($compact, 3, 3);
                    } else {
                        $postal = $compact;
                    }
                }

                $clean[$key] = $postal;
                continue;
            }

            $clean[$key] = $normalized;
        }

        return $clean;
    }

    private function idNamePairs(string $table): array
    {
        $extraSelect = '';
        if ($table === 'distributors') {
            $termCol = $this->resolveColumn('distributors', ['contract_term_years']);
            if ($termCol !== null) {
                $extraSelect = ', ' . $termCol . ' AS contract_term_years';
            }

            $startCol = $this->resolveColumn('distributors', ['contract_start_date']);
            if ($startCol !== null) {
                $extraSelect .= ', ' . $startCol . ' AS contract_start_date';
            }

            $endCol = $this->resolveColumn('distributors', ['contract_end_date']);
            if ($endCol !== null) {
                $extraSelect .= ', ' . $endCol . ' AS contract_end_date';
            }
        }

        $sql = 'SELECT id, name' . $extraSelect . ' FROM ' . $this->safeTable($table) . ' ORDER BY name ASC';
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }

    private function agentPairs(): array
    {
        $firstCol = $this->resolveColumn('agent', ['first_name', 'first']) ?? 'id';
        $lastCol = $this->resolveColumn('agent', ['last_name', 'last']) ?? 'id';
        $stmt = $this->pdo->query('SELECT id, ' . $firstCol . ' AS first_name, ' . $lastCol . ' AS last_name FROM agent ORDER BY first_name ASC, last_name ASC');
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => $row['id'] ?? null,
                'name' => trim(((string)($row['first_name'] ?? '')) . ' ' . ((string)($row['last_name'] ?? ''))),
            ];
        }

        return $rows;
    }

    private function countByTable(string $table, int $distributorScopeId = 0): int
    {
        $safe = $this->safeTable($table);
        if ($distributorScopeId <= 0) {
            $sql = 'SELECT COUNT(*) AS total FROM ' . $safe;
            $stmt = $this->pdo->query($sql);
            $row = $stmt->fetch();
            return (int)($row['total'] ?? 0);
        }

        if ($table === 'distributors') {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) AS total FROM distributors WHERE id = :distributor_id');
            $stmt->bindValue(':distributor_id', $distributorScopeId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
            return (int)($row['total'] ?? 0);
        }

        if ($table === 'providers' || $table === 'agent') {
            $distributorCol = $this->resolveColumn($table, ['distributor_id', 'distID', 'distributorID']);
            if ($distributorCol === null) {
                return 0;
            }

            $stmt = $this->pdo->prepare('SELECT COUNT(*) AS total FROM ' . $safe . ' WHERE ' . $distributorCol . ' = :distributor_id');
            $stmt->bindValue(':distributor_id', $distributorScopeId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
            return (int)($row['total'] ?? 0);
        }

        if ($table === 'deals') {
            $distributorCol = $this->resolveColumn('deals', ['distributor_id', 'distID']);
            if ($distributorCol === null) {
                return 0;
            }

            $stmt = $this->pdo->prepare('SELECT COUNT(*) AS total FROM deals WHERE ' . $distributorCol . ' = :distributor_id');
            $stmt->bindValue(':distributor_id', $distributorScopeId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
            return (int)($row['total'] ?? 0);
        }

        return 0;
    }

    private function orderByForTable(string $table): string
    {
        if (in_array($table, ['distributors', 'providers'], true)) {
            $nameCol = $this->resolveColumn($table, ['name']);
            if ($nameCol !== null) {
                return $nameCol . ' ASC, id ASC';
            }

            return 'id ASC';
        }

        if ($table === 'deals') {
            $nameCol = $this->resolveColumn('deals', ['deal_name', 'dealname', 'name', 'title']);
            if ($nameCol !== null) {
                return $nameCol . ' ASC, id ASC';
            }

            return 'id ASC';
        }

        if ($table === 'agent') {
            $lastCol = $this->resolveColumn('agent', ['last_name', 'last']);
            $firstCol = $this->resolveColumn('agent', ['first_name', 'first']);

            if ($lastCol !== null && $firstCol !== null) {
                return $lastCol . ' ASC, ' . $firstCol . ' ASC, id ASC';
            }

            if ($lastCol !== null) {
                return $lastCol . ' ASC, id ASC';
            }

            return 'id ASC';
        }

        return 'id ASC';
    }

    private function resolveColumn(string $table, array $candidates): ?string
    {
        $columns = $this->tableColumns($table);
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function tableColumns(string $table): array
    {
        $safeTable = $this->safeTable($table);
        if (isset($this->tableColumnsCache[$safeTable])) {
            return $this->tableColumnsCache[$safeTable];
        }

        $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . $safeTable);
        $fields = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!isset($row['Field'])) {
                continue;
            }
            $fields[] = (string)$row['Field'];
        }

        $this->tableColumnsCache[$safeTable] = $fields;
        return $fields;
    }

    private function tableColumnMeta(string $table): array
    {
        $safeTable = $this->safeTable($table);
        if (isset($this->tableColumnMetaCache[$safeTable])) {
            return $this->tableColumnMetaCache[$safeTable];
        }

        $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . $safeTable);
        $meta = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!isset($row['Field'])) {
                continue;
            }
            $meta[(string)$row['Field']] = $row;
        }

        $this->tableColumnMetaCache[$safeTable] = $meta;
        return $meta;
    }

    private function safeTable(string $table): string
    {
        $allowed = ['agent', 'deals', 'distributors', 'providers'];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported table: ' . $table);
        }

        return $table;
    }
}
