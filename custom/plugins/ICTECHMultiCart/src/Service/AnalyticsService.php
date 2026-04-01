<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;

final class AnalyticsService
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return array<string, float|int|array<int, array<string, int|string|float>>>
     */
    public function getAnalytics(?string $salesChannelId, Context $context): array
    {
        unset($context);

        $cartStats = $this->getCartStats($salesChannelId);
        $totalCarts = $cartStats['totalCarts'];
        $totalOrders = $this->getTotalOrders($salesChannelId);
        $conversionRate = $totalCarts > 0 ? ($totalOrders / $totalCarts) * 100 : 0.0;

        return [
            'totalCartsCreated' => $totalCarts,
            'cartsConvertedToOrders' => $totalOrders,
            'conversionRate' => round($conversionRate, 2),
            'averageItemsPerCart' => round($cartStats['averageItemsPerCart'], 2),
            'averageCartValue' => round($cartStats['averageCartValue'], 2),
            'totalCartValue' => round($cartStats['totalCartValue'], 2),
            'usageDistribution' => $this->getUsageDistribution($salesChannelId),
        ];
    }

    /**
     * @return array{
     *     totalCarts: int,
     *     averageItemsPerCart: float,
     *     averageCartValue: float,
     *     totalCartValue: float
     * }
     */
    private function getCartStats(?string $salesChannelId): array
    {
        $query = <<<'SQL'
SELECT
    COUNT(cart.id) AS totalCarts,
    COALESCE(SUM(cart.total), 0) AS totalCartValue,
    COALESCE(SUM(item_summary.totalQuantity), 0) AS totalItems
FROM ictech_multi_cart cart
LEFT JOIN (
    SELECT multi_cart_id, SUM(quantity) AS totalQuantity
    FROM ictech_multi_cart_item
    GROUP BY multi_cart_id
) item_summary ON item_summary.multi_cart_id = cart.id
WHERE 1 = 1
SQL;
        $params = [];

        if ($salesChannelId !== null) {
            $query .= ' AND cart.sales_channel_id = UNHEX(:salesChannelId)';
            $params['salesChannelId'] = $salesChannelId;
        }

        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative($query, $params);

        if ($row === false) {
            return [
                'totalCarts' => 0,
                'averageItemsPerCart' => 0.0,
                'averageCartValue' => 0.0,
                'totalCartValue' => 0.0,
            ];
        }

        $totalCarts = $this->toInt($row['totalCarts'] ?? null);
        $totalCartValue = $this->toFloat($row['totalCartValue'] ?? null);
        $totalItems = $this->toFloat($row['totalItems'] ?? null);

        return [
            'totalCarts' => $totalCarts,
            'averageItemsPerCart' => $totalCarts > 0 ? $totalItems / $totalCarts : 0.0,
            'averageCartValue' => $totalCarts > 0 ? $totalCartValue / $totalCarts : 0.0,
            'totalCartValue' => $totalCartValue,
        ];
    }

    private function getTotalOrders(?string $salesChannelId): int
    {
        $query = <<<'SQL'
SELECT COUNT(order_map.id) AS totalOrders
FROM ictech_multi_cart_order order_map
INNER JOIN `order` orders ON orders.id = order_map.order_id
WHERE 1 = 1
SQL;
        $params = [];

        if ($salesChannelId !== null) {
            $query .= ' AND orders.sales_channel_id = UNHEX(:salesChannelId)';
            $params['salesChannelId'] = $salesChannelId;
        }

        /** @var mixed $value */
        $value = $this->connection->fetchOne($query, $params);

        return $this->toInt($value);
    }

    /**
     * @return list<array<string, int|string|float>>
     */
    private function getUsageDistribution(?string $salesChannelId): array
    {
        $query = <<<'SQL'
SELECT
    LOWER(HEX(customer.id)) AS customerId,
    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(customer.first_name, ''), ' ', COALESCE(customer.last_name, ''))), ''), customer.email, 'Unknown') AS customerName,
    customer.email AS customerEmail,
    COUNT(cart.id) AS cartCount,
    COALESCE(SUM(cart.total), 0) AS totalValue
FROM ictech_multi_cart cart
LEFT JOIN customer customer ON customer.id = cart.customer_id
WHERE 1 = 1
SQL;
        $params = [];

        if ($salesChannelId !== null) {
            $query .= ' AND cart.sales_channel_id = UNHEX(:salesChannelId)';
            $params['salesChannelId'] = $salesChannelId;
        }

        $query .= '
GROUP BY customer.id, customerName, customerEmail
ORDER BY cartCount DESC, totalValue DESC
LIMIT 10';

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($query, $params);

        return array_map(function (array $row): array {
            return [
                'customerId' => $this->toString($row['customerId'] ?? null),
                'customerName' => $this->toString($row['customerName'] ?? null, 'Unknown'),
                'customerEmail' => $this->toString($row['customerEmail'] ?? null),
                'cartCount' => $this->toInt($row['cartCount'] ?? null),
                'totalValue' => round($this->toFloat($row['totalValue'] ?? null), 2),
            ];
        }, $rows);
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

    private function toString(mixed $value, string $default = ''): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return $default;
    }
}
