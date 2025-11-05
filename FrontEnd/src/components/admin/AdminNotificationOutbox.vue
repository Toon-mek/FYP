<script setup>
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import {
  NAlert,
  NButton,
  NCard,
  NDataTable,
  NEmpty,
  NModal,
  NPagination,
  NSelect,
  NSkeleton,
  NSpace,
  NTag,
  NText,
} from 'naive-ui'

const API_BASE = import.meta.env.VITE_API_BASE || '/api'

const props = defineProps({
  initialRecipientType: {
    type: String,
    default: 'all',
  },
  pageSize: {
    type: Number,
    default: 10,
  },
})

const state = reactive({
  loading: false,
  refreshing: false,
  error: '',
  notifications: [],
  recipientFilter: props.initialRecipientType,
  meta: {
    total: 0,
    limit: props.pageSize,
    offset: 0,
  },
})

const detailState = reactive({
  show: false,
  notification: null,
})

const recipientOptions = [
  { label: 'All recipients', value: 'all' },
  { label: 'Operators', value: 'Operator' },
  { label: 'Travelers', value: 'Traveler' },
  { label: 'Admins', value: 'Admin' },
]

const columns = [
  {
    title: 'Recipient',
    key: 'recipient',
    minWidth: 220,
    render(row) {
      const display = row.recipient?.name || `User #${row.recipientId}`
      const email = row.recipient?.email || ''
      return h(
        'div',
        { style: 'display:flex; flex-direction:column; gap:4px;' },
        [
          h('div', { style: 'display:flex; align-items:center; gap:10px;' }, [
            h(
              NTag,
              {
                size: 'tiny',
                type:
                  row.recipientType === 'Operator'
                    ? 'warning'
                    : row.recipientType === 'Traveler'
                      ? 'success'
                      : 'info',
                round: true,
                bordered: false,
              },
              { default: () => row.recipientType },
            ),
            h('span', { style: 'font-weight:600;' }, display),
          ]),
          email
            ? h(NText, { depth: 3, style: 'font-size:12px; margin-left: 26px;' }, { default: () => email })
            : null,
        ].filter(Boolean),
      )
    },
  },
  {
    title: 'Title',
    key: 'title',
    minWidth: 200,
  },
  {
    title: 'Message',
    key: 'message',
    minWidth: 320,
    render(row) {
      return buildPreviewText(row.message)
    },
  },
  {
    title: 'Sent at',
    key: 'createdAt',
    width: 180,
    render(row) {
      return formatTimestamp(row.createdAt)
    },
  },
  {
    title: 'Action',
    key: 'actions',
    width: 120,
    render(row) {
      return h(
        NButton,
        {
          size: 'small',
          tertiary: true,
          onClick: () => openDetails(row),
          style: 'justify-content:flex-start;',
        },
        { default: () => 'Details' },
      )
    },
  },
]

const hasNotifications = computed(() => state.notifications.length > 0)

const page = computed({
  get() {
    return Math.floor(state.meta.offset / state.meta.limit) + 1
  },
  set(newPage) {
    const target = Number(newPage)
    if (!Number.isFinite(target) || target < 1) return
    const nextOffset = (target - 1) * state.meta.limit
    if (nextOffset === state.meta.offset) return
    state.meta.offset = nextOffset
    loadSentNotifications()
  },
})

const pageCount = computed(() =>
  state.meta.limit > 0 ? Math.max(1, Math.ceil(state.meta.total / state.meta.limit)) : 1,
)

const formatter = new Intl.DateTimeFormat(undefined, {
  dateStyle: 'medium',
  timeStyle: 'short',
})

function formatTimestamp(value) {
  if (!value) return '--'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  try {
    return formatter.format(date)
  } catch {
    return date.toLocaleString()
  }
}

function buildPreviewText(message) {
  const text = String(message ?? '').trim()
  if (text.length <= 140) return text || '--'
  return `${text.slice(0, 137)}...`
}

function openDetails(row) {
  detailState.notification = row
  detailState.show = true
}

function closeDetails() {
  detailState.show = false
  detailState.notification = null
}

async function loadSentNotifications() {
  if (state.loading) return
  state.loading = true
  state.error = ''

  const params = new URLSearchParams({
    scope: 'sent',
    limit: String(state.meta.limit),
    offset: String(state.meta.offset),
  })
  if (state.recipientFilter && state.recipientFilter !== 'all') {
    params.set('recipientType', state.recipientFilter)
  }

  try {
    const response = await fetch(`${API_BASE}/notifications.php?${params.toString()}`)
    if (!response.ok) {
      throw new Error('Unable to load sent notifications.')
    }
    const body = await response.json().catch(() => ({}))
    state.notifications = Array.isArray(body.notifications) ? body.notifications : []
    state.meta.total = Number(body?.meta?.total ?? state.notifications.length)
  } catch (error) {
    console.error(error)
    state.error = error instanceof Error ? error.message : 'Something went wrong while loading notifications.'
    state.notifications = []
  } finally {
    state.loading = false
    state.refreshing = false
  }
}

async function refreshSentNotifications() {
  if (state.refreshing) {
    return
  }
  state.refreshing = true
  await loadSentNotifications()
}

onMounted(() => {
  loadSentNotifications()
})

watch(
  () => state.recipientFilter,
  () => {
    state.meta.offset = 0
    loadSentNotifications()
  },
)
</script>

<template>
  <n-card size="large" title="Sent notifications" :segmented="{ content: true }" style="margin-top: 16px;">
    <template #header-extra>
      <n-space align="center" :size="12">
        <n-select v-model:value="state.recipientFilter" size="small" :options="recipientOptions"
          style="width: 160px;" />
        <n-button size="small" quaternary :loading="state.refreshing" :disabled="state.refreshing"
          @click="refreshSentNotifications">
          Refresh
        </n-button>
      </n-space>
    </template>

    <n-alert v-if="state.error" type="error" style="margin-bottom: 12px;">
      {{ state.error }}
    </n-alert>

    <template v-else>
      <n-skeleton v-if="state.loading && !state.notifications.length" text :repeat="4" />

      <n-empty v-else-if="!state.loading && !hasNotifications"
        description="No notifications have been sent for this audience yet." />

      <template v-else>
        <n-data-table size="small" :columns="columns" :data="state.notifications" :bordered="false"
          :loading="state.loading" :row-key="(row) => row.id" :pagination="false" />
        <n-space justify="flex-end" style="margin-top: 16px;">
          <n-pagination v-model:page="page" :page-count="pageCount" :page-size="state.meta.limit" simple size="small"
            :disabled="state.loading" />
        </n-space>
      </template>
    </template>
  </n-card>

  <n-modal v-model:show="detailState.show" preset="card" title="Notification details"
    :style="{ maxWidth: '600px', width: '100%' }" size="small">
    <n-space vertical size="large">
      <div>
        <n-text depth="3">Recipient</n-text>
        <div class="detail-recipient">
          <strong>{{ detailState.notification?.recipient?.name ?? `User #${detailState.notification?.recipientId ?? ''}`
            }}</strong>
          <n-text v-if="detailState.notification?.recipient?.email" depth="3">
            {{ detailState.notification.recipient.email }}
          </n-text>
          <n-tag size="tiny" :bordered="false" type="info">
            {{ detailState.notification?.recipientType }}
          </n-tag>
        </div>
      </div>

      <div>
        <n-text strong>Sent at</n-text>
        <div>{{ formatTimestamp(detailState.notification?.createdAt) }}</div>
      </div>

      <div>
        <n-text strong>Message</n-text>
        <div class="detail-message">
          {{ detailState.notification?.message || 'No additional details provided.' }}
        </div>
      </div>
    </n-space>

    <template #footer>
      <n-space justify="end">
        <n-button tertiary @click="closeDetails">Close</n-button>
      </n-space>
    </template>
  </n-modal>
</template>

<style scoped>
.detail-recipient {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 6px;
}

.detail-message {
  margin-top: 10px;
  white-space: pre-wrap;
}
</style>
