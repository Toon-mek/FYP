<script setup>
import { computed, reactive, ref, watch, nextTick, onBeforeUnmount } from 'vue'
import {
  NAlert,
  NAvatar,
  NButton,
  NDescriptions,
  NDescriptionsItem,
  NDrawer,
  NDrawerContent,
  NEmpty,
  NIcon,
  NInput,
  NScrollbar,
  NSpin,
  NSpace,
  NTag,
  NText,
  useMessage,
  useNotification,
} from 'naive-ui'
import { extractProfileImage } from '../utils/profileImage.js'

const props = defineProps({
    currentUser: {
        type: Object,
        default: () => ({}),
    },
})

const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const MESSAGES_ENDPOINT = `${API_BASE}/messages.php`
const MALAYSIA_TIME_ZONE = 'Asia/Kuala_Lumpur'
const MALAYSIA_LOCALE = 'en-MY'
const MALAYSIA_FORMAT = new Intl.DateTimeFormat(MALAYSIA_LOCALE, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: MALAYSIA_TIME_ZONE,
})
const CONVERSATION_POLL_INTERVAL_MS = 3000

const message = useMessage()
const notification = useNotification()

const viewer = computed(() => {
    const source = props.currentUser ?? {}
    const profile = extractProfileImage(source)
    const name = source.fullName || source.displayName || source.username || 'Operator'
    return {
        id:
            source.id ??
            source.operatorID ??
            source.operatorId ??
            source.userId ??
            null,
        type: 'Operator',
        name,
        username: source.username ?? '',
        initials: computeInitialsFromName(name || source.username || 'Operator'),
        avatar: profile.url || source.avatarUrl || '',
    }
})

const threadsState = reactive({
    loading: false,
    error: '',
    items: [],
    search: '',
})

const conversationState = reactive({
    loading: false,
    sending: false,
    error: '',
    input: '',
    messages: [],
})

const travelerDetailsDrawer = reactive({
    visible: false,
    loading: false,
    data: null,
    error: '',
})

const activeThread = ref(null)
const conversationPaneRef = ref(null)
let conversationPollHandle = null
let conversationPollInFlight = false

const filteredThreads = computed(() => {
    if (!threadsState.search) {
        return threadsState.items
    }
    const q = threadsState.search.toLowerCase()
    return threadsState.items.filter((thread) =>
        [thread.participantName, thread.participantUsername]
            .filter(Boolean)
            .some((value) => value.toLowerCase().includes(q))
    )
})

watch(
    () => viewer.value.id,
    async (id) => {
        stopConversationPolling()
        threadsState.items = []
        activeThread.value = null
        conversationState.messages = []
        if (id) {
            await loadThreads({ preserveActive: false })
        }
    },
    { immediate: true }
)

watch(
  () => activeThread.value?.threadKey ?? null,
  async () => {
    stopConversationPolling()
    if (activeThread.value) {
      await loadConversation(activeThread.value)
      startConversationPolling()
      nextTick(() => scrollConversationToEnd())
    } else {
      conversationState.messages = []
    }
  }
)

watch(
  () => conversationState.messages.length,
  () => {
    if (!conversationState.loading && conversationPaneRef.value) {
      nextTick(() => scrollConversationToEnd())
    }
  }
)

function scrollConversationToEnd(behavior = 'auto') {
  if (conversationPaneRef.value?.scrollTo) {
    conversationPaneRef.value.scrollTo({ top: Number.MAX_SAFE_INTEGER, behavior })
  }
}

async function loadThreads(options = {}) {
    const { preserveActive = false, silent = false } = options
    const previousKey = preserveActive && activeThread.value ? activeThread.value.threadKey : null
    const id = viewer.value.id
    if (!id) {
        return
    }
    if (!silent) {
        threadsState.loading = true
    }
    threadsState.error = ''
    try {
        const params = new URLSearchParams({
            view: 'threads',
            currentType: viewer.value.type,
            currentId: String(id),
        })
        const response = await fetch(`${MESSAGES_ENDPOINT}?${params.toString()}`)
        const payload = await readJsonResponse(response, `Failed to load conversation list (${response.status})`)
        const rows = Array.isArray(payload?.threads) ? payload.threads : []
        const items = rows
            .map(normaliseThreadRow)
            .sort((a, b) => {
                const aTime = a.lastSentAt instanceof Date ? a.lastSentAt.getTime() : 0
                const bTime = b.lastSentAt instanceof Date ? b.lastSentAt.getTime() : 0
                return bTime - aTime
            })
        threadsState.items = items
        if (!items.length) {
            activeThread.value = null
            return
        }

        if (preserveActive && previousKey) {
            const matched = items.find((thread) => thread.threadKey === previousKey)
            if (matched) {
                activeThread.value = matched
            } else {
                activeThread.value = items[0]
            }
        } else if (!activeThread.value || !preserveActive) {
            activeThread.value = items[0]
        }
    } catch (error) {
        const messageText = error instanceof Error ? error.message : 'Unable to load conversations.'
        threadsState.error = messageText
        if (!silent) {
            message.error(messageText)
        } else {
            console.error(messageText)
        }
    } finally {
        if (!silent) {
            threadsState.loading = false
        }
    }
}

async function loadConversation(thread, options = {}) {
    const { silent = false, notifyOnNew = false } = options
    if (!thread) {
        return
    }
    const id = viewer.value.id
    if (!id) {
        return
    }
    if (!silent) {
        conversationState.loading = true
    }
    conversationState.error = ''
    const previousLastSignature = messageSignature(
        conversationState.messages[conversationState.messages.length - 1],
    )
    const hadPreviousMessages = conversationState.messages.length > 0
    try {
        const params = new URLSearchParams({
            currentType: viewer.value.type,
            currentId: String(id),
            participantType: thread.participantType,
            participantId: String(thread.participantId),
        })
        if (thread.postId != null) {
            params.set('postId', String(thread.postId))
        }
        const response = await fetch(`${MESSAGES_ENDPOINT}?${params.toString()}`)
        const payload = await readJsonResponse(response, `Failed to load messages (${response.status})`)
        const rows = Array.isArray(payload?.messages) ? payload.messages : []

        const listingFromMessages = rows.find((row) =>
            Number.isFinite(Number(row?.listingId ?? row?.listingID ?? NaN)) &&
            Number(row?.listingId ?? row?.listingID ?? 0) > 0,
        )
        const listingIdValue = listingFromMessages
            ? Number(listingFromMessages.listingId ?? listingFromMessages.listingID)
            : null

        if (listingIdValue && listingIdValue > 0) {
            thread.listingId = listingIdValue
        }

        const normalisedMessages = rows.map((item, index) => normaliseConversationMessage(item, index))
        const latestMessage = normalisedMessages[normalisedMessages.length - 1]
        const latestSignature = messageSignature(latestMessage)
        const incomingMessage =
            notifyOnNew &&
            hadPreviousMessages &&
            latestMessage &&
            latestSignature &&
            latestSignature !== previousLastSignature &&
            !isOwnMessage(latestMessage)
        conversationState.messages = normalisedMessages
        if (incomingMessage) {
            announceIncomingMessage(latestMessage, thread)
        }

        const idx = threadsState.items.findIndex((item) => item.threadKey === thread.threadKey)
        if (idx >= 0) {
            const updatedThread = {
                ...threadsState.items[idx],
                unreadCount: 0,
                listingId:
                    thread.listingId ?? listingIdValue ?? threadsState.items[idx].listingId ?? null,
            }
            threadsState.items.splice(idx, 1, updatedThread)
            if (activeThread.value?.threadKey === thread.threadKey) {
                activeThread.value = updatedThread
            }
        }

    } catch (error) {
        const messageText = error instanceof Error ? error.message : 'Unable to load messages.'
        conversationState.error = messageText
        if (!silent) {
            message.error(messageText)
        } else {
            console.error(messageText)
        }
    } finally {
        if (!silent) {
            conversationState.loading = false
        }
    }
}

async function sendMessage() {
    if (!activeThread.value) {
        return
    }
    stopConversationPolling()
    const viewerId = viewer.value.id
    if (!viewerId) {
        message.error('We could not determine your operator account. Please sign in again.')
        return
    }
    const trimmed = conversationState.input.trim()
    if (!trimmed) {
        message.warning('Enter a message before sending.')
        return
    }
    conversationState.sending = true
    conversationState.error = ''

    const payload = {
        senderType: viewer.value.type,
        senderID: viewerId,
        receiverType: activeThread.value.participantType,
        receiverID: activeThread.value.participantId,
        listingID: activeThread.value.listingId ?? null,
        postID: activeThread.value.postId ?? null,
        content: trimmed,
    }

    try {
        const response = await fetch(MESSAGES_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        const data = await readJsonResponse(response, `Failed to send message (${response.status})`)
        const saved = data?.message ?? {}
        const serverTimestamp = saved.sentAt ?? saved.sent_at ?? null
        const messageRecord = normaliseConversationMessage(
            {
                ...payload,
                listingId: payload.listingID,
                id: saved.id ?? saved.messageId ?? null,
                messageID: saved.id ?? saved.messageId ?? null,
                sentAt: serverTimestamp ?? new Date().toISOString(),
            },
            conversationState.messages.length
        )
        conversationState.messages.push(messageRecord)
        conversationState.input = ''
        updateThreadPreviewAfterSend(activeThread.value, messageRecord)
        await nextTick()
        await loadThreads({ preserveActive: true })
        if (activeThread.value) {
            await loadConversation(activeThread.value)
        }
        startConversationPolling()
    } catch (error) {
        const messageText = error instanceof Error ? error.message : 'Unable to send message.'
        conversationState.error = messageText
        message.error(messageText)
    } finally {
        conversationState.sending = false
        if (activeThread.value) {
            startConversationPolling()
        }
    }
}

function updateThreadPreviewAfterSend(thread, messageRecord) {
    const idx = threadsState.items.findIndex((item) => item.threadKey === thread.threadKey)
    if (idx >= 0) {
        const updated = {
            ...threadsState.items[idx],
            lastMessage: messageRecord.content,
            lastMessageSenderType: messageRecord.senderType,
            lastMessageSenderId: messageRecord.senderId,
            rawLastSentAt: messageRecord.rawSentAt ?? messageRecord.sentAt ?? null,
            lastSentAt: normalizeTimestamp(messageRecord.rawSentAt ?? messageRecord.sentAt ?? null),
            unreadCount: threadsState.items[idx].participantId === thread.participantId ? threadsState.items[idx].unreadCount : 0,
        }
        threadsState.items.splice(idx, 1, updated)
        activeThread.value = threadsState.items[0]
    }
}

function selectThread(thread) {
    if (!thread) return
    activeThread.value = thread
}

function handleComposerEnter(event) {
    if (event.shiftKey) {
        return
    }
    event.preventDefault()
    if (!conversationState.sending && conversationState.input.trim()) {
        sendMessage()
    }
}

function resolveThreadSenderLabel(thread) {
    const viewerId = viewer.value.id
    if (
        thread.lastMessageSenderType &&
        thread.lastMessageSenderType === viewer.value.type &&
        Number(thread.lastMessageSenderId ?? 0) === Number(viewerId ?? -1)
    ) {
        return 'You'
    }
    return thread.participantName || 'They'
}

function normaliseThreadRow(row) {
    const participantType = normaliseMessageAccountType(row?.participantType ?? row?.participant_type ?? '')
    const participantId = Number(row?.participantId ?? row?.participantID ?? row?.participant_id ?? 0)
    const profile = extractProfileImage({
        avatarUrl: row?.avatar ?? '',
        profileImage: row?.avatarRelative ?? '',
    })
    const name = row?.participantName || row?.name || 'Traveler'
    return {
        threadKey: `${participantType}-${participantId}`,
        participantId,
        participantType,
        participantName: name,
        participantUsername: row?.participantUsername || row?.username || '',
        avatar: row?.avatar || profile.url || '',
        avatarFallback: profile.relative || '',
        postId: row?.postId ?? row?.postID ?? null,
        lastMessage: row?.lastMessage || '',
        lastMessageSenderType: normaliseMessageAccountType(row?.lastMessageSenderType ?? ''),
        lastMessageSenderId: Number(row?.lastMessageSenderId ?? row?.lastMessageSenderID ?? 0),
        rawLastSentAt: row?.lastSentAt ?? row?.last_sent_at ?? null,
        lastSentAt: normalizeTimestamp(row?.lastSentAt ?? row?.last_sent_at ?? null),
        unreadCount: Number(row?.unreadCount ?? 0),
    }
}

function normaliseConversationMessage(row, index = 0) {
    const senderType = normaliseMessageAccountType(row?.senderType ?? row?.sender_type ?? '')
    const receiverType = normaliseMessageAccountType(row?.receiverType ?? row?.receiver_type ?? '')
    const sentAt = row?.sentAt ?? row?.sent_at ?? null

    return {
        id: row?.id ?? row?.messageID ?? index,
        senderType,
        senderId: Number(row?.senderId ?? row?.senderID ?? 0),
        receiverType,
        receiverId: Number(row?.receiverId ?? row?.receiverID ?? 0),
        content: row?.content ?? '',
        rawSentAt: sentAt,
        sentAt: normalizeTimestamp(sentAt),
        isRead: Boolean(row?.isRead ?? row?.is_read ?? false),
        listingId: row?.listingId ?? row?.listingID ?? null,
        postId: row?.postId ?? row?.postID ?? null,
    }
}

function computeInitialsFromName(value) {
    if (!value) {
        return 'OP'
    }
    const letters = String(value)
        .split(/\s+/)
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
    return letters ? letters.toUpperCase() : 'OP'
}

function normaliseMessageAccountType(value) {
    switch (String(value ?? '').toLowerCase()) {
        case 'traveler':
        case 'traveller':
            return 'Traveler'
        case 'operator':
        case 'business':
        case 'tourismoperator':
            return 'Operator'
        case 'admin':
        case 'administrator':
            return 'Admin'
        default:
            return ''
    }
}

function normalizeTimestamp(value) {
    if (value instanceof Date) {
        const ts = value.getTime()
        return Number.isNaN(ts) ? null : value
    }
    if (typeof value === 'number') {
        const date = new Date(value)
        return Number.isNaN(date.getTime()) ? null : date
    }
    if (typeof value === 'string') {
        const trimmed = value.trim()
        if (!trimmed) {
            return null
        }

        const formattedMatch = trimmed.match(
            /^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4}),\s*(\d{1,2}):(\d{2})\s*(am|pm)$/i
        )
        if (formattedMatch) {
            const [, dayStr, monthName, yearStr, hourStr, minuteStr, meridiemRaw] = formattedMatch
            const monthIndex = monthNameToIndex(monthName)
            if (monthIndex !== -1) {
                let hours = Number(hourStr)
                const minutes = Number(minuteStr)
                const meridiem = meridiemRaw.toLowerCase()
                if (meridiem === 'pm' && hours !== 12) {
                    hours += 12
                }
                if (meridiem === 'am' && hours === 12) {
                    hours = 0
                }
                const utcDate = new Date(Date.UTC(Number(yearStr), monthIndex, Number(dayStr), hours, minutes))
                if (!Number.isNaN(utcDate.getTime())) {
                    return utcDate
                }
            }
        }

        const numericMatch = trimmed.match(
            /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/
        )
        if (numericMatch) {
            const [, year, month, day, hour = '00', minute = '00', second = '00'] = numericMatch
            const utcDate = new Date(
                Date.UTC(
                    Number(year),
                    Number(month) - 1,
                    Number(day),
                    Number(hour),
                    Number(minute),
                    Number(second)
                )
            )
            if (!Number.isNaN(utcDate.getTime())) {
                return utcDate
            }
        }

        const withoutCommas = trimmed.replace(/,/g, '')
        const appended = withoutCommas.endsWith('Z') || /[+-]\d{2}:?\d{2}$/.test(withoutCommas)
            ? withoutCommas
            : `${withoutCommas.replace(/\s+/g, 'T')}Z`
        const fallback = new Date(appended)
        if (!Number.isNaN(fallback.getTime())) {
            return fallback
        }
    }

    return null
}

function monthNameToIndex(label) {
    const normalized = label.slice(0, 3).toLowerCase()
    switch (normalized) {
        case 'jan':
            return 0
        case 'feb':
            return 1
        case 'mar':
            return 2
        case 'apr':
            return 3
        case 'may':
            return 4
        case 'jun':
            return 5
        case 'jul':
            return 6
        case 'aug':
            return 7
        case 'sep':
            return 8
        case 'oct':
            return 9
        case 'nov':
            return 10
        case 'dec':
            return 11
        default:
            return -1
    }
}

function formatMessageTimestamp(value, fallback = null) {
    if (typeof fallback === 'string' && fallback.trim()) {
        return fallback.trim()
    }
    if (value === null || value === undefined) {
        return ''
    }
    if (typeof value === 'string' && value.trim()) {
        return value.trim()
    }
    const date = normalizeTimestamp(value)
    if (!date) {
        return value ? String(value) : ''
    }
    return MALAYSIA_FORMAT.format(date)
}

async function readJsonResponse(response, fallbackMessage) {
    const text = await response.text()
    if (!response.ok) {
        try {
            const payload = JSON.parse(text)
            throw new Error(payload?.error ?? fallbackMessage)
        } catch (error) {
            throw new Error(text ? text.slice(0, 200) : fallbackMessage)
        }
    }
    try {
        return JSON.parse(text)
    } catch (error) {
        throw new Error(text ? text.slice(0, 200) : 'Invalid JSON response from server.')
    }
}

function isOwnMessage(entry) {
    const viewerId = viewer.value.id
    if (!viewerId) {
        return false
    }
    return entry.senderType === viewer.value.type && Number(entry.senderId) === Number(viewerId)
}

function messageSignature(entry) {
    if (!entry) return ''
    const baseId =
        entry.id ??
        entry.messageId ??
        entry.messageID ??
        entry.clientId ??
        entry.clientID ??
        entry.tempId ??
        ''
    const stamp = entry.rawSentAt ?? entry.sentAt ?? ''
    return `${baseId}-${stamp}`
}

function summariseMessageContent(content) {
    if (!content) {
        return 'Open the conversation to reply.'
    }
    const text = String(content).trim()
    if (!text) {
        return 'Open the conversation to reply.'
    }
    return text.length > 160 ? `${text.slice(0, 157)}...` : text
}

function announceIncomingMessage(messageEntry, thread) {
    if (!notification || !messageEntry) {
        return
    }
    notification.info({
        title: thread?.participantName || 'New message',
        content: summariseMessageContent(messageEntry.content),
        meta: formatMessageTimestamp(messageEntry.rawSentAt ?? messageEntry.sentAt ?? null),
        duration: 5000,
        keepAliveOnHover: true,
    })
}

function startConversationPolling() {
    stopConversationPolling()
    if (!activeThread.value) {
        return
    }
    conversationPollHandle = setInterval(async () => {
        if (!activeThread.value) {
            stopConversationPolling()
            return
        }
        if (conversationState.loading || conversationState.sending || conversationPollInFlight) {
            return
        }
        conversationPollInFlight = true
        try {
            await loadThreads({ preserveActive: true, silent: true })
            if (activeThread.value) {
                await loadConversation(activeThread.value, { silent: true, notifyOnNew: true })
            }
        } catch (error) {
            console.error('Failed to poll conversation', error)
        } finally {
            conversationPollInFlight = false
        }
    }, CONVERSATION_POLL_INTERVAL_MS)
}

function stopConversationPolling() {
    if (conversationPollHandle) {
        clearInterval(conversationPollHandle)
        conversationPollHandle = null
    }
    conversationPollInFlight = false
}

async function openTravelerDetails() {
    if (!activeThread.value || activeThread.value.participantType !== 'Traveler') {
        message.warning('This conversation is not with a traveler.')
        return
    }

    travelerDetailsDrawer.visible = true
    travelerDetailsDrawer.loading = true
    travelerDetailsDrawer.error = ''
    travelerDetailsDrawer.data = null

    try {
        const response = await fetch(`${API_BASE}/traveler/profile.php?id=${activeThread.value.participantId}`)
        if (!response.ok) {
            throw new Error(`Failed to fetch traveler details (${response.status})`)
        }
        const data = await response.json()
        if (data.error) {
            throw new Error(data.error)
        }
        const payload = data.traveler || data || {}
        const threadAvatar = activeThread.value?.avatar || activeThread.value?.participantAvatar || null
        travelerDetailsDrawer.data = {
            ...payload,
            avatarUrl:
                payload.profileImageUrl ||
                payload.profileImage ||
                payload.avatarUrl ||
                threadAvatar ||
                null,
            contactNumber: payload.contactNumber ?? payload.phoneNumber ?? payload.phone ?? '',
        }
    } catch (error) {
        travelerDetailsDrawer.error = error instanceof Error ? error.message : 'Failed to load traveler details'
        message.error(travelerDetailsDrawer.error)
    } finally {
        travelerDetailsDrawer.loading = false
    }
}

function closeTravelerDetails() {
    travelerDetailsDrawer.visible = false
}

onBeforeUnmount(() => {
    stopConversationPolling()
})
</script>

<template>
    <div class="messages-layout">
        <aside class="messages-sidebar">
            <header class="messages-sidebar__header">
                <div class="messages-sidebar__title">Messages</div>
                <n-text depth="3">Reach out to travelers and collaborators.</n-text>
            </header>

            <n-input v-model:value="threadsState.search" placeholder="Search by name or username" size="small" round
                clearable class="messages-sidebar__search">
                <template #prefix>
                    <n-icon size="16">
                        <i class="ri-search-2-line" />
                    </n-icon>
                </template>
            </n-input>

            <n-alert v-if="threadsState.error" type="error" closable @close="threadsState.error = ''">
                {{ threadsState.error }}
            </n-alert>

            <n-spin :show="threadsState.loading">
                <div class="messages-thread-list">
                    <template v-if="filteredThreads.length">
                        <button v-for="thread in filteredThreads" :key="thread.threadKey" :class="[
                            'messages-thread',
                            { 'messages-thread--active': activeThread?.threadKey === thread.threadKey },
                        ]" @click="selectThread(thread)">
                            <n-avatar round size="medium" :src="thread.avatar || undefined"
                                class="messages-thread__avatar">
                                <template v-if="!thread.avatar">
                                    {{ computeInitialsFromName(thread.participantName || thread.participantUsername ||
                                    'OP') }}
                                </template>
                            </n-avatar>
                            <div class="messages-thread__body">
                                <div class="messages-thread__top">
                                    <span class="messages-thread__name">{{ thread.participantName }}</span>
                                    <span class="messages-thread__time">
                                        {{ formatMessageTimestamp(thread.lastSentAt, thread.rawLastSentAt) }}
                                    </span>
                                </div>
                                <div class="messages-thread__preview">
                                    <span class="messages-thread__preview-self">
                                        {{ resolveThreadSenderLabel(thread) }}:
                                    </span>
                                    <span>{{ thread.lastMessage || 'Start the conversation' }}</span>
                                </div>
                            </div>
                            <n-tag v-if="thread.unreadCount > 0" type="success" size="small" round bordered>
                                {{ thread.unreadCount }}
                            </n-tag>
                        </button>
                    </template>
                    <n-empty v-else description="No conversations yet. Start with a community story." />
                </div>
            </n-spin>
        </aside>

        <section class="messages-content">
            <template v-if="activeThread">
                <header class="messages-content__header">
                    <n-space align="center" size="small" style="flex: 1;">
                        <n-avatar round size="large" :src="activeThread.avatar || undefined">
                            <template v-if="!activeThread.avatar">
                                {{ computeInitialsFromName(activeThread.participantName ||
                                    activeThread.participantUsername || 'OP') }}
                            </template>
                        </n-avatar>
              <div class="messages-content__identity">
                <div class="messages-content__name">
                  {{ activeThread.participantName }}
                </div>
              </div>
            </n-space>
                    <n-button v-if="activeThread.participantType === 'Traveler'" 
                        text @click="openTravelerDetails" style="padding: 8px;">
                        <template #icon>
                            <n-icon size="20">
                                <i class="ri-user-line" />
                            </n-icon>
                        </template>
                    </n-button>
                </header>

                <section class="messages-content__body">
                    <n-scrollbar ref="conversationPaneRef" style="height: 100%;">
                        <n-spin :show="conversationState.loading" style="padding: 40px;">
                            <div style="padding: 16px; display: flex; flex-direction: column; gap: 8px;">
                                <template v-if="conversationState.messages.length">
                                    <div v-for="message in conversationState.messages"
                                        :key="message.id ?? `${message.sentAt}-${message.senderId}`"
                                        :style="{
                                            display: 'flex',
                                            flexDirection: 'column',
                                            alignItems: isOwnMessage(message) ? 'flex-end' : 'flex-start',
                                            width: '100%'
                                        }">
                                        <div :style="{
                                            maxWidth: '60%',
                                            background: isOwnMessage(message) ? '#d1f4e0' : '#f1f3f4',
                                            padding: '8px 12px',
                                            borderRadius: '18px',
                                            wordBreak: 'break-word'
                                        }">
                                            {{ message.content }}
                                        </div>
                                        <div style="font-size: 11px; color: #5f6368; margin-top: 2px; padding: 0 4px;">
                                            {{ formatMessageTimestamp(message.sentAt, message.rawSentAt) }}
                                        </div>
                                    </div>
                                </template>
                                <n-empty v-else description="No messages yet. Say hello to start collaborating." />
                            </div>
                        </n-spin>
                    </n-scrollbar>
                </section>

                <footer class="messages-content__composer">
                    <n-alert v-if="conversationState.error" type="error" closable @close="conversationState.error = ''">
                        {{ conversationState.error }}
                    </n-alert>
                    <div class="composer-input-row">
                        <n-input v-model:value="conversationState.input" type="textarea"
                            :autosize="{ minRows: 2, maxRows: 4 }" maxlength="2000" show-count
                            placeholder="Message..." @keyup.enter.prevent="handleComposerEnter" />
                        <n-button type="primary" class="composer-send-button" :disabled="!conversationState.input.trim()"
                            :loading="conversationState.sending" @click="sendMessage">
                            Send
                        </n-button>
                    </div>
                </footer>
            </template>

            <template v-else>
                <div class="messages-content__placeholder">
                    <n-empty description="Select a conversation to view details" />
                </div>
            </template>
        </section>
    </div>

    <n-drawer v-model:show="travelerDetailsDrawer.visible" :width="400" placement="right">
        <n-drawer-content title="Traveler Details" closable>
            <n-spin :show="travelerDetailsDrawer.loading">
                <template v-if="travelerDetailsDrawer.error">
                    <n-alert type="error" :title="travelerDetailsDrawer.error" />
                </template>
                <template v-else-if="travelerDetailsDrawer.data">
                    <n-space vertical size="large">
                        <n-space vertical align="center" style="width: 100%;">
                            <n-avatar round :size="80" :src="travelerDetailsDrawer.data.avatarUrl || undefined">
                                <template v-if="!travelerDetailsDrawer.data.avatarUrl">
                                    {{ computeInitialsFromName(travelerDetailsDrawer.data.fullName || travelerDetailsDrawer.data.username || 'TR') }}
                                </template>
                            </n-avatar>
                            <div style="text-align: center;">
                                <div style="font-size: 1.2rem; font-weight: 600;">
                                    {{ travelerDetailsDrawer.data.fullName || travelerDetailsDrawer.data.username }}
                                </div>
                                <n-text depth="3" v-if="travelerDetailsDrawer.data.username">
                                    @{{ travelerDetailsDrawer.data.username }}
                                </n-text>
                            </div>
                        </n-space>

                        <n-descriptions bordered :column="1" size="small">
                            <n-descriptions-item label="Email" v-if="travelerDetailsDrawer.data.email">
                                {{ travelerDetailsDrawer.data.email }}
                            </n-descriptions-item>
                            <n-descriptions-item label="Phone" v-if="travelerDetailsDrawer.data.contactNumber || travelerDetailsDrawer.data.phoneNumber">
                                {{ travelerDetailsDrawer.data.contactNumber || travelerDetailsDrawer.data.phoneNumber }}
                            </n-descriptions-item>
                        </n-descriptions>
                    </n-space>
                </template>
            </n-spin>
        </n-drawer-content>
    </n-drawer>
</template>

<style scoped>
.messages-layout {
    display: flex;
    height: clamp(600px, 75vh, 850px);
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
    overflow: hidden;
    border: 1px solid rgba(15, 23, 42, 0.05);
}

.messages-sidebar {
    width: 420px;
    background: linear-gradient(180deg, #f5fbf8 0%, #fefefe 100%);
    border-right: 1px solid rgba(15, 23, 42, 0.05);
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 20px;
}

.messages-sidebar__header {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.messages-sidebar__title {
    font-size: 1.2rem;
    font-weight: 600;
    color: #14532d;
}

.messages-sidebar__search {
    width: 100%;
}

.messages-thread-list {
    flex: 1;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.messages-thread-list :deep(.n-empty) {
    margin-top: 60px;
}

.messages-thread {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 10px;
    border-radius: 12px;
    border: none;
    background: transparent;
    cursor: pointer;
    text-align: left;
    transition: background 0.2s ease;
    width: 100%;
}

.messages-thread:hover,
.messages-thread--active {
    background: rgba(24, 160, 88, 0.08);
}

.messages-thread__avatar {
    flex-shrink: 0;
}

.messages-thread__body {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.messages-thread__top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 6px;
    font-size: 0.84rem;
    color: #475569;
}

.messages-thread__name {
    font-weight: 600;
    color: #1f2937;
}

.messages-thread__time {
    font-size: 0.75rem;
}

.messages-thread__preview {
    font-size: 0.78rem;
    color: rgba(15, 23, 42, 0.6);
    display: flex;
    gap: 4px;
}

.messages-thread__preview-self {
    font-weight: 600;
}

.messages-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: #fff;
    min-height: 0;
}

.messages-content__header {
    padding: 20px 24px;
    border-bottom: 1px solid rgba(15, 23, 42, 0.05);
    background: rgba(248, 252, 251, 0.7);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.messages-content__identity {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.messages-content__name {
    font-size: 1.1rem;
    font-weight: 600;
    color: #0f172a;
}

.messages-content__body {
    flex: 1;
    min-height: 0;
}

.messages-content__composer {
    padding: 18px 24px 20px;
    border-top: 1px solid rgba(15, 23, 42, 0.05);
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.composer-input-row {
    display: flex;
    gap: 12px;
    align-items: center;
}

.composer-input-row :deep(.n-input) {
    flex: 1;
}

.composer-send-button {
    min-width: 96px;
    height: 44px;
    border-radius: 99px;
    font-weight: 600;
    box-shadow: 0 8px 18px rgba(24, 160, 88, 0.25);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    padding: 0 24px;
}

.composer-send-button :deep(.n-button__content) {
    gap: 8px;
}

.composer-send-button:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 22px rgba(24, 160, 88, 0.3);
}

.composer-send-button:active {
    transform: translateY(0);
    box-shadow: 0 6px 14px rgba(24, 160, 88, 0.25);
}

.messages-content__placeholder {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
}

@media (max-width: 960px) {
    .messages-layout {
        flex-direction: column;
        height: auto;
    }

    .messages-sidebar {
        width: 100%;
        border-right: none;
        border-bottom: 1px solid rgba(15, 23, 42, 0.05);
    }
}
</style>
