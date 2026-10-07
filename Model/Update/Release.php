<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Update;

/**
 * Reading a GitHub release and checking its package, without Magento (Updater installs it).
 *
 * The package is the zip a merchant unzips into app/code: everything under Quissly/Search/.
 */
class Release
{
    /** The module's folder inside the package (and under app/code). */
    public const MODULE_PATH = 'Quissly/Search/';

    /** Quissly's release-signing public key (Ed25519, base64): every release zip has a `.sig`. */
    public const PUBLIC_KEY = 'zAXZVzzPPk0LR8MjMrnoTZi96kcvNUvolgQ6fax9c4E=';

    /**
     * Read a GitHub "latest release" answer.
     *
     * Its version comes from a `v1.2.3` / `1.2.3` tag; the module zip and its signature
     * (`<zip>.sig`) are accepted only from $packagePrefix. Drafts, pre-releases and unsigned
     * releases are never offered.
     *
     * @param mixed $json the decoded answer
     * @param string $asset the zip's file name
     * @param string $packagePrefix what the zip's URL must start with
     * @return array|null version, package, signature
     */
    public function parse($json, string $asset, string $packagePrefix): ?array
    {
        if (!is_array($json) || !empty($json['draft']) || !empty($json['prerelease'])) {
            return null;
        }
        if (!preg_match('/^v?(\d+\.\d+\.\d+)$/', (string)($json['tag_name'] ?? ''), $m)) {
            return null;
        }
        $urls = [];
        foreach ((array)($json['assets'] ?? []) as $item) {
            $url = is_array($item) ? (string)($item['browser_download_url'] ?? '') : '';
            if (strpos($url, $packagePrefix) === 0) {
                $urls[(string)($item['name'] ?? '')] = $url;
            }
        }
        if (!isset($urls[$asset], $urls[$asset . '.sig'])) {
            return null;
        }
        return ['version' => $m[1], 'package' => $urls[$asset], 'signature' => $urls[$asset . '.sig']];
    }

    /**
     * Whether $signature (base64) is a valid Ed25519 signature of $data by $publicKey (base64).
     *
     * @param string $data the signed bytes (the zip)
     * @param string $signature the detached signature, base64
     * @param string $publicKey the public key, base64
     * @return bool
     */
    public function signatureValid(string $data, string $signature, string $publicKey): bool
    {
        try {
            $sig = sodium_base642bin(trim($signature), SODIUM_BASE64_VARIANT_ORIGINAL);
            $key = sodium_base642bin($publicKey, SODIUM_BASE64_VARIANT_ORIGINAL);
            if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return false;
            }
            return sodium_crypto_sign_verify_detached($sig, $data, $key);
        } catch (\Throwable $e) {
            return false; // not base64, or the wrong length for sodium
        }
    }

    /**
     * Why a package's file list cannot be installed, or null when it can.
     *
     * Every entry sits under Quissly/Search/, none climbs out of it, and the module's
     * registration.php and composer.json are there.
     *
     * @param string[] $names the zip's entry names
     * @return string|null
     */
    public function problem(array $names): ?string
    {
        if ($names === []) {
            return 'empty';
        }
        foreach ($names as $name) {
            if ($name === '' || $name[0] === '/' || strpos($name, '\\') !== false
                || preg_match('#(^|/)\.\.?(/|$)#', $name)
            ) {
                return 'unsafe_path';
            }
            if ($name !== 'Quissly/' && strpos($name, self::MODULE_PATH) !== 0) {
                return 'foreign_file';
            }
        }
        foreach (['registration.php', 'composer.json'] as $required) {
            if (!in_array(self::MODULE_PATH . $required, $names, true)) {
                return 'no_' . str_replace('.', '_', $required);
            }
        }
        return null;
    }

    /**
     * The version a composer.json declares.
     *
     * @param string $composerJson
     * @return string|null
     */
    public function versionIn(string $composerJson): ?string
    {
        $data = json_decode($composerJson, true);
        $version = is_array($data) ? (string)($data['version'] ?? '') : '';
        return preg_match('/^\d+\.\d+\.\d+$/', $version) ? $version : null;
    }
}
