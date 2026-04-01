const PluginManager = window.PluginManager;

PluginManager.register(
    'ICTECHMultiCartAddToCart',
    () => import('./plugin/multi-cart-add-to-cart.plugin'),
    '[data-ictech-multi-cart-add-to-cart="true"]'
);

PluginManager.register(
    'ICTECHMultiCartAccount',
    () => import('./plugin/multi-cart-account.plugin'),
    '[data-ictech-multi-cart-account="true"]'
);
