<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Repositories\EntityRepository;

final class DashboardController
{
    public function index(array $request, array $config): string
    {
        $authUser = Auth::requireUser($config);
        $repo = new EntityRepository(Database::pdo($config));
        $scopedDistributorId = Auth::scopeDistributorId($authUser);

        return View::render('dashboard/index', [
            'title' => 'Dashboard',
            'appName' => $config['name'] ?? 'Distributor and Deal Manager',
            'counts' => $repo->dashboardCounts($scopedDistributorId),
            'pipeline' => $repo->dealPipelineByStage($scopedDistributorId),
            'recentDeals' => $repo->recentDeals(8, $scopedDistributorId),
            'currentUser' => $authUser,
            'canWrite' => Auth::canWrite($authUser),
        ]);
    }
}
