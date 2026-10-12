<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Search\SearchSuggestions;
use Quissly\Search\Model\Search\ShowcaseRunner;

/**
 * Search bar suggestions generated from the catalog (Model/Search/ShowcaseRunner): each
 * website's tick - it does nothing until the first sync is done, and nothing once a list
 * was generated or the merchant wrote one.
 *
 * It also looks up each connected website's QSearch service id when it is not stored yet: the
 * storefront reads Quissly's lists with the stored id only, so without this a website showed no
 * Search Examples or suggestion buttons until someone opened Configuration.
 */
class ShowcaseCron
{
    /**
     * @param ShowcaseRunner $runner
     * @param StoreManagerInterface $storeManager
     * @param SearchSuggestions $suggestions
     * @param Settings $settings
     */
    public function __construct(
        private readonly ShowcaseRunner $runner,
        private readonly StoreManagerInterface $storeManager,
        private readonly SearchSuggestions $suggestions,
        private readonly Settings $settings
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
            $websiteId = (int)$website->getId();
            if ($this->settings->searchServiceId($websiteId) === null
                && $this->settings->projectId($websiteId) !== null
            ) {
                try {
                    $this->suggestions->serviceId($websiteId); // looked up and stored (a failure waits a minute)
                } catch (\Throwable $e) {
                    unset($e); // tried again on the next tick
                }
            }
            $this->runner->tick($websiteId);
        }
    }
}
