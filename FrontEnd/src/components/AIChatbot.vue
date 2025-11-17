<template>
  <section class="chatbot" role="complementary" aria-label="AI Travel Assistant Chatbot">
    <transition name="chat-fade">
      <section v-if="isChatOpen" class="chat-panel" role="dialog" aria-labelledby="chat-title">
        <template v-if="true">
          <header class="chat-panel__header">
            <n-avatar round size="large" aria-label="AI Assistant Avatar">AI</n-avatar>
            <div class="chat-panel__title">
              <strong id="chat-title">AI Travel Assistant</strong>
              <small>
                <span class="status-dot" aria-label="Online status"></span>
                Online • Powered by Google Gemini
              </small>
            </div>
            <n-button size="small" text @click="exportConversation" title="Export conversation" aria-label="Export conversation">
              💾
            </n-button>
          </header>

          <section class="chat-panel__body" role="log" aria-live="polite">
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
                  <!-- Retry option for bot messages -->
                  <div v-if="message.role === 'assistant' && message.id === lastBotMessageId && !isTyping" class="chat-msg__retry">
                    <n-button size="tiny" text type="primary" @click="regenerateResponse(message)">
                      🔄 Regenerate
                    </n-button>
                  </div>
                </div>
              </div>
              <!-- Typing indicator -->
              <div v-if="isTyping" class="chat-msg chat-msg--bot">
                <n-avatar size="small" class="chat-msg__avatar">AI</n-avatar>
                <div class="chat-msg__body">
                  <div class="typing-indicator">
                    <span></span><span></span><span></span>
                  </div>
                </div>
              </div>
            </n-scrollbar>
          </section>

          <footer class="chat-panel__composer">
            <!-- Contextual quick suggestions -->
            <div v-if="contextualSuggestions.length > 0 && messages.length <= 2" class="quick-suggestions">
              <n-button
                v-for="(suggestion, idx) in contextualSuggestions"
                :key="idx"
                size="tiny"
                secondary
                @click="sendQuickMessage(suggestion)"
              >
                {{ suggestion }}
              </n-button>
            </div>
            
            <div class="composer-input">
              <n-input v-model:value="userInput" type="textarea" placeholder="Feel free to ask.." aria-label="Chat message input"
                :autosize="{ minRows: 1, maxRows: 3 }" :disabled="isSending" @keydown.enter="handleEnter" />
              <n-button type="primary" :loading="isSending" @click="sendMessage" aria-label="Send message">Send</n-button>
            </div>
          </footer>
        </template>
      </section>
    </transition>

    <n-button class="chatbot__trigger" circle type="primary" @click="toggleChat" 
      :aria-label="isChatOpen ? 'Close AI chat' : 'Open AI chat'"
      :aria-expanded="isChatOpen">
      <template #icon>
        <svg v-if="!isChatOpen" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true">
          <path fill="currentColor"
            d="M12 3a9 9 0 0 0-9 9c0 2.16.89 4.17 2.38 5.67L4 21l4.48-1.46C9.62 20.55 10.79 21 12 21c4.97 0 9-3.58 9-9s-4.03-9-9-9Z" />
        </svg>
        <svg v-else width="22" height="22" viewBox="0 0 24 24" aria-hidden="true">
          <path fill="currentColor"
            d="m7.05 4.6-1.4 1.42L10.6 11l-4.95 4.95l1.4 1.41L12 12.42l4.95 4.94 1.41-1.41L13.41 11l4.95-4.97-1.41-1.42L12 9.58z" />
        </svg>
      </template>
    </n-button>
  </section>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
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
const sessionId = ref('')
const isTyping = ref(false)
const lastUserMessage = ref('')
const idleTimer = ref(null)
const hasShownProactiveTip = ref(false)

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

const lastBotMessageId = computed(() => {
  const botMessages = messages.value.filter(m => m.role === 'assistant')
  return botMessages.length > 0 ? botMessages[botMessages.length - 1].id : null
})

const contextualSuggestions = computed(() => {
  const currentRoute = router.currentRoute.value.path
  const role = personaRole.value
  
  // Role-based suggestions
  if (role === 'traveler') {
    if (currentRoute.includes('dashboard')) {
      return ['Plan a new trip', 'Budget planning', 'View saved posts']
    }
    if (currentRoute.includes('community')) {
      return ['Find food recommendations', 'Save this post', 'Eco-friendly tips']
    }
    if (currentRoute.includes('saved')) {
      return ['How to save posts', 'View my itineraries', 'Plan from saved']
    }
    return ['Visa requirements', 'Emergency contacts', 'Eco-friendly tips']
  }
  
  if (role === 'operator') {
    if (currentRoute.includes('dashboard')) {
      return ['Upload business info', 'Manage listings', 'View guidelines']
    }
    if (currentRoute.includes('media')) {
      return ['Photo upload tips', 'Image requirements', 'Best practices']
    }
    return ['How to start registration', 'Listing approval process', 'Upload media']
  }
  
  if (role === 'admin') {
    return ['View analytics', 'Check notifications', 'Moderate content']
  }
  
  // Guest suggestions
  return ['Explore sustainable travel', 'Browse destinations', 'Learn more']
})

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
  lastUserMessage.value = text
  addMessage('user', text)
  errorMessage.value = ''
  isSending.value = true
  isTyping.value = true

  try {
    const response = await fetch(CHAT_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        message: text,
        history: historyPayload,
        persona: personaDetails.value,
        sessionId: sessionId.value,
      }),
    })
    const data = await response.json().catch(() => null)
    if (!response.ok || !data?.ok) {
      throw new Error(data?.error || 'Unable to reach the AI service.')
    }
    
    // Store sessionId from response
    if (data.sessionId) {
      sessionId.value = data.sessionId
    }
    
    isTyping.value = false
    addMessage('assistant', data.reply || 'I did not get any text back.', data.actions ?? [])
  } catch (error) {
    isTyping.value = false
    addMessage(
      'assistant',
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

function regenerateResponse(message) {
  if (!lastUserMessage.value || isSending.value) {
    return
  }
  
  // Remove the last bot response
  const botIndex = messages.value.findIndex(m => m.id === message.id)
  if (botIndex !== -1) {
    messages.value.splice(botIndex, 1)
  }
  
  // Resend the last user message
  userInput.value = lastUserMessage.value
  sendMessage()
}

function exportConversation() {
  const timestamp = new Date().toISOString().split('T')[0]
  let content = `Malaysia Sustainable Travel - AI Chat Export\nDate: ${timestamp}\nPersona: ${personaRole.value}\n\n`
  
  messages.value.forEach(msg => {
    const time = formatTime(msg.createdAt)
    const role = msg.role === 'user' ? 'You' : 'AI Assistant'
    content += `[${time}] ${role}:\n${msg.content}\n\n`
  })
  
  const blob = new Blob([content], { type: 'text/plain' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `chat-export-${timestamp}.txt`
  link.click()
  URL.revokeObjectURL(url)
}

function sendQuickMessage(text) {
  if (!text || isSending.value) {
    return
  }
  userInput.value = text
  sendMessage()
}

function handleKeyDown(event) {
  // ESC to close chat
  if (event.key === 'Escape' && isChatOpen.value) {
    toggleChat()
  }
}

function resetIdleTimer() {
  if (idleTimer.value) {
    clearTimeout(idleTimer.value)
  }
  
  // Only show proactive tip if chat is not open and hasn't been shown yet
  if (!isChatOpen.value && !hasShownProactiveTip.value) {
    idleTimer.value = setTimeout(() => {
      showProactiveTip()
    }, 30000) // 30 seconds of inactivity
  }
}

function showProactiveTip() {
  if (isChatOpen.value || hasShownProactiveTip.value) {
    return
  }
  
  const currentRoute = router.currentRoute.value.path
  const role = personaRole.value
  let tip = ''
  
  // Context-based proactive tips
  if (role === 'traveler') {
    if (currentRoute.includes('dashboard')) {
      tip = '💡 Tip: You can plan a trip with AI! Just ask me "Plan a 3-day trip to Penang" or "Budget planning for Malaysia"'
    } else if (currentRoute.includes('community')) {
      tip = '💡 Tip: Looking for food recommendations? I can help you find local favorites! You can also ask about eco-friendly travel tips.'
    } else if (currentRoute.includes('saved')) {
      tip = '💡 Tip: Want to turn your saved posts into an itinerary? Ask me "Plan a trip from my saved places"'
    } else {
      tip = '💡 Tip: I can help you plan sustainable trips across Malaysia. Ask me about visa requirements, emergency contacts, or budget planning!'
    }
  } else if (role === 'operator') {
    if (currentRoute.includes('listings')) {
      tip = '💡 Tip: Need help managing your listings? I can guide you through the process!'
    } else if (currentRoute.includes('media')) {
      tip = '💡 Tip: Want photo upload tips? Ask me about media requirements!'
    } else {
      tip = '💡 Tip: I can help with registration, listings, and operator guidelines. Just ask!'
    }
  }
  
  if (tip) {
    hasShownProactiveTip.value = true
    // Show notification-style tip without opening chat
    console.log('[Proactive Tip]', tip)
    // You could add a toast notification here if you have a toast system
  }
}

function toggleChat() {
  isChatOpen.value = !isChatOpen.value
  if (isChatOpen.value) {
    // Clear idle timer when chat opens
    if (idleTimer.value) {
      clearTimeout(idleTimer.value)
      idleTimer.value = null
    }
    hasShownProactiveTip.value = true
  } else {
    // Start idle timer when chat closes
    resetIdleTimer()
  }
}

onMounted(() => {
  document.addEventListener('keydown', handleKeyDown)
  // Start idle timer on mount
  resetIdleTimer()
  
  // Reset idle timer on any user activity
  document.addEventListener('mousemove', resetIdleTimer)
  document.addEventListener('click', resetIdleTimer)
  document.addEventListener('scroll', resetIdleTimer)
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeyDown)
  document.removeEventListener('mousemove', resetIdleTimer)
  document.removeEventListener('click', resetIdleTimer)
  document.removeEventListener('scroll', resetIdleTimer)
  
  if (idleTimer.value) {
    clearTimeout(idleTimer.value)
  }
})

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

.chat-msg__retry {
  margin-top: 4px;
  opacity: 0.7;
}

.chat-msg__retry:hover {
  opacity: 1;
}

.typing-indicator {
  display: flex;
  gap: 4px;
  padding: 8px 12px;
}

.typing-indicator span {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: rgba(15, 23, 42, 0.4);
  animation: typing-bounce 1.4s infinite ease-in-out both;
}

.typing-indicator span:nth-child(1) {
  animation-delay: -0.32s;
}

.typing-indicator span:nth-child(2) {
  animation-delay: -0.16s;
}

@keyframes typing-bounce {
  0%, 80%, 100% {
    transform: scale(0);
    opacity: 0.5;
  }
  40% {
    transform: scale(1);
    opacity: 1;
  }
}

.chat-panel__composer {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px 18px 18px;
  border-top: 1px solid rgba(15, 23, 42, 0.08);
  background: #fff;
}

.quick-suggestions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 4px 0;
}

.composer-input {
  display: flex;
  gap: 12px;
}

.composer-input .n-input {
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
