<template>
	<div class="jira-issue">
		<div class="rows">
			<div class="row">
				<NcIconSvgWrapper inline class="icon" :path="circleIcon" style="color: var(--color-error);" />
				<span>{{ displayName }}</span>
			</div>
			<div class="row">
				<p class="type">
					<NcIconSvgWrapper inline class="icon" :path="toolsIcon" />
					<b>{{ t('integration_jira', 'Type') }}:</b> {{ type }}&nbsp;|&nbsp;
				</p>
				<p class="status">
					<NcIconSvgWrapper inline class="icon" :path="pinIcon" style="color: var(--color-error);" />
					<b>{{ t('integration_jira', 'Status') }}:</b> {{ status }}&nbsp;|&nbsp;
				</p>
				<p class="priority">
					<NcIconSvgWrapper inline class="icon" :path="lightningBoltIcon" style="color: var(--color-warning);" />
					<b>{{ t('integration_jira', 'Priority') }}:</b> <span :class="`priority-${priorityColor}`">{{ priority }}</span>
				</p>
			</div>
			<div v-if="labels.length > 0" class="row">
				<p class="labels">
					<NcIconSvgWrapper inline class="icon" :path="labelIcon" />
					<b>{{ t('integration_jira', 'Labels') }}:</b>
					<span v-for="label in labels"
						:key="label"
						class="label-badge">
						{{ label }}
					</span>
				</p>
			</div>
			<div class="row">
				<p class="assignee">
					<NcIconSvgWrapper inline class="icon" :path="accountIcon" style="color: var(--color-info);" />
					<b>{{ t('integration_jira', 'Assignee') }}:</b> {{ assignedTo }}
				</p>
			</div>
			<p v-if="created" class="row created-time">
				<NcIconSvgWrapper inline class="icon" :path="calendarCheckIcon" />
				<b>{{ t('integration_jira', 'Created') }}:</b> {{ created }}
			</p>
			<p v-if="updated" class="row updated-time">
				<NcIconSvgWrapper inline class="icon" :path="calendarUpdatedIcon" />
				<b>{{ t('integration_jira', 'Updated') }}:</b> {{ updated }}
			</p>
			<p v-if="summary" class="summary">{{ summary }}</p>
		</div>
	</div>
</template>

<script>
import moment from '@nextcloud/moment'

import NcIconSvgWrapper from '@nextcloud/vue/dist/Components/NcIconSvgWrapper.js'

import {
	mdiCircle,
	mdiTools,
	mdiAccount,
	mdiPin,
	mdiLightningBolt,
	mdiCalendarCheck,
	mdiCalendarBadgeOutline,
	mdiLabel,
} from '@mdi/js'

export default {
	name: 'JiraReference',

	components: {
		NcIconSvgWrapper,
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

	computed: {
		displayName() {
			return `[${this.richObject?.fields?.project.name}] ${this.richObject?.key}`
		},
		created() {
			return this.richObject ? moment(this.richObject?.fields?.created).format('LLL') : null
		},
		updated() {
			return this.richObject ? moment(this.richObject?.fields?.updated).format('LLL') : null
		},
		summary() {
			return this.richObject ? this.richObject?.fields?.summary : null
		},
		name() {
			return this.richObject ? this.richObject?.fields?.status?.name : null
		},
		type() {
			return this.richObject ? this.richObject?.fields?.issuetype?.name : null
		},
		status() {
			return this.richObject ? this?.richObject?.fields?.status?.name : null
		},
		priority() {
			return this.richObject ? this.richObject?.fields?.priority?.name : null
		},
		priorityColor() {
			if (this.richObject) {
				switch (this.richObject?.fields?.priority?.name) {
					case 'Highest':
						return 'dark-red'
					case 'High':
						return 'orange'
					case 'Medium':
						return 'yellow'
					case 'Low':
						return 'dark-grey'
					case 'Lowest':
						return 'light-grey'
					default:
						return 'yellow'
				}
			}
			return 'yellow'
		},
		assignedTo() {
			return this.richObject ? this.richObject?.fields?.assignee?.displayName ?? t('integration_jira', 'Unassigned') : t('integration_jira', 'Unassigned')
		},
		labels() {
			return this.richObject ? this.richObject?.fields?.labels : []
		},
		circleIcon() {
			return mdiCircle
		},
		toolsIcon() {
			return mdiTools
		},
		accountIcon() {
			return mdiAccount
		},
		pinIcon() {
			return mdiPin
		},
		lightningBoltIcon() {
			return mdiLightningBolt
		},
		calendarCheckIcon() {
			return mdiCalendarCheck
		},
		calendarUpdatedIcon() {
			return mdiCalendarBadgeOutline
		},
		labelIcon() {
			return mdiLabel
		},
	},
}
</script>

<style lang="scss" scoped>
.jira-issue {
	padding: 10px;

	.rows {
		display: flex;
		flex-direction: column;
	}

	.row {
		display: flex;
		align-items: center;
		padding: 3px 0;
		flex-wrap: wrap;

		b {
			margin-right: 3px;
		}
	}

	.icon {
		margin-right: 2px;
	}

	.labels {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
	}

	.label-badge {
		padding: 2px 5px;
		border-radius: 5px;
		margin-right: 5px;
		border: 1px solid var(--color-border);
	}

	.summary {
		margin: 5px 0;
	}

	.priority-light-grey {
		color: #D3D3D3;
	}

	.priority-dark-grey {
		color: #A9A9A9;
	}

	.priority-yellow {
		color: var(--color-warning);
	}

	.priority-orange {
		color: #FFA500;
	}

	.priority-dark-red {
		color: #8B0000;
	}
}
</style>