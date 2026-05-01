<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Core\Content\MultiCartItem;

use DateTimeInterface;
use ICTECHMultiCart\Core\Content\MultiCart\MultiCartEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

final class MultiCartItemEntity extends Entity
{
    protected string $id;
    protected string $multiCartId;
    protected ?string $productId;
    protected string $productNumber;
    protected string $productName;
    protected int $quantity;
    protected float $unitPrice;
    protected float $totalPrice;
    /** @var array<string, string|int|float|bool|null>|null */
    protected ?array $payload = null;
    /** @var DateTimeInterface|null */
    protected $createdAt;
    /** @var DateTimeInterface|null */
    protected $updatedAt;
    protected ?MultiCartEntity $multiCart;
    protected ?ProductEntity $product;

    public function getId(): string
    {
        return $this->id;
    }

    public function getMultiCartId(): string
    {
        return $this->multiCartId;
    }

    public function getProductId(): ?string
    {
        return $this->productId;
    }

    public function getProductNumber(): string
    {
        return $this->productNumber;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnitPrice(): float
    {
        return $this->unitPrice;
    }

    public function getTotalPrice(): float
    {
        return $this->totalPrice;
    }

    /**
     * @return array<string, string|int|float|bool|null>|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getMultiCart(): ?MultiCartEntity
    {
        return $this->multiCart;
    }

    public function getProduct(): ?ProductEntity
    {
        return $this->product;
    }
}
