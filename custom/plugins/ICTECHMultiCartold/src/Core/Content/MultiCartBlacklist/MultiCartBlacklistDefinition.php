<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Core\Content\MultiCartBlacklist;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

final class MultiCartBlacklistDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'ictech_multi_cart_blacklist';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return MultiCartBlacklistEntity::class;
    }

    public function getCollectionClass(): string
    {
        return MultiCartBlacklistCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            new IdField('id', 'id'),
            new FkField('customer_id', 'customerId', CustomerDefinition::class),
            new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class),
            new StringField('reason', 'reason', 500),
            new StringField('created_by', 'createdBy'),
            new CreatedAtField(),
            new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id', false),
            new ManyToOneAssociationField('salesChannel', 'sales_channel_id', SalesChannelDefinition::class, 'id', false),
        ]);
    }
}
