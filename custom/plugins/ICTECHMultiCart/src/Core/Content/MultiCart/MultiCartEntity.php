<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Core\Content\MultiCart;

use DateTimeInterface;
use ICTECHMultiCart\Core\Content\MultiCartItem\MultiCartItemCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

final class MultiCartEntity extends Entity
{
    protected string $id;
    protected string $customerId;
    protected string $salesChannelId;
    protected string $name;
    protected ?string $notes;
    protected string $status;
    protected ?string $cartToken;
    protected bool $isDefault;
    protected bool $isActive;
    protected ?string $shippingAddressId;
    protected ?string $billingAddressId;
    protected ?string $paymentMethodId;
    protected ?string $shippingMethodId;
    protected ?string $promotionCode;
    protected ?float $promotionDiscount;
    protected float $subtotal;
    protected float $total;
    protected string $currencyIso;
    /** @var DateTimeInterface|null */
    protected $createdAt;
    /** @var DateTimeInterface|null */
    protected $updatedAt;
    protected ?CustomerEntity $customer;
    protected ?SalesChannelEntity $salesChannel;
    protected ?MultiCartItemCollection $items;

    public function getId(): string
    {
        return $this->id;
    }

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCartToken(): ?string
    {
        return $this->cartToken;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getShippingAddressId(): ?string
    {
        return $this->shippingAddressId;
    }

    public function getBillingAddressId(): ?string
    {
        return $this->billingAddressId;
    }

    public function getPaymentMethodId(): ?string
    {
        return $this->paymentMethodId;
    }

    public function getShippingMethodId(): ?string
    {
        return $this->shippingMethodId;
    }

    public function getPromotionCode(): ?string
    {
        return $this->promotionCode;
    }

    public function getPromotionDiscount(): ?float
    {
        return $this->promotionDiscount;
    }

    public function getSubtotal(): float
    {
        return $this->subtotal;
    }

    public function getTotal(): float
    {
        return $this->total;
    }

    public function getCurrencyIso(): string
    {
        return $this->currencyIso;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function getSalesChannel(): ?SalesChannelEntity
    {
        return $this->salesChannel;
    }

    public function getItems(): ?MultiCartItemCollection
    {
        return $this->items;
    }
}
