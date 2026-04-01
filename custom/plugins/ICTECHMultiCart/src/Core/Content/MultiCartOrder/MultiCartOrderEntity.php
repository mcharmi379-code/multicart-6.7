<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Core\Content\MultiCartOrder;

use DateTimeInterface;
use ICTECHMultiCart\Core\Content\MultiCart\MultiCartEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

final class MultiCartOrderEntity extends Entity
{
    protected string $id;
    protected ?string $multiCartId;
    protected string $orderId;
    protected string $cartNameSnapshot;
    protected ?string $promotionCodeSnapshot;
    protected ?float $discountSnapshot;
    /** @var DateTimeInterface|null */
    protected $createdAt;
    protected ?MultiCartEntity $multiCart;
    protected ?OrderEntity $order;

    public function getId(): string
    {
        return $this->id;
    }

    public function getMultiCartId(): ?string
    {
        return $this->multiCartId;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getCartNameSnapshot(): string
    {
        return $this->cartNameSnapshot;
    }

    public function getPromotionCodeSnapshot(): ?string
    {
        return $this->promotionCodeSnapshot;
    }

    public function getDiscountSnapshot(): ?float
    {
        return $this->discountSnapshot;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getMultiCart(): ?MultiCartEntity
    {
        return $this->multiCart;
    }

    public function getOrder(): ?OrderEntity
    {
        return $this->order;
    }
}
