<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Media;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Api\MediaSearchClient;
use Quissly\Search\Model\Api\ResponseClassifier;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Health\MediaCapability;
use Quissly\Search\Model\Search\MediaThrottle;
use Quissly\Search\Model\Search\Shopper;
use Quissly\Search\Model\Search\TokenStore;

/**
 * Shared anonymous proxy for voice/image search.
 *
 * Anonymous by design - signing is server-side, so no credential ever reaches
 * the browser. THREE protections gate it before any API call, all of them
 * required: a server-side feature toggle (404 when off, so
 * a disabled feature is invisible), a strict body-size cap applied BEFORE the
 * payload is parsed, and a per-IP throttle. The throttle is not optional
 * boilerplate here: every accepted request costs the merchant a Gemini
 * transcription or image embedding, so an unthrottled anonymous endpoint is a
 * cost-amplification vector against a store that has done nothing wrong. On success the validated ids are stashed in a
 * single-use token and the browser is handed a same-origin results URL; the
 * ids themselves never travel to the client. While the feature is dark the
 * backend answers 403 (voice) / 503 (image), surfaced as an "unavailable"
 * JSON the widget renders as a graceful notice.
 */
abstract class AbstractMedia implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Room for the JSON envelope around the base64 media field.
     *
     * Keeps the raw-body check from rejecting a legitimate payload that is at
     * the cap, without letting an arbitrarily large body through.
     */
    private const ENVELOPE_ALLOWANCE_BYTES = 1024;

    /**
     * Anonymous JSON proxy - no form key required.
     *
     * Signing is server-side, so the protection is the toggle gate + body cap.
     * CSRF validation is skipped deliberately.
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
     * @param RequestInterface $request
     * @param ResultFactory $resultFactory
     * @param Settings $settings
     * @param MediaSearchClient $client
     * @param TokenStore $tokenStore
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $url
     * @param MediaCapability $capability
     * @param MediaThrottle $throttle
     * @param Shopper $shopper
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        protected readonly Settings $settings,
        private readonly MediaSearchClient $client,
        private readonly TokenStore $tokenStore,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $url,
        private readonly MediaCapability $capability,
        private readonly MediaThrottle $throttle,
        private readonly Shopper $shopper
    ) {
    }

    /**
     * Which media this controller proxies (a MediaSearchClient::KIND_* value).
     *
     * @return string
     */
    abstract protected function kind(): string;

    /**
     * Whether this feature is toggled on for the website (default OFF).
     *
     * @param int|null $websiteId
     * @return bool
     */
    abstract protected function isEnabled(?int $websiteId): bool;

    /**
     * The results-URL query param carrying the hand-off token.
     *
     * @return string
     */
    abstract protected function urlParam(): string;

    /**
     * Max accepted base64 payload length in bytes (media-specific).
     *
     * @return int
     */
    abstract protected function maxBytes(): int;

    /**
     * Visible search term when the API returns none (image has no transcription).
     *
     * Catalogsearch only runs an interceptable search when a query term is
     * present, so the results URL always carries one - the token then overrides
     * which products render.
     *
     * @return string
     */
    abstract protected function fallbackQuery(): string;

    /**
     * Proxy one voice/image search request.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $websiteId = $this->websiteId();

        // Feature-toggle gate: a disabled feature is a 404 - it does not exist.
        if (!$this->isEnabled($websiteId)) {
            return $this->resultFactory->create(ResultFactory::TYPE_FORWARD)->forward('noroute');
        }

        // Per-IP throttle: counted before any parsing or API call, so a flood
        // costs this process a cache read rather than a paid Gemini call.
        if (!$this->throttle->allow($this->kind())) {
            return $this->json(['status' => 'error', 'message' => 'Too many requests.'], 429);
        }

        // Size check on the RAW body first. Checking only the decoded media
        // field means a huge POST is fully parsed before being rejected, which
        // hands an anonymous caller a cheap way to spend our memory and CPU.
        $raw = (string)$this->request->getContent();
        if (strlen($raw) > $this->maxBytes() + self::ENVELOPE_ALLOWANCE_BYTES) {
            return $this->json(['status' => 'error', 'message' => 'Media too large.'], 413);
        }

        $payload = json_decode($raw, true);
        $media = is_array($payload) ? (string)($payload['media'] ?? '') : '';

        if ($media === '') {
            return $this->json(['status' => 'error', 'message' => 'No media supplied.'], 400);
        }
        if (strlen($media) > $this->maxBytes()) {
            return $this->json(['status' => 'error', 'message' => 'Media too large.'], 413);
        }

        $result = $this->client->search($this->kind(), $media, $websiteId, $this->shopper->userId());

        if ($result['code'] !== ResponseClassifier::OK) {
            // A per-tenant gate (voice 403, image 503) means Quissly has not
            // enabled this feature: remember it so the storefront stops drawing
            // a control that cannot work. Transient failures must NOT hide it.
            if ($result['code'] === ResponseClassifier::FORBIDDEN || $result['status'] === 503) {
                $this->capability->markUnavailable($websiteId, $this->kind(), $result['code']);
            } else {
                $this->capability->markFailure($websiteId, $this->kind(), $result['code']);
            }
            // The reason stays server-side. The classifier codes name the merchant's
            // private business state - payment_required, auth_error,
            // clock_skew_suspected - and this endpoint is anonymous by necessity, so
            // returning one told any visitor that a store had not paid its bill:
            // exactly the leak SearchDiagnosticHeader exists to prevent. The
            // merchant still gets the reason, explained rather than coded, on the
            // admin Dashboard - the mark* calls above are what put it there. The
            // browser never read this field; it shows one generic notice either way.
            return $this->json(['status' => 'unavailable'], 200);
        }
        $this->capability->markAvailable($websiteId, $this->kind());

        // A search that matched nothing is a RESULT, not a failure, so it goes to
        // the results page like any other and the theme renders its own empty
        // state - the page a shopper gets when a typed search finds nothing.
        // Reporting it back to the widget instead put "No similar products found"
        // under the search box and left the shopper on whatever page they were
        // on, which reads as the feature having broken rather than as an answer.
        // An empty token carries that answer: the interceptor renders zero ids
        // and never falls through to a native query for the placeholder term.
        // The variants ride along: the tiles are rendered on a later request,
        // so the token is the only thing that survives to carry them.
        $token = $this->tokenStore->store($result['ids'], $result['variants'] ?? []);
        return $this->json(['status' => 'ok', 'redirect' => $this->resultsUrl($token, $result['query'])], 200);
    }

    /**
     * Build the same-origin results URL carrying the single-use token.
     *
     * @param string $token
     * @param string $query visible search term (voice transcription; '' image)
     * @return string
     */
    private function resultsUrl(string $token, string $query): string
    {
        $params = [
            $this->urlParam() => $token,
            'q' => $query !== '' ? $query : $this->fallbackQuery(),
        ];
        return $this->url->getUrl('catalogsearch/result', ['_query' => $params]);
    }

    /**
     * Current website id (credentials + toggles are website-scoped).
     *
     * @return int|null
     */
    private function websiteId(): ?int
    {
        try {
            return (int)$this->storeManager->getStore()->getWebsiteId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * A JSON result with an explicit HTTP status.
     *
     * @param array $data
     * @param int $httpStatus
     * @return JsonResult
     */
    private function json(array $data, int $httpStatus): JsonResult
    {
        /** @var JsonResult $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setHttpResponseCode($httpStatus);
        $result->setData($data);
        return $result;
    }
}
