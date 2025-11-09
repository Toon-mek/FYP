<script setup>
import { computed, inject, reactive, ref, watch } from 'vue'
import {
  NAlert,
  NButton,
  NCard,
  NEmpty,
  NList,
  NListItem,
  NModal,
  NSkeleton,
  NSpace,
  NTag,
  NText,
  useMessage,
} from 'naive-ui'
import {
  notificationFeedSymbol,
  useNotificationFeed,
} from '../composables/useNotificationFeed.js'
import SimplePagination from './shared/SimplePagination.vue'

const LIST_LIMIT = 25

const props = defineProps({
  recipientType: {
    type: String,
    required: true,
  },
  recipientId: {
    type: Number,
    default: null,
  },
  title: {
    type: String,
    default: 'Notifications',
  },
  description: {
    type: String,
    default: 'Stay up to date with moderation decisions and account alerts.',
  },
})

const message = useMessage()
const injectedFeed = inject(notificationFeedSymbol, null)

const feed =
  injectedFeed ??
  useNotificationFeed({
    recipientType: computed(() => props.recipientType),
    recipientId: computed(() => props.recipientId),
    listLimit: LIST_LIMIT,
    announce: false,
    autoFetch: true,
  })

const {
  notifications,
  unreadCount,
  loading,
  refreshing,
  error,
  canLoad,
  refreshNotifications,
  markNotificationsRead,
  setRecipient,
} = feed

watch(
  () => [props.recipientType, props.recipientId],
  ([type, id]) => {
    setRecipient(type, id)
  },
  { immediate: true },
)

const preview = reactive({
  visible: false,
  notification: null,
  marking: false,
})

const hasNotifications = computed(() => notifications.value.length > 0)
const hasUnread = computed(() => notifications.value.some((item) => item.isRead === false))
const pageSize = 10
const currentPage = ref(1)
const paginatedNotifications = computed(() => {
  const start = (currentPage.value - 1) * pageSize
  return notifications.value.slice(start, start + pageSize)
})
watch(
  () => notifications.value.length,
  () => {
    const maxPage = Math.max(1, Math.ceil((notifications.value.length || 1) / pageSize))
    if (currentPage.value > maxPage) {
      currentPage.value = maxPage
    }
  },
)

const dateFormatter = new Intl.DateTimeFormat(undefined, {
  dateStyle: 'medium',
  timeStyle: 'short',
})

function formatTimestamp(value) {
  if (!value) {
    return '--'
  }
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return value
  }
  try {
    return dateFormatter.format(date)
  } catch {
    return date.toLocaleString()
  }
}

function openPreview(item) {
  preview.notification = { ...item }
  preview.visible = true
}

function closePreview() {
  preview.visible = false
  preview.notification = null
  preview.marking = false
}

async function handleMarkRead(ids) {
  try {
    await markNotificationsRead(ids)
  } catch (error) {
    console.error(error)
    message.error(error instanceof Error ? error.message : 'Failed to mark notification as read.')
  }
}

async function markPreviewRead() {
  if (!preview.notification || preview.notification.isRead) {
    closePreview()
    return
  }
  preview.marking = true
  try {
    await markNotificationsRead([preview.notification.id])
  } catch (error) {
    console.error(error)
    message.error(error instanceof Error ? error.message : 'Failed to mark notification as read.')
  } finally {
    preview.marking = false
    syncPreviewState()
  }
}

function syncPreviewState() {
  if (!preview.notification) {
    return
  }
  const updated = notifications.value.find(
    (item) => Number(item.id) === Number(preview.notification?.id ?? 0),
  )
  if (updated) {
    preview.notification = { ...updated }
  }
}

watch(
  notifications,
  () => {
    if (!preview.visible) {
      return
    }
    syncPreviewState()
  },
  { deep: true },
)
</script>

<template>
  <n-card :title="title" size="large" :segmented="{ content: true, footer: true }">
    <template #header-extra>
      <n-space align="center" :size="12">
        <n-tag v-if="unreadCount > 0" type="warning" size="small" bordered>
          {{ unreadCount }} new
        </n-tag>
        <n-button size="small" quaternary :loading="refreshing" @click="refreshNotifications">
          Refresh
        </n-button>
      </n-space>
    </template>

    <n-alert v-if="error" type="error" style="margin-bottom: 12px;">
      {{ error }}
    </n-alert>

    <n-alert v-else-if="!canLoad" type="info" style="margin-bottom: 12px;">
      Sign in to see your notifications.
    </n-alert>

    <template v-if="canLoad">
      <n-skeleton v-if="loading && !notifications.length" text :repeat="4" />

      <n-empty v-else-if="!loading && !hasNotifications" description="No notifications yet.">
        Use the dashboard actions to receive new updates here.
      </n-empty>

      <n-list v-else bordered :show-divider="false">
        <n-list-item
          v-for="item in paginatedNotifications"
          :key="item.id"
          class="notification-item-wrapper"
        >
          <div
            class="notification-card"
            :class="item.isRead === false ? 'notification-card--unread' : 'notification-card--read'"
            @click="openPreview(item)"
          >
            <n-space vertical size="small" style="width: 100%;">
              <n-space justify="space-between" align="center">
                <n-space align="center">
                  <n-text strong style="font-size: 1rem;">{{ item.title || 'Notification' }}</n-text>
                  <n-tag v-if="item.isRead === false" size="small" type="warning" bordered>New</n-tag>
                </n-space>
                <n-text depth="3" style="font-size: 0.85rem;">
                  {{ formatTimestamp(item.createdAt) }}
                </n-text>
              </n-space>
              <n-text :depth="item.isRead === false ? 2 : 3">
                {{ item.message || 'No additional details provided.' }}
              </n-text>
              <n-space v-if="item.isRead === false" justify="end">
                <n-button
                  size="tiny"
                  tertiary
                  type="primary"
                  @click.stop="handleMarkRead([item.id])"
                >
                  Mark as read
                </n-button>
              </n-space>
            </n-space>
          </div>
        </n-list-item>
      </n-list>
    </template>

    <template #footer>
      <n-space justify="space-between" align="center" style="width: 100%; flex-wrap: wrap; gap: 8px;">
        <n-text depth="3">{{ description }}</n-text>
        <n-space align="center" wrap>
          <SimplePagination
            v-if="hasNotifications"
            v-model:page="currentPage"
            :item-count="notifications.length"
            :page-size="pageSize"
          />
          <n-button
            size="small"
            tertiary
            type="primary"
            :disabled="!hasUnread"
            @click="handleMarkRead(notifications.filter((item) => item.isRead === false).map((item) => item.id))"
          >
            Mark all as read
          </n-button>
        </n-space>
      </n-space>
    </template>
  </n-card>

  <n-modal
    v-model:show="preview.visible"
    preset="card"
    :title="preview.notification?.title || 'Notification details'"
    style="max-width: 520px; width: 100%;"
  >
    <n-space vertical size="large">
      <n-space justify="space-between" align="center">
        <n-tag v-if="preview.notification?.isRead === false" type="warning" size="small" bordered>
          New
        </n-tag>
        <n-text depth="3">
          {{ formatTimestamp(preview.notification?.createdAt) }}
        </n-text>
      </n-space>
      <n-text depth="2">
        {{ preview.notification?.message || 'No additional details provided.' }}
      </n-text>
    </n-space>

    <template #footer>
      <n-space justify="end">
        <n-button quaternary @click="closePreview">Close</n-button>
        <n-button
          v-if="preview.notification && preview.notification.isRead === false"
          type="primary"
          size="small"
          :loading="preview.marking"
          @click="markPreviewRead"
        >
          Mark as read
        </n-button>
      </n-space>
    </template>
  </n-modal>
</template>

<style scoped>
.notification-item-wrapper {
  padding: 0 !important;
}

.notification-card {
  padding: 16px 20px;
  border-radius: 16px;
  border: 1px solid rgba(15, 23, 42, 0.05);
  background: #fff;
  cursor: pointer;
  transition:
    background-color 0.18s ease,
    border-color 0.18s ease,
    box-shadow 0.18s ease,
    transform 0.18s ease;
}

.notification-card--unread {
  background: linear-gradient(0deg, rgba(24, 160, 88, 0.08), rgba(24, 160, 88, 0.08)), #ffffff;
  border-color: rgba(24, 160, 88, 0.28);
  box-shadow: 0 10px 24px rgba(24, 160, 88, 0.12);
}

.notification-card--read {
  background: rgba(15, 23, 42, 0.04);
  border-color: rgba(15, 23, 42, 0.06);
  box-shadow: none;
}

.notification-card:hover {
  transform: translateY(-1px);
  border-color: rgba(24, 160, 88, 0.45);
  box-shadow: 0 12px 26px rgba(24, 160, 88, 0.18);
}

.notification-card--read:hover {
  background: rgba(15, 23, 42, 0.06);
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.12);
}
</style>
