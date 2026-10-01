<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Plugin\Frontend;

use Magento\CatalogSearch\Block\Result;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Phrase;

/**
 * Retitles the results page for a voice or image hand-off.
 *
 * Magento renders "Search results for: 'image search'" from the q parameter,
 * and on a hand-off that parameter is a PLACEHOLDER: a photo carries no words,
 * but Magento will not route a search without a term and the interception guard
 * rejects anything under two characters, so the URL has to carry one. Printing
 * it back tells the shopper we searched for the phrase "image search", which we
 * did not - the placeholder is routing machinery, not their query.
 *
 * The search box is already blanked on arrival (quissly-image.js); this is the
 * other half, and it also fixes the browser tab, since Block\Result feeds this
 * same string to the page title.
 *
 * Frontend-area DI only, and only when the URL carries our token: an ordinary
 * typed search is never touched.
 */
class SearchHeading
{
    /**
     * @param RequestInterface $request
     */
    public function __construct(private readonly RequestInterface $request)
    {
    }

    /**
     * Replace the heading when this page is a hand-off, otherwise leave it alone.
     *
     * @param Result $subject
     * @param Phrase|string $result
     * @return Phrase|string
     */
    public function afterGetSearchQueryText(Result $subject, $result)
    {
        if ($this->isNonEmptyParam('quissly_img')) {
            return __('Results for your photo');
        }
        if ($this->isNonEmptyParam('quissly_voice')) {
            return __('Results for your recording');
        }
        return $result;
    }

    /**
     * Whether the request carries a non-empty value for this parameter.
     *
     * @param string $param
     * @return bool
     */
    private function isNonEmptyParam(string $param): bool
    {
        $value = $this->request->getParam($param);
        return is_string($value) && $value !== '';
    }
}
