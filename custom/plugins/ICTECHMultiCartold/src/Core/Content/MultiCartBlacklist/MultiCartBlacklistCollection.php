<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Core\Content\MultiCartBlacklist;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<MultiCartBlacklistEntity>
 */
final class MultiCartBlacklistCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'ictech_multi_cart_blacklist_collection';
    }

    protected function getExpectedClass(): string
    {
        return MultiCartBlacklistEntity::class;
    }
}
