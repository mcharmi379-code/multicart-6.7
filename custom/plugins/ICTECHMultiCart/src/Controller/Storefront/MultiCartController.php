<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Controller\Storefront;

use ICTECHMultiCart\Service\MultiCartCheckoutService;
use ICTECHMultiCart\Service\MultiCartStorefrontContextService;
use ICTECHMultiCart\Service\MultiCartStorefrontItemService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
final class MultiCartController extends StorefrontController
{
    public function __construct(
        private readonly MultiCartStorefrontContextService $contextService,
        private readonly MultiCartStorefrontItemService $itemService,
        private readonly MultiCartCheckoutService $checkoutService
    ) {
    }

    #[Route(path: '/multi-cart/state', name: 'frontend.ictech.multi_cart.state', methods: ['GET'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false])]
    public function state(SalesChannelContext $salesChannelContext): JsonResponse
    {
        return new JsonResponse($this->contextService->getState($salesChannelContext));
    }

    #[Route(path: '/multi-cart/create', name: 'frontend.ictech.multi_cart.create', methods: ['POST'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false])]
    public function create(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $cartName = trim((string) $request->request->get('name', ''));

        if ($cartName === '') {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('ictech-multi-cart.selector.cartNameRequired'),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $cartId = $this->contextService->createCart($salesChannelContext, $cartName);

        if ($cartId === null) {
            $failure = $this->contextService->getCreateCartFailureMessage($salesChannelContext);

            return new JsonResponse([
                'success' => false,
                'message' => $this->trans($failure['messageKey']),
                'state' => $this->contextService->getState($salesChannelContext),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success' => true,
            'cartId' => $cartId,
            'state' => $this->contextService->getState($salesChannelContext),
        ]);
    }

    #[Route(path: '/multi-cart/activate', name: 'frontend.ictech.multi_cart.activate', methods: ['POST'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false])]
    public function activate(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $cartId = (string) $request->request->get('cartId', '');

        if ($cartId === '') {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('ictech-multi-cart.selector.selectCartRequired'),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!$this->contextService->activateCart($cartId, $salesChannelContext)) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('ictech-multi-cart.selector.switchCartError'),
                'state' => $this->contextService->getState($salesChannelContext),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success' => true,
            'state' => $this->contextService->getState($salesChannelContext),
        ]);
    }

    #[Route(path: '/multi-cart/promotion', name: 'frontend.ictech.multi_cart.promotion', methods: ['POST'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false])]
    public function updatePromotion(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $cartId = trim((string) $request->request->get('cartId', ''));
        $promotionCode = trim((string) $request->request->get('promotionCode', ''));

        if ($cartId === '') {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('ictech-multi-cart.selector.selectCartRequired'),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $result = $this->contextService->updatePromotionCode($cartId, $promotionCode, $salesChannelContext);

        if (!$result['success']) {
            $messageKey = $result['messageKey'];
            $message = $this->trans('ictech-multi-cart.selector.promoRequired');

            if ($messageKey === 'promotion-not-found') {
                $message = $this->trans('ictech-multi-cart.selector.promoNotFound', ['%code%' => $promotionCode]);
            } elseif (is_string($messageKey) && $messageKey !== '') {
                $message = $this->trans($messageKey, $result['messageParameters']);
            }

            return new JsonResponse([
                'success' => false,
                'message' => $message,
                'state' => $result['state'],
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success' => true,
            'applied' => $result['applied'],
            'message' => $promotionCode === ''
                ? $this->trans('ictech-multi-cart.selector.promoRemoved')
                : (
                    $result['applied']
                        ? $this->trans('ictech-multi-cart.selector.promoApplied')
                        : $this->trans('ictech-multi-cart.selector.promoSaved')
                ),
            'state' => $result['state'],
        ]);
    }

    #[Route(path: '/multi-cart/add-product', name: 'frontend.ictech.multi_cart.add_product', methods: ['POST'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false])]
    public function addProduct(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $customerId = $this->contextService->getManagedCustomerId($salesChannelContext);
        $cartId = (string) $request->request->get('cartId', '');
        $productId = (string) $request->request->get('productId', '');
        $quantity = (int) $request->request->get('quantity', 1);
        $productName = $request->request->get('productName');

        if ($customerId === null) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('ictech-multi-cart.selector.createDisabledUnavailable'),
            ], JsonResponse::HTTP_FORBIDDEN);
        }

        if ($cartId === '' || $productId === '') {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('ictech-multi-cart.selector.selectCartRequired'),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $this->contextService->activateCart($cartId, $salesChannelContext);
            $cartSummary = $this->itemService->addProductToCart(
                $cartId,
                $customerId,
                $salesChannelContext,
                $productId,
                $quantity,
                is_string($productName) ? $productName : null
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'state' => $this->contextService->getState($salesChannelContext),
            ], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\JsonException) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('ictech-multi-cart.selector.addError'),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Throwable) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('ictech-multi-cart.selector.addError'),
                'state' => $this->contextService->getState($salesChannelContext),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'success' => true,
            'message' => $this->trans('ictech-multi-cart.selector.addedSuccess', ['%cart%' => $cartSummary['name'] ?? '']),
            'cart' => $cartSummary,
            'state' => $this->contextService->getState($salesChannelContext),
        ]);
    }

    #[Route(path: '/multi-cart/remove-product', name: 'frontend.ictech.multi_cart.remove_product', methods: ['POST'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false])]
    public function removeProduct(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $customerId = $this->contextService->getManagedCustomerId($salesChannelContext);
        $cartId = trim((string) $request->request->get('cartId', ''));
        $itemId = trim((string) $request->request->get('itemId', ''));

        if ($customerId === null) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('ictech-multi-cart.selector.createDisabledUnavailable'),
            ], JsonResponse::HTTP_FORBIDDEN);
        }

        if ($cartId === '' || $itemId === '') {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('ictech-multi-cart.selector.selectCartRequired'),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $cartSummary = $this->itemService->removeProductFromCart(
                $cartId,
                $itemId,
                $customerId,
                $salesChannelContext
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'state' => $this->contextService->getState($salesChannelContext),
            ], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\Throwable) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('ictech-multi-cart.selector.removeItemError'),
                'state' => $this->contextService->getState($salesChannelContext),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'success' => true,
            'message' => $this->trans('ictech-multi-cart.selector.removeItemSuccess'),
            'cart' => $cartSummary,
            'state' => $this->contextService->getState($salesChannelContext),
        ]);
    }

    #[Route(path: '/multi-cart/checkout', name: 'frontend.ictech.multi_cart.checkout.active', defaults: ['_loginRequired' => true], methods: ['GET'])]
    public function checkoutActiveCart(SalesChannelContext $salesChannelContext): RedirectResponse
    {
        return $this->redirectToPreparedCheckout(null, $salesChannelContext);
    }

    #[Route(path: '/multi-cart/checkout/{cartId}', name: 'frontend.ictech.multi_cart.checkout.cart', defaults: ['_loginRequired' => true], methods: ['GET'])]
    public function checkoutCart(string $cartId, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        return $this->redirectToPreparedCheckout($cartId, $salesChannelContext);
    }

    private function redirectToPreparedCheckout(?string $cartId, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        if (!$this->checkoutService->prepareCheckout($cartId, $salesChannelContext)) {
            $this->addFlash(self::DANGER, $this->trans('ictech-multi-cart.account.checkoutFailed'));

            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        return $this->redirectToRoute('frontend.checkout.confirm.page');
    }
}
