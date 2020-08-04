<template>
    <DashboardWidget :items="items"
          :showMoreUrl="showMoreUrl"
          :loading="state === 'loading'">
        <template v-slot:empty-content>
            <div v-if="state === 'no-token'">
                <a :href="settingsUrl">
                    {{ t('zammad', 'Click here to configure the access to your Zammad account.')}}
                </a>
            </div>
            <div v-else-if="state === 'error'">
                <a :href="settingsUrl">
                    {{ t('zammad', 'Incorrect access token.') }}
                    {{ t('zammad', 'Click here to configure the access to your Zammad account.')}}
                </a>
            </div>
            <div v-else-if="state === 'ok'">
                {{ t('zammad', 'Nothing to show') }}
            </div>
        </template>
    </DashboardWidget>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl, imagePath } from '@nextcloud/router'
import { DashboardWidget } from '@nextcloud/vue-dashboard'
import { showSuccess, showError } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import { getLocale } from '@nextcloud/l10n'

export default {
    name: 'Dashboard',

    props: [],
    components: {
        DashboardWidget
    },

    beforeMount() {
        this.launchLoop()
    },

    mounted() {
    },

    data() {
        return {
            zammadUrl: null,
            notifications: [],
            locale: getLocale(),
            loop: null,
            state: 'loading',
            settingsUrl: generateUrl('/settings/user/linked-accounts'),
            themingColor: OCA.Theming ? OCA.Theming.color.replace('#', '') : '0082C9',
        }
    },

    computed: {
        showMoreUrl() {
            return this.zammadUrl + '/#dashboard'
        },
        items() {
            return this.notifications.map((n) => {
                return {
                    id: this.getUniqueKey(n),
                    targetUrl: this.getNotificationTarget(n),
                    avatarUrl: this.getAuthorAvatarUrl(n),
                    avatarUsername: this.getAuthorShortName(n),
                    overlayIconUrl: this.getNotificationTypeImage(n),
                    mainText: this.getTargetTitle(n),
                    subText: this.getSubline(n),
                }
            })
        },
        lastDate() {
            const nbNotif = this.notifications.length
            return (nbNotif > 0) ? this.notifications[0].updated_at : null
        },
        lastMoment() {
            return moment(this.lastDate)
        },
    },

    methods: {
        async launchLoop() {
            // get zammad URL first
            try {
                const response = await axios.get(generateUrl('/apps/zammad/url'))
                this.zammadUrl = response.data.replace(/\/+$/, '')
            } catch (error) {
                console.log(error)
            }
            // then launch the loop
            this.fetchNotifications()
            this.loop = setInterval(() => this.fetchNotifications(), 45000)
        },
        fetchNotifications() {
            const req = {}
            if (this.lastDate) {
                req.params = {
                    since: this.lastDate
                }
            }
            axios.get(generateUrl('/apps/zammad/notifications'), req).then((response) => {
                this.processNotifications(response.data)
                this.state = 'ok'
            }).catch((error) => {
                clearInterval(this.loop)
                if (error.response && error.response.status === 400) {
                    this.state = 'no-token'
                } else if (error.response && error.response.status === 401) {
                    showError(t('zammad', 'Failed to get Zammad notifications.'))
                    this.state = 'error'
                } else {
                    // there was an error in notif processing
                    console.log(error)
                }
            })
        },
        processNotifications(newNotifications) {
            if (this.lastDate) {
                // just add those which are more recent than our most recent one
                let i = 0
                while (i < newNotifications.length && this.lastMoment.isBefore(newNotifications[i].updated_at)) {
                    i++
                }
                if (i > 0) {
                    const toAdd = this.filter(newNotifications.slice(0, i))
                    this.notifications = toAdd.concat(this.notifications)
                }
            } else {
                // first time we don't check the date
                this.notifications = this.filter(newNotifications)
            }
        },
        filter(notifications) {
            return notifications
        },
        getNotificationTarget(n) {
            return this.zammadUrl + '/#ticket/zoom/' + n.o_id
        },
        getUniqueKey(n) {
            return n.id + ':' + n.updated_at
        },
        getAuthorShortName(n) {
            if (!n.firstname && !n.lastname) {
                return '?'
            } else {
                return (n.firstname ? n.firstname[0] : '') +
                    (n.lastname ? n.lastname[0] : '')
            }
        },
        getAuthorFullName(n) {
            return n.firstname + ' ' + n.lastname
        },
        getAuthorAvatarUrl(n) {
            return (n.image) ?
                    generateUrl('/apps/zammad/avatar?') + encodeURIComponent('image') + '=' + encodeURIComponent(n.image) :
                    ''
        },
        getNotificationProjectName(n) {
            return ''
        },
        getNotificationContent(n) {
            return ''
        },
        getNotificationTypeImage(n) {
            if (n.type_lookup_id === 2 || n.type === 'update') {
                return generateUrl('/svg/zammad/rename?color=ffffff')
            } else if (n.type_lookup_id === 3 || n.type === 'create') {
                return generateUrl('/svg/zammad/add?color=ffffff')
            }
            return generateUrl('/svg/core/actions/sound?color=' + this.themingColor)
        },
        getSubline(n) {
            return this.getAuthorFullName(n) + ' #' + n.o_id
        },
        getTargetTitle(n) {
            return n.title
        },
        getTargetIdentifier(n) {
            return n.o_id
        },
        getFormattedDate(n) {
            return moment(n.updated_at).locale(this.locale).format('LLL')
        },
    },
}
</script>

<style scoped lang="scss">
</style>
