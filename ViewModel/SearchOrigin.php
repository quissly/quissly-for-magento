<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Quissly\Search\Model\Search\SearchSignal;
use Quissly\Search\Observer\SearchDiagnosticHeader;

/**
 * Says which engine answered this search - Quissly's or Magento's.
 *
 * A fallback is invisible by design: the shopper gets ordinary Magento results
 * and the page looks perfectly healthy, which is correct behaviour and exactly
 * what makes it hard to diagnose. When quota runs out or the service is cold,
 * a merchant needs to be able to tell.
 *
 * Rendered ONLY when the URL carries ?quissly_debug=1. That is deliberate and
 * not a placeholder for a setting: shoppers must never see it. "Results by
 * Magento" advertises a failure they cannot act on, and the rule against
 * results-page UI exists for that reason. Opt-in per request means
 * nothing changes for anyone who did not ask.
 */
class SearchOrigin implements ArgumentInterface
{
    /**
     * @param SearchSignal $signal
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly SearchSignal $signal,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * Whether to render anything at all.
     *
     * @return bool
     */
    public function shouldRender(): bool
    {
        return $this->request->getParam(SearchDiagnosticHeader::DEBUG_PARAM) !== null
            && $this->signal->headerValue() !== null;
    }

    /**
     * Whether Quissly answered this search.
     *
     * @return bool
     */
    public function servedByQuissly(): bool
    {
        return str_starts_with((string)$this->signal->headerValue(), SearchSignal::HIT);
    }

    /**
     * Why the module stepped aside, when it did (e.g. "sort:price").
     *
     * @return string|null
     */
    public function skipReason(): ?string
    {
        return $this->signal->skipReason();
    }
}
