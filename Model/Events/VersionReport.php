<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Events;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Update\Release;

/**
 * Which version of the module a store runs, reported to Quissly.
 *
 * A `quissly_version_update` event tells Quissly which version runs, which one ran before and
 * since when: when a website is first connected, right after each update, and once a day (so a
 * report that was missed is right again within a day). Every connected website reports - each
 * is its own Quissly account - and Quissly knows which from the website's key; the event
 * carries its own time.
 *
 * Metadata: {platform, version, previous_version, updated_at} ('' previous_version before the
 * first update; updated_at = when the store moved to this version, ISO 8601 UTC).
 *
 * The version is the store's own fact, not a shopper's: it is reported whenever a website is
 * connected, whatever its "Shopping activity" switch says, with no user id. Run by the
 * per-minute events cron, so an update is reported within a minute of the new version running.
 *
 * track() and metadata() are pure (unit-tested).
 */
class VersionReport
{
    public const EVENT = 'quissly_version_update';
    public const PLATFORM = 'magento';
    /** {version, previous, updated_at, reported: {website id: when}} */
    public const FLAG = 'quissly_version_report';
    public const EVERY = 86400;

    /**
     * @param FlagManager $flags
     * @param Settings $settings
     * @param StoreManagerInterface $storeManager
     * @param Recorder $recorder
     * @param ComponentRegistrarInterface $components
     * @param File $files
     * @param Release $release
     */
    public function __construct(
        private readonly FlagManager $flags,
        private readonly Settings $settings,
        private readonly StoreManagerInterface $storeManager,
        private readonly Recorder $recorder,
        private readonly ComponentRegistrarInterface $components,
        private readonly File $files,
        private readonly Release $release
    ) {
    }

    /**
     * What to keep, and which websites are due a report.
     *
     * A new version is due everywhere at once; otherwise a website is due when it never
     * reported, or a day has passed since it did.
     *
     * @param array $state the stored record ([] before the first one)
     * @param string $version the running version
     * @param int[] $websiteIds the connected websites
     * @param int $now
     * @return array{state: array, due: int[]}
     */
    public function track(array $state, string $version, array $websiteIds, int $now): array
    {
        $known = (string)($state['version'] ?? '');
        if ($known !== $version) {
            $state = ['version' => $version, 'previous' => $known, 'updated_at' => $now, 'reported' => []];
        }
        $reported = (array)($state['reported'] ?? []);
        $due = [];
        foreach ($websiteIds as $websiteId) {
            $at = (int)($reported[(string)$websiteId] ?? 0);
            if ($at <= 0 || $now - $at >= self::EVERY) {
                $due[] = (int)$websiteId;
            }
        }
        return ['state' => $state, 'due' => $due];
    }

    /**
     * The event's metadata.
     *
     * @param array $state the stored record
     * @return array{platform: string, version: string, previous_version: string, updated_at: string}
     */
    public function metadata(array $state): array
    {
        return [
            'platform' => self::PLATFORM,
            'version' => (string)($state['version'] ?? ''),
            'previous_version' => (string)($state['previous'] ?? ''),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z', (int)($state['updated_at'] ?? 0)),
        ];
    }

    /**
     * Track the running version and report it from every connected website that is due.
     *
     * @param int $now
     * @return void
     */
    public function run(int $now): void
    {
        $version = $this->installedVersion();
        if ($version === '') {
            return;
        }
        $websiteIds = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            if ($this->settings->isConfigured((int)$website->getId())) {
                $websiteIds[] = (int)$website->getId();
            }
        }
        $stored = (array)($this->flags->getFlagData(self::FLAG) ?: []);
        $result = $this->track($stored, $version, $websiteIds, $now);
        $state = $result['state'];
        foreach ($result['due'] as $websiteId) {
            if ($this->recorder->store($websiteId, self::EVENT, $this->metadata($state))) {
                $state['reported'][(string)$websiteId] = $now;
            }
        }
        if ($state !== $stored) {
            $this->flags->saveFlag(self::FLAG, $state);
        }
    }

    /**
     * The installed module's version (its composer.json), '' when it cannot be read.
     *
     * @return string
     */
    private function installedVersion(): string
    {
        try {
            $path = rtrim((string)$this->components->getPath(ComponentRegistrar::MODULE, 'Quissly_Search'), '/')
                . '/composer.json';
            return $this->files->isExists($path)
                ? (string)$this->release->versionIn((string)$this->files->fileGetContents($path))
                : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
