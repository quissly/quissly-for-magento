<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Crypto;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Quissly\Search\Model\Api\SignerException;
use Quissly\Search\Model\Config\Settings;

/**
 * Generates and stores the merchant's RSA-2048 keypair.
 *
 * Storage is core_config_data ONLY, private key encrypted with Magento's
 * EncryptorInterface - so bin/magento encryption:key:change re-encrypts it
 * automatically (unlike custom tables).
 * Scope follows the config-scope model: default scope (websiteId null/0) or a
 * specific website's scope override.
 */
class KeyManager
{
    private const KEY_BITS = 2048;

    /**
     * @param WriterInterface $configWriter
     * @param EncryptorInterface $encryptor
     * @param Settings $settings
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly EncryptorInterface $encryptor,
        private readonly Settings $settings,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    /**
     * Generate a fresh RSA-2048 keypair and store it at the given scope.
     * Returns the PUBLIC key PEM (for registration with Quissly). The private
     * key is encrypted and written to config; the plaintext never leaves this
     * method's scope.
     *
     * @param int|null $websiteId null = default scope
     * @return string public key PEM
     * @throws SignerException
     */
    public function generateAndStore(?int $websiteId = null): string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => self::KEY_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            throw new SignerException('Key generation failed: ' . (string)openssl_error_string());
        }
        $privatePem = '';
        if (!openssl_pkey_export($resource, $privatePem)) {
            throw new SignerException('Key export failed: ' . (string)openssl_error_string());
        }
        $details = openssl_pkey_get_details($resource);
        if ($details === false || empty($details['key'])) {
            throw new SignerException('Public key derivation failed.');
        }
        $publicPem = (string)$details['key'];

        $this->store($privatePem, $publicPem, $websiteId);

        return $publicPem;
    }

    /**
     * Generate a keypair WITHOUT writing it, so a failed caller strands nothing.
     *
     * Provisioning is the caller that matters: it registers the public key
     * remotely, and if that call fails the store must keep the credentials it
     * already had. Writing first destroyed a working tenant once - the private
     * key is overwritten in place and there is no copy to restore.
     *
     * @return array{private: string, public: string}
     * @throws SignerException
     */
    public function generate(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => self::KEY_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            throw new SignerException('Key generation failed: ' . (string)openssl_error_string());
        }
        $privatePem = '';
        if (!openssl_pkey_export($resource, $privatePem)) {
            throw new SignerException('Key export failed: ' . (string)openssl_error_string());
        }
        $details = openssl_pkey_get_details($resource);
        if ($details === false || empty($details['key'])) {
            throw new SignerException('Public key derivation failed.');
        }

        return ['private' => $privatePem, 'public' => (string)$details['key']];
    }

    /**
     * Commit a keypair produced by generate().
     *
     * @param string $privatePem
     * @param string $publicPem
     * @param int|null $websiteId
     * @return void
     */
    public function store(string $privatePem, string $publicPem, ?int $websiteId = null): void
    {
        $paths = $this->settings->credentialPaths();
        [$scope, $scopeId] = $this->scopeFor($websiteId);
        $this->configWriter->save($paths['private_key'], $this->encryptor->encrypt($privatePem), $scope, $scopeId);
        $this->configWriter->save($paths['public_key'], $publicPem, $scope, $scopeId);
        $this->cacheTypeList->cleanType('config');
    }

    /**
     * Map a website id to the config writer's (scope, scopeId) pair.
     *
     * @param int|null $websiteId
     * @return array{0: string, 1: int}
     */
    private function scopeFor(?int $websiteId): array
    {
        if ($websiteId === null || $websiteId === 0) {
            return ['default', 0];
        }
        return ['websites', $websiteId];
    }
}
