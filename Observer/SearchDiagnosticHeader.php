<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Observer;

use Magento\Framework\App\Response\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Quissly\Search\Model\Search\SearchSignal;

/**
 * Stamps X-Quissly-Search on responses where a product search ran.
 *
 * Lets anyone answer "did Quissly serve this page, or did it fall back?" from
 * a browser's network tab. Absent header = no interceptable search happened on
 * that request.
 *
 * DEVELOPER MODE ONLY. The outcome includes auth/billing failure codes, and
 * those belong to the merchant's admin, never to a shopper - a public
 * header would tell any visitor that a store's
 * credentials are broken or its bill unpaid. It also advertises the vendor,
 * which is the merchant's decision to publish, not ours.
 *
 * Under full-page cache the header is stored with the page, so on a cache HIT
 * it reports what happened when the page was BUILT - which is still the truth
 * about the bytes being served.
 */
class SearchDiagnosticHeader implements ObserverInterface
{
    /** Append ?quissly_debug=1 to any search URL to see which engine answered. */
    public const DEBUG_PARAM = 'quissly_debug';

    /**
     * @param SearchSignal $signal
     * @param State $appState
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly SearchSignal $signal,
        private readonly State $appState,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * Add the diagnostic header when the request ran a Quissly-guarded search.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->isDeveloperMode() && !$this->wasAskedFor()) {
            return;
        }
        $value = $this->signal->headerValue();
        if ($value === null) {
            return;
        }
        $response = $observer->getEvent()->getData('response');
        if ($response instanceof Http) {
            $response->setHeader('X-Quissly-Search', $value, true);
        }
    }

    /**
     * Whether this request explicitly asked to see the diagnostic.
     *
     * A production store still needs SOMEONE able to tell Quissly's results
     * from Magento's - when quota runs out, the storefront looks perfectly
     * healthy because falling back is the correct behaviour, and that is
     * exactly when a merchant needs to know which engine answered.
     *
     * Opt-in per request rather than a setting: nothing changes for shoppers,
     * nothing is cached differently, and a merchant can check any page at any
     * time by adding ?quissly_debug=1 to the URL.
     *
     * @return bool
     */
    private function wasAskedFor(): bool
    {
        return $this->request->getParam(self::DEBUG_PARAM) !== null;
    }

    /**
     * Whether the store runs in developer mode.
     *
     * A store whose mode cannot be resolved is treated as production: the
     * safe direction is to stay silent.
     *
     * @return bool
     */
    private function isDeveloperMode(): bool
    {
        try {
            return $this->appState->getMode() === State::MODE_DEVELOPER;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
