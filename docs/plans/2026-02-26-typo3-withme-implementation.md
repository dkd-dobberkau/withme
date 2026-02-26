# TYPO3 with me — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a production-ready API that receives anonymous TYPO3 install pings, resolves locations, and streams events to a live dashboard.

**Architecture:** Docker Compose stack with Slim 4 PHP API + MySQL + Nginx. Landing page and dashboard are static HTML served by the same Nginx. The Composer plugin sends pings to the API, which resolves IP to city via MaxMind GeoLite2, stores events, and streams them via SSE.

**Tech Stack:** PHP 8.3, Slim 4, MySQL 8, Nginx, Docker Compose, MaxMind GeoLite2, Server-Sent Events

---

## Task 1: Docker Compose Setup

**Files:**
- Create: `api/docker-compose.yml`
- Create: `api/Dockerfile`
- Create: `api/.env.example`
- Create: `api/.gitignore`

**Step 1: Create docker-compose.yml**

```yaml
# api/docker-compose.yml
services:
  nginx:
    image: nginx:alpine
    container_name: t3withme-nginx
    ports:
      - "8080:80"
    volumes:
      - ./nginx.conf:/etc/nginx/conf.d/default.conf
      - ./public:/var/www/html/public
      - ../landing:/var/www/html/landing
      - ../dashboard:/var/www/html/dashboard
    depends_on:
      - php
    networks:
      - t3withme
    restart: unless-stopped

  php:
    build: .
    container_name: t3withme-php
    volumes:
      - .:/var/www/html
    depends_on:
      mysql:
        condition: service_healthy
    env_file:
      - .env
    networks:
      - t3withme
    restart: unless-stopped

  mysql:
    image: mysql:8.0
    container_name: t3withme-mysql
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:-rootpass}
      MYSQL_DATABASE: ${MYSQL_DATABASE:-t3withme}
      MYSQL_USER: ${MYSQL_USER:-t3withme}
      MYSQL_PASSWORD: ${MYSQL_PASSWORD:-t3withme}
    volumes:
      - mysql_data:/var/lib/mysql
      - ./config/schema.sql:/docker-entrypoint-initdb.d/01-schema.sql
    ports:
      - "3307:3306"
    networks:
      - t3withme
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5

volumes:
  mysql_data:

networks:
  t3withme:
    driver: bridge
```

**Step 2: Create Dockerfile**

```dockerfile
# api/Dockerfile
FROM php:8.3-fpm AS builder
WORKDIR /var/www/html
RUN apt-get update && apt-get install -y unzip git && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

FROM php:8.3-fpm
RUN apt-get update && apt-get install -y libmaxminddb0 libmaxminddb-dev && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo pdo_mysql
RUN pecl install maxminddb && docker-php-ext-enable maxminddb
WORKDIR /var/www/html
COPY --from=builder /var/www/html/vendor ./vendor
COPY . .
RUN chown -R www-data:www-data /var/www/html
USER www-data
```

**Step 3: Create .env.example**

```bash
# api/.env.example
MYSQL_HOST=mysql
MYSQL_PORT=3306
MYSQL_DATABASE=t3withme
MYSQL_USER=t3withme
MYSQL_PASSWORD=t3withme
MYSQL_ROOT_PASSWORD=rootpass
CORS_ORIGIN=*
```

**Step 4: Create .gitignore**

```
# api/.gitignore
vendor/
.env
data/GeoLite2-City.mmdb
```

**Step 5: Commit**

```bash
git add api/docker-compose.yml api/Dockerfile api/.env.example api/.gitignore
git commit -m "feat: add Docker Compose setup for API stack"
```

---

## Task 2: Nginx Config

**Files:**
- Create: `api/nginx.conf`

**Step 1: Create nginx.conf**

```nginx
# api/nginx.conf
server {
    listen 80;
    server_name _;

    # Landing page
    location / {
        root /var/www/html/landing;
        index index.html;
        try_files $uri $uri/ =404;
    }

    # Dashboard
    location /dashboard {
        alias /var/www/html/dashboard;
        index dashboard-prototype.html;
        try_files $uri $uri/ =404;
    }

    # API
    location /v1/ {
        root /var/www/html/public;
        fastcgi_pass php:9000;
        fastcgi_param SCRIPT_FILENAME /var/www/html/public/index.php;
        fastcgi_param REQUEST_URI $request_uri;
        fastcgi_param REMOTE_ADDR $remote_addr;
        include fastcgi_params;

        # SSE support
        fastcgi_buffering off;
        fastcgi_keep_conn on;
        proxy_read_timeout 300s;
    }
}
```

**Step 2: Commit**

```bash
git add api/nginx.conf
git commit -m "feat: add Nginx config with API, landing, dashboard routing"
```

---

## Task 3: MySQL Schema

**Files:**
- Create: `api/config/schema.sql`

**Step 1: Create schema.sql**

```sql
-- api/config/schema.sql
CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    typo3_version VARCHAR(20) NOT NULL,
    php_version VARCHAR(10) NOT NULL,
    event_type ENUM('new_install', 'install', 'update') NOT NULL,
    project_hash CHAR(16) NOT NULL,
    os VARCHAR(20) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    country CHAR(2) DEFAULT NULL,
    latitude DECIMAL(8, 4) DEFAULT NULL,
    longitude DECIMAL(9, 4) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_project (project_hash),
    INDEX idx_version (typo3_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limits (
    ip_hash CHAR(64) PRIMARY KEY,
    request_count INT UNSIGNED DEFAULT 1,
    window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Step 2: Commit**

```bash
git add api/config/schema.sql
git commit -m "feat: add MySQL schema for events and rate limits"
```

---

## Task 4: Slim 4 Skeleton + Composer Dependencies

**Files:**
- Create: `api/composer.json`
- Create: `api/public/index.php`
- Create: `api/config/settings.php`
- Create: `api/public/.htaccess`

**Step 1: Create api/composer.json**

```json
{
    "name": "typo3/withme-api",
    "description": "TYPO3 with me API server",
    "type": "project",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": "^8.2",
        "slim/slim": "^4.14",
        "slim/psr7": "^1.7",
        "php-di/php-di": "^7.0",
        "php-di/slim-bridge": "^3.4",
        "vlucas/phpdotenv": "^5.6",
        "geoip2/geoip2": "^3.0"
    },
    "autoload": {
        "psr-4": {
            "T3WithMe\\": "src/"
        }
    }
}
```

**Step 2: Create api/config/settings.php**

```php
<?php
// api/config/settings.php
declare(strict_types=1);

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

return [
    'db' => [
        'host' => $_ENV['MYSQL_HOST'] ?? 'mysql',
        'port' => (int) ($_ENV['MYSQL_PORT'] ?? 3306),
        'database' => $_ENV['MYSQL_DATABASE'] ?? 't3withme',
        'username' => $_ENV['MYSQL_USER'] ?? 't3withme',
        'password' => $_ENV['MYSQL_PASSWORD'] ?? 't3withme',
    ],
    'cors_origin' => $_ENV['CORS_ORIGIN'] ?? '*',
    'geoip_db' => __DIR__ . '/../data/GeoLite2-City.mmdb',
    'rate_limit' => [
        'max_requests' => 10,
        'window_seconds' => 60,
    ],
];
```

**Step 3: Create api/public/index.php**

```php
<?php
// api/public/index.php
declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use T3WithMe\Action\PingAction;
use T3WithMe\Action\StatsAction;
use T3WithMe\Action\StreamAction;
use T3WithMe\Middleware\CorsMiddleware;

require __DIR__ . '/../vendor/autoload.php';

$settings = require __DIR__ . '/../config/settings.php';

// Build DI container
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
]);
$container = $containerBuilder->build();

// Create app
$app = AppFactory::createFromContainer($container);

// Middleware
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(false, true, true);
$app->add(new CorsMiddleware($settings['cors_origin']));

// Routes
$app->post('/v1/ping', PingAction::class);
$app->get('/v1/stats', StatsAction::class);
$app->get('/v1/stream', StreamAction::class);

// Health check
$app->get('/v1/health', function ($request, $response) {
    $response->getBody()->write(json_encode(['status' => 'ok']));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
```

**Step 4: Create api/public/.htaccess**

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

**Step 5: Commit**

```bash
git add api/composer.json api/config/settings.php api/public/index.php api/public/.htaccess
git commit -m "feat: add Slim 4 skeleton with DI container and routing"
```

---

## Task 5: CORS Middleware

**Files:**
- Create: `api/src/Middleware/CorsMiddleware.php`

**Step 1: Create CorsMiddleware.php**

```php
<?php
// api/src/Middleware/CorsMiddleware.php
declare(strict_types=1);

namespace T3WithMe\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $allowedOrigin) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response();
            return $this->addCorsHeaders($response);
        }

        $response = $handler->handle($request);
        return $this->addCorsHeaders($response);
    }

    private function addCorsHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $this->allowedOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Last-Event-ID')
            ->withHeader('Access-Control-Max-Age', '86400');
    }
}
```

**Step 2: Commit**

```bash
git add api/src/Middleware/CorsMiddleware.php
git commit -m "feat: add CORS middleware"
```

---

## Task 6: EventService (Database Layer)

**Files:**
- Create: `api/src/Service/EventService.php`

**Step 1: Create EventService.php**

```php
<?php
// api/src/Service/EventService.php
declare(strict_types=1);

namespace T3WithMe\Service;

use PDO;

class EventService
{
    public function __construct(private readonly PDO $pdo) {}

    public function insertEvent(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO events (typo3_version, php_version, event_type, project_hash, os, city, country, latitude, longitude)
             VALUES (:typo3_version, :php_version, :event_type, :project_hash, :os, :city, :country, :latitude, :longitude)'
        );
        $stmt->execute([
            'typo3_version' => $data['typo3_version'],
            'php_version' => $data['php_version'],
            'event_type' => $data['event_type'],
            'project_hash' => $data['project_hash'],
            'os' => $data['os'] ?? null,
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function getEventsSince(int $lastId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, typo3_version, php_version, event_type, city, country, latitude, longitude, created_at
             FROM events WHERE id > :last_id ORDER BY id ASC LIMIT :lim'
        );
        $stmt->bindValue('last_id', $lastId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getStats(): array
    {
        $total = $this->pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
        $today = $this->pdo->query(
            "SELECT COUNT(*) FROM events WHERE created_at >= CURDATE()"
        )->fetchColumn();

        $versions = $this->pdo->query(
            "SELECT SUBSTRING_INDEX(typo3_version, '.', 2) AS ver, COUNT(*) AS cnt
             FROM events GROUP BY ver ORDER BY cnt DESC LIMIT 10"
        )->fetchAll();

        $countries = $this->pdo->query(
            "SELECT country, COUNT(*) AS cnt FROM events
             WHERE country IS NOT NULL GROUP BY country ORDER BY cnt DESC LIMIT 20"
        )->fetchAll();

        $recent = $this->pdo->query(
            "SELECT id, typo3_version, php_version, event_type, city, country, latitude, longitude, created_at
             FROM events ORDER BY id DESC LIMIT 10"
        )->fetchAll();

        return [
            'total_installs' => (int) $total,
            'today' => (int) $today,
            'versions' => array_column($versions, 'cnt', 'ver'),
            'countries' => array_column($countries, 'cnt', 'country'),
            'recent' => $recent,
        ];
    }

    public function checkRateLimit(string $ipHash, int $maxRequests, int $windowSeconds): bool
    {
        $this->pdo->exec(
            "DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL {$windowSeconds} SECOND"
        );

        $stmt = $this->pdo->prepare(
            'SELECT request_count FROM rate_limits WHERE ip_hash = :ip_hash'
        );
        $stmt->execute(['ip_hash' => $ipHash]);
        $row = $stmt->fetch();

        if (!$row) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO rate_limits (ip_hash, request_count, window_start) VALUES (:ip_hash, 1, NOW())'
            );
            $stmt->execute(['ip_hash' => $ipHash]);
            return true;
        }

        if ((int) $row['request_count'] >= $maxRequests) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE rate_limits SET request_count = request_count + 1 WHERE ip_hash = :ip_hash'
        );
        $stmt->execute(['ip_hash' => $ipHash]);
        return true;
    }
}
```

**Step 2: Commit**

```bash
git add api/src/Service/EventService.php
git commit -m "feat: add EventService with insert, query, stats, rate limiting"
```

---

## Task 7: GeoIP Service

**Files:**
- Create: `api/src/Service/GeoIpService.php`
- Create: `api/data/.gitkeep`

**Step 1: Create GeoIpService.php**

```php
<?php
// api/src/Service/GeoIpService.php
declare(strict_types=1);

namespace T3WithMe\Service;

use GeoIp2\Database\Reader;

class GeoIpService
{
    private ?Reader $reader = null;

    public function __construct(private readonly string $dbPath) {}

    public function resolve(string $ip): array
    {
        if (!file_exists($this->dbPath)) {
            return ['city' => null, 'country' => null, 'latitude' => null, 'longitude' => null];
        }

        try {
            if ($this->reader === null) {
                $this->reader = new Reader($this->dbPath);
            }
            $record = $this->reader->city($ip);
            return [
                'city' => $record->city->name,
                'country' => $record->country->isoCode,
                'latitude' => $record->location->latitude,
                'longitude' => $record->location->longitude,
            ];
        } catch (\Exception) {
            return ['city' => null, 'country' => null, 'latitude' => null, 'longitude' => null];
        }
    }
}
```

**Step 2: Create data directory placeholder**

```bash
mkdir -p api/data && touch api/data/.gitkeep
```

Note: The GeoLite2-City.mmdb file must be downloaded manually from MaxMind (free account required at https://www.maxmind.com/en/geolite2/signup) and placed in `api/data/`.

**Step 3: Commit**

```bash
git add api/src/Service/GeoIpService.php api/data/.gitkeep
git commit -m "feat: add GeoIP service with MaxMind GeoLite2 reader"
```

---

## Task 8: PingAction (POST /v1/ping)

**Files:**
- Create: `api/src/Action/PingAction.php`

**Step 1: Create PingAction.php**

```php
<?php
// api/src/Action/PingAction.php
declare(strict_types=1);

namespace T3WithMe\Action;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use T3WithMe\Service\EventService;
use T3WithMe\Service\GeoIpService;

class PingAction
{
    private EventService $eventService;
    private GeoIpService $geoIpService;
    private array $settings;

    public function __construct(PDO $pdo, array $settings)
    {
        $this->eventService = new EventService($pdo);
        $this->geoIpService = new GeoIpService($settings['geoip_db']);
        $this->settings = $settings;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->jsonError($response, 'Invalid JSON body', 400);
        }

        // Validate required fields
        $errors = $this->validate($body);
        if (!empty($errors)) {
            return $this->jsonError($response, implode(', ', $errors), 422);
        }

        // Rate limiting
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        $ipHash = hash('sha256', $ip);
        $rl = $this->settings['rate_limit'];
        if (!$this->eventService->checkRateLimit($ipHash, $rl['max_requests'], $rl['window_seconds'])) {
            return $this->jsonError($response, 'Rate limit exceeded', 429);
        }

        // Resolve IP to location
        $geo = $this->geoIpService->resolve($ip);

        // Store event (IP is NOT stored)
        $this->eventService->insertEvent([
            'typo3_version' => $body['typo3_version'],
            'php_version' => $body['php_version'],
            'event_type' => $body['event'],
            'project_hash' => $body['project_hash'],
            'os' => $body['os'] ?? null,
            'city' => $geo['city'],
            'country' => $geo['country'],
            'latitude' => $geo['latitude'],
            'longitude' => $geo['longitude'],
        ]);

        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    private function validate(array $body): array
    {
        $errors = [];

        if (empty($body['typo3_version']) || !preg_match('/^\d+\.\d+(\.\d+)?$/', $body['typo3_version'])) {
            $errors[] = 'Invalid typo3_version';
        }
        if (empty($body['php_version']) || !preg_match('/^\d+\.\d+$/', $body['php_version'])) {
            $errors[] = 'Invalid php_version';
        }
        if (empty($body['event']) || !in_array($body['event'], ['new_install', 'install', 'update'], true)) {
            $errors[] = 'Invalid event type';
        }
        if (empty($body['project_hash']) || !preg_match('/^[a-f0-9]{16}$/', $body['project_hash'])) {
            $errors[] = 'Invalid project_hash';
        }

        return $errors;
    }

    private function jsonError(Response $response, string $message, int $status): Response
    {
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
```

**Step 2: Commit**

```bash
git add api/src/Action/PingAction.php
git commit -m "feat: add PingAction with validation, rate limiting, GeoIP"
```

---

## Task 9: StatsAction (GET /v1/stats)

**Files:**
- Create: `api/src/Action/StatsAction.php`

**Step 1: Create StatsAction.php**

```php
<?php
// api/src/Action/StatsAction.php
declare(strict_types=1);

namespace T3WithMe\Action;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use T3WithMe\Service\EventService;

class StatsAction
{
    private EventService $eventService;
    private static ?array $cache = null;
    private static float $cacheTime = 0;

    public function __construct(PDO $pdo)
    {
        $this->eventService = new EventService($pdo);
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $now = microtime(true);
        if (self::$cache === null || ($now - self::$cacheTime) > 60) {
            self::$cache = $this->eventService->getStats();
            self::$cacheTime = $now;
        }

        $response->getBody()->write(json_encode(self::$cache, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

**Step 2: Commit**

```bash
git add api/src/Action/StatsAction.php
git commit -m "feat: add StatsAction with 60s in-memory cache"
```

---

## Task 10: StreamAction (GET /v1/stream — SSE)

**Files:**
- Create: `api/src/Action/StreamAction.php`

**Step 1: Create StreamAction.php**

```php
<?php
// api/src/Action/StreamAction.php
declare(strict_types=1);

namespace T3WithMe\Action;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use T3WithMe\Service\EventService;

class StreamAction
{
    private EventService $eventService;

    public function __construct(PDO $pdo)
    {
        $this->eventService = new EventService($pdo);
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $lastId = (int) ($request->getHeaderLine('Last-Event-ID') ?: $request->getQueryParams()['lastId'] ?? 0);

        // If no lastId, start from the latest event
        if ($lastId === 0) {
            $recent = $this->eventService->getEventsSince(0, 1);
            // Placeholder: we return empty and let the client catch up via stats
        }

        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // Disable output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }

        $maxTime = 55; // Stay under typical 60s timeout
        $start = time();

        while ((time() - $start) < $maxTime) {
            $events = $this->eventService->getEventsSince($lastId);

            foreach ($events as $event) {
                echo "id: {$event['id']}\n";
                echo "data: " . json_encode([
                    'city' => $event['city'],
                    'country' => $event['country'],
                    'version' => $event['typo3_version'],
                    'event' => $event['event_type'],
                    'lat' => (float) $event['latitude'],
                    'lng' => (float) $event['longitude'],
                ]) . "\n\n";
                $lastId = (int) $event['id'];
            }

            if (connection_aborted()) {
                break;
            }

            flush();
            sleep(2);
        }

        // Send reconnect hint
        echo "retry: 3000\n\n";
        flush();

        // Exit to prevent Slim from trying to send response
        exit;
    }
}
```

**Step 2: Commit**

```bash
git add api/src/Action/StreamAction.php
git commit -m "feat: add SSE StreamAction with 55s keep-alive and auto-reconnect"
```

---

## Task 11: Composer Plugin Fix

**Files:**
- Move: `withme/Plugin.php` -> `withme/src/Plugin.php`

**Step 1: Move Plugin.php to match autoload path**

```bash
mkdir -p withme/src
mv withme/Plugin.php withme/src/Plugin.php
```

**Step 2: Update endpoint to be configurable**

In `withme/src/Plugin.php`, change the ENDPOINT constant to read from composer extra config, falling back to the default:

Replace:
```php
private const ENDPOINT = 'https://api.typo3withme.org/v1/ping';
```

With a method that reads config:
```php
private const DEFAULT_ENDPOINT = 'https://api.typo3withme.org/v1/ping';

private function getEndpoint(): string
{
    $extra = $this->composer->getPackage()->getExtra();
    return $extra['typo3/withme']['endpoint'] ?? self::DEFAULT_ENDPOINT;
}
```

Update `sendPing()` to use `$this->getEndpoint()` instead of `self::ENDPOINT`.

**Step 3: Remove redundant composer field from payload**

In `buildPayload()`, remove the `'composer' => Composer::getVersion()` line.

**Step 4: Commit**

```bash
git add withme/
git commit -m "fix: move Plugin.php to src/, make endpoint configurable"
```

---

## Task 12: Build and Test Locally

**Step 1: Copy .env.example to .env**

```bash
cp api/.env.example api/.env
```

**Step 2: Install composer dependencies locally**

```bash
cd api && composer install && cd ..
```

**Step 3: Build and start containers**

```bash
cd api && docker compose up --build -d
```

**Step 4: Wait for MySQL to be ready, then test health endpoint**

```bash
curl http://localhost:8080/v1/health
```

Expected: `{"status":"ok"}`

**Step 5: Test ping endpoint**

```bash
curl -X POST http://localhost:8080/v1/ping \
  -H "Content-Type: application/json" \
  -d '{"typo3_version":"13.4.2","php_version":"8.3","event":"new_install","project_hash":"a1b2c3d4e5f6g7h8","os":"Linux"}'
```

Expected: HTTP 201

**Step 6: Test stats endpoint**

```bash
curl http://localhost:8080/v1/stats
```

Expected: JSON with `total_installs: 1` and the event in `recent`

**Step 7: Test SSE stream**

```bash
curl -N http://localhost:8080/v1/stream
```

Expected: SSE stream (will wait for new events). Send another ping in a second terminal to see it appear.

**Step 8: Test landing page and dashboard**

```
http://localhost:8080/           -> Landing Page
http://localhost:8080/dashboard  -> Dashboard
```

**Step 9: Commit any fixes**

```bash
git add -A && git commit -m "chore: verify local Docker stack works end-to-end"
```

---

## Task 13: Landing Page Bug Fix

**Files:**
- Modify: `landing/index.html`

**Step 1: Fix the self-referencing --border variable in :root**

In `landing/index.html` line 23, replace:
```css
--border: var(--border);
```

With:
```css
--border: rgba(255, 255, 255, 0.04);
```

**Step 2: Commit**

```bash
git add landing/index.html
git commit -m "fix: resolve --border CSS variable self-reference in landing page"
```

---

## Task 14: Update Design Doc

**Files:**
- Modify: `docs/plans/2026-02-26-typo3-withme-mvp-design.md`

**Step 1: Update the Deployment section**

Replace the "Deployment on Mittwald" section with Docker-based deployment info reflecting the actual architecture.

**Step 2: Commit**

```bash
git add docs/
git commit -m "docs: update design doc with Docker-based architecture"
```

---

## Summary

| Task | Component | Result |
|---|---|---|
| 1-2 | Docker + Nginx | Container stack runs |
| 3 | MySQL Schema | Database ready |
| 4-5 | Slim 4 + CORS | API framework running |
| 6 | EventService | Database layer complete |
| 7 | GeoIpService | IP resolution ready |
| 8 | PingAction | Pings can be received |
| 9 | StatsAction | Stats can be queried |
| 10 | StreamAction | SSE live stream works |
| 11 | Composer Plugin | Plugin fixed and configurable |
| 12 | Integration Test | Full stack verified locally |
| 13 | Landing Page Fix | CSS bug resolved |
| 14 | Documentation | Design doc updated |

**Note:** GeoLite2-City.mmdb must be downloaded manually from MaxMind and placed in `api/data/` for GeoIP to work. Without it, pings are accepted but city/country will be null.
