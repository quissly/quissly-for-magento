<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Image;

use Quissly\Search\Controller\Media\AbstractMedia;
use Quissly\Search\Model\Api\MediaSearchClient;

/**
 * Anonymous image-search proxy: `POST /quissly/image/search` with a base64 JPEG.
 * Built dark (default OFF); backend gate is a 503 until the qimage service is on.
 */
class Search extends AbstractMedia
{
    /** JPEG resized client-side to longest-edge 1024 @ 0.85; cap ~3 MB base64. */
    private const MAX_BASE64_BYTES = 3000000;

    /**
     * @inheritDoc
     */
    protected function kind(): string
    {
        return MediaSearchClient::KIND_IMAGE;
    }

    /**
     * @inheritDoc
     */
    protected function isEnabled(?int $websiteId): bool
    {
        return $this->settings->isImageEnabled($websiteId);
    }

    /**
     * @inheritDoc
     */
    protected function urlParam(): string
    {
        return 'quissly_img';
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
        return 'image search';
    }
}
