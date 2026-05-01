<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Core\Content\MultiCartConfig;

use DateTimeInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

final class MultiCartConfigEntity extends Entity
{
    protected string $id;
    protected string $salesChannelId;
    protected bool $pluginEnabled;
    protected int $maxCartsPerUser;
    protected bool $checkoutPrefsEnabled;
    protected bool $promotionsEnabled;
    protected bool $multiPaymentEnabled;
    protected string $conflictResolution;
    protected string $uiStyle;
    protected string $afterOrderAction;
    /** @var DateTimeInterface|null */
    protected $createdAt;
    /** @var DateTimeInterface|null */
    protected $updatedAt;
    protected ?SalesChannelEntity $salesChannel;

    public function getId(): string
    {
        return $this->id;
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function isPluginEnabled(): bool
    {
        return $this->pluginEnabled;
    }

    public function getMaxCartsPerUser(): int
    {
        return $this->maxCartsPerUser;
    }

    public function isCheckoutPrefsEnabled(): bool
    {
        return $this->checkoutPrefsEnabled;
    }

    public function isPromotionsEnabled(): bool
    {
        return $this->promotionsEnabled;
    }

    public function isMultiPaymentEnabled(): bool
    {
        return $this->multiPaymentEnabled;
    }

    public function getConflictResolution(): string
    {
        return $this->conflictResolution;
    }

    public function getUiStyle(): string
    {
        return $this->uiStyle;
    }

    public function getAfterOrderAction(): string
    {
        return $this->afterOrderAction;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getSalesChannel(): ?SalesChannelEntity
    {
        return $this->salesChannel;
    }
}
