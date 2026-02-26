# TYPO3 with me — MVP Design

**Date:** 2026-02-26
**Author:** Olivier Dobberkau
**Status:** Approved

## Vision

A live world map that lights up every time someone installs TYPO3. The ecosystem pulse, visible in real time.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Goal | Production MVP | Fully working service, not just a demo |
| Hosting | Mittwald (existing server) | Already available, PHP-native |
| API Framework | Slim 4 + MySQL | Lightweight, structured, good fit for Mittwald |
| Live Updates | SSE + Polling Hybrid | No WebSocket server needed, works within Mittwald's PHP timeout limits |
| GeoIP | MaxMind GeoLite2 (local) | Free, fast, no external API dependency |

## Architecture

```
┌─────────────────────┐         ┌──────────────────────────────┐
│  Composer Plugin    │  POST   │  Slim PHP API (Mittwald)     │
│  (typo3/withme)     │────────>│                              │
│                     │         │  POST /v1/ping               │
│  Fire & forget      │         │    -> GeoLite2 (IP -> Stadt) │
│  3s timeout         │         │    -> Discard IP             │
└─────────────────────┘         │    -> MySQL INSERT           │
                                │                              │
┌─────────────────────┐  SSE    │  GET /v1/stream (SSE)        │
│  Dashboard          │<────────│    -> Stream new events      │
│  (typo3withme.org)  │         │                              │
│                     │  GET    │  GET /v1/stats               │
│  Landing Page       │<────────│    -> Aggregate stats        │
│  + Live Map         │         │                              │
└─────────────────────┘         └──────────────────────────────┘
                                           │
                                    ┌──────┴──────┐
                                    │   MySQL     │
                                    │   events    │
                                    └─────────────┘
```

## Components

### 1. API Server (Slim 4)

**Directory structure:**

```
api/
├── public/
│   ├── index.php              # Entry point
│   └── .htaccess              # Rewrite rules
├── src/
│   ├── Action/
│   │   ├── PingAction.php     # POST /v1/ping
│   │   ├── StreamAction.php   # GET /v1/stream (SSE)
│   │   └── StatsAction.php    # GET /v1/stats
│   ├── Service/
│   │   ├── GeoIpService.php   # MaxMind GeoLite2 lookup
│   │   └── EventService.php   # DB queries, stats aggregation
│   └── Middleware/
│       ├── RateLimitMiddleware.php
│       └── CorsMiddleware.php
├── config/
│   └── settings.php
├── composer.json
└── .env.example
```

**Dependencies:**
- `slim/slim` ^4.0
- `slim/psr7`
- `geoip2/geoip2` (MaxMind PHP reader)
- `vlucas/phpdotenv`

### 2. API Endpoints

#### `POST /v1/ping`

Receives anonymous telemetry from the Composer plugin.

**Request body:**
```json
{
    "typo3_version": "13.4.2",
    "php_version": "8.3",
    "event": "new_install",
    "project_hash": "a1b2c3d4e5f6g7h8",
    "os": "Linux"
}
```

**Processing:**
1. Validate payload (strict field validation, version format check, project_hash 16 hex chars)
2. Resolve IP to city/country/lat/lng via GeoLite2
3. Discard IP immediately (never stored, never logged)
4. Insert event into MySQL
5. Return `201 Created` with empty body

**Rate limiting:** Max 10 pings per IP per minute.

#### `GET /v1/stream`

Server-Sent Events stream for live dashboard updates.

```
Content-Type: text/event-stream
Cache-Control: no-cache

id: 4821
data: {"city":"Frankfurt","country":"DE","version":"13.4.2","event":"new_install","lat":50.1109,"lng":8.6821}

id: 4822
data: {"city":"Berlin","country":"DE","version":"13.4.2","event":"update","lat":52.5200,"lng":13.4050}
```

- Polls MySQL every 2 seconds for new events since last ID
- Supports `Last-Event-ID` header for reconnection
- Mittwald PHP timeout (~60s) is handled by browser auto-reconnect (EventSource standard behavior)

#### `GET /v1/stats`

Aggregated statistics, cached for 60 seconds.

```json
{
    "total_installs": 14283,
    "today": 342,
    "versions": {
        "13.4": 8421,
        "12.4": 3102,
        "11.5": 1846
    },
    "regions": {
        "EU": 8421,
        "NA": 2103,
        "AP": 1846,
        "OT": 477
    },
    "recent": [
        {
            "city": "Frankfurt",
            "country": "DE",
            "version": "13.4.2",
            "event": "new_install",
            "lat": 50.1109,
            "lng": 8.6821,
            "ago": "3s"
        }
    ]
}
```

### 3. Database Schema

```sql
CREATE TABLE events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    typo3_version VARCHAR(20) NOT NULL,
    php_version VARCHAR(10) NOT NULL,
    event_type ENUM('new_install', 'install', 'update') NOT NULL,
    project_hash CHAR(16) NOT NULL,
    os VARCHAR(20),
    city VARCHAR(100),
    country CHAR(2),
    latitude DECIMAL(8, 4),
    longitude DECIMAL(9, 4),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_project (project_hash),
    INDEX idx_version (typo3_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4. GeoIP Service

- Uses MaxMind GeoLite2 City database (~70MB)
- PHP reader via `geoip2/geoip2` package
- Returns city, country code, latitude, longitude
- IP is passed in-memory only, never written to disk or database
- Database file updated monthly (free MaxMind account required for download)

### 5. Composer Plugin Fixes

The existing `withme/Plugin.php` needs:
- Move `Plugin.php` into `src/` to match autoload config
- Make endpoint URL configurable via composer extra config (for dev/staging)
- Remove redundant `composer` field from payload
- Add `timestamp` field

### 6. Dashboard

The existing prototype (`dashboard/dashboard-prototype.html`) gets rewired:
- Replace simulated data with `EventSource('/v1/stream')` for live pings
- Fetch initial stats from `GET /v1/stats`
- Fallback to `setInterval` + `fetch` (every 5s) when SSE connection fails
- All existing UI (SVG map, toasts, feed, charts, region stats) stays as-is

### 7. Landing Page

Minimal changes:
- Update links once domain is configured
- Fix `--border` CSS variable self-reference bug in `:root`

## Security

| Concern | Mitigation |
|---|---|
| Payload injection | Strict validation: only allowed fields, format checks |
| Rate limiting | 10 pings/IP/minute, return 429 on excess |
| CORS | Only allow dashboard domain |
| SQL injection | PDO prepared statements (Slim/PDO standard) |
| IP privacy | Resolved to city in-memory, immediately discarded |
| Abuse | project_hash uniqueness check (same hash = same project, deduplicate) |

## Deployment on Mittwald

```
html/t3-with-me/
├── index.html                  # Landing Page
├── dashboard/
│   └── index.html              # Live Dashboard
└── api/
    ├── public/
    │   ├── index.php            # API entry point
    │   └── .htaccess            # Rewrite rules
    ├── src/
    ├── vendor/
    ├── config/
    └── data/
        └── GeoLite2-City.mmdb   # MaxMind database
```

MySQL database provisioned via Mittwald dashboard.

## Implementation Phases

| Phase | Description | Deliverable |
|---|---|---|
| 1 | API setup (Slim 4, MySQL, Ping endpoint) | Pings can be received and stored |
| 2 | GeoIP integration (MaxMind GeoLite2) | IP resolves to city, IP discarded |
| 3 | Stats + SSE endpoints | Dashboard can fetch data and stream events |
| 4 | Dashboard rewire to real API | Live dashboard with real data |
| 5 | Composer plugin fixes + testing | End-to-end working pipeline |
| 6 | Deploy to Mittwald | Everything live |

## Out of Scope (for MVP)

- Custom domain (typo3withme.org)
- Authentication/admin panel
- Historical charts and trends
- Email notifications
- Public API documentation
- Automated GeoLite2 DB updates (manual for MVP)
