<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Service;

use Doctrine\DBAL\Connection;
use ICTECHMultiCart\Core\Content\MultiCart\MultiCartCollection;
use ICTECHMultiCart\Core\Content\MultiCartOrder\MultiCartOrderCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

final class MultiCartService
{
    public function __construct(
        /** @var EntityRepository<MultiCartCollection> */
        private EntityRepository $multiCartRepository,
        /** @var EntityRepository<MultiCartOrderCollection> */
        private EntityRepository $multiCartOrderRepository,
        private Connection $connection
    ) {
    }

    /**
     * @return array<int, array<string, string|int|float|\DateTimeInterface|null>>
     */
    public function getActiveCarts(?string $salesChannelId, Context $context): array
    {
        $query = <<<'SQL'
SELECT
    LOWER(HEX(cart.id)) AS id,
    cart.name AS name,
    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(customer.first_name, ''), ' ', COALESCE(customer.last_name, ''))), ''), customer.email, 'Unknown') AS owner,
    customer.email AS ownerEmail,
    COALESCE(SUM(item.quantity), 0) AS itemCount,
    cart.total AS total,
    COALESCE(cart.updated_at, cart.created_at) AS lastActivity,
    cart.created_at AS createdAt
FROM ictech_multi_cart cart
LEFT JOIN customer customer ON customer.id = cart.customer_id
LEFT JOIN ictech_multi_cart_item item ON item.multi_cart_id = cart.id
LEFT JOIN ictech_multi_cart_order order_map ON order_map.multi_cart_id = cart.id
WHERE cart.status = 'active'
  AND order_map.id IS NULL
SQL;

        $params = [];

        if ($salesChannelId !== null) {
            $query .= ' AND cart.sales_channel_id = UNHEX(:salesChannelId)';
            $params['salesChannelId'] = $salesChannelId;
        }

        $query .= '
GROUP BY cart.id, cart.name, owner, ownerEmail, cart.total, lastActivity, createdAt
ORDER BY lastActivity DESC
LIMIT 100';

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($query, $params);

        return array_map(function (array $row): array {
            return [
                'id' => $this->toNullableString($row['id'] ?? null) ?? '',
                'name' => $this->toNullableString($row['name'] ?? null) ?? 'Cart',
                'owner' => $this->toNullableString($row['owner'] ?? null) ?? 'Unknown',
                'ownerEmail' => $this->toNullableString($row['ownerEmail'] ?? null) ?? '',
                'itemCount' => $this->toInt($row['itemCount'] ?? null),
                'total' => $this->toFloat($row['total'] ?? null),
                'lastActivity' => $this->toNullableString($row['lastActivity'] ?? null),
                'createdAt' => $this->toNullableString($row['createdAt'] ?? null),
            ];
        }, $rows);
    }

    /**
     * @return array<int, array<string, string|int|float|\DateTimeInterface|null>>
     */
    public function getCompletedOrders(?string $salesChannelId, Context $context): array
    {
        $query = <<<'SQL'
SELECT
    LOWER(HEX(order_map.id)) AS id,
    order_map.cart_name_snapshot AS cartName,
    LOWER(HEX(order_map.order_id)) AS orderId,
    order_map.promotion_code_snapshot AS promotionCode,
    order_map.discount_snapshot AS discount,
    order_map.ordered_at AS orderedAt,
    COALESCE(state_translation.name, state.technical_name) AS orderStatus,
    GROUP_CONCAT(
        DISTINCT CONCAT(line_item.label, ' x', line_item.quantity)
        ORDER BY line_item.label ASC
        SEPARATOR ' | '
    ) AS items
FROM ictech_multi_cart_order order_map
LEFT JOIN `order` orders ON orders.id = order_map.order_id
LEFT JOIN state_machine_state state ON state.id = orders.state_id
LEFT JOIN state_machine_state_translation state_translation
    ON state_translation.state_machine_state_id = state.id
LEFT JOIN order_line_item line_item
    ON line_item.order_id = order_map.order_id
   AND line_item.type = 'product'
WHERE 1 = 1
SQL;

        $params = [];

        if ($salesChannelId !== null) {
            $query .= ' AND orders.sales_channel_id = UNHEX(:salesChannelId)';
            $params['salesChannelId'] = $salesChannelId;
        }

        $query .= '
GROUP BY order_map.id, cartName, orderId, promotionCode, discount, orderedAt, orderStatus
ORDER BY orderedAt DESC
LIMIT 100';

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($query, $params);

        return array_map(function (array $row): array {
            return [
                'id' => $this->toNullableString($row['id'] ?? null) ?? '',
                'cartName' => $this->toNullableString($row['cartName'] ?? null) ?? 'Cart',
                'orderId' => $this->toNullableString($row['orderId'] ?? null) ?? '',
                'promotionCode' => $this->toNullableString($row['promotionCode'] ?? null),
                'discount' => $this->toFloat($row['discount'] ?? null),
                'orderedAt' => $this->toNullableString($row['orderedAt'] ?? null),
                'status' => $this->toNullableString($row['orderStatus'] ?? null) ?? 'Unknown',
                'items' => $this->toNullableString($row['items'] ?? null) ?? '',
            ];
        }, $rows);
    }

    public function createCart(string $customerId, string $salesChannelId, string $name, ?string $notes, Context $context): string
    {
        $cartId = (string)\Ramsey\Uuid\Uuid::uuid4()->getHex();

        $this->multiCartRepository->create([
            [
                'id' => $cartId,
                'customerId' => $customerId,
                'salesChannelId' => $salesChannelId,
                'name' => $name,
                'notes' => $notes,
                'status' => 'active',
                'isActive' => true,
                'subtotal' => 0,
                'total' => 0,
                'currencyIso' => 'EUR',
            ]
        ], $context);

        return $cartId;
    }

    public function deleteCart(string $cartId, Context $context): void
    {
        $this->multiCartRepository->delete([['id' => $cartId]], $context);
    }

    public function updateCartStatus(string $cartId, string $status, Context $context): void
    {
        $this->multiCartRepository->update([
            [
                'id' => $cartId,
                'status' => $status,
            ]
        ], $context);
    }

    private function toNullableString(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    private function toFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
