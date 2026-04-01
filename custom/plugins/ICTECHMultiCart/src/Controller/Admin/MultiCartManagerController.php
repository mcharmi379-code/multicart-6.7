<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Controller\Admin;

use Doctrine\DBAL\Connection;
use ICTECHMultiCart\Core\Content\MultiCart\MultiCartCollection;
use ICTECHMultiCart\Core\Content\MultiCartBlacklist\MultiCartBlacklistCollection;
use ICTECHMultiCart\Core\Content\MultiCartConfig\MultiCartConfigCollection;
use ICTECHMultiCart\Core\Content\MultiCartOrder\MultiCartOrderCollection;
use ICTECHMultiCart\Service\AnalyticsService;
use ICTECHMultiCart\Service\MultiCartConfigService;
use ICTECHMultiCart\Service\MultiCartService;
use Ramsey\Uuid\Uuid;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
final class MultiCartManagerController
{
    public function __construct(
        /** @var EntityRepository<MultiCartCollection> */
        private EntityRepository $multiCartRepository,
        /** @var EntityRepository<MultiCartConfigCollection> */
        private EntityRepository $multiCartConfigRepository,
        /** @var EntityRepository<MultiCartBlacklistCollection> */
        private EntityRepository $multiCartBlacklistRepository,
        /** @var EntityRepository<MultiCartOrderCollection> */
        private EntityRepository $multiCartOrderRepository,
        /** @var EntityRepository<SalesChannelCollection> */
        private EntityRepository $salesChannelRepository,
        private MultiCartService $multiCartService,
        private AnalyticsService $analyticsService,
        private MultiCartConfigService $configService,
        private Connection $connection
    ) {
    }

    #[Route(path: '/api/_action/multi-cart/dashboard', name: 'api.multi_cart.dashboard', methods: ['GET'], defaults: ['_routeScope' => ['api']])]
    public function getDashboard(Request $request, Context $context): JsonResponse
    {
        $salesChannelId = $this->normalizeSalesChannelId($request->query->get('salesChannelId'));

        if ($salesChannelId === false) {
            return new JsonResponse(['error' => 'Invalid sales channel ID'], 400);
        }

        if ($salesChannelId === null) {
            return new JsonResponse([
                'activeCarts' => [],
                'analytics' => [
                    'totalCartsCreated' => 0,
                    'cartsConvertedToOrders' => 0,
                    'conversionRate' => 0.0,
                    'averageItemsPerCart' => 0.0,
                    'averageCartValue' => 0.0,
                    'totalCartValue' => 0.0,
                    'usageDistribution' => [],
                ],
                'completedOrders' => [],
            ]);
        }

        $activeCarts = $this->multiCartService->getActiveCarts($salesChannelId, $context);
        $analytics = $this->analyticsService->getAnalytics($salesChannelId, $context);
        $completedOrders = $this->multiCartService->getCompletedOrders($salesChannelId, $context);

        return new JsonResponse([
            'activeCarts' => $activeCarts,
            'analytics' => $analytics,
            'completedOrders' => $completedOrders,
        ]);
    }

    #[Route(path: '/api/_action/multi-cart/config', name: 'api.multi_cart.config.get', methods: ['GET'], defaults: ['_routeScope' => ['api']])]
    public function getConfig(Request $request, Context $context): JsonResponse
    {
        $salesChannelId = $request->query->get('salesChannelId');
        if (!is_string($salesChannelId)) {
            return new JsonResponse(['error' => 'Sales Channel ID is required'], 400);
        }

        $config = $this->configService->getStoredConfig($salesChannelId);

        if ($config === null) {
            return new JsonResponse([]);
        }

        return new JsonResponse([
            'id' => $this->getRequiredStringFromRow($config, 'id'),
            'salesChannelId' => $this->getRequiredStringFromRow($config, 'salesChannelId'),
            'pluginEnabled' => $this->getRequiredBoolFromRow($config, 'pluginEnabled'),
            'maxCartsPerUser' => $this->getRequiredIntFromRow($config, 'maxCartsPerUser'),
            'checkoutPrefsEnabled' => $this->getRequiredBoolFromRow($config, 'checkoutPrefsEnabled'),
            'promotionsEnabled' => $this->getRequiredBoolFromRow($config, 'promotionsEnabled'),
            'multiPaymentEnabled' => $this->getRequiredBoolFromRow($config, 'multiPaymentEnabled'),
            'conflictResolution' => $this->getRequiredStringFromRow($config, 'conflictResolution'),
            'uiStyle' => $this->getRequiredStringFromRow($config, 'uiStyle'),
        ]);
    }

    #[Route(path: '/api/_action/multi-cart/config', name: 'api.multi_cart.config.save', methods: ['POST'], defaults: ['_routeScope' => ['api']])]
    public function saveConfig(Request $request, Context $context): JsonResponse
    {

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid request body'], 400);
        }

        $salesChannelId = $data['salesChannelId'] ?? null;
        if (!is_string($salesChannelId)) {
            return new JsonResponse(['error' => 'Sales Channel ID is required'], 400);
        }

        $this->configService->saveConfig($salesChannelId, $data);

        return new JsonResponse(['success' => true]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function getRequiredStringFromRow(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new \UnexpectedValueException(sprintf('Expected string value for "%s".', $key));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function getRequiredIntFromRow(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException(sprintf('Expected int value for "%s".', $key));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function getRequiredBoolFromRow(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        throw new \UnexpectedValueException(sprintf('Expected bool value for "%s".', $key));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function getNullableStringFromRow(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function getFloatFromRow(array $row, string $key): float
    {
        $value = $row[$key] ?? null;

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function getIntFromRow(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function normalizeScalarInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Expected numeric scalar value.');
    }

    private function normalizeSalesChannelId(mixed $value): string|false|null
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return null;
        }

        if (!preg_match('/^[0-9a-f]{32}$/', $normalized)) {
            return false;
        }

        return $normalized;
    }


    #[Route(path: '/api/_action/multi-cart/blacklist', name: 'api.multi_cart.blacklist.list', methods: ['GET'], defaults: ['_routeScope' => ['api']])]
    public function getBlacklist(Request $request, Context $context): JsonResponse
    {
        $salesChannelId = $request->query->get('salesChannelId');
        $page = (int)$request->query->get('page', 1);
        $limit = (int)$request->query->get('limit', 50);
        $offset = ($page - 1) * $limit;

        $query = <<<'SQL'
SELECT
    HEX(blacklist.id) as id,
    HEX(blacklist.customer_id) as customerId,
    HEX(blacklist.sales_channel_id) as salesChannelId,
    customer.email as customerEmail,
    TRIM(CONCAT(COALESCE(customer.first_name, ''), ' ', COALESCE(customer.last_name, ''))) as customerName,
    blacklist.reason as reason,
    blacklist.created_at as createdAt
FROM ictech_multi_cart_blacklist blacklist
LEFT JOIN customer customer ON customer.id = blacklist.customer_id
SQL;
        $countQuery = 'SELECT COUNT(*) as total FROM ictech_multi_cart_blacklist';
        $params = [];

        if (is_string($salesChannelId)) {
            $query .= ' WHERE blacklist.sales_channel_id = UNHEX(?)';
            $countQuery .= ' WHERE sales_channel_id = UNHEX(?)';
            $params = [$salesChannelId];
        }

        $query .= sprintf(' LIMIT %d OFFSET %d', $limit, $offset);

        $data = $this->connection->fetchAllAssociative($query, $params);
        $total = $this->connection->fetchOne($countQuery, $params);

        return new JsonResponse([
            'data' => $data,
            'total' => $this->normalizeScalarInt($total),
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[Route(path: '/api/_action/multi-cart/blacklist', name: 'api.multi_cart.blacklist.add', methods: ['POST'], defaults: ['_routeScope' => ['api']])]
    public function addToBlacklist(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!is_array($data)) {
                return new JsonResponse(['error' => 'Invalid request body'], 400);
            }

            $customerId = $data['customerId'] ?? null;
            $salesChannelId = $data['salesChannelId'] ?? null;
            if (!is_string($customerId) || !is_string($salesChannelId)) {
                return new JsonResponse(['error' => 'Invalid customer or sales channel ID'], 400);
            }

            // Check if already exists
            $existing = $this->connection->fetchOne(
                'SELECT id FROM ictech_multi_cart_blacklist WHERE customer_id = UNHEX(?) AND sales_channel_id = UNHEX(?)',
                [$customerId, $salesChannelId]
            );

            if ($existing) {
                return new JsonResponse(['success' => true, 'alreadyExists' => true]);
            }


            // Insert directly using SQL
            $id = Uuid::uuid4()->getHex();
            $reason = $data['reason'] ?? null;
            $createdBy = $data['createdBy'] ?? null;
            $now = (new \DateTime())->format('Y-m-d H:i:s.u');

            $this->connection->executeStatement(
                'INSERT INTO ictech_multi_cart_blacklist (id, customer_id, sales_channel_id, reason, created_by, created_at) 
                 VALUES (UNHEX(?), UNHEX(?), UNHEX(?), ?, ?, ?)',
                [$id, $customerId, $salesChannelId, $reason, $createdBy, $now]
            );

            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route(path: '/api/_action/multi-cart/blacklist/{id}', name: 'api.multi_cart.blacklist.remove', methods: ['DELETE'], defaults: ['_routeScope' => ['api']])]
    public function removeFromBlacklist(string $id, Context $context): JsonResponse
    {
        $this->connection->executeStatement(
            'DELETE FROM ictech_multi_cart_blacklist WHERE id = UNHEX(?)',
            [$id]
        );

        return new JsonResponse(['success' => true]);
    }

    #[Route(path: '/api/_action/multi-cart/monitoring/carts', name: 'api.multi_cart.monitoring.carts', methods: ['GET'], defaults: ['_routeScope' => ['api']])]
    public function getMonitoringCarts(Request $request, Context $context): JsonResponse
    {
        $salesChannelId = $request->query->get('salesChannelId');
        $customerId = $request->query->get('customerId');

        if (!is_string($salesChannelId) || !is_string($customerId) || $salesChannelId === '' || $customerId === '') {
            return new JsonResponse([
                'data' => [],
                'total' => 0,
            ]);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    LOWER(HEX(cart.id)) AS id,
    cart.name AS name,
    cart.status AS status,
    cart.promotion_code AS promotionCode,
    cart.promotion_discount AS promotionDiscount,
    cart.subtotal AS subtotal,
    cart.total AS total,
    cart.created_at AS createdAt,
    COALESCE(cart.updated_at, cart.created_at) AS updatedAt,
    COUNT(item.id) AS itemCount
FROM ictech_multi_cart cart
LEFT JOIN ictech_multi_cart_item item ON item.multi_cart_id = cart.id
WHERE cart.sales_channel_id = UNHEX(:salesChannelId)
  AND cart.customer_id = UNHEX(:customerId)
GROUP BY cart.id, cart.name, cart.status, cart.promotion_code, cart.promotion_discount, cart.subtotal, cart.total, cart.created_at, updatedAt
ORDER BY updatedAt DESC
SQL,
            [
                'salesChannelId' => $salesChannelId,
                'customerId' => $customerId,
            ]
        );

        $carts = array_map(function (array $row): array {
            $cartId = $this->getRequiredStringFromRow($row, 'id');

            /** @var list<array<string, mixed>> $items */
            $items = $this->connection->fetchAllAssociative(
                <<<'SQL'
SELECT
    item.product_name AS productName,
    item.product_number AS productNumber,
    item.quantity AS quantity,
    item.unit_price AS unitPrice,
    item.total_price AS totalPrice
FROM ictech_multi_cart_item item
WHERE item.multi_cart_id = UNHEX(:cartId)
ORDER BY item.created_at ASC
SQL,
                ['cartId' => $cartId]
            );

            return [
                'id' => $cartId,
                'name' => $this->getRequiredStringFromRow($row, 'name'),
                'status' => $this->getRequiredStringFromRow($row, 'status'),
                'promotionCode' => $this->getNullableStringFromRow($row, 'promotionCode'),
                'promotionDiscount' => $this->getFloatFromRow($row, 'promotionDiscount'),
                'subtotal' => $this->getFloatFromRow($row, 'subtotal'),
                'total' => $this->getFloatFromRow($row, 'total'),
                'createdAt' => $this->getNullableStringFromRow($row, 'createdAt'),
                'updatedAt' => $this->getNullableStringFromRow($row, 'updatedAt'),
                'itemCount' => $this->getIntFromRow($row, 'itemCount'),
                'items' => array_map(fn (array $item): array => [
                    'productName' => $this->getNullableStringFromRow($item, 'productName') ?? '',
                    'productNumber' => $this->getNullableStringFromRow($item, 'productNumber') ?? '',
                    'quantity' => $this->getIntFromRow($item, 'quantity'),
                    'unitPrice' => $this->getFloatFromRow($item, 'unitPrice'),
                    'totalPrice' => $this->getFloatFromRow($item, 'totalPrice'),
                ], $items),
            ];
        }, $rows);

        return new JsonResponse([
            'data' => $carts,
            'total' => count($carts),
        ]);
    }

    #[Route(path: '/api/_action/multi-cart/sales-channels', name: 'api.multi_cart.sales_channels', methods: ['GET'], defaults: ['_routeScope' => ['api']])]
    public function getSalesChannels(Context $context): JsonResponse
    {
        $criteria = new Criteria();
        $result = $this->salesChannelRepository->search($criteria, $context);

        $salesChannels = [];
        foreach ($result->getEntities() as $salesChannel) {
            /** @var SalesChannelEntity $salesChannel */
            $translatedName = $salesChannel->getTranslation('name');
            $name = is_string($translatedName) && $translatedName !== ''
                ? $translatedName
                : ($salesChannel->getName() ?? $salesChannel->getId());

            $salesChannels[] = [
                'id' => $salesChannel->getId(),
                'name' => $name,
            ];
        }

        return new JsonResponse($salesChannels);
    }
}
