<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $content */

$currentUser = is_array($currentUser ?? null) ? $currentUser : null;
$currentRole = (string)($currentUser['role'] ?? '');
$isInternal = in_array($currentRole, ['internal_admin', 'internal_read_only'], true);
$isAgentViewer = $currentRole === 'agent_viewer';
$canViewReports = $isInternal;
$canViewAgents = $isInternal;
$canViewCoreLists = $isInternal || $isAgentViewer;
$canManageUsers = $currentRole === 'internal_admin';
$displayName = trim((string)($currentUser['display_name'] ?? ''));
if ($displayName === '') {
    $displayName = trim((string)($currentUser['email'] ?? ''));
}

$roleLabel = match ($currentRole) {
    'internal_admin' => 'Internal Admin',
    'internal_read_only' => 'Internal Read-Only',
    'agent_viewer' => 'Agent Viewer',
    default => '',
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?> | Distributor and Deal Manager</title>
    <style>
        :root {
            --bg: #f3f5f7;
            --card: #ffffff;
            --ink: #1f2933;
            --muted: #6b7280;
            --brand: #0f766e;
            --brand-2: #155e75;
            --line: #dbe2ea;
            --glow: #d1fae5;
            --th-bg: #f8fafc;
            --field-bg: #ffffff;
            --field-ink: #1f2933;
            --chip-bg: rgba(255,255,255,.18);
            --chip-border: rgba(255,255,255,.22);
            --row-alt: #f8fafc;
        }
        body[data-theme="dark"] {
            --bg: #0f1722;
            --card: #111827;
            --ink: #e5e7eb;
            --muted: #9ca3af;
            --line: #3c4b63;
            --glow: rgba(20, 184, 166, 0.2);
            --th-bg: #1b2637;
            --field-bg: #0b1320;
            --field-ink: #e5e7eb;
            --chip-bg: rgba(15,23,34,.55);
            --chip-border: rgba(148,163,184,.35);
            --row-alt: #151f2f;
        }
        body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: var(--bg); color: var(--ink); }
        .shell { max-width: 1120px; margin: 0 auto; padding: 20px; }
        .topbar { background: linear-gradient(120deg, var(--brand), var(--brand-2)); color: #fff; border-radius: 14px; padding: 16px 20px; box-shadow: 0 10px 30px rgba(15,118,110,.2); }
        .topbar h1 { margin: 0; font-size: 24px; }
        .topbar .app-mini { margin: 2px 0 0; font-size: 12px; opacity: .85; letter-spacing: .02em; }
        .topbar p { margin: 6px 0 0; opacity: .9; }
        .nav { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .nav a { color: #fff; text-decoration: none; background: var(--chip-bg); border: 1px solid var(--chip-border); padding: 7px 12px; border-radius: 999px; font-size: 13px; }
        .theme-toggle { margin-left: auto; background: rgba(2, 6, 23, .35); border: 1px solid rgba(255,255,255,.35); color: #fff; border-radius: 999px; padding: 7px 12px; font-size: 13px; }
        .theme-toggle:hover { background: rgba(2, 6, 23, .5); }
        .card { background: var(--card); border-radius: 14px; padding: 18px; margin-top: 16px; border: 1px solid var(--line); box-shadow: 0 8px 20px rgba(31,41,51,.06); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; border-bottom: 1px solid var(--line); padding: 10px 8px; font-size: 14px; }
        th { background: var(--th-bg); }
        tbody tr:nth-child(even) td { background: var(--row-alt); }
        input, select, button { padding: 10px; border: 1px solid var(--line); border-radius: 8px; font: inherit; box-sizing: border-box; max-width: 100%; background: var(--field-bg); color: var(--field-ink); }
        button { background: var(--brand); color: #fff; border: 0; cursor: pointer; }
        .btn-muted { background: #64748b; }
        .btn-danger { background: #b91c1c; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
        .row-actions { display: flex; gap: 8px; align-items: center; }
        .table-actions { gap: 12px; }
        .table-actions .action-link-btn { display: inline-block; text-decoration: none; background: #64748b; color: #fff; border: 1px solid #526176; padding: 6px 10px; border-radius: 8px; font-size: 12px; line-height: 1.2; }
        .table-actions .btn-delete-small { padding: 6px 8px; font-size: 12px; line-height: 1.2; }
        .form-actions { grid-column: 1 / -1; display: flex; gap: 10px; align-items: center; }
        .table-wrap { overflow-x: auto; width: 100%; }
        .table-wrap table { min-width: 980px; }
        .table-wrap table.providers-table { min-width: 760px; }
        .table-wrap table.distributors-table { min-width: 760px; }
        .stacked-col-cell { text-align: center; vertical-align: middle; }
        .stacked-pair { display: flex; flex-direction: column; gap: 6px; align-items: center; }
        .stacked-pair > div { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; }
        .providers-detail-toggle { margin-left: auto; }
        .distributors-detail-toggle { margin-left: auto; }
        .providers-table.providers-compact th.providers-optional-col,
        .providers-table.providers-compact td.providers-optional-col { display: none; }
        .distributors-table.distributors-compact th.distributors-optional-col,
        .distributors-table.distributors-compact td.distributors-optional-col { display: none; }
        @media (max-width: 900px) {
            .table-wrap table { min-width: 860px; }
            .table-wrap table.providers-table { min-width: 620px; }
            .table-wrap table.distributors-table { min-width: 620px; }
            th, td { padding: 8px 6px; font-size: 13px; }
            .badge { font-size: 11px; padding: 2px 8px; }
            .table-actions .action-link-btn, .table-actions .btn-delete-small { font-size: 11px; }
        }
        .badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; line-height: 1.4; }
        .badge-active { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .badge-inactive { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .badge-pending { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .badge-open { background: #fce7f3; color: #9d174d; border: 1px solid #f9a8d4; }
        .badge-unknown { background: #e5e7eb; color: #374151; border: 1px solid #d1d5db; }
        .contract-warning-row td { background: #fff7ed !important; }
        .contract-warning-row td:first-child { box-shadow: inset 4px 0 0 #ea580c; }
        body[data-theme="dark"] .contract-warning-row td { background: #3b1f13 !important; }
        body[data-theme="dark"] .contract-warning-row td:first-child { box-shadow: inset 4px 0 0 #fb923c; }
        .muted { color: var(--muted); font-size: 13px; }
        .kpi { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
        .kpi .item { background: var(--th-bg); border: 1px solid var(--line); border-radius: 10px; padding: 12px; }
        .kpi .num { font-size: 24px; font-weight: 700; margin-top: 4px; }
    </style>
</head>
<body>
<div class="shell">
    <div class="topbar">
        <h1>Diablo Data</h1>
        <div class="app-mini">Taking the devil out of the details</div>
        <p>Distributor and Deal Manager</p>
        <nav class="nav">
            <?php if ($currentUser === null): ?>
                <a href="/login">Login</a>
            <?php else: ?>
                <a href="/">Dashboard</a>
                <?php if ($canViewCoreLists): ?>
                    <a href="/distributors">Distributors</a>
                    <?php if ($canViewAgents): ?>
                        <a href="/agents">Agents</a>
                    <?php endif; ?>
                    <a href="/providers">Providers</a>
                    <a href="/deals">Deals</a>
                <?php endif; ?>
                <?php if ($canViewReports): ?>
                    <a href="/reports">Reports</a>
                <?php endif; ?>
                <?php if ($canManageUsers): ?>
                    <a href="/users">Users</a>
                <?php endif; ?>
                <?php if ($displayName !== '' || $roleLabel !== ''): ?>
                    <span style="padding: 7px 12px; border-radius: 999px; border: 1px solid var(--chip-border); background: var(--chip-bg); font-size: 13px;">
                        <?= htmlspecialchars(trim($displayName . ($roleLabel !== '' ? ' (' . $roleLabel . ')' : ''))) ?>
                    </span>
                <?php endif; ?>
                <form method="post" action="/logout" style="margin: 0;">
                    <?= $csrfField() ?>
                    <button type="submit" class="theme-toggle" style="margin-left: 0;">Logout</button>
                </form>
            <?php endif; ?>
            <button type="button" id="themeToggle" class="theme-toggle" aria-label="Toggle theme" aria-pressed="false">Switch to Dark</button>
        </nav>
    </div>

    <?= $content ?>
</div>
<script>
(function () {
    var storageKey = 'ddm-theme';
    var toggle = document.getElementById('themeToggle');

    function applyTheme(theme) {
        document.body.setAttribute('data-theme', theme);
        if (!toggle) {
            return;
        }

        var isDark = theme === 'dark';
        toggle.textContent = isDark ? 'Switch to Light' : 'Switch to Dark';
        toggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
    }

    var savedTheme = localStorage.getItem(storageKey);
    if (savedTheme !== 'light' && savedTheme !== 'dark') {
        savedTheme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark'
            : 'light';
    }

    applyTheme(savedTheme);

    if (!toggle) {
        return;
    }

    toggle.addEventListener('click', function () {
        var current = document.body.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        var next = current === 'dark' ? 'light' : 'dark';
        localStorage.setItem(storageKey, next);
        applyTheme(next);
    });
})();
</script>
</body>
</html>
