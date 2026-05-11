<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\EntityController;
use App\Controllers\UserAdminController;
use App\Core\Router;

return static function (Router $router): void {
    $dashboard = new DashboardController();
    $auth = new AuthController();
    $entity = new EntityController();
    $users = new UserAdminController();

    $router->get('/', fn(array $r, array $c) => $dashboard->index($r, $c));

    $router->get('/login', fn(array $r, array $c) => $auth->loginForm($r, $c));
    $router->post('/login', fn(array $r, array $c) => $auth->login($r, $c));
    $router->post('/logout', fn(array $r, array $c) => $auth->logout($r, $c));

    $router->get('/users', fn(array $r, array $c) => $users->index($r, $c));
    $router->post('/users', fn(array $r, array $c) => $users->create($r, $c));
    $router->post('/users/update', fn(array $r, array $c) => $users->update($r, $c));

    $router->get('/distributors', fn(array $r, array $c) => $entity->list('distributors', 'Distributors', 'entity/list', $r, $c));
    $router->get('/distributors/download-csv', fn(array $r, array $c) => $entity->downloadEntityCsv('distributors', $r, $c));
    $router->post('/distributors', fn(array $r, array $c) => $entity->create('distributors', '/distributors', $r, $c));
    $router->post('/distributors/update', fn(array $r, array $c) => $entity->update('distributors', '/distributors', $r, $c));
    $router->post('/distributors/delete', fn(array $r, array $c) => $entity->delete('distributors', '/distributors', $r, $c));

    $router->get('/deals', fn(array $r, array $c) => $entity->list('deals', 'Deals', 'entity/list', $r, $c));
    $router->get('/deals/download-csv', fn(array $r, array $c) => $entity->downloadEntityCsv('deals', $r, $c));
    $router->post('/deals', fn(array $r, array $c) => $entity->create('deals', '/deals', $r, $c));
    $router->post('/deals/update', fn(array $r, array $c) => $entity->update('deals', '/deals', $r, $c));
    $router->post('/deals/delete', fn(array $r, array $c) => $entity->delete('deals', '/deals', $r, $c));

    $router->get('/providers', fn(array $r, array $c) => $entity->list('providers', 'Providers', 'entity/list', $r, $c));
    $router->get('/providers/download-csv', fn(array $r, array $c) => $entity->downloadEntityCsv('providers', $r, $c));
    $router->post('/providers', fn(array $r, array $c) => $entity->create('providers', '/providers', $r, $c));
    $router->post('/providers/update', fn(array $r, array $c) => $entity->update('providers', '/providers', $r, $c));
    $router->post('/providers/delete', fn(array $r, array $c) => $entity->delete('providers', '/providers', $r, $c));

    $router->get('/agents', fn(array $r, array $c) => $entity->list('agent', 'Agents', 'entity/list', $r, $c));
    $router->post('/agents', fn(array $r, array $c) => $entity->create('agent', '/agents', $r, $c));
    $router->post('/agents/import-deals', fn(array $r, array $c) => $entity->importDealsFromSpreadsheet($r, $c));
    $router->post('/agents/import-providers', fn(array $r, array $c) => $entity->importProvidersFromSpreadsheet($r, $c));
    $router->post('/agents/update', fn(array $r, array $c) => $entity->update('agent', '/agents', $r, $c));
    $router->post('/agents/delete', fn(array $r, array $c) => $entity->delete('agent', '/agents', $r, $c));

    $router->get('/reports', fn(array $r, array $c) => $entity->list('deals', 'Reports', 'reports/index', $r, $c));
    $router->get('/reports/download', fn(array $r, array $c) => $entity->downloadReportCsv($r, $c));
};
