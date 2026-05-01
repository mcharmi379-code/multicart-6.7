<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Core\Content\MultiCartItem;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<MultiCartItemEntity>
 */
final class MultiCartItemCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'ictech_multi_cart_item_collection';
    }

    protected function getExpectedClass(): string
    {
        return MultiCartItemEntity::class;
    }
}
