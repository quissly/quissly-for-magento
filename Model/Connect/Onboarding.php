<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Connect;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\FlagManager;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Api\ServiceDirectory;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Sync\SyncCompletion;
use Quissly\Search\Model\Sync\SyncWorker;

/**
 * Quissly Setup: the one-click onboarding, as the Shopify app does it.
 *
 * Three steps - Your details (Connect), Choose a plan, Go live - and the first
 * catalog sync starts on its own in between, once the service Connect created
 * has settled (ConnectHold). Going live is the merchant's own click: it
 * switches Quissly search on, and only once the first sync has finished.
 *
 * Setup works at the default scope for the default website, the storefront
 * Connect registers. Further websites are connected from Configuration as
 * before.
 *
 * A store connected before Setup existed has no record here and counts as
 * finished, so nobody already live is sent back through it.
 */
class Onboarding
{
    public const STEP_DETAILS = 'details';
    public const STEP_PLAN = 'plan';
    public const STEP_GOLIVE = 'golive';

    private const FLAG = 'quissly_onboarding';

    private const STATUS_IN_PROGRESS = 'in_progress';
    private const STATUS_COMPLETE = 'complete';

    private const PATH_ENABLE_SEARCH = 'quissly/features/enable_search';
    private const PATH_QCHAT_AGENT_ID = 'quissly/features/qchat_agent_id';

    /**
     * @param FlagManager $flagManager
     * @param Settings $settings
     * @param StoreManagerInterface $storeManager
     * @param ConnectHold $hold
     * @param SyncWorker $worker
     * @param SyncCompletion $completion
     * @param WriterInterface $configWriter
     * @param ReinitableConfigInterface $reinitableConfig
     * @param TypeListInterface $cacheTypeList
     * @param LoggerInterface $logger
     * @param ServiceDirectory $serviceDirectory
     */
    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly Settings $settings,
        private readonly StoreManagerInterface $storeManager,
        private readonly ConnectHold $hold,
        private readonly SyncWorker $worker,
        private readonly SyncCompletion $completion,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly LoggerInterface $logger,
        private readonly ServiceDirectory $serviceDirectory
    ) {
    }

    /**
     * Whether Setup is behind this store. Until it is, the Quissly menu leads to it.
     *
     * @return bool
     */
    public function isComplete(): bool
    {
        $state = $this->state();
        if ($state === null) {
            return $this->anyConnected();
        }
        return ($state['status'] ?? '') === self::STATUS_COMPLETE;
    }

    /**
     * The step the store is on.
     *
     * @return string
     */
    public function step(): string
    {
        if (!$this->isConnected()) {
            return self::STEP_DETAILS;
        }
        return empty($this->state()['plan']) ? self::STEP_PLAN : self::STEP_GOLIVE;
    }

    /**
     * Connect succeeded for a store that had not finished Setup: carry on from the plan step.
     *
     * @return void
     */
    public function connected(): void
    {
        $state = $this->state() ?? [];
        $state['status'] = self::STATUS_IN_PROGRESS;
        $this->save($state);
    }

    /**
     * The plan step is done: a plan is live, or the merchant was let through without one.
     *
     * @return void
     */
    public function planChosen(): void
    {
        $state = $this->state() ?? ['status' => self::STATUS_IN_PROGRESS];
        $state['plan'] = true;
        $this->save($state);
    }

    /**
     * Whether the default website resolves credentials.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->settings->apiToken($this->websiteId()) !== null;
    }

    /**
     * The website Setup is for: the default store view's.
     *
     * @return int
     */
    public function websiteId(): int
    {
        $store = $this->storeManager->getDefaultStoreView();
        return $store === null ? 1 : (int)$store->getWebsiteId();
    }

    /**
     * Start the first catalog sync once the new service can take it.
     *
     * Called by the Setup page while it is open and by the sync cron, so it
     * happens whether or not the merchant keeps the page open. Idempotent: the
     * sync starts once, and never on a store that has synced before.
     *
     * @return void
     */
    public function advance(): void
    {
        $state = $this->state();
        if ($state === null || ($state['status'] ?? '') !== self::STATUS_IN_PROGRESS || !empty($state['sync'])) {
            return;
        }
        $websiteId = $this->websiteId();
        if (!$this->isConnected() || $this->hold->remainingSeconds($websiteId) > 0) {
            return;
        }
        $state['sync'] = true;
        $this->save($state);
        $this->lookUpChat($websiteId);
        if ($this->worker->progress($websiteId) !== null) {
            return;
        }
        try {
            $this->worker->startFullSync($websiteId);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                '[quissly] setup could not start the first sync website=%d: %s',
                $websiteId,
                $e->getMessage()
            ));
            unset($state['sync']);
            $this->save($state);
        }
    }

    /**
     * Look up the chat service once more if Connect could not (it is built with the search one).
     *
     * @param int $websiteId
     * @return void
     */
    private function lookUpChat(int $websiteId): void
    {
        if ($this->settings->qchatAgentId($websiteId) !== null) {
            return;
        }
        try {
            $agentId = $this->serviceDirectory->qchatAgentId($websiteId);
        } catch (\Throwable $e) {
            $agentId = null;
        }
        if ($agentId !== null) {
            $this->configWriter->save(self::PATH_QCHAT_AGENT_ID, $agentId);
            $this->reinitableConfig->reinit();
        }
    }

    /**
     * Finish Setup without going live (the Shopify app's "Save changes").
     *
     * For a merchant not ready to switch search on yet - or whose first sync needs another
     * look - so nobody is held on this screen. Needs the account's services built (the
     * settling period over); the first sync is started now if it has not been, and search
     * stays off until the merchant switches it on in Configuration.
     *
     * @return bool false while the new services are still being built
     */
    public function saveForLater(): bool
    {
        $websiteId = $this->websiteId();
        if (!$this->isConnected() || $this->hold->remainingSeconds($websiteId) > 0) {
            return false;
        }
        $this->advance();
        $state = $this->state() ?? [];
        $state['status'] = self::STATUS_COMPLETE;
        $this->save($state);
        $this->logger->info(sprintf('[quissly] setup saved without going live website=%d', $websiteId));
        return true;
    }

    /**
     * Start the first sync again after it stopped or delivered nothing.
     *
     * @return void
     */
    public function retrySync(): void
    {
        if ($this->syncFailed($this->worker->progress($this->websiteId()))) {
            $this->worker->startFullSync($this->websiteId());
        }
    }

    /**
     * The progress list on the Go live step.
     *
     * The Shopify app's rows: store identity, organization, project, QSearch service, QChat
     * service, catalog import. Here one Connect call creates the first three at once, and the
     * two services are built in the settling period after it.
     *
     * @return array{ready: bool, failed: bool, saveable: bool, rows: array<string, array>}
     */
    public function status(): array
    {
        $websiteId = $this->websiteId();
        $connected = $this->isConnected();
        $hold = $connected ? $this->hold->remainingSeconds($websiteId) : 0;
        $progress = $this->worker->progress($websiteId);
        $ready = $connected && $this->completion->isComplete($websiteId);
        $failed = !$ready && $this->syncFailed($progress);

        $created = $this->row($connected ? 'done' : 'pending', '');
        $building = !$connected ? 'pending' : ($hold > 0 ? 'running' : 'done');
        $chat = $this->settings->qchatAgentId($websiteId) !== null;
        $rows = [
            'store' => $created,
            'organization' => $created,
            'project' => $created,
            'qsearch' => $this->row(
                $building,
                $hold > 0 ? (string)__('Quissly is building your search service.') : ''
            ),
            'qchat' => $chat || $building !== 'done'
                ? $this->row($building, '')
                : $this->row('pending', (string)__('Not found yet - chat can still be switched on later.')),
            'catalog' => $this->catalogRow($connected, $hold, $progress, $ready, $failed),
        ];

        return [
            'ready' => $ready,
            'failed' => $failed,
            'saveable' => $connected && $hold === 0,
            'rows' => $rows,
        ];
    }

    /**
     * Go live: switch Quissly search on and finish Setup.
     *
     * @return bool false while the first sync has not finished
     */
    public function goLive(): bool
    {
        if (!$this->isConnected() || !$this->completion->isComplete($this->websiteId())) {
            return false;
        }
        // Default scope, like Connect: the setting every website inherits. A
        // website whose own first sync has not run stays on native search
        // regardless, because the interceptor also asks its gate.
        $this->configWriter->save(self::PATH_ENABLE_SEARCH, '1');
        $this->reinitableConfig->reinit();
        // Written through the config WRITER, which runs no backend model, so
        // the storefront caches are cleaned here (StorefrontSetting's job).
        $this->cacheTypeList->cleanType('config');
        $this->cacheTypeList->cleanType('full_page');
        $this->cacheTypeList->cleanType('block_html');

        $state = $this->state() ?? [];
        $state['status'] = self::STATUS_COMPLETE;
        $this->save($state);
        $this->logger->info(sprintf('[quissly] setup finished, search live website=%d', $this->websiteId()));
        return true;
    }

    /**
     * The catalog row, from the sync's own progress record.
     *
     * @param bool $connected
     * @param int $hold
     * @param array|null $progress
     * @param bool $ready
     * @param bool $failed
     * @return array{state: string, detail: string}
     */
    private function catalogRow(bool $connected, int $hold, ?array $progress, bool $ready, bool $failed): array
    {
        $total = (int)($progress['total'] ?? 0);
        $sent = (int)($progress['ok'] ?? 0);
        if ($ready) {
            return $this->row('done', $sent > 0 ? (string)__('%1 products sent.', $sent) : '');
        }
        if ($failed) {
            return $this->row('failed', !empty($progress['gate_blocked'])
                ? (string)__('No products reached Quissly. Check the log, then start the sync again.')
                : (string)__('The sync stopped before it finished. Start it again to continue.'));
        }
        if ($progress !== null) {
            return $this->row('running', (string)__('%1 of %2 products sent.', $sent, $total));
        }
        if ($connected && $hold === 0) {
            return $this->row('running', (string)__('Starting...'));
        }
        return $this->row('pending', (string)__('Starts as soon as the search service is ready.'));
    }

    /**
     * Whether the first sync ended without the catalog getting through.
     *
     * @param array|null $progress
     * @return bool
     */
    private function syncFailed(?array $progress): bool
    {
        return is_array($progress) && (!empty($progress['stalled']) || !empty($progress['gate_blocked']));
    }

    /**
     * Whether any scope holds credentials - a store connected before Setup existed.
     *
     * @return bool
     */
    private function anyConnected(): bool
    {
        if ($this->settings->apiToken(null) !== null) {
            return true;
        }
        foreach ($this->storeManager->getWebsites() as $website) {
            if ($this->settings->apiToken((int)$website->getId()) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * One progress row.
     *
     * @param string $state done|running|pending|failed
     * @param string $detail
     * @return array{state: string, detail: string}
     */
    private function row(string $state, string $detail): array
    {
        return ['state' => $state, 'detail' => $detail];
    }

    /**
     * The stored record, or null for a store that never started Setup.
     *
     * @return array|null
     */
    private function state(): ?array
    {
        $data = $this->flagManager->getFlagData(self::FLAG);
        return is_array($data) ? $data : null;
    }

    /**
     * Persist the record.
     *
     * @param array $state
     * @return void
     */
    private function save(array $state): void
    {
        $this->flagManager->saveFlag(self::FLAG, $state);
    }
}
