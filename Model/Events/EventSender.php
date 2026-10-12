<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Events;

use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Api\HttpClient;
use Quissly\Search\Model\Config\Settings;

/**
 * Sends a website's queued events to `POST /v2beta/qsearch/events` (the `quissly_events`
 * cron, every minute), one signed request each - the endpoint takes one event per call.
 *
 * What each answer means is EventOutcome's. A website whose switch is off, or that is no
 * longer connected, has its queue emptied: switching it off means nothing more leaves the
 * store. A run sends at most BATCH events, stops after TIME_BUDGET seconds and after
 * FAILURES_PER_RUN failed sends in a row, so a burst is spread over a few minutes and a
 * Quissly outage costs one short run a minute, not 200 timeouts.
 */
class EventSender
{
    public const BATCH = 200;

    /** Events kept per website while Quissly cannot be reached; the oldest go first. */
    public const KEEP = 50000;

    private const TIME_BUDGET = 40;

    /** Failed sends in a row after which a run stops. */
    private const FAILURES_PER_RUN = 5;
    private const TIMEOUT_SECONDS = 5;
    private const LOCK_PREFIX = 'quissly_events_w';

    /**
     * @param EventQueue $queue
     * @param EventOutcome $outcome
     * @param HttpClient $http
     * @param Settings $settings
     * @param LockManagerInterface $lockManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EventQueue $queue,
        private readonly EventOutcome $outcome,
        private readonly HttpClient $http,
        private readonly Settings $settings,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Send what is due for one website.
     *
     * @param int $websiteId
     * @param int $now
     * @return array{sent: int, dropped: int, retried: int}
     */
    public function run(int $websiteId, int $now): array
    {
        $counts = ['sent' => 0, 'dropped' => 0, 'retried' => 0];
        if (!$this->settings->isEventsEnabled($websiteId) || !$this->settings->isConfigured($websiteId)) {
            $counts['dropped'] = $this->queue->clear($websiteId);
            return $counts;
        }
        $lock = self::LOCK_PREFIX . $websiteId;
        if (!$this->lockManager->lock($lock, 0)) {
            return $counts;
        }
        try {
            $counts['dropped'] += $this->queue->clear($websiteId, $now - EventOutcome::MAX_AGE_SECONDS);
            $counts['dropped'] += $this->queue->trim($websiteId, self::KEEP);
            $this->send($websiteId, $now, $counts);
        } finally {
            $this->lockManager->unlock($lock);
        }
        if ($counts !== ['sent' => 0, 'dropped' => 0, 'retried' => 0]) {
            $this->logger->info(sprintf(
                '[quissly] events website=%d sent=%d dropped=%d retried=%d',
                $websiteId,
                $counts['sent'],
                $counts['dropped'],
                $counts['retried']
            ));
        }
        return $counts;
    }

    /**
     * Send the due events, one call each.
     *
     * @param int $websiteId
     * @param int $now
     * @param array $counts updated in place
     * @return void
     */
    private function send(int $websiteId, int $now, array &$counts): void
    {
        $token = (string)$this->settings->apiToken($websiteId);
        $pem = (string)$this->settings->privateKeyPem($websiteId);
        $environment = $this->settings->environment($websiteId);
        $baseUrl = $this->settings->apiBaseUrl($websiteId);
        $started = microtime(true);
        $done = [];
        $failures = 0;
        foreach ($this->queue->due($websiteId, $now, self::BATCH) as $row) {
            if (microtime(true) - $started > self::TIME_BUDGET) {
                break;
            }
            if ($row['body'] === []) {
                $done[] = $row['queue_id'];
                $counts['dropped']++;
                continue;
            }
            try {
                $status = $this->http->postV2(
                    HttpClient::PATH_EVENTS,
                    $row['body'],
                    $token,
                    $pem,
                    $environment,
                    false,
                    self::TIMEOUT_SECONDS,
                    $baseUrl
                )['status'];
            } catch (\Throwable $e) {
                $status = 0;
            }
            $next = $this->outcome->after($status, $row['attempts']);
            if ($next['action'] === EventOutcome::RETRY) {
                $this->queue->retry($row['queue_id'], $row['attempts'] + 1, $now + $next['delay']);
                $counts['retried']++;
                // Quissly is down or refusing this store: the rest waits for the next run.
                if (++$failures >= self::FAILURES_PER_RUN) {
                    break;
                }
                continue;
            }
            $failures = 0;
            $done[] = $row['queue_id'];
            if ($next['action'] === EventOutcome::SENT) {
                $counts['sent']++;
            } else {
                $counts['dropped']++;
                $this->logger->info(sprintf(
                    '[quissly] event dropped website=%d type=%s http=%d attempts=%d',
                    $websiteId,
                    (string)($row['body']['event_type'] ?? ''),
                    $status,
                    $row['attempts'] + 1
                ));
            }
        }
        $this->queue->remove($done);
    }
}
