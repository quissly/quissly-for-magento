<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Connect;

use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The settling period after a successful connect.
 *
 * Provisioning answers as soon as the account exists; the rest of the setup
 * (search service, indexes, panel) finishes on Quissly's side in the
 * background over the following minute or so. A first catalog sync started
 * inside that window can land on a half-built service, so for HOLD_SECONDS
 * after the connect response the store counts as "still being set up": the
 * config page keeps its progress bar running instead of announcing success,
 * and the Dashboard refuses to start the sync.
 *
 * The connect time lives in a flag, like the first-sync gate, so the hold
 * survives reloads and reads the same in every browser and tab. A connect
 * made at default scope applies to every website, so a website's hold is the
 * later of its own and the default-scope one.
 */
class ConnectHold
{
    public const HOLD_SECONDS = 90;

    private const FLAG_PREFIX = 'quissly_connected_at_w';

    /**
     * @param FlagManager $flagManager
     * @param DateTime $clock
     */
    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly DateTime $clock
    ) {
    }

    /**
     * Record that this scope has just connected.
     *
     * @param int $scopeId Website id, or 0 for the default scope.
     * @return void
     */
    public function start(int $scopeId): void
    {
        $this->flagManager->saveFlag(self::FLAG_PREFIX . $scopeId, $this->clock->gmtTimestamp());
    }

    /**
     * Seconds still to wait before the store counts as set up; 0 when none.
     *
     * @param int $websiteId
     * @return int
     */
    public function remainingSeconds(int $websiteId): int
    {
        $latest = max($this->connectedAt($websiteId), $this->connectedAt(0));
        if ($latest === 0) {
            return 0;
        }

        return max(0, $latest + self::HOLD_SECONDS - $this->clock->gmtTimestamp());
    }

    /**
     * When the scope connected, as a unix timestamp; 0 when it never has.
     *
     * @param int $scopeId
     * @return int
     */
    private function connectedAt(int $scopeId): int
    {
        return (int)$this->flagManager->getFlagData(self::FLAG_PREFIX . $scopeId);
    }
}
