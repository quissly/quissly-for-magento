<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Sync\FirstSyncGate;
use Quissly\Search\Model\Sync\SyncWorker;

/**
 * Sync progress for one website, as JSON.
 *
 * The Dashboard used to report a first sync only as often as the merchant
 * pressed reload, so a run that takes minutes looked frozen. This is what the
 * page polls while a sync is in flight.
 *
 * Read-only and cheap on purpose: it reads the progress flag and the gate, and
 * never touches the queue or the API. Polling something that did real work
 * would turn an idle admin tab into load on Quissly.
 */
class Progress extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Quissly_Search::dashboard';

    /**
     * @param Action\Context $context
     * @param SyncWorker $worker
     * @param FirstSyncGate $gate
     * @param StoreManagerInterface $storeManager
     * @param JsonFactory $jsonFactory
     */
    public function __construct(
        Action\Context $context,
        private readonly SyncWorker $worker,
        private readonly FirstSyncGate $gate,
        private readonly StoreManagerInterface $storeManager,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Report progress for every website, keyed by website id.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $sites = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $id = (int)$website->getId();
            $progress = $this->worker->progress($id) ?? [];
            $sites[$id] = [
                'gate_open' => $this->gate->isOpen($id),
                'progress' => $progress,
                // The page stops polling on this rather than re-deriving the
                // finished condition in JavaScript, so there is one definition
                // of "done" and it lives here.
                'running' => (bool)($progress['running'] ?? false),
            ];
        }

        return $this->jsonFactory->create()->setData(['sites' => $sites]);
    }
}
