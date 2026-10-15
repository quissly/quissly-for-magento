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

    /** "Never": no checks and no automatic install (`bin/magento quissly:update` by hand). */
    public const NEVER = 0;

    /** How often a store checks, unless the merchant chose otherwise: every 30 minutes. */
    public const DEFAULT_EVERY = 1800;

    /** The same update problem goes to the log again after this long. */
    public const PROBLEM_EVERY = 86400;

    /** The frequencies offered: 5 minutes (for testing), 30 minutes (the default) to a day, never. */
    public const FREQUENCIES = [300, 1800, 3600, 21600, 43200, 86400, self::NEVER];

    /**
     * A frequency as one of FREQUENCIES; anything else (nothing chosen included) is the default.
     *
     * @param mixed $value seconds
     * @return int
     */
    public function interval($value): int
    {
        if (!is_numeric($value)) {
            return self::DEFAULT_EVERY;
        }
        return in_array((int)$value, self::FREQUENCIES, true) ? (int)$value : self::DEFAULT_EVERY;
    }

    /**
     * Whether a version the release repository's composer.json names is worth asking GitHub about.
     *
     * It is when it is newer than the installed one and than the release already known.
     *
     * @param string $hinted
     * @param string $installed
     * @param string $known '' = none known yet
     * @return bool
     */
    public function isNews(string $hinted, string $installed, string $known): bool
    {
        return version_compare($hinted, $installed, '>')
            && ($known === '' || version_compare($hinted, $known, '>'));
    }

    /**
     * A release named by its version alone, at the addresses the release workflow publishes it to.
     *
     * For when GitHub's API refuses the server: `v<version>/` + the zip and its `.sig`.
     *
     * @param string $version
     * @param string $asset the zip's file name
     * @param string $packagePrefix the releases' download address
     * @return array version, package, signature
     */
    public function forVersion(string $version, string $asset, string $packagePrefix): array
    {
        $package = $packagePrefix . 'v' . $version . '/' . $asset;
        return ['version' => $version, 'package' => $package, 'signature' => $package . '.sig'];
    }

    /**
     * Why a request to GitHub failed, '' when it did not.
     *
     * No answer at all, or a status other than 200 - GitHub answers 403/429 to an IP address
     * past its limit (60 API requests an hour, shared by every site on a shared host).
     *
     * @param bool $answered
     * @param int $status
     * @param string $error the transport's message, when there was no answer
     * @return string
     */
    public function requestError(bool $answered, int $status, string $error): string
    {
        if (!$answered) {
            return 'no answer' . ($error !== '' ? ': ' . $error : '');
        }
        if ($status === 200) {
            return '';
        }
        return in_array($status, [403, 429], true)
            ? 'HTTP ' . $status . ", GitHub's limit for this server's IP address"
            : 'HTTP ' . $status;
    }

    /**
     * Whether an update problem goes to the log: a new one at once, the same one again after a day.
     *
     * @param array $last message, at - of the last one logged ([] = none)
     * @param string $message
     * @param int $now
     * @return bool
     */
    public function problemDue(array $last, string $message, int $now): bool
    {
        return ($last['message'] ?? '') !== $message || $now - (int)($last['at'] ?? 0) >= self::PROBLEM_EVERY;
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
