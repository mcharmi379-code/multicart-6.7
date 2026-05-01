<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Core\Content\MultiCartOrder;

use ICTECHMultiCart\Core\Content\MultiCart\MultiCartDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class MultiCartOrderDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'ictech_multi_cart_order';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return MultiCartOrderEntity::class;
    }

    public function getCollectionClass(): string
    {
        return MultiCartOrderCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            new IdField('id', 'id'),
            new FkField('multi_cart_id', 'multiCartId', MultiCartDefinition::class),
            new FkField('order_id', 'orderId', OrderDefinition::class),
            new StringField('cart_name_snapshot', 'cartNameSnapshot'),
            new StringField('promotion_code_snapshot', 'promotionCodeSnapshot'),
            new FloatField('discount_snapshot', 'discountSnapshot'),
            new CreatedAtField(),
            new ManyToOneAssociationField('multiCart', 'multi_cart_id', MultiCartDefinition::class, 'id', false),
            new ManyToOneAssociationField('order', 'order_id', OrderDefinition::class, 'id', false),
        ]);
    }
}
