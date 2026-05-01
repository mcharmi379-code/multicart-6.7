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
            usagePage: 1,
            usageLimit: 10,
            usageTotal: 0,
            activePage: 1,
            activeLimit: 10,
            activeTotal: 0,
            completedPage: 1,
            completedLimit: 10,
            completedTotal: 0,
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

    watch: {
        selectedSalesChannel(newValue, oldValue) {
            if (!newValue || newValue === oldValue) {
                return;
            }

            this.resetPagination();
            this.loadDashboard();
        },
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
                        usagePage: this.usagePage,
                        usageLimit: this.usageLimit,
                        activePage: this.activePage,
                        activeLimit: this.activeLimit,
                        completedPage: this.completedPage,
                        completedLimit: this.completedLimit,
                    },
                }
            ).then((response) => {
                this.activeCarts = response.data.activeCarts?.data || [];
                this.activeTotal = Number.isInteger(response.data.activeCarts?.total) ? response.data.activeCarts.total : 0;
                this.analytics = response.data.analytics;
                this.completedOrders = response.data.completedOrders?.data || [];
                this.completedTotal = Number.isInteger(response.data.completedOrders?.total) ? response.data.completedOrders.total : 0;
                this.usageDistribution = response.data.analytics?.usageDistribution?.data || [];
                this.usageTotal = Number.isInteger(response.data.analytics?.usageDistribution?.total) ? response.data.analytics.usageDistribution.total : 0;
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
            this.resetPagination();
            this.loadDashboard();
        },

        resetPagination() {
            this.usagePage = 1;
            this.activePage = 1;
            this.completedPage = 1;
        },

        onUsagePageChange(page) {
            this.usagePage = page;
            this.loadDashboard();
        },

        onActivePageChange(page) {
            this.activePage = page;
            this.loadDashboard();
        },

        onCompletedPageChange(page) {
            this.completedPage = page;
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
