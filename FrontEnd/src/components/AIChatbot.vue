<template>
  <section class="chatbot">
    <transition name="chat-fade">
      <section v-if="isChatOpen" class="chat-panel">
        <template v-if="true">
          <header class="chat-panel__header">
            <n-avatar round size="large">AI</n-avatar>
            <div class="chat-panel__title">
              <strong>AI Travel Assistant</strong>
              <small>
                <span class="status-dot"></span>
                Online • Powered by Google Gemini
              </small>
            </div>
          </header>

          <section class="chat-panel__body">
            <n-scrollbar ref="chatContainer" class="chat-panel__messages" :style="{ height: '100%' }"
              :content-style="{ paddingRight: '6px' }">
              <div v-for="message in messages" :key="message.id"
                :class="['chat-msg', message.role === 'user' ? 'chat-msg--user' : 'chat-msg--bot']">
                <n-avatar size="small" class="chat-msg__avatar">
                  {{ message.role === 'user' ? 'U' : 'AI' }}
                </n-avatar>
                <div class="chat-msg__body">
                  <span class="chat-msg__time">{{ formatTime(message.createdAt) }}</span>
                  <p>{{ message.content }}</p>
                  <div v-if="message.actions?.length" class="chat-msg__actions">
                    <n-button
                      v-for="(action, idx) in message.actions"
                      :key="`${message.id}-${idx}`"
                      size="tiny"
                      tertiary
                      type="primary"
                      @click="handleAction(action)"
                    >
                      {{ action.label }}
                    </n-button>
                  </div>
                </div>
              </div>
            </n-scrollbar>
          </section>

          <footer class="chat-panel__composer">
            <n-input v-model:value="userInput" type="textarea" placeholder="Feel free to ask.."
              :autosize="{ minRows: 1, maxRows: 3 }" :disabled="isSending" @keydown.enter="handleEnter" />
            <n-button type="primary" :loading="isSending" @click="sendMessage">Send</n-button>
          </footer>
        </template>
      </section>
    </transition>

    <n-button class="chatbot__trigger" circle type="primary" @click="toggleChat">
      <template #icon>
        <svg v-if="!isChatOpen" width="22" height="22" viewBox="0 0 24 24">
          <path fill="currentColor"
            d="M12 3a9 9 0 0 0-9 9c0 2.16.89 4.17 2.38 5.67L4 21l4.48-1.46C9.62 20.55 10.79 21 12 21c4.97 0 9-3.58 9-9s-4.03-9-9-9Z" />
        </svg>
        <svg v-else width="22" height="22" viewBox="0 0 24 24">
          <path fill="currentColor"
            d="m7.05 4.6-1.4 1.42L10.6 11l-4.95 4.95l1.4 1.41L12 12.42l4.95 4.94 1.41-1.41L13.41 11l4.95-4.97-1.41-1.42L12 9.58z" />
        </svg>
      </template>
    </n-button>
  </section>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

const props = defineProps({
  persona: {
    type: String,
    default: 'guest',
  },
  displayName: {
    type: String,
    default: '',
  },
})

const CHAT_ENDPOINT = '/api/external/chatbot.php'
const router = useRouter()

const isChatOpen = ref(false)
const userInput = ref('')
const personaRole = computed(() => {
  const raw = (props.persona || '').toLowerCase()
  return ['traveler', 'operator', 'admin'].includes(raw) ? raw : 'guest'
})

const personaDetails = computed(() => {
  const displayName =
    typeof props.displayName === 'string' ? props.displayName.trim() : ''
  return {
    role: personaRole.value,
    displayName,
  }
})

const personaViewPath = computed(() => resolveViewPath(personaRole.value))

const messages = ref([
  {
    id: 1,
    role: 'assistant',
    content: 'Hi! I am your AI travel helper. Ask me anything about Malaysian sustainable travel.',
    createdAt: new Date().toISOString(),
    actions: [],
  },
])
const chatContainer = ref(null)
const errorMessage = ref('')
const isSending = ref(false)
let counter = 2

function toggleChat() {
  isChatOpen.value = !isChatOpen.value
}

function formatTime(timestamp) {
  if (!timestamp) return ''
  try {
    const formatter = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' })
    return formatter.format(new Date(timestamp))
  } catch {
    return ''
  }
}

function addMessage(role, content, actions = []) {
  const entry = {
    id: counter++,
    role,
    content,
    createdAt: new Date().toISOString(),
    actions: Array.isArray(actions) ? actions : [],
  }
  messages.value.push(entry)
  return entry.id
}

function updateMessageContent(id, content, actions = []) {
  const target = messages.value.find((item) => item.id === id)
  if (target) {
    target.content = content
    target.createdAt = new Date().toISOString()
    target.actions = Array.isArray(actions) ? actions : []
  }
}

async function sendMessage() {
  const text = userInput.value.trim()
  if (!text || isSending.value) {
    return
  }

  const historyPayload = messages.value.slice(-8).map((item) => ({
    role: item.role === 'assistant' ? 'assistant' : 'user',
    text: item.content,
  }))

  userInput.value = ''
  addMessage('user', text)
  const placeholderId = addMessage('assistant', '…')
  errorMessage.value = ''
  isSending.value = true

  try {
    const response = await fetch(CHAT_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        message: text,
        history: historyPayload,
        persona: personaDetails.value,
      }),
    })
    const data = await response.json().catch(() => null)
    if (!response.ok || !data?.ok) {
      throw new Error(data?.error || 'Unable to reach the AI service.')
    }
    updateMessageContent(placeholderId, data.reply || 'I did not get any text back.', data.actions ?? [])
  } catch (error) {
    updateMessageContent(
      placeholderId,
      'Sorry, please ask again later.',
      [],
    )
    errorMessage.value = error instanceof Error ? error.message : 'Unexpected error while sending your question.'
  } finally {
    isSending.value = false
    await scrollToBottom()
  }
}

function handleEnter(event) {
  if (!event.shiftKey) {
    event.preventDefault()
    sendMessage()
  }
}

async function handleAction(action) {
  if (!action || typeof action !== 'object') {
    return
  }

  if (action.type === 'link' && typeof action.url === 'string') {
    window.open(action.url, '_blank', 'noopener')
    return
  }

  if (action.type === 'module') {
    const moduleKey = typeof action.module === 'string' ? action.module : ''
    const targetView =
      typeof action.view === 'string' && action.view.length > 0 ? action.view : personaRole.value
    const path = resolveViewPath(targetView) || personaViewPath.value
    const query = {}

    if (moduleKey && moduleKey !== 'dashboard' && moduleKey !== 'profile') {
      query.module = moduleKey
    }

    if (action.params && typeof action.params === 'object') {
      Object.entries(action.params).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
          query[key] = value
        }
      })
    }

    if (moduleKey === 'profile' && query.editProfile === undefined) {
      query.editProfile = '1'
    }

    try {
      await router.push({ path, query })
    } catch (err) {
      const message = err instanceof Error ? err.message : ''
      if (!message.includes('Avoided redundant navigation')) {
        console.error('Navigation failed', err)
      }
    }

    return
  }
}

function resolveViewPath(view) {
  switch (view) {
    case 'traveler':
      return '/traveler'
    case 'operator':
      return '/operator'
    case 'admin':
      return '/admin'
    default:
      return '/'
  }
}

async function scrollToBottom() {
  await nextTick()
  if (chatContainer.value) {
    chatContainer.value.scrollTo({ top: Number.MAX_SAFE_INTEGER, behavior: 'smooth' })
  }
}

watch(
  () => messages.value.length,
  async () => {
    await scrollToBottom()
  }
)

</script>

<style scoped>
.chatbot {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 1000;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 16px;
}

.chatbot__trigger {
  width: 56px;
  height: 56px;
  box-shadow: 0 15px 40px rgba(15, 23, 42, 0.25);
}

.chat-panel {
  width: min(360px, 92vw);
  height: 520px;
  border-radius: 22px;
  box-shadow: 0 35px 80px rgba(15, 23, 42, 0.25);
  background: #fff;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.chat-panel__header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 20px;
}

.chat-panel__title {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.chat-panel__title small {
  font-size: 0.78rem;
  color: rgba(15, 23, 42, 0.6);
  display: flex;
  align-items: center;
  gap: 6px;
}

.status-dot {
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: #22c55e;
  box-shadow: 0 0 8px rgba(34, 197, 94, 0.6);
}

.chat-panel__body {
  flex: 1;
  padding: 0 18px 12px;
  display: flex;
  overflow: hidden;
}

.chat-panel__messages {
  flex: 1;
  height: 100%;
  width: 100%;
  overflow-y: auto;
}

.chat-msg {
  display: flex;
  gap: 12px;
  margin: 14px 0;
}

.chat-msg--user {
  flex-direction: row-reverse;
}

.chat-msg__body {
  background: #f4f7ff;
  border-radius: 16px;
  padding: 12px 16px;
  box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12);
  max-width: 78%;
}

.chat-msg--user .chat-msg__body {
  background: #e8fff3;
}

.chat-msg__body p {
  margin: 0;
  color: #0f172a;
  line-height: 1.45;
}

.chat-msg__actions {
  margin-top: 8px;
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.chat-msg__time {
  display: block;
  font-size: 0.7rem;
  color: rgba(15, 23, 42, 0.45);
  margin-bottom: 4px;
}

.chat-panel__composer {
  display: flex;
  gap: 12px;
  padding: 12px 18px 18px;
  border-top: 1px solid rgba(15, 23, 42, 0.08);
  background: #fff;
}

.chat-panel__composer .n-input {
  flex: 1;
}

.chat-fade-enter-active,
.chat-fade-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.chat-fade-enter-from,
.chat-fade-leave-to {
  opacity: 0;
  transform: translateY(16px);
}
</style>
