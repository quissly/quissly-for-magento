<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Api;

use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Config\Settings;

/**
 * Creates the store's Quissly account, so the merchant never types a credential.
 *
 * The endpoint takes exactly what Magento already knows - its own domain, an
 * admin email, and a public key the module generates - and returns the API key
 * and project id. Everything onboarding previously asked a human to fetch and
 * paste, obtained in one call.
 *
 * It also REGISTERS the public key as part of creation, which removes the step
 * where a merchant copies a PEM into a console and waits for it to take effect.
 *
 * Creating an account is NOT idempotent: calling it twice makes a second tenant
 * and strands the first along with everything already synced to it. The caller
 * must refuse when credentials already exist.
 */
class Provisioner
{
    private const PATH = '/api/v1/services/external/open-source';

    /**
     * Account creation does real work upstream; a shopper budget is meaningless
     * here. 60s was not enough - provisioning regularly outran it and the
     * merchant saw a transport failure for an account that was being created
     * successfully. Note this is still a synchronous request: the merchant's own
     * nginx/fastcgi_read_timeout and PHP max_execution_time can cut it shorter,
     * which is why a non-blocking Connect is the real fix.
     */
    private const TIMEOUT_SECONDS = 300;

    /**
     * @param Settings $settings
     * @param CurlFactory $curlFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Create a Quissly account for this store.
     *
     * @param string $domain Storefront host, e.g. shop.example.com
     * @param string $email Account owner; also the panel sign-in identity
     * @param string $publicKeyPem Registered as part of creation
     * @param string|null $storeName Display name in the Quissly console; null lets
     *                                Quissly name the account from the domain
     * @param int|null $websiteId
     * @param string|null $firstName Admin's first name, for the account record
     * @param string|null $lastName Admin's last name, for the account record
     * @return array Shape: {ok: bool, api_key: string, project_id: string,
     *                        store_id: string, error: string}
     */
    public function provision(
        string $domain,
        string $email,
        string $publicKeyPem,
        ?string $storeName,
        ?int $websiteId = null,
        ?string $firstName = null,
        ?string $lastName = null
    ): array {
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        $curl->addHeader('Content-Type', 'application/json');

        $payload = [
            'domain' => $domain,
            'public_key' => $publicKeyPem,
            'email' => $email,
            'platform' => 'magento',
            'service_type_slug' => 'qsearch',
            'name' => $storeName,
            'description' => 'Magento store connected via quissly/module-search.',
            'environment' => $this->settings->environment($websiteId),
            // Who connected, so the account is not anonymous in the console.
            // Distinct from 'name', which is the STORE and is what the org and
            // project are named after.
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => trim(sprintf('%s %s', (string)$firstName, (string)$lastName)) ?: null,
        ];
        // Send a name field only when we actually have one. The endpoint accepts
        // unknown keys silently, so a wrong or empty field fails quietly rather
        // than loudly - which is exactly the case for leaving it out.
        foreach (['first_name', 'last_name', 'display_name'] as $optional) {
            if (($payload[$optional] ?? null) === null || $payload[$optional] === '') {
                unset($payload[$optional]);
            }
        }
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}';

        try {
            $curl->post($this->settings->consoleUrl($websiteId) . self::PATH, $body);
        } catch (\Throwable $e) {
            $this->logger->error('[quissly] provisioning transport failure: ' . $e->getMessage());
            return $this->failure('transport_error');
        }

        $status = (int)$curl->getStatus();
        $decoded = json_decode((string)$curl->getBody(), true);

        if ($status !== 200 || !is_array($decoded)) {
            // The detail is Quissly's own wording (e.g. a domain already
            // registered); it is the only useful thing to show the merchant.
            $detail = is_array($decoded) ? (string)($decoded['detail'] ?? '') : '';
            $this->logger->info(sprintf('[quissly] provisioning http=%d %s', $status, $detail));
            return $this->failure($detail !== '' ? $detail : 'unexpected');
        }

        $apiKey = (string)($decoded['api_key'] ?? '');
        $projectId = (string)($decoded['project_id'] ?? '');
        $storeId = (string)($decoded['store_id'] ?? '');

        if ($apiKey === '' || $projectId === '') {
            $this->logger->error('[quissly] provisioning 200 without credentials');
            return $this->failure('unexpected');
        }

        // Quissly returns store_id as a copy of project_id today. Storing what
        // the response actually says, rather than assuming they stay equal, so
        // a divergence shows up here instead of as a mystery 401 later.
        if ($storeId !== '' && $storeId !== $projectId) {
            $this->logger->warning(sprintf(
                '[quissly] store_id and project_id differ (store=%s project=%s) - '
                . 'they have always been the same value; check which one the API expects',
                $storeId,
                $projectId
            ));
        }

        // The project id only - never the key, not even truncated.
        $this->logger->info(sprintf('[quissly] provisioned project=%s', $projectId));

        return [
            'ok' => true,
            'api_key' => $apiKey,
            'project_id' => $projectId,
            // Falls back to the project id: older deployments do not send it,
            // and an empty value stored would be worse than the known copy.
            'store_id' => $storeId !== '' ? $storeId : $projectId,
            'error' => '',
        ];
    }

    /**
     * A failed provisioning, shaped like a successful one.
     *
     * @param string $error
     * @return array
     */
    private function failure(string $error): array
    {
        return [
            'ok' => false,
            'api_key' => '',
            'project_id' => '',
            'store_id' => '',
            'error' => $error,
        ];
    }
}
