<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Core\Content\MultiCart;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<MultiCartEntity>
 */
final class MultiCartCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'ictech_multi_cart_collection';
    }

    protected function getExpectedClass(): string
    {
        return MultiCartEntity::class;
    }
}
