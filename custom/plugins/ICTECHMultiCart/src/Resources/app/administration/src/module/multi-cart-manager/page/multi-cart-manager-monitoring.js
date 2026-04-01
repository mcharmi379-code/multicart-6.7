import template from './multi-cart-manager-monitoring.html.twig';
import './multi-cart-manager-monitoring.scss';

const { Application, Component, Mixin } = Shopware;

Component.register('multi-cart-manager-monitoring', {
    template,

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            salesChannels: [],
            selectedSalesChannel: null,
            selectedCustomerId: null,
            monitoredCarts: [],
            selectedCartDetails: null,
        };
    },

    computed: {
        httpClient() {
            return Application.getContainer('init').httpClient;
        },

        monitoringColumns() {
            return [
                { property: 'name', dataIndex: 'name', label: this.$tc('multi-cart-manager.monitoring.cartName'), primary: true },
                { property: 'status', dataIndex: 'status', label: this.$tc('multi-cart-manager.monitoring.status') },
                { property: 'itemCount', dataIndex: 'itemCount', label: this.$tc('multi-cart-manager.monitoring.itemCount') },
                { property: 'promotionCode', dataIndex: 'promotionCode', label: this.$tc('multi-cart-manager.monitoring.promotionCode') },
                { property: 'total', dataIndex: 'total', label: this.$tc('multi-cart-manager.monitoring.total') },
                { property: 'updatedAt', dataIndex: 'updatedAt', label: this.$tc('multi-cart-manager.monitoring.updatedAt') },
            ];
        },
    },

    created() {
        this.loadSalesChannels();
    },

    watch: {
        selectedSalesChannel() {
            this.monitoredCarts = [];
            this.selectedCartDetails = null;
        },

        selectedCustomerId(newValue) {
            if (!newValue || !this.selectedSalesChannel) {
                this.monitoredCarts = [];
                this.selectedCartDetails = null;

                return;
            }

            this.loadCarts();
        },
    },

    methods: {
        loadSalesChannels() {
            this.isLoading = true;
            this.httpClient.get('/_action/multi-cart/sales-channels', {
                headers: Shopware.Context.api.apiResourceHeaders,
            }).then((response) => {
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

        loadCarts() {
            this.isLoading = true;

            const query = new URLSearchParams({
                salesChannelId: this.selectedSalesChannel,
                customerId: this.selectedCustomerId,
                _ts: String(Date.now()),
            });

            this.httpClient.get(`/_action/multi-cart/monitoring/carts?${query.toString()}`, {
                headers: Shopware.Context.api.apiResourceHeaders,
            }).then((response) => {
                this.monitoredCarts = Array.isArray(response.data?.data) ? response.data.data : [];
                this.isLoading = false;
            }).catch(() => {
                this.createNotificationError({
                    title: this.$tc('multi-cart-manager.notification.error'),
                    message: this.$tc('multi-cart-manager.notification.loadMonitoringError'),
                });
                this.isLoading = false;
            });
        },

        formatCurrency(value) {
            return Number(value || 0).toFixed(2);
        },

        formatCurrencyWithSymbol(value) {
            return `€ ${this.formatCurrency(value)}`;
        },

        formatDate(value) {
            if (!value) {
                return '-';
            }

            const parsedDate = new Date(value);

            if (Number.isNaN(parsedDate.getTime())) {
                return value;
            }

            return parsedDate.toLocaleString();
        },

        closeCartDetails() {
            this.selectedCartDetails = null;
        },

        showCartDetails(item) {
            this.selectedCartDetails = item;
        },
    },
});
