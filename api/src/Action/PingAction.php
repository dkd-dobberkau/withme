<?php

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

        $errors = $this->validate($body);
        if (!empty($errors)) {
            return $this->jsonError($response, implode(', ', $errors), 422);
        }

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        $ipHash = hash('sha256', $ip);
        $rl = $this->settings['rate_limit'];
        if (!$this->eventService->checkRateLimit($ipHash, $rl['max_requests'], $rl['window_seconds'])) {
            return $this->jsonError($response, 'Rate limit exceeded', 429);
        }

        $geo = $this->geoIpService->resolve($ip);

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
