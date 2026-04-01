import template from './multi-cart-manager-blacklist.html.twig';
import './multi-cart-manager-blacklist.scss';

const { Application, Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('multi-cart-manager-blacklist', {
    template,

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            blacklistedUsers: [],
            selectedSalesChannel: null,
            salesChannels: [],
            showAddForm: false,
            newBlacklist: {
                customerId: null,
                reason: '',
            },
            page: 1,
            limit: 50,
            total: 0,
        };
    },

    computed: {
        httpClient() {
            return Application.getContainer('init').httpClient;
        },

        blacklistColumns() {
            return [
                { property: 'customerDisplay', dataIndex: 'customerDisplay', label: this.$tc('multi-cart-manager.blacklist.customer'), primary: true },
                { property: 'reason', dataIndex: 'reason', label: this.$tc('multi-cart-manager.blacklist.reason') },
                { property: 'createdAt', dataIndex: 'createdAt', label: this.$tc('multi-cart-manager.blacklist.createdAt') },
            ];
        },

        blacklistedCustomerIds() {
            return this.blacklistedUsers
                .map((item) => item.customerId)
                .filter((customerId) => typeof customerId === 'string' && customerId.length > 0);
        },

        customerCriteria() {
            const criteria = new Criteria(1, 25);

            if (this.blacklistedCustomerIds.length > 0) {
                criteria.addFilter(Criteria.not('and', [
                    Criteria.equalsAny('id', this.blacklistedCustomerIds),
                ]));
            }

            return criteria;
        },

        isDuplicateSelection() {
            return this.blacklistedCustomerIds.includes(this.newBlacklist.customerId);
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

            this.page = 1;
            this.newBlacklist = { customerId: null, reason: '' };
            this.loadBlacklist();
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

        loadBlacklist() {
            if (!this.selectedSalesChannel) {
                this.blacklistedUsers = [];
                this.total = 0;
                return;
            }

            this.isLoading = true;
            const query = new URLSearchParams({
                salesChannelId: this.selectedSalesChannel,
                page: String(this.page),
                limit: String(this.limit),
                _ts: String(Date.now()),
            });

            this.httpClient.get(
                `/_action/multi-cart/blacklist?${query.toString()}`,
                { headers: Shopware.Context.api.apiResourceHeaders }
            ).then((response) => {
                const rows = Array.isArray(response.data?.data) ? response.data.data : [];
                this.blacklistedUsers = rows.map((item) => ({
                    ...item,
                    customerDisplay: this.getCustomerDisplay(item),
                }));
                this.total = Number.isInteger(response.data?.total) ? response.data.total : 0;
                this.isLoading = false;
            }).catch(() => {
                this.createNotificationError({
                    title: this.$tc('multi-cart-manager.notification.error'),
                    message: this.$tc('multi-cart-manager.notification.loadBlacklistError'),
                });
                this.isLoading = false;
            });
        },



        getCustomerDisplay(item) {
            if (item?.customerEmail) {
                return item.customerEmail;
            }

            if (item?.customerName) {
                return item.customerName;
            }

            return item?.customerId || '-';
        },

        formatCreatedAt(value) {
            if (!value) {
                return '-';
            }

            const parsedDate = new Date(value);
            if (Number.isNaN(parsedDate.getTime())) {
                return value;
            }

            return parsedDate.toLocaleString();
        },
        addToBlacklist() {
            if (this.isDuplicateSelection) {
                this.createNotificationError({
                    title: this.$tc('multi-cart-manager.notification.error'),
                    message: this.$tc('multi-cart-manager.notification.userAlreadyBlacklisted'),
                });
                return;
            }

            if (!this.newBlacklist.customerId) {
                this.createNotificationError({
                    title: this.$tc('multi-cart-manager.notification.error'),
                    message: this.$tc('multi-cart-manager.notification.customerIdRequired'),
                });
                return;
            }

            this.isLoading = true;
            const payload = {
                customerId: this.newBlacklist.customerId,
                salesChannelId: this.selectedSalesChannel,
                reason: this.newBlacklist.reason,
            };

            this.httpClient.post(
                '/_action/multi-cart/blacklist',
                payload,
                { headers: Shopware.Context.api.apiResourceHeaders }
            ).then(() => {
                this.createNotificationSuccess({
                    title: this.$tc('multi-cart-manager.notification.success'),
                    message: this.$tc('multi-cart-manager.notification.userBlacklisted'),
                });
                this.newBlacklist = { customerId: null, reason: '' };
                this.showAddForm = false;
                this.page = 1;
                this.loadBlacklist();
            }).catch(() => {
                this.createNotificationError({
                    title: this.$tc('multi-cart-manager.notification.error'),
                    message: this.$tc('multi-cart-manager.notification.addBlacklistError'),
                });
                this.isLoading = false;
            });
        },

        removeFromBlacklist(id) {
            if (!confirm(this.$tc('multi-cart-manager.notification.confirmRemoveBlacklist'))) {
                return;
            }

            this.isLoading = true;
            this.httpClient.delete(
                `/_action/multi-cart/blacklist/${id}`,
                { headers: Shopware.Context.api.apiResourceHeaders }
            ).then(() => {
                this.createNotificationSuccess({
                    title: this.$tc('multi-cart-manager.notification.success'),
                    message: this.$tc('multi-cart-manager.notification.userRemovedFromBlacklist'),
                });
                this.loadBlacklist();
            }).catch(() => {
                this.createNotificationError({
                    title: this.$tc('multi-cart-manager.notification.error'),
                    message: this.$tc('multi-cart-manager.notification.removeBlacklistError'),
                });
                this.isLoading = false;
            });
        },

    },
});
