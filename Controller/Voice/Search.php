<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Voice;

use Quissly\Search\Controller\Media\AbstractMedia;
use Quissly\Search\Model\Api\MediaSearchClient;

/**
 * Anonymous voice-search proxy: `POST /quissly/voice/search` with a base64 WAV.
 * Built dark (default OFF); backend gate is a per-tenant 403 until enabled.
 */
class Search extends AbstractMedia
{
    /** WAV capped at 5 s / 16 kHz mono ≈ 160 KB raw → ~220 KB base64; allow headroom. */
    private const MAX_BASE64_BYTES = 700000;

    /**
     * @inheritDoc
     */
    protected function kind(): string
    {
        return MediaSearchClient::KIND_VOICE;
    }

    /**
     * @inheritDoc
     */
    protected function isEnabled(?int $websiteId): bool
    {
        return $this->settings->isVoiceEnabled($websiteId);
    }

    /**
     * @inheritDoc
     */
    protected function urlParam(): string
    {
        return 'quissly_voice';
    }

    /**
     * @inheritDoc
     */
    protected function maxBytes(): int
    {
        return self::MAX_BASE64_BYTES;
    }

    /**
     * @inheritDoc
     */
    protected function fallbackQuery(): string
    {
        return 'voice search';
    }
}
