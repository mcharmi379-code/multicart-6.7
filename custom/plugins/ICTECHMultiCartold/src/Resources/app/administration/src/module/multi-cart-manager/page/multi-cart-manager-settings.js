import template from './multi-cart-manager-settings.html.twig';
import './multi-cart-manager-settings.scss';

const { Application, Component, Mixin } = Shopware;

Component.register('multi-cart-manager-settings', {
    template,

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            isSaving: false,
            selectedSalesChannel: null,
            salesChannels: [],
            config: {
                pluginEnabled: true,
                maxCartsPerUser: 10,
                checkoutPrefsEnabled: true,
                promotionsEnabled: true,
                multiPaymentEnabled: true,
                conflictResolution: 'allow_override',
                uiStyle: 'popup',
            },
        };
    },

    computed: {
        httpClient() {
            return Application.getContainer('init').httpClient;
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
                { headers: Shopware.Context.api.apiResourceHeaders }
            ).then((response) => {
                this.salesChannels = response.data;
                if (this.salesChannels.length > 0) {
                    this.selectedSalesChannel = this.salesChannels[0].id;
                    this.loadConfig();
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

        loadConfig() {
            this.isLoading = true;
            this.httpClient.get(
                `/_action/multi-cart/config?salesChannelId=${this.selectedSalesChannel}`,
                { headers: Shopware.Context.api.apiResourceHeaders }
            ).then((response) => {
                if (response.data && Object.keys(response.data).length > 0) {
                    this.config = {
                        ...this.config,
                        ...response.data,
                        uiStyle: response.data.uiStyle || 'popup',
                    };
                }
                this.isLoading = false;
            }).catch(() => {
                this.createNotificationError({
                    title: this.$tc('multi-cart-manager.notification.error'),
                    message: this.$tc('multi-cart-manager.notification.loadConfigError'),
                });
                this.isLoading = false;
            });
        },

        saveConfig() {
            this.isSaving = true;
            const payload = {
                ...this.config,
                salesChannelId: this.selectedSalesChannel,
            };

            this.httpClient.post(
                '/_action/multi-cart/config',
                payload,
                { headers: Shopware.Context.api.apiResourceHeaders }
            ).then(() => {
                this.createNotificationSuccess({
                    title: this.$tc('multi-cart-manager.notification.success'),
                    message: this.$tc('multi-cart-manager.notification.configSaved'),
                });
                this.isSaving = false;
            }).catch(() => {
                this.createNotificationError({
                    title: this.$tc('multi-cart-manager.notification.error'),
                    message: this.$tc('multi-cart-manager.notification.saveConfigError'),
                });
                this.isSaving = false;
            });
        },

        onSalesChannelChange() {
            this.loadConfig();
        },
    },
});
