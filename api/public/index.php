<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use T3WithMe\Action\PingAction;
use T3WithMe\Action\StatsAction;
use T3WithMe\Action\StreamAction;
use T3WithMe\Middleware\CorsMiddleware;

require __DIR__ . '/../vendor/autoload.php';

$settings = require __DIR__ . '/../config/settings.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions([
    'settings' => $settings,
    PDO::class => function () use ($settings) {
        $db = $settings['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']);
        $pdo = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    },
    PingAction::class => function (PDO $pdo) use ($settings) {
        return new PingAction($pdo, $settings);
    },
]);
$container = $containerBuilder->build();

$app = AppFactory::createFromContainer($container);

$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(false, true, true);
$app->add(new CorsMiddleware($settings['cors_origin']));

$app->post('/v1/ping', PingAction::class);
$app->get('/v1/stats', StatsAction::class);
$app->get('/v1/stream', StreamAction::class);

$app->get('/v1/health', function ($request, $response) {
    $response->getBody()->write(json_encode(['status' => 'ok']));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
