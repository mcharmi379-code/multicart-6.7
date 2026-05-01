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
     * @return array{
     *     totalCartsCreated: int,
     *     cartsConvertedToOrders: int,
     *     conversionRate: float,
     *     averageItemsPerCart: float,
     *     averageCartValue: float,
     *     totalCartValue: float,
     *     usageDistribution: array{
     *         data: list<array<string, int|string|float>>,
     *         total: int,
     *         page: int,
     *         limit: int
     *     }
     * }
     */
    public function getAnalytics(?string $salesChannelId, Context $context, int $usagePage = 1, int $usageLimit = 10): array
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
            'usageDistribution' => $this->getUsageDistribution($salesChannelId, $usagePage, $usageLimit),
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
     * @return array{
     *     data: list<array<string, int|string|float>>,
     *     total: int,
     *     page: int,
     *     limit: int
     * }
     */
    private function getUsageDistribution(?string $salesChannelId, int $page, int $limit): array
    {
        $normalizedPage = max(1, $page);
        $normalizedLimit = min(100, max(1, $limit));
        $offset = ($normalizedPage - 1) * $normalizedLimit;

        $countQuery = <<<'SQL'
SELECT COUNT(*) FROM (
    SELECT customer.id
    FROM ictech_multi_cart cart
    LEFT JOIN customer customer ON customer.id = cart.customer_id
    WHERE 1 = 1
SQL;

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
        $types = [];

        if ($salesChannelId !== null) {
            $countQuery .= ' AND cart.sales_channel_id = UNHEX(:salesChannelId)';
            $query .= ' AND cart.sales_channel_id = UNHEX(:salesChannelId)';
            $params['salesChannelId'] = $salesChannelId;
        }

        $countQuery .= '
    GROUP BY
        customer.id,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(customer.first_name, \'\'), \' \', COALESCE(customer.last_name, \'\'))), \'\'), customer.email, \'Unknown\'),
        customer.email
) usage_distribution';

        /** @var mixed $total */
        $total = $this->connection->fetchOne($countQuery, $params);

        $query .= '
GROUP BY customer.id, customerName, customerEmail
ORDER BY cartCount DESC, totalValue DESC
LIMIT :limit OFFSET :offset';

        $params['limit'] = $normalizedLimit;
        $params['offset'] = $offset;
        $types['limit'] = \PDO::PARAM_INT;
        $types['offset'] = \PDO::PARAM_INT;

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($query, $params, $types);

        return [
            'data' => array_map(function (array $row): array {
                return [
                    'customerId' => $this->toString($row['customerId'] ?? null),
                    'customerName' => $this->toString($row['customerName'] ?? null, 'Unknown'),
                    'customerEmail' => $this->toString($row['customerEmail'] ?? null),
                    'cartCount' => $this->toInt($row['cartCount'] ?? null),
                    'totalValue' => round($this->toFloat($row['totalValue'] ?? null), 2),
                ];
            }, $rows),
            'total' => $this->toInt($total),
            'page' => $normalizedPage,
            'limit' => $normalizedLimit,
        ];
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
