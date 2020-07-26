<template>
    <div id="zammad_prefs" class="section">
            <h2>
                <a class="icon icon-zammad"></a>
                {{ t('zammad', 'Zammad') }}
            </h2>
            <div class="zammad-grid-form">
                <label for="zammad-url">
                    <a class="icon icon-link"></a>
                    {{ t('zammad', 'Zammad instance address') }}
                </label>
                <input id="zammad-url" type="text" v-model="state.url" @input="onInput"
                    :placeholder="t('zammad', 'https://my.zammad.org')"/>
                <button id="zammad-oauth" v-if="showOAuth" @click="onOAuthClick">
                    {{ t('zammad', 'Get access with OAuth') }}
                </button>
                <span v-else></span>
                <label for="zammad-token">
                    <a class="icon icon-category-auth"></a>
                    {{ t('zammad', 'Zammad access token') }}
                </label>
                <input id="zammad-token" type="password" v-model="state.token" @input="onInput"
                    :placeholder="t('zammad', 'Get a token in Zammad settings')"/>
            </div>
    </div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateUrl, imagePath } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
    name: 'PersonalSettings',

    props: [],
    components: {
    },

    mounted() {
        const paramString = window.location.search.substr(1)
        const urlParams = new URLSearchParams(paramString)
        const zmToken = urlParams.get('zammadToken')
        if (zmToken === 'success') {
            showSuccess(t('zammad', 'OAuth access token successfully retrieved!'))
        } else if (zmToken === 'error') {
            showError(t('zammad', 'OAuth access token could not be obtained:') + ' ' + urlParams.get('message'))
        }
    },

    data() {
        return {
            state: loadState('zammad', 'user-config'),
        }
    },

    watch: {
    },

    computed: {
        showOAuth() {
            return this.state.url === this.state.oauth_instance_url &&
                this.state.client_id && this.state.client_secret
        },
    },

    methods: {
        onInput() {
            const that = this
            delay(function() {
                that.saveOptions()
            }, 2000)()
        },
        saveOptions() {
            if (this.state.url !== '' && !this.state.url.startsWith('https://')) {
                if (this.state.url.startsWith('http://')) {
                    this.state.url = this.state.url.replace('http://', 'https://')
                } else {
                    this.state.url = 'https://' + this.state.url
                }
            }
            const req = {
                values: {
                    token: this.state.token,
                    url: this.state.url,
                    // if manually set, this is not an oauth access token
                    token_type: 'access'
                }
            }
            const url = generateUrl('/apps/zammad/config')
            axios.put(url, req)
                .then(function (response) {
                    showSuccess(t('zammad', 'Zammad options saved.'))
                })
                .catch(function (error) {
                    showError(t('zammad', 'Failed to save Zammad options') +
                        ': ' + error.response.request.responseText
                    )
                })
                .then(function () {
                })
        },
        onOAuthClick() {
            const redirect_endpoint = generateUrl('/apps/zammad/oauth-redirect')
            const redirect_uri = OC.getProtocol() + '://' + OC.getHostName() + redirect_endpoint
            const oauth_state = Math.random().toString(36).substring(3)
            const request_url = this.state.url + '/oauth/authorize?client_id=' + encodeURIComponent(this.state.client_id) +
                '&redirect_uri=' + encodeURIComponent(redirect_uri) +
                '&response_type=code' +
                '&state=' + encodeURIComponent(oauth_state)

            const req = {
                values: {
                    oauth_state: oauth_state,
                }
            }
            const url = generateUrl('/apps/zammad/config')
            axios.put(url, req)
                .then(function (response) {
                    window.location.replace(request_url)
                })
                .catch(function (error) {
                    showError(t('zammad', 'Failed to save Zammad OAuth state') +
                        ': ' + error.response.request.responseText
                    )
                })
                .then(function () {
                })
        }
    }
}
</script>

<style scoped lang="scss">
.zammad-grid-form label {
    line-height: 38px;
}
.zammad-grid-form input {
    width: 100%;
}
.zammad-grid-form {
    width: 900px;
    display: grid;
    grid-template: 1fr / 233px 233px 300px;
    margin-left: 30px;
}
#zammad_prefs .icon {
    display: inline-block;
    width: 32px;
}
#zammad_prefs .grid-form .icon {
    margin-bottom: -3px;
}
.icon-zammad {
    background-image: url(./../../img/app-dark.svg);
    background-size: 23px 23px;
    height: 23px;
    margin-bottom: -4px;
}
body.dark .icon-zammad {
    background-image: url(./../../img/app.svg);
}
</style>
