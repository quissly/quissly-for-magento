<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Config\Backend;

use Magento\Framework\App\Cache\Type\Block;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Config\Value;
use Magento\PageCache\Model\Cache\Type as PageCache;

/**
 * Backend model for any setting that changes what the STOREFRONT renders.
 *
 * Not only the feature toggles: the connection settings qualify too. Changing
 * the environment or the API host changes WHICH backend answers, and therefore
 * which products a shopper sees - a merchant who switches environments and
 * keeps being served pages built against the old one has the same bug, wearing
 * a different hat.
 *
 * Magento's own Value::afterSave() invalidates the CONFIG cache only. Under
 * full-page cache that is not enough: pages generated while a feature was on
 * keep being served after the merchant turns it off, so the toggle looks
 * broken.
 *
 * Tag-based cleaning does not help either: the page cache lives in its own
 * cache frontend, which the model's tag cleaner never touches. Cleaning the
 * cache TYPES is what actually purges it - and cleaning rather than merely
 * invalidating means the toggle is live on save, instead of waiting for
 * someone to notice an admin banner.
 *
 * The CONFIG type is cleaned for a second reason: parent::afterSave() marks it
 * INVALID, which is what raises the "One or more of the Cache Types are
 * invalidated" banner across the whole admin. Saving a Quissly setting should
 * not leave a merchant staring at a cache warning and wondering what they have
 * broken, so the type is purged and left valid rather than flagged for someone
 * to deal with later.
 */
class StorefrontSetting extends Value
{
    /**
     * Purge the storefront caches whenever one of these settings changes.
     *
     * @return $this
     */
    public function afterSave()
    {
        $result = parent::afterSave();

        if ($this->isValueChanged()) {
            $this->cacheTypeList->cleanType(PageCache::TYPE_IDENTIFIER);
            $this->cacheTypeList->cleanType(Block::TYPE_IDENTIFIER);
            // Must come after parent::afterSave(), which is what invalidated it.
            $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
        }

        return $result;
    }
}
