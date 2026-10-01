<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

use Magento\Cookie\Helper\Cookie as CookieConsent;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\Header;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Who is searching - the shopper details sent with a storefront search, in the Quissly
 * Shopify app's scheme (the WooCommerce and CS-Cart plugins send the same):
 *
 *   user_id  customer:<id>  a signed-in customer - stable across devices and sessions;
 *            guest:<uuid>   an anonymous browser - a UUID kept in the first-party quissly_uid
 *                           cookie for a year. One device, not one person. None (null) while
 *                           the store's cookie notice (Magento's cookie restriction mode) has
 *                           not been accepted, as the Shopify app withholds it without consent.
 *   device / os             from the browser's User-Agent (text search only, as Shopify).
 *
 * Only for pages Magento does not cache (Plugin/Frontend/SearchPageCache): a cached page is
 * served to other shoppers, and Magento clears the customer session while building one.
 */
class Shopper
{
    public const COOKIE = 'quissly_uid';
    public const CUSTOMER_PREFIX = 'customer:';
    public const GUEST_PREFIX = 'guest:';
    private const YEAR = 31536000;

    /** @var string|null|false false = not resolved yet (memo: one cookie mint per request) */
    private $userId = false;

    /**
     * @param CustomerSession $customerSession
     * @param CookieManagerInterface $cookies
     * @param CookieMetadataFactory $cookieMetadata
     * @param CookieConsent $consent
     * @param Header $header
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CookieManagerInterface $cookies,
        private readonly CookieMetadataFactory $cookieMetadata,
        private readonly CookieConsent $consent,
        private readonly Header $header,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * The body fields for a text search: user_id, device and (when known) os.
     *
     * @return array{user_id: string|null, device: string, os?: string}
     */
    public function forSearch(): array
    {
        $fields = ['user_id' => $this->userId()] + $this->deviceAndOs((string)$this->header->getHttpUserAgent());
        if ($fields['os'] === '') {
            unset($fields['os']);
        }
        return $fields;
    }

    /**
     * The shopper's id: customer:<id>, guest:<uuid>, or null without cookie consent.
     *
     * @return string|null
     */
    public function userId(): ?string
    {
        if ($this->userId === false) {
            $this->userId = $this->resolve();
        }
        return $this->userId;
    }

    /**
     * The shopper's device and OS (QueryRequestV2 `device` / `os`).
     *
     * `os` is '' when unknown (then not sent); anything unrecognised counts as a desktop.
     *
     * @param string $userAgent
     * @return array{device: string, os: string}
     */
    public function deviceAndOs(string $userAgent): array
    {
        $ua = strtolower($userAgent);
        return match (true) {
            str_contains($ua, 'ipad') => ['device' => 'tablet', 'os' => 'iOS'],
            str_contains($ua, 'iphone') => ['device' => 'mobile', 'os' => 'iOS'],
            str_contains($ua, 'android') => [
                'device' => str_contains($ua, 'mobile') ? 'mobile' : 'tablet',
                'os' => 'Android',
            ],
            str_contains($ua, 'mac os x') => ['device' => 'desktop', 'os' => 'macOS'],
            str_contains($ua, 'windows nt') => ['device' => 'desktop', 'os' => 'Windows'],
            str_contains($ua, 'linux') => ['device' => 'desktop', 'os' => 'Linux'],
            default => ['device' => 'desktop', 'os' => ''],
        };
    }

    /**
     * Only a UUID v4 - the shape the cookie is minted with - is trusted.
     *
     * The cookie is client-supplied; a free-form value would let a visitor pick (or replay)
     * an identity.
     *
     * @param string $value
     * @return bool
     */
    public function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * Resolve the shopper's id (uncached).
     *
     * @return string|null
     */
    private function resolve(): ?string
    {
        $customerId = (int)$this->customerSession->getCustomerId();
        if ($customerId > 0) {
            return self::CUSTOMER_PREFIX . $customerId;
        }
        if ($this->consent->isUserNotAllowSaveCookie()) {
            return null;
        }
        $cookie = (string)$this->cookies->getCookie(self::COOKIE);
        if ($this->isUuid($cookie)) {
            return self::GUEST_PREFIX . strtolower($cookie);
        }
        $uuid = $this->newUuid();
        try {
            $metadata = $this->cookieMetadata->createPublicCookieMetadata()
                ->setDuration(self::YEAR)
                ->setPath('/')
                ->setHttpOnly(true) // nothing client-side reads it
                ->setSecure(method_exists($this->request, 'isSecure') && $this->request->isSecure())
                ->setSameSite('Lax');
            $this->cookies->setPublicCookie(self::COOKIE, $uuid, $metadata);
        } catch (\Throwable $e) {
            // Headers already sent, or a cookie Magento refuses: this search still counts.
            unset($e);
        }
        return self::GUEST_PREFIX . $uuid;
    }

    /**
     * A fresh UUID v4.
     *
     * @return string
     */
    private function newUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000, // version 4
            random_int(0, 0x3fff) | 0x8000, // RFC 4122 variant
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}
