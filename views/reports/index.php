<?php

declare(strict_types=1);

ob_start();
$dealCount = count($rows ?? []);
$searchQuery = (string)($searchQuery ?? '');

$rows = is_array($rows ?? null) ? $rows : [];
$lookups = is_array($lookups ?? null) ? $lookups : [];
$contractAlerts = is_array($contractAlerts ?? null) ? $contractAlerts : [];
$providerReportRows = is_array($providerReportRows ?? null) ? $providerReportRows : [];
$dealsReportRows = is_array($dealsReportRows ?? null) ? $dealsReportRows : [];
$providerReportDistributorId = (int)($providerReportDistributorId ?? 0);
$dealsReportAgentId = (int)($dealsReportAgentId ?? 0);
$canWrite = (bool)($canWrite ?? false);

$lookupMap = static function (array $items): array {
    $map = [];
    foreach ($items as $item) {
        $id = (string)($item['id'] ?? '');
        $name = trim((string)($item['name'] ?? ''));
        if ($id === '') {
            continue;
        }
        $map[$id] = $name !== '' ? $name : ('#' . $id);
    }
    return $map;
};

$aggregateBy = static function (array $sourceRows, string $idColumn, array $nameMap): array {
    $buckets = [];
    foreach ($sourceRows as $row) {
        $id = trim((string)($row[$idColumn] ?? ''));
        if ($id === '') {
            $id = '__unassigned__';
            $label = '(Unassigned)';
        } else {
            $label = $nameMap[$id] ?? ('#' . $id);
        }

        if (!isset($buckets[$id])) {
            $buckets[$id] = [
                'name' => $label,
                'deal_count' => 0,
                'revenue_total' => 0.0,
            ];
        }

        $buckets[$id]['deal_count']++;
        $buckets[$id]['revenue_total'] += (float)($row['revenue'] ?? 0);
    }

    $items = array_values($buckets);
    usort($items, static function (array $a, array $b): int {
        if ($a['deal_count'] === $b['deal_count']) {
            if ($a['revenue_total'] === $b['revenue_total']) {
                return strcmp((string)$a['name'], (string)$b['name']);
            }
            return $b['revenue_total'] <=> $a['revenue_total'];
        }
        return $b['deal_count'] <=> $a['deal_count'];
    });

    return $items;
};

$distributorMap = $lookupMap((array)($lookups['distributors'] ?? []));
$providerMap = $lookupMap((array)($lookups['providers'] ?? []));
$agentMap = $lookupMap((array)($lookups['agents'] ?? []));

$byDistributor = $aggregateBy($rows, 'distributor_id', $distributorMap);
$byProvider = $aggregateBy($rows, 'provider_id', $providerMap);
$byAgent = $aggregateBy($rows, 'agent_id', $agentMap);

$distinctDistributorCount = count($byDistributor);
$distinctProviderCount = count($byProvider);
$distinctAgentCount = count($byAgent);

$totalRevenue = 0.0;
foreach ($rows as $row) {
    $totalRevenue += (float)($row['revenue'] ?? 0);
}
?>
<section class="card">
    <h2>Reports</h2>

    <form method="get" action="/reports" class="row-actions" style="margin: 10px 0 14px;">
        <input name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search reports (deals)">
        <button type="submit" class="btn-muted">Search</button>
        <?php if ($searchQuery !== ''): ?>
            <a href="/reports">Clear</a>
        <?php endif; ?>
    </form>
    <div class="kpi">
        <div class="item">
            <div class="muted"><?= $searchQuery !== '' ? 'Filtered deals' : 'Deals loaded' ?></div>
            <div class="num"><?= (int)$dealCount ?></div>
        </div>
        <div class="item">
            <div class="muted">Distinct distributors</div>
            <div class="num"><?= (int)$distinctDistributorCount ?></div>
        </div>
        <div class="item">
            <div class="muted">Distinct providers</div>
            <div class="num"><?= (int)$distinctProviderCount ?></div>
        </div>
        <div class="item">
            <div class="muted">Distinct agents</div>
            <div class="num"><?= (int)$distinctAgentCount ?></div>
        </div>
        <div class="item">
            <div class="muted">Total revenue</div>
            <div class="num">$<?= number_format($totalRevenue, 2) ?></div>
        </div>
    </div>

    <div class="table-wrap" style="margin-top:14px;">
        <h3 style="margin: 0 0 8px;">Distributor Contracts Ending Within 60 Days</h3>
        <table>
            <thead>
                <tr>
                    <th>Distributor</th>
                    <th>Term</th>
                    <th>Contract Start</th>
                    <th>Contract End</th>
                    <th>Days Remaining</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($contractAlerts === []): ?>
                    <tr><td colspan="6" class="muted">No distributor contracts are ending within 60 days.</td></tr>
                <?php else: ?>
                    <?php foreach ($contractAlerts as $contract): ?>
                        <?php
                        $contractId = (int)($contract['id'] ?? 0);
                        $termYears = (int)($contract['contract_term_years'] ?? 1);
                        $termYears = in_array($termYears, [1, 2, 3], true) ? $termYears : 1;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string)($contract['name'] ?? ('#' . $contractId))) ?></td>
                            <td><?= $termYears ?> <?= $termYears === 1 ? 'year' : 'years' ?></td>
                            <td><?= htmlspecialchars((string)($contract['contract_start_date'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($contract['contract_end_date'] ?? '')) ?></td>
                            <td><?= (int)($contract['days_remaining'] ?? 0) ?></td>
                            <td>
                                <?php if ($canWrite): ?>
                                    <form method="post" action="/distributors/update" onsubmit="return confirm('Extend distributor contract end date by the configured contract term?');">
                                        <?= $csrfField() ?>
                                        <input type="hidden" name="id" value="<?= $contractId ?>">
                                        <input type="hidden" name="extend_distributor_contract" value="1">
                                        <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery) ?>">
                                        <button type="submit" class="btn-muted">Extend End Date</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">Read-only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</section>

<section class="card">
    <h2>Deal Breakdowns</h2>
    <p class="muted">Aggregated deal views by distributor, provider, and agent.</p>

    <details style="margin: 12px 0;">
        <summary style="cursor: pointer; font-weight: 600;">Deals By Distributor</summary>
        <div class="table-wrap" style="margin-top:10px;">
            <table>
                <thead>
                    <tr><th>Distributor</th><th>Deals</th><th>Revenue</th></tr>
                </thead>
                <tbody>
                    <?php if ($byDistributor === []): ?>
                        <tr><td colspan="3" class="muted">No rows available.</td></tr>
                    <?php else: ?>
                        <?php foreach ($byDistributor as $bucket): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$bucket['name']) ?></td>
                                <td><?= (int)$bucket['deal_count'] ?></td>
                                <td>$<?= number_format((float)$bucket['revenue_total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </details>

    <details style="margin: 12px 0;">
        <summary style="cursor: pointer; font-weight: 600;">Deals By Provider</summary>
        <div class="table-wrap" style="margin-top:10px;">
            <table>
                <thead>
                    <tr><th>Provider</th><th>Deals</th><th>Revenue</th></tr>
                </thead>
                <tbody>
                    <?php if ($byProvider === []): ?>
                        <tr><td colspan="3" class="muted">No rows available.</td></tr>
                    <?php else: ?>
                        <?php foreach ($byProvider as $bucket): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$bucket['name']) ?></td>
                                <td><?= (int)$bucket['deal_count'] ?></td>
                                <td>$<?= number_format((float)$bucket['revenue_total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </details>

    <details style="margin: 12px 0;">
        <summary style="cursor: pointer; font-weight: 600;">Deals By Agent</summary>
        <div class="table-wrap" style="margin-top:10px;">
            <table>
                <thead>
                    <tr><th>Agent</th><th>Deals</th><th>Revenue</th></tr>
                </thead>
                <tbody>
                    <?php if ($byAgent === []): ?>
                        <tr><td colspan="3" class="muted">No rows available.</td></tr>
                    <?php else: ?>
                        <?php foreach ($byAgent as $bucket): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$bucket['name']) ?></td>
                                <td><?= (int)$bucket['deal_count'] ?></td>
                                <td>$<?= number_format((float)$bucket['revenue_total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </details>
</section>

<section class="card">
    <h2>Detailed Reports</h2>
    <p class="muted">Filtered operational reports with CSV export.</p>

    <details style="margin: 12px 0;"<?= $providerReportDistributorId > 0 ? ' open' : '' ?>>
        <summary style="cursor: pointer; font-weight: 600;">Provider Report</summary>
        <div class="table-wrap" style="margin-top:10px;">
            <form method="get" action="/reports" class="row-actions" style="margin: 0 0 10px;">
                <select name="provider_distributor_id" aria-label="Filter provider report by distributor">
                    <option value="">All Distributors</option>
                    <?php foreach ((array)($lookups['distributors'] ?? []) as $dist): ?>
                        <?php $distId = (int)($dist['id'] ?? 0); ?>
                        <option value="<?= $distId ?>"<?= $providerReportDistributorId === $distId ? ' selected' : '' ?>>
                            <?= htmlspecialchars((string)($dist['name'] ?? ('#' . $distId))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-muted">View Report</button>
                <?php if ($providerReportDistributorId > 0): ?>
                    <a href="/reports">Clear</a>
                <?php endif; ?>
                <a class="btn-muted" href="/reports/download?type=providers<?= $providerReportDistributorId > 0 ? '&provider_distributor_id=' . $providerReportDistributorId : '' ?>">Download CSV</a>
            </form>
            <table>
                <thead>
                    <tr>
                        <th>Provider</th>
                        <th>Distributor</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Segment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($providerReportRows === []): ?>
                        <tr><td colspan="8" class="muted">No providers found for the selected filter.</td></tr>
                    <?php else: ?>
                        <?php foreach ($providerReportRows as $providerRow): ?>
                            <?php
                            $providerStatusRaw = strtolower(trim((string)($providerRow['status'] ?? '')));
                            $providerStatus = match ($providerStatusRaw) {
                                '0', 'reserved' => 'Reserved',
                                '1', 'protected' => 'Protected',
                                '2', 'open' => 'Open',
                                default => (string)($providerRow['status'] ?? ''),
                            };
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)($providerRow['provider_name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($providerRow['distributor_name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars($providerStatus) ?></td>
                                <td><?= htmlspecialchars((string)($providerRow['start_date'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($providerRow['end_date'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($providerRow['phone'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($providerRow['email'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($providerRow['segment'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </details>

    <details style="margin: 12px 0;">
        <summary style="cursor: pointer; font-weight: 600;">Deals Report</summary>
        <div class="table-wrap" style="margin-top:10px;">
            <form method="get" action="/reports" class="row-actions" style="margin: 0 0 10px;">
                <select name="report_agent_id" aria-label="Filter deals report by agent">
                    <option value="">All Agents</option>
                    <?php foreach ((array)($lookups['agents'] ?? []) as $agent): ?>
                        <?php $agentId = (int)($agent['id'] ?? 0); ?>
                        <option value="<?= $agentId ?>"<?= $dealsReportAgentId === $agentId ? ' selected' : '' ?>>
                            <?= htmlspecialchars((string)($agent['name'] ?? ('#' . $agentId))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-muted">View Report</button>
                <?php if ($dealsReportAgentId > 0): ?>
                    <a href="/reports">Clear</a>
                <?php endif; ?>
                <a class="btn-muted" href="/reports/download?type=deals<?= $dealsReportAgentId > 0 ? '&report_agent_id=' . $dealsReportAgentId : '' ?>">Download CSV</a>
            </form>
            <table>
                <thead>
                    <tr>
                        <th>Deal</th>
                        <th>Stage</th>
                        <th>Revenue</th>
                        <th>Deal Date</th>
                        <th>Close Date</th>
                        <th>Distributor</th>
                        <th>Provider</th>
                        <th>Agent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($dealsReportRows === []): ?>
                        <tr><td colspan="8" class="muted">No deals found for the selected filter.</td></tr>
                    <?php else: ?>
                        <?php foreach ($dealsReportRows as $dealReportRow): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)($dealReportRow['deal_name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($dealReportRow['stage'] ?? '')) ?></td>
                                <td>$<?= number_format((float)($dealReportRow['revenue'] ?? 0), 2) ?></td>
                                <td><?= htmlspecialchars((string)($dealReportRow['deal_date'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($dealReportRow['close_date'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($dealReportRow['distributor_name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($dealReportRow['provider_name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($dealReportRow['agent_name'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </details>
</section>
<?php
$content = (string)ob_get_clean();
require __DIR__ . '/../layouts/app.php';
