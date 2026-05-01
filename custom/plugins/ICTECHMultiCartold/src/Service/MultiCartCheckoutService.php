<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Service;

use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Promotion\Cart\PromotionItemBuilder;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannel\ContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class MultiCartCheckoutService
{
    private const ORDER_TRACKING_SESSION_KEY = 'ictech_multi_cart.prepared_checkout';
    public const CONFLICT_RESOLUTION_ALLOW_OVERRIDE = 'allow_override';
    public const CONFLICT_RESOLUTION_REQUIRE_SAME = 'require_same';
    public const CONFLICT_RESOLUTION_SHOW_WARNING = 'show_warning';

    /**
     * @var list<string>
     */
    private const CONTEXT_PREFERENCE_FIELDS = [
        'shippingAddressId',
        'billingAddressId',
        'paymentMethodId',
        'shippingMethodId',
    ];

    private const PRODUCT_LINE_ITEM_PAYLOAD = [
        'type' => 'product',
        'stackable' => true,
        'removable' => true,
    ];

    public function __construct(
        private readonly MultiCartStorefrontContextService $contextService,
        private readonly CartService $cartService,
        private readonly LineItemFactoryRegistry $lineItemFactoryRegistry,
        private readonly ContextSwitchRoute $contextSwitchRoute,
        private readonly PromotionItemBuilder $promotionItemBuilder,
        private readonly RequestStack $requestStack
    ) {
    }

    public function prepareCheckout(?string $cartId, SalesChannelContext $salesChannelContext): bool
    {
        $state = $this->contextService->getState($salesChannelContext);
        $targetCartId = $cartId !== null && $cartId !== '' ? $cartId : $state['activeCartId'];

        if (!is_string($targetCartId) || $targetCartId === '') {
            $this->clearPreparedCheckout();

            return false;
        }

        if (!$this->contextService->activateCart($targetCartId, $salesChannelContext)) {
            $this->clearPreparedCheckout();

            return false;
        }

        $selectedCart = $this->contextService->getCartSummary($targetCartId, $salesChannelContext);

        if ($selectedCart === null) {
            $this->clearPreparedCheckout();

            return false;
        }

        $normalizedCart = $this->normalizePreparedCart($selectedCart);

        if ($normalizedCart === null) {
            $this->clearPreparedCheckout();

            return false;
        }

        /** @var list<array{
         *     id?: mixed,
         *     name?: mixed,
         *     items: list<array{productId?: mixed, quantity?: mixed}>,
         *     promotionCode?: mixed,
         *     shippingAddressId?: mixed,
         *     billingAddressId?: mixed,
         *     paymentMethodId?: mixed,
         *     shippingMethodId?: mixed
         * }> $selectedCarts
         */
        $selectedCarts = [$normalizedCart];

        return $this->prepareSelectedCarts($selectedCarts, $salesChannelContext);
    }

    /**
     * @param list<string> $cartIds
     * @param array{
     *     shippingAddressId?: string|null,
     *     billingAddressId?: string|null,
     *     paymentMethodId?: string|null,
     *     shippingMethodId?: string|null
     * } $preferenceOverride
     */
    public function prepareCombinedCheckout(array $cartIds, SalesChannelContext $salesChannelContext, array $preferenceOverride = []): bool
    {
        $state = $this->contextService->getState($salesChannelContext);

        if (
            !$state['enabled']
            || !$state['customerLoggedIn']
            || !$state['customerAllowed']
            || $state['blacklisted']
            || !$state['checkoutPrefsEnabled']
            || !$state['multiPaymentEnabled']
        ) {
            $this->clearPreparedCheckout();

            return false;
        }

        $cartIds = array_values(array_filter($cartIds, static fn (string $cartId): bool => $cartId !== ''));

        if ($cartIds === []) {
            $this->clearPreparedCheckout();

            return false;
        }

        $selectedCarts = $this->loadSelectedCartsFromIds($cartIds, $salesChannelContext);

        if ($selectedCarts === []) {
            $this->clearPreparedCheckout();

            return false;
        }

        return $this->prepareSelectedCarts($selectedCarts, $salesChannelContext, $preferenceOverride);
    }

    /**
     * @param list<string> $cartIds
     *
     * @return array{
     *     strategy: string,
     *     hasConflicts: bool,
     *     conflictingFields: list<string>,
     *     cartNames: list<string>
     * }
     */
    public function analyzeCombinedCheckoutConflicts(array $cartIds, SalesChannelContext $salesChannelContext): array
    {
        $state = $this->contextService->getState($salesChannelContext);
        $selectedCarts = $this->loadSelectedCartsFromIds($cartIds, $salesChannelContext);

        if ($selectedCarts === []) {
            return [
                'strategy' => $this->normalizeConflictResolution((string) ($state['conflictResolution'] ?? '')),
                'hasConflicts' => false,
                'conflictingFields' => [],
                'cartNames' => [],
            ];
        }

        $conflictingFields = [];

        foreach (self::CONTEXT_PREFERENCE_FIELDS as $field) {
            if ($this->hasCartPreferenceConflict($selectedCarts, $field)) {
                $conflictingFields[] = $field;
            }
        }

        return [
            'strategy' => $this->normalizeConflictResolution((string) ($state['conflictResolution'] ?? '')),
            'hasConflicts' => $conflictingFields !== [],
            'conflictingFields' => $conflictingFields,
            'cartNames' => $this->collectPreparedCheckoutField($selectedCarts, 'name'),
        ];
    }

    /**
     * @param list<array{
     *     id?: mixed,
     *     name?: mixed,
     *     items: list<array{productId?: mixed, quantity?: mixed}>,
     *     promotionCode?: mixed,
     *     shippingAddressId?: mixed,
     *     billingAddressId?: mixed,
     *     paymentMethodId?: mixed,
     *     shippingMethodId?: mixed
     * }> $selectedCarts
     * @param array{
     *     shippingAddressId?: string|null,
     *     billingAddressId?: string|null,
     *     paymentMethodId?: string|null,
     *     shippingMethodId?: string|null
     * } $preferenceOverride
     */
    private function prepareSelectedCarts(array $selectedCarts, SalesChannelContext $salesChannelContext, array $preferenceOverride = []): bool
    {
        $state = $this->contextService->getState($salesChannelContext);

        if ($selectedCarts === []) {
            $this->clearPreparedCheckout();

            return false;
        }

        $contextPayload = $this->buildContextPayload($selectedCarts, $preferenceOverride, (bool) $state['checkoutPrefsEnabled']);

        if ($contextPayload !== []) {
            $this->contextSwitchRoute->switchContext(new RequestDataBag($contextPayload), $salesChannelContext);
        }

        $this->cartService->deleteCart($salesChannelContext);
        $cart = $this->cartService->createNew($salesChannelContext->getToken());
        $cart = $this->addCartItems($cart, $selectedCarts, $salesChannelContext);
        $cart = $this->addPromotionItems($cart, $selectedCarts, $salesChannelContext, (bool) $state['promotionsEnabled']);

        $this->cartService->recalculate($cart, $salesChannelContext);
        $this->storePreparedCheckout($selectedCarts, $salesChannelContext);

        return true;
    }

    /**
     * @param list<string> $cartIds
     *
     * @return list<array{
     *     id?: mixed,
     *     name?: mixed,
     *     items: list<array{productId?: mixed, quantity?: mixed}>,
     *     promotionCode?: mixed,
     *     shippingAddressId?: mixed,
     *     billingAddressId?: mixed,
     *     paymentMethodId?: mixed,
     *     shippingMethodId?: mixed
     * }>
     */
    private function loadSelectedCartsFromIds(array $cartIds, SalesChannelContext $salesChannelContext): array
    {
        $selectedCarts = [];
        $state = $this->contextService->getState($salesChannelContext);

        foreach ($cartIds as $cartId) {
            $cart = $this->findCart($state['carts'], $cartId);

            if ($cart === null) {
                continue;
            }

            $normalizedCart = $this->normalizePreparedCart($cart);

            if ($normalizedCart === null) {
                continue;
            }

            $selectedCarts[] = $normalizedCart;
        }

        return $selectedCarts;
    }

    /**
     * @return array{
     *     salesChannelId: string,
     *     customerId: string,
     *     cartIds: list<string>,
     *     cartNames: list<string>,
     *     promotionCodes: list<string>
     * }|null
     */
    public function consumePreparedCheckout(string $salesChannelId, string $customerId): ?array
    {
        $session = $this->getSession();

        if (!$session instanceof SessionInterface) {
            return null;
        }

        $payload = $session->get(self::ORDER_TRACKING_SESSION_KEY);
        $session->remove(self::ORDER_TRACKING_SESSION_KEY);

        if (!is_array($payload)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        if (!$this->matchesPreparedCheckoutIdentity($payload, $salesChannelId, $customerId)) {
            return null;
        }

        return [
            'salesChannelId' => $salesChannelId,
            'customerId' => $customerId,
            'cartIds' => $this->filterStringList($payload['cartIds'] ?? null),
            'cartNames' => $this->filterStringList($payload['cartNames'] ?? null),
            'promotionCodes' => $this->filterStringList($payload['promotionCodes'] ?? null),
        ];
    }

    /**
     * @param list<array<string, mixed>> $carts
     *
     * @return array<string, mixed>|null
     */
    private function findCart(array $carts, string $cartId): ?array
    {
        foreach ($carts as $cart) {
            if (($cart['id'] ?? null) === $cartId) {
                return $cart;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $cart
     *
     * @return array{
     *     id?: mixed,
     *     name?: mixed,
     *     items: list<array{productId?: mixed, quantity?: mixed}>,
     *     promotionCode?: mixed,
     *     shippingAddressId?: mixed,
     *     billingAddressId?: mixed,
     *     paymentMethodId?: mixed,
     *     shippingMethodId?: mixed
     * }|null
     */
    private function normalizePreparedCart(array $cart): ?array
    {
        $items = $cart['items'] ?? null;

        if (!is_array($items) || $items === []) {
            return null;
        }

        $normalizedItems = [];

        foreach ($items as $item) {
            $normalizedItem = $this->normalizePreparedItem($item);

            if ($normalizedItem !== null) {
                $normalizedItems[] = $normalizedItem;
            }
        }

        if ($normalizedItems === []) {
            return null;
        }

        return [
            'id' => $cart['id'] ?? null,
            'name' => $cart['name'] ?? null,
            'items' => $normalizedItems,
            'promotionCode' => $cart['promotionCode'] ?? null,
            'shippingAddressId' => $cart['shippingAddressId'] ?? null,
            'billingAddressId' => $cart['billingAddressId'] ?? null,
            'paymentMethodId' => $cart['paymentMethodId'] ?? null,
            'shippingMethodId' => $cart['shippingMethodId'] ?? null,
        ];
    }

    private function getContextField(string $cartField): ?string
    {
        return match ($cartField) {
            'shippingAddressId' => SalesChannelContextService::SHIPPING_ADDRESS_ID,
            'billingAddressId' => SalesChannelContextService::BILLING_ADDRESS_ID,
            'paymentMethodId' => SalesChannelContextService::PAYMENT_METHOD_ID,
            'shippingMethodId' => SalesChannelContextService::SHIPPING_METHOD_ID,
            default => null,
        };
    }

    /**
     * @param list<array<string, mixed>> $selectedCarts
     */
    private function storePreparedCheckout(array $selectedCarts, SalesChannelContext $salesChannelContext): void
    {
        $session = $this->getSession();
        $customer = $salesChannelContext->getCustomer();

        if (!$session instanceof SessionInterface || $customer === null) {
            return;
        }

        $session->set(self::ORDER_TRACKING_SESSION_KEY, [
            'salesChannelId' => $salesChannelContext->getSalesChannelId(),
            'customerId' => $customer->getId(),
            'cartIds' => $this->collectPreparedCheckoutField($selectedCarts, 'id'),
            'cartNames' => $this->collectPreparedCheckoutField($selectedCarts, 'name'),
            'promotionCodes' => $this->collectPreparedCheckoutField($selectedCarts, 'promotionCode'),
        ]);
    }

    private function clearPreparedCheckout(): void
    {
        $this->getSession()?->remove(self::ORDER_TRACKING_SESSION_KEY);
    }

    private function getSession(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }

    /**
     * @param list<array{
     *     id?: mixed,
     *     name?: mixed,
     *     items: list<array{productId?: mixed, quantity?: mixed}>,
     *     promotionCode?: mixed,
     *     shippingAddressId?: mixed,
     *     billingAddressId?: mixed,
     *     paymentMethodId?: mixed,
     *     shippingMethodId?: mixed
     * }> $selectedCarts
     */
    private function hasCartPreferenceConflict(array $selectedCarts, string $field): bool
    {
        $values = [];

        foreach ($selectedCarts as $selectedCart) {
            $value = $selectedCart[$field] ?? null;
            $values[$this->normalizeConflictValue($value)] = true;
        }

        return count($values) > 1;
    }

    private function normalizeConflictValue(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '__empty__';
        }

        return $value;
    }

    private function normalizeConflictResolution(string $strategy): string
    {
        return match ($strategy) {
            self::CONFLICT_RESOLUTION_REQUIRE_SAME,
            self::CONFLICT_RESOLUTION_SHOW_WARNING => $strategy,
            default => self::CONFLICT_RESOLUTION_ALLOW_OVERRIDE,
        };
    }

    /**
     * @param list<array{
     *     id?: mixed,
     *     name?: mixed,
     *     items: list<array{productId?: mixed, quantity?: mixed}>,
     *     promotionCode?: mixed,
     *     shippingAddressId?: mixed,
     *     billingAddressId?: mixed,
     *     paymentMethodId?: mixed,
     *     shippingMethodId?: mixed
     * }> $selectedCarts
     * @param array{
     *     shippingAddressId?: string|null,
     *     billingAddressId?: string|null,
     *     paymentMethodId?: string|null,
     *     shippingMethodId?: string|null
     * } $preferenceOverride
     *
     * @return array<string, string>
     */
    private function buildContextPayload(array $selectedCarts, array $preferenceOverride, bool $checkoutPrefsEnabled): array
    {
        if (!$checkoutPrefsEnabled) {
            return [];
        }

        $contextPayload = [];

        foreach (self::CONTEXT_PREFERENCE_FIELDS as $cartField) {
            $contextField = $this->getContextField($cartField);
            $value = $preferenceOverride[$cartField] ?? ($selectedCarts[0][$cartField] ?? null);

            if ($contextField !== null && is_string($value) && $value !== '') {
                $contextPayload[$contextField] = $value;
            }
        }

        return $contextPayload;
    }

    /**
     * @param list<array{
     *     id?: mixed,
     *     name?: mixed,
     *     items: list<array{productId?: mixed, quantity?: mixed}>,
     *     promotionCode?: mixed,
     *     shippingAddressId?: mixed,
     *     billingAddressId?: mixed,
     *     paymentMethodId?: mixed,
     *     shippingMethodId?: mixed
     * }> $selectedCarts
     */
    private function addCartItems(
        \Shopware\Core\Checkout\Cart\Cart $cart,
        array $selectedCarts,
        SalesChannelContext $salesChannelContext
    ): \Shopware\Core\Checkout\Cart\Cart {
        foreach ($selectedCarts as $selectedCart) {
            $items = $selectedCart['items'] ?? null;

            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $lineItem = $this->buildProductLineItem($item, $salesChannelContext);

                if ($lineItem !== null) {
                    $cart = $this->cartService->add($cart, $lineItem, $salesChannelContext);
                }
            }
        }

        return $cart;
    }

    /**
     * @param list<array{
     *     id?: mixed,
     *     name?: mixed,
     *     items: list<array{productId?: mixed, quantity?: mixed}>,
     *     promotionCode?: mixed,
     *     shippingAddressId?: mixed,
     *     billingAddressId?: mixed,
     *     paymentMethodId?: mixed,
     *     shippingMethodId?: mixed
     * }> $selectedCarts
     */
    private function addPromotionItems(
        \Shopware\Core\Checkout\Cart\Cart $cart,
        array $selectedCarts,
        SalesChannelContext $salesChannelContext,
        bool $promotionsEnabled
    ): \Shopware\Core\Checkout\Cart\Cart {
        if (!$promotionsEnabled) {
            return $cart;
        }

        foreach ($this->collectPreparedCheckoutField($selectedCarts, 'promotionCode') as $promotionCode) {
            $promotionItem = $this->promotionItemBuilder->buildPlaceholderItem($promotionCode);
            $cart = $this->cartService->add($cart, $promotionItem, $salesChannelContext);
        }

        return $cart;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function matchesPreparedCheckoutIdentity(array $payload, string $salesChannelId, string $customerId): bool
    {
        return ($payload['salesChannelId'] ?? null) === $salesChannelId
            && ($payload['customerId'] ?? null) === $customerId;
    }

    /**
     * @param mixed $values
     *
     * @return list<string>
     */
    private function filterStringList($values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            $values,
            static fn ($value): bool => is_string($value) && $value !== ''
        ));
    }

    /**
     * @param mixed $item
     *
     * @return array{productId?: mixed, quantity?: mixed}|null
     */
    private function normalizePreparedItem($item): ?array
    {
        if (!is_array($item)) {
            return null;
        }

        return [
            'productId' => $item['productId'] ?? null,
            'quantity' => $item['quantity'] ?? null,
        ];
    }

    /**
     * @param mixed $item
     */
    private function buildProductLineItem(
        $item,
        SalesChannelContext $salesChannelContext
    ): ?\Shopware\Core\Checkout\Cart\LineItem\LineItem {
        if (!is_array($item)) {
            return null;
        }

        $productId = $item['productId'] ?? null;
        $quantity = $item['quantity'] ?? null;

        if (!is_string($productId) || $productId === '' || !is_int($quantity) || $quantity <= 0) {
            return null;
        }

        return $this->lineItemFactoryRegistry->create(
            [
                'id' => $productId,
                'referencedId' => $productId,
                'quantity' => $quantity,
            ] + self::PRODUCT_LINE_ITEM_PAYLOAD,
            $salesChannelContext
        );
    }

    /**
     * @param list<array<string, mixed>> $selectedCarts
     *
     * @return list<string>
     */
    private function collectPreparedCheckoutField(array $selectedCarts, string $field): array
    {
        $values = [];

        foreach ($selectedCarts as $selectedCart) {
            $value = $selectedCart[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }
}
