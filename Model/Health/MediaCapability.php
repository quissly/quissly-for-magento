<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Health;

use Magento\Framework\FlagManager;

/**
 * Remembers whether Quissly has actually enabled voice/image for a website.
 *
 * Both features carry TWO switches: the merchant's admin toggle and Quissly's
 * per-tenant backend enablement (voice = transcription_prompt, image =
 * qimage_config + image vectors). A toggle that is ON while the backend gate is
 * shut renders a control that fails every time a shopper presses it, so the
 * storefront consults this record before drawing a button.
 *
 * This is a memory of the last observed gate, never a source of truth: records
 * expire, so a feature reappears on its own once Quissly enables it.
 */
class MediaCapability
{
    public const STATUS_OK = 'ok';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_UNKNOWN = 'unknown';

    private const FLAG_PREFIX = 'quissly_media_gate_';

    /** Re-probe window: a gate observed longer ago than this is forgotten. */
    private const TTL_SECONDS = 21600;

    /**
     * Consecutive soft failures before a control is treated as unusable.
     *
     * A documented gate (voice 403, image 503) is proof on the first response.
     * An ambiguous 5xx is not - it could be one bad minute - so we only stop
     * drawing the control once the failure has clearly repeated.
     */
    private const SOFT_FAILURE_LIMIT = 3;

    /**
     * @param FlagManager $flagManager
     */
    public function __construct(private readonly FlagManager $flagManager)
    {
    }

    /**
     * Record that the backend refused this media kind for the website.
     *
     * @param int|null $websiteId
     * @param string $kind MediaSearchClient::KIND_*
     * @param string $code ResponseClassifier constant
     * @return void
     */
    public function markUnavailable(?int $websiteId, string $kind, string $code): void
    {
        $this->flagManager->saveFlag(
            $this->flag($websiteId, $kind),
            ['code' => $code, 'at' => time(), 'fails' => self::SOFT_FAILURE_LIMIT]
        );
    }

    /**
     * Record an ambiguous failure (5xx, transport) for this media kind.
     *
     * Hides the control only once the failure repeats, so a single bad response
     * never removes a feature that works.
     *
     * @param int|null $websiteId
     * @param string $kind
     * @param string $code
     * @return void
     */
    public function markFailure(?int $websiteId, string $kind, string $code): void
    {
        $stored = $this->flagManager->getFlagData($this->flag($websiteId, $kind));
        // A previous success starts the count over rather than carrying its
        // shape forward: the feature demonstrably worked since then.
        $fails = is_array($stored) && empty($stored['ok']) ? (int)($stored['fails'] ?? 0) : 0;
        $this->flagManager->saveFlag(
            $this->flag($websiteId, $kind),
            ['code' => $code, 'at' => time(), 'fails' => $fails + 1]
        );
    }

    /**
     * Record a verified success.
     *
     * Deliberately WRITES a record rather than deleting one. Deleting made
     * "we checked and it works" indistinguishable from "we have never
     * checked" - both produced a null gate - which let the admin render a
     * confident "Yes" for a feature nobody had ever exercised. A panel that
     * exists to prevent false confidence must not manufacture it.
     *
     * @param int|null $websiteId
     * @param string $kind
     * @return void
     */
    public function markAvailable(?int $websiteId, string $kind): void
    {
        $this->flagManager->saveFlag(
            $this->flag($websiteId, $kind),
            ['ok' => true, 'at' => time()]
        );
    }

    /**
     * Discard any observation, returning this website/kind to "not checked".
     *
     * Only tests call this - uninstall removes the flags by prefix in SQL, and
     * the storefront has no reason to forget an observation.
     *
     * @param int|null $websiteId
     * @param string $kind
     * @return void
     */
    public function forget(?int $websiteId, string $kind): void
    {
        $this->flagManager->deleteFlag($this->flag($websiteId, $kind));
    }

    /**
     * What was last OBSERVED for this website/kind, for admin display.
     *
     * Three answers, not two:
     *   self::STATUS_OK - a call succeeded, recently
     *   self::STATUS_BLOCKED - a call was refused, recently
     *   self::STATUS_UNKNOWN - nothing recent to go on
     *
     * UNKNOWN is not a failure state and must never be shown as one; it is
     * the honest answer when a feature has never been exercised, when the last
     * observation has aged out of the re-probe window, or when it has failed
     * too few times to call it blocked.
     *
     * Those last two are NOT the same thing to a merchant, so `at` is null
     * only for never-checked. A caller that ignores `at` and describes every
     * UNKNOWN as "nobody has tried this" will tell someone whose feature just
     * started failing that nobody tried it.
     *
     * @param int|null $websiteId
     * @param string $kind
     * @return array Shape: {status: string, code: string, at: int|null}
     */
    public function status(?int $websiteId, string $kind): array
    {
        $stored = $this->flagManager->getFlagData($this->flag($websiteId, $kind));
        if (!is_array($stored) || !isset($stored['at'])
            || time() - (int)$stored['at'] > self::TTL_SECONDS
        ) {
            return ['status' => self::STATUS_UNKNOWN, 'code' => '', 'at' => null];
        }
        if (!empty($stored['ok'])) {
            return ['status' => self::STATUS_OK, 'code' => '', 'at' => (int)$stored['at']];
        }
        if ((int)($stored['fails'] ?? 0) < self::SOFT_FAILURE_LIMIT) {
            // Failing, but not yet often enough to call it blocked. The code
            // and timestamp travel with it: the caller must be able to tell
            // this apart from never-having-been-checked, which reads the same
            // in `status` alone and means the opposite to a merchant.
            return [
                'status' => self::STATUS_UNKNOWN,
                'code' => (string)($stored['code'] ?? ''),
                'at' => (int)$stored['at'],
            ];
        }
        return [
            'status' => self::STATUS_BLOCKED,
            'code' => (string)($stored['code'] ?? ''),
            'at' => (int)$stored['at'],
        ];
    }

    /**
     * Whether the storefront may render a control for this media kind.
     *
     * Optimistic by design: unknown means usable. Hiding a working feature is
     * worse than one failed attempt that then hides the control.
     *
     * @param int|null $websiteId
     * @param string $kind
     * @return bool
     */
    public function isUsable(?int $websiteId, string $kind): bool
    {
        return $this->gate($websiteId, $kind) === null;
    }

    /**
     * The live gate record, or null when none applies.
     *
     * Also drives the admin feature-status display.
     *
     * @param int|null $websiteId
     * @param string $kind
     * @return array{code: string, at: int}|null
     */
    public function gate(?int $websiteId, string $kind): ?array
    {
        $stored = $this->flagManager->getFlagData($this->flag($websiteId, $kind));
        if (!is_array($stored) || !isset($stored['at'])) {
            return null;
        }
        if (time() - (int)$stored['at'] > self::TTL_SECONDS) {
            return null;
        }
        if (!empty($stored['ok'])) {
            return null;
        }
        if ((int)($stored['fails'] ?? 0) < self::SOFT_FAILURE_LIMIT) {
            return null;
        }
        return ['code' => (string)($stored['code'] ?? ''), 'at' => (int)$stored['at']];
    }

    /**
     * Flag name for one website/kind pair.
     *
     * @param int|null $websiteId
     * @param string $kind
     * @return string
     */
    private function flag(?int $websiteId, string $kind): string
    {
        return self::FLAG_PREFIX . $kind . '_w' . (int)$websiteId;
    }
}
