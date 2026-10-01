<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * The variant a product page was opened for, when it was opened from a result.
 *
 * The deep link carries the selection twice, and it has to: `#93=4` is what
 * Magento's configurable.js reads, but a URL FRAGMENT IS NEVER SENT TO THE
 * SERVER. PHP sees only `aurora-tee.html`, renders the parent's default image,
 * and the correction happens after paint - the flash of the wrong colour.
 * `?quissly_variant=56` is the same fact in a form the server can act on.
 *
 * Anything arriving on a URL is a claim, not a fact: the id is accepted only
 * after the database confirms it is a child of the product being rendered.
 * Without that, ?quissly_variant= would render any product's images on any
 * product's page.
 *
 * DIRECT SQL, deliberately, and the same exception PriceIndexReadiness takes.
 * The convention is ResourceModels over the tables this module declares;
 * catalog_product_super_link is Magento's, and this is a read of one row to
 * answer one yes/no question on every product page render. Loading the parent's
 * type instance to ask the same thing would load every child of the product.
 * Documented rather than implicit, because a marketplace reviewer will ask.
 */
class VariantRequest
{
    public const PARAM = 'quissly_variant';

    /**
     * @var array<string, bool>
     */
    private array $verified = [];

    /**
     * @param RequestInterface $request
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * The requested variant id, when it really is a child of this parent.
     *
     * @param int $parentId
     * @return int|null
     */
    public function variantFor(int $parentId): ?int
    {
        $raw = $this->request->getParam(self::PARAM);
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }
        $raw = trim((string)$raw);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $variantId = (int)$raw;
        if ($variantId <= 0 || $parentId <= 0) {
            return null;
        }
        return $this->isChild($parentId, $variantId) ? $variantId : null;
    }

    /**
     * Whether the database says this really is a child of that parent.
     *
     * @param int $parentId
     * @param int $variantId
     * @return bool
     */
    private function isChild(int $parentId, int $variantId): bool
    {
        $key = $parentId . ':' . $variantId;
        if (!array_key_exists($key, $this->verified)) {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($this->resource->getTableName('catalog_product_super_link'), ['link_id'])
                ->where('parent_id = ?', $parentId)
                ->where('product_id = ?', $variantId)
                ->limit(1);
            $this->verified[$key] = (bool)$connection->fetchOne($select);
        }
        return $this->verified[$key];
    }
}
