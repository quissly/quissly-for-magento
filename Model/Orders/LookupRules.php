<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Orders;

/**
 * The rules of an order lookup, with no Magento in them (OrderLookup applies them):
 *
 *  - verify(): only Quissly's signed request is read - the raw body signed with Quissly's order
 *    lookup key (Ed25519; the public halves are KEYS, by key id), naming a project, at most
 *    CLOCK_SKEW old, with a nonce (used once - the controller keeps it);
 *  - sameNumber() / contactMatches(): the shopper's order number and email or phone - the middleware's
 *    rules for Shopify orders, so every platform answers alike;
 *  - statuses(): a Magento order in the engine's (Shopify's) words.
 */
class LookupRules
{
    /** Quissly's order lookup public keys (Ed25519, base64), by key id. */
    public const KEYS = [
        'k1' => '4oqUKgUeo6tWq0wECjgNycyb8E9LRWPEijAkJ/xyYfU=',
    ];

    public const MAX_BODY = 2048;
    public const CLOCK_SKEW = 300;
    public const MIN_PHONE = 7;

    /**
     * The signed request, or null when it is not Quissly's or too old.
     *
     * @param string $body the raw body
     * @param string $signature X-Quissly-Signature (base64)
     * @param string $keyId X-Quissly-Key-Id
     * @param int $now unix time
     * @param array $keys public keys by id
     * @return array{project_id: string, order_number: string, contact: string, nonce: string}|null
     */
    public static function verify(
        string $body,
        string $signature,
        string $keyId,
        int $now,
        array $keys = self::KEYS
    ): ?array {
        if ($body === '' || strlen($body) > self::MAX_BODY || !isset($keys[$keyId])
            || !function_exists('sodium_crypto_sign_verify_detached')
        ) {
            return null;
        }
        $sig = base64_decode($signature, true);
        $key = base64_decode($keys[$keyId], true);
        if ($sig === false || $key === false || strlen($sig) !== 64 || strlen($key) !== 32) {
            return null;
        }
        try {
            if (!sodium_crypto_sign_verify_detached($sig, $body, $key)) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        $ask = json_decode($body, true);
        if (!is_array($ask)) {
            return null;
        }
        $project = is_string($ask['project_id'] ?? null) ? strtolower(trim($ask['project_id'])) : '';
        $number = is_string($ask['order_number'] ?? null) ? trim($ask['order_number']) : '';
        $contact = is_string($ask['contact'] ?? null) ? trim($ask['contact']) : '';
        $nonce = is_string($ask['nonce'] ?? null) ? $ask['nonce'] : '';
        $at = is_int($ask['timestamp'] ?? null) ? $ask['timestamp'] : 0;
        if ($project === '' || abs($now - $at) > self::CLOCK_SKEW || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $nonce)
            || $number === '' || strlen($number) > 64 || $contact === '' || strlen($contact) > 254
        ) {
            return null;
        }

        return ['project_id' => $project, 'order_number' => $number, 'contact' => $contact, 'nonce' => $nonce];
    }

    /**
     * Whether two order numbers are the same.
     *
     * Letters and digits only, any case ("#000000123" = "000000123"), and an all-digit number without its
     * leading zeros ("123" = "000000123": the zeros are Magento's padding, and a shopper rarely types them).
     *
     * @param string $given what the shopper typed
     * @param string $number the order's increment id
     * @return bool
     */
    public static function sameNumber(string $given, string $number): bool
    {
        $a = strtolower((string)preg_replace('/[^0-9A-Za-z]/', '', $given));
        $b = strtolower((string)preg_replace('/[^0-9A-Za-z]/', '', $number));
        if ($a === '' || $b === '') {
            return false;
        }
        if (ctype_digit($a) && ctype_digit($b)) {
            return ltrim($a, '0') === ltrim($b, '0') && ltrim($a, '0') !== '';
        }

        return $a === $b;
    }

    /**
     * The increment ids an order number may be stored as.
     *
     * As typed (letters, digits and dashes), and an all-digit one also padded to Magento's nine digits.
     *
     * @param string $given
     * @return string[]
     */
    public static function candidates(string $given): array
    {
        $clean = (string)preg_replace('/[^0-9A-Za-z-]/', '', $given);
        if ($clean === '') {
            return [];
        }
        $out = [$clean];
        if (ctype_digit($clean) && strlen($clean) < 9) {
            $out[] = str_pad(ltrim($clean, '0'), 9, '0', STR_PAD_LEFT);
        }

        return array_values(array_unique($out));
    }

    /**
     * Whether the shopper's email or phone is the order's own.
     *
     * An email equal in any case; a phone whose digits (at least MIN_PHONE) are the end of the other's
     * ("+995 555 12 34 56" = "555123456").
     *
     * @param string $contact
     * @param string[] $emails
     * @param string[] $phones
     * @return bool
     */
    public static function contactMatches(string $contact, array $emails, array $phones): bool
    {
        $contact = strtolower(trim($contact));
        if (str_contains($contact, '@')) {
            foreach ($emails as $email) {
                $email = strtolower(trim((string)$email));
                if ($email !== '' && $email === $contact) {
                    return true;
                }
            }

            return false;
        }
        $given = (string)preg_replace('/\D/', '', $contact);
        if (strlen($given) < self::MIN_PHONE) {
            return false;
        }
        foreach ($phones as $phone) {
            $known = (string)preg_replace('/\D/', '', (string)$phone);
            if (strlen($known) >= self::MIN_PHONE && (str_ends_with($known, $given) || str_ends_with($given, $known))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A Magento order in the engine's words.
     *
     * @param string $state Order::getState()
     * @param float $grandTotal
     * @param float $paid total paid
     * @param float $refunded total refunded
     * @param float $ordered quantity ordered (visible items)
     * @param float $shipped quantity shipped
     * @return array{fulfillment_status: string, financial_status: string, cancelled: bool}
     */
    public static function statuses(
        string $state,
        float $grandTotal,
        float $paid,
        float $refunded,
        float $ordered,
        float $shipped
    ): array {
        $fulfillment = match (true) {
            in_array($state, ['complete', 'closed'], true) || ($ordered > 0 && $shipped >= $ordered) => 'FULFILLED',
            $shipped > 0 => 'PARTIALLY_FULFILLED',
            in_array($state, ['holded', 'payment_review'], true) => 'ON_HOLD',
            $state === 'processing' => 'IN_PROGRESS',
            default => 'UNFULFILLED',
        };
        $financial = match (true) {
            $refunded > 0 && $refunded >= $grandTotal => 'REFUNDED',
            $refunded > 0 => 'PARTIALLY_REFUNDED',
            $grandTotal > 0 && $paid >= $grandTotal => 'PAID',
            $paid > 0 => 'PARTIALLY_PAID',
            $grandTotal <= 0 && in_array($state, ['processing', 'complete'], true) => 'PAID',
            default => 'PENDING',
        };

        return [
            'fulfillment_status' => $fulfillment,
            'financial_status' => $financial,
            'cancelled' => $state === 'canceled',
        ];
    }
}
