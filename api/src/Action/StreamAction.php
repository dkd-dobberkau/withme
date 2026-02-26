<?php

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

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        if (ob_get_level()) {
            ob_end_clean();
        }

        $maxTime = 55;
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

        echo "retry: 3000\n\n";
        flush();

        exit;
    }
}
