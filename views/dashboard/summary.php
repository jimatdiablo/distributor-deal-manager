<section class="card">
    <h2>Welcome</h2>
    <p class="muted">Live dashboard sourced from distdb.</p>
    <div class="kpi">
        <div class="item"><div class="muted">Distributors</div><div class="num"><?= (int)($counts['distributors'] ?? 0) ?></div></div>
        <div class="item"><div class="muted">Agents</div><div class="num"><?= (int)($counts['agents'] ?? 0) ?></div></div>
        <div class="item"><div class="muted">Providers</div><div class="num"><?= (int)($counts['providers'] ?? 0) ?></div></div>
        <div class="item"><div class="muted">Deals</div><div class="num"><?= (int)($counts['deals'] ?? 0) ?></div></div>
    </div>
</section>

<section class="card">
    <h2>Pipeline By Stage</h2>
    <table>
        <thead>
            <tr>
                <th>Stage</th>
                <th>Deals</th>
                <th>Total Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (($pipeline ?? []) as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string)($row['stage'] ?? 'unknown')) ?></td>
                    <td><?= (int)($row['deal_count'] ?? 0) ?></td>
                    <td>$<?= number_format((float)($row['total_amount'] ?? 0), 2) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($pipeline)): ?>
                <tr><td colspan="3" class="muted">No deal pipeline data yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <h2>Recent Deals</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Stage</th>
                <th>Amount</th>
                <th>Distributor</th>
                <th>Provider</th>
                <th>Agent</th>
                <th>Close Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (($recentDeals ?? []) as $row): ?>
                <tr>
                    <td><?= (int)($row['id'] ?? 0) ?></td>
                    <td><?= htmlspecialchars((string)($row['title'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string)($row['stage'] ?? '')) ?></td>
                    <td>$<?= number_format((float)($row['amount'] ?? 0), 2) ?></td>
                    <td><?= htmlspecialchars((string)($row['distributor_name'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string)($row['provider_name'] ?? '')) ?></td>
                    <td><?= htmlspecialchars(trim((string)($row['agent_name'] ?? ''))) ?></td>
                    <td><?= htmlspecialchars((string)($row['close_date'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recentDeals)): ?>
                <tr><td colspan="8" class="muted">No deals yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>
