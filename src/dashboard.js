/**
 * Nextcloud - zammad
 *
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

document.addEventListener('DOMContentLoaded', () => {
	OCA.Dashboard.register('zammad_notifications', async (el, { widget }) => {
		const { default: Vue } = await import('vue')
		const { default: Dashboard } = await import('./views/Dashboard.vue')
		Vue.mixin({ methods: { t, n } })
		const View = Vue.extend(Dashboard)
		new View({
			propsData: { title: widget.title },
		}).$mount(el)
	})
})
