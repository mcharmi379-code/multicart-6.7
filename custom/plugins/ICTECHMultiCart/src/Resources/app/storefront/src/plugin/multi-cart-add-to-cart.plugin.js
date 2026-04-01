import AddToCartPlugin from 'src/plugin/add-to-cart/add-to-cart.plugin';
import PseudoModalUtil from 'src/utility/modal-extension/pseudo-modal.util';
import OffCanvas from 'src/plugin/offcanvas/offcanvas.plugin';

export default class MultiCartAddToCartPlugin extends AddToCartPlugin {
    init() {
        super.init();

        this._ensureStyles();
        this._labels = this._getLabels();
        this._selectorState = null;
        this._selectedCartId = null;
        this._currentPayload = null;
        this._pseudoModal = null;
        this._selectorFeedback = null;
    }

    _registerEvents() {
        this.el.addEventListener('submit', this._formSubmit.bind(this), true);
    }

    async _formSubmit(event) {
        if (!this._isMultiCartEnabled()) {
            return super._formSubmit(event);
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        this._currentPayload = this._extractProductPayload();

        if (!this._currentPayload.productId) {
            return;
        }

        const state = await this._loadState();

        if (!state || !state.enabled) {
            return;
        }

        this._selectorState = state;
        this._selectedCartId = this._resolveSelectedCartId(state);
        this._openSelector();
    }

    _isMultiCartEnabled() {
        return this.el.dataset.multiCartEnabled === 'true';
    }

    _getLabels() {
        try {
            const rawLabels = this.el.getAttribute('data-multi-cart-labels');
            return rawLabels ? JSON.parse(rawLabels) : {};
        } catch (error) {
            return {};
        }
    }

    _extractProductPayload() {
        const formData = new FormData(this._form);
        let productId = '';
        let quantity = 1;

        for (const [key, value] of formData.entries()) {
            if (key.endsWith('[referencedId]') && typeof value === 'string') {
                productId = value;
            }

            if (key.endsWith('[quantity]') && typeof value === 'string') {
                quantity = Number.parseInt(value, 10) || 1;
            }
        }

        const productName = typeof formData.get('product-name') === 'string'
            ? formData.get('product-name')
            : '';

        return {
            productId,
            quantity,
            productName,
        };
    }

    async _loadState() {
        try {
            const response = await fetch(this.el.dataset.multiCartStateUrl, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                return null;
            }

            return await response.json();
        } catch {
            return null;
        }
    }

    _openSelector() {
        const markup = this._renderSelectorMarkup();

        if (this._selectorState.uiStyle === 'drawer') {
            OffCanvas.open(markup, () => this._bindSelectorEvents(), 'right', true, OffCanvas.REMOVE_OFF_CANVAS_DELAY(), false, 'ictech-multi-cart-drawer');
            return;
        }

        this._pseudoModal = new PseudoModalUtil(markup, true);
        this._pseudoModal.open(() => {
            this._applyPopupModalShell();
            this._bindSelectorEvents();
        });
    }

    _renderSelectorMarkup() {
        const state = this._selectorState;
        const selectedCart = this._getSelectedCart();
        const isDrawer = state.uiStyle === 'drawer';
        const createDisabled = state.canCreateCart ? '' : ' disabled';
        const createDisabledMessage = this._getCreateDisabledMessage(state);
        const promotionValue = selectedCart && selectedCart.promotionCode ? selectedCart.promotionCode : '';
        const promotionSummaryMarkup = this._renderPromotionSummary(selectedCart);
        const promotionControlsMarkup = state.promotionsEnabled
            ? `
                <div class="ictech-multi-cart-selector__promo">
                    <input class="ictech-multi-cart-selector__input ictech-multi-cart-selector__input--compact" type="text" value="${this._escapeHtml(promotionValue)}" placeholder="${this._escapeHtml(this._labels.promoPlaceholder || 'Promo code (global discount)')}" data-multi-cart-promo-input>
                    <div class="ictech-multi-cart-selector__promo-actions">
                        <button type="button" class="ictech-multi-cart-selector__promo-button" data-multi-cart-promo>${this._escapeHtml(this._labels.promoApplyLabel || 'Apply')}</button>
                        <button type="button" class="ictech-multi-cart-selector__ghost ictech-multi-cart-selector__ghost--subtle" data-multi-cart-promo-remove>${this._escapeHtml(this._labels.promoRemoveLabel || 'Remove')}</button>
                    </div>
                </div>
                <p class="ictech-multi-cart-selector__helper">${this._escapeHtml(this._labels.promoHelpLabel || 'Promotion code will be applied during the normal Shopware checkout flow.')}</p>
            `
            : '';
        const carts = Array.isArray(state.carts) ? state.carts : [];
        const cartOptions = carts.map((cart) => {
            const selected = cart.id === this._selectedCartId ? ' selected' : '';

            return `<option value="${this._escapeHtml(cart.id)}"${selected}>${this._escapeHtml(cart.name)}</option>`;
        }).join('');
        const cartCards = carts.map((cart) => {
            const activeClass = cart.id === this._selectedCartId ? ' is-active' : '';

            return `
                <button type="button" class="ictech-multi-cart-card${activeClass}" data-multi-cart-select="${cart.id}">
                    <span class="ictech-multi-cart-card__name">${this._escapeHtml(cart.name)}</span>
                    <span class="ictech-multi-cart-card__meta">${Number(cart.itemCount || 0)} ${this._escapeHtml(this._labels.itemsLabel || 'items')}</span>
                    <span class="ictech-multi-cart-card__price">${this._escapeHtml(this._formatPrice(cart.total, cart.currencyIso))}</span>
                    <span class="ictech-multi-cart-card__action">${this._escapeHtml(this._labels.addHereLabel || 'Add here')}</span>
                </button>
            `;
        }).join('');
        const itemsMarkup = this._renderItemsMarkup(selectedCart);
        const footerMarkup = `
            <div class="ictech-multi-cart-selector__footer-actions">
                <button type="button" class="ictech-multi-cart-selector__ghost" data-multi-cart-close>${this._escapeHtml(this._labels.continueShoppingLabel || 'Continue shopping')}</button>
                <button type="button" class="ictech-multi-cart-selector__ghost ictech-multi-cart-selector__ghost--accent" data-multi-cart-go-checkout>${this._escapeHtml(this._labels.checkoutLabel || 'Checkout')}</button>
                <button type="button" class="ictech-multi-cart-selector__ghost" data-multi-cart-go-carts>${this._escapeHtml(this._labels.myCartsLabel || 'My carts')}</button>
            </div>
        `;
        const createMarkup = `
            <section class="ictech-multi-cart-selector__panel ictech-multi-cart-selector__panel--soft">
                <div class="ictech-multi-cart-selector__section-head">
                    <h3>${this._escapeHtml(this._labels.createAnotherCartLabel || this._labels.createCartLabel || 'Create another cart')}</h3>
                </div>
                <label class="ictech-multi-cart-selector__label" for="ictech-multi-cart-name">${this._escapeHtml(this._labels.cartNameLabel || 'Cart name')}</label>
                <div class="ictech-multi-cart-selector__create-row">
                    <input id="ictech-multi-cart-name" class="ictech-multi-cart-selector__input" type="text" maxlength="255" placeholder="${this._escapeHtml(this._labels.cartNamePlaceholder || 'For example: March office order')}"${createDisabled}>
                    <button type="button" class="ictech-multi-cart-selector__secondary" data-multi-cart-create>${this._escapeHtml(this._labels.createAndAddLabel || 'Create and add product')}</button>
                </div>
                ${createDisabledMessage ? `<p class="ictech-multi-cart-selector__helper">${this._escapeHtml(createDisabledMessage)}</p>` : ''}
            </section>
        `;
        const renameMarkup = selectedCart
            ? `
                <section class="ictech-multi-cart-selector__panel ictech-multi-cart-selector__panel--soft">
                    <div class="ictech-multi-cart-selector__section-head">
                        <h3>${this._escapeHtml(this._labels.renameCartLabel || 'Edit cart name')}</h3>
                    </div>
                    <label class="ictech-multi-cart-selector__label" for="ictech-multi-cart-rename">${this._escapeHtml(this._labels.renameLabel || 'Cart name')}</label>
                    <div class="ictech-multi-cart-selector__create-row">
                        <input id="ictech-multi-cart-rename" class="ictech-multi-cart-selector__input" type="text" maxlength="255" value="${this._escapeHtml(selectedCart.name || '')}" data-multi-cart-rename-input>
                        <button type="button" class="ictech-multi-cart-selector__secondary" data-multi-cart-rename-save>${this._escapeHtml(this._labels.renameSaveLabel || 'Save name')}</button>
                    </div>
                </section>
            `
            : '';

        if (isDrawer) {
            return `
                <div class="js-ictech-multi-cart-selector ictech-multi-cart-selector ictech-multi-cart-selector--drawer">
                    <div class="ictech-multi-cart-selector__topbar">
                        <div>
                            <p class="ictech-multi-cart-selector__eyebrow">${this._escapeHtml(this._labels.selectorEyebrow || 'Multi Cart')}</p>
                            <h2 class="ictech-multi-cart-selector__drawer-title">${this._escapeHtml(this._labels.selectorDrawerTitle || 'Your cart')}</h2>
                        </div>
                    </div>
                    <section class="ictech-multi-cart-selector__panel">
                        <label class="ictech-multi-cart-selector__label" for="ictech-multi-cart-select">${this._escapeHtml(this._labels.chooseCartLabel || 'Choose cart')}</label>
                        <select id="ictech-multi-cart-select" class="ictech-multi-cart-selector__select" data-multi-cart-dropdown>
                            ${cartOptions}
                        </select>
                    </section>
                    <section class="ictech-multi-cart-selector__panel">
                        <div class="ictech-multi-cart-selector__section-head">
                            <h3>${this._escapeHtml(this._labels.cartProductsLabel || 'Products in this cart')}</h3>
                            <span>${this._escapeHtml(selectedCart ? selectedCart.name : (this._labels.cartFallbackLabel || 'cart'))}</span>
                        </div>
                        ${itemsMarkup}
                        <div class="ictech-multi-cart-selector__summary">
                            <div class="ictech-multi-cart-selector__summary-row">
                                <span>${this._escapeHtml(this._labels.subtotalLabel || 'Subtotal')}</span>
                                <span>${this._escapeHtml(this._formatPrice(selectedCart ? selectedCart.subtotal : 0, selectedCart ? selectedCart.currencyIso : 'EUR'))}</span>
                            </div>
                            ${promotionSummaryMarkup}
                            <div class="ictech-multi-cart-selector__summary-row">
                                <span>${this._escapeHtml(this._labels.totalLabel || 'Total')}</span>
                                <strong>${this._escapeHtml(this._formatCartTotal(selectedCart))}</strong>
                            </div>
                        </div>
                        ${promotionControlsMarkup}
                    </section>
                    <section class="ictech-multi-cart-selector__panel ictech-multi-cart-selector__panel--accent">
                        <div class="ictech-multi-cart-selector__pending">
                            <span class="ictech-multi-cart-selector__pending-label">${this._escapeHtml(this._labels.pendingProductLabel || 'Ready to add')}</span>
                            <strong>${this._escapeHtml(this._currentPayload.productName || this._labels.productFallbackLabel || 'Product')}</strong>
                        </div>
                        <button type="button" class="ictech-multi-cart-selector__primary" data-multi-cart-add-selected>${this._escapeHtml(this._labels.addToSelectedCartLabel || 'Add to selected cart')}</button>
                        ${footerMarkup}
                    </section>
                    ${renameMarkup}
                    ${createMarkup}
                    <div class="ictech-multi-cart-selector__feedback" data-multi-cart-feedback></div>
                </div>
            `;
        }

        const emptyState = carts.length === 0
            ? `<p class="ictech-multi-cart-empty">${this._escapeHtml(this._labels.noCartsLabel || 'No carts yet. Create your first cart below.')}</p>`
            : `<div class="ictech-multi-cart-cards">${cartCards}</div>`;

        return `
            <div class="js-ictech-multi-cart-selector ictech-multi-cart-selector ictech-multi-cart-selector--popup">
                <div class="ictech-multi-cart-selector__hero">
                    <p class="ictech-multi-cart-selector__eyebrow">${this._escapeHtml(this._labels.selectorEyebrow || 'Multi Cart')}</p>
                    <h2 class="ictech-multi-cart-selector__title">${this._escapeHtml(this._labels.selectorTitle || 'Choose a cart')}</h2>
                    <p class="ictech-multi-cart-selector__subtitle">${this._escapeHtml(this._labels.selectorSubtitle || 'Add this product to one of your carts or create a new one instantly.')}</p>
                </div>
                <div class="ictech-multi-cart-selector__grid">
                    <section class="ictech-multi-cart-selector__panel">
                        <div class="ictech-multi-cart-selector__section-head">
                            <h3>${this._escapeHtml(this._labels.existingCartsLabel || 'Your carts')}</h3>
                            <span>${state.cartCount}</span>
                        </div>
                        ${emptyState}
                    </section>
                    <section class="ictech-multi-cart-selector__panel ictech-multi-cart-selector__panel--accent">
                        <div class="ictech-multi-cart-selector__section-head">
                            <h3>${this._escapeHtml(this._labels.orderSummaryLabel || 'Order summary')}</h3>
                            <span>${this._escapeHtml(selectedCart ? selectedCart.name : (this._labels.cartFallbackLabel || 'cart'))}</span>
                        </div>
                        <div class="ictech-multi-cart-selector__pending">
                            <span class="ictech-multi-cart-selector__pending-label">${this._escapeHtml(this._labels.pendingProductLabel || 'Ready to add')}</span>
                            <strong>${this._escapeHtml(this._currentPayload.productName || this._labels.productFallbackLabel || 'Product')}</strong>
                        </div>
                        <div class="ictech-multi-cart-selector__popup-products">
                            <h4>${this._escapeHtml(this._labels.cartProductsLabel || 'Products in this cart')}</h4>
                            ${itemsMarkup}
                        </div>
                        <div class="ictech-multi-cart-selector__summary">
                            <div class="ictech-multi-cart-selector__summary-row">
                                <span>${this._escapeHtml(this._labels.subtotalLabel || 'Subtotal')}</span>
                                <span>${this._escapeHtml(this._formatPrice(selectedCart ? selectedCart.subtotal : 0, selectedCart ? selectedCart.currencyIso : 'EUR'))}</span>
                            </div>
                            ${promotionSummaryMarkup}
                            <div class="ictech-multi-cart-selector__summary-row">
                                <span>${this._escapeHtml(this._labels.totalLabel || 'Total')}</span>
                                <strong>${this._escapeHtml(this._formatCartTotal(selectedCart))}</strong>
                            </div>
                        </div>
                        ${promotionControlsMarkup}
                        <button type="button" class="ictech-multi-cart-selector__primary" data-multi-cart-add-selected>${this._escapeHtml(this._labels.addToSelectedCartLabel || 'Add to selected cart')}</button>
                        ${footerMarkup}
                    </section>
                </div>
                ${renameMarkup}
                ${createMarkup}
                <div class="ictech-multi-cart-selector__feedback" data-multi-cart-feedback></div>
            </div>
        `;
    }

    _bindSelectorEvents() {
        const container = document.querySelector('.js-ictech-multi-cart-selector');

        if (!container) {
            return;
        }

        container.querySelectorAll('[data-multi-cart-select]').forEach((button) => {
            button.addEventListener('click', async () => {
                await this._switchCart(button.dataset.multiCartSelect);
            });
        });

        const dropdown = container.querySelector('[data-multi-cart-dropdown]');
        if (dropdown) {
            dropdown.addEventListener('change', async (event) => {
                await this._switchCart(event.target.value);
            });
        }

        const addSelectedButton = container.querySelector('[data-multi-cart-add-selected]');
        if (addSelectedButton) {
            addSelectedButton.addEventListener('click', async () => {
                if (!this._selectedCartId) {
                    this._setFeedback(this._labels.selectCartRequiredLabel || 'Please choose a cart first.');
                    return;
                }

                await this._addToSelectedCart(this._selectedCartId);
            });
        }

        const createButton = container.querySelector('[data-multi-cart-create]');
        if (createButton) {
            createButton.addEventListener('click', async () => {
                const nameField = container.querySelector('#ictech-multi-cart-name');
                const cartName = nameField ? nameField.value.trim() : '';
                await this._createCartAndAdd(cartName);
            });
        }

        const promoButton = container.querySelector('[data-multi-cart-promo]');
        if (promoButton) {
            promoButton.addEventListener('click', async () => {
                const promoInput = container.querySelector('[data-multi-cart-promo-input]');
                const promotionCode = promoInput ? promoInput.value.trim() : '';

                if (!this._selectedCartId) {
                    this._setFeedback(this._labels.selectCartRequiredLabel || 'Please choose a cart first.');
                    return;
                }

                if (promotionCode === '') {
                    this._setFeedback(this._labels.promoRequiredLabel || 'Please enter a promotion code.');
                    return;
                }

                await this._updatePromotionCode(this._selectedCartId, promotionCode);
            });
        }

        const promoRemoveButton = container.querySelector('[data-multi-cart-promo-remove]');
        if (promoRemoveButton) {
            promoRemoveButton.addEventListener('click', async () => {
                if (!this._selectedCartId) {
                    this._setFeedback(this._labels.selectCartRequiredLabel || 'Please choose a cart first.');
                    return;
                }

                await this._updatePromotionCode(this._selectedCartId, '');
            });
        }

        const renameButton = container.querySelector('[data-multi-cart-rename-save]');
        if (renameButton) {
            renameButton.addEventListener('click', async (event) => {
                event.preventDefault();
                const renameInput = container.querySelector('[data-multi-cart-rename-input]');
                const nextName = renameInput ? renameInput.value.trim() : '';

                if (!this._selectedCartId) {
                    this._setFeedback(this._labels.selectCartRequiredLabel || 'Please choose a cart first.');
                    return;
                }

                if (!nextName) {
                    this._setFeedback(this._labels.renameNameRequiredLabel || 'Please enter a cart name.');
                    return;
                }

                await this._renameSelectedCart(this._selectedCartId, nextName);
            });
        }

        const closeButton = container.querySelector('[data-multi-cart-close]');
        if (closeButton) {
            closeButton.addEventListener('click', () => this._closeSelector());
        }

        const checkoutButton = container.querySelector('[data-multi-cart-go-checkout]');
        if (checkoutButton) {
            checkoutButton.addEventListener('click', () => {
                window.location.href = this._getCheckoutUrl();
            });
        }

        const cartsButton = container.querySelector('[data-multi-cart-go-carts]');
        if (cartsButton) {
            cartsButton.addEventListener('click', () => {
                window.location.href = this.el.dataset.multiCartCartsUrl || this._getCheckoutUrl();
            });
        }
    }

    async _createCartAndAdd(cartName) {
        if (this._selectorState && !this._selectorState.canCreateCart) {
            this._setFeedback(this._getCreateDisabledMessage(this._selectorState), 'error');
            return;
        }

        if (!cartName) {
            this._setFeedback(this._labels.cartNameRequiredLabel || 'Please enter a cart name.');
            return;
        }

        const response = await fetch(this.el.dataset.multiCartCreateUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new URLSearchParams({ name: cartName }),
        });

        const data = await response.json();

        if (!response.ok || !data.success || !data.cartId) {
            this._setFeedback(data.message || this._labels.createErrorLabel || 'The cart could not be created.');
            return;
        }

        this._selectorState = data.state || this._selectorState;
        this._selectedCartId = this._resolveSelectedCartId(this._selectorState, data.cartId);
        await this._addToSelectedCart(data.cartId);
    }

    async _switchCart(cartId) {
        if (!cartId || cartId === this._selectedCartId) {
            return;
        }

        const previousCartId = this._selectedCartId;
        this._selectedCartId = cartId;

        if (!this.el.dataset.multiCartActivateUrl) {
            this._rerenderSelector();
            return;
        }

        const response = await fetch(this.el.dataset.multiCartActivateUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new URLSearchParams({ cartId }),
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            this._selectedCartId = previousCartId;
            this._setFeedback(data.message || this._labels.switchCartErrorLabel || 'The selected cart could not be loaded.');
            return;
        }

        this._selectorState = data.state || this._selectorState;
        this._selectedCartId = this._resolveSelectedCartId(this._selectorState, cartId);
        this._rerenderSelector();
        this._restoreFeedback();
    }

    async _addToSelectedCart(cartId) {
        const response = await fetch(this.el.dataset.multiCartAddUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new URLSearchParams({
                cartId,
                productId: this._currentPayload.productId,
                quantity: String(this._currentPayload.quantity),
                productName: this._currentPayload.productName || '',
            }),
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            this._setFeedback(data.message || this._labels.addErrorLabel || 'The product could not be added to the cart.');
            return;
        }

        const cartName = data.cart && data.cart.cartName ? data.cart.cartName : this._labels.cartFallbackLabel || 'cart';
        this._setFeedback((this._labels.addedSuccessLabel || 'Added to').replace('%cart%', cartName), 'success');
        this._selectorState = data.state || this._selectorState;
        this._selectedCartId = this._resolveSelectedCartId(this._selectorState, cartId);

        window.setTimeout(() => this._closeSelector(), 900);
    }

    async _updatePromotionCode(cartId, promotionCode) {
        if (!this.el.dataset.multiCartPromotionUrl) {
            return;
        }

        const response = await fetch(this.el.dataset.multiCartPromotionUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new URLSearchParams({
                cartId,
                promotionCode,
            }),
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            this._setFeedback(data.message || this._labels.addErrorLabel || 'The promotion code could not be updated.');
            return;
        }

        this._selectorState = data.state || this._selectorState;
        this._selectedCartId = this._resolveSelectedCartId(this._selectorState, cartId);
        this._setFeedback(
            data.message || (
                promotionCode === ''
                    ? (this._labels.promoRemovedLabel || 'Promotion code removed.')
                    : (data.applied
                        ? (this._labels.promoAppliedLabel || 'Promotion applied.')
                        : (this._labels.promoSavedLabel || 'Promotion code saved.'))
            ),
            'success',
        );
        this._rerenderSelector();
        this._restoreFeedback();
    }

    async _renameSelectedCart(cartId, nextName) {
        const renameUrlTemplate = this.el.dataset.multiCartRenameUrlTemplate;

        if (!renameUrlTemplate) {
            return;
        }

        if (!nextName) {
            this._setFeedback(this._labels.renameNameRequiredLabel || 'Please enter a cart name.');
            return;
        }

        const response = await fetch(renameUrlTemplate.replace('__cartId__', cartId), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new URLSearchParams({
                name: nextName,
            }),
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            this._setFeedback(data.message || this._labels.renameFailedLabel || 'The cart name could not be updated.');
            return;
        }

        this._selectorState = data.state || this._selectorState;
        this._selectedCartId = this._resolveSelectedCartId(this._selectorState, cartId);
        this._setFeedback(data.message || this._labels.renameSuccessLabel || 'The cart name was updated successfully.', 'success');
        this._rerenderSelector();
        this._restoreFeedback();
    }

    _rerenderSelector() {
        const markup = this._renderSelectorMarkup();

        if (this._selectorState && this._selectorState.uiStyle === 'drawer') {
            const offCanvas = OffCanvas.getOffCanvas()[0];

            if (offCanvas) {
                offCanvas.innerHTML = markup;
            }

            this._bindSelectorEvents();

            return;
        }

        if (this._pseudoModal) {
            this._pseudoModal.updateContent(markup, () => {
                this._applyPopupModalShell();
                this._bindSelectorEvents();
            });
        }
    }

    _applyPopupModalShell() {
        const modal = document.querySelector('.js-pseudo-modal .modal');

        if (!modal) {
            return;
        }

        modal.classList.add('ictech-multi-cart-modal');

        const modalDialog = modal.querySelector('.modal-dialog');
        if (modalDialog) {
            modalDialog.classList.add('ictech-multi-cart-modal__dialog');
        }

        const modalContent = modal.querySelector('.modal-content');
        if (modalContent) {
            modalContent.classList.add('ictech-multi-cart-modal__content');
        }

        const modalBody = modal.querySelector('.modal-body');
        if (modalBody) {
            modalBody.classList.add('ictech-multi-cart-modal__body');
        }
    }

    _setFeedback(message, state = 'error') {
        this._selectorFeedback = { message, state };
        const feedback = document.querySelector('[data-multi-cart-feedback]');

        if (!feedback) {
            return;
        }

        feedback.textContent = message;
        feedback.className = `ictech-multi-cart-selector__feedback is-${state}`;
    }

    _restoreFeedback() {
        if (!this._selectorFeedback || !this._selectorFeedback.message) {
            return;
        }

        this._setFeedback(this._selectorFeedback.message, this._selectorFeedback.state);
    }

    _closeSelector() {
        if (this._selectorState && this._selectorState.uiStyle === 'drawer') {
            OffCanvas.close();
            return;
        }

        if (this._pseudoModal) {
            this._pseudoModal.close();
        }
    }

    _resolveSelectedCartId(state, preferredCartId = null) {
        const carts = Array.isArray(state && state.carts) ? state.carts : [];

        if (preferredCartId && carts.some((cart) => cart.id === preferredCartId)) {
            return preferredCartId;
        }

        if (state && state.activeCartId && carts.some((cart) => cart.id === state.activeCartId)) {
            return state.activeCartId;
        }

        return carts[0] ? carts[0].id : null;
    }

    _getSelectedCart() {
        if (!this._selectorState || !Array.isArray(this._selectorState.carts)) {
            return null;
        }

        return this._selectorState.carts.find((cart) => cart.id === this._selectedCartId) || null;
    }

    _renderItemsMarkup(cart) {
        const items = Array.isArray(cart && cart.items) ? cart.items : [];

        if (items.length === 0) {
            return `<p class="ictech-multi-cart-empty">${this._escapeHtml(this._labels.cartProductsEmptyLabel || 'No products in this cart yet.')}</p>`;
        }

        return `
            <div class="ictech-multi-cart-selector__items">
                ${items.map((item) => `
                    <div class="ictech-multi-cart-selector__item">
                        <div>
                            <strong>${this._escapeHtml(item.productName || this._labels.productFallbackLabel || 'Product')}</strong>
                            <span>${this._escapeHtml(`${item.productNumber || ''} x${Number(item.quantity || 0)}`.trim())}</span>
                        </div>
                        <span>${this._escapeHtml(this._formatPrice(item.totalPrice, cart.currencyIso))}</span>
                    </div>
                `).join('')}
            </div>
        `;
    }

    _renderPromotionSummary(cart) {
        if (!cart) {
            return '';
        }

        const promotionCode = cart.promotionCode ? String(cart.promotionCode).trim() : '';
        const promotionDiscount = Number(cart.promotionDiscount || 0);

        if (!promotionCode && promotionDiscount <= 0) {
            return '';
        }

        const currencyIso = cart.currencyIso || 'EUR';
        const discountRow = promotionDiscount > 0
            ? `
                <div class="ictech-multi-cart-selector__summary-row ictech-multi-cart-selector__summary-row--discount">
                    <span>${this._escapeHtml(this._labels.discountLabel || 'Discount')}</span>
                    <strong>-${this._escapeHtml(this._formatPrice(promotionDiscount, currencyIso))}</strong>
                </div>
            `
            : '';

        const statusLabel = promotionDiscount > 0
            ? (this._labels.promoAppliedLabel || 'Promotion applied')
            : (this._labels.promoSavedLabel || 'Promotion code saved.');

        return `
            <div class="ictech-multi-cart-selector__promo-status">
                <div class="ictech-multi-cart-selector__promo-status-head">
                    <span>${this._escapeHtml(this._labels.promoCodeLabel || 'Promo code')}</span>
                    <strong>${this._escapeHtml(promotionCode || '-')}</strong>
                </div>
                <p>${this._escapeHtml(statusLabel)}</p>
            </div>
            ${discountRow}
        `;
    }

    _formatCartTotal(cart) {
        if (!cart) {
            return this._formatPrice(0, 'EUR');
        }

        return this._formatPrice(cart.total, cart.currencyIso);
    }

    _formatPrice(value, currencyIso = 'EUR') {
        return `${Number(value || 0).toFixed(2)} ${currencyIso || 'EUR'}`;
    }

    _getCheckoutUrl() {
        const template = this.el.dataset.multiCartCheckoutUrlTemplate;

        if (template && this._selectedCartId) {
            return template.replace('__cartId__', this._selectedCartId);
        }

        return this.el.dataset.multiCartCartsUrl || '/checkout/cart';
    }

    _getCreateDisabledMessage(state) {
        if (!state || state.canCreateCart) {
            return '';
        }

        if (state.createCartReason === 'limit_reached') {
            return (this._labels.createDisabledLimitLabel || 'You have reached the maximum number of carts.')
                .replace('%limit%', String(state.maxCartsPerUser || 0));
        }

        return this._labels.createDisabledUnavailableLabel || 'Cart creation is currently unavailable.';
    }

    _ensureStyles() {
        if (document.getElementById('ictech-multi-cart-inline-styles')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'ictech-multi-cart-inline-styles';
        style.textContent = this._getInlineStyles();
        document.head.appendChild(style);
    }

    _getInlineStyles() {
        return `
            .ictech-multi-cart-selector{--ictech-surface:#fff;--ictech-surface-soft:#f8fbff;--ictech-surface-highlight:#eef5ff;--ictech-border:#d8e1ec;--ictech-border-strong:#0a56c6;--ictech-ink:#15253a;--ictech-muted:#5e6c80;--ictech-brand:#0a56c6;--ictech-brand-dark:#083c8d;--ictech-shadow:0 24px 60px rgba(14,33,68,.12);color:var(--ictech-ink)}
            .ictech-multi-cart-modal .modal-dialog.ictech-multi-cart-modal__dialog{max-width:min(1080px,calc(100vw - 1.5rem));margin:1rem auto}
            .ictech-multi-cart-modal .modal-content.ictech-multi-cart-modal__content{border:0;border-radius:1.5rem;overflow:hidden;background:#fff;box-shadow:0 28px 70px rgba(14,33,68,.18)}
            .ictech-multi-cart-modal .modal-header{padding:1rem 1rem 0;border-bottom:0}
            .ictech-multi-cart-modal .modal-body.ictech-multi-cart-modal__body{padding:0 1rem 1rem}
            .ictech-multi-cart-selector--popup{min-width:min(940px,calc(100vw - 3rem))}
            .ictech-multi-cart-selector__hero{margin-bottom:1.25rem;padding:1.5rem;border-radius:1.5rem;background:radial-gradient(circle at top right,rgba(10,86,198,.16),transparent 34%),linear-gradient(135deg,#f6f9ff 0,#fff 52%,#eef4ff 100%)}
            .ictech-multi-cart-selector__eyebrow{margin:0 0 .45rem;font-size:.76rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--ictech-brand)}
            .ictech-multi-cart-selector__title,.ictech-multi-cart-selector__drawer-title{margin:0;font-size:clamp(1.45rem,2.5vw,2rem);font-weight:800;line-height:1.1}
            .ictech-multi-cart-selector__subtitle{margin:.75rem 0 0;max-width:42rem;color:var(--ictech-muted)}
            .ictech-multi-cart-selector__topbar{display:flex;justify-content:space-between;margin-bottom:1rem}
            .ictech-multi-cart-selector__grid{display:grid;gap:1rem;grid-template-columns:minmax(0,.95fr) minmax(0,1.05fr)}
            .ictech-multi-cart-selector__panel{padding:1.25rem;border:1px solid var(--ictech-border);border-radius:1.35rem;background:var(--ictech-surface);box-shadow:var(--ictech-shadow)}
            .ictech-multi-cart-selector__panel--accent{background:linear-gradient(180deg,#fff 0,#f7faff 100%)}
            .ictech-multi-cart-selector__panel--soft{margin-top:1rem;background:linear-gradient(180deg,#fff 0,#f9fbff 100%)}
            .ictech-multi-cart-selector__section-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:1rem}
            .ictech-multi-cart-selector__section-head h3,.ictech-multi-cart-selector__popup-products h4{margin:0;font-size:1rem;font-weight:800}
            .ictech-multi-cart-selector__section-head span,.ictech-multi-cart-selector__popup-products{color:var(--ictech-muted)}
            .ictech-multi-cart-cards,.ictech-multi-cart-selector__items{display:grid;gap:.75rem}
            .ictech-multi-cart-card{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.25rem 1rem;width:100%;padding:1rem 1.05rem;border:1px solid var(--ictech-border);border-radius:1rem;background:var(--ictech-surface-soft);text-align:left;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease}
            .ictech-multi-cart-card:hover,.ictech-multi-cart-card:focus{transform:translateY(-1px);border-color:var(--ictech-brand);box-shadow:0 16px 34px rgba(10,86,198,.12)}
            .ictech-multi-cart-card.is-active{border-color:var(--ictech-border-strong);background:var(--ictech-surface-highlight)}
            .ictech-multi-cart-card__name,.ictech-multi-cart-selector__item strong,.ictech-multi-cart-selector__pending strong{font-weight:800;color:var(--ictech-ink)}
            .ictech-multi-cart-card__meta,.ictech-multi-cart-card__action,.ictech-multi-cart-empty,.ictech-multi-cart-selector__item span,.ictech-multi-cart-selector__pending-label{color:var(--ictech-muted);font-size:.92rem}
            .ictech-multi-cart-card__price{grid-row:1/span 2;grid-column:2;align-self:center;font-weight:800;color:var(--ictech-ink)}
            .ictech-multi-cart-card__action{color:var(--ictech-brand);font-weight:700}
            .ictech-multi-cart-selector__label{display:block;margin-bottom:.45rem;font-weight:700;color:var(--ictech-ink)}
            .ictech-multi-cart-selector__input,.ictech-multi-cart-selector__select{width:100%;min-height:46px;padding:.8rem .95rem;border:1px solid var(--ictech-border);border-radius:.95rem;background:#fff;color:var(--ictech-ink)}
            .ictech-multi-cart-selector__input:focus,.ictech-multi-cart-selector__select:focus{border-color:var(--ictech-brand);outline:0;box-shadow:0 0 0 3px rgba(10,86,198,.12)}
            .ictech-multi-cart-selector__input--compact{min-height:40px}
            .ictech-multi-cart-selector__promo,.ictech-multi-cart-selector__create-row{display:grid;gap:.75rem;grid-template-columns:minmax(0,1fr) auto;align-items:center}
            .ictech-multi-cart-selector__promo-actions{display:grid;gap:.55rem;grid-template-columns:repeat(2,minmax(0,1fr));align-items:center}
            .ictech-multi-cart-selector__primary,.ictech-multi-cart-selector__secondary,.ictech-multi-cart-selector__promo-button,.ictech-multi-cart-selector__ghost{min-height:42px;border-radius:999px;font-weight:800;transition:transform .2s ease,box-shadow .2s ease,opacity .2s ease}
            .ictech-multi-cart-selector__primary,.ictech-multi-cart-selector__secondary,.ictech-multi-cart-selector__promo-button{border:0}
            .ictech-multi-cart-selector__primary{width:100%;margin-top:1rem;padding:.9rem 1rem;background:linear-gradient(135deg,var(--ictech-brand) 0,var(--ictech-brand-dark) 100%);color:#fff;box-shadow:0 14px 26px rgba(10,86,198,.2)}
            .ictech-multi-cart-selector__secondary{padding:.8rem 1.05rem;background:#edf4ff;color:var(--ictech-brand-dark)}
            .ictech-multi-cart-selector__promo-button{padding:.75rem 1rem;background:#eef3fb;color:var(--ictech-brand-dark)}
            .ictech-multi-cart-selector__ghost{padding:.75rem 1rem;border:1px solid var(--ictech-border);background:#fff;color:var(--ictech-brand-dark)}
            .ictech-multi-cart-selector__ghost--accent{border-color:rgba(10,86,198,.18);background:#edf4ff}
            .ictech-multi-cart-selector__ghost--subtle{background:#f8fbff}
            .ictech-multi-cart-selector__primary:hover,.ictech-multi-cart-selector__secondary:hover,.ictech-multi-cart-selector__promo-button:hover,.ictech-multi-cart-selector__ghost:hover{transform:translateY(-1px)}
            .ictech-multi-cart-selector__primary:disabled,.ictech-multi-cart-selector__secondary:disabled,.ictech-multi-cart-selector__promo-button:disabled{cursor:not-allowed;opacity:.55}
            .ictech-multi-cart-selector__pending{display:grid;gap:.15rem;padding:.95rem 1rem;border-radius:1rem;background:rgba(255,255,255,.8);border:1px solid rgba(10,86,198,.12)}
            .ictech-multi-cart-selector__item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.75rem;align-items:center;padding-bottom:.65rem;border-bottom:1px solid #e7edf5}
            .ictech-multi-cart-selector__item:last-child{padding-bottom:0;border-bottom:0}
            .ictech-multi-cart-selector__summary{margin-top:1rem;padding-top:1rem;border-top:1px solid #e7edf5}
            .ictech-multi-cart-selector__summary-row{display:flex;align-items:center;justify-content:space-between;gap:1rem;font-size:1rem}
            .ictech-multi-cart-selector__summary-row strong{font-size:1.08rem}
            .ictech-multi-cart-selector__summary-row--discount strong{color:#1c7c48}
            .ictech-multi-cart-selector__promo-status{margin:.85rem 0;padding:.85rem 1rem;border:1px solid rgba(28,124,72,.18);border-radius:1rem;background:rgba(28,124,72,.08)}
            .ictech-multi-cart-selector__promo-status-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;font-size:.95rem}
            .ictech-multi-cart-selector__promo-status-head strong{font-size:1rem;color:#1c7c48}
            .ictech-multi-cart-selector__promo-status p{margin:.35rem 0 0;color:#1c7c48;font-size:.9rem;font-weight:700}
            .ictech-multi-cart-selector__footer-actions{display:grid;gap:.6rem;grid-template-columns:repeat(3,minmax(0,1fr));margin-top:1rem}
            .ictech-multi-cart-selector__feedback{min-height:1.5rem;margin-top:.85rem;font-size:.95rem;font-weight:700}
            .ictech-multi-cart-selector__feedback.is-success{color:#1c7c48}
            .ictech-multi-cart-selector__feedback.is-error{color:#c0392b}
            .ictech-multi-cart-selector__helper{margin:.75rem 0 0;color:var(--ictech-muted);font-size:.9rem;line-height:1.5}
            .ictech-multi-cart-drawer{width:min(100vw,420px);max-width:420px;padding:1rem;background:radial-gradient(circle at top left,rgba(10,86,198,.12),transparent 26%),linear-gradient(180deg,#f6f9ff 0,#fff 100%)}
            .ictech-multi-cart-selector--drawer .ictech-multi-cart-selector__panel{padding:1rem;box-shadow:0 14px 34px rgba(14,33,68,.08)}
            .ictech-multi-cart-selector--drawer .ictech-multi-cart-selector__ghost{padding-inline:.5rem;font-size:.82rem}
            @media (max-width:991.98px){.ictech-multi-cart-modal .modal-dialog.ictech-multi-cart-modal__dialog{max-width:calc(100vw - 1rem);margin:.5rem auto}.ictech-multi-cart-selector--popup{min-width:min(100vw - 2rem,760px)}.ictech-multi-cart-selector__grid{grid-template-columns:1fr}}
            @media (max-width:767.98px){.ictech-multi-cart-modal .modal-header{padding:.75rem .75rem 0}.ictech-multi-cart-modal .modal-body.ictech-multi-cart-modal__body{padding:0 .75rem .75rem}.ictech-multi-cart-selector--popup{min-width:100%}.ictech-multi-cart-selector__hero,.ictech-multi-cart-selector__panel{padding:1rem}.ictech-multi-cart-selector__promo,.ictech-multi-cart-selector__create-row,.ictech-multi-cart-selector__footer-actions,.ictech-multi-cart-selector__promo-actions{grid-template-columns:1fr}.ictech-multi-cart-card{grid-template-columns:1fr}.ictech-multi-cart-card__price{grid-row:auto;grid-column:auto}.ictech-multi-cart-drawer{width:100vw;max-width:100vw;padding:.75rem}}
        `;
    }

    _escapeHtml(value) {
        const element = document.createElement('div');
        element.textContent = value;
        return element.innerHTML;
    }
}
