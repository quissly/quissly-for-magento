<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Store\Model\ScopeInterface;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Sync\SyncWorker;

/**
 * Saving a changed attribute list re-pushes the catalog.
 *
 * The attributes travel inside every product record, so a list that changes
 * after the first sync leaves Quissly holding records built with the OLD one
 * until each product happens to be saved again. A merchant who just added
 * "material" expects material to be searchable now, not product by product
 * over the coming months - so the save itself queues a full sync for every
 * website the change applies to (2026-09-10).
 *
 * Only for websites that are actually connected: an unconnected website has
 * nothing to re-push, and its first sync will carry the list when it comes.
 */
class CatalogAttributes extends Value
{
    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
     * @param \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList
     * @param SyncWorker $worker
     * @param Settings $settings
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param MessageManager $messages
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        private readonly SyncWorker $worker,
        private readonly Settings $settings,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly MessageManager $messages,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Multiselects post an array; the stored form is the comma-joined list.
     *
     * @return $this
     */
    public function beforeSave()
    {
        $value = $this->getValue();
        if (is_array($value)) {
            $this->setValue(implode(',', array_filter(array_map('trim', $value), 'strlen')));
        }
        return parent::beforeSave();
    }

    /**
     * @inheritdoc
     */
    public function afterSave()
    {
        $result = parent::afterSave();

        if ($this->isValueChanged()) {
            foreach ($this->websitesInScope() as $websiteId) {
                if (!$this->settings->isConfigured($websiteId)) {
                    continue;
                }
                $queued = $this->worker->startFullSync($websiteId);
                // "You saved the configuration" says nothing about the minutes
                // of syncing that just started; say where to watch it.
                $this->messages->addNoticeMessage(__(
                    'Your catalog is being re-sent to Quissly with the new attribute list '
                    . '(%1 products). Follow the progress on Quissly > Dashboard.',
                    $queued
                ));
            }
        }

        return $result;
    }

    /**
     * The websites this save applies to.
     *
     * The one being edited, or all of them at default scope - credentials and
     * this list both inherit from default.
     *
     * @return int[]
     */
    private function websitesInScope(): array
    {
        if ((string)$this->getScope() === ScopeInterface::SCOPE_WEBSITES) {
            return [(int)$this->getScopeId()];
        }
        $ids = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $ids[] = (int)$website->getId();
        }
        return $ids;
    }
}
