<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Framework\App\Cache\Type\Block;
use Magento\PageCache\Model\Cache\Type as PageCache;

/**
 * An encrypted credential that also clears the storefront caches on save.
 *
 * Magento's Encrypted handles the encryption and invalidates the config cache,
 * which is not enough here: rotating the API token changes which account
 * answers a search, so pages built with the old credential must not keep being
 * served. Same defect as the feature toggles had (see StorefrontSetting), just
 * on a field where the consequence is a merchant thinking their new key does
 * not work.
 */
class EncryptedStorefrontSetting extends Encrypted
{
    /**
     * Purge the storefront caches whenever the credential changes.
     *
     * @return $this
     */
    public function afterSave()
    {
        $result = parent::afterSave();

        if ($this->isValueChanged()) {
            $this->cacheTypeList->cleanType(PageCache::TYPE_IDENTIFIER);
            $this->cacheTypeList->cleanType(Block::TYPE_IDENTIFIER);
        }

        return $result;
    }
}
