<?php

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
