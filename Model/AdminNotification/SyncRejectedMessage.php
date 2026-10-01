<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\AdminNotification;

use Magento\Framework\Notification\MessageInterface;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Api\ResponseClassifier;
use Quissly\Search\Model\Health\HealthRecorder;

/**
 * Raises an admin banner when Quissly is refusing this store's catalog sync.
 *
 * A refusal is silent by design elsewhere: shoppers keep getting native search
 * and nothing breaks, so the only trace is a health record on a dashboard
 * nobody has a reason to open. The merchant needs to be told, on whatever page
 * they are already looking at.
 *
 * Deliberately narrow. It fires for account refusals - no trial, no quota, the
 * service switched off - and NOT for timeouts, rate limits or a bad gateway,
 * which clear themselves and would train merchants to dismiss the banner
 * without reading it.
 */
class SyncRejectedMessage implements MessageInterface
{
    private const IDENTITY = 'quissly_sync_rejected';

    /**
     * @param HealthRecorder $health
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly HealthRecorder $health,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getIdentity()
    {
        // Stable across websites: several refused at once is still one
        // problem, and one banner rather than five. Not md5 - the Magento
        // standard forbids it, and there is no reason to reach for a broken
        // hash when this is only an identity string.
        return hash('sha256', self::IDENTITY);
    }

    /**
     * @inheritdoc
     */
    public function isDisplayed()
    {
        return $this->refusedWebsites() !== [];
    }

    /**
     * @inheritdoc
     */
    public function getText()
    {
        $names = $this->refusedWebsites();
        if ($names === []) {
            return '';
        }

        return (string)__(
            'Quissly is not accepting catalog updates for %1. Products changed since then '
            . 'are queued and will send once this is resolved - nothing has been lost. %2 '
            . 'See Quissly > Dashboard for details.',
            implode(', ', array_keys($names)),
            $this->advice(array_unique(array_values($names)))
        );
    }

    /**
     * @inheritdoc
     */
    public function getSeverity()
    {
        // MAJOR, not CRITICAL: the storefront still works - shoppers get
        // Magento's own search - so this is urgent for the merchant without
        // being an outage.
        return self::SEVERITY_MAJOR;
    }

    /**
     * Websites whose last catalog call was refused by Quissly.
     *
     * @return array<int, string>
     */
    private function refusedWebsites(): array
    {
        $refused = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $failure = $this->health->currentFailure((int)$website->getId());
            if ($failure !== null && $this->isRejection((string)$failure['code'])) {
                $refused[(string)$website->getName()] = (string)$failure['code'];
            }
        }

        return $refused;
    }

    /**
     * What to actually do about it, which differs by cause.
     *
     * "Check your Quissly plan" is useless advice for a server clock that has
     * drifted, and the merchant who most needs a clear next step is the one
     * whose problem is not the obvious one.
     *
     * @param array $codes
     * @return string
     */
    private function advice(array $codes): string
    {
        if (count($codes) > 1) {
            return (string)__('The cause differs by website.');
        }

        switch ($codes[0] ?? '') {
            case ResponseClassifier::CLOCK_SKEW_SUSPECTED:
                return (string)__(
                    "This server's clock is too far from Quissly's, so every request is "
                    . 'rejected. Check NTP on the server.'
                );
            case ResponseClassifier::AUTH_ERROR:
                return (string)__('Quissly did not accept this store\'s credentials.');
            default:
                return (string)__('Check your Quissly plan.');
        }
    }

    /**
     * Whether the recorded failure is a refusal rather than a hiccup.
     *
     * @param string $code
     * @return bool
     */
    private function isRejection(string $code): bool
    {
        return in_array(
            $code,
            [
                ResponseClassifier::PAYMENT_REQUIRED,
                ResponseClassifier::FORBIDDEN,
                ResponseClassifier::AUTH_ERROR,
                ResponseClassifier::CLOCK_SKEW_SUSPECTED,
            ],
            true
        );
    }
}
