<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Orders;

use Magento\Framework\FlagManager;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Config\Settings;

/**
 * Tells Quissly where each connected website answers order lookups (Controller/Orders/Lookup) - or that
 * it does not, while order status is off: `PUT <console>/api/v1/store/order-lookup` with the website's
 * store API key, once a day and whenever the address or the switch changed (cron `quissly_order_lookup`,
 * hourly). Never throws; a failure is logged once and tried again the next day.
 */
class LookupRegistration
{
    public const PATH = '/api/v1/store/order-lookup';
    public const EVERY = 86400;
    public const FLAG_PREFIX = 'quissly_order_lookup_w';
    private const TIMEOUT_SECONDS = 10;

    /**
     * @param StoreManagerInterface $storeManager
     * @param Settings $settings
     * @param FlagManager $flags
     * @param CurlFactory $curlFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Settings $settings,
        private readonly FlagManager $flags,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Register every connected website that is due.
     *
     * @param int $now
     * @return void
     */
    public function run(int $now): void
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            try {
                $this->registerWebsite((int)$website->getId(), $now);
            } catch (\Throwable $e) {
                $this->logger->warning('[quissly] order lookup registration failed: ' . get_class($e));
            }
        }
    }

    /**
     * Register one website when its address or switch changed, or a day passed.
     *
     * @param int $websiteId
     * @param int $now
     * @return void
     */
    private function registerWebsite(int $websiteId, int $now): void
    {
        $key = $this->settings->apiToken($websiteId);
        $project = (string)$this->settings->projectId($websiteId);
        if ($key === null || $project === '' || !$this->settings->isConfigured($websiteId)) {
            return;
        }
        $url = null;
        if ($this->settings->isOrderStatusEnabled($websiteId)) {
            $store = $this->storeManager->getWebsite($websiteId)->getDefaultStore();
            $url = $store
                ? (string)$store->getUrl('quissly/orders/lookup', ['_secure' => true, '_nosid' => true])
                : null;
        }
        $flag = self::FLAG_PREFIX . $websiteId;
        $state = (array)($this->flags->getFlagData($flag) ?? []);
        $same = array_key_exists('url', $state) && $state['url'] === $url;
        if ($same && $now - (int)($state['at'] ?? 0) < self::EVERY) {
            return;
        }
        // Claimed before the network: a run meanwhile does not ask again.
        $this->flags->saveFlag($flag, ['url' => $url, 'at' => $now, 'ok' => $state['ok'] ?? false]);

        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        $curl->addHeader('X-Store-Api-Key', $key);
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Accept', 'application/json');
        $curl->setOption(CURLOPT_CUSTOMREQUEST, 'PUT');
        $status = 0;
        try {
            $curl->post(
                $this->settings->consoleUrl($websiteId) . self::PATH,
                (string)json_encode(['project_id' => $project, 'url' => $url], JSON_UNESCAPED_SLASHES)
            );
            $status = (int)$curl->getStatus();
        } catch (\Throwable $e) {
            $status = 0;
        }
        $ok = $status >= 200 && $status < 300;
        if (!$ok && ($state['ok'] ?? true)) {
            $this->logger->warning(sprintf(
                '[quissly] could not tell Quissly where to check order status (website %d, HTTP %d); retried tomorrow',
                $websiteId,
                $status
            ));
        }
        $this->flags->saveFlag($flag, ['url' => $url, 'at' => $now, 'ok' => $ok]);
    }
}
