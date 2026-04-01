import template from './multi-cart-manager-dashboard.html.twig';
import './multi-cart-manager-dashboard.scss';

const { Application, Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('multi-cart-manager-dashboard', {
    template,

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            activeCarts: [],
            analytics: {},
            completedOrders: [],
            usageDistribution: [],
            selectedSalesChannel: null,
            salesChannels: [],
        };
    },

    computed: {
        httpClient() {
            return Application.getContainer('init').httpClient;
        },

        activeCartsColumns() {
            return [
                { property: 'name', dataIndex: 'name', label: this.$tc('multi-cart-manager.dashboard.cartName'), primary: true },
                { property: 'owner', dataIndex: 'owner', label: this.$tc('multi-cart-manager.dashboard.owner') },
                { property: 'itemCount', dataIndex: 'itemCount', label: this.$tc('multi-cart-manager.dashboard.itemCount') },
                { property: 'total', dataIndex: 'total', label: this.$tc('multi-cart-manager.dashboard.total') },
                { property: 'lastActivity', dataIndex: 'lastActivity', label: this.$tc('multi-cart-manager.dashboard.lastActivity') },
                { property: 'createdAt', dataIndex: 'createdAt', label: this.$tc('multi-cart-manager.dashboard.createdAt') },
            ];
        },

        completedOrdersColumns() {
            return [
                { property: 'cartName', dataIndex: 'cartName', label: this.$tc('multi-cart-manager.dashboard.cartName'), primary: true },
                { property: 'orderId', dataIndex: 'orderId', label: this.$tc('multi-cart-manager.dashboard.orderId') },
                { property: 'status', dataIndex: 'status', label: this.$tc('multi-cart-manager.dashboard.status') },
                { property: 'items', dataIndex: 'items', label: this.$tc('multi-cart-manager.dashboard.itemsSummary') },
                { property: 'promotionCode', dataIndex: 'promotionCode', label: this.$tc('multi-cart-manager.dashboard.promotionCode') },
                { property: 'discount', dataIndex: 'discount', label: this.$tc('multi-cart-manager.dashboard.discount') },
                { property: 'orderedAt', dataIndex: 'orderedAt', label: this.$tc('multi-cart-manager.dashboard.orderedAt') },
            ];
        },

        usageDistributionColumns() {
            return [
                { property: 'customerName', dataIndex: 'customerName', label: this.$tc('multi-cart-manager.dashboard.customer'), primary: true },
                { property: 'customerEmail', dataIndex: 'customerEmail', label: this.$tc('multi-cart-manager.dashboard.email') },
                { property: 'cartCount', dataIndex: 'cartCount', label: this.$tc('multi-cart-manager.dashboard.cartCount') },
                { property: 'totalValue', dataIndex: 'totalValue', label: this.$tc('multi-cart-manager.dashboard.totalValue') },
            ];
        },
    },

    created() {
        this.loadSalesChannels();
    },

    methods: {
        loadSalesChannels() {
            this.isLoading = true;
            this.httpClient.get(
                '/_action/multi-cart/sales-channels',
                {
                    headers: Shopware.Context.api.apiResourceHeaders,
                }
            ).then((response) => {
                this.salesChannels = response.data;
                if (this.salesChannels.length > 0) {
                    this.selectedSalesChannel = this.salesChannels[0].id;
                    this.loadDashboard();
                }
                this.isLoading = false;
            }).catch(() => {
                this.createNotificationError({
                    title: this.$tc('multi-cart-manager.notification.error'),
                    message: this.$tc('multi-cart-manager.notification.loadSalesChannelsError'),
                });
                this.isLoading = false;
            });
        },

        loadDashboard() {
            if (!this.selectedSalesChannel) {
                this.activeCarts = [];
                this.analytics = {};
                this.completedOrders = [];
                this.usageDistribution = [];

                return;
            }

            this.isLoading = true;
            this.httpClient.get(
                '/_action/multi-cart/dashboard',
                {
                    headers: Shopware.Context.api.apiResourceHeaders,
                    params: {
                        salesChannelId: this.selectedSalesChannel,
                    },
                }
            ).then((response) => {
                this.activeCarts = response.data.activeCarts;
                this.analytics = response.data.analytics;
                this.completedOrders = response.data.completedOrders;
                this.usageDistribution = response.data.analytics?.usageDistribution || [];
                this.isLoading = false;
            }).catch(() => {
                this.createNotificationError({
                    title: this.$tc('multi-cart-manager.notification.error'),
                    message: this.$tc('multi-cart-manager.notification.loadDashboardError'),
                });
                this.isLoading = false;
            });
        },

        onSalesChannelChange() {
            this.loadDashboard();
        },

        formatCurrency(value) {
            const amount = Number(value || 0);

            return amount.toFixed(2);
        },

        formatDate(value) {
            if (!value) {
                return '-';
            }

            const parsed = new Date(value);

            if (Number.isNaN(parsed.getTime())) {
                return value;
            }

            return parsed.toLocaleString();
        },

        showUsageDetails(item) {
            this.createNotificationInfo({
                title: this.$tc('multi-cart-manager.dashboard.usageDistribution'),
                message: `${item.customerName} (${item.customerEmail || '-'}) • ${item.cartCount} carts • € ${this.formatCurrency(item.totalValue)}`,
            });
        },

        showCartDetails(item) {
            this.createNotificationInfo({
                title: this.$tc('multi-cart-manager.dashboard.activeCarts'),
                message: `${item.name} • ${item.owner} • ${item.itemCount} items • € ${this.formatCurrency(item.total)}`,
            });
        },

        showOrderDetails(item) {
            this.createNotificationInfo({
                title: this.$tc('multi-cart-manager.dashboard.completedOrders'),
                message: `${item.cartName} • ${item.orderId} • ${item.status} • ${item.items || '-'}`,
            });
        },
    },
});
