<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Core\Content\MultiCartConfig;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<MultiCartConfigEntity>
 */
final class MultiCartConfigCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'ictech_multi_cart_config_collection';
    }

    protected function getExpectedClass(): string
    {
        return MultiCartConfigEntity::class;
    }
}
