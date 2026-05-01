<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Core\Content\MultiCartBlacklist;

use DateTimeInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

final class MultiCartBlacklistEntity extends Entity
{
    protected string $id;
    protected string $customerId;
    protected string $salesChannelId;
    protected ?string $reason;
    protected ?string $createdBy;
    /** @var DateTimeInterface|null */
    protected $createdAt;
    /** @var DateTimeInterface|null */
    protected $updatedAt;
    protected ?CustomerEntity $customer;
    protected ?SalesChannelEntity $salesChannel;

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

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
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
}
