<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Config\Backend;

use Laminas\Uri\UriFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * The API host, refused unless it is safe to send credentials to.
 *
 * Every request to this host carries `Authorization: Bearer <token>` and a
 * valid v2 signature. Over plaintext http:// both are readable in transit; at
 * an arbitrary host both are simply handed over. The field was a bare text
 * input with no validation, so either was one admin save away - and
 * Quissly_Search::config is its own ACL resource, meaning a role scoped to
 * just this section could do it without ever being trusted with the token.
 *
 * Refuses on save with an explicit message rather than silently correcting,
 * because a merchant who typed a host needs to know it was not accepted.
 * Settings::apiBaseUrl() re-checks at read time, since bin/magento config:set
 * bypasses backend models entirely.
 */
class ApiBaseUrl extends StorefrontSetting
{
    /**
     * Hosts where plaintext is acceptable: the traffic never leaves the box.
     *
     * IPv6 appears here in its bracketed URL form, which is what the parser
     * returns for http://[::1]:8080 - the bare '::1' never matches a parsed
     * host and would be dead weight.
     */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];

    /**
     * Reject anything we would not send a bearer token to.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());

        // Empty is valid: the module falls back to the documented default.
        if ($value === '') {
            return parent::beforeSave();
        }

        try {
            $uri = UriFactory::factory($value);
        } catch (\Throwable $e) {
            throw new LocalizedException(
                __('Enter a full URL including the scheme, for example https://api.quissly.com.')
            );
        }

        $scheme = strtolower((string)$uri->getScheme());
        $host = strtolower((string)$uri->getHost());

        if ($scheme === '' || $host === '') {
            throw new LocalizedException(
                __('Enter a full URL including the scheme, for example https://api.quissly.com.')
            );
        }

        if ($scheme === 'https') {
            return parent::beforeSave();
        }
        if ($scheme === 'http' && in_array($host, self::LOOPBACK_HOSTS, true)) {
            // A local backend on loopback never puts credentials on a wire.
            return parent::beforeSave();
        }

        throw new LocalizedException(
            __(
                'The API URL must use https. Every request to it carries your API token, '
                . 'which would be readable in transit over http.'
            )
        );
    }
}
