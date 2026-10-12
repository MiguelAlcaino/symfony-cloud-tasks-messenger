<?php

declare(strict_types=1);

use MiguelAlcaino\CloudTasksMessenger\Controller\PushController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('cloud_tasks_messenger_push', '/_messenger/cloud-tasks')
        ->controller(PushController::class)
        ->methods(['POST'])
        ->format('json');
};
