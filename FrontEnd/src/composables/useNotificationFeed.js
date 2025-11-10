import {
  computed,
  onBeforeUnmount,
  onMounted,
  ref,
  unref,
  watch,
} from 'vue'
import { useNotification } from 'naive-ui'

export const notificationFeedSymbol = Symbol('notificationFeed')

function toInt(value) {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? Math.trunc(numeric) : null
}

function normaliseRecipientType(value) {
  if (!value || typeof value !== 'string') {
    return ''
  }
  return value.trim()
}

function buildToastContent(message) {
  if (!message || typeof message !== 'string') {
    return 'Open your notification inbox to read the full message.'
  }
  const trimmed = message.trim()
  if (trimmed.length <= 160) {
    return trimmed === '' ? 'Open your notification inbox to read the full message.' : trimmed
  }
  return `${trimmed.slice(0, 157)}...`
}

export function useNotificationFeed(options = {}) {
  const announceEnabled = options.announce !== false
  const notificationApi = announceEnabled ? useNotification() : null

  const listLimit = ref(options.listLimit ?? 25)
  const autoFetch = ref(options.autoFetch !== false)
  const pollIntervalMs = ref(options.pollInterval ?? null)

  const recipientType = ref(normaliseRecipientType(unref(options.recipientType)))
  const recipientId = ref(toInt(unref(options.recipientId)))

  const notifications = ref([])
  const unreadCount = ref(0)
  const loading = ref(false)
  const refreshing = ref(false)
  const error = ref('')
  const lastFetchedAt = ref(null)

  const canLoad = computed(
    () =>
      !!recipientType.value &&
      recipientId.value !== null &&
      Number.isInteger(recipientId.value) &&
      recipientId.value > 0,
  )

  const announcedIds = new Set()
  let pollHandle = null

  function clearFeed() {
    notifications.value = []
    unreadCount.value = 0
    error.value = ''
    announcedIds.clear()
  }

  async function loadNotifications() {
    if (!canLoad.value) {
      clearFeed()
      loading.value = false
      refreshing.value = false
      return
    }
    if (loading.value) {
      return
    }

    loading.value = true
    error.value = ''

    const params = new URLSearchParams({
      recipientType: recipientType.value,
      recipientId: String(recipientId.value),
      limit: String(listLimit.value ?? 25),
    })

    try {
      const response = await fetch(`${import.meta.env.VITE_API_BASE || '/api'}/notifications.php?${params.toString()}`, {
        method: 'GET',
      })

      if (!response.ok) {
        throw new Error('Unable to load notifications right now.')
      }

      const body = await response.json().catch(() => ({}))
      let list = Array.isArray(body.notifications) ? body.notifications : []
      
      // Filter out "Announcement Created" notifications for admins
      if (recipientType.value === 'Admin') {
        list = list.filter((item) => {
          const title = String(item?.title || '').toLowerCase()
          return !title.includes('announcement created')
        })
      }
      
      notifications.value = list
      unreadCount.value = Number(body?.meta?.unreadCount ?? 0) || 0
      lastFetchedAt.value = new Date()

      if (announceEnabled && notificationApi) {
        const fresh = []
        list.forEach((item) => {
          const id = toInt(item?.id)
          if (!Number.isInteger(id) || id <= 0) {
            return
          }
          if (!announcedIds.has(id)) {
            announcedIds.add(id)
            if (item?.isRead === false) {
              fresh.push(item)
            }
          }
        })
        fresh.forEach((item) => {
          notificationApi.info({
            title: item?.title || 'New notification',
            content: buildToastContent(item?.message),
            duration: 6000,
            keepAliveOnHover: true,
            meta: item?.createdAt || undefined,
          })
        })
      }
    } catch (err) {
      console.error(err)
      error.value =
        err instanceof Error ? err.message : 'Something went wrong while loading notifications.'
      notifications.value = []
    } finally {
      loading.value = false
      refreshing.value = false
    }
  }

  async function refreshNotifications() {
    if (refreshing.value) {
      return
    }
    refreshing.value = true
    await loadNotifications()
  }

  function applyLocalRead(idList) {
    const idSet = new Set(idList.map((value) => toInt(value)).filter((value) => Number.isInteger(value) && value > 0))
    if (!idSet.size) {
      return
    }
    notifications.value = notifications.value.map((item) => {
      const id = toInt(item?.id)
      if (idSet.has(id)) {
        return { ...item, isRead: true }
      }
      return item
    })
  }

  async function markNotificationsRead(idList) {
    if (!canLoad.value) {
      return 0
    }
    const cleanIds = Array.isArray(idList) ? idList : [idList]
    const filtered = cleanIds
      .map((value) => toInt(value))
      .filter((value) => Number.isInteger(value) && value > 0)

    if (!filtered.length) {
      return 0
    }

    const payload = {
      action: 'mark-read',
      recipientType: recipientType.value,
      recipientId: recipientId.value,
      notificationIds: filtered,
    }

    const response = await fetch(`${import.meta.env.VITE_API_BASE || '/api'}/notifications.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })

    if (!response.ok) {
      const body = await response.json().catch(() => ({}))
      const errorMessage = body?.error || 'Unable to update notification status.'
      throw new Error(errorMessage)
    }

    const body = await response.json().catch(() => ({}))
    applyLocalRead(filtered)
    if (body?.meta?.unreadCount !== undefined) {
      unreadCount.value = Number(body.meta.unreadCount) || 0
    } else {
      unreadCount.value = Math.max(0, unreadCount.value - filtered.length)
    }
    return filtered.length
  }

  function setRecipient(type, id) {
    const normalisedType = normaliseRecipientType(unref(type))
    const normalisedId = toInt(unref(id))
    const typeChanged = normalisedType !== recipientType.value
    const idChanged = normalisedId !== recipientId.value

    if (!typeChanged && !idChanged) {
      return
    }

    recipientType.value = normalisedType
    recipientId.value = normalisedId
    clearFeed()
    if (autoFetch.value) {
      loadNotifications()
    }
  }

function resetPolling() {
  if (typeof window === 'undefined') {
    return
  }
  if (pollHandle) {
    window.clearInterval(pollHandle)
    pollHandle = null
  }
  const ms = toInt(pollIntervalMs.value)
  if (autoFetch.value && ms && ms > 0) {
    pollHandle = window.setInterval(() => {
      refreshNotifications()
    }, ms)
  }
  }

  if (autoFetch.value) {
    watch(
      () => [unref(options.recipientType), unref(options.recipientId)],
      ([nextType, nextId]) => {
        setRecipient(nextType, nextId)
      },
      { immediate: true },
    )
  } else {
    watch(
      () => [unref(options.recipientType), unref(options.recipientId)],
      ([nextType, nextId]) => {
        recipientType.value = normaliseRecipientType(nextType)
        recipientId.value = toInt(nextId)
      },
      { immediate: true },
    )
  }

  watch([autoFetch, pollIntervalMs], () => {
    if (typeof window !== 'undefined') {
      resetPolling()
    }
  })

  onMounted(() => {
    if (autoFetch.value) {
      loadNotifications()
    }
    if (typeof window !== 'undefined') {
      resetPolling()
    }
  })

  onBeforeUnmount(() => {
    if (pollHandle) {
      window.clearInterval(pollHandle)
      pollHandle = null
    }
  })

  return {
    notifications,
    unreadCount,
    loading,
    refreshing,
    error,
    lastFetchedAt,
    canLoad,
    loadNotifications,
    refreshNotifications,
    markNotificationsRead,
    setRecipient,
  }
}
