<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Api\MediaSearchClient;
use Quissly\Search\Model\Api\QuickClient;
use Quissly\Search\Model\Api\ResponseClassifier;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Connect\ConnectHold;
use Quissly\Search\Model\Health\HealthRecorder;
use Quissly\Search\Model\Health\MediaCapability;
use Quissly\Search\Model\Sync\FirstSyncGate;
use Quissly\Search\Model\Sync\SyncWorker;

/**
 * Per-website status for the Quissly dashboard.
 *
 * The organising idea is the TWO-SWITCH model: every optional
 * feature needs the merchant's toggle AND Quissly's per-tenant enablement, and
 * until now a merchant could see only their own half. A toggle reading "Yes"
 * while the storefront silently suppressed the control was indistinguishable
 * from a broken module.
 */
class Dashboard extends Template
{
    /** Quissly-side state for a feature that exists but waits on the first sync. */
    public const STATUS_AFTER_SYNC = 'after_sync';

    /**
     * @param Context $context
     * @param Settings $settings
     * @param FirstSyncGate $gate
     * @param SyncWorker $worker
     * @param HealthRecorder $health
     * @param MediaCapability $capability
     * @param ResponseClassifier $classifier
     * @param StoreManagerInterface $storeManager
     * @param ConnectHold $hold
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Settings $settings,
        private readonly FirstSyncGate $gate,
        private readonly SyncWorker $worker,
        private readonly HealthRecorder $health,
        private readonly MediaCapability $capability,
        private readonly ResponseClassifier $classifier,
        private readonly StoreManagerInterface $storeManager,
        private readonly ConnectHold $hold,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * One status row per website.
     *
     * Multi-website merchants configure each separately,
     * so a single global view would lie.
     *
     * @return array<int, array<string, mixed>>
     */
    public function websites(): array
    {
        $rows = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $id = (int)$website->getId();
            $rows[] = [
                'service_fingerprint' => $this->serviceFingerprint($id),
                'id' => $id,
                'name' => (string)$website->getName(),
                'configured' => $this->settings->isConfigured($id),
                'environment' => $this->settings->environment($id),
                'api_host' => $this->settings->apiBaseUrl($id),
                'gate_open' => $this->gate->isOpen($id),
                // Settling period after a connect: while above zero the sync
                // button is disabled with a countdown (ConnectHold).
                'hold_remaining' => $this->hold->remainingSeconds($id),
                'intercepting' => $this->isIntercepting($id),
                'progress' => $this->worker->progress($id),
                'health' => $this->healthDetail($id),
                'features' => $this->features($id),
                'sync_url' => $this->getUrl('quissly/dashboard/sync', ['website_id' => $id]),
            ];
        }
        return $this->flagSharedServices($rows);
    }

    /**
     * Identifies the Quissly service a website resolves to.
     *
     * A HASH of host + token: two websites sharing a service must be
     * detectable, but a credential has no business being held in a view model
     * or reaching a template.
     *
     * @param int $websiteId
     * @return string
     */
    private function serviceFingerprint(int $websiteId): string
    {
        $token = (string)$this->settings->apiToken($websiteId);
        if ($token === '') {
            return '';
        }
        return hash('sha256', $this->settings->apiBaseUrl($websiteId) . '|' . $token);
    }

    /**
     * Warn when websites share one Quissly service.
     *
     * Products are identified by their Magento id, which is the same number on
     * every website - so two websites pushing to one service overwrite each
     * other's copy of every SHARED product, and the last sync wins with its own
     * title, description, URL and stock. Search results stay correct (ids are
     * ids); what breaks is the rendered card and the link.
     *
     * Worth surfacing because the configuration looks entirely healthy:
     * ConnectionService::test() checks one website in isolation, so both report
     * fine. Nothing else in the module can see the collision.
     *
     * @param array $rows
     * @return array
     */
    private function flagSharedServices(array $rows): array
    {
        $seen = [];
        foreach ($rows as $row) {
            $print = (string)$row['service_fingerprint'];
            if ($print !== '') {
                $seen[$print] = ($seen[$print] ?? 0) + 1;
            }
        }
        foreach ($rows as &$row) {
            $print = (string)$row['service_fingerprint'];
            $row['shares_service'] = $print !== '' && ($seen[$print] ?? 0) > 1;
        }
        return $rows;
    }

    /**
     * What the merchant should know about chat, and what to do next.
     *
     * The agent exists from the moment Connect succeeds, well before the widget
     * can be switched on, so "try it on your storefront" is only true once the
     * toggle is on (2026-09-10).
     *
     * @param int $websiteId
     * @param string|null $agentId
     * @return string
     */
    private function chatDetail(int $websiteId, ?string $agentId): string
    {
        if ($agentId === null) {
            return (string)__(
                'No chat agent on Quissly\'s side for this store - reconnect, or set the QChat '
                . 'Agent ID under Features.'
            );
        }
        $agent = substr($agentId, 0, 8) . '…';
        if ($this->settings->isQchatEnabled($websiteId)) {
            return (string)__(
                'Chat agent %1 exists on Quissly\'s side. The widget talks to Quissly directly '
                . 'from the shopper\'s browser, so this page cannot see chat traffic - open the '
                . 'chat on your storefront to try it.',
                $agent
            );
        }
        if (!$this->gate->isOpen($websiteId)) {
            return (string)__(
                'Chat agent %1 is ready on Quissly\'s side. Switch QChat on under Features once the '
                . 'first catalog sync has finished; the widget then appears on every storefront page.',
                $agent
            );
        }
        return (string)__(
            'Chat agent %1 is ready on Quissly\'s side. Switch QChat on under Features; the widget '
            . 'then appears on every storefront page.',
            $agent
        );
    }

    /**
     * The standing connection failure, explained rather than coded.
     *
     * ResponseClassifier::describe() already carries the operator-facing text
     * for each code - notably that a clock-skew 401 is an NTP problem, not a
     * credentials problem, which is the single hardest symptom in this
     * protocol to diagnose. Showing the bare code made a merchant search the
     * internet for "clock_skew_suspected".
     *
     * @param int $websiteId
     * @return array|null Shape: {code: string, at: int, explanation: string}
     */
    private function healthDetail(int $websiteId): ?array
    {
        $failure = $this->health->currentFailure($websiteId);
        if ($failure === null) {
            return null;
        }
        $failure['explanation'] = $this->classifier->describe((string)$failure['code']);
        return $failure;
    }

    /**
     * Whether shopper searches on this website are actually being intercepted.
     *
     * Deliberately recomputed from the same three conditions the plugin uses,
     * rather than reporting the toggle alone: the toggle is the merchant's
     * intent, this is the outcome.
     *
     * @param int $websiteId
     * @return bool
     */
    private function isIntercepting(int $websiteId): bool
    {
        return $this->settings->isSearchEnabled($websiteId)
            && $this->settings->isConfigured($websiteId)
            && $this->gate->isOpen($websiteId);
    }

    /**
     * Both switches for every optional feature.
     *
     * @param int $websiteId
     * @return array<int, array<string, mixed>>
     */
    private function features(int $websiteId): array
    {
        $agentId = $this->settings->qchatAgentId($websiteId);
        $gateOpen = $this->gate->isOpen($websiteId);
        $voice = $this->assumeEnabledAfterSync(
            $this->capability->status($websiteId, MediaSearchClient::KIND_VOICE),
            $gateOpen
        );
        $image = $this->assumeEnabledAfterSync(
            $this->capability->status($websiteId, MediaSearchClient::KIND_IMAGE),
            $gateOpen
        );
        $quick = $this->assumeEnabledAfterSync($this->capability->status($websiteId, QuickClient::KIND), $gateOpen);

        return [
            [
                'label' => __('Voice search'),
                'merchant' => $this->settings->isVoiceEnabled($websiteId),
                'quissly' => $voice['status'],
                'detail' => $this->statusDetail($voice),
            ],
            [
                'label' => __('Image search'),
                'merchant' => $this->settings->isImageEnabled($websiteId),
                'quissly' => $image['status'],
                'detail' => $this->statusDetail($image),
            ],
            [
                // QuickClient records this on every call, including the
                // per-tenant 404 ("Quick suggestions are not enabled for this
                // service"). Recording it and then not showing it would leave a
                // merchant with an empty dropdown and no way to learn why -
        // exactly the guessing the capability exists to prevent.
                'label' => __('Quick suggestions'),
                'merchant' => $this->settings->isQuickEnabled($websiteId),
                'quissly' => $quick['status'],
                'detail' => $this->statusDetail($quick),
            ],
            [
                // The module never calls the chat backend - the widget does,
                // from the shopper's browser - so it observes no chat traffic
                // and must not invent any. What it CAN vouch for is
                // that Quissly created a chat agent for this store: the id
                // came back from Quissly's own service directory at Connect.
                // "Not checked yet" next to "cannot verify" read as a
                // contradiction (2026-09-10); say what is known instead.
                'label' => __('Chat (QChat)'),
                'merchant' => $this->settings->isQchatEnabled($websiteId) && $agentId !== null,
                // Before the first sync the agent exists but nothing can use it
                // yet; "Yes" beside a feature that cannot be switched on reads as
                // a contradiction (2026-09-10) - say when instead.
                'quissly' => $agentId === null
                    ? MediaCapability::STATUS_BLOCKED
                    : ($this->gate->isOpen($websiteId) ? MediaCapability::STATUS_OK : self::STATUS_AFTER_SYNC),
                'detail' => $this->chatDetail($websiteId, $agentId),
            ],
            [
                'label' => __('Search overlay'),
                'merchant' => $this->settings->isOverlayEnabled($websiteId),
                'quissly' => MediaCapability::STATUS_OK,
                'detail' => (string)__('Presentation only - needs no Quissly enablement.'),
            ],
        ];
    }

    /**
     * Quissly enables voice, image and Quick for an account when its first
     * catalog sync completes (2026-09-10), so a store that has synced and
     * never been refused is enabled - "not checked yet" beside a feature that is
     * known to work only told the merchant we had not looked. A refusal that
     * WAS observed still wins: the column never claims more than the evidence.
     *
     * @param array $status MediaCapability::status()
     * @param bool $gateOpen first sync complete
     * @return array
     */
    private function assumeEnabledAfterSync(array $status, bool $gateOpen): array
    {
        $neverObserved = $status['status'] === MediaCapability::STATUS_UNKNOWN && $status['at'] === null;
        if (!$neverObserved) {
            return $status;
        }
        return [
            'status' => $gateOpen ? MediaCapability::STATUS_OK : self::STATUS_AFTER_SYNC,
            'code' => '',
            'at' => null,
            'assumed' => true,
        ];
    }

    /**
     * Human-readable explanation of the last observation.
     *
     * Always states WHEN, because the check is reactive: this reports what was
     * last seen, never a live probe. "Not checked yet" is said plainly
     * rather than dressed up as either success or failure.
     *
     * @param array $status Shape: {status: string, code: string, at: int|null}
     * @return string
     */
    private function statusDetail(array $status): string
    {
        // Never checked is the only case where nothing has been observed. An
        // UNKNOWN that carries a timestamp HAS been tried and HAS failed - just
        // not often enough to call it blocked - and that is precisely the
        // moment a merchant is most likely to be reading this page. Telling
        // them "no shopper has used this" then would be false.
        if (!empty($status['assumed'])) {
            return $status['status'] === MediaCapability::STATUS_OK
                ? (string)__('Enabled with your first catalog sync. A refusal from Quissly would show here.')
                : (string)__('Quissly enables this once the first catalog sync has finished.');
        }
        if ($status['at'] === null) {
            return (string)__('Not checked yet - no shopper has used this feature recently.');
        }

        $when = $this->formatDate(date('c', (int)$status['at']), \IntlDateFormatter::MEDIUM, true);

        if ($status['status'] === MediaCapability::STATUS_UNKNOWN) {
            if ($status['code'] !== '') {
                return (string)__(
                    'A recent attempt failed (%1), but not consistently enough to confirm (last tried %2).',
                    $status['code'],
                    $when
                );
            }
            return (string)__(
                'A recent attempt failed, but not consistently enough to confirm (last tried %1).',
                $when
            );
        }

        if ($status['status'] === MediaCapability::STATUS_OK) {
            return (string)__('Working when last used (%1).', $when);
        }
        if ($status['code'] === ResponseClassifier::FORBIDDEN) {
            return (string)__('Quissly has not enabled this for your account (last checked %1).', $when);
        }
        return (string)__('Quissly could not serve this (%1, last checked %2).', $status['code'], $when);
    }

    /**
     * When the most recent drain ran, in the admin's own timezone.
     *
     * Empty when nothing has run yet, which the template reads as "say
     * nothing" rather than printing a date from 1970.
     *
     * @param array $progress
     * @return string
     */
    public function lastRunAt(array $progress): string
    {
        $at = (int)($progress['last_run']['at'] ?? 0);
        if ($at <= 0) {
            return '';
        }
        return (string)$this->formatTime(
            new \DateTime('@' . $at, new \DateTimeZone('UTC')),
            \IntlDateFormatter::SHORT,
            true
        );
    }
}
