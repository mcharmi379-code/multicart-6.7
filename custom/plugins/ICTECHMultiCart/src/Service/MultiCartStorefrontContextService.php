<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class MultiCartStorefrontContextService
{
    private const DEFAULT_CART_NAME = 'Default Cart';
    private const CREATE_CART_REASON_NOT_LOGGED_IN = 'not_logged_in';
    private const CREATE_CART_REASON_PLUGIN_DISABLED = 'plugin_disabled';
    private const CREATE_CART_REASON_CUSTOMER_NOT_ALLOWED = 'customer_not_allowed';
    private const CREATE_CART_REASON_BLACKLISTED = 'blacklisted';
    private const CREATE_CART_REASON_LIMIT_REACHED = 'limit_reached';
    private ?bool $hasIsDefaultColumn = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
        private readonly MultiCartConfigService $configService,
        private readonly MultiCartPriceCalculatorService $priceCalculator,
        /** @var EntityRepository<CustomerAddressCollection> */
        private readonly EntityRepository $customerAddressRepository
    ) {
    }

    public function bootstrapCustomerCarts(SalesChannelContext $salesChannelContext): void
    {
        $customer = $salesChannelContext->getCustomer();

        if (!$customer instanceof CustomerEntity) {
            $this->clearActiveCartSelection($salesChannelContext->getSalesChannelId());

            return;
        }

        $state = $this->buildState($salesChannelContext);

        if (!$state['enabled'] || !$state['customerAllowed']) {
            $this->clearActiveCartSelection($salesChannelContext->getSalesChannelId());

            return;
        }

        if ($state['blacklisted']) {
            $this->clearActiveCartSelection($salesChannelContext->getSalesChannelId());
            $this->deactivateAllCarts($customer->getId(), $salesChannelContext->getSalesChannelId());

            return;
        }

        if ($state['activeCartId'] === null) {
            $defaultCartId = $this->ensureDefaultCart($salesChannelContext, $customer, $state);

            if ($defaultCartId !== null) {
                $this->activateCartSelection($defaultCartId, $salesChannelContext, $customer);
            }
        }
    }

    public function buildStruct(SalesChannelContext $salesChannelContext): ArrayStruct
    {
        return new ArrayStruct(
            $this->getState($salesChannelContext),
            'ictech_multi_cart'
        );
    }

    /**
     * @return array{
     *     enabled: bool,
     *     blacklisted: bool,
     *     customerLoggedIn: bool,
     *     customerAllowed: bool,
     *     canCreateCart: bool,
     *     createCartReason: string|null,
     *     salesChannelId: string,
     *     customerId: string|null,
     *     activeCartId: string|null,
     *     activeCart: array<string, mixed>|null,
     *     carts: list<array<string, mixed>>,
     *     cartCount: int,
     *     uiStyle: string,
     *     maxCartsPerUser: int,
     *     checkoutPrefsEnabled: bool,
     *     promotionsEnabled: bool,
     *     multiPaymentEnabled: bool,
     *     conflictResolution: string
     * }
     */
    public function getState(SalesChannelContext $salesChannelContext): array
    {
        $this->bootstrapCustomerCarts($salesChannelContext);

        return $this->buildState($salesChannelContext);
    }

    public function getManagedCustomerId(SalesChannelContext $salesChannelContext): ?string
    {
        $state = $this->getState($salesChannelContext);

        if (!$state['enabled'] || $state['blacklisted'] || !$state['customerAllowed']) {
            return null;
        }

        return $state['customerId'];
    }

    public function createCart(SalesChannelContext $salesChannelContext, string $name): ?string
    {
        $state = $this->getState($salesChannelContext);
        $customer = $salesChannelContext->getCustomer();

        if (!$customer instanceof CustomerEntity || !$state['canCreateCart']) {
            return null;
        }

        $trimmedName = trim($name);

        if ($trimmedName === '') {
            return null;
        }

        $cartId = (string) Uuid::uuid4()->getHex();
        $cartToken = (string) Uuid::uuid4()->getHex();
        $currencyIso = $salesChannelContext->getCurrency()->getIsoCode();
        $paymentMethodId = $salesChannelContext->getPaymentMethod()->getId();
        $shippingMethodId = $salesChannelContext->getShippingMethod()->getId();

        $insert = [
            'id' => $this->toBinary($cartId),
            'customer_id' => $this->toBinary($customer->getId()),
            'sales_channel_id' => $this->toBinary($salesChannelContext->getSalesChannelId()),
            'name' => $trimmedName,
            'notes' => null,
            'status' => 'active',
            'cart_token' => $cartToken,
            'is_active' => 0,
            'shipping_address_id' => $this->toBinaryOrNull($customer->getDefaultShippingAddressId()),
            'billing_address_id' => $this->toBinaryOrNull($customer->getDefaultBillingAddressId()),
            'payment_method_id' => $paymentMethodId,
            'shipping_method_id' => $shippingMethodId,
            'promotion_code' => null,
            'promotion_discount' => 0.00,
            'subtotal' => 0.00,
            'total' => 0.00,
            'currency_iso' => $currencyIso,
            'created_at' => $this->now(),
            'updated_at' => null,
        ];

        if ($this->hasIsDefaultColumn()) {
            $insert['is_default'] = 0;
        }

        $this->connection->insert('ictech_multi_cart', $insert);

        $this->activateCartSelection($cartId, $salesChannelContext, $customer);

        return $cartId;
    }

    public function activateCart(string $cartId, SalesChannelContext $salesChannelContext): bool
    {
        $customerId = $this->getManagedCustomerId($salesChannelContext);

        if ($customerId === null) {
            return false;
        }

        if (!$this->cartExistsForCustomer($cartId, $customerId, $salesChannelContext->getSalesChannelId())) {
            return false;
        }

        $this->activateCartSelection($cartId, $salesChannelContext, $customerId);

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAccountCarts(SalesChannelContext $salesChannelContext): array
    {
        $state = $this->getState($salesChannelContext);

        if (!$state['enabled'] || !$state['customerLoggedIn'] || !$state['customerAllowed'] || $state['blacklisted']) {
            return [];
        }

        return $state['carts'];
    }

    /**
     * @return array{
     *     addresses: list<array{id: string, label: string}>,
     *     countries: list<array{id: string, name: string}>,
     *     paymentMethods: list<array{id: string, name: string}>,
     *     shippingMethods: list<array{id: string, name: string}>
     * }
     */
    public function getAccountOptions(SalesChannelContext $salesChannelContext): array
    {
        $customerId = $this->getManagedCustomerId($salesChannelContext);

        if ($customerId === null) {
            return [
                'addresses' => [],
                'countries' => [],
                'paymentMethods' => [],
                'shippingMethods' => [],
            ];
        }

        /** @var list<array<string, mixed>> $addresses */
        $addresses = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id,
                    CONCAT_WS(\' - \',
                        NULLIF(TRIM(CONCAT_WS(\' \', first_name, last_name)), \'\'),
                        NULLIF(TRIM(street), \'\')
                    ) AS label
             FROM customer_address
             WHERE customer_id = UNHEX(:customerId)
             ORDER BY created_at ASC',
            ['customerId' => $customerId]
        );

        /** @var list<array<string, mixed>> $paymentMethods */
        $paymentMethods = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(method.id)) AS id,
                    COALESCE(translation.name, method.technical_name, method.handler_identifier) AS name
             FROM sales_channel_payment_method assignment
             INNER JOIN payment_method method ON method.id = assignment.payment_method_id
             LEFT JOIN payment_method_translation translation
                ON translation.payment_method_id = method.id
               AND translation.language_id = UNHEX(:languageId)
             WHERE assignment.sales_channel_id = UNHEX(:salesChannelId)
               AND method.active = 1
             ORDER BY method.position ASC, name ASC',
            [
                'languageId' => $salesChannelContext->getLanguageId(),
                'salesChannelId' => $salesChannelContext->getSalesChannelId(),
            ]
        );

        /** @var list<array<string, mixed>> $shippingMethods */
        $shippingMethods = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(method.id)) AS id,
                    COALESCE(translation.name, method.technical_name) AS name
             FROM sales_channel_shipping_method assignment
             INNER JOIN shipping_method method ON method.id = assignment.shipping_method_id
             LEFT JOIN shipping_method_translation translation
                ON translation.shipping_method_id = method.id
               AND translation.language_id = UNHEX(:languageId)
             WHERE assignment.sales_channel_id = UNHEX(:salesChannelId)
               AND method.active = 1
             ORDER BY name ASC',
            [
                'languageId' => $salesChannelContext->getLanguageId(),
                'salesChannelId' => $salesChannelContext->getSalesChannelId(),
            ]
        );

        /** @var list<array<string, mixed>> $countries */
        $countries = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(country.id)) AS id,
                    COALESCE(translation.name, country.iso) AS name
             FROM country country
             LEFT JOIN country_translation translation
                ON translation.country_id = country.id
               AND translation.language_id = UNHEX(:languageId)
             WHERE country.active = 1
             ORDER BY name ASC',
            [
                'languageId' => $salesChannelContext->getLanguageId(),
            ]
        );

        return [
            'addresses' => array_map(fn (array $address): array => [
                'id' => $this->toNullableString($address['id'] ?? null) ?? '',
                'label' => $this->toNullableString($address['label'] ?? null) ?? 'Address',
            ], $addresses),
            'countries' => array_map(fn (array $country): array => [
                'id' => $this->toNullableString($country['id'] ?? null) ?? '',
                'name' => $this->toNullableString($country['name'] ?? null) ?? 'Country',
            ], $countries),
            'paymentMethods' => array_map(fn (array $method): array => [
                'id' => $this->toNullableString($method['id'] ?? null) ?? '',
                'name' => $this->toNullableString($method['name'] ?? null) ?? 'Payment',
            ], $paymentMethods),
            'shippingMethods' => array_map(fn (array $method): array => [
                'id' => $this->toNullableString($method['id'] ?? null) ?? '',
                'name' => $this->toNullableString($method['name'] ?? null) ?? 'Shipping',
            ], $shippingMethods),
        ];
    }

    /**
     * @param array{
     *     shippingAddressId?: string|null,
     *     billingAddressId?: string|null,
     *     paymentMethodId?: string|null,
     *     shippingMethodId?: string|null,
     *     promotionCode?: string|null
     * } $payload
     */
    public function updateCartPreferences(string $cartId, array $payload, SalesChannelContext $salesChannelContext): bool
    {
        $customerId = $this->getManagedCustomerId($salesChannelContext);

        if ($customerId === null || !$this->cartExistsForCustomer($cartId, $customerId, $salesChannelContext->getSalesChannelId())) {
            return false;
        }

        $options = $this->getAccountOptions($salesChannelContext);
        $addressIds = array_column($options['addresses'], 'id');
        $paymentMethodIds = array_column($options['paymentMethods'], 'id');
        $shippingMethodIds = array_column($options['shippingMethods'], 'id');

        $update = [
            'updated_at' => $this->now(),
        ];

        if (array_key_exists('shippingAddressId', $payload)) {
            $update['shipping_address_id'] = in_array($payload['shippingAddressId'], $addressIds, true)
                ? $this->toBinary((string) $payload['shippingAddressId'])
                : null;
        }

        if (array_key_exists('billingAddressId', $payload)) {
            $update['billing_address_id'] = in_array($payload['billingAddressId'], $addressIds, true)
                ? $this->toBinary((string) $payload['billingAddressId'])
                : null;
        }

        if (array_key_exists('paymentMethodId', $payload)) {
            $update['payment_method_id'] = in_array($payload['paymentMethodId'], $paymentMethodIds, true)
                ? (string) $payload['paymentMethodId']
                : null;
        }

        if (array_key_exists('shippingMethodId', $payload)) {
            $update['shipping_method_id'] = in_array($payload['shippingMethodId'], $shippingMethodIds, true)
                ? (string) $payload['shippingMethodId']
                : null;
        }

        if (array_key_exists('promotionCode', $payload)) {
            $promotionCode = trim((string) ($payload['promotionCode'] ?? ''));
            $update['promotion_code'] = $promotionCode !== '' ? $promotionCode : null;
        }

        $this->connection->update('ictech_multi_cart', $update, [
            'id' => $this->toBinary($cartId),
        ]);

        $this->priceCalculator->recalculateCart($cartId, $salesChannelContext);

        return true;
    }

    /**
     * @param array{
     *     firstName?: string|null,
     *     lastName?: string|null,
     *     street?: string|null,
     *     zipcode?: string|null,
     *     city?: string|null,
     *     countryId?: string|null
     * } $payload
     *
     * @return array{
     *     success: bool,
     *     addressId?: string,
     *     options?: array{
     *         addresses: list<array{id: string, label: string}>,
     *         countries: list<array{id: string, name: string}>,
     *         paymentMethods: list<array{id: string, name: string}>,
     *         shippingMethods: list<array{id: string, name: string}>
     *     }
     * }
     */
    public function createCustomerAddress(array $payload, SalesChannelContext $salesChannelContext): array
    {
        $customer = $salesChannelContext->getCustomer();

        if (!$customer instanceof CustomerEntity) {
            return [
                'success' => false,
            ];
        }

        $firstName = $this->normalizeNonEmptyString($payload['firstName'] ?? null);
        $lastName = $this->normalizeNonEmptyString($payload['lastName'] ?? null);
        $street = $this->normalizeNonEmptyString($payload['street'] ?? null);
        $zipcode = $this->normalizeNonEmptyString($payload['zipcode'] ?? null);
        $city = $this->normalizeNonEmptyString($payload['city'] ?? null);
        $countryId = $this->normalizeNonEmptyString($payload['countryId'] ?? null);

        if ($firstName === null || $lastName === null || $street === null || $zipcode === null || $city === null || $countryId === null) {
            return [
                'success' => false,
            ];
        }

        $addressId = (string) Uuid::uuid4()->getHex();

        $this->customerAddressRepository->create(
            [[
                'id' => $addressId,
                'customerId' => $customer->getId(),
                'countryId' => $countryId,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'street' => $street,
                'zipcode' => $zipcode,
                'city' => $city,
            ]],
            $salesChannelContext->getContext()
        );

        return [
            'success' => true,
            'addressId' => $addressId,
            'options' => $this->getAccountOptions($salesChannelContext),
        ];
    }

    public function updateCartName(string $cartId, string $name, SalesChannelContext $salesChannelContext): bool
    {
        $customerId = $this->getManagedCustomerId($salesChannelContext);

        if ($customerId === null || !$this->cartExistsForCustomer($cartId, $customerId, $salesChannelContext->getSalesChannelId())) {
            return false;
        }

        $trimmedName = trim($name);

        if ($trimmedName === '') {
            return false;
        }

        $this->connection->update('ictech_multi_cart', [
            'name' => $trimmedName,
            'updated_at' => $this->now(),
        ], [
            'id' => $this->toBinary($cartId),
        ]);

        return true;
    }

    /**
     * @return array{
     *     success: bool,
     *     applied: bool,
     *     messageKey: string|null,
     *     messageParameters: array<string, mixed>,
     *     state: array<string, mixed>
     * }
     */
    public function updatePromotionCode(string $cartId, string $promotionCode, SalesChannelContext $salesChannelContext): array
    {
        $state = $this->getState($salesChannelContext);

        if (!$state['promotionsEnabled']) {
            return [
                'success' => false,
                'applied' => false,
                'messageKey' => null,
                'messageParameters' => [],
                'state' => $state,
            ];
        }

        $customerId = $this->getManagedCustomerId($salesChannelContext);

        if ($customerId === null || !$this->cartExistsForCustomer($cartId, $customerId, $salesChannelContext->getSalesChannelId())) {
            return [
                'success' => false,
                'applied' => false,
                'messageKey' => null,
                'messageParameters' => [],
                'state' => $state,
            ];
        }

        $normalizedPromotionCode = trim($promotionCode);
        $previousCart = $this->getCartSummary($cartId, $salesChannelContext);
        $previousPromotionCode = is_string($previousCart['promotionCode'] ?? null)
            ? trim((string) $previousCart['promotionCode'])
            : '';

        if (!$this->updateCartPreferences($cartId, ['promotionCode' => $normalizedPromotionCode], $salesChannelContext)) {
            return [
                'success' => false,
                'applied' => false,
                'messageKey' => null,
                'messageParameters' => [],
                'state' => $this->getState($salesChannelContext),
            ];
        }

        $result = $this->priceCalculator->recalculateCart($cartId, $salesChannelContext);

        $firstError = $result['promotionErrors'][0] ?? null;
        $messageKey = is_array($firstError) ? ($firstError['messageKey'] ?? null) : null;
        $messageParameters = is_array($firstError) ? ($firstError['parameters'] ?? []) : [];
        $shouldRejectPromotion = $normalizedPromotionCode !== '' && $messageKey === 'promotion-not-found';

        if ($shouldRejectPromotion) {
            $this->persistPromotionSnapshot($cartId, $previousPromotionCode, $result['discount']);

            $this->priceCalculator->recalculateCart($cartId, $salesChannelContext);

            return [
                'success' => false,
                'applied' => false,
                'messageKey' => $messageKey,
                'messageParameters' => $messageParameters,
                'state' => $this->getState($salesChannelContext),
            ];
        }

        $finalPromotionCode = $normalizedPromotionCode;

        if (!$result['promotionApplied']) {
            $finalPromotionCode = '';
        }

        $this->persistPromotionSnapshot($cartId, $finalPromotionCode, $result['discount']);

        return [
            'success' => true,
            'applied' => $normalizedPromotionCode === '' || $result['promotionApplied'],
            'messageKey' => is_string($messageKey) ? $messageKey : null,
            'messageParameters' => $messageParameters,
            'state' => $this->getState($salesChannelContext),
        ];
    }

    /**
     * @param list<string> $orderIds
     *
     * @return array<string, array{cartName: string, orderedAt: string|null}>
     */
    public function getOrderMetaMap(array $orderIds): array
    {
        $orderIds = array_values(array_filter($orderIds, static fn (string $orderId): bool => $orderId !== ''));

        if ($orderIds === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(order_id)) AS orderId,
                    cart_name_snapshot AS cartName,
                    ordered_at AS orderedAt
             FROM ictech_multi_cart_order
             WHERE LOWER(HEX(order_id)) IN (:orderIds)',
            ['orderIds' => $orderIds],
            ['orderIds' => ArrayParameterType::STRING]
        );

        $meta = [];

        foreach ($rows as $row) {
            $orderId = $this->toNullableString($row['orderId'] ?? null);

            if ($orderId === null) {
                continue;
            }

            $meta[$orderId] = [
                'cartName' => $this->toNullableString($row['cartName'] ?? null) ?? self::DEFAULT_CART_NAME,
                'orderedAt' => $this->toNullableString($row['orderedAt'] ?? null),
            ];
        }

        return $meta;
    }

    public function duplicateCart(string $cartId, SalesChannelContext $salesChannelContext): ?string
    {
        $customerId = $this->getManagedCustomerId($salesChannelContext);

        if ($customerId === null || !$this->cartExistsForCustomer($cartId, $customerId, $salesChannelContext->getSalesChannelId())) {
            return null;
        }

        /** @var array<string, mixed>|false $cart */
        $cart = $this->connection->fetchAssociative(
            'SELECT *
             FROM ictech_multi_cart
             WHERE id = UNHEX(:cartId)
               AND customer_id = UNHEX(:customerId)
               AND sales_channel_id = UNHEX(:salesChannelId)
             LIMIT 1',
            [
                'cartId' => $cartId,
                'customerId' => $customerId,
                'salesChannelId' => $salesChannelContext->getSalesChannelId(),
            ]
        );

        if ($cart === false) {
            return null;
        }

        $newCartId = (string) Uuid::uuid4()->getHex();
        $newCartToken = (string) Uuid::uuid4()->getHex();
        $now = $this->now();
        $originalName = is_string($cart['name'] ?? null) && $cart['name'] !== '' ? $cart['name'] : self::DEFAULT_CART_NAME;

        $insert = [
            'id' => $this->toBinary($newCartId),
            'customer_id' => $cart['customer_id'],
            'sales_channel_id' => $cart['sales_channel_id'],
            'name' => sprintf('%s (Copy)', $originalName),
            'notes' => $cart['notes'] ?? null,
            'status' => $cart['status'] ?? 'active',
            'cart_token' => $newCartToken,
            'is_active' => 0,
            'shipping_address_id' => $cart['shipping_address_id'] ?? null,
            'billing_address_id' => $cart['billing_address_id'] ?? null,
            'payment_method_id' => $cart['payment_method_id'] ?? null,
            'shipping_method_id' => $cart['shipping_method_id'] ?? null,
            'promotion_code' => $cart['promotion_code'] ?? null,
            'promotion_discount' => $cart['promotion_discount'] ?? 0,
            'subtotal' => $cart['subtotal'] ?? 0,
            'total' => $cart['total'] ?? 0,
            'currency_iso' => $cart['currency_iso'] ?? 'EUR',
            'created_at' => $now,
            'updated_at' => null,
        ];

        if ($this->hasIsDefaultColumn()) {
            $insert['is_default'] = 0;
        }

        $this->connection->insert('ictech_multi_cart', $insert);

        /** @var list<array<string, mixed>> $items */
        $items = $this->connection->fetchAllAssociative(
            'SELECT *
             FROM ictech_multi_cart_item
             WHERE multi_cart_id = UNHEX(:cartId)
             ORDER BY created_at ASC',
            ['cartId' => $cartId]
        );

        foreach ($items as $item) {
            $this->connection->insert('ictech_multi_cart_item', [
                'id' => $this->toBinary((string) Uuid::uuid4()->getHex()),
                'multi_cart_id' => $this->toBinary($newCartId),
                'product_id' => $item['product_id'] ?? null,
                'product_number' => $item['product_number'] ?? '',
                'product_name' => $item['product_name'] ?? 'Product',
                'quantity' => $item['quantity'] ?? 0,
                'unit_price' => $item['unit_price'] ?? 0,
                'total_price' => $item['total_price'] ?? 0,
                'payload' => $item['payload'] ?? null,
                'created_at' => $now,
                'updated_at' => null,
            ]);
        }

        return $newCartId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCartSummary(string $cartId, SalesChannelContext $salesChannelContext): ?array
    {
        $state = $this->getState($salesChannelContext);

        return $this->findCartById($state['carts'], $cartId);
    }

    public function deleteCart(string $cartId, SalesChannelContext $salesChannelContext): bool
    {
        $customerId = $this->getManagedCustomerId($salesChannelContext);

        if ($customerId === null || !$this->cartExistsForCustomer($cartId, $customerId, $salesChannelContext->getSalesChannelId())) {
            return false;
        }

        if ($this->isDefaultCart($cartId)) {
            return false;
        }

        /** @var mixed $wasActive */
        $wasActive = $this->connection->fetchOne(
            'SELECT is_active
             FROM ictech_multi_cart
             WHERE id = UNHEX(:cartId)
               AND customer_id = UNHEX(:customerId)
               AND sales_channel_id = UNHEX(:salesChannelId)
             LIMIT 1',
            [
                'cartId' => $cartId,
                'customerId' => $customerId,
                'salesChannelId' => $salesChannelContext->getSalesChannelId(),
            ]
        );

        $this->connection->delete('ictech_multi_cart', [
            'id' => $this->toBinary($cartId),
        ]);

        if ($this->toBool($wasActive)) {
            $remainingCarts = $this->loadCustomerCarts($customerId, $salesChannelContext->getSalesChannelId(), $salesChannelContext->getLanguageId());
            $nextCartId = $this->getFirstCartId($remainingCarts);

            if ($nextCartId !== null) {
                $this->activateCartSelection($nextCartId, $salesChannelContext, $customerId);
            } else {
                $this->clearActiveCartSelection($salesChannelContext->getSalesChannelId());
            }
        }

        return true;
    }

    public function deleteOrResetCompletedCart(string $cartId, string $customerId, string $salesChannelId): void
    {
        if (!$this->cartExistsForCustomer($cartId, $customerId, $salesChannelId)) {
            return;
        }

        if ($this->isDefaultCart($cartId)) {
            $this->resetDefaultCart($cartId);

            return;
        }

        $this->connection->delete('ictech_multi_cart', [
            'id' => $this->toBinary($cartId),
        ]);
    }

    /**
     * @return array{
     *     enabled: bool,
     *     blacklisted: bool,
     *     customerLoggedIn: bool,
     *     customerAllowed: bool,
     *     canCreateCart: bool,
     *     createCartReason: string|null,
     *     salesChannelId: string,
     *     customerId: string|null,
     *     activeCartId: string|null,
     *     activeCart: array<string, mixed>|null,
     *     carts: list<array<string, mixed>>,
     *     cartCount: int,
     *     uiStyle: string,
     *     maxCartsPerUser: int,
     *     checkoutPrefsEnabled: bool,
     *     promotionsEnabled: bool,
     *     multiPaymentEnabled: bool,
     *     conflictResolution: string
     * }
     */
    private function buildState(SalesChannelContext $salesChannelContext): array
    {
        $salesChannelId = $salesChannelContext->getSalesChannelId();
        $config = $this->configService->getConfig($salesChannelId);
        $customer = $salesChannelContext->getCustomer();

        if (!$customer instanceof CustomerEntity) {
            return $this->buildAnonymousState($salesChannelId, $config);
        }

        $customerAllowed = $this->isCustomerGroupAllowed($customer);
        $blacklisted = $this->isBlacklisted($customer->getId(), $salesChannelId);

        if (!$config['pluginEnabled'] || !$customerAllowed || $blacklisted) {
            return $this->buildUnavailableCustomerState(
                $salesChannelId,
                $customer->getId(),
                $config,
                $customerAllowed,
                $blacklisted
            );
        }

        $carts = $this->loadCustomerCarts($customer->getId(), $salesChannelId, $salesChannelContext->getLanguageId());
        $activeCartId = $this->resolveActiveCartId($customer->getId(), $salesChannelId, $carts);
        $activeCart = $this->findCartById($carts, $activeCartId);
        $maxCartsPerUser = $config['maxCartsPerUser'];
        $canCreateCart = $maxCartsPerUser <= 0 || count($carts) < $maxCartsPerUser;

        return $this->buildStatePayload($salesChannelId, $config) + [
            'enabled' => true,
            'blacklisted' => false,
            'customerLoggedIn' => true,
            'customerAllowed' => true,
            'canCreateCart' => $canCreateCart,
            'createCartReason' => $canCreateCart ? null : self::CREATE_CART_REASON_LIMIT_REACHED,
            'salesChannelId' => $salesChannelId,
            'customerId' => $customer->getId(),
            'activeCartId' => $activeCartId,
            'activeCart' => $activeCart,
            'carts' => $carts,
            'cartCount' => count($carts),
        ];
    }

    /**
     * @param array{
     *     pluginEnabled: bool,
     *     maxCartsPerUser: int,
     *     checkoutPrefsEnabled: bool,
     *     promotionsEnabled: bool,
     *     multiPaymentEnabled: bool,
     *     conflictResolution: string,
     *     uiStyle: string
     * } $config
     *
     * @return array{
     *     salesChannelId: string,
     *     uiStyle: string,
     *     maxCartsPerUser: int,
     *     checkoutPrefsEnabled: bool,
     *     promotionsEnabled: bool,
     *     multiPaymentEnabled: bool,
     *     conflictResolution: string
     * }
     */
    private function buildStatePayload(string $salesChannelId, array $config): array
    {
        return [
            'salesChannelId' => $salesChannelId,
            'uiStyle' => $config['uiStyle'],
            'maxCartsPerUser' => $config['maxCartsPerUser'],
            'checkoutPrefsEnabled' => $config['checkoutPrefsEnabled'],
            'promotionsEnabled' => $config['promotionsEnabled'],
            'multiPaymentEnabled' => $config['multiPaymentEnabled'],
            'conflictResolution' => $config['conflictResolution'],
        ];
    }

    /**
     * @param array{
     *     pluginEnabled: bool,
     *     maxCartsPerUser: int,
     *     checkoutPrefsEnabled: bool,
     *     promotionsEnabled: bool,
     *     multiPaymentEnabled: bool,
     *     conflictResolution: string,
     *     uiStyle: string
     * } $config
     *
     * @return array{
     *     enabled: bool,
     *     blacklisted: bool,
     *     customerLoggedIn: bool,
     *     customerAllowed: bool,
     *     canCreateCart: bool,
     *     createCartReason: string|null,
     *     salesChannelId: string,
     *     customerId: string|null,
     *     activeCartId: string|null,
     *     activeCart: array<string, mixed>|null,
     *     carts: list<array<string, mixed>>,
     *     cartCount: int,
     *     uiStyle: string,
     *     maxCartsPerUser: int,
     *     checkoutPrefsEnabled: bool,
     *     promotionsEnabled: bool,
     *     multiPaymentEnabled: bool,
     *     conflictResolution: string
     * }
     */
    private function buildAnonymousState(string $salesChannelId, array $config): array
    {
        return $this->buildStatePayload($salesChannelId, $config) + [
            'enabled' => $config['pluginEnabled'],
            'blacklisted' => false,
            'customerLoggedIn' => false,
            'customerAllowed' => false,
            'canCreateCart' => false,
            'createCartReason' => self::CREATE_CART_REASON_NOT_LOGGED_IN,
            'customerId' => null,
            'activeCartId' => null,
            'activeCart' => null,
            'carts' => [],
            'cartCount' => 0,
        ];
    }

    /**
     * @param array{
     *     pluginEnabled: bool,
     *     maxCartsPerUser: int,
     *     checkoutPrefsEnabled: bool,
     *     promotionsEnabled: bool,
     *     multiPaymentEnabled: bool,
     *     conflictResolution: string,
     *     uiStyle: string
     * } $config
     *
     * @return array{
     *     enabled: bool,
     *     blacklisted: bool,
     *     customerLoggedIn: bool,
     *     customerAllowed: bool,
     *     canCreateCart: bool,
     *     createCartReason: string|null,
     *     salesChannelId: string,
     *     customerId: string|null,
     *     activeCartId: string|null,
     *     activeCart: array<string, mixed>|null,
     *     carts: list<array<string, mixed>>,
     *     cartCount: int,
     *     uiStyle: string,
     *     maxCartsPerUser: int,
     *     checkoutPrefsEnabled: bool,
     *     promotionsEnabled: bool,
     *     multiPaymentEnabled: bool,
     *     conflictResolution: string
     * }
     */
    private function buildUnavailableCustomerState(
        string $salesChannelId,
        string $customerId,
        array $config,
        bool $customerAllowed,
        bool $blacklisted
    ): array {
        return $this->buildStatePayload($salesChannelId, $config) + [
            'enabled' => $config['pluginEnabled'],
            'blacklisted' => $blacklisted,
            'customerLoggedIn' => true,
            'customerAllowed' => $customerAllowed,
            'canCreateCart' => false,
            'createCartReason' => $this->resolveCreateCartReason($config['pluginEnabled'], $customerAllowed, $blacklisted),
            'customerId' => $customerId,
            'activeCartId' => null,
            'activeCart' => null,
            'carts' => [],
            'cartCount' => 0,
        ];
    }

    private function resolveCreateCartReason(bool $pluginEnabled, bool $customerAllowed, bool $blacklisted): string
    {
        if ($blacklisted) {
            return self::CREATE_CART_REASON_BLACKLISTED;
        }

        if (!$customerAllowed) {
            return self::CREATE_CART_REASON_CUSTOMER_NOT_ALLOWED;
        }

        if (!$pluginEnabled) {
            return self::CREATE_CART_REASON_PLUGIN_DISABLED;
        }

        return self::CREATE_CART_REASON_LIMIT_REACHED;
    }

    public function getCreateCartFailureReason(SalesChannelContext $salesChannelContext): ?string
    {
        $state = $this->getState($salesChannelContext);

        if ($state['canCreateCart']) {
            return null;
        }

        return is_string($state['createCartReason'] ?? null) ? $state['createCartReason'] : null;
    }

    /**
     * @return array{messageKey: string, message: string}
     */
    public function getCreateCartFailureMessage(SalesChannelContext $salesChannelContext): array
    {
        $state = $this->getState($salesChannelContext);
        $reason = $this->getCreateCartFailureReason($salesChannelContext);

        return match ($reason) {
            self::CREATE_CART_REASON_LIMIT_REACHED => [
                'messageKey' => 'ictech-multi-cart.account.createLimitReached',
                'message' => sprintf(
                    'You have reached the maximum of %d carts for your account.',
                    $state['maxCartsPerUser']
                ),
            ],
            self::CREATE_CART_REASON_BLACKLISTED => [
                'messageKey' => 'ictech-multi-cart.account.blacklistedMessage',
                'message' => 'Multi-cart is not available for your account.',
            ],
            self::CREATE_CART_REASON_PLUGIN_DISABLED => [
                'messageKey' => 'ictech-multi-cart.account.pluginDisabledMessage',
                'message' => 'Multi-cart is currently unavailable.',
            ],
            self::CREATE_CART_REASON_CUSTOMER_NOT_ALLOWED => [
                'messageKey' => 'ictech-multi-cart.account.customerNotAllowedMessage',
                'message' => 'Your account is not eligible for multi-cart.',
            ],
            default => [
                'messageKey' => 'ictech-multi-cart.account.createFailed',
                'message' => 'The cart could not be created.',
            ],
        };
    }

    /**
     * @param array{
     *     enabled: bool,
     *     blacklisted: bool,
     *     customerLoggedIn: bool,
     *     customerAllowed: bool,
     *     canCreateCart: bool,
     *     createCartReason: string|null,
     *     salesChannelId: string,
     *     customerId: string|null,
     *     activeCartId: string|null,
     *     activeCart: array<string, mixed>|null,
     *     carts: list<array<string, mixed>>,
     *     cartCount: int,
     *     uiStyle: string,
     *     maxCartsPerUser: int,
     *     checkoutPrefsEnabled: bool,
     *     promotionsEnabled: bool,
     *     multiPaymentEnabled: bool,
     *     conflictResolution: string
     * } $state
     */
    private function ensureDefaultCart(
        SalesChannelContext $salesChannelContext,
        CustomerEntity $customer,
        array $state
    ): ?string {
        if ($state['cartCount'] > 0 || !$state['canCreateCart']) {
            return $state['cartCount'] > 0 && $state['activeCartId'] === null
                ? $this->getFirstCartId($state['carts'])
                : null;
        }

        $cartId = (string) Uuid::uuid4()->getHex();
        $cartToken = (string) Uuid::uuid4()->getHex();
        $currencyIso = $salesChannelContext->getCurrency()->getIsoCode();
        $paymentMethodId = $salesChannelContext->getPaymentMethod()->getId();
        $shippingMethodId = $salesChannelContext->getShippingMethod()->getId();

        $insert = [
            'id' => $this->toBinary($cartId),
            'customer_id' => $this->toBinary($customer->getId()),
            'sales_channel_id' => $this->toBinary($salesChannelContext->getSalesChannelId()),
            'name' => self::DEFAULT_CART_NAME,
            'notes' => null,
            'status' => 'active',
            'cart_token' => $cartToken,
            'is_active' => 1,
            'shipping_address_id' => $this->toBinaryOrNull($customer->getDefaultShippingAddressId()),
            'billing_address_id' => $this->toBinaryOrNull($customer->getDefaultBillingAddressId()),
            'payment_method_id' => $paymentMethodId,
            'shipping_method_id' => $shippingMethodId,
            'promotion_code' => null,
            'promotion_discount' => 0.00,
            'subtotal' => 0.00,
            'total' => 0.00,
            'currency_iso' => $currencyIso,
            'created_at' => $this->now(),
            'updated_at' => null,
        ];

        if ($this->hasIsDefaultColumn()) {
            $insert['is_default'] = 1;
        }

        $this->connection->insert('ictech_multi_cart', $insert);

        return $cartId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCustomerCarts(string $customerId, string $salesChannelId, ?string $languageId = null): array
    {
        $isDefaultSelect = $this->hasIsDefaultColumn()
            ? 'cart.is_default AS isDefault,'
            : '0 AS isDefault,';
        $isDefaultOrderBy = $this->hasIsDefaultColumn()
            ? 'cart.is_default DESC, '
            : '';

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(cart.id)) AS id,
                    LOWER(HEX(cart.customer_id)) AS customerId,
                    LOWER(HEX(cart.sales_channel_id)) AS salesChannelId,
                    cart.name,
                    cart.notes,
                    cart.status,
                    cart.cart_token AS cartToken,
                    ' . $isDefaultSelect . '
                    cart.is_active AS isActive,
                    cart.promotion_code AS promotionCode,
                    cart.promotion_discount AS promotionDiscount,
                    cart.subtotal,
                    cart.total,
                    cart.currency_iso AS currencyIso,
                    cart.created_at AS createdAt,
                    cart.updated_at AS updatedAt,
                    COUNT(item.id) AS itemCount,
                    LOWER(HEX(cart.shipping_address_id)) AS shippingAddressId,
                    LOWER(HEX(cart.billing_address_id)) AS billingAddressId,
                    LOWER(cart.payment_method_id) AS paymentMethodId,
                    LOWER(cart.shipping_method_id) AS shippingMethodId,
                    CONCAT_WS(\' \', NULLIF(TRIM(shipping_address.first_name), \'\'), NULLIF(TRIM(shipping_address.last_name), \'\')) AS shippingRecipient,
                    shipping_address.street AS shippingStreet,
                    CONCAT_WS(\' \', NULLIF(TRIM(billing_address.first_name), \'\'), NULLIF(TRIM(billing_address.last_name), \'\')) AS billingRecipient,
                    billing_address.street AS billingStreet,
                    COALESCE(payment_translation.name, cart.payment_method_id) AS paymentMethodName,
                    COALESCE(shipping_translation.name, cart.shipping_method_id) AS shippingMethodName
             FROM ictech_multi_cart cart
             LEFT JOIN ictech_multi_cart_item item ON item.multi_cart_id = cart.id
             LEFT JOIN customer_address shipping_address ON shipping_address.id = cart.shipping_address_id
             LEFT JOIN customer_address billing_address ON billing_address.id = cart.billing_address_id
             LEFT JOIN payment_method payment_method ON LOWER(HEX(payment_method.id)) = LOWER(cart.payment_method_id)
             LEFT JOIN payment_method_translation payment_translation
                ON payment_translation.payment_method_id = payment_method.id
               AND (:languageId IS NULL OR payment_translation.language_id = UNHEX(:languageId))
             LEFT JOIN shipping_method shipping_method ON LOWER(HEX(shipping_method.id)) = LOWER(cart.shipping_method_id)
             LEFT JOIN shipping_method_translation shipping_translation
                ON shipping_translation.shipping_method_id = shipping_method.id
               AND (:languageId IS NULL OR shipping_translation.language_id = UNHEX(:languageId))
             WHERE cart.customer_id = UNHEX(:customerId)
               AND cart.sales_channel_id = UNHEX(:salesChannelId)
             GROUP BY cart.id
             ORDER BY cart.is_active DESC, ' . $isDefaultOrderBy . 'cart.created_at ASC',
            [
                'customerId' => $customerId,
                'salesChannelId' => $salesChannelId,
                'languageId' => $languageId,
            ]
        );

        $carts = [];
        foreach ($rows as $row) {
            $cartName = $this->toNullableString($row['name'] ?? null) ?? self::DEFAULT_CART_NAME;
            $isDefault = $this->hasIsDefaultColumn()
                ? $this->toBool($row['isDefault'] ?? null)
                : $this->isLegacyDefaultCartName($cartName);

            $carts[] = [
                'id' => $this->toNullableString($row['id'] ?? null),
                'customerId' => $this->toNullableString($row['customerId'] ?? null),
                'salesChannelId' => $this->toNullableString($row['salesChannelId'] ?? null),
                'name' => $cartName,
                'notes' => $this->toNullableString($row['notes'] ?? null),
                'status' => $this->toNullableString($row['status'] ?? null) ?? 'active',
                'cartToken' => $this->toNullableString($row['cartToken'] ?? null),
                'isDefault' => $isDefault,
                'isActive' => $this->toBool($row['isActive'] ?? null),
                'promotionCode' => $this->toNullableString($row['promotionCode'] ?? null),
                'promotionDiscount' => $this->toFloat($row['promotionDiscount'] ?? null),
                'subtotal' => $this->toFloat($row['subtotal'] ?? null),
                'total' => $this->toFloat($row['total'] ?? null),
                'currencyIso' => $this->toNullableString($row['currencyIso'] ?? null) ?? 'EUR',
                'createdAt' => $this->toNullableString($row['createdAt'] ?? null),
                'updatedAt' => $this->toNullableString($row['updatedAt'] ?? null),
                'itemCount' => $this->toInt($row['itemCount'] ?? null),
                'shippingAddressId' => $this->toNullableString($row['shippingAddressId'] ?? null),
                'billingAddressId' => $this->toNullableString($row['billingAddressId'] ?? null),
                'paymentMethodId' => $this->toNullableString($row['paymentMethodId'] ?? null),
                'shippingMethodId' => $this->toNullableString($row['shippingMethodId'] ?? null),
                'shippingAddressLabel' => $this->formatAddressLabel(
                    $this->toNullableString($row['shippingRecipient'] ?? null),
                    $this->toNullableString($row['shippingStreet'] ?? null)
                ),
                'billingAddressLabel' => $this->formatAddressLabel(
                    $this->toNullableString($row['billingRecipient'] ?? null),
                    $this->toNullableString($row['billingStreet'] ?? null)
                ),
                'paymentMethodName' => $this->toNullableString($row['paymentMethodName'] ?? null) ?? 'Not assigned',
                'shippingMethodName' => $this->toNullableString($row['shippingMethodName'] ?? null) ?? 'Not assigned',
            ];
        }

        $itemsByCartId = $this->loadCartItems($carts);

        foreach ($carts as &$cart) {
            $cartId = is_string($cart['id'] ?? null) ? $cart['id'] : null;
            $cart['items'] = $cartId !== null ? ($itemsByCartId[$cartId] ?? []) : [];
        }
        unset($cart);

        return $carts;
    }

    /**
     * @param list<array<string, mixed>> $carts
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function loadCartItems(array $carts): array
    {
        $cartIds = [];

        foreach ($carts as $cart) {
            if (is_string($cart['id'] ?? null) && $cart['id'] !== '') {
                $cartIds[] = $cart['id'];
            }
        }

        if ($cartIds === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(multi_cart_id)) AS cartId,
                    LOWER(HEX(product_id)) AS productId,
                    product_number AS productNumber,
                    product_name AS productName,
                    quantity,
                    unit_price AS unitPrice,
                    total_price AS totalPrice
             FROM ictech_multi_cart_item
             WHERE LOWER(HEX(multi_cart_id)) IN (:cartIds)
             ORDER BY created_at ASC',
            ['cartIds' => $cartIds],
            ['cartIds' => ArrayParameterType::STRING]
        );

        $itemsByCartId = [];

        foreach ($rows as $row) {
            $cartId = $this->toNullableString($row['cartId'] ?? null);

            if ($cartId === null) {
                continue;
            }

            $itemsByCartId[$cartId][] = [
                'productId' => $this->toNullableString($row['productId'] ?? null),
                'productNumber' => $this->toNullableString($row['productNumber'] ?? null),
                'productName' => $this->toNullableString($row['productName'] ?? null) ?? 'Product',
                'quantity' => $this->toInt($row['quantity'] ?? null),
                'unitPrice' => $this->toFloat($row['unitPrice'] ?? null),
                'totalPrice' => $this->toFloat($row['totalPrice'] ?? null),
            ];
        }

        return $itemsByCartId;
    }

    /**
     * @param list<array<string, mixed>> $carts
     */
    private function resolveActiveCartId(string $customerId, string $salesChannelId, array $carts): ?string
    {
        $sessionCartId = $this->getSession()?->get($this->buildSessionKey($salesChannelId));

        if (is_string($sessionCartId) && $this->findCartById($carts, $sessionCartId) !== null) {
            return $sessionCartId;
        }

        foreach ($carts as $cart) {
            if (($cart['isActive'] ?? false) === true && is_string($cart['id'])) {
                $this->storeActiveCartSelection($salesChannelId, $cart['id']);

                return $cart['id'];
            }
        }

        $firstCartId = $this->getFirstCartId($carts);

        if ($firstCartId !== null) {
            $this->activateCartSelection($firstCartId, $salesChannelId, $customerId);
        }

        return $firstCartId;
    }

    private function activateCartSelection(
        string $cartId,
        SalesChannelContext|string $salesChannelContext,
        CustomerEntity|string $customer
    ): void {
        $salesChannelId = $salesChannelContext instanceof SalesChannelContext
            ? $salesChannelContext->getSalesChannelId()
            : $salesChannelContext;
        $customerId = $customer instanceof CustomerEntity ? $customer->getId() : $customer;

        $this->connection->executeStatement(
            'UPDATE ictech_multi_cart
             SET is_active = CASE WHEN id = UNHEX(:cartId) THEN 1 ELSE 0 END,
                 updated_at = :updatedAt
             WHERE customer_id = UNHEX(:customerId)
               AND sales_channel_id = UNHEX(:salesChannelId)',
            [
                'cartId' => $cartId,
                'customerId' => $customerId,
                'salesChannelId' => $salesChannelId,
                'updatedAt' => $this->now(),
            ]
        );

        $this->storeActiveCartSelection($salesChannelId, $cartId);
    }

    private function deactivateAllCarts(string $customerId, string $salesChannelId): void
    {
        $this->connection->executeStatement(
            'UPDATE ictech_multi_cart
             SET is_active = 0,
                 updated_at = :updatedAt
             WHERE customer_id = UNHEX(:customerId)
               AND sales_channel_id = UNHEX(:salesChannelId)',
            [
                'customerId' => $customerId,
                'salesChannelId' => $salesChannelId,
                'updatedAt' => $this->now(),
            ]
        );
    }

    private function isDefaultCart(string $cartId): bool
    {
        if (!$this->hasIsDefaultColumn()) {
            /** @var mixed $legacyName */
            $legacyName = $this->connection->fetchOne(
                'SELECT name
                 FROM ictech_multi_cart
                 WHERE id = UNHEX(:cartId)
                 LIMIT 1',
                ['cartId' => $cartId]
            );

            return $this->isLegacyDefaultCartName($legacyName);
        }

        /** @var mixed $value */
        $value = $this->connection->fetchOne(
            'SELECT is_default
             FROM ictech_multi_cart
             WHERE id = UNHEX(:cartId)
             LIMIT 1',
            ['cartId' => $cartId]
        );

        return $this->toBool($value);
    }

    private function resetDefaultCart(string $cartId): void
    {
        $now = $this->now();

        $this->connection->delete('ictech_multi_cart_item', [
            'multi_cart_id' => $this->toBinary($cartId),
        ]);

        $this->connection->update('ictech_multi_cart', [
            'status' => 'active',
            'promotion_code' => null,
            'promotion_discount' => 0.0,
            'subtotal' => 0.0,
            'total' => 0.0,
            'updated_at' => $now,
        ], [
            'id' => $this->toBinary($cartId),
        ]);
    }

    private function cartExistsForCustomer(string $cartId, string $customerId, string $salesChannelId): bool
    {
        /** @var mixed $value */
        $value = $this->connection->fetchOne(
            'SELECT 1
             FROM ictech_multi_cart
             WHERE id = UNHEX(:cartId)
               AND customer_id = UNHEX(:customerId)
               AND sales_channel_id = UNHEX(:salesChannelId)
             LIMIT 1',
            [
                'cartId' => $cartId,
                'customerId' => $customerId,
                'salesChannelId' => $salesChannelId,
            ]
        );

        return $value !== false;
    }

    private function isBlacklisted(string $customerId, string $salesChannelId): bool
    {
        /** @var mixed $value */
        $value = $this->connection->fetchOne(
            'SELECT 1
             FROM ictech_multi_cart_blacklist
             WHERE customer_id = UNHEX(:customerId)
               AND sales_channel_id = UNHEX(:salesChannelId)
             LIMIT 1',
            [
                'customerId' => $customerId,
                'salesChannelId' => $salesChannelId,
            ]
        );

        return $value !== false;
    }

    private function isCustomerGroupAllowed(CustomerEntity $customer): bool
    {
        return !$customer->getGuest();
    }

    /**
     * @param list<array<string, mixed>> $carts
     *
     * @return array<string, mixed>|null
     */
    private function findCartById(array $carts, ?string $cartId): ?array
    {
        if ($cartId === null) {
            return null;
        }

        foreach ($carts as $cart) {
            if (($cart['id'] ?? null) === $cartId) {
                return $cart;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $carts
     */
    private function getFirstCartId(array $carts): ?string
    {
        if (!isset($carts[0]['id']) || !is_string($carts[0]['id'])) {
            return null;
        }

        return $carts[0]['id'];
    }

    private function buildSessionKey(string $salesChannelId): string
    {
        return 'ictech_multi_cart.active_cart.' . $salesChannelId;
    }

    private function storeActiveCartSelection(string $salesChannelId, string $cartId): void
    {
        $this->getSession()?->set($this->buildSessionKey($salesChannelId), $cartId);
    }

    private function clearActiveCartSelection(string $salesChannelId): void
    {
        $this->getSession()?->remove($this->buildSessionKey($salesChannelId));
    }

    private function getSession(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }

    private function toBinaryOrNull(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->toBinary($value);
    }

    private function toBinary(string $value): string
    {
        $binaryValue = hex2bin($value);

        if ($binaryValue === false) {
            throw new \InvalidArgumentException('Invalid hexadecimal identifier provided.');
        }

        return $binaryValue;
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

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes'], true);
        }

        return false;
    }

    private function normalizeNonEmptyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalizedValue = trim($value);

        return $normalizedValue !== '' ? $normalizedValue : null;
    }

    private function persistPromotionSnapshot(string $cartId, string $promotionCode, float $promotionDiscount): void
    {
        $normalizedPromotionCode = trim($promotionCode);
        $normalizedDiscount = $promotionDiscount > 0 ? $promotionDiscount : 0.0;

        $this->connection->update('ictech_multi_cart', [
            'promotion_code' => $normalizedPromotionCode !== '' ? $normalizedPromotionCode : null,
            'promotion_discount' => $normalizedDiscount,
            'updated_at' => $this->now(),
        ], [
            'id' => $this->toBinary($cartId),
        ]);
    }

    private function isLegacyDefaultCartName(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return trim($value) === self::DEFAULT_CART_NAME;
    }

    private function hasIsDefaultColumn(): bool
    {
        if ($this->hasIsDefaultColumn !== null) {
            return $this->hasIsDefaultColumn;
        }

        /** @var list<string> $columns */
        $columns = $this->connection->fetchFirstColumn(
            'SHOW COLUMNS FROM `ictech_multi_cart` LIKE :columnName',
            ['columnName' => 'is_default']
        );

        $this->hasIsDefaultColumn = $columns !== [];

        return $this->hasIsDefaultColumn;
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

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
    }

    private function formatAddressLabel(?string $recipient, ?string $street): string
    {
        $parts = array_values(array_filter([$recipient, $street], static fn (?string $part): bool => $part !== null && $part !== ''));

        if ($parts === []) {
            return 'Not assigned';
        }

        return implode(' - ', $parts);
    }
}
