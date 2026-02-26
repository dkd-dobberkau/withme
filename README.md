# TYPO3 with me

A live world map that lights up every time someone installs TYPO3. See the ecosystem pulse in real time.

## How it works

1. **Install TYPO3** — The plugin ships with the official TYPO3 Composer template
2. **Anonymous ping** — A tiny, non-blocking request sends only TYPO3 version, PHP version, and an anonymous project hash
3. **Light up the map** — Your installation appears as a pulse on the live world map

## Privacy

- No personal data stored
- IP is resolved to a city via MaxMind GeoLite2 and immediately discarded
- Open source and fully auditable
- Opt-out in one command
- CI-aware — build pipelines are skipped automatically
- GDPR compliant (Art. 6(1)(f) legitimate interest)

### Payload

```json
{
  "typo3_version": "13.4.2",
  "php_version": "8.3",
  "event": "new_install",
  "project_hash": "a1b2c3d4e5f6g7h8",
  "os": "Linux"
}
```

That's it. That's the whole payload.

## Deploy

```bash
./deploy.sh
```

## License

Open source under GPL-2.0.

## Author

Olivier Dobberkau
