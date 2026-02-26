<?php

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
