#!/bin/bash
set -e

SSH_HOST="olivier.dobberkau@dkd.de@a-udutje@ssh.isenstedt.project.host"
LOCAL_ROOT="/Users/olivier/Versioncontrol/local/t3-with-me"

echo "=== Deploying t3-with.me ==="

# Landing page
echo "Deploying landing page..."
scp "$LOCAL_ROOT/landing/index.html" "$SSH_HOST:html/t3-with-me/index.html"
echo "  -> https://www.t3-with.me/"

# Dashboard
echo "Deploying dashboard..."
scp "$LOCAL_ROOT/dashboard/index.html" "$SSH_HOST:html/dashboard/index.html"
scp "$LOCAL_ROOT/dashboard/land-110m.json" "$SSH_HOST:html/dashboard/land-110m.json"
echo "  -> https://dashboard.t3-with.me/"

# API
echo "Deploying API..."
scp "$LOCAL_ROOT/api/public/index.php" "/tmp/t3withme-index-prod.php"
# Build production index.php with correct paths
cat > /tmp/t3withme-index-prod.php << 'PHPEOF'
<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use T3WithMe\Action\PingAction;
use T3WithMe\Action\StatsAction;
use T3WithMe\Action\StreamAction;
use T3WithMe\Middleware\CorsMiddleware;

$appRoot = dirname(__DIR__, 2) . '/app';

require $appRoot . '/vendor/autoload.php';

$settings = require $appRoot . '/config/settings.php';

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
PHPEOF

scp /tmp/t3withme-index-prod.php "$SSH_HOST:html/api/index.php"
scp "$LOCAL_ROOT/api/public/.htaccess" "$SSH_HOST:html/api/.htaccess"
# Redirect api root to landing page
cat << 'HTMLEOF' | ssh "$SSH_HOST" "cat > ~/html/api/index.html"
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="0;url=https://www.t3-with.me/">
<title>Redirecting…</title>
</head>
<body>
<p>Redirecting to <a href="https://www.t3-with.me/">t3-with.me</a>…</p>
</body>
</html>
HTMLEOF
scp -r "$LOCAL_ROOT/api/src/Action" "$LOCAL_ROOT/api/src/Service" "$LOCAL_ROOT/api/src/Middleware" "$SSH_HOST:app/src/"
scp "$LOCAL_ROOT/api/config/settings.php" "$SSH_HOST:app/config/"
rm /tmp/t3withme-index-prod.php
echo "  -> https://api.t3-with.me/v1/health"

echo ""
echo "=== Deploy complete ==="
echo "Landing:   https://www.t3-with.me/"
echo "Dashboard: https://dashboard.t3-with.me/"
echo "API:       https://api.t3-with.me/v1/health"
