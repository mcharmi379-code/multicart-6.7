import './page/multi-cart-manager-dashboard';
import './page/multi-cart-manager-settings';
import './page/multi-cart-manager-blacklist';
import './page/multi-cart-manager-monitoring';
import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';

const { Module } = Shopware;

Module.register('multi-cart-manager', {
    type: 'plugin',
    name: 'Multi Cart Manager',
    title: 'multi-cart-manager.general.mainMenuItemGeneral',
    description: 'multi-cart-manager.general.descriptionTextModule',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#62ff80',
    icon: 'default-shopping-cart',
    favicon: 'icon-module-marketing.png',
    entity: 'multi_cart_manager',
    snippets: {
        'en-GB': enGB,
        'de-DE': deDE,
    },

    routes: {
        index: {
            component: 'multi-cart-manager-dashboard',
            path: 'index',
        },
        settings: {
            component: 'multi-cart-manager-settings',
            path: 'settings',
        },
        blacklist: {
            component: 'multi-cart-manager-blacklist',
            path: 'blacklist',
        },
        monitoring: {
            component: 'multi-cart-manager-monitoring',
            path: 'monitoring',
        },
    },

    navigation: [
        {
            id: 'multi-cart-manager',
            label: 'multi-cart-manager.general.mainMenuItemGeneral',
            color: '#62ff80',
            icon: 'default-shopping-cart',
            path: 'multi.cart.manager.index',
            position: 100,
            parent: 'sw-marketing',
        },
        {
            id: 'multi-cart-manager-dashboard',
            label: 'multi-cart-manager.dashboard.title',
            path: 'multi.cart.manager.index',
            parent: 'multi-cart-manager',
            position: 10,
        },
        {
            id: 'multi-cart-manager-settings',
            label: 'multi-cart-manager.settings.title',
            path: 'multi.cart.manager.settings',
            parent: 'multi-cart-manager',
            position: 20,
        },
        {
            id: 'multi-cart-manager-blacklist',
            label: 'multi-cart-manager.blacklist.title',
            path: 'multi.cart.manager.blacklist',
            parent: 'multi-cart-manager',
            position: 30,
        },
        {
            id: 'multi-cart-manager-monitoring',
            label: 'multi-cart-manager.monitoring.title',
            path: 'multi.cart.manager.monitoring',
            parent: 'multi-cart-manager',
            position: 40,
        },
    ],
});
