<?php

declare(strict_types=1);

use FastRoute\RouteCollector;

return static function (RouteCollector $routes): void {
    $routes->addRoute('GET', '/health', 'operations.health');
    $routes->addRoute('GET', '/', 'operations.setup');
};
