<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Search\ShowcaseRunner;

/**
 * Search bar suggestions generated from the catalog (Model/Search/ShowcaseRunner): each
 * website's tick - it does nothing until the first sync is done, and nothing once a list
 * was generated or the merchant wrote one.
 */
class ShowcaseCron
{
    /**
     * @param ShowcaseRunner $runner
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ShowcaseRunner $runner,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Tick every website.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            $this->runner->tick((int)$website->getId());
        }
    }
}
