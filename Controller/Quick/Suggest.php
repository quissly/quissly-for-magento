<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Quick;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Api\QuickClient;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Search\MediaThrottle;
use Quissly\Search\Model\Search\Shopper;
use Quissly\Search\Model\Search\SuggestionHydrator;

/**
 * Anonymous type-ahead proxy for the storefront.
 *
 * Anonymous by design: signing happens server-side, so the browser holds no
 * credential. The protections are therefore the toggle gate (404 when the
 * feature is off, so a disabled store exposes no endpoint at all), a hard
 * length cap on the term, and the same per-IP throttle the media proxies use.
 * All three exist before the endpoint ships - an unthrottled anonymous route
 * that reaches a paid API is an invitation.
 *
 * Every failure answers 200 with an empty list rather than an error status.
 * A dropdown that cannot suggest anything should quietly not appear; a
 * shopper mid-keystroke has no use for a diagnostic.
 */
class Suggest implements HttpGetActionInterface, CsrfAwareActionInterface
{
    /** Long enough for any real query; short enough that nothing is a payload. */
    private const MAX_TERM_LENGTH = 150;

    /** Below this, suggestions are noise - matches the widget's own floor. */
    private const MIN_TERM_LENGTH = 2;

    /**
     * @param RequestInterface $request
     * @param ResultFactory $resultFactory
     * @param Settings $settings
     * @param QuickClient $client
     * @param MediaThrottle $throttle
     * @param SuggestionHydrator $hydrator
     * @param StoreManagerInterface $storeManager
     * @param Shopper $shopper
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly Settings $settings,
        private readonly QuickClient $client,
        private readonly MediaThrottle $throttle,
        private readonly SuggestionHydrator $hydrator,
        private readonly StoreManagerInterface $storeManager,
        private readonly Shopper $shopper
    ) {
    }

    /**
     * Read-only GET with no credential; a form key would buy nothing.
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
     * Answer a type-ahead request.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $websiteId = (int)$this->storeManager->getStore()->getWebsiteId();

        // Off means gone: a store with Quick disabled must not expose a route
        // that reaches Quissly at all.
        if (!$this->settings->isQuickEnabled($websiteId)) {
            return $this->notFound();
        }

        if (!$this->throttle->allow(QuickClient::KIND)) {
            return $this->empty();
        }

        $term = trim((string)$this->request->getParam('q', ''));
        if (mb_strlen($term) < self::MIN_TERM_LENGTH || mb_strlen($term) > self::MAX_TERM_LENGTH) {
            return $this->empty();
        }

        // Quick answers with ids; everything the shopper sees comes from
        // Magento, in their store view's language, currency and image sizes.
        $ids = $this->client->suggest($term, $websiteId, $this->shopper->userId());

        return $this->json(['suggestions' => $this->hydrator->hydrate($ids)]);
    }

    /**
     * A well-formed answer carrying nothing to show.
     *
     * @return Json
     */
    private function empty(): Json
    {
        return $this->json(['suggestions' => []]);
    }

    /**
     * The feature is off for this website; the route does not exist.
     *
     * @return Json
     */
    private function notFound(): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setHttpResponseCode(404);

        return $result->setData(['suggestions' => []]);
    }

    /**
     * Build a JSON result.
     *
     * @param array $data
     * @return Json
     */
    private function json(array $data): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        return $result->setData($data);
    }
}
