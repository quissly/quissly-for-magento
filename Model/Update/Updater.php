<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Update;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\State;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Laminas\Uri\UriFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Automatic updates from the module's public GitHub repository.
 *
 * Every "Update check frequency" (Stores > Configuration > Quissly > Updates: 5 minutes for
 * testing, 30 minutes by default, up to a day, or Never) the module reads the version in the
 * repository's composer.json on GitHub's CDN (raw.githubusercontent.com - no API rate limit),
 * and asks GitHub for the latest release when that names a newer version than the one
 * installed and known - and at least once a day. When the API refuses the server (60 requests
 * an hour per IP address, shared by every site on a shared host), the release is found at the
 * address the release workflow publishes it to, from the CDN's version. Cron/UpdateCron ticks
 * every 5 minutes;
 * `bin/magento quissly:update` checks and installs at once, Never included. A newer release is
 * installed straight away:
 *
 *  1. the release zip is downloaded and checked: signed with Quissly's release key
 *     (Release::PUBLIC_KEY - an unsigned or wrongly signed zip is never installed, even if
 *     someone could publish to the repository), only Quissly/Search/, its composer.json
 *     naming the release's version;
 *  2. the store goes into maintenance mode (unless it already was), app/code/Quissly/Search
 *     is moved to a backup and the new one put in its place;
 *  3. `setup:upgrade` runs - in production mode with --keep-generated, then
 *     `setup:di:compile` and `setup:static-content:deploy` - then `cache:flush`, each
 *     as its own `bin/magento` process (the new code is compiled by a process that loads it);
 *  4. maintenance mode is switched off again.
 *
 * Any failure puts the old module back and runs the same steps for it, so the store comes
 * back on the version it had. A version that failed is not tried again; the next one is.
 *
 * A module installed with Composer (under vendor/) is never written to: Composer owns it,
 * and `composer update quissly/module-search` updates it (the releases are tagged). Nor is a
 * store whose files are a git checkout: whoever deploys it updates it.
 *
 * What the last check found - the outcome, and why an update is not arriving - is kept with
 * the state (status()) for Quissly > Dashboard; a problem also goes to quissly.log, the same
 * one once a day.
 */
class Updater
{
    public const REPOSITORY = 'quissly/quissly-for-magento';
    public const ASSET = 'quissly-for-magento.zip';
    public const FLAG = 'quissly_update';
    public const CHECK_EVERY = 86400;
    public const RETRY_AFTER = 3600;

    /** The merchant's "Update check frequency" (default scope; Release::interval()). */
    public const EVERY_PATH = 'quissly/updates/check_every';

    /** The repository's composer.json on GitHub's CDN: its version is the latest one pushed. */
    public const HINT_URL = 'https://raw.githubusercontent.com/quissly/quissly-for-magento/main/composer.json';

    /** How long one bin/magento step may take (static content on a big store is slow). */
    private const STEP_TIMEOUT = 3600;

    private const LOCK = 'quissly_update';

    /**
     * Why the last GitHub request failed ('' = it did not).
     *
     * @var string
     */
    private string $githubError = '';

    /**
     * @param CurlFactory $curlFactory
     * @param FlagManager $flags
     * @param ComponentRegistrarInterface $components
     * @param DirectoryList $directories
     * @param File $files
     * @param MaintenanceMode $maintenance
     * @param State $state
     * @param DeploymentConfig $deploymentConfig
     * @param LockManagerInterface $locks
     * @param Release $release
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $config
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly FlagManager $flags,
        private readonly ComponentRegistrarInterface $components,
        private readonly DirectoryList $directories,
        private readonly File $files,
        private readonly MaintenanceMode $maintenance,
        private readonly State $state,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly LockManagerInterface $locks,
        private readonly Release $release,
        private readonly LoggerInterface $logger,
        private readonly ScopeConfigInterface $config
    ) {
    }

    /**
     * Check (and install) when the frequency's time is up - or now, with $force.
     *
     * @param int $now
     * @param bool $force check and install now, a version that failed included (and Never)
     * @return string what happened: never, not_due, nothing_new, check_failed, up_to_date,
     *                composer_install, git_checkout, skipped_failed, busy, installed:<version>,
     *                failed:<reason>
     */
    public function run(int $now, bool $force = false): string
    {
        $every = $this->interval();
        if (!$force && $every === Release::NEVER) {
            return 'never';
        }
        $state = (array)($this->flags->getFlagData(self::FLAG) ?: []);
        // A frequency changed since the last check applies at once.
        $changed = (int)($state['every'] ?? 0) !== $every;
        if (!$force && !$changed && (int)($state['next_at'] ?? 0) > $now) {
            return 'not_due';
        }
        if (!$this->locks->lock(self::LOCK, 0)) {
            return 'busy';
        }
        try {
            // Claimed before the network: the next tick finds it not due.
            $state['every'] = $every;
            $state['next_at'] = $now + min(self::RETRY_AFTER, $every);
            $this->flags->saveFlag(self::FLAG, $state);

            // The cheap check first: GitHub's CDN copy of composer.json. Only news (or a day
            // since the last full check) asks GitHub's API for the release.
            $hinted = null;
            if (!$force && $now - (int)($state['checked_at'] ?? 0) < self::CHECK_EVERY) {
                $hinted = $this->hintedVersion();
                if ($hinted === null) {
                    $message = sprintf('Quissly could not check GitHub for a new version (%s).', $this->githubError);
                    $this->problem($state, $message, $now);
                    $state['next_at'] = $now + $every;
                    $this->flags->saveFlag(self::FLAG, $state);
                    return 'check_failed';
                }
                $known = (string)($state['latest'] ?? '');
                if (!$this->release->isNews($hinted, $this->installedVersion(), $known)) {
                    unset($state['problem']);
                    $state['next_at'] = $now + $every;
                    $state['hinted_at'] = $now;
                    $this->flags->saveFlag(self::FLAG, $state);
                    return 'nothing_new';
                }
            }

            $release = $this->latest($hinted);
            if ($release === null) {
                if ($this->githubError !== '') {
                    $message = sprintf(
                        'Quissly could not get the latest release from GitHub (%s).',
                        $this->githubError
                    );
                    $this->problem($state, $message, $now);
                    $this->flags->saveFlag(self::FLAG, $state);
                }
                return 'check_failed'; // tried again soon
            }
            unset($state['problem']);
            $state['next_at'] = $now + $every;
            $state['checked_at'] = $now;
            $state['latest'] = $release['version'];
            $result = $this->consider($release, $state, $now, $force);
            $state['outcome'] = $result;
            $this->flags->saveFlag(self::FLAG, $state);
            return $result;
        } finally {
            $this->locks->unlock(self::LOCK);
        }
    }

    /**
     * The merchant's "Update check frequency", in seconds (Release::NEVER = never).
     *
     * @return int
     */
    public function interval(): int
    {
        return $this->release->interval($this->config->getValue(self::EVERY_PATH));
    }

    /**
     * The installed module's version.
     *
     * @return string
     */
    public function installedVersion(): string
    {
        $moduleDir = rtrim((string)$this->components->getPath(ComponentRegistrar::MODULE, 'Quissly_Search'), '/');
        return (string)$this->release->versionIn($this->read($moduleDir . '/composer.json'));
    }

    /**
     * The version GitHub's CDN copy of the repository's composer.json names.
     *
     * Null when it cannot be read. `quissly/update_hint_url` in app/etc/env.php replaces the address.
     *
     * @return string|null
     */
    private function hintedVersion(): ?string
    {
        $override = (string)$this->deploymentConfig->get('quissly/update_hint_url');
        $body = $this->fetch($override !== '' ? $override : self::HINT_URL);
        if ($body === null) {
            return null; // fetch() said why
        }
        $version = $this->release->versionIn($body);
        if ($version === null) {
            $this->githubError = 'no version in its composer.json';
        }
        return $version;
    }

    /**
     * Note why an update is not arriving: kept for the Dashboard, logged once a day.
     *
     * @param array $state updated in place
     * @param string $message
     * @param int $now
     * @return void
     */
    private function problem(array &$state, string $message, int $now): void
    {
        $last = (array)($state['problem'] ?? []);
        $logged = ['message' => (string)($last['message'] ?? ''), 'at' => (int)($last['logged_at'] ?? 0)];
        $state['problem'] = ['message' => $message, 'at' => $now, 'logged_at' => $logged['at']];
        if ($this->release->problemDue($logged, $message, $now)) {
            $this->logger->warning($message);
            $state['problem']['logged_at'] = $now;
        }
    }

    /**
     * What the update check found, as run() stores it ([] before the first check).
     *
     * @return array
     */
    public function status(): array
    {
        return (array)($this->flags->getFlagData(self::FLAG) ?: []);
    }

    /**
     * Install the release when it is newer and can be installed.
     *
     * @param array $release version, package, signature
     * @param array $state updated in place
     * @param int $now
     * @param bool $force
     * @return string
     */
    private function consider(array $release, array &$state, int $now, bool $force): string
    {
        $moduleDir = rtrim((string)$this->components->getPath(ComponentRegistrar::MODULE, 'Quissly_Search'), '/');
        $installed = (string)$this->release->versionIn($this->read($moduleDir . '/composer.json'));
        if (!version_compare($release['version'], $installed, '>')) {
            return 'up_to_date';
        }
        $appCode = rtrim($this->directories->getPath(DirectoryList::APP), '/') . '/code/';
        if (strpos($this->files->getRealPath($moduleDir) . '/', $this->files->getRealPath($appCode) . '/') !== 0) {
            return 'composer_install';
        }
        $root = $this->directories->getRoot();
        if ($this->files->isExists($root . '/.git') || $this->files->isExists($moduleDir . '/.git')) {
            // Files managed by whoever deploys the store (or a developer's copy of the
            // repository): never written to, as WordPress skips a git-managed site.
            return 'git_checkout';
        }
        if (!$force && ($state['failed'] ?? '') === $release['version']) {
            return 'skipped_failed';
        }

        $error = $this->install($release, $moduleDir);
        $state['last'] = ['from' => $installed, 'to' => $release['version'], 'at' => $now, 'error' => $error];
        if ($error !== null) {
            $state['failed'] = $release['version'];
            $this->logger->error('Quissly update failed', [
                'version' => $release['version'], 'reason' => $error, 'running' => $installed,
            ]);
            return 'failed:' . $error;
        }
        unset($state['failed']);
        $this->logger->info('Quissly update installed', ['from' => $installed, 'to' => $release['version']]);
        return 'installed:' . $release['version'];
    }

    /**
     * The latest release from GitHub.
     *
     * From GitHub's API; when the API refuses the server, the release of the CDN's version at
     * its known addresses, once published (the workflow takes a minute or two after the push).
     *
     * @param string|null $hinted the CDN's version, when this check read it already
     * @return array|null version, package, signature
     */
    private function latest(?string $hinted): ?array
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout(15);
        $curl->addHeader('Accept', 'application/vnd.github+json');
        $curl->addHeader('User-Agent', 'quissly-for-magento');
        try {
            $curl->get($this->releaseUrl());
            $this->githubError = $this->release->requestError(true, (int)$curl->getStatus(), '');
        } catch (\Throwable $e) {
            $this->githubError = $this->release->requestError(false, 0, $e->getMessage());
        }
        if ($this->githubError === '') {
            $json = json_decode((string)$curl->getBody(), true);
            return $this->release->parse($json, self::ASSET, $this->packagePrefix());
        }
        if ((string)$this->deploymentConfig->get('quissly/update_url') !== '') {
            return null;
        }
        $apiError = $this->githubError;
        $hinted = $hinted ?? $this->hintedVersion();
        $this->githubError = $apiError;
        if ($hinted === null) {
            return null;
        }
        $release = $this->release->forVersion($hinted, self::ASSET, $this->packagePrefix());
        $published = $this->fetch($release['signature']) !== null;
        $this->githubError = $apiError; // the API's refusal is the reason, not a release still being built
        return $published ? $release : null;
    }

    /**
     * Download, check and put the release in place. Null on success, else why not.
     *
     * @param array $release version, package, signature
     * @param string $moduleDir
     * @return string|null
     */
    private function install(array $release, string $moduleDir): ?string
    {
        $work = rtrim($this->directories->getPath(DirectoryList::VAR_DIR), '/') . '/quissly/update/';
        $this->remove($work . 'new');
        $this->files->createDirectory($work . 'new');

        $problem = $this->unpack($release, $work);
        if ($problem !== null) {
            $this->remove($work . 'new');
            return $problem;
        }

        $wasInMaintenance = $this->maintenance->isOn();
        if (!$wasInMaintenance) {
            $this->maintenance->set(true);
        }
        try {
            $this->remove($work . 'backup');
            $this->files->createDirectory($work . 'backup');
            $this->move($moduleDir, $work . 'backup/Search');
            try {
                $this->move($work . 'new/' . Release::MODULE_PATH, $moduleDir);
            } catch (\Throwable $e) {
                $this->remove($moduleDir);
                $this->move($work . 'backup/Search', $moduleDir);
                return 'swap_failed';
            }

            $failed = $this->steps();
            if ($failed === null) {
                $this->remove($work . 'new');
                return null;
            }
            // Back to the version the store had, set up the same way.
            $this->remove($moduleDir);
            $this->move($work . 'backup/Search', $moduleDir);
            $this->steps();
            return 'step_failed:' . $failed;
        } catch (\Throwable $e) {
            $this->logger->error('Quissly update: install error', [
                'exception' => get_class($e), 'message' => $e->getMessage(),
            ]);
            if (!$this->files->isExists($moduleDir . '/registration.php')
                && $this->files->isExists($work . 'backup/Search/registration.php')
            ) {
                $this->remove($moduleDir);
                $this->move($work . 'backup/Search', $moduleDir);
                $this->steps();
            }
            return 'install_error';
        } finally {
            if (!$wasInMaintenance) {
                $this->maintenance->set(false);
            }
        }
    }

    /**
     * Download the zip into $work and extract it to $work/new; null when it is the release.
     *
     * @param array $release version, package, signature
     * @param string $work
     * @return string|null
     */
    private function unpack(array $release, string $work): ?string
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout(120);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, true);
        $curl->setOption(CURLOPT_MAXREDIRS, 5);
        $curl->addHeader('User-Agent', 'quissly-for-magento');
        try {
            $curl->get($release['package']);
        } catch (\Throwable $e) {
            return 'download_failed';
        }
        $body = (string)$curl->getBody();
        if ((int)$curl->getStatus() !== 200 || $body === '') {
            return 'download_failed';
        }
        $signature = $this->fetch($release['signature']);
        if ($signature === null) {
            return 'no_signature';
        }
        if (!$this->release->signatureValid($body, $signature, Release::PUBLIC_KEY)) {
            return 'bad_signature';
        }
        $zipPath = $work . 'package.zip';
        $this->files->filePutContents($zipPath, $body);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return 'bad_zip';
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string)$zip->getNameIndex($i);
        }
        $problem = $this->release->problem($names);
        $extracted = $problem === null && $zip->extractTo($work . 'new');
        $zip->close();
        $this->files->deleteFile($zipPath);
        if ($problem !== null) {
            return 'bad_package:' . $problem;
        }
        if (!$extracted) {
            return 'extract_failed';
        }
        $version = $this->release->versionIn($this->read($work . 'new/' . Release::MODULE_PATH . 'composer.json'));
        return $version === $release['version'] ? null : 'version_mismatch';
    }

    /**
     * A small file's contents from a release download (redirects followed); null on failure.
     *
     * @param string $url
     * @return string|null
     */
    private function fetch(string $url): ?string
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout(30);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, true);
        $curl->setOption(CURLOPT_MAXREDIRS, 5);
        $curl->addHeader('User-Agent', 'quissly-for-magento');
        try {
            $curl->get($url);
            $this->githubError = $this->release->requestError(true, (int)$curl->getStatus(), '');
        } catch (\Throwable $e) {
            $this->githubError = $this->release->requestError(false, 0, $e->getMessage());
        }
        return $this->githubError === '' ? (string)$curl->getBody() : null;
    }

    /**
     * The bin/magento steps that set up the module now in app/code; the failed one, or null.
     *
     * @return string|null
     */
    private function steps(): ?string
    {
        $production = $this->state->getMode() === State::MODE_PRODUCTION;
        $steps = $production
            ? [['setup:upgrade', '--keep-generated'], ['setup:di:compile'], ['setup:static-content:deploy']]
            : [['setup:upgrade']];
        $steps[] = ['cache:flush'];

        $root = $this->directories->getRoot();
        foreach ($steps as $step) {
            $command = [PHP_BINARY, '-d', 'memory_limit=-1', $root . '/bin/magento'];
            foreach ($step as $part) {
                $command[] = $part;
            }
            $command[] = '--no-interaction';
            $process = new Process(
                $command,
                $root,
                null,
                null,
                self::STEP_TIMEOUT
            );
            try {
                $process->run();
            } catch (\Throwable $e) {
                return $step[0];
            }
            if (!$process->isSuccessful()) {
                // The step's own last words (Magento's error message; no request data).
                $this->logger->error('Quissly update: step failed', [
                    'step' => $step[0],
                    'exit' => $process->getExitCode(),
                    'error' => substr(trim($process->getErrorOutput()), 0, 800),
                    'output' => substr(trim($process->getOutput()), -300),
                ]);
                return $step[0];
            }
        }
        return null;
    }

    /**
     * Move a directory: a rename, or a copy and then a delete.
     *
     * The copy is for moves across filesystems (var/ and app/code/ are often separate
     * mounts, where rename() fails).
     *
     * @param string $from
     * @param string $to
     * @return void
     */
    private function move(string $from, string $to): void
    {
        $from = rtrim($from, '/');
        $to = rtrim($to, '/');
        try {
            $this->files->rename($from, $to);
            return;
        } catch (\Throwable $e) {
            $this->remove($to); // whatever a failed rename left
        }
        $this->files->createDirectory($to);
        foreach ($this->files->readDirectoryRecursively($from) as $path) {
            if (strpos($path, $from . '/') !== 0) {
                throw new \RuntimeException('Unexpected path while copying');
            }
            $target = $to . substr($path, strlen($from));
            if ($this->files->isDirectory($path)) {
                $this->files->createDirectory($target);
            } else {
                $this->files->createDirectory($this->files->getParentDirectory($target));
                $this->files->copy($path, $target);
            }
        }
        // Some network and container filesystems report a just-emptied directory as not empty
        // for a moment: the delete is tried again before it counts as failed.
        for ($try = 1;; $try++) {
            try {
                $this->files->deleteDirectory($from);
                return;
            } catch (\Throwable $e) {
                if ($try === 3) {
                    throw $e;
                }
                sleep(1); // phpcs:ignore Magento2.Functions.DiscouragedFunction -- a CLI-only cron step
            }
        }
    }

    /**
     * A file's contents, '' when it cannot be read.
     *
     * @param string $path
     * @return string
     */
    private function read(string $path): string
    {
        try {
            return $this->files->isExists($path) ? (string)$this->files->fileGetContents($path) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Remove a directory if it is there.
     *
     * @param string $path
     * @return void
     */
    private function remove(string $path): void
    {
        if ($this->files->isExists($path)) {
            $this->files->deleteDirectory($path);
        }
    }

    /**
     * GitHub's "latest release" address; `quissly/update_url` in app/etc/env.php replaces it.
     *
     * @return string
     */
    private function releaseUrl(): string
    {
        $override = (string)$this->deploymentConfig->get('quissly/update_url');
        return $override !== '' ? $override : 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest';
    }

    /**
     * Where a package may come from: this repository's release downloads (or the override's host).
     *
     * @return string
     */
    private function packagePrefix(): string
    {
        $override = (string)$this->deploymentConfig->get('quissly/update_url');
        if ($override === '') {
            return 'https://github.com/' . self::REPOSITORY . '/releases/download/';
        }
        $uri = UriFactory::factory($override);
        $port = $uri->getPort();
        return ($uri->getScheme() ?: 'https') . '://' . $uri->getHost() . ($port ? ':' . $port : '') . '/';
    }
}
