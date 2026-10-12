<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Config\Backend;

use Quissly\Search\Model\Search\MostLoved;

/**
 * A Most loved list (Model/Search/MostLoved) as saved: SKUs one per line, trimmed, each once, in
 * order - at most 8 for the shown list, 4 for the backups. A SKU that matches no product is kept
 * (Configuration says so); the page cache is cleaned like every storefront setting.
 */
class MostLovedSkus extends StorefrontSetting
{
    /**
     * Clean the list before it is saved.
     *
     * @return $this
     */
    public function beforeSave()
    {
        $max = (string)($this->getField() ?? '') === 'backups' ? MostLoved::BACKUPS : MostLoved::SHOWN;
        $skus = [];
        foreach (preg_split('/\R/u', (string)$this->getValue()) ?: [] as $line) {
            $sku = trim($line);
            if ($sku !== '' && !in_array($sku, $skus, true)) {
                $skus[] = $sku;
            }
        }
        $this->setValue(implode("\n", array_slice($skus, 0, $max)));
        return parent::beforeSave();
    }
}
