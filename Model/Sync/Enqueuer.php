<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Sync;

use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Catalog\Model\ResourceModel\Product\Website as ProductWebsite;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Shared enqueue logic for observers: fans a product change out to its
 * websites' queues, and lifts CHILD changes to their configurable PARENTS
 * (children are "Not Visible Individually" and ride inside variants[]).
 */
class Enqueuer
{
    /**
     * @param QueueResource $queue
     * @param Configurable $configurableType
     * @param StoreManagerInterface $storeManager
     * @param ProductWebsite $productWebsite
     */
    public function __construct(
        private readonly QueueResource $queue,
        private readonly Configurable $configurableType,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductWebsite $productWebsite
    ) {
    }

    /**
     * Enqueue an upsert for a product and its configurable parents.
     *
     * Applies to the given websites - all websites when none supplied.
     *
     * @param int $productId
     * @param int[] $websiteIds
     * @return void
     */
    public function enqueueUpsert(int $productId, array $websiteIds = []): void
    {
        foreach ($this->targets($productId) as $id) {
            // Resolve the product's OWN websites when the caller has none to
            // give. Observers that only receive an id (stock changes, mass
            // actions) were fanning out to every website, so on a multi-website
            // store one stock movement wrote N rows, N-1 of them for websites
            // the product is not on. The worker then loaded each, found it
            // ineligible and deleted it - self-correcting, but it multiplies
            // queue writes by the number of websites and emits a steady drip of
            // "dropped … unmappable" lines that read like errors.
            $targets = $websiteIds !== [] ? $this->websites($websiteIds) : $this->websitesOf($id);
            foreach ($targets as $websiteId) {
                $this->queue->enqueue($id, $websiteId, QueueResource::OP_UPSERT);
            }
        }
    }

    /**
     * Enqueue a delete for a product on the given websites.
     *
     * Parents of a deleted child get an UPSERT (variants[] must regenerate).
     *
     * @param int $productId
     * @param int[] $websiteIds
     * @return void
     */
    public function enqueueDelete(int $productId, array $websiteIds = []): void
    {
        foreach ($this->websites($websiteIds) as $websiteId) {
            $this->queue->enqueue($productId, $websiteId, QueueResource::OP_DELETE);
        }
        foreach ($this->parentIds($productId) as $parentId) {
            foreach ($this->websites($websiteIds) as $websiteId) {
                $this->queue->enqueue($parentId, $websiteId, QueueResource::OP_UPSERT);
            }
        }
    }

    /**
     * The websites a product is actually assigned to.
     *
     * Falls back to ALL websites when the assignment cannot be read: for an
     * upsert the worker re-checks eligibility and drops what does not belong,
     * so over-queueing is recoverable while under-queueing would silently
     * leave a product missing from an index.
     *
     * @param int $productId
     * @return int[]
     */
    private function websitesOf(int $productId): array
    {
        try {
            $rows = $this->productWebsite->getWebsites([$productId]);
        } catch (\Throwable $e) {
            return $this->websites([]);
        }
        $ids = array_map('intval', $rows[$productId] ?? []);
        return $ids !== [] ? $ids : $this->websites([]);
    }

    /**
     * The product itself plus any configurable parents.
     *
     * @param int $productId
     * @return int[]
     */
    private function targets(int $productId): array
    {
        return array_values(array_unique(array_merge([$productId], $this->parentIds($productId))));
    }

    /**
     * Configurable parent ids of a (potential) child.
     *
     * @param int $productId
     * @return int[]
     */
    private function parentIds(int $productId): array
    {
        return array_map('intval', $this->configurableType->getParentIdsByChild($productId));
    }

    /**
     * Explicit website ids, or all websites.
     *
     * @param int[] $websiteIds
     * @return int[]
     */
    private function websites(array $websiteIds): array
    {
        if ($websiteIds !== []) {
            return array_map('intval', $websiteIds);
        }
        $all = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $all[] = (int)$website->getId();
        }
        return $all;
    }
}
