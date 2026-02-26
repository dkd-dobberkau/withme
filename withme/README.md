# TYPO3 with me 🌍

A Composer plugin that lights up a live world map every time someone installs or updates TYPO3.

## The Idea

Inspired by "Beer with me" — but instead of showing where your friends are drinking, **TYPO3 with me** shows where people around the world are installing TYPO3, right now, in real time.

Every `composer install`, `composer update`, or `composer create-project` that includes TYPO3 sends an anonymous ping to the TYPO3 with me service. The result is a live, pulsing world map at [typo3withme.org](https://typo3withme.org) that makes the TYPO3 ecosystem tangible and visible.

## How It Works

### For Site Owners & Developers

The plugin hooks into three Composer events:

| Event | When it fires | Label on map |
|---|---|---|
| `post-create-project-cmd` | Fresh TYPO3 project via `composer create-project` | 🆕 New Install |
| `post-install-cmd` | `composer install` (with lock file) | 📦 Install |
| `post-update-cmd` | `composer update` | 🔄 Update |

When the event fires, the plugin:

1. Checks if the user has opted out (env var, composer config, or CI detection)
2. Looks for `typo3/cms-core` in the local repository to determine the TYPO3 version
3. Sends a minimal, anonymous JSON payload to the TYPO3 with me API
4. Prints a one-line confirmation in the terminal

The entire process is **fire-and-forget** with a 3-second timeout. It will never slow down or break your Composer workflow.

### What Gets Sent

```json
{
    "typo3_version": "13.4.2",
    "php_version": "8.3",
    "event": "new_install",
    "project_hash": "a1b2c3d4e5f6g7h8",
    "composer": "2.7.1",
    "os": "Linux",
    "timestamp": 1739052000
}
```

That's it. No names, no emails, no tracking cookies, no IP storage.

The `project_hash` is a truncated SHA-256 of the local project path combined with the machine hostname. It allows counting unique installations without identifying anyone. The IP address is used server-side only to resolve an approximate city-level location and is then immediately discarded.

### What Is NOT Sent

- No personal data of any kind
- No domain names or URLs
- No project names or file paths
- No extension lists
- No database credentials
- No IP addresses (resolved to city, then discarded)

## Installation

The plugin would ship as a dependency of the official TYPO3 project template, so it works out of the box for new installations:

```bash
# Already included when you start a new TYPO3 project:
composer create-project typo3/cms-base-distribution my-project

# Or add it to an existing project:
composer require typo3/withme
```

## Opting Out

Three ways to opt out, pick whichever suits you:

### 1. Environment Variable

```bash
export TYPO3_WITHME_OPTOUT=1
```

### 2. Composer Configuration

```bash
composer config extra.typo3/withme.enabled false
```

This adds the following to your `composer.json`:

```json
{
    "extra": {
        "typo3/withme": {
            "enabled": false
        }
    }
}
```

### 3. Remove the Package

```bash
composer remove typo3/withme
```

### CI Environments

The plugin automatically detects CI environments (GitHub Actions, GitLab CI, Jenkins, Travis) and **skips the ping by default**. This prevents build pipelines from inflating the numbers. You can override this with explicit config if desired.

## Architecture

```
┌─────────────────────┐     ┌──────────────────────┐     ┌─────────────────────┐
│  Developer Machine  │     │   TYPO3 with me API  │     │   Live Dashboard    │
│                     │     │                      │     │                     │
│  composer install   │────▶│  POST /v1/ping       │────▶│  typo3withme.org    │
│                     │     │                      │     │                     │
│  typo3/withme       │     │  - Resolve IP → city │     │  - Live world map   │
│  (Composer Plugin)  │     │  - Discard IP        │     │  - Activity feed    │
│                     │     │  - Store event       │     │  - Version stats    │
│                     │     │  - Push to WebSocket │     │  - Region breakdown │
└─────────────────────┘     └──────────────────────┘     └─────────────────────┘
```

### API Server (to be built)

The API server would be a lightweight service that:

- Receives pings via `POST /v1/ping`
- Resolves the requesting IP to a city using MaxMind GeoLite2 (free, GDPR-compliant)
- Immediately discards the IP address
- Stores the event with city, country, version, and timestamp
- Pushes new events to connected dashboard clients via WebSocket
- Exposes aggregate statistics via `GET /v1/stats`

### Dashboard (prototype included)

The included `typo3-with-me.html` is a fully functional prototype of the live dashboard with simulated data. For production, the simulated data would be replaced with a WebSocket connection to the API.

## Integration with TYPO3 Project Templates

The recommended distribution strategy is to include `typo3/withme` in the official project templates:

**typo3/cms-base-distribution** `composer.json`:
```json
{
    "require": {
        "typo3/cms-core": "^13.4",
        "typo3/withme": "^1.0"
    }
}
```

This means every new TYPO3 project automatically participates, with easy opt-out for those who prefer not to.

## Use Cases

- **Conference screens**: Show the live map on a big display at T3CON, TYPO3 Barcamps, or partner events
- **Marketing**: Embed the map on typo3.org to demonstrate ecosystem vitality
- **Community building**: Developers see they're not alone – TYPO3 is alive everywhere
- **Version adoption tracking**: Watch v13 adoption roll out across the globe
- **Regional insights**: Identify growing markets for TYPO3 agencies and service providers

## Privacy & GDPR

This plugin was designed with privacy as a core principle:

- **Opt-in by default via package inclusion** — users choose to keep the package
- **Trivial opt-out** — one command, one env var, or just remove the package
- **No personal data** — the payload contains only technical metadata
- **No IP storage** — IP is resolved to city-level and immediately discarded
- **No tracking** — the project hash cannot be reversed to identify a person or project
- **CI-aware** — automatically disables in build pipelines
- **Open source** — the full plugin code is visible and auditable
- **GDPR Art. 6(1)(f)** — legitimate interest with minimal data, easy objection

## Development

```bash
git clone https://github.com/typo3/withme.git
cd withme
composer install
```

### Testing locally

Use a path repository in a test TYPO3 project:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../typo3-with-me-plugin"
        }
    ],
    "require": {
        "typo3/withme": "@dev"
    }
}
```

## License

GPL-2.0-or-later — consistent with the TYPO3 ecosystem.
