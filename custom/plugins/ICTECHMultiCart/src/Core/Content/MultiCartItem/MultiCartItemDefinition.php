<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Core\Content\MultiCartItem;

use ICTECHMultiCart\Core\Content\MultiCart\MultiCartDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class MultiCartItemDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'ictech_multi_cart_item';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return MultiCartItemEntity::class;
    }

    public function getCollectionClass(): string
    {
        return MultiCartItemCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey()),
            new FkField('multi_cart_id', 'multiCartId', MultiCartDefinition::class),
            new FkField('product_id', 'productId', ProductDefinition::class),
            new StringField('product_number', 'productNumber'),
            new StringField('product_name', 'productName'),
            new IntField('quantity', 'quantity'),
            new FloatField('unit_price', 'unitPrice'),
            new FloatField('total_price', 'totalPrice'),
            new JsonField('payload', 'payload'),
            new CreatedAtField(),
            new UpdatedAtField(),
            new ManyToOneAssociationField('multiCart', 'multi_cart_id', MultiCartDefinition::class, 'id', false),
            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class, 'id', false),
        ]);
    }
}
