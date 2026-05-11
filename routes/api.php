<?php

declare(strict_types=1);

use App\Controllers\EntityController;
use App\Core\Router;

return static function (Router $router): void {
    $entity = new EntityController();

    $router->get('/api/distributors', fn(array $r, array $c) => $entity->listJson('distributors', $r, $c));
    $router->get('/api/deals', fn(array $r, array $c) => $entity->listJson('deals', $r, $c));
    $router->get('/api/providers', fn(array $r, array $c) => $entity->listJson('providers', $r, $c));
    $router->get('/api/agents', fn(array $r, array $c) => $entity->listJson('agent', $r, $c));
};
