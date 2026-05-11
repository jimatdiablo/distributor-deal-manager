<?php

declare(strict_types=1);

use App\Core\View;

$content = View::render('dashboard/summary', [
	'appName' => $appName ?? 'Distributor and Deal Manager',
	'counts' => $counts ?? [],
	'pipeline' => $pipeline ?? [],
	'recentDeals' => $recentDeals ?? [],
]);
require __DIR__ . '/../layouts/app.php';
