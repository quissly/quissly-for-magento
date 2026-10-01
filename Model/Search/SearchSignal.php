<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

/**
 * Records what happened to the current request's product search.
 *
 * A shared (singleton) instance: the interception plugin writes the outcome,
 * and the response observer reads it to stamp a diagnostic header. It exists
 * so "was this page Quissly or native?" is answerable from a browser's network
 * tab, instead of only from var/log on a machine the merchant may not have.
 *
 * Carries no shopper data - an outcome word and two counts, nothing else.
 */
class SearchSignal
{
    public const HIT = 'hit';
    public const FALLBACK = 'fallback';
    public const SKIPPED = 'skipped';

    /** @var string|null */
    private ?string $outcome = null;

    /** @var int */
    private int $ids = 0;

    /** @var int */
    private int $total = 0;

    /** @var string */
    private string $code = '';

    /** @var string */
    private string $reason = '';

    /**
     * Quissly answered and its results are being rendered.
     *
     * @param int $ids
     * @param int $total
     * @return void
     */
    public function recordHit(int $ids, int $total): void
    {
        $this->outcome = self::HIT;
        $this->ids = $ids;
        $this->total = $total;
    }

    /**
     * Quissly did not answer; native search rendered this page.
     *
     * @param string $code ResponseClassifier constant
     * @return void
     */
    public function recordFallback(string $code): void
    {
        $this->outcome = self::FALLBACK;
        $this->code = $code;
    }

    /**
     * The module stepped aside and native search answered - not a failure, a
     * guard that did not pass. The reason (CriteriaInspector::rejectionReason,
     * or which setting) is what makes the silent path diagnosable; a hit or a
     * fallback recorded for this request always wins over it.
     *
     * @param string $reason field names and fixed words only, never query text
     * @return void
     */
    public function recordSkip(string $reason): void
    {
        if ($this->outcome !== null) {
            return;
        }
        $this->outcome = self::SKIPPED;
        // Field names come from other extensions' code: one clean header token.
        $this->reason = (string)preg_replace('/[^A-Za-z0-9_.:-]/', '', $reason);
    }

    /**
     * Why the module stepped aside on this request, or null when it did not.
     *
     * @return string|null
     */
    public function skipReason(): ?string
    {
        return $this->outcome === self::SKIPPED ? $this->reason : null;
    }

    /**
     * Header value, or null when no interceptable search ran on this request.
     *
     * @return string|null
     */
    public function headerValue(): ?string
    {
        if ($this->outcome === self::HIT) {
            return sprintf('hit; ids=%d; total=%d', $this->ids, $this->total);
        }
        if ($this->outcome === self::FALLBACK) {
            return sprintf('fallback; code=%s', $this->code);
        }
        if ($this->outcome === self::SKIPPED) {
            return sprintf('skipped; reason=%s', $this->reason);
        }
        return null;
    }
}
