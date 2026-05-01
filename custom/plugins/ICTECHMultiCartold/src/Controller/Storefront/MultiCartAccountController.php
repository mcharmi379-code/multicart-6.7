<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Controller\Storefront;

use ICTECHMultiCart\Service\MultiCartCheckoutService;
use ICTECHMultiCart\Service\MultiCartStorefrontContextService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
final class MultiCartAccountController extends StorefrontController
{
    public function __construct(
        private readonly MultiCartStorefrontContextService $contextService,
        private readonly MultiCartCheckoutService $checkoutService
    ) {
    }

    #[Route(path: '/account/my-carts', name: 'frontend.ictech.multi_cart.account.page', defaults: ['_loginRequired' => true, '_noStore' => true], methods: ['GET'])]
    public function index(Request $request, SalesChannelContext $salesChannelContext, CustomerEntity $customer): Response
    {
        $carts = $this->contextService->getAccountCarts($salesChannelContext);
        $totalItems = 0;

        foreach ($carts as $cart) {
            $itemCount = $cart['itemCount'] ?? 0;

            if (is_int($itemCount)) {
                $totalItems += $itemCount;
            }
        }

        return $this->renderStorefront('@ICTECHMultiCart/storefront/page/account/my-carts/index.html.twig', [
            'page' => [
                'customer' => $customer,
                'carts' => $carts,
                'state' => $this->contextService->getState($salesChannelContext),
                'options' => $this->contextService->getAccountOptions($salesChannelContext),
                'totalItems' => $totalItems,
            ],
        ]);
    }

    #[Route(path: '/account/my-carts/create', name: 'frontend.ictech.multi_cart.account.create', defaults: ['_loginRequired' => true], methods: ['POST'])]
    public function create(Request $request, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        $name = trim((string) $request->request->get('name', ''));

        if ($name === '') {
            $this->addFlash(self::DANGER, $this->trans('ictech-multi-cart.account.createNameRequired'));

            return $this->redirectToRoute('frontend.ictech.multi_cart.account.page');
        }

        $cartId = $this->contextService->createCart($salesChannelContext, $name);

        if ($cartId === null) {
            $failure = $this->contextService->getCreateCartFailureMessage($salesChannelContext);
            $this->addFlash(self::DANGER, $this->trans($failure['messageKey']));

            return $this->redirectToRoute('frontend.ictech.multi_cart.account.page');
        }

        $this->addFlash(self::SUCCESS, $this->trans('ictech-multi-cart.account.createSuccess'));

        return $this->redirectToRoute('frontend.ictech.multi_cart.account.page');
    }

    #[Route(path: '/account/my-carts/{cartId}/view', name: 'frontend.ictech.multi_cart.account.view', defaults: ['_loginRequired' => true], methods: ['POST'])]
    public function viewCart(string $cartId, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        if (!$this->contextService->activateCart($cartId, $salesChannelContext)) {
            $this->addFlash(self::DANGER, $this->trans('ictech-multi-cart.account.activateFailed'));

            return $this->redirectToRoute('frontend.ictech.multi_cart.account.page');
        }

        return $this->redirectToRoute('frontend.checkout.cart.page');
    }

    #[Route(path: '/account/my-carts/{cartId}/checkout', name: 'frontend.ictech.multi_cart.account.checkout', defaults: ['_loginRequired' => true], methods: ['POST'])]
    public function checkoutCart(string $cartId, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        if (!$this->checkoutService->prepareCheckout($cartId, $salesChannelContext)) {
            $this->addFlash(self::DANGER, $this->trans('ictech-multi-cart.account.checkoutFailed'));

            return $this->redirectToRoute('frontend.ictech.multi_cart.account.page');
        }

        return $this->redirectToRoute('frontend.checkout.confirm.page');
    }

    #[Route(path: '/account/my-carts/{cartId}/duplicate', name: 'frontend.ictech.multi_cart.account.duplicate', defaults: ['_loginRequired' => true], methods: ['POST'])]
    public function duplicateCart(string $cartId, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        $duplicatedCartId = $this->contextService->duplicateCart($cartId, $salesChannelContext);

        if ($duplicatedCartId === null) {
            $this->addFlash(self::DANGER, $this->trans('ictech-multi-cart.account.duplicateFailed'));

            return $this->redirectToRoute('frontend.ictech.multi_cart.account.page');
        }

        $this->addFlash(self::SUCCESS, $this->trans('ictech-multi-cart.account.duplicateSuccess'));

        return $this->redirectToRoute('frontend.ictech.multi_cart.account.page');
    }

    #[Route(path: '/account/my-carts/{cartId}/delete', name: 'frontend.ictech.multi_cart.account.delete', defaults: ['_loginRequired' => true], methods: ['POST'])]
    public function deleteCart(string $cartId, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        if (!$this->contextService->deleteCart($cartId, $salesChannelContext)) {
            $this->addFlash(self::DANGER, $this->trans('ictech-multi-cart.account.deleteFailed'));

            return $this->redirectToRoute('frontend.ictech.multi_cart.account.page');
        }

        $this->addFlash(self::SUCCESS, $this->trans('ictech-multi-cart.account.deleteSuccess'));

        return $this->redirectToRoute('frontend.ictech.multi_cart.account.page');
    }

    #[Route(path: '/account/my-carts/{cartId}/preferences', name: 'frontend.ictech.multi_cart.account.preferences', defaults: ['_loginRequired' => true, 'XmlHttpRequest' => true], methods: ['POST'])]
    public function updatePreferences(string $cartId, Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        /** @var array{
         *     shippingAddressId?: string|null,
         *     billingAddressId?: string|null,
         *     paymentMethodId?: string|null,
         *     shippingMethodId?: string|null
         * } $payload
         */
        $payload = [
            'shippingAddressId' => $this->getNullableStringRequestValue($request, 'shippingAddressId'),
            'billingAddressId' => $this->getNullableStringRequestValue($request, 'billingAddressId'),
            'paymentMethodId' => $this->getNullableStringRequestValue($request, 'paymentMethodId'),
            'shippingMethodId' => $this->getNullableStringRequestValue($request, 'shippingMethodId'),
        ];

        if (!$this->contextService->updateCartPreferences($cartId, $payload, $salesChannelContext)) {
            return new JsonResponse([
                'success' => false,
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success' => true,
            'cart' => $this->contextService->getCartSummary($cartId, $salesChannelContext),
        ]);
    }

    #[Route(path: '/account/my-carts/{cartId}/promotion', name: 'frontend.ictech.multi_cart.account.promotion', defaults: ['_loginRequired' => true, 'XmlHttpRequest' => true], methods: ['POST'])]
    public function updatePromotion(string $cartId, Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $promotionCode = trim((string) $request->request->get('promotionCode', ''));
        $result = $this->contextService->updatePromotionCode($cartId, $promotionCode, $salesChannelContext);

        if (!$result['success']) {
            $messageKey = $result['messageKey'];

            return new JsonResponse([
                'success' => false,
                'message' => is_string($messageKey) && $messageKey !== ''
                    ? $this->trans($messageKey, $result['messageParameters'])
                    : $this->trans('ictech-multi-cart.account.promoApplyFailed'),
                'cart' => $this->contextService->getCartSummary($cartId, $salesChannelContext),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success' => true,
            'message' => $promotionCode === ''
                ? $this->trans('ictech-multi-cart.account.promoRemoved')
                : (
                    $result['applied']
                        ? $this->trans('ictech-multi-cart.account.promoApplied')
                        : $this->trans('ictech-multi-cart.account.promoSaved')
                ),
            'cart' => $this->contextService->getCartSummary($cartId, $salesChannelContext),
        ]);
    }

    #[Route(path: '/account/my-carts/{cartId}/name', name: 'frontend.ictech.multi_cart.account.rename', defaults: ['_loginRequired' => true, 'XmlHttpRequest' => true], methods: ['POST'])]
    public function renameCart(string $cartId, Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $name = trim((string) $request->request->get('name', ''));

        if ($name === '') {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('ictech-multi-cart.account.renameNameRequired'),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!$this->contextService->updateCartName($cartId, $name, $salesChannelContext)) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('ictech-multi-cart.account.renameFailed'),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success' => true,
            'name' => $name,
            'state' => $this->contextService->getState($salesChannelContext),
            'message' => $this->trans('ictech-multi-cart.account.renameSuccess'),
        ]);
    }

    #[Route(path: '/account/my-carts/address', name: 'frontend.ictech.multi_cart.account.address.create', defaults: ['_loginRequired' => true, 'XmlHttpRequest' => true], methods: ['POST'])]
    public function createAddress(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        /** @var array{
         *     firstName?: string|null,
         *     lastName?: string|null,
         *     street?: string|null,
         *     zipcode?: string|null,
         *     city?: string|null,
         *     countryId?: string|null
         * } $payload
         */
        $payload = [
            'firstName' => $this->getNullableStringRequestValue($request, 'firstName'),
            'lastName' => $this->getNullableStringRequestValue($request, 'lastName'),
            'street' => $this->getNullableStringRequestValue($request, 'street'),
            'zipcode' => $this->getNullableStringRequestValue($request, 'zipcode'),
            'city' => $this->getNullableStringRequestValue($request, 'city'),
            'countryId' => $this->getNullableStringRequestValue($request, 'countryId'),
        ];

        $result = $this->contextService->createCustomerAddress($payload, $salesChannelContext);

        if (!$result['success']) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('ictech-multi-cart.account.addressCreateFailed'),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success' => true,
            'message' => $this->trans('ictech-multi-cart.account.addressCreateSuccess'),
            'addressId' => $result['addressId'] ?? null,
            'options' => $result['options'] ?? null,
        ]);
    }

    #[Route(path: '/account/my-carts/combined-checkout', name: 'frontend.ictech.multi_cart.account.combined_checkout', defaults: ['_loginRequired' => true], methods: ['POST'])]
    public function combinedCheckout(Request $request, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        $cartIds = $request->request->all('cartIds');
        $validCartIds = $this->extractCartIds($cartIds);

        if ($validCartIds === []) {
            $this->addFlash(self::DANGER, $this->trans('ictech-multi-cart.account.combined.noSelection'));

            return $this->redirectToRoute('frontend.ictech.multi_cart.account.page');
        }

        /** @var array{
         *     shippingAddressId?: string|null,
         *     billingAddressId?: string|null,
         *     paymentMethodId?: string|null,
         *     shippingMethodId?: string|null
         * } $preferencePayload
         */
        $preferencePayload = [
            'shippingAddressId' => $this->getNullableStringRequestValue($request, 'shippingAddressId'),
            'billingAddressId' => $this->getNullableStringRequestValue($request, 'billingAddressId'),
            'paymentMethodId' => $this->getNullableStringRequestValue($request, 'paymentMethodId'),
            'shippingMethodId' => $this->getNullableStringRequestValue($request, 'shippingMethodId'),
        ];

        $conflictAnalysis = $this->checkoutService->analyzeCombinedCheckoutConflicts($validCartIds, $salesChannelContext);

        if ($this->mustBlockCombinedCheckout($conflictAnalysis, $request)) {
            $this->addFlash(
                self::WARNING,
                $this->trans($this->getCombinedCheckoutConflictMessageKey($conflictAnalysis['strategy']))
            );

            return $this->redirectToRoute('frontend.ictech.multi_cart.account.page');
        }

        $this->updateSelectedCartPreferences($validCartIds, $preferencePayload, $salesChannelContext);

        if (!$this->checkoutService->prepareCombinedCheckout($validCartIds, $salesChannelContext, $preferencePayload)) {
            $this->addFlash(self::DANGER, $this->trans('ictech-multi-cart.account.checkoutFailed'));

            return $this->redirectToRoute('frontend.ictech.multi_cart.account.page');
        }

        $this->addFlash(self::INFO, $this->trans('ictech-multi-cart.account.combined.notice'));

        return $this->redirectToRoute('frontend.checkout.confirm.page');
    }

    private function getNullableStringRequestValue(Request $request, string $key): ?string
    {
        $value = $request->request->get($key);

        return is_string($value) ? $value : null;
    }

    /**
     * @param array{
     *     strategy: string,
     *     hasConflicts: bool,
     *     conflictingFields: list<string>,
     *     cartNames: list<string>
     * } $conflictAnalysis
     */
    private function mustBlockCombinedCheckout(array $conflictAnalysis, Request $request): bool
    {
        if (!$conflictAnalysis['hasConflicts']) {
            return false;
        }

        if ($conflictAnalysis['strategy'] === MultiCartCheckoutService::CONFLICT_RESOLUTION_REQUIRE_SAME) {
            return true;
        }

        if ($conflictAnalysis['strategy'] !== MultiCartCheckoutService::CONFLICT_RESOLUTION_SHOW_WARNING) {
            return false;
        }

        return !$this->isConflictAcknowledged($request);
    }

    private function getCombinedCheckoutConflictMessageKey(string $strategy): string
    {
        return match ($strategy) {
            MultiCartCheckoutService::CONFLICT_RESOLUTION_REQUIRE_SAME => 'ictech-multi-cart.account.combined.requireSameConflict',
            MultiCartCheckoutService::CONFLICT_RESOLUTION_SHOW_WARNING => 'ictech-multi-cart.account.combined.warningConflict',
            default => 'ictech-multi-cart.account.checkoutFailed',
        };
    }

    private function isConflictAcknowledged(Request $request): bool
    {
        $value = $request->request->get('conflictAcknowledged');

        return $value === '1' || $value === 1 || $value === true || $value === 'true';
    }

    /**
     * @param mixed $cartIds
     *
     * @return list<string>
     */
    private function extractCartIds($cartIds): array
    {
        if (!is_array($cartIds)) {
            return [];
        }

        return array_values(array_filter(
            $cartIds,
            static fn ($cartId): bool => is_string($cartId) && $cartId !== ''
        ));
    }

    /**
     * @param list<string> $cartIds
     * @param array{
     *     shippingAddressId?: string|null,
     *     billingAddressId?: string|null,
     *     paymentMethodId?: string|null,
     *     shippingMethodId?: string|null
     * } $preferencePayload
     */
    private function updateSelectedCartPreferences(
        array $cartIds,
        array $preferencePayload,
        SalesChannelContext $salesChannelContext
    ): void {
        foreach ($cartIds as $cartId) {
            $this->contextService->updateCartPreferences($cartId, $preferencePayload, $salesChannelContext);
        }
    }
}
