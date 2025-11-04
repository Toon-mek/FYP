<script setup>
import { computed, reactive, ref, watch, nextTick } from 'vue'
import {
  NAlert,
  NAvatar,
  NButton,
  NEmpty,
  NIcon,
  NInput,
  NSpin,
  NSpace,
  NTag,
  useMessage,
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

const message = useMessage()

const viewer = computed(() => {
  const source = props.currentUser ?? {}
  const profile = extractProfileImage(source)
  const name = source.fullName || source.displayName || source.username || 'Traveler'
  return {
    id:
      source.id ??
      source.travelerID ??
      source.travelerId ??
      source.userId ??
      null,
    type: 'Traveler',
    name,
    username: source.username ?? '',
    initials: computeInitialsFromName(name || source.username || 'Traveler'),
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

const activeThread = ref(null)
const conversationPaneRef = ref(null)

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
  (id) => {
    if (id) {
      loadThreads()
    }
  },
  { immediate: true }
)

watch(
  () => activeThread.value?.threadKey ?? null,
  () => {
    if (activeThread.value) {
      loadConversation(activeThread.value)
    } else {
      conversationState.messages = []
    }
  }
)

watch(
  () => conversationState.messages.length,
  () => {
    scrollConversationToEnd()
  }
)

async function loadThreads() {
  const id = viewer.value.id
  if (!id) {
    return
  }
  threadsState.loading = true
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
    threadsState.items = rows.map(normaliseThreadRow)
    if (threadsState.items.length && !activeThread.value) {
      activeThread.value = threadsState.items[0]
    }
  } catch (error) {
    const messageText = error instanceof Error ? error.message : 'Unable to load conversations.'
    threadsState.error = messageText
    message.error(messageText)
  } finally {
    threadsState.loading = false
  }
}

async function loadConversation(thread) {
  if (!thread) {
    return
  }
  const id = viewer.value.id
  if (!id) {
    return
  }
  conversationState.loading = true
  conversationState.error = ''
  try {
    const params = new URLSearchParams({
      currentType: viewer.value.type,
      currentId: String(id),
      participantType: thread.participantType,
      participantId: String(thread.participantId),
    })
    const response = await fetch(`${MESSAGES_ENDPOINT}?${params.toString()}`)
    const payload = await readJsonResponse(response, `Failed to load messages (${response.status})`)
    const rows = Array.isArray(payload?.messages) ? payload.messages : []
    conversationState.messages = rows.map((item, index) => normaliseConversationMessage(item, index))

    const idx = threadsState.items.findIndex((item) => item.threadKey === thread.threadKey)
    if (idx >= 0) {
      const updated = { ...threadsState.items[idx], unreadCount: 0 }
      threadsState.items.splice(idx, 1, updated)
      if (activeThread.value?.threadKey === thread.threadKey) {
        activeThread.value = updated
      }
    }

    scrollConversationToEnd()
  } catch (error) {
    const messageText = error instanceof Error ? error.message : 'Unable to load messages.'
    conversationState.error = messageText
    message.error(messageText)
  } finally {
    conversationState.loading = false
  }
}

async function sendMessage() {
  if (!activeThread.value) {
    return
  }
  const viewerId = viewer.value.id
  if (!viewerId) {
    message.error('We could not determine your traveler account. Please sign in again.')
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
    const messageRecord = normaliseConversationMessage(
      {
        ...payload,
        id: saved.id ?? saved.messageId ?? null,
        messageID: saved.id ?? saved.messageId ?? null,
        sentAt: saved.sentAt ?? saved.sent_at ?? new Date().toISOString().replace('T', ' ').slice(0, 19),
      },
      conversationState.messages.length
    )
    conversationState.messages.push(messageRecord)
    conversationState.input = ''
    updateThreadPreviewAfterSend(activeThread.value, messageRecord)
    scrollConversationToEnd()
  } catch (error) {
    const messageText = error instanceof Error ? error.message : 'Unable to send message.'
    conversationState.error = messageText
    message.error(messageText)
  } finally {
    conversationState.sending = false
  }
}

function updateThreadPreviewAfterSend(thread, messageRecord) {
  const idx = threadsState.items.findIndex((item) => item.threadKey === thread.threadKey)
  if (idx >= 0) {
    const updated = {
      ...threadsState.items[idx],
      lastMessage: messageRecord.content,
      lastMessageSenderType: messageRecord.senderType,
      lastSentAt: messageRecord.sentAt,
      unreadCount: threadsState.items[idx].participantId === thread.participantId ? threadsState.items[idx].unreadCount : 0,
    }
    threadsState.items.splice(idx, 1, updated)
  }
}

function selectThread(thread) {
  activeThread.value = thread
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
    lastSentAt: row?.lastSentAt || null,
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
    sentAt,
    listingId: row?.listingId ?? row?.listingID ?? null,
    postId: row?.postId ?? row?.postID ?? null,
  }
}

function computeInitialsFromName(value) {
  if (!value) {
    return 'TR'
  }
  const letters = String(value)
    .split(/\s+/)
    .filter(Boolean)
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
  return letters ? letters.toUpperCase() : 'TR'
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

function formatMessageTimestamp(value) {
  if (!value) {
    return ''
  }
  const isoLike = String(value).includes('T') ? value : String(value).replace(' ', 'T')
  const date = new Date(isoLike)
  if (Number.isNaN(date.getTime())) {
    return String(value)
  }
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date)
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

function scrollConversationToEnd() {
  nextTick(() => {
    const pane = conversationPaneRef.value
    if (pane && typeof pane.scrollHeight === 'number') {
      pane.scrollTop = pane.scrollHeight
    }
  })
}
</script>

<template>
  <div class="messages-layout">
    <aside class="messages-sidebar">
      <header class="messages-sidebar__header">
        <div class="messages-sidebar__title">Messages</div>
        <n-text depth="3">Reach out to collaborators and operators.</n-text>
      </header>

      <n-input
        v-model:value="threadsState.search"
        placeholder="Search by name or username"
        size="small"
        round
        clearable
        class="messages-sidebar__search"
      >
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
            <button
              v-for="thread in filteredThreads"
              :key="thread.threadKey"
              :class="[
                'messages-thread',
                { 'messages-thread--active': activeThread?.threadKey === thread.threadKey },
              ]"
              @click="selectThread(thread)"
            >
              <n-avatar
                round
                size="medium"
                :src="thread.avatar || undefined"
                class="messages-thread__avatar"
              >
                <template v-if="!thread.avatar">
                  {{ computeInitialsFromName(thread.participantName || thread.participantUsername || 'TR') }}
                </template>
              </n-avatar>
              <div class="messages-thread__body">
                <div class="messages-thread__top">
                  <span class="messages-thread__name">{{ thread.participantName }}</span>
                  <span class="messages-thread__time">
                    {{ formatMessageTimestamp(thread.lastSentAt) }}
                  </span>
                </div>
                <div class="messages-thread__preview">
                  <span v-if="thread.lastMessageSenderType === viewer.type" class="messages-thread__preview-self">You: </span>
                  <span>{{ thread.lastMessage || 'Start the conversation' }}</span>
                </div>
              </div>
              <n-tag
                v-if="thread.unreadCount > 0"
                type="success"
                size="small"
                round
                bordered
              >
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
          <n-space align="center" size="small">
            <n-avatar
              round
              size="large"
              :src="activeThread.avatar || undefined"
            >
              <template v-if="!activeThread.avatar">
                {{ computeInitialsFromName(activeThread.participantName || activeThread.participantUsername || 'TR') }}
              </template>
            </n-avatar>
            <div class="messages-content__identity">
              <div class="messages-content__name">
                {{ activeThread.participantName }}
              </div>
              <n-text v-if="activeThread.participantUsername" depth="3">
                @{{ activeThread.participantUsername }}
              </n-text>
            </div>
          </n-space>
        </header>

        <section class="messages-content__body">
          <n-spin :show="conversationState.loading">
            <div class="messages-conversation" ref="conversationPaneRef">
              <template v-if="conversationState.messages.length">
                <div
                  v-for="message in conversationState.messages"
                  :key="message.id ?? `${message.sentAt}-${message.senderId}`"
                  :class="[
                    'messages-conversation__item',
                    { 'messages-conversation__item--own': isOwnMessage(message) },
                  ]"
                >
                  <div class="messages-conversation__bubble">
                    {{ message.content }}
                  </div>
                  <div class="messages-conversation__timestamp">
                    {{ formatMessageTimestamp(message.sentAt) }}
                  </div>
                </div>
              </template>
              <n-empty v-else description="No messages yet. Say hello to start collaborating." />
            </div>
          </n-spin>
        </section>

        <footer class="messages-content__composer">
          <n-alert
            v-if="conversationState.error"
            type="error"
            closable
            @close="conversationState.error = ''"
          >
            {{ conversationState.error }}
          </n-alert>
          <n-input
            v-model:value="conversationState.input"
            type="textarea"
            :autosize="{ minRows: 3, maxRows: 5 }"
            maxlength="2000"
            show-count
            placeholder="Type your message here..."
          />
          <NSpace justify="end">
            <NButton
              type="primary"
              :disabled="!conversationState.input.trim()"
              :loading="conversationState.sending"
              @click="sendMessage"
            >
              Send
            </NButton>
          </NSpace>
        </footer>
      </template>

      <template v-else>
        <div class="messages-content__placeholder">
          <n-empty description="Select a conversation to view details" />
        </div>
      </template>
    </section>
  </div>
</template>

<style scoped>
.messages-layout {
  display: flex;
  min-height: 620px;
  background: #fff;
  border-radius: 18px;
  box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
  overflow: hidden;
  border: 1px solid rgba(15, 23, 42, 0.05);
}

.messages-sidebar {
  width: 320px;
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
  padding: 18px 24px;
  overflow: hidden;
}

.messages-conversation {
  height: 100%;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding-right: 6px;
}

.messages-conversation__item {
  max-width: 70%;
  align-self: flex-start;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.messages-conversation__item--own {
  align-self: flex-end;
}

.messages-conversation__bubble {
  background: rgba(15, 23, 42, 0.06);
  padding: 10px 14px;
  border-radius: 16px;
  line-height: 1.5;
  color: #1f2937;
  box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.04);
}

.messages-conversation__item--own .messages-conversation__bubble {
  background: rgba(24, 160, 88, 0.15);
  color: #15603a;
  box-shadow: inset 0 0 0 1px rgba(24, 160, 88, 0.18);
}

.messages-conversation__timestamp {
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.45);
  text-align: right;
}

.messages-content__composer {
  padding: 18px 24px 20px;
  border-top: 1px solid rgba(15, 23, 42, 0.05);
  display: flex;
  flex-direction: column;
  gap: 12px;
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
  }

  .messages-sidebar {
    width: 100%;
    border-right: none;
    border-bottom: 1px solid rgba(15, 23, 42, 0.05);
  }
}
</style>

