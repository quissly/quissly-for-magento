<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Health;

use Magento\Framework\FlagManager;

/**
 * Per-website connection health record (fallback matrix: auth/billing failures
 * happen in the SHOPPER's request, so they are persisted here and surfaced to
 * the merchant in the admin - never to the shopper). A success newer than the
 * last failure clears it.
 *
 * Records are kept per CHANNEL. Search and catalog fail for different reasons and
 * are read by different callers: a shopper's search can be refused (no quota grant
 * for the search service, say) while catalog ingestion is perfectly healthy. Sharing
 * one record made a shopper's 403 raise the admin's "not accepting catalog updates"
 * banner, and made a later successful search clear a real catalog rejection.
 */
class HealthRecorder
{
    public const CHANNEL_CATALOG = 'catalog';
    public const CHANNEL_SEARCH = 'search';

    private const FLAG_PREFIX = 'quissly_health_w';

    /**
     * @param FlagManager $flagManager
     */
    public function __construct(private readonly FlagManager $flagManager)
    {
    }

    /**
     * Record an auth-class failure for a website.
     *
     * @param int $websiteId
     * @param string $code ResponseClassifier constant
     * @param string $channel CHANNEL_CATALOG or CHANNEL_SEARCH
     * @return void
     */
    public function recordFailure(
        int $websiteId,
        string $code,
        string $channel = self::CHANNEL_CATALOG
    ): void {
        $this->flagManager->saveFlag(
            $this->flagCode($websiteId, $channel),
            ['code' => $code, 'at' => time()]
        );
    }

    /**
     * Clear the record after a verified success.
     *
     * @param int $websiteId
     * @param string $channel CHANNEL_CATALOG or CHANNEL_SEARCH
     * @return void
     */
    public function recordSuccess(int $websiteId, string $channel = self::CHANNEL_CATALOG): void
    {
        $this->flagManager->deleteFlag($this->flagCode($websiteId, $channel));
    }

    /**
     * The current failure record, or null when healthy.
     *
     * @param int $websiteId
     * @param string $channel CHANNEL_CATALOG or CHANNEL_SEARCH
     * @return array{code: string, at: int}|null
     */
    public function currentFailure(int $websiteId, string $channel = self::CHANNEL_CATALOG): ?array
    {
        $data = $this->flagManager->getFlagData($this->flagCode($websiteId, $channel));
        if (!is_array($data) || empty($data['code'])) {
            return null;
        }
        return ['code' => (string)$data['code'], 'at' => (int)($data['at'] ?? 0)];
    }

    /**
     * Flag code for a website/channel pair.
     *
     * The catalog channel keeps the original un-suffixed code so records written
     * before channels existed still read back as catalog failures.
     *
     * @param int $websiteId
     * @param string $channel
     * @return string
     */
    private function flagCode(int $websiteId, string $channel): string
    {
        $base = self::FLAG_PREFIX . $websiteId;
        return $channel === self::CHANNEL_CATALOG ? $base : $base . '_' . $channel;
    }
}
