<template>
    <div>
        <ul v-if="state === 'ok'" class="notification-list">
            <li v-for="n in notifications" :key="getUniqueKey(n)">
                <a :href="getNotificationTarget(n)" target="_blank" class="notification-list__entry">
                    <Avatar v-if="n.image"
                        class="project-avatar"
                        :url="getAuthorAvatarUrl(n)"
                        />
                    <Avatar v-else
                        class="project-avatar"
                        :user="getAuthorShortName(n)"
                        />
                    <img class="zammad-notification-icon" :src="getNotificationTypeImage(n)"/>
                    <div class="notification__details">
                        <h3>
                            {{ getTargetTitle(n) }}
                        </h3>
                        <p class="message" :title="getSubline(n)">
                            {{ getSubline(n) }}
                        </p>
                    </div>
                </a>
            </li>
        </ul>
        <div v-else-if="state === 'no-token'">
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
        <div v-else-if="state === 'loading'" class="icon-loading-small"></div>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl, imagePath } from '@nextcloud/router'
import { Avatar, Popover } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import { getLocale } from '@nextcloud/l10n'

export default {
    name: 'Dashboard',

    props: [],
    components: {
        Avatar, Popover
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
            hovered: {},
        }
    },

    computed: {
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
                    this.notifications = toAdd.concat(this.notifications).slice(0, 7)
                }
            } else {
                // first time we don't check the date
                console.log(this.filter(newNotifications))
                this.notifications = this.filter(newNotifications).slice(0, 7)
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
            return generateUrl('/svg/core/actions/sound?color=' + this.themingColor)
        },
        getNotificationActionChar(n) {
            if (['Issue', 'MergeRequest'].includes(n.target_type)) {
                if (['approval_required', 'assigned'].includes(n.action_name)) {
                    return '👁'
                } else if (['directly_addressed', 'mentioned'].includes(n.action_name)) {
                    return '🗨'
                } else if (n.action_name === 'marked') {
                    return '✅'
                } else if (['build_failed', 'unmergeable'].includes(n.action_name)) {
                    return '❎'
                }
            }
            return ''
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
li .notification-list__entry {
    display: flex;
    align-items: flex-start;
    padding: 8px;

    &:hover,
    &:focus {
        background-color: var(--color-background-hover);
        border-radius: var(--border-radius-large);
    }
    .project-avatar {
        position: relative;
        margin-top: auto;
        margin-bottom: auto;
    }
    .notification__details {
        padding-left: 8px;
        max-height: 44px;
        flex-grow: 1;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        h3,
        .message {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .message span {
            width: 10px;
            display: inline-block;
            margin-bottom: -3px;
        }
        h3 {
            font-size: 100%;
            margin: 0;
        }
        .message {
            width: 100%;
            color: var(--color-text-maxcontrast);
        }
    }
    img.zammad-notification-icon {
        position: absolute;
        width: 14px;
        height: 14px;
        margin: 27px 0 10px 24px;
    }
    button.primary {
        padding: 21px;
        margin: 0;
    }
}
.date-popover {
    position: relative;
    top: 7px;
}
.content-popover {
    height: 0px;
    width: 0px;
    margin-left: auto;
    margin-right: auto;
}
.popover-container {
    width: 100%;
    height: 0px;
}
.popover-author-name {
    vertical-align: top;
    line-height: 24px;
}
</style>