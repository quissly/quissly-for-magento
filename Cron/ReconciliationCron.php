<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Cron;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Sync\QueueResource;
use Quissly\Search\Model\Sync\ProductMapper;

/**
 * The drift backstop: re-enqueue every eligible product (updates are
 * idempotent; a silently-dropped update self-heals) and enqueue DELETEs for
 * ingested products that are no longer eligible (missed unpublish paths).
 */
class ReconciliationCron
{
    /**
     * @param QueueResource $queue
     * @param CollectionFactory $productCollectionFactory
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly QueueResource $queue,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Enqueue the full eligible set + stale-state deletes, per website.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int)$website->getId();
            $collection = $this->productCollectionFactory->create();
            $collection->addWebsiteFilter($websiteId)
                ->addAttributeToFilter('status', Status::STATUS_ENABLED)
                ->addAttributeToFilter(
                    'visibility',
                    ['in' => [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH]]
                )
                ->addAttributeToFilter('type_id', ['in' => ProductMapper::SYNCABLE_TYPES]);
            $eligible = array_map('intval', $collection->getAllIds());
            foreach ($eligible as $id) {
                $this->queue->enqueue($id, $websiteId, QueueResource::OP_UPSERT);
            }
            $stale = array_diff($this->queue->allIngestedIds($websiteId), $eligible);
            foreach ($stale as $id) {
                $this->queue->enqueue($id, $websiteId, QueueResource::OP_DELETE);
            }
            $this->logger->info(sprintf(
                '[quissly] reconciliation website=%d re-enqueued=%d stale-deletes=%d',
                $websiteId,
                count($eligible),
                count($stale)
            ));
        }
    }
}
