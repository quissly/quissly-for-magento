<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Sync;

use Magento\Framework\FlagManager;

/**
 * The per-website first-sync gate: interception never fires for a
 * website until one full catalog sync has completed there - a fresh install
 * must not serve an empty index. The sync worker opens it on completion; the
 * quissly:gate console command controls it for development/support.
 */
class FirstSyncGate
{
    private const FLAG_PREFIX = 'quissly_first_sync_complete_w';

    /**
     * @param FlagManager $flagManager
     */
    public function __construct(private readonly FlagManager $flagManager)
    {
    }

    /**
     * Whether the gate is open for a website.
     *
     * @param int $websiteId
     * @return bool
     */
    public function isOpen(int $websiteId): bool
    {
        return (bool)$this->flagManager->getFlagData(self::FLAG_PREFIX . $websiteId);
    }

    /**
     * Open the gate (full sync completed for the website).
     *
     * @param int $websiteId
     * @return void
     */
    public function open(int $websiteId): void
    {
        $this->flagManager->saveFlag(self::FLAG_PREFIX . $websiteId, 1);
    }

    /**
     * Close the gate (e.g. credentials rotated to an empty index).
     *
     * @param int $websiteId
     * @return void
     */
    public function close(int $websiteId): void
    {
        $this->flagManager->deleteFlag(self::FLAG_PREFIX . $websiteId);
    }
}
