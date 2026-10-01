<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Quissly\Search\Logger\Logger;
use Quissly\Search\Model\Connect\ConnectHold;
use Quissly\Search\Model\Sync\SyncWorker;

/**
 * Start a full catalog sync for one website.
 *
 * README step 5 has always told merchants to start the first sync from here;
 * until now there was nowhere to start it, and they had to wait for cron.
 *
 * This only ENQUEUES the catalog - the cron worker drains it. A request that
 * pushed thousands of products inline would time out and leave the queue in an
 * unclear state, so the slow part stays where it belongs.
 */
class Sync extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Quissly_Search::dashboard';

    /**
     * @param Action\Context $context
     * @param SyncWorker $worker
     * @param Logger $logger
     * @param ConnectHold $hold
     */
    public function __construct(
        Action\Context $context,
        private readonly SyncWorker $worker,
        private readonly Logger $logger,
        private readonly ConnectHold $hold
    ) {
        parent::__construct($context);
    }

    /**
     * Enqueue every eligible product for the given website.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $websiteId = (int)$this->getRequest()->getParam('website_id', 0);
        if ($websiteId < 1) {
            // Defaulting to website 1 would silently sync the WRONG catalog on
            // a multi-website store. Refuse instead.
            $this->messageManager->addErrorMessage(__('No website was specified for the sync.'));
            return $this->resultRedirectFactory->create()->setPath('quissly/dashboard/index');
        }

        $remaining = $this->hold->remainingSeconds($websiteId);
        if ($remaining > 0) {
            // The account exists but the service behind it is still being
            // built on Quissly's side; a sync now would land on a half-built
            // service. The Dashboard shows a countdown for exactly this, and
            // the button is disabled there - this catches a stale page.
            $this->messageManager->addErrorMessage(
                __('Your store is still being set up. The first catalog sync can start in %1 seconds.', $remaining)
            );
            return $this->resultRedirectFactory->create()->setPath('quissly/dashboard/index');
        }

        try {
            $queued = $this->worker->startFullSync($websiteId);
            $this->messageManager->addSuccessMessage(
                __('Queued %1 products for website %2. The sync cron will process them.', $queued, $websiteId)
            );
        } catch (\Throwable $e) {
            // The message below sends the merchant to quissly.log, so the
            // failure has to actually BE there - pointing someone at an empty
            // log is worse than saying nothing.
            $this->logger->error(sprintf(
                '[quissly] full sync could not start website=%d: %s',
                $websiteId,
                $e->getMessage()
            ));
            // Never surface an internal message verbatim in the admin UI.
            $this->messageManager->addErrorMessage(
                __('Could not start the sync. Check var/log/quissly.log for details.')
            );
        }

        return $this->resultRedirectFactory->create()->setPath('quissly/dashboard/index');
    }
}
