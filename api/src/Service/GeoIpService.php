<?php

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
