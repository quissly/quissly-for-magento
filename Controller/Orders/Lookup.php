<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Orders;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Quissly\Search\Model\Orders\LookupRules;
use Quissly\Search\Model\Orders\OrderLookup;

/**
 * `POST /quissly/orders/lookup`: Quissly's chat asking "where is order N?" with the email or phone the
 * shopper gave (the Shopify app's order lookup, for Magento).
 *
 * Only Quissly may ask: the body must be signed with Quissly's order lookup key (LookupRules::verify),
 * name a website connected to that project with order status on, be fresh, and carry a nonce not seen in
 * NONCE_TTL. Anything else is a 401 with no body, before any order is read. Then a store-wide limit
 * (LIMIT_PER_MINUTE), and OrderLookup checks the contact on the order itself. Signed instead of a form
 * key (it comes from Quissly's server, not a browser); the answer is never cached.
 */
class Lookup implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const NONCE_TTL = 600;
    public const LIMIT_PER_MINUTE = 60;
    private const CACHE_TAG = 'QUISSLY_ORDER_LOOKUP';

    /**
     * @param RequestInterface $request
     * @param ResultFactory $resultFactory
     * @param CacheInterface $cache
     * @param OrderLookup $lookup
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly CacheInterface $cache,
        private readonly OrderLookup $lookup
    ) {
    }

    /**
     * Signed by Quissly's server, not a browser form: no form key.
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Accept the request without a form key (see above).
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Answer one lookup.
     *
     * @return Json
     */
    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setHeader('Cache-Control', 'no-store', true);
        $body = $this->request instanceof Http ? (string)$this->request->getContent() : '';
        $ask = LookupRules::verify(
            $body,
            (string)$this->request->getHeader('X-Quissly-Signature'),
            (string)$this->request->getHeader('X-Quissly-Key-Id'),
            time()
        );
        $websiteId = $ask !== null ? $this->lookup->websiteFor($ask['project_id']) : null;
        if ($ask === null || $websiteId === null || !$this->claimNonce($ask['nonce'])) {
            return $result->setHttpResponseCode(401)->setData([]);
        }
        if (!$this->withinLimit()) {
            return $result->setHttpResponseCode(429)->setData([]);
        }

        return $result->setData($this->lookup->answer($websiteId, $ask['order_number'], $ask['contact']));
    }

    /**
     * Claim the nonce for NONCE_TTL; false when it was used already.
     *
     * @param string $nonce
     * @return bool
     */
    private function claimNonce(string $nonce): bool
    {
        $key = 'quissly_oln_' . hash('sha256', $nonce);
        if ($this->cache->load($key) !== false) {
            return false;
        }
        $this->cache->save('1', $key, [self::CACHE_TAG], self::NONCE_TTL);

        return true;
    }

    /**
     * The store-wide limit: LIMIT_PER_MINUTE lookups a minute.
     *
     * @return bool
     */
    private function withinLimit(): bool
    {
        $key = 'quissly_olr_' . gmdate('YmdHi');
        $count = (int)$this->cache->load($key);
        if ($count >= self::LIMIT_PER_MINUTE) {
            return false;
        }
        $this->cache->save((string)($count + 1), $key, [self::CACHE_TAG], 120);

        return true;
    }
}
