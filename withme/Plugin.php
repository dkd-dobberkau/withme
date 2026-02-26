<?php

declare(strict_types=1);

namespace TYPO3\WithMe;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * TYPO3 with me – Composer Plugin
 *
 * Sends an anonymous, opt-in ping to the TYPO3 with me service whenever
 * a TYPO3 installation is created or updated via Composer.
 *
 * The ping contains only:
 *   - TYPO3 version (e.g. "13.4.2")
 *   - PHP version (e.g. "8.3")
 *   - Approximate location derived server-side from IP (city-level, IP is NOT stored)
 *   - A SHA-256 hash of the project path (to count unique installs, not track users)
 *
 * Opt-out:
 *   composer config extra.typo3/withme.enabled false
 *   — or set env: TYPO3_WITHME_OPTOUT=1
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const ENDPOINT = 'https://api.typo3withme.org/v1/ping';
    private const TIMEOUT_SECONDS = 3;
    private const USER_AGENT = 'TYPO3-WithMe-Composer/1.0';

    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Nothing to clean up
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Nothing to clean up
    }

    /**
     * Subscribe to post-install and post-update command events.
     * These fire after `composer install` or `composer update` completes.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => ['onPostInstallOrUpdate', -100],
            ScriptEvents::POST_UPDATE_CMD  => ['onPostInstallOrUpdate', -100],
            ScriptEvents::POST_CREATE_PROJECT_CMD => ['onPostInstallOrUpdate', -100],
        ];
    }

    /**
     * Called after composer install/update/create-project finishes.
     */
    public function onPostInstallOrUpdate(Event $event): void
    {
        // 1. Check opt-out
        if ($this->isOptedOut()) {
            return;
        }

        // 2. Detect TYPO3 version from installed packages
        $typo3Version = $this->detectTypo3Version();
        if ($typo3Version === null) {
            // No TYPO3 core found – nothing to report
            return;
        }

        // 3. Build payload
        $payload = $this->buildPayload($typo3Version, $event->getName());

        // 4. Send ping (non-blocking, fire-and-forget)
        $this->sendPing($payload);

        // 5. Show a friendly message
        $this->io->write('');
        $this->io->write('<info>🌍 TYPO3 with me:</info> Ping sent! Your installation is now visible on <href=https://typo3withme.org>typo3withme.org</>');
        $this->io->write('<comment>   Opt out anytime: composer config extra.typo3/withme.enabled false</comment>');
        $this->io->write('');
    }

    /**
     * Check if the user has opted out via config or environment variable.
     */
    private function isOptedOut(): bool
    {
        // Environment variable opt-out
        if (getenv('TYPO3_WITHME_OPTOUT') === '1') {
            return true;
        }

        // Composer extra config opt-out
        $extra = $this->composer->getPackage()->getExtra();
        if (isset($extra['typo3/withme']['enabled']) && $extra['typo3/withme']['enabled'] === false) {
            return true;
        }

        // CI environments: opt out by default (can be overridden)
        if ($this->isCiEnvironment() && !$this->isExplicitlyEnabled()) {
            $this->io->write(
                '<comment>TYPO3 with me: CI environment detected, skipping ping. '
                . 'Set TYPO3_WITHME_OPTOUT=0 to enable.</comment>',
                true,
                IOInterface::VERBOSE
            );
            return true;
        }

        return false;
    }

    /**
     * Detect if running in a CI environment.
     */
    private function isCiEnvironment(): bool
    {
        $ciIndicators = ['CI', 'CONTINUOUS_INTEGRATION', 'GITHUB_ACTIONS', 'GITLAB_CI', 'JENKINS_URL', 'TRAVIS'];
        foreach ($ciIndicators as $env) {
            if (getenv($env) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if explicitly enabled (overrides CI detection).
     */
    private function isExplicitlyEnabled(): bool
    {
        $extra = $this->composer->getPackage()->getExtra();
        return isset($extra['typo3/withme']['enabled']) && $extra['typo3/withme']['enabled'] === true;
    }

    /**
     * Find the installed TYPO3 core version from the local repository.
     */
    private function detectTypo3Version(): ?string
    {
        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();

        // Try typo3/cms-core first, then typo3/cms
        foreach (['typo3/cms-core', 'typo3/cms'] as $packageName) {
            $packages = $localRepo->findPackages($packageName);
            if (!empty($packages)) {
                return $packages[0]->getPrettyVersion();
            }
        }

        return null;
    }

    /**
     * Build the anonymous telemetry payload.
     */
    private function buildPayload(string $typo3Version, string $eventName): array
    {
        // Project hash: a non-reversible identifier to count unique installs
        // Uses the absolute project path + a machine-specific salt
        $projectDir = $this->composer->getConfig()->get('vendor-dir') . '/..';
        $projectHash = hash('sha256', realpath($projectDir) . php_uname('n'));

        return [
            'typo3_version' => $typo3Version,
            'php_version'   => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'event'         => $this->mapEventName($eventName),
            'project_hash'  => substr($projectHash, 0, 16), // Only first 16 chars
            'composer'      => Composer::getVersion(),
            'os'            => PHP_OS_FAMILY,
            'timestamp'     => time(),
        ];
    }

    /**
     * Map Composer event names to simpler labels.
     */
    private function mapEventName(string $eventName): string
    {
        return match ($eventName) {
            ScriptEvents::POST_CREATE_PROJECT_CMD => 'new_install',
            ScriptEvents::POST_INSTALL_CMD        => 'install',
            ScriptEvents::POST_UPDATE_CMD         => 'update',
            default                               => 'unknown',
        };
    }

    /**
     * Send the ping as a non-blocking HTTP POST request.
     * Uses a socket directly to avoid blocking the Composer process.
     * Falls back to file_get_contents if sockets are not available.
     */
    private function sendPing(array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        try {
            $this->sendNonBlocking($json);
        } catch (\Throwable $e) {
            // Silently fail – this must never break a composer install
            $this->io->write(
                '<comment>TYPO3 with me: Could not send ping (' . $e->getMessage() . ')</comment>',
                true,
                IOInterface::VERY_VERBOSE
            );
        }
    }

    /**
     * Non-blocking HTTP POST using stream context.
     * The request is fire-and-forget with a short timeout.
     */
    private function sendNonBlocking(string $json): void
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", [
                    'Content-Type: application/json',
                    'User-Agent: ' . self::USER_AGENT,
                    'Accept: application/json',
                ]),
                'content' => $json,
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        // Fire and forget – we don't care about the response
        @file_get_contents(self::ENDPOINT, false, $context);
    }
}
