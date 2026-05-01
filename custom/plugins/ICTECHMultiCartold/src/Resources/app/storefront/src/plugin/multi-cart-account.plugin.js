import Plugin from 'src/plugin-system/plugin.class';

export default class MultiCartAccountPlugin extends Plugin {
    init() {
        if (this.el.dataset.ictechBound === 'true') {
            return;
        }

        this.el.dataset.ictechBound = 'true';

        const root = this.el;
        const createModal = root.querySelector('[data-ictech-create-modal]');
        const addressModal = root.querySelector('[data-ictech-address-modal]');
        const combinedModal = root.querySelector('[data-ictech-combined-modal]');
        const combinedBar = root.querySelector('[data-ictech-combined-bar]');
        const countNode = root.querySelector('[data-ictech-combined-count]');
        const totalNode = root.querySelector('[data-ictech-combined-total]');
        const cartIdContainer = root.querySelector('[data-ictech-combined-cart-ids]');
        const descriptionNode = root.querySelector('[data-ictech-combined-description]');
        const conflictAcknowledgedInput = root.querySelector('[data-ictech-conflict-acknowledged]');
        const combinedForm = combinedModal || root.querySelector('[data-ictech-combined-form]');
        const renameUrlTemplate = root.dataset.ictechRenameUrlTemplate || '';
        const promotionUrlTemplate = root.dataset.ictechPromotionUrlTemplate || '';
        const addressUrl = root.dataset.ictechAddressUrl || '';
        const conflictResolution = root.dataset.ictechConflictResolution || 'allow_override';
        const combinedFields = ['shippingAddressId', 'billingAddressId', 'paymentMethodId', 'shippingMethodId'];
        const renameNameRequiredLabel = root.dataset.ictechRenameNameRequiredLabel || '';
        const renameFailedLabel = root.dataset.ictechRenameFailedLabel || '';
        const addressCreateFailedLabel = root.dataset.ictechAddressCreateFailedLabel || '';
        const combinedOverrideLabel = root.dataset.ictechCombinedOverrideLabel || '';
        const combinedWarningLabel = root.dataset.ictechCombinedWarningLabel || '';
        const combinedRequireSameLabel = root.dataset.ictechCombinedRequireSameLabel || '';

        const formatAmount = (value) => Number(value || 0).toFixed(2);
        const formatCurrency = (value, currencyIso) => {
            const currency = currencyIso || 'EUR';

            try {
                return new Intl.NumberFormat(document.documentElement.lang || undefined, {
                    style: 'currency',
                    currency,
                }).format(Number(value || 0));
            } catch {
                return `${formatAmount(value)} ${currency}`;
            }
        };

        const setPromotionFeedback = (card, message, isError = false) => {
            const feedback = card.querySelector('[data-ictech-promotion-feedback]');

            if (!feedback) {
                return;
            }

            feedback.textContent = message || '';
            feedback.hidden = !message;
            feedback.classList.toggle('is-error', isError);
            feedback.classList.toggle('is-success', Boolean(message) && !isError);
        };

        const getSelectedCards = () => [...root.querySelectorAll('[data-ictech-combined-cart]:checked')]
            .map((checkbox) => checkbox.closest('.ictech-account-carts__card'))
            .filter(Boolean);

        const populateCombinedCartIds = (selectedCards) => {
            if (!cartIdContainer) {
                return;
            }

            cartIdContainer.innerHTML = '';
            selectedCards.forEach((card) => {
                const checkbox = card.querySelector('[data-ictech-combined-cart]');

                if (!checkbox) {
                    return;
                }

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'cartIds[]';
                input.value = checkbox.value;
                cartIdContainer.appendChild(input);
            });
        };

        const setConflictAcknowledgement = (isAcknowledged) => {
            if (conflictAcknowledgedInput) {
                conflictAcknowledgedInput.value = isAcknowledged ? '1' : '0';
            }
        };

        const getFieldValues = (selectedCards, fieldName) => selectedCards
            .map((card) => {
                const input = card.querySelector(`[data-ictech-preference="${fieldName}"]`);

                return input ? input.value : '';
            })
            .filter(Boolean);

        const hasPreferenceMismatch = (selectedCards) => combinedFields.some((fieldName) => {
            const uniqueValues = [...new Set(getFieldValues(selectedCards, fieldName))];

            return uniqueValues.length > 1;
        });

        const openModal = (modal) => {
            if (modal) {
                modal.hidden = false;
            }
        };

        const closeModal = (modal) => {
            if (modal) {
                modal.hidden = true;
            }
        };

        const setRenameFeedback = (card, message, isError = false) => {
            const feedback = card.querySelector('[data-ictech-rename-feedback]');

            if (!feedback) {
                return;
            }

            feedback.textContent = message || '';
            feedback.hidden = !message;
            feedback.classList.toggle('is-error', isError);
            feedback.classList.toggle('is-success', Boolean(message) && !isError);
        };

        const updateCartNameReferences = (card, nextName) => {
            const title = card.querySelector('[data-ictech-cart-title]');
            const renameInput = card.querySelector('[data-ictech-rename-input]');
            const combinedCheckbox = card.querySelector('[data-ictech-combined-cart]');

            if (title) {
                title.textContent = nextName;
            }

            if (renameInput) {
                renameInput.value = nextName;
                renameInput.defaultValue = nextName;
            }

            if (combinedCheckbox) {
                combinedCheckbox.setAttribute('data-ictech-cart-name', nextName);
            }

            if (!card.classList.contains('is-active')) {
                return;
            }

            const activeCartName = root.querySelector('[data-ictech-active-cart-name]');

            if (activeCartName) {
                activeCartName.textContent = nextName;
            }
        };

        const syncBillingWithShipping = (card, force = false) => {
            const shipping = card.querySelector('[data-ictech-preference="shippingAddressId"]');
            const billing = card.querySelector('[data-ictech-preference="billingAddressId"]');
            const sameAsShipping = card.querySelector('[data-ictech-same-as-shipping]');

            if (!shipping || !billing || !sameAsShipping || !sameAsShipping.checked) {
                if (billing) {
                    billing.disabled = false;
                }

                return;
            }

            billing.value = shipping.value;
            billing.disabled = true;

            if (force) {
                billing.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };

        const syncCombinedBar = () => {
            const selected = [...root.querySelectorAll('[data-ictech-combined-cart]:checked')];
            const total = selected.reduce((carry, checkbox) => carry + Number(checkbox.getAttribute('data-ictech-cart-total') || 0), 0);

            if (combinedBar) {
                combinedBar.hidden = selected.length === 0;
            }

            if (countNode) {
                countNode.textContent = String(selected.length);
            }

            if (totalNode) {
                totalNode.textContent = formatAmount(total);
            }
        };

        const updateCardSummary = (card, cart) => {
            if (!card || !cart) {
                return;
            }

            const subtotalDisplay = card.querySelector('[data-ictech-cart-subtotal]');
            const discountRow = card.querySelector('[data-ictech-cart-discount-row]');
            const discountDisplay = card.querySelector('[data-ictech-cart-discount]');
            const totalDisplay = card.querySelector('[data-ictech-cart-total-display]');
            const combinedCheckbox = card.querySelector('[data-ictech-combined-cart]');
            const promotionInput = card.querySelector('[data-ictech-promotion-input]');
            const currencyIso = cart.currencyIso || 'EUR';

            if (subtotalDisplay) {
                subtotalDisplay.textContent = formatCurrency(cart.subtotal || 0, currencyIso);
            }

            if (totalDisplay) {
                totalDisplay.textContent = formatCurrency(cart.total || 0, currencyIso);
            }

            if (combinedCheckbox) {
                combinedCheckbox.setAttribute('data-ictech-cart-total', String(cart.total || 0));
            }

            if (discountRow && discountDisplay) {
                const discount = Number(cart.promotionDiscount || 0);
                discountRow.hidden = discount <= 0;
                discountDisplay.textContent = discount > 0 ? `-${formatCurrency(discount, currencyIso)}` : '';
            }

            if (promotionInput && typeof cart.promotionCode !== 'undefined') {
                promotionInput.value = cart.promotionCode || '';
            }

            syncCombinedBar();
        };

        const updateAddressOptions = (options) => {
            if (!options || !Array.isArray(options.addresses)) {
                return;
            }

            root.querySelectorAll('[data-ictech-preference="shippingAddressId"], [data-ictech-preference="billingAddressId"]').forEach((select) => {
                const currentValue = select.value;
                const placeholder = select.querySelector('option[value=""]');

                select.innerHTML = '';

                if (placeholder) {
                    select.appendChild(placeholder.cloneNode(true));
                }

                options.addresses.forEach((address) => {
                    const option = document.createElement('option');
                    option.value = address.id;
                    option.textContent = address.label;
                    select.appendChild(option);
                });

                if ([...select.options].some((option) => option.value === currentValue)) {
                    select.value = currentValue;
                }
            });
        };

        root.querySelectorAll('[data-ictech-create-cart-toggle]').forEach((button) => {
            button.addEventListener('click', () => openModal(createModal));
        });

        root.querySelectorAll('[data-ictech-modal-close]').forEach((button) => {
            button.addEventListener('click', () => {
                closeModal(createModal);
                closeModal(addressModal);
                closeModal(combinedModal);
            });
        });

        root.querySelectorAll('.ictech-account-carts__card').forEach((card) => {
            const toggle = card.querySelector('[data-ictech-rename-toggle]');
            const form = card.querySelector('[data-ictech-rename-form]');
            const input = card.querySelector('[data-ictech-rename-input]');
            const cancel = card.querySelector('[data-ictech-rename-cancel]');
            const save = card.querySelector('[data-ictech-rename-save]');
            const cartId = card.getAttribute('data-cart-id') || '';

            if (!toggle || !form || !input || !cancel || !save || !cartId || !renameUrlTemplate) {
                return;
            }

            const openRename = () => {
                form.hidden = false;
                setRenameFeedback(card, '');
                input.value = input.defaultValue;
                input.focus();
                input.select();
            };

            const closeRename = () => {
                form.hidden = true;
                setRenameFeedback(card, '');
                input.value = input.defaultValue;
            };

            toggle.addEventListener('click', openRename);
            cancel.addEventListener('click', closeRename);

            form.addEventListener('submit', async (event) => {
                event.preventDefault();

                const nextName = input.value.trim();

                if (!nextName) {
                    setRenameFeedback(card, renameNameRequiredLabel, true);
                    return;
                }

                save.disabled = true;
                cancel.disabled = true;

                try {
                    const formData = new FormData();
                    formData.append('name', nextName);

                    const response = await fetch(renameUrlTemplate.replace('__cartId__', cartId), {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok || payload.success !== true) {
                        setRenameFeedback(card, payload.message || renameFailedLabel, true);
                        return;
                    }

                    updateCartNameReferences(card, payload.name || nextName);
                    setRenameFeedback(card, payload.message || '', false);
                    window.setTimeout(closeRename, 700);
                } catch {
                    setRenameFeedback(card, renameFailedLabel, true);
                } finally {
                    save.disabled = false;
                    cancel.disabled = false;
                }
            });
        });

        root.querySelectorAll('.ictech-account-carts__card').forEach((card) => {
            const shipping = card.querySelector('[data-ictech-preference="shippingAddressId"]');
            const sameAsShipping = card.querySelector('[data-ictech-same-as-shipping]');

            if (sameAsShipping) {
                sameAsShipping.addEventListener('change', () => {
                    const billing = card.querySelector('[data-ictech-preference="billingAddressId"]');

                    if (!sameAsShipping.checked && billing) {
                        billing.disabled = false;
                        return;
                    }

                    syncBillingWithShipping(card, true);
                });
            }

            if (shipping) {
                shipping.addEventListener('change', () => syncBillingWithShipping(card, true));
            }

            syncBillingWithShipping(card);
        });

        root.querySelectorAll('[data-ictech-preference]').forEach((field) => {
            field.addEventListener('change', async () => {
                const cartId = field.getAttribute('data-cart-id');
                const card = field.closest('.ictech-account-carts__card');

                if (!cartId || !card) {
                    return;
                }

                const formData = new FormData();

                card.querySelectorAll('[data-ictech-preference]').forEach((input) => {
                    formData.append(input.getAttribute('data-ictech-preference'), input.value);
                });

                try {
                    const response = await fetch(root.dataset.ictechPreferencesUrlTemplate.replace('__cartId__', cartId), {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (response.ok && payload.success === true && payload.cart) {
                        updateCardSummary(card, payload.cart);
                    }
                } catch {
                    return;
                }
            });
        });

        root.querySelectorAll('[data-ictech-promotion-apply]').forEach((button) => {
            button.addEventListener('click', async () => {
                const cartId = button.getAttribute('data-cart-id');
                const card = button.closest('.ictech-account-carts__card');
                const input = card ? card.querySelector('[data-ictech-promotion-input]') : null;

                if (!cartId || !card || !input || !promotionUrlTemplate) {
                    return;
                }

                setPromotionFeedback(card, '');
                button.disabled = true;
                input.disabled = true;

                try {
                    const formData = new FormData();
                    formData.append('promotionCode', input.value.trim());

                    const response = await fetch(promotionUrlTemplate.replace('__cartId__', cartId), {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok || payload.success !== true) {
                        if (payload.cart) {
                            updateCardSummary(card, payload.cart);
                        }

                        setPromotionFeedback(card, payload.message || root.dataset.ictechPromoFailedLabel || '', true);
                        return;
                    }

                    if (payload.cart) {
                        updateCardSummary(card, payload.cart);
                    }

                    setPromotionFeedback(card, payload.message || root.dataset.ictechPromoAppliedLabel || '', false);
                } catch {
                    setPromotionFeedback(card, root.dataset.ictechPromoFailedLabel || '', true);
                } finally {
                    button.disabled = false;
                    input.disabled = false;
                }
            });
        });

        root.querySelectorAll('[data-ictech-promotion-remove]').forEach((button) => {
            button.addEventListener('click', async () => {
                const cartId = button.getAttribute('data-cart-id');
                const card = button.closest('.ictech-account-carts__card');
                const input = card ? card.querySelector('[data-ictech-promotion-input]') : null;
                const applyButton = card ? card.querySelector('[data-ictech-promotion-apply]') : null;

                if (!cartId || !card || !input || !promotionUrlTemplate) {
                    return;
                }

                setPromotionFeedback(card, '');
                button.disabled = true;
                input.disabled = true;

                if (applyButton) {
                    applyButton.disabled = true;
                }

                try {
                    const formData = new FormData();
                    formData.append('promotionCode', '');

                    const response = await fetch(promotionUrlTemplate.replace('__cartId__', cartId), {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok || payload.success !== true) {
                        if (payload.cart) {
                            updateCardSummary(card, payload.cart);
                        }

                        setPromotionFeedback(card, payload.message || root.dataset.ictechPromoFailedLabel || '', true);
                        return;
                    }

                    if (payload.cart) {
                        updateCardSummary(card, payload.cart);
                    } else {
                        input.value = '';
                    }

                    setPromotionFeedback(card, payload.message || root.dataset.ictechPromoRemovedLabel || '', false);
                } catch {
                    setPromotionFeedback(card, root.dataset.ictechPromoFailedLabel || '', true);
                } finally {
                    button.disabled = false;
                    input.disabled = false;

                    if (applyButton) {
                        applyButton.disabled = false;
                    }
                }
            });
        });

        root.querySelectorAll('[data-ictech-add-address]').forEach((link) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();

                if (!addressModal) {
                    return;
                }

                const card = link.closest('.ictech-account-carts__card');
                const cartId = card ? card.getAttribute('data-cart-id') : '';
                const targetField = link.getAttribute('data-ictech-address-target') || '';
                const cartIdInput = addressModal.querySelector('[data-ictech-address-cart-id]');
                const targetFieldInput = addressModal.querySelector('[data-ictech-address-target-field]');
                const feedback = addressModal.querySelector('[data-ictech-address-feedback]');

                if (!cartId || !cartIdInput || !targetFieldInput) {
                    return;
                }

                addressModal.reset();
                cartIdInput.value = cartId;
                targetFieldInput.value = targetField;

                if (feedback) {
                    feedback.textContent = '';
                    feedback.hidden = true;
                    feedback.classList.remove('is-error', 'is-success');
                }

                openModal(addressModal);
            });
        });

        if (addressModal) {
            addressModal.addEventListener('submit', async (event) => {
                event.preventDefault();

                if (!addressUrl) {
                    return;
                }

                const formData = new FormData(addressModal);
                const cartId = formData.get('cartId');
                const targetField = formData.get('targetField');
                const feedback = addressModal.querySelector('[data-ictech-address-feedback]');

                try {
                    const response = await fetch(addressUrl, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok || payload.success !== true) {
                        if (feedback) {
                            feedback.textContent = payload.message || addressCreateFailedLabel;
                            feedback.hidden = false;
                            feedback.classList.add('is-error');
                        }

                        return;
                    }

                    updateAddressOptions(payload.options || null);

                    if (typeof cartId === 'string' && cartId !== '' && typeof targetField === 'string' && targetField !== '') {
                        const card = root.querySelector(`.ictech-account-carts__card[data-cart-id="${cartId}"]`);
                        const select = card ? card.querySelector(`[data-ictech-preference="${targetField}"]`) : null;

                        if (select && payload.addressId) {
                            select.value = payload.addressId;
                            select.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }

                    closeModal(addressModal);
                } catch {
                    if (feedback) {
                        feedback.textContent = addressCreateFailedLabel;
                        feedback.hidden = false;
                        feedback.classList.add('is-error');
                    }
                }
            });
        }

        root.querySelectorAll('[data-ictech-combined-cart]').forEach((checkbox) => {
            checkbox.addEventListener('change', syncCombinedBar);
        });

        root.querySelectorAll('[data-ictech-combined-open]').forEach((button) => {
            button.addEventListener('click', () => {
                if (root.dataset.ictechCombinedEnabled !== 'true') {
                    window.alert(root.dataset.ictechCombinedDisabledLabel || '');
                    return;
                }

                const selectedCards = getSelectedCards();

                if (selectedCards.length === 0 || !cartIdContainer) {
                    return;
                }

                populateCombinedCartIds(selectedCards);

                if (!hasPreferenceMismatch(selectedCards)) {
                    setConflictAcknowledgement(false);

                    if (combinedForm) {
                        combinedForm.submit();
                    }

                    return;
                }

                if (conflictResolution === 'require_same') {
                    window.alert(combinedRequireSameLabel || root.dataset.ictechCombinedMismatchLabel || '');

                    return;
                }

                if (!combinedModal) {
                    return;
                }

                combinedFields.forEach((fieldName) => {
                    const uniqueValues = [...new Set(getFieldValues(selectedCards, fieldName))];
                    const select = combinedModal.querySelector(`[data-ictech-combined-field="${fieldName}"]`);

                    if (select && uniqueValues[0]) {
                        select.value = uniqueValues[0];
                    }
                });

                const names = selectedCards
                    .map((card) => card.querySelector('.ictech-account-carts__card-title')?.textContent?.trim())
                    .filter(Boolean);

                if (descriptionNode) {
                    const conflictMessage = conflictResolution === 'show_warning'
                        ? combinedWarningLabel
                        : combinedOverrideLabel;

                    descriptionNode.textContent = `${conflictMessage || root.dataset.ictechCombinedMismatchLabel} ${names.join(', ')}`;
                }

                setConflictAcknowledgement(conflictResolution === 'show_warning');
                openModal(combinedModal);
            });
        });

        syncCombinedBar();
    }
}
