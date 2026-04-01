<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Core\Content\MultiCartOrder;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<MultiCartOrderEntity>
 */
final class MultiCartOrderCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'ictech_multi_cart_order_collection';
    }

    protected function getExpectedClass(): string
    {
        return MultiCartOrderEntity::class;
    }
}
