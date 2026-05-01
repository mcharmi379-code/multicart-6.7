<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Core\Content\MultiCart;

use ICTECHMultiCart\Core\Content\MultiCartItem\MultiCartItemDefinition;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

final class MultiCartDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'ictech_multi_cart';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return MultiCartEntity::class;
    }

    public function getCollectionClass(): string
    {
        return MultiCartCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            ...$this->getIdAndForeignKeyFields(),
            ...$this->getCartFields(),
            ...$this->getAddressAndPaymentFields(),
            ...$this->getPromotionAndPricingFields(),
            ...$this->getTimestampAndAssociationFields(),
        ]);
    }

    /**
     * @return array<int, IdField|FkField>
     */
    private function getIdAndForeignKeyFields(): array
    {
        return [
            new IdField('id', 'id'),
            new FkField('customer_id', 'customerId', CustomerDefinition::class),
            new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class),
        ];
    }

    /**
     * @return array<int, StringField|BoolField>
     */
    private function getCartFields(): array
    {
        return [
            new StringField('name', 'name'),
            new StringField('notes', 'notes', 500),
            new StringField('status', 'status'),
            new StringField('cart_token', 'cartToken'),
            new BoolField('is_default', 'isDefault'),
            new BoolField('is_active', 'isActive'),
        ];
    }

    /**
     * @return array<int, StringField>
     */
    private function getAddressAndPaymentFields(): array
    {
        return [
            new StringField('shipping_address_id', 'shippingAddressId'),
            new StringField('billing_address_id', 'billingAddressId'),
            new StringField('payment_method_id', 'paymentMethodId'),
            new StringField('shipping_method_id', 'shippingMethodId'),
        ];
    }

    /**
     * @return array<int, StringField|FloatField>
     */
    private function getPromotionAndPricingFields(): array
    {
        return [
            new StringField('promotion_code', 'promotionCode'),
            new FloatField('promotion_discount', 'promotionDiscount'),
            new FloatField('subtotal', 'subtotal'),
            new FloatField('total', 'total'),
            new StringField('currency_iso', 'currencyIso'),
        ];
    }

    /**
     * @return array<int, CreatedAtField|UpdatedAtField|ManyToOneAssociationField|OneToManyAssociationField>
     */
    private function getTimestampAndAssociationFields(): array
    {
        return [
            new CreatedAtField(),
            new UpdatedAtField(),
            new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id', false),
            new ManyToOneAssociationField('salesChannel', 'sales_channel_id', SalesChannelDefinition::class, 'id', false),
            new OneToManyAssociationField('items', MultiCartItemDefinition::class, 'multi_cart_id', 'id'),
        ];
    }
}
