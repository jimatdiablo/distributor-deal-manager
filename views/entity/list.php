<?php

declare(strict_types=1);

use App\Core\View;

$columns = $columns ?? [];
$editRow = $editRow ?? null;
$lookups = $lookups ?? [];
$searchQuery = (string)($searchQuery ?? '');
$distributorFilter = (int)($distributorFilter ?? 0);
$agentFilter = (int)($agentFilter ?? 0);
$providerReservedNotice = (string)($providerReservedNotice ?? '');
$canWrite = (bool)($canWrite ?? true);
$canExportCsv = (bool)($canExportCsv ?? false);
$importStatus = trim((string)($_GET['import_status'] ?? ''));
$importMessage = trim((string)($_GET['import_message'] ?? ''));
$importedDeals = (int)($_GET['imported'] ?? 0);
$skippedDeals = (int)($_GET['skipped'] ?? 0);
$providerImportStatus = trim((string)($_GET['provider_import_status'] ?? ''));
$providerImportMessage = trim((string)($_GET['provider_import_message'] ?? ''));
$importedProviders = (int)($_GET['providers_imported'] ?? 0);
$skippedProviders = (int)($_GET['providers_skipped'] ?? 0);
$providerImportConflictsRaw = trim((string)($_GET['provider_import_conflicts'] ?? ''));
$providerImportConflicts = $providerImportConflictsRaw !== ''
    ? array_values(array_filter(array_map('trim', explode('||', $providerImportConflictsRaw))))
    : [];
$routeTableName = $tableName === 'agent' ? 'agents' : $tableName;
$listInSeparateCard = in_array((string)$tableName, ['distributors', 'deals', 'providers'], true);
$listCardTitle =
    (string)$tableName === 'distributors' ? 'Distributor List'
    : ((string)$tableName === 'deals' ? 'Deals List' : ((string)$tableName === 'providers' ? 'Providers List' : ''));
$listSummaryTitle =
    (string)$tableName === 'distributors' ? 'Distributor Table'
    : ((string)$tableName === 'deals' ? 'Deals Table' : ((string)$tableName === 'providers' ? 'Providers Table' : ''));
$listShouldOpen =
    ((string)$tableName === 'distributors' && $searchQuery !== '')
    || ((string)$tableName === 'deals' && ($searchQuery !== '' || $agentFilter > 0))
    || ((string)$tableName === 'providers' && ($searchQuery !== '' || $distributorFilter > 0));

$providerHiddenColumns = ['point_of_contact_email', 'customer_name'];
$visibleColumns = array_values(array_filter(
    $columns,
    static function ($col) use ($tableName, $providerHiddenColumns): bool {
        $col = (string)$col;
        if (preg_match('/^id$/i', $col)) {
            return false;
        }
        if (in_array($col, ['created_at', 'updated_at'], true)) {
            return false;
        }
        if ($tableName === 'providers' && in_array($col, $providerHiddenColumns, true)) {
            return false;
        }
        if ($tableName === 'deals' && $col === 'status') {
            return false;
        }

        return true;
    }
));

$tableColumns = $visibleColumns;
if ($tableName === 'providers' && in_array('status', $tableColumns, true)) {
    $tableColumns = array_values(array_filter(
        $tableColumns,
        static fn($col): bool => (string)$col !== 'status'
    ));

    array_splice($tableColumns, 1, 0, ['status']);

    $distributorCol = null;
    foreach (['distributor_id', 'distID', 'distributorID'] as $candidate) {
        if (in_array($candidate, $tableColumns, true)) {
            $distributorCol = $candidate;
            break;
        }
    }

    $statusIndex = array_search('status', $tableColumns, true);
    $distributorIndex = $distributorCol !== null ? array_search($distributorCol, $tableColumns, true) : false;
    if ($statusIndex !== false || $distributorIndex !== false) {
        $insertAt = count($tableColumns);
        if ($statusIndex !== false && $distributorIndex !== false) {
            $insertAt = min((int)$statusIndex, (int)$distributorIndex);
        } elseif ($statusIndex !== false) {
            $insertAt = (int)$statusIndex;
        } else {
            $insertAt = (int)$distributorIndex;
        }

        $tableColumns = array_values(array_filter(
            $tableColumns,
            static function ($col) use ($distributorCol): bool {
                if ((string)$col === 'status') {
                    return false;
                }

                if ($distributorCol !== null && (string)$col === (string)$distributorCol) {
                    return false;
                }

                return true;
            }
        ));

        array_splice($tableColumns, $insertAt, 0, ['provider_distributor_status']);
    }

    $startCol = null;
    foreach (['start_date', 'sdate'] as $candidate) {
        if (in_array($candidate, $tableColumns, true)) {
            $startCol = $candidate;
            break;
        }
    }

    $endCol = null;
    foreach (['end_date', 'enddate'] as $candidate) {
        if (in_array($candidate, $tableColumns, true)) {
            $endCol = $candidate;
            break;
        }
    }

    if ($startCol !== null || $endCol !== null) {
        $dateInsertAt = count($tableColumns);
        $startIndex = $startCol !== null ? array_search($startCol, $tableColumns, true) : false;
        $endIndex = $endCol !== null ? array_search($endCol, $tableColumns, true) : false;
        if ($startIndex !== false && $endIndex !== false) {
            $dateInsertAt = min((int)$startIndex, (int)$endIndex);
        } elseif ($startIndex !== false) {
            $dateInsertAt = (int)$startIndex;
        } elseif ($endIndex !== false) {
            $dateInsertAt = (int)$endIndex;
        }

        $tableColumns = array_values(array_filter(
            $tableColumns,
            static function ($col) use ($startCol, $endCol): bool {
                return !in_array((string)$col, array_filter([(string)$startCol, (string)$endCol]), true);
            }
        ));

        array_splice($tableColumns, $dateInsertAt, 0, ['provider_dates']);
    }

    $phoneCol = null;
    foreach (['phone'] as $candidate) {
        if (in_array($candidate, $tableColumns, true)) {
            $phoneCol = $candidate;
            break;
        }
    }

    $phoneAltCol = null;
    foreach (['phone_alt'] as $candidate) {
        if (in_array($candidate, $tableColumns, true)) {
            $phoneAltCol = $candidate;
            break;
        }
    }

    if ($phoneCol !== null || $phoneAltCol !== null) {
        $phoneInsertAt = count($tableColumns);
        $phoneIndex = $phoneCol !== null ? array_search($phoneCol, $tableColumns, true) : false;
        $phoneAltIndex = $phoneAltCol !== null ? array_search($phoneAltCol, $tableColumns, true) : false;
        if ($phoneIndex !== false && $phoneAltIndex !== false) {
            $phoneInsertAt = min((int)$phoneIndex, (int)$phoneAltIndex);
        } elseif ($phoneIndex !== false) {
            $phoneInsertAt = (int)$phoneIndex;
        } elseif ($phoneAltIndex !== false) {
            $phoneInsertAt = (int)$phoneAltIndex;
        }

        $tableColumns = array_values(array_filter(
            $tableColumns,
            static function ($col) use ($phoneCol, $phoneAltCol): bool {
                return !in_array((string)$col, array_filter([(string)$phoneCol, (string)$phoneAltCol]), true);
            }
        ));

        array_splice($tableColumns, $phoneInsertAt, 0, ['provider_phone_pair']);
    }

    $contactNameCol = null;
    foreach (['point_of_contact_name'] as $candidate) {
        if (in_array($candidate, $tableColumns, true)) {
            $contactNameCol = $candidate;
            break;
        }
    }

    $contactPhoneCol = null;
    foreach (['point_of_contact_phone'] as $candidate) {
        if (in_array($candidate, $tableColumns, true)) {
            $contactPhoneCol = $candidate;
            break;
        }
    }

    if ($contactNameCol !== null || $contactPhoneCol !== null) {
        $contactInsertAt = count($tableColumns);
        $contactNameIndex = $contactNameCol !== null ? array_search($contactNameCol, $tableColumns, true) : false;
        $contactPhoneIndex = $contactPhoneCol !== null ? array_search($contactPhoneCol, $tableColumns, true) : false;
        if ($contactNameIndex !== false && $contactPhoneIndex !== false) {
            $contactInsertAt = min((int)$contactNameIndex, (int)$contactPhoneIndex);
        } elseif ($contactNameIndex !== false) {
            $contactInsertAt = (int)$contactNameIndex;
        } elseif ($contactPhoneIndex !== false) {
            $contactInsertAt = (int)$contactPhoneIndex;
        }

        $tableColumns = array_values(array_filter(
            $tableColumns,
            static function ($col) use ($contactNameCol, $contactPhoneCol): bool {
                return !in_array((string)$col, array_filter([(string)$contactNameCol, (string)$contactPhoneCol]), true);
            }
        ));

        array_splice($tableColumns, $contactInsertAt, 0, ['provider_contact_pair']);
    }
}

if ($tableName === 'distributors') {
    $contractStartCol = null;
    foreach (['contract_start_date'] as $candidate) {
        if (in_array($candidate, $tableColumns, true)) {
            $contractStartCol = $candidate;
            break;
        }
    }

    $contractEndCol = null;
    foreach (['contract_end_date'] as $candidate) {
        if (in_array($candidate, $tableColumns, true)) {
            $contractEndCol = $candidate;
            break;
        }
    }

    if ($contractStartCol !== null || $contractEndCol !== null) {
        $contractInsertAt = count($tableColumns);
        $startIndex = $contractStartCol !== null ? array_search($contractStartCol, $tableColumns, true) : false;
        $endIndex = $contractEndCol !== null ? array_search($contractEndCol, $tableColumns, true) : false;
        if ($startIndex !== false && $endIndex !== false) {
            $contractInsertAt = min((int)$startIndex, (int)$endIndex);
        } elseif ($startIndex !== false) {
            $contractInsertAt = (int)$startIndex;
        } elseif ($endIndex !== false) {
            $contractInsertAt = (int)$endIndex;
        }

        $tableColumns = array_values(array_filter(
            $tableColumns,
            static function ($col) use ($contractStartCol, $contractEndCol): bool {
                return !in_array((string)$col, array_filter([(string)$contractStartCol, (string)$contractEndCol]), true);
            }
        ));

        array_splice($tableColumns, $contractInsertAt, 0, ['distributor_contract_dates']);
    }
}

$providerOptionalColumns = [];
if ($tableName === 'providers') {
    $providerOptionalColumns = [
        'address',
        'city',
        'state',
        'country_code',
        'country',
        'postal_code',
        'postalCode',
        'zip',
        'segment',
        'email',
        'account_manager_agent_id',
    ];
}

$distributorOptionalColumns = [];
if ($tableName === 'distributors') {
    $distributorOptionalColumns = [
        'address',
        'city',
        'state',
        'country_code',
        'country',
        'postal_code',
        'postalCode',
        'zip',
        'segment',
        'email',
        'phone',
        'phone_alt',
        'contract_start_date',
        'contract_end_date',
    ];
}

$regionOptions = [
    'US-AL' => 'AL - Alabama',
    'US-AK' => 'AK - Alaska',
    'US-AZ' => 'AZ - Arizona',
    'US-AR' => 'AR - Arkansas',
    'US-CA' => 'CA - California',
    'US-CO' => 'CO - Colorado',
    'US-CT' => 'CT - Connecticut',
    'US-DE' => 'DE - Delaware',
    'US-FL' => 'FL - Florida',
    'US-GA' => 'GA - Georgia',
    'US-HI' => 'HI - Hawaii',
    'US-ID' => 'ID - Idaho',
    'US-IL' => 'IL - Illinois',
    'US-IN' => 'IN - Indiana',
    'US-IA' => 'IA - Iowa',
    'US-KS' => 'KS - Kansas',
    'US-KY' => 'KY - Kentucky',
    'US-LA' => 'LA - Louisiana',
    'US-ME' => 'ME - Maine',
    'US-MD' => 'MD - Maryland',
    'US-MA' => 'MA - Massachusetts',
    'US-MI' => 'MI - Michigan',
    'US-MN' => 'MN - Minnesota',
    'US-MS' => 'MS - Mississippi',
    'US-MO' => 'MO - Missouri',
    'US-MT' => 'MT - Montana',
    'US-NE' => 'NE - Nebraska',
    'US-NV' => 'NV - Nevada',
    'US-NH' => 'NH - New Hampshire',
    'US-NJ' => 'NJ - New Jersey',
    'US-NM' => 'NM - New Mexico',
    'US-NY' => 'NY - New York',
    'US-NC' => 'NC - North Carolina',
    'US-ND' => 'ND - North Dakota',
    'US-OH' => 'OH - Ohio',
    'US-OK' => 'OK - Oklahoma',
    'US-OR' => 'OR - Oregon',
    'US-PA' => 'PA - Pennsylvania',
    'US-RI' => 'RI - Rhode Island',
    'US-SC' => 'SC - South Carolina',
    'US-SD' => 'SD - South Dakota',
    'US-TN' => 'TN - Tennessee',
    'US-TX' => 'TX - Texas',
    'US-UT' => 'UT - Utah',
    'US-VT' => 'VT - Vermont',
    'US-VA' => 'VA - Virginia',
    'US-WA' => 'WA - Washington',
    'US-WV' => 'WV - West Virginia',
    'US-WI' => 'WI - Wisconsin',
    'US-WY' => 'WY - Wyoming',
    'CA-AB' => 'AB - Alberta',
    'CA-BC' => 'BC - British Columbia',
    'CA-MB' => 'MB - Manitoba',
    'CA-NB' => 'NB - New Brunswick',
    'CA-NL' => 'NL - Newfoundland and Labrador',
    'CA-NS' => 'NS - Nova Scotia',
    'CA-NT' => 'NT - Northwest Territories',
    'CA-NU' => 'NU - Nunavut',
    'CA-ON' => 'ON - Ontario',
    'CA-PE' => 'PE - Prince Edward Island',
    'CA-QC' => 'QC - Quebec',
    'CA-SK' => 'SK - Saskatchewan',
    'CA-YT' => 'YT - Yukon',
];

$selectSources = [
    'distributor_id' => $lookups['distributors'] ?? [],
    'distID' => $lookups['distributors'] ?? [],
    'distributorID' => $lookups['distributors'] ?? [],
    'provider_id' => $lookups['providers'] ?? [],
    'provID' => $lookups['providers'] ?? [],
    'agent_id' => $lookups['agents'] ?? [],
    'agentID' => $lookups['agents'] ?? [],
    'account_manager_agent_id' => $lookups['agents'] ?? [],
];

$lookupIdMaps = [];
foreach ($selectSources as $key => $items) {
    $map = [];
    foreach ($items as $item) {
        $id = (string)($item['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $map[$id] = (string)($item['name'] ?? ('#' . $id));
    }
    $lookupIdMaps[$key] = $map;
}

$labelFor = static function (string $col): string {
    $map = [
        'distID' => 'Distributor',
        'distributorID' => 'Distributor',
        'distributor_id' => 'Distributor',
        'provID' => 'Provider',
        'provider_id' => 'Provider',
        'agentID' => 'Agent',
        'agent_id' => 'Agent',
        'country_code' => 'Country',
        'postal_code' => 'ZIP / Postal',
        'postalCode' => 'ZIP / Postal',
    ];

    if (isset($map[$col])) {
        return $map[$col];
    }

    return ucwords(str_replace(['_', '-'], ' ', $col));
};

$formatPhone = static function ($value): string {
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
        $digits = substr($digits, 1);
    }

    if (strlen($digits) === 10) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
    }

    return $raw;
};

$isDateWithinDays = static function ($dateText, int $days): bool {
    $raw = trim((string)$dateText);
    if ($raw === '') {
        return false;
    }

    $targetTs = strtotime($raw);
    if ($targetTs === false) {
        return false;
    }

    $todayTs = strtotime(date('Y-m-d'));
    if ($todayTs === false) {
        return false;
    }

    $diffDays = (int)floor(($targetTs - $todayTs) / 86400);
    return $diffDays >= 0 && $diffDays <= $days;
};

$renderField = static function (string $col, $value = null) use ($formatPhone, $labelFor, $selectSources, $regionOptions, $tableName): string {
    if (in_array($col, ['start_date', 'sdate'], true)) {
        $dateValue = (string)($value ?? '');
        if ($dateValue === '') {
            $dateValue = date('Y-m-d');
        }
        return '<input type="date" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($dateValue) . '">';
    }

    if (in_array($col, ['deal_date', 'date'], true)) {
        $dateValue = (string)($value ?? '');
        if ($dateValue === '') {
            $dateValue = date('Y-m-d');
        }
        return '<input type="date" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($dateValue) . '">';
    }

    if ($col === 'close_date') {
        return '<input type="date" name="close_date" value="' . htmlspecialchars((string)($value ?? '')) . '">';
    }

    if (in_array($col, ['end_date', 'enddate'], true)) {
        return '<input type="date" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars((string)($value ?? '')) . '">';
    }

    if (in_array($col, ['contract_start_date', 'contract_end_date'], true)) {
        $dateValue = (string)($value ?? '');
        if ($col === 'contract_start_date' && $dateValue === '') {
            $dateValue = date('Y-m-d');
        }
        return '<input type="date" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($dateValue) . '">';
    }

    if ($tableName === 'distributors' && $col === 'contract_term_years') {
        $current = (string)($value ?? '1');
        if (!in_array($current, ['1', '2', '3'], true)) {
            $current = '1';
        }

        $html = '<select name="contract_term_years">';
        foreach (['1' => '1 Year', '2' => '2 Years', '3' => '3 Years'] as $years => $label) {
            $selected = $current === $years ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars((string)$years) . '"' . $selected . '>' . htmlspecialchars((string)$label) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    if ($col === 'agent') {
        $current = (string)($value ?? '');
        $html = '<select name="agent">';
        $html .= '<option value="">(none)</option>';
        foreach (($selectSources['agentID'] ?? []) as $item) {
            $name = (string)($item['name'] ?? '');
            $selected = $name !== '' && $name === $current ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($name) . '"' . $selected . '>' . htmlspecialchars($name) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    if (in_array($col, ['country', 'country_code', 'countryCode'], true)) {
        $current = strtoupper(trim((string)($value ?? 'US')));
        $options = [
            'US' => 'United States',
            'CA' => 'Canada',
        ];

        $html = '<select name="' . htmlspecialchars($col) . '">';
        foreach ($options as $code => $label) {
            $selected = $current === $code ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($code) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    if (in_array($col, ['state', 'province', 'region'], true)) {
        $raw = strtoupper(trim((string)($value ?? '')));
        $current = strlen($raw) === 2 ? $raw : $raw;
        $html = '<select name="' . htmlspecialchars($col) . '">';
        $html .= '<option value="">Select region</option>';
        foreach ($regionOptions as $code => $label) {
            $abbr = substr($code, 3);
            $selected = $current === $abbr ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($abbr) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    if (in_array($col, ['zip', 'postal_code', 'postalCode'], true)) {
        return '<input name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars((string)($value ?? '')) . '" placeholder="ZIP / Postal (US or CA)" maxlength="10">';
    }

    if (str_contains($col, 'phone')) {
        $phoneValue = $formatPhone($value ?? '');
        return '<input name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($phoneValue) . '" placeholder="(800) 555-1212">';
    }

    if ($col === 'city') {
        return '<input name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars((string)($value ?? '')) . '" placeholder="City">';
    }

    if (in_array($col, ['address', 'street'], true)) {
        return '<input name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars((string)($value ?? '')) . '" placeholder="Address">';
    }

    if ($col === 'status') {
        $raw = strtolower((string)($value ?? ''));
        if ($tableName === 'providers') {
            $current = match ($raw) {
                '0', 'reserved' => 'reserved',
                '1', 'protected' => 'protected',
                '2', 'open' => 'open',
                default => '',
            };

            $options = [
                'reserved' => 'Reserved',
                'protected' => 'Protected',
                'open' => 'Open',
            ];
        } else {
            $current = match ($raw) {
                '1', 'active' => 'active',
                '0', 'inactive' => 'inactive',
                '2', 'pending' => 'pending',
                default => '',
            };

            $options = [
                'active' => 'Active',
                'inactive' => 'Inactive',
                'pending' => 'Pending',
            ];
        }

        $html = '<select name="status">';
        foreach ($options as $key => $label) {
            $selected = $current === $key ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($key) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    if ($tableName === 'deals' && $col === 'stage') {
        $current = strtolower(trim((string)($value ?? 'pending')));
        $options = [
            'pending' => 'Pending',
            'closed' => 'Closed',
            'cancelled' => 'Cancelled',
        ];

        $html = '<select name="stage">';
        foreach ($options as $key => $label) {
            $selected = $current === $key ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($key) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    if (isset($selectSources[$col])) {
        $current = (string)($value ?? '');
        $isDistributorSelect = in_array($col, ['distributor_id', 'distID', 'distributorID'], true);
        $html = '<select name="' . htmlspecialchars($col) . '">';
        $html .= '<option value="">(none)</option>';
        foreach ($selectSources[$col] as $item) {
            $id = (string)($item['id'] ?? '');
            $name = (string)($item['name'] ?? ('#' . $id));
            $selected = $id !== '' && $id === $current ? ' selected' : '';
            $termYearsAttr = '';
            if ($isDistributorSelect) {
                $termYears = (string)($item['contract_term_years'] ?? '1');
                if (!in_array($termYears, ['1', '2', '3'], true)) {
                    $termYears = '1';
                }
                $termYearsAttr = ' data-contract-term-years="' . htmlspecialchars($termYears) . '"';

                $contractEndRaw = trim((string)($item['contract_end_date'] ?? ''));
                if ($contractEndRaw !== '') {
                    $termYearsAttr .= ' data-contract-end-date="' . htmlspecialchars($contractEndRaw) . '"';
                }
            }

            $html .= '<option value="' . htmlspecialchars($id) . '"' . $selected . $termYearsAttr . '>' . htmlspecialchars($name) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    return '<input name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars((string)($value ?? '')) . '" placeholder="' . htmlspecialchars($labelFor($col)) . '">';
};

$renderStatusBadge = static function ($value) use ($tableName): string {
    $raw = strtolower(trim((string)$value));
    if ($tableName === 'providers') {
        $map = [
            '0' => ['Reserved', 'badge-pending'],
            'reserved' => ['Reserved', 'badge-pending'],
            '1' => ['Protected', 'badge-active'],
            'protected' => ['Protected', 'badge-active'],
            '2' => ['Open', 'badge-open'],
            'open' => ['Open', 'badge-open'],
        ];
    } else {
        $map = [
            '1' => ['Active', 'badge-active'],
            'active' => ['Active', 'badge-active'],
            '0' => ['Inactive', 'badge-inactive'],
            'inactive' => ['Inactive', 'badge-inactive'],
            '2' => ['Pending', 'badge-pending'],
            'pending' => ['Pending', 'badge-pending'],
        ];
    }

    if (!isset($map[$raw])) {
        return '<span class="badge badge-unknown">' . htmlspecialchars((string)$value) . '</span>';
    }

    [$label, $className] = $map[$raw];
    return '<span class="badge ' . $className . '">' . htmlspecialchars($label) . '</span>';
};

$renderDealStageBadge = static function ($value): string {
    $raw = strtolower(trim((string)$value));
    $map = [
        'closed' => ['Closed', 'badge-active'],
        'pending' => ['Pending', 'badge-pending'],
        'cancelled' => ['Cancelled', 'badge-inactive'],
    ];

    if (!isset($map[$raw])) {
        return '<span class="badge badge-unknown">' . htmlspecialchars((string)$value) . '</span>';
    }

    [$label, $className] = $map[$raw];
    return '<span class="badge ' . $className . '">' . htmlspecialchars($label) . '</span>';
};

ob_start();
?>
<?php if ((string)$tableName === 'agent' && $canWrite): ?>
<section class="card">
    <h2>Agent Imports</h2>

    <?php if ($importStatus === 'ok'): ?>
        <p style="margin: 8px 0 12px; padding: 8px 10px; border: 1px solid #86efac; border-radius: 8px; background: #f0fdf4; color: #166534;">
            Deals import complete. Imported: <?= $importedDeals ?>. Skipped: <?= $skippedDeals ?>.
        </p>
    <?php elseif ($importStatus === 'error'): ?>
        <p style="margin: 8px 0 12px; padding: 8px 10px; border: 1px solid #fecaca; border-radius: 8px; background: #fef2f2; color: #991b1b;">
            Deal import failed<?= $importMessage !== '' ? ': ' . htmlspecialchars($importMessage) : '.' ?>
        </p>
    <?php endif; ?>

    <?php if ($providerImportStatus === 'ok'): ?>
        <p style="margin: 8px 0 12px; padding: 8px 10px; border: 1px solid #86efac; border-radius: 8px; background: #f0fdf4; color: #166534;">
            Provider import complete. Imported: <?= $importedProviders ?>. Skipped: <?= $skippedProviders ?>.
        </p>
    <?php elseif ($providerImportStatus === 'error'): ?>
        <p style="margin: 8px 0 12px; padding: 8px 10px; border: 1px solid #fecaca; border-radius: 8px; background: #fef2f2; color: #991b1b;">
            Provider import failed<?= $providerImportMessage !== '' ? ': ' . htmlspecialchars($providerImportMessage) : '.' ?>
        </p>
    <?php endif; ?>

    <?php if ((string)$tableName === 'agent' && $providerImportConflicts !== []): ?>
        <div style="margin: 8px 0 12px; padding: 8px 10px; border: 1px solid #fcd34d; border-radius: 8px; background: #fffbeb; color: #92400e;">
            <div style="font-weight: 600; margin: 0 0 6px;">Some provider rows were skipped due to conflicts:</div>
            <ul style="margin: 0; padding-left: 20px;">
                <?php foreach ($providerImportConflicts as $conflict): ?>
                    <li><?= htmlspecialchars($conflict) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <details style="margin: 10px 0 0;">
        <summary style="cursor: pointer; font-weight: 600;">Import Deals From Agent Spreadsheet</summary>
        <form method="post" action="/agents/import-deals" enctype="multipart/form-data" class="row-actions" style="margin: 10px 0 0;">
            <?= $csrfField() ?>
            <input type="file" name="deal_import_file" accept=".csv,text/csv" required>
            <button type="submit" class="btn-muted">Import Deals CSV</button>
            <a class="btn-muted" href="/downloads/agent_deal_import_template.csv" download>Download Template</a>
        </form>
        <p class="muted" style="margin: 8px 0 0;">Use CSV template headers. Date format must be YYYY-MM-DD.</p>
    </details>

    <details style="margin: 10px 0 0;">
        <summary style="cursor: pointer; font-weight: 600;">Import Providers From Agent Spreadsheet</summary>
        <form method="post" action="/agents/import-providers" enctype="multipart/form-data" class="row-actions" style="margin: 10px 0 0;">
            <?= $csrfField() ?>
            <input type="file" name="provider_import_file" accept=".csv,text/csv" required>
            <button type="submit" class="btn-muted">Import Providers CSV</button>
            <a class="btn-muted" href="/downloads/agent_provider_import_template.csv" download>Download Template</a>
        </form>
        <p class="muted" style="margin: 8px 0 0;">Use CSV template headers. Date format must be YYYY-MM-DD.</p>
    </details>
</section>
<?php endif; ?>

<section class="card">
    <h2><?= htmlspecialchars((string)$title) ?></h2>

    <?php if ((string)$tableName === 'deals' && $providerReservedNotice === '1'): ?>
        <p style="margin: 8px 0 12px; padding: 8px 10px; border: 1px solid #fde68a; border-radius: 8px; background: #fffbeb; color: #92400e;">
            Provider was automatically set to Reserved and end date aligned to start date because no closed deals remain.
        </p>
    <?php endif; ?>

    <?php if (!$listInSeparateCard): ?>
        <form method="get" action="/<?= htmlspecialchars((string)$routeTableName) ?>" class="row-actions" style="margin: 10px 0 14px;">
            <input name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search <?= htmlspecialchars((string)$title) ?>">
            <?php if ((string)$tableName === 'providers'): ?>
                <select name="distributor_id" aria-label="Filter providers by distributor">
                    <option value="">All Distributors</option>
                    <?php foreach (($lookups['distributors'] ?? []) as $dist): ?>
                        <?php $distId = (int)($dist['id'] ?? 0); ?>
                        <option value="<?= $distId ?>"<?= $distributorFilter === $distId ? ' selected' : '' ?>>
                            <?= htmlspecialchars((string)($dist['name'] ?? ('#' . $distId))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php elseif ((string)$tableName === 'deals'): ?>
                <select name="agent_id" aria-label="Filter deals by agent">
                    <option value="">All Agents</option>
                    <?php foreach (($lookups['agents'] ?? []) as $agent): ?>
                        <?php $agentId = (int)($agent['id'] ?? 0); ?>
                        <option value="<?= $agentId ?>"<?= $agentFilter === $agentId ? ' selected' : '' ?>>
                            <?= htmlspecialchars((string)($agent['name'] ?? ('#' . $agentId))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button type="submit" class="btn-muted">Search</button>
            <?php if ($searchQuery !== '' || ((string)$tableName === 'providers' && $distributorFilter > 0) || ((string)$tableName === 'deals' && $agentFilter > 0)): ?>
                <a href="/<?= htmlspecialchars((string)$routeTableName) ?>">Clear</a>
            <?php endif; ?>
            <?php if ($canExportCsv && in_array((string)$tableName, ['distributors', 'providers', 'deals'], true)): ?>
                <a class="btn-muted" href="/<?= htmlspecialchars((string)$routeTableName) ?>/download-csv?q=<?= urlencode($searchQuery) ?><?= ((string)$tableName === 'providers' && $distributorFilter > 0) ? '&distributor_id=' . $distributorFilter : '' ?><?= ((string)$tableName === 'deals' && $agentFilter > 0) ? '&agent_id=' . $agentFilter : '' ?>">Download CSV</a>
            <?php endif; ?>
            <?php if ((string)$tableName === 'providers'): ?>
                <button type="button" id="providersDetailToggle" class="btn-muted providers-detail-toggle" aria-pressed="false">Show Details</button>
            <?php elseif ((string)$tableName === 'distributors'): ?>
                <button type="button" id="distributorsDetailToggle" class="btn-muted distributors-detail-toggle" aria-pressed="false">Show Details</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <?php if ($canWrite): ?>
    <details style="margin: 14px 0;">
        <summary style="cursor: pointer; font-weight: 600;"><?= (string)$tableName === 'agent' ? 'Add Agent' : 'Add Record' ?></summary>
        <form method="post" class="grid" style="margin: 10px 0 0;">
            <?= $csrfField() ?>
            <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery) ?>">
            <?php if ((string)$tableName === 'providers' && $distributorFilter > 0): ?>
                <input type="hidden" name="distributor_id" value="<?= $distributorFilter ?>">
            <?php elseif ((string)$tableName === 'deals' && $agentFilter > 0): ?>
                <input type="hidden" name="agent_id" value="<?= $agentFilter ?>">
            <?php endif; ?>
            <?php if ((string)$tableName === 'deals'): ?>
                <input type="hidden" name="sync_provider_end_date" value="0">
            <?php elseif ((string)$tableName === 'distributors'): ?>
                <input type="hidden" name="sync_distributor_provider_dates" value="0">
            <?php endif; ?>
            <?php foreach ($visibleColumns as $col): ?>
                <div>
                    <div class="muted" style="margin: 0 0 4px;"><?= htmlspecialchars($labelFor((string)$col)) ?></div>
                    <?= $renderField((string)$col) ?>
                </div>
            <?php endforeach; ?>
            <div class="form-actions">
                <button type="submit" name="submit" value="1">Add Record</button>
            </div>
        </form>
    </details>
    <?php endif; ?>

    <?php if ($canWrite && is_array($editRow)): ?>
        <details open style="margin: 10px 0 16px; padding: 12px; border: 1px solid #dbe2ea; border-radius: 10px; background: #f8fafc;">
            <summary style="cursor: pointer; font-weight: 600;">Edit Record</summary>
            <form method="post" action="/<?= htmlspecialchars((string)$routeTableName) ?>/update" class="grid" style="margin: 10px 0 0;">
                <?= $csrfField() ?>
                <input type="hidden" name="id" value="<?= (int)($editRow['id'] ?? 0) ?>">
                <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery) ?>">
                <?php if ((string)$tableName === 'providers' && $distributorFilter > 0): ?>
                    <input type="hidden" name="distributor_id" value="<?= $distributorFilter ?>">
                <?php elseif ((string)$tableName === 'deals' && $agentFilter > 0): ?>
                    <input type="hidden" name="agent_id" value="<?= $agentFilter ?>">
                <?php endif; ?>
                <?php if ((string)$tableName === 'deals'): ?>
                    <input type="hidden" name="sync_provider_end_date" value="0">
                <?php elseif ((string)$tableName === 'distributors'): ?>
                    <input type="hidden" name="sync_distributor_provider_dates" value="0">
                    <input type="hidden" name="extend_distributor_contract" value="0">
                <?php endif; ?>
                <?php foreach ($visibleColumns as $col): ?>
                    <div>
                        <div class="muted" style="margin: 0 0 4px;"><?= htmlspecialchars($labelFor((string)$col)) ?></div>
                        <?= $renderField((string)$col, $editRow[$col] ?? null) ?>
                    </div>
                <?php endforeach; ?>
                <div class="form-actions">
                    <button type="submit">Save Changes</button>
                    <?php if ((string)$tableName === 'distributors'): ?>
                        <button type="submit" class="btn-muted" name="extend_distributor_contract" value="1">Extend End Date By Term</button>
                    <?php endif; ?>
                    <a href="/<?= htmlspecialchars((string)$routeTableName) ?>" class="muted">Cancel</a>
                </div>
            </form>
        </details>
    <?php endif; ?>

    <?php if ($listInSeparateCard): ?>
</section>

<section class="card">
    <h2><?= htmlspecialchars($listCardTitle) ?></h2>
    <form method="get" action="/<?= htmlspecialchars((string)$routeTableName) ?>" class="row-actions" style="margin: 10px 0 14px;">
        <input name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search <?= htmlspecialchars((string)$title) ?>">
        <?php if ((string)$tableName === 'providers'): ?>
            <select name="distributor_id" aria-label="Filter providers by distributor">
                <option value="">All Distributors</option>
                <?php foreach (($lookups['distributors'] ?? []) as $dist): ?>
                    <?php $distId = (int)($dist['id'] ?? 0); ?>
                    <option value="<?= $distId ?>"<?= $distributorFilter === $distId ? ' selected' : '' ?>>
                        <?= htmlspecialchars((string)($dist['name'] ?? ('#' . $distId))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php elseif ((string)$tableName === 'deals'): ?>
            <select name="agent_id" aria-label="Filter deals by agent">
                <option value="">All Agents</option>
                <?php foreach (($lookups['agents'] ?? []) as $agent): ?>
                    <?php $agentId = (int)($agent['id'] ?? 0); ?>
                    <option value="<?= $agentId ?>"<?= $agentFilter === $agentId ? ' selected' : '' ?>>
                        <?= htmlspecialchars((string)($agent['name'] ?? ('#' . $agentId))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="submit" class="btn-muted">Search</button>
        <?php if ($searchQuery !== '' || ((string)$tableName === 'providers' && $distributorFilter > 0) || ((string)$tableName === 'deals' && $agentFilter > 0)): ?>
            <a href="/<?= htmlspecialchars((string)$routeTableName) ?>">Clear</a>
        <?php endif; ?>
        <?php if ($canExportCsv && in_array((string)$tableName, ['distributors', 'providers', 'deals'], true)): ?>
            <a class="btn-muted" href="/<?= htmlspecialchars((string)$routeTableName) ?>/download-csv?q=<?= urlencode($searchQuery) ?><?= ((string)$tableName === 'providers' && $distributorFilter > 0) ? '&distributor_id=' . $distributorFilter : '' ?><?= ((string)$tableName === 'deals' && $agentFilter > 0) ? '&agent_id=' . $agentFilter : '' ?>">Download CSV</a>
        <?php endif; ?>
        <?php if ((string)$tableName === 'providers'): ?>
            <button type="button" id="providersDetailToggle" class="btn-muted providers-detail-toggle" aria-pressed="false">Show Details</button>
        <?php elseif ((string)$tableName === 'distributors'): ?>
            <button type="button" id="distributorsDetailToggle" class="btn-muted distributors-detail-toggle" aria-pressed="false">Show Details</button>
        <?php endif; ?>
    </form>

    <details style="margin: 12px 0;"<?= $listShouldOpen ? ' open' : '' ?>>
        <summary style="cursor: pointer; font-weight: 600;"><?= htmlspecialchars($listSummaryTitle) ?></summary>
        <div class="table-wrap" style="margin-top: 10px;">
    <?php elseif ((string)$tableName === 'agent'): ?>
    <details style="margin: 12px 0;">
        <summary style="cursor: pointer; font-weight: 600;">Agent List</summary>
        <div class="table-wrap" style="margin-top: 10px;">
    <?php else: ?>
    <div class="table-wrap">
    <?php endif; ?>
    <table<?= (string)$tableName === 'providers' ? ' id="providersTable" class="providers-table providers-compact"' : ((string)$tableName === 'distributors' ? ' id="distributorsTable" class="distributors-table distributors-compact"' : '') ?>>
        <thead>
            <tr>
                <?php foreach ($tableColumns as $col): ?>
                    <th<?= ((string)$tableName === 'providers' && in_array((string)$col, $providerOptionalColumns, true)) ? ' class="providers-optional-col"' : (((string)$tableName === 'distributors' && in_array((string)$col, $distributorOptionalColumns, true)) ? ' class="distributors-optional-col"' : '') ?>><?php
                        $headerLabel = $labelFor((string)$col);
                        if ((string)$col === 'provider_dates') {
                            $headerLabel = 'Start / End Date';
                        } elseif ((string)$col === 'distributor_contract_dates') {
                            $headerLabel = 'Contract Start / End Date';
                        } elseif ((string)$col === 'provider_distributor_status') {
                            $headerLabel = 'Distributor / Status';
                        } elseif ((string)$col === 'provider_phone_pair') {
                            $headerLabel = 'Phone / Phone Alt';
                        } elseif ((string)$col === 'provider_contact_pair') {
                            $headerLabel = 'Point of Contact';
                        }
                        ?><?= htmlspecialchars($headerLabel) ?></th>
                <?php endforeach; ?>
                <?php if ($canWrite): ?>
                    <th>Actions</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $isDistributorContractNearEnd =
                    (string)$tableName === 'distributors'
                    && $isDateWithinDays($row['contract_end_date'] ?? '', 60);
                ?>
                <tr<?= $isDistributorContractNearEnd ? ' class="contract-warning-row"' : '' ?>>
                    <?php foreach ($tableColumns as $col): ?>
                        <?php
                        $cellClasses = [];
                        if (in_array((string)$col, ['provider_distributor_status', 'provider_dates', 'provider_phone_pair', 'provider_contact_pair'], true)) {
                            $cellClasses[] = 'stacked-col-cell';
                        }
                        if ((string)$col === 'distributor_contract_dates') {
                            $cellClasses[] = 'stacked-col-cell';
                        }
                        if ((string)$tableName === 'providers' && in_array((string)$col, $providerOptionalColumns, true)) {
                            $cellClasses[] = 'providers-optional-col';
                        }
                        if ((string)$tableName === 'distributors' && in_array((string)$col, $distributorOptionalColumns, true)) {
                            $cellClasses[] = 'distributors-optional-col';
                        }
                        ?>
                        <td<?= $cellClasses !== [] ? ' class="' . htmlspecialchars(implode(' ', $cellClasses)) . '"' : '' ?>>
                            <?php if ((string)$col === 'distributor_contract_dates' && (string)$tableName === 'distributors'): ?>
                                <div class="stacked-pair">
                                    <div><span class="muted">Contract Start</span><span><?= htmlspecialchars((string)($row['contract_start_date'] ?? '')) ?></span></div>
                                    <div><span class="muted">Contract End</span><span><?= htmlspecialchars((string)($row['contract_end_date'] ?? '')) ?></span></div>
                                </div>
                            <?php elseif ((string)$col === 'provider_distributor_status' && (string)$tableName === 'providers'): ?>
                                <?php
                                $distributorRawId = (string)($row['distributor_id'] ?? ($row['distID'] ?? ($row['distributorID'] ?? '')));
                                $distributorLabel =
                                    ($lookupIdMaps['distributor_id'][$distributorRawId] ?? null)
                                    ?? ($lookupIdMaps['distID'][$distributorRawId] ?? null)
                                    ?? ($lookupIdMaps['distributorID'][$distributorRawId] ?? null)
                                    ?? $distributorRawId;
                                ?>
                                <div class="stacked-pair">
                                    <div><span class="muted">Distributor</span><span><?= htmlspecialchars($distributorLabel) ?></span></div>
                                    <div><span class="muted">Status</span><span><?= $renderStatusBadge($row['status'] ?? '') ?></span></div>
                                </div>
                            <?php elseif ((string)$col === 'provider_dates' && (string)$tableName === 'providers'): ?>
                                <div class="stacked-pair">
                                    <div><span class="muted">Start</span><span><?= htmlspecialchars((string)($row['start_date'] ?? ($row['sdate'] ?? ''))) ?></span></div>
                                    <div><span class="muted">End</span><span><?= htmlspecialchars((string)($row['end_date'] ?? ($row['enddate'] ?? ''))) ?></span></div>
                                </div>
                            <?php elseif ((string)$col === 'provider_phone_pair' && (string)$tableName === 'providers'): ?>
                                <div class="stacked-pair">
                                    <div><span class="muted">Phone</span><span><?= htmlspecialchars($formatPhone($row['phone'] ?? '')) ?></span></div>
                                    <div><span class="muted">Alt</span><span><?= htmlspecialchars($formatPhone($row['phone_alt'] ?? '')) ?></span></div>
                                </div>
                            <?php elseif ((string)$col === 'provider_contact_pair' && (string)$tableName === 'providers'): ?>
                                <div class="stacked-pair">
                                    <div><span class="muted">Name</span><span><?= htmlspecialchars((string)($row['point_of_contact_name'] ?? '')) ?></span></div>
                                    <div><span class="muted">Phone</span><span><?= htmlspecialchars($formatPhone($row['point_of_contact_phone'] ?? '')) ?></span></div>
                                </div>
                            <?php elseif ((string)$col === 'status'): ?>
                                <?= $renderStatusBadge($row[$col] ?? '') ?>
                            <?php elseif ((string)$tableName === 'deals' && (string)$col === 'stage'): ?>
                                <?= $renderDealStageBadge($row[$col] ?? '') ?>
                            <?php elseif (isset($lookupIdMaps[(string)$col])): ?>
                                <?php
                                $rawId = (string)($row[$col] ?? '');
                                $label = $lookupIdMaps[(string)$col][$rawId] ?? $rawId;
                                ?>
                                <?= htmlspecialchars($label) ?>
                            <?php else: ?>
                                <?php if (str_contains((string)$col, 'phone')): ?>
                                    <?= htmlspecialchars($formatPhone($row[$col] ?? '')) ?>
                                <?php else: ?>
                                    <?= htmlspecialchars((string)($row[$col] ?? '')) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <?php if ($canWrite): ?>
                    <td>
                        <div class="row-actions table-actions">
                            <a class="action-link-btn" href="/<?= htmlspecialchars((string)$routeTableName) ?>?edit=<?= (int)($row['id'] ?? 0) ?><?= $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '' ?><?= ((string)$tableName === 'providers' && $distributorFilter > 0) ? '&distributor_id=' . $distributorFilter : '' ?><?= ((string)$tableName === 'deals' && $agentFilter > 0) ? '&agent_id=' . $agentFilter : '' ?>">Edit</a>
                            <form method="post" action="/<?= htmlspecialchars((string)$routeTableName) ?>/delete" onsubmit="return confirm('Delete this record?');">
                                <?= $csrfField() ?>
                                <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
                                <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery) ?>">
                                <?php if ((string)$tableName === 'providers' && $distributorFilter > 0): ?>
                                    <input type="hidden" name="distributor_id" value="<?= $distributorFilter ?>">
                                <?php elseif ((string)$tableName === 'deals' && $agentFilter > 0): ?>
                                    <input type="hidden" name="agent_id" value="<?= $agentFilter ?>">
                                <?php endif; ?>
                                <button type="submit" class="btn-danger btn-delete-small">Delete</button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="<?= $canWrite ? 99 : 98 ?>" class="muted">No rows found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if ($listInSeparateCard): ?>
        </div>
    </details>
</section>
    <?php elseif ((string)$tableName === 'agent'): ?>
        </div>
    </details>
    <?php else: ?>
    </div>
    <?php endif; ?>

    <?php if ((string)$tableName === 'distributors'): ?>
    <script>
    (function () {
        function bindDistributorTermSyncRule(form) {
            var termInput = form.querySelector('select[name="contract_term_years"]');
            var syncInput = form.querySelector('input[name="sync_distributor_provider_dates"]');
            if (!termInput || !syncInput) {
                return;
            }

            var initialTerm = String(termInput.value || '').trim();

            form.addEventListener('submit', function () {
                syncInput.value = '0';

                var oldTerm = parseInt(initialTerm, 10);
                var newTerm = parseInt(String(termInput.value || '').trim(), 10);
                if (isNaN(oldTerm) || isNaN(newTerm)) {
                    return;
                }

                if (newTerm === oldTerm) {
                    return;
                }

                var direction = newTerm > oldTerm ? 'increased' : 'reduced';
                var confirmed = window.confirm('Contract term ' + direction + '. Recalculate related protected provider end dates based on provider start date and the new term length?');
                syncInput.value = confirmed ? '1' : '0';
            });
        }

        function bindDistributorDetailsToggle() {
            var toggle = document.getElementById('distributorsDetailToggle');
            var table = document.getElementById('distributorsTable');
            if (!toggle || !table) {
                return;
            }

            function apply(showDetails) {
                table.classList.toggle('distributors-compact', !showDetails);
                toggle.textContent = showDetails ? 'Hide Details' : 'Show Details';
                toggle.setAttribute('aria-pressed', showDetails ? 'true' : 'false');
                toggle.setAttribute('aria-label', showDetails ? 'Hide distributor detail columns' : 'Show distributor detail columns');
            }

            apply(false);
            toggle.addEventListener('click', function () {
                var isCompact = table.classList.contains('distributors-compact');
                apply(isCompact);
            });
        }

        var forms = document.querySelectorAll('form.grid');
        forms.forEach(bindDistributorTermSyncRule);
        bindDistributorDetailsToggle();
    })();
    </script>
    <?php endif; ?>

    <?php if ((string)$tableName === 'providers'): ?>
    <script>
    (function () {
        function addYears(dateText, years) {
            if (!dateText) {
                return '';
            }

            var dt = new Date(dateText + 'T00:00:00');
            if (isNaN(dt.getTime())) {
                return '';
            }

            dt.setFullYear(dt.getFullYear() + years);
            return dt.toISOString().slice(0, 10);
        }

        function isProtectedStatus(statusValue) {
            var normalized = String(statusValue || '').trim().toLowerCase();
            return normalized === '1' || normalized === 'protected';
        }

        function bindProviderDateRule(form) {
            var startInput = form.querySelector('input[name="start_date"], input[name="sdate"]');
            var endInput = form.querySelector('input[name="end_date"], input[name="enddate"]');
            var statusInput = form.querySelector('select[name="status"]');
            var distributorInput = form.querySelector('select[name="distributor_id"], select[name="distID"], select[name="distributorID"]');
            if (!startInput || !endInput) {
                return;
            }

            function selectedDistributorTermYears() {
                if (!distributorInput || distributorInput.selectedIndex < 0) {
                    return 1;
                }

                var option = distributorInput.options[distributorInput.selectedIndex];
                var raw = String((option && option.dataset ? option.dataset.contractTermYears : '') || '1').trim();
                var years = parseInt(raw, 10);
                if (isNaN(years) || years < 1 || years > 3) {
                    return 1;
                }

                return years;
            }

            function syncEndDateFromStatus() {
                var protectedStatus = isProtectedStatus(statusInput ? statusInput.value : '');
                if (!protectedStatus) {
                    endInput.value = '';
                    return;
                }

                var endValue = addYears(startInput.value, selectedDistributorTermYears());
                endInput.value = endValue;
            }

            startInput.addEventListener('change', syncEndDateFromStatus);
            if (statusInput) {
                statusInput.addEventListener('change', syncEndDateFromStatus);
            }
            if (distributorInput) {
                distributorInput.addEventListener('change', syncEndDateFromStatus);
            }
        }

        function bindProviderDetailsToggle() {
            var toggle = document.getElementById('providersDetailToggle');
            var table = document.getElementById('providersTable');
            if (!toggle || !table) {
                return;
            }

            function apply(showDetails) {
                table.classList.toggle('providers-compact', !showDetails);
                toggle.textContent = showDetails ? 'Hide Details' : 'Show Details';
                toggle.setAttribute('aria-pressed', showDetails ? 'true' : 'false');
                toggle.setAttribute('aria-label', showDetails ? 'Hide provider detail columns' : 'Show provider detail columns');
            }

            apply(false);
            toggle.addEventListener('click', function () {
                var isCompact = table.classList.contains('providers-compact');
                apply(isCompact);
            });
        }

        var forms = document.querySelectorAll('form.grid');
        forms.forEach(bindProviderDateRule);
        bindProviderDetailsToggle();
    })();
    </script>
    <?php endif; ?>

    <?php if ((string)$tableName === 'deals'): ?>
    <script>
    (function () {
        function todayIso() {
            return new Date().toISOString().slice(0, 10);
        }

        function addYears(dateText, years) {
            if (!dateText) {
                return '';
            }

            var dt = new Date(dateText + 'T00:00:00');
            if (isNaN(dt.getTime())) {
                return '';
            }

            dt.setFullYear(dt.getFullYear() + years);
            return dt.toISOString().slice(0, 10);
        }

        function bindDealCloseDateRule(form) {
            var stageInput = form.querySelector('select[name="stage"]');
            var closeDateInput = form.querySelector('input[name="close_date"]');
            var providerSyncInput = form.querySelector('input[name="sync_provider_end_date"]');
            var distributorInput = form.querySelector('select[name="distributor_id"], select[name="distID"], select[name="distributorID"]');
            if (!stageInput || !closeDateInput) {
                return;
            }

            var initialStage = String(stageInput.value || '').trim().toLowerCase();

            function syncCloseDateReadOnly() {
                var stage = String(stageInput.value || '').trim().toLowerCase();
                var isClosed = stage === 'closed';
                closeDateInput.readOnly = !isClosed;
                closeDateInput.style.backgroundColor = isClosed ? '' : '#f8fafc';
            }

            stageInput.addEventListener('change', function () {
                var stage = String(stageInput.value || '').trim().toLowerCase();
                if (stage === 'closed') {
                    closeDateInput.value = todayIso();
                }
                syncCloseDateReadOnly();
            });

            form.addEventListener('submit', function (event) {
                if (!providerSyncInput) {
                    return;
                }

                providerSyncInput.value = '0';
                var stage = String(stageInput.value || '').trim().toLowerCase();
                var changedToClosed = stage === 'closed' && initialStage !== 'closed';
                if (!changedToClosed) {
                    return;
                }

                var termYears = 1;
                if (distributorInput && distributorInput.selectedIndex >= 0) {
                    var selectedOption = distributorInput.options[distributorInput.selectedIndex];
                    var rawTerm = String((selectedOption && selectedOption.dataset ? selectedOption.dataset.contractTermYears : '') || '1').trim();
                    var parsedTerm = parseInt(rawTerm, 10);
                    if (!isNaN(parsedTerm) && parsedTerm >= 1 && parsedTerm <= 3) {
                        termYears = parsedTerm;
                    }
                }

                var closeDateForCalc = String(closeDateInput.value || '').trim();
                if (!closeDateForCalc) {
                    closeDateForCalc = todayIso();
                }

                var projectedProviderEndDate = addYears(closeDateForCalc, termYears);
                var distributorContractEndDate = '';
                if (distributorInput && distributorInput.selectedIndex >= 0) {
                    var selectedDistOption = distributorInput.options[distributorInput.selectedIndex];
                    distributorContractEndDate = String((selectedDistOption && selectedDistOption.dataset ? selectedDistOption.dataset.contractEndDate : '') || '').trim();
                }

                if (projectedProviderEndDate && distributorContractEndDate) {
                    var projectedTs = Date.parse(projectedProviderEndDate + 'T00:00:00');
                    var contractEndTs = Date.parse(distributorContractEndDate + 'T00:00:00');
                    if (!isNaN(projectedTs) && !isNaN(contractEndTs) && projectedTs > contractEndTs) {
                        var overrunWarning = window.confirm(
                            'Warning: projected provider end date (' + projectedProviderEndDate + ') is after distributor contract end date (' + distributorContractEndDate + '). Continue closing this deal?'
                        );
                        if (!overrunWarning) {
                            providerSyncInput.value = '0';
                            event.preventDefault();
                            return;
                        }
                    }
                }

                var yearLabel = termYears === 1 ? 'year' : 'years';
                var confirmUpdate = window.confirm('Stage changed to Closed. Recalculate provider end date from the deal close date plus ' + termYears + ' ' + yearLabel + '?');
                providerSyncInput.value = confirmUpdate ? '1' : '0';
            });

            syncCloseDateReadOnly();
        }

        var forms = document.querySelectorAll('form.grid');
        forms.forEach(bindDealCloseDateRule);
    })();
    </script>
    <?php endif; ?>
<?php if (!$listInSeparateCard): ?>
</section>
<?php endif; ?>
<?php
$content = (string)ob_get_clean();
require __DIR__ . '/../layouts/app.php';
