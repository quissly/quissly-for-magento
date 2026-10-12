<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Events;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Events\EventBody;
use Quissly\Search\Model\Events\Recorder;
use Quissly\Search\Model\Search\MediaThrottle;

/**
 * `POST /quissly/events/collect`: the two events only the browser sees - a product page
 * view and a search (`quissly-events.js`; Magento's page cache serves those pages without
 * running any PHP, so no observer can).
 *
 * Anonymous like the other storefront endpoints, with the same three protections: 404
 * while the switch is off or the website is not connected, a body cap, and the per-IP
 * throttle (a higher limit than the paid media proxies: an event costs the merchant
 * nothing, and a shop floor's shared IP browses fast). The browser says only what was
 * viewed or searched; who the shopper is comes from the request (Recorder / Shopper),
 * and a product id that is not in the catalog is refused.
 *
 * Writes a queue row and nothing else - nothing is forwarded from this request.
 */
class Collect implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const KIND = 'events';
    public const LIMIT_PER_MINUTE = 60;
    public const MAX_BODY_BYTES = 1024;

    /**
     * @param RequestInterface $request
     * @param ResultFactory $resultFactory
     * @param StoreManagerInterface $storeManager
     * @param Recorder $recorder
     * @param EventBody $body
     * @param MediaThrottle $throttle
     * @param ProductResource $products
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Recorder $recorder,
        private readonly EventBody $body,
        private readonly MediaThrottle $throttle,
        private readonly ProductResource $products
    ) {
    }

    /**
     * Sent by sendBeacon, which cannot carry a form key; it writes only an analytics row.
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
     * Record the event.
     *
     * @return Raw
     */
    public function execute(): Raw
    {
        if (!$this->recorder->enabled((int)$this->storeManager->getStore()->getWebsiteId())) {
            return $this->status(404);
        }
        if (!$this->throttle->allow(self::KIND, self::LIMIT_PER_MINUTE)) {
            return $this->status(429);
        }
        $raw = method_exists($this->request, 'getContent') ? (string)$this->request->getContent() : '';
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return $this->status(413);
        }
        $event = json_decode($raw, true);
        $type = is_array($event) ? (string)($event['type'] ?? '') : '';

        if ($type === EventBody::VIEW) {
            $productId = $this->productId($event['product_id'] ?? null);
            if ($productId === null) {
                return $this->status(400);
            }
            $this->recorder->shopper(EventBody::VIEW, $this->body->view($productId));
            return $this->status(204);
        }
        if ($type === EventBody::SEARCH) {
            $metadata = $this->body->search(
                is_string($event['query'] ?? null) ? $event['query'] : '',
                is_string($event['modality'] ?? null) ? $event['modality'] : ''
            );
            if ($metadata === null) {
                return $this->status(400);
            }
            $this->recorder->shopper(EventBody::SEARCH, $metadata);
            return $this->status(204);
        }
        return $this->status(400);
    }

    /**
     * A catalog product id, or null.
     *
     * @param mixed $value
     * @return int|null
     */
    private function productId($value): ?int
    {
        if (!is_scalar($value) || !preg_match('/^[1-9]\d{0,9}$/', (string)$value)) {
            return null;
        }
        $id = (int)$value;
        return $id <= 2147483647 && $this->products->getProductsSku([$id]) !== [] ? $id : null;
    }

    /**
     * An empty answer with a status.
     *
     * @param int $code
     * @return Raw
     */
    private function status(int $code): Raw
    {
        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode($code);
        return $result->setContents('');
    }
}
