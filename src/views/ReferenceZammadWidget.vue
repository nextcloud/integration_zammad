<!--
  - @copyright Copyright (c) 2022 2022 Julien Veyssier <eneiluj@posteo.net>
  -
  - @author 2022 Julien Veyssier <eneiluj@posteo.net>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -->

<template>
	<div class="zammad-reference">
		<div v-if="isError">
			<h3>
				<ZammadIcon :size="20" class="icon" />
				<span>{{ t('integration_zammad', 'Zammad API error') }}</span>
			</h3>
			<p v-if="richObject.error"
				class="widget-error">
				{{ richObject.error }}
			</p>
			<p v-else
				class="widget-error">
				{{ t('integration_zammad', 'Unknown error') }}
			</p>
			<a :href="settingsUrl" class="settings-link external" target="_blank">
				<OpenInNewIcon :size="20" class="icon" />
				{{ t('integration_zammad', 'Zammad connected accounts settings') }}
			</a>
		</div>
		<div v-if="!isError" class="ticket-wrapper">
			<div class="ticket-info">
				<div class="line">
					<div class="title">
						<a :href="ticketUrl" class="ticket-link" target="_blank">
							<strong>
								{{ richObject.title }}
							</strong>
						</a>
						<div v-for="tag in richObject.zammad_ticket_tags.tags"
							:key="tag"
							class="tag">
							{{ tag }}
						</div>
					</div>
				</div>
				<div class="sub-text">
					<component :is="iconComponent"
						v-tooltip.top="{ content: stateTooltip }"
						:size="16"
						class="icon"
						:fill-color="iconColor" />
					<span>
						<a :href="ticketUrl" class="slug-link" target="_blank">
							{{ t('integration_zammad', 'Ticket#{number}', { number: richObject.number }) }}
						</a>
						[{{ richObject.severity }}]
					</span>
					<a
						v-tooltip.top="{ content: authorTooltip }"
						:href="authorUrl"
						target="_blank"
						class="author-link">
						{{ t('integration_zammad', 'by {creator}', { creator: authorName }) }}
					</a>
					<a v-if="richObject.zammad_ticket_author_organization"
						v-tooltip.top="{ html: true, content: $safeHTML(authorOrgTooltip) }"
						:href="authorOrgUrl"
						target="_blank"
						class="author-link">
						[{{ authorOrgName }}]
					</a>
					<span
						v-tooltip.top="{ content: createdAtFormatted }"
						class="date-with-tooltip">
						{{ createdAtText }}
					</span>
				</div>
			</div>
			<div class="right-content">
				<div>
					<NcAvatar v-if="authorAvatarUrl"
						:tooltip-message="authorTooltip"
						class="ticket-author-avatar"
						:is-no-user="true"
						:size="20"
						:url="authorAvatarUrl" />
					<NcAvatar v-else
						:tooltip-message="authorTooltip"
						class="ticket-author-avatar"
						:is-no-user="true"
						:size="20"
						:display-name="authorName" />
					<span>
						{{ ticketStateNames[richObject.state_id] }}
					</span>
					<div v-tooltip.top="{ content: t('integration_zammad', 'Comments') }"
						class="comments-count">
						<CommentIcon :size="16" class="icon" />
						{{ richObject.article_count }}
					</div>
				</div>
				<div v-if="richObject.close_at"
					v-tooltip.top="{ content: closedAtFormatted }"
					class="closed-at date-with-tooltip">
					&nbsp;· {{ closedAtText }}
				</div>
				<div v-else-if="richObject.updated_at"
					v-tooltip.top="{ content: updatedAtFormatted }"
					class="updated-at date-with-tooltip">
					&nbsp;· {{ updatedAtText }}
				</div>
			</div>
		</div>
		<div v-if="!isError && richObject.zammad_comment" class="comment">
			<div class="comment--author">
				<NcAvatar v-if="commentAuthorAvatarUrl"
					class="comment--author--avatar"
					:tooltip-message="commentAuthorTooltip"
					:is-no-user="true"
					:url="commentAuthorAvatarUrl" />
				<NcAvatar v-else
					class="comment--author--avatar"
					:tooltip-message="commentAuthorTooltip"
					:is-no-user="true"
					:display-name="commentAuthorName" />
				<span class="comment--author--bubble-tip" />
				<span class="comment--author--bubble">
					<div class="comment--author--bubble--header">
						<a
							v-tooltip.top="{ content: commentAuthorTooltip }"
							:href="commentAuthorUrl"
							target="_blank"
							class="author-link">
							<strong class="comment-author-display-name">{{ commentAuthorName }}</strong>
						</a>
						&nbsp;
						<a v-if="richObject.zammad_comment_author_organization"
							v-tooltip.top="{ html: true, content: commentAuthorOrgTooltip }"
							:href="commentAuthorOrgUrl"
							target="_blank"
							class="author-link">
							[{{ commentAuthorOrgName }}]
						</a>
						&nbsp;·&nbsp;
						<span
							v-tooltip.top="{ content: commentCreatedAtTooltip }"
							class="date-with-tooltip">
							{{ commentCreatedAtText }}
						</span>
						<div class="spacer" />
						<div class="tag">
							{{ t('integration_zammad', 'internal') }}
						</div>
					</div>
					<div v-tooltip.top="{ html: true, content: shortComment ? t('integration_zammad', 'Click to expand comment') : undefined }"
						v-html-safe="richObject.zammad_comment.body"
						:class="{
							'comment--author--bubble--content': true,
							'short-comment': shortComment,
						}"
						@click="shortComment = !shortComment" />
				</span>
			</div>
		</div>
	</div>
</template>

<script>
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'

import ZammadIcon from '../components/icons/ZammadIcon.vue'
import CommentIcon from '../components/icons/CommentIcon.vue'

import { generateUrl } from '@nextcloud/router'
import moment from '@nextcloud/moment'

import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip.js'
import VueSecureHTML from 'vue-html-secure'
import Vue from 'vue'

Vue.use(VueSecureHTML)
Vue.prototype.$safeHTML = VueSecureHTML.safeHTML
Vue.directive('tooltip', Tooltip)

export default {
	name: 'ReferenceZammadWidget',

	components: {
		ZammadIcon,
		CommentIcon,
		NcAvatar,
		OpenInNewIcon,
	},

	props: {
		richObjectType: {
			type: String,
			default: '',
		},
		richObject: {
			type: Object,
			default: null,
		},
		accessible: {
			type: Boolean,
			default: true,
		},
	},

	data() {
		return {
			settingsUrl: generateUrl('/settings/user/connected-accounts#zammad_prefs'),
			shortComment: true,
		}
	},

	computed: {
		isError() {
			return !!this.richObject.error
		},
		ticketUrl() {
			return this.richObject.zammad_url + '/#ticket/zoom/' + this.richObject.id
		},
		authorUrl() {
			return this.richObject.zammad_url + '/#user/profile/' + this.richObject.customer_id
		},
		authorName() {
			return this.richObject.zammad_ticket_author.firstname + ' ' + this.richObject.zammad_ticket_author.lastname
		},
		authorTooltip() {
			return this.richObject.zammad_ticket_author.email
		},
		authorAvatarUrl() {
			const imageId = this.richObject.zammad_ticket_author.image
			return imageId
				? generateUrl('/apps/integration_zammad/avatar/{imageId}', { imageId })
				: null
		},
		authorOrgUrl() {
			return this.richObject.zammad_url + '/#organization/profile/' + this.richObject.organization_id
		},
		authorOrgName() {
			return this.richObject.zammad_ticket_author_organization.name
		},
		authorOrgTooltip() {
			return '<strong>' + t('integration_zammad', 'Account manager')
				+ ':</strong> ' + this.richObject.zammad_ticket_author_organization.account_manager
				+ (this.richObject.zammad_ticket_author_organization.subscription_end
					? '<br><strong>' + t('integration_zammad', 'Subscription ends') + ':</strong> '
						+ moment(this.richObject.zammad_ticket_author_organization.subscription_end).format('LL')
					: '')
		},
		ticketStateNames() {
			const stateNamesById = {}
			this.richObject.zammad_ticket_states.forEach(s => {
				stateNamesById[s.id] = s.name
			})
			return stateNamesById
		},
		iconComponent() {
			return ZammadIcon
		},
		iconColor() {
			return '#8b949e'
		},
		stateTooltip() {
			return this.ticketStateNames[this.richObject.state_id]
		},
		createdAtFormatted() {
			return moment(this.richObject.created_at).format('LLL')
		},
		closedAtFormatted() {
			return moment(this.richObject.close_at).format('LLL')
		},
		updatedAtFormatted() {
			return moment(this.richObject.updated_at).format('LLL')
		},
		createdAtText() {
			return t('integration_zammad', 'created {relativeDate}', { relativeDate: moment(this.richObject.created_at).fromNow() })
		},
		closedAtText() {
			return t('integration_zammad', 'closed {relativeDate}', { relativeDate: moment(this.richObject.close_at).fromNow() })
		},
		updatedAtText() {
			return t('integration_zammad', 'updated {relativeDate}', { relativeDate: moment(this.richObject.updated_at).fromNow() })
		},
		commentCreatedAtText() {
			return moment(this.richObject.zammad_comment.created_at).fromNow()
		},
		commentCreatedAtTooltip() {
			return moment(this.richObject.zammad_comment.created_at).format('LLL')
		},
		commentAuthorUrl() {
			return this.richObject.zammad_url + '/#user/profile/' + this.richObject.zammad_comment.created_by_id
		},
		commentAuthorName() {
			return this.richObject.zammad_comment_author.firstname + ' ' + this.richObject.zammad_comment_author.lastname
		},
		commentAuthorTooltip() {
			return this.richObject.zammad_comment_author.email
		},
		commentAuthorAvatarUrl() {
			const imageId = this.richObject.zammad_comment_author.image
			return imageId
				? generateUrl('/apps/integration_zammad/avatar/{imageId}', { imageId })
				: null
		},
		commentAuthorOrgUrl() {
			return this.richObject.zammad_url + '/#organization/profile/' + this.richObject.zammad_comment_author.organization_id
		},
		commentAuthorOrgName() {
			return this.richObject.zammad_comment_author_organization.name
		},
		commentAuthorOrgTooltip() {
			return '<strong>' + t('integration_zammad', 'Account manager')
				+ ':</strong> ' + this.richObject.zammad_comment_author_organization.account_manager
				+ (this.richObject.zammad_comment_author_organization.subscription_end
					? '<br><strong>' + t('integration_zammad', 'Subscription ends') + ':</strong> '
					+ moment(this.richObject.zammad_comment_author_organization.subscription_end).format('LL')
					: '')
		},
	},

	methods: {
	},
}
</script>

<style scoped lang="scss">
.zammad-reference {
	width: 100%;
	white-space: normal;
	padding: 12px;

	a {
		padding: 0 !important;
		color: var(--color-main-text) !important;
		text-decoration: unset !important;
	}

	h3 {
		display: flex;
		align-items: center;
		font-weight: bold;
		margin-top: 0;
		.icon {
			margin-right: 8px;
		}
	}

	.ticket-wrapper {
		width: 100%;
		display: flex;
		flex-direction: column;
		align-items: start;

		.title {
			display: flex;
			align-items: center;
			flex-wrap: wrap;

			> * {
				margin-bottom: 2px;
			}

			.ticket-link {
				margin-right: 8px;
			}
		}

		.line {
			display: flex;
			align-items: center;

			> .icon {
				margin: 0 16px 0 8px;
			}
		}

		.sub-text {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			color: var(--color-text-maxcontrast);
			> * {
				margin-right: 4px;
			}

			.icon {
				width: 20px;
				margin-right: 8px;
			}
		}

		.closed-at,
		.updated-at {
			color: var(--color-text-maxcontrast);
		}

		.right-content {
			display: flex;
			flex-wrap: wrap;

			> * {
				display: flex;
				align-items: center;
			}

			.ticket-author-avatar {
				margin-right: 8px;
			}

			.comments-count {
				display: flex;
				align-items: center;
				margin-left: 8px;
				color: var(--color-text-maxcontrast);
				.icon {
					margin-right: 4px;
				}
			}
		}
	}

	.comment {
		margin-top: 8px;
		display: flex;
		flex-direction: column;
		align-items: start;
		&--author {
			display: flex;
			align-items: start;
			width: 100%;

			&--avatar {
				margin-top: 4px;
			}

			&--bubble {
				// TODO improve this
				display: grid;
				width: 100%;
				padding: 4px 8px;
				border: 1px solid var(--color-border-dark);
				border-radius: var(--border-radius);
				&--header {
					display: flex;
					flex-wrap: wrap;
					align-items: center;
					margin-bottom: 6px;
					color: var(--color-text-maxcontrast);
					.comment-author-display-name {
						color: var(--color-main-text);
					}
				}
				&--content {
					cursor: pointer;
					max-height: 250px;
					overflow: scroll;
					&.short-comment {
						max-height: 25px;
						overflow: hidden;
					}
				}
			}
			&--bubble-tip {
				margin-left: 15px;
				position: relative;
				top: 20px;
				&:before {
					content: '';
					width: 0px;
					height: 0px;
					position: absolute;
					border-left: 8px solid transparent;
					border-right: 8px solid var(--color-border-dark);
					border-top: 8px solid transparent;
					border-bottom: 8px solid transparent;
					left: -15px;
					top: -8px;
				}

				&:after {
					content: '';
					width: 0px;
					height: 0px;
					position: absolute;
					border-left: 8px solid transparent;
					border-right: 8px solid var(--color-main-background);
					border-top: 8px solid transparent;
					border-bottom: 8px solid transparent;
					left: -14px;
					top: -8px;
				}
				.message-body:hover &:after {
					border-right: 8px solid var(--color-background-hover);
				}
			}
		}
	}

	.tag {
		display: flex;
		align-items: center;
		height: 20px;
		margin-right: 4px;
		margin-bottom: 0 !important;
		border: 1px solid var(--color-border-dark);
		padding: 0 7px;
		border-radius: var(--border-radius-pill);
		font-size: 12px;
	}
	.comment .tag {
		margin-right: 0;
	}

	::v-deep .author-link,
	.slug-link {
		color: inherit !important;
	}

	.date-with-tooltip,
	::v-deep .author-link,
	.author-link:hover .comment-author-display-name,
	.slug-link,
	.ticket-link {
		&:hover {
			color: #58a6ff !important;
		}
	}

	.settings-link {
		display: flex;
		align-items: center;
		.icon {
			margin-right: 4px;
		}
	}

	.widget-error {
		margin-bottom: 8px;
	}

	.spacer {
		flex-grow: 1;
	}
}
</style>
