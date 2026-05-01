<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Core\Content\MultiCartConfig;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

final class MultiCartConfigDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'ictech_multi_cart_config';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return MultiCartConfigEntity::class;
    }

    public function getCollectionClass(): string
    {
        return MultiCartConfigCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            new IdField('id', 'id'),
            new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class),
            new BoolField('plugin_enabled', 'pluginEnabled'),
            new IntField('max_carts_per_user', 'maxCartsPerUser'),
            new BoolField('checkout_prefs_enabled', 'checkoutPrefsEnabled'),
            new BoolField('promotions_enabled', 'promotionsEnabled'),
            new BoolField('multi_payment_enabled', 'multiPaymentEnabled'),
            new StringField('conflict_resolution', 'conflictResolution'),
            new StringField('ui_style', 'uiStyle'),
            new StringField('after_order_action', 'afterOrderAction'),
            new CreatedAtField(),
            new UpdatedAtField(),
            new ManyToOneAssociationField('salesChannel', 'sales_channel_id', SalesChannelDefinition::class, 'id', false),
        ]);
    }
}
