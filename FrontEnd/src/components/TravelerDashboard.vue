<script setup>
import { computed, h, reactive, ref, watch, nextTick, provide } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { NIcon, useMessage } from 'naive-ui'
import TravelerWeatherWidget from './TravelerWeatherWidget.vue'
import BookingLiveStays from './BookingLiveStays.vue'
import TravelerSocialFeed from './TravelerSocialFeed.vue'
import TravelerSavedPosts from './TravelerSavedPosts.vue'
import TravelerMessages from './TravelerMessages.vue'
import TravelerMarketplace from './TravelerMarketplace.vue'
import NotificationCenter from './NotificationCenter.vue'
import { notificationFeedSymbol, useNotificationFeed } from '../composables/useNotificationFeed.js'
import { extractProfileImage } from '../utils/profileImage.js'

const props = defineProps({
  traveler: {
    type: Object,
    default: () => null,
  },
  metrics: {
    type: Object,
    default: () => ({}),
  },
  destinationGroups: {
    type: Array,
    default: () => [],
  },
  upcomingTrips: {
    type: Array,
    default: () => [],
  },
  stays: {
    type: Array,
    default: () => [],
  },
  transport: {
    type: Array,
    default: () => [],
  },
  experiences: {
    type: Array,
    default: () => [],
  },
  companions: {
    type: Array,
    default: () => [],
  },
  integrations: {
    type: Array,
    default: () => [],
  },
  insights: {
    type: Array,
    default: () => [],
  },
  communityPosts: {
    type: Array,
    default: () => [],
  },
  communityCategories: {
    type: Array,
    default: () => [],
  },
})

const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const COLLAPSED_LOGO_SRC = '/Traveler Hub.png'
const MESSAGES_ENDPOINT = `${API_BASE}/messages.php`

const defaultTraveler = {
  fullName: 'Traveler',
  username: 'traveler01',
}

const traveler = computed(() => {
  const incoming = props.traveler ?? {}
  const displayName = incoming.fullName || incoming.username || defaultTraveler.fullName
  const initials =
    incoming.avatarInitials ||
    displayName
      .split(/\s+/)
      .map((part) => part[0])
      .join('')
      .slice(0, 2)
      .toUpperCase()
  const { relative: derivedAvatarPath, url: derivedAvatarUrl } = deriveAvatarInfo(incoming)
  const avatarUrl = derivedAvatarUrl || incoming.avatarUrl || ''
  const avatarPath = derivedAvatarPath || incoming.avatarPath || ''

  return {
    ...defaultTraveler,
    ...incoming,
    displayName,
    initials,
    avatarUrl,
    avatarPath,
  }
})

function deriveAvatarInfo(source) {
  return extractProfileImage(source)
}

const metrics = computed(() => ({
  tripsPlanned: Number(props.metrics.tripsPlanned ?? 0),
  ecoPoints: Number(props.metrics.ecoPoints ?? 0),
  savedSpots: Number(props.metrics.savedSpots ?? 0),
  impactBadges: Number(props.metrics.impactBadges ?? 0),
  nextTrip: props.metrics.nextTrip ?? 'Not scheduled yet',
  carbonSaved: Number(props.metrics.carbonSaved ?? 0),
  pledges: Number(props.metrics.pledges ?? 0),
  sharedGuides: Number(props.metrics.sharedGuides ?? 0),
  impactScore: Number(props.metrics.impactScore ?? 0),
}))

const renderIcon = (name) => () =>
  h(NIcon, null, { default: () => h('i', { class: name }) })

const destinationGroups = computed(() => props.destinationGroups ?? [])
const experiences = computed(() => props.experiences ?? [])
const upcomingTrips = computed(() => props.upcomingTrips ?? [])
const stays = computed(() => props.stays ?? [])
const transport = computed(() => props.transport ?? [])
const companions = computed(() => props.companions ?? [])
const integrations = computed(() => props.integrations ?? [])
const insights = computed(() => props.insights ?? [])
const communityFeedPosts = computed(() => props.communityPosts ?? [])
const communityFeedCategories = computed(() => props.communityCategories ?? [])
const currentTravelerId = computed(() => {
  const source = traveler.value ?? {}
  return (
    source.id ??
    source.travelerID ??
    source.travelerId ??
    source.userId ??
    null
  )
})

const travelerNotificationFeed = useNotificationFeed({
  recipientType: computed(() => 'Traveler'),
  recipientId: currentTravelerId,
  listLimit: 25,
  pollInterval: 60000,
  announce: true,
})
provide(notificationFeedSymbol, travelerNotificationFeed)

const currentTravelerType = 'Traveler'
const messageTimestampFormatter = new Intl.DateTimeFormat(undefined, {
  dateStyle: 'medium',
  timeStyle: 'short',
})

const contactDialog = reactive({
  visible: false,
  loading: false,
  sending: false,
  messages: [],
  input: '',
  error: '',
  target: null,
})

const contactThreadRef = ref(null)

const avatarFallbackStyle = {
  background: 'linear-gradient(135deg, rgba(36, 198, 220, 0.18), rgba(81, 74, 157, 0.18))',
  color: '#1f2933',
  fontWeight: '600',
}

const sidebarOptions = [
  { key: 'dashboard', label: 'Dashboard overview', icon: renderIcon('ri-compass-3-line') },
  { key: 'weather', label: 'Weather outlook', icon: renderIcon('ri-sun-cloudy-line') },
  { key: 'community', label: 'Community feed', icon: renderIcon('ri-hashtag') },
  { key: 'saved-posts', label: 'Saved posts', icon: renderIcon('ri-bookmark-line') },
  { key: 'messages', label: 'Messages', icon: renderIcon('ri-chat-3-line') },
  { key: 'notifications', label: 'Notifications', icon: renderIcon('ri-notification-3-line') },
  { key: 'marketplace', label: 'Marketplace', icon: renderIcon('ri-store-3-line') },
  { key: 'trips', label: 'Trip planner', disabled: true, icon: renderIcon('ri-calendar-event-line') },
  { key: 'saved', label: 'Saved places', disabled: true, icon: renderIcon('ri-heart-3-line') },
  { key: 'settings', label: 'Account settings', disabled: true, icon: renderIcon('ri-settings-4-line') },
]

const route = useRoute()
const router = useRouter()
const selectableModules = sidebarOptions.filter((item) => !item.disabled).map((item) => item.key)
const selectedMenu = ref('dashboard')
const marketplaceRef = ref(null)

const normaliseModuleKey = (value) => {
  if (Array.isArray(value)) {
    return typeof value[0] === 'string' ? value[0] : ''
  }
  return typeof value === 'string' ? value : ''
}

watch(
  () => normaliseModuleKey(route.query.module),
  (moduleKey) => {
    if (moduleKey && selectableModules.includes(moduleKey)) {
      selectedMenu.value = moduleKey
    } else if (!moduleKey) {
      selectedMenu.value = 'dashboard'
    }
  },
  { immediate: true },
)

watch(selectedMenu, (next) => {
  const desired = next && next !== 'dashboard' ? next : null
  const current = normaliseModuleKey(route.query.module)
  if (desired === current) {
    return
  }

  const nextQuery = { ...route.query }
  if (desired) {
    nextQuery.module = desired
  } else {
    delete nextQuery.module
  }
  router.replace({ query: nextQuery }).catch(() => {})
})

watch(
  () => route.query.listingId,
  (listingId) => {
    if (!listingId) {
      return
    }
    if (selectedMenu.value !== 'marketplace') {
      selectedMenu.value = 'marketplace'
    }
    const numeric = Number(listingId)
    if (Number.isFinite(numeric)) {
      nextTick(() => {
        marketplaceRef.value?.openListingById?.(numeric)
      })
    }
    const nextQuery = { ...route.query }
    delete nextQuery.listingId
    router.replace({ query: nextQuery }).catch(() => {})
  },
  { immediate: true }
)
const message = useMessage()

const destinationTabs = computed(() => destinationGroups.value.map((group) => group.label))
const activeDestinationTab = ref(destinationTabs.value[0] ?? null)

watch(destinationTabs, (tabs) => {
  if (!tabs.includes(activeDestinationTab.value)) {
    activeDestinationTab.value = tabs[0] ?? null
  }
})

watch(
  () => contactDialog.visible,
  (visible) => {
    if (!visible) {
      contactDialog.target = null
      contactDialog.messages = []
      contactDialog.input = ''
      contactDialog.error = ''
      contactDialog.loading = false
      contactDialog.sending = false
    }
  }
)

watch(
  () => contactDialog.messages.length,
  () => {
    if (contactDialog.visible) {
      scrollContactThreadToBottom()
    }
  }
)

const activeDestinations = computed(() => {
  const group = destinationGroups.value.find((item) => item.label === activeDestinationTab.value)
  return group?.items ?? []
})

function handleMenuSelect(val) {
  selectedMenu.value = val
}

function handleCommunityContact(post) {
  const viewerId = currentTravelerId.value
  if (!viewerId) {
    message.error('Unable to determine your traveler profile. Please sign in again.')
    return
  }

  const target = normaliseContactTarget(post)
  if (!target) {
    message.error('Unable to contact this creator at the moment.')
    return
  }

  contactDialog.target = target
  contactDialog.messages = []
  contactDialog.input = ''
  contactDialog.error = ''
  contactDialog.visible = true
  loadContactMessages()
}

function handleMarketplaceContact(listing) {
  const viewerId = currentTravelerId.value
  if (!viewerId) {
    message.error('Unable to determine your traveler profile. Please sign in again.')
    return
  }

  if (!listing || !listing.operator) {
    message.error('Unable to contact this business at the moment.')
    return
  }

  const operatorId = Number(listing.operator.id ?? listing.operator.operatorID ?? 0)
  if (!operatorId) {
    message.error('Unable to contact this business at the moment.')
    return
  }

  const numericListingId = Number(listing.id ?? listing.listingId ?? 0)

  const target = {
    authorId: operatorId,
    authorType: 'Operator',
    authorTypeRaw: 'operator',
    authorName: listing.operator.name || 'Business Operator',
    authorUsername: listing.operator.email || '',
    authorAvatar: '',
    authorInitials: computeInitialsFromName(listing.operator.name || 'Operator'),
    postId: null,
    listingId: Number.isFinite(numericListingId) && numericListingId > 0 ? numericListingId : null,
    caption: `Inquiry about ${listing.businessName}`,
  }

  contactDialog.target = target
  contactDialog.messages = []
  contactDialog.input = ''
  contactDialog.error = ''
  contactDialog.visible = true
  loadContactMessages()
}

async function loadContactMessages() {
  if (!contactDialog.target) {
    return
  }
  const viewerId = currentTravelerId.value
  if (!viewerId) {
    return
  }

  contactDialog.loading = true
  contactDialog.error = ''

  try {
    const params = new URLSearchParams({
      currentType: currentTravelerType,
      currentId: String(viewerId),
      participantType: contactDialog.target.authorType,
      participantId: String(contactDialog.target.authorId),
    })
    if (contactDialog.target.postId) {
      params.set('postId', String(contactDialog.target.postId))
    }

    const response = await fetch(`${MESSAGES_ENDPOINT}?${params.toString()}`)
    const payload = await readJsonResponse(
      response,
      `Failed to load messages (${response.status})`
    )
    const rows = Array.isArray(payload?.messages) ? payload.messages : []

    if (!contactDialog.target.listingId) {
      const listingFromMessages = rows.find((row) =>
        Number.isFinite(Number(row?.listingId ?? row?.listingID ?? NaN)) &&
        Number(row?.listingId ?? row?.listingID ?? 0) > 0,
      )
      if (listingFromMessages) {
        contactDialog.target.listingId = Number(listingFromMessages.listingId ?? listingFromMessages.listingID)
      }
    }

    contactDialog.messages = rows.map((row, index) => normaliseConversationMessage(row, index))

    if (Array.isArray(payload?.participants)) {
      const counterpart = payload.participants.find(
        (item) =>
          Number(item?.id ?? item?.ID ?? 0) === contactDialog.target.authorId &&
          normaliseMessageAccountType(item?.type ?? item?.accountType ?? item?.role ?? '') ===
            contactDialog.target.authorType
      )
      if (counterpart) {
        Object.assign(contactDialog.target, {
          authorName: counterpart.name || contactDialog.target.authorName,
          authorUsername: counterpart.username || contactDialog.target.authorUsername,
          authorAvatar: counterpart.avatar || contactDialog.target.authorAvatar,
        })
        if (!contactDialog.target.authorInitials) {
          contactDialog.target.authorInitials = computeInitialsFromName(
            contactDialog.target.authorName || contactDialog.target.authorUsername || 'Traveler'
          )
        }
      }
    }

    scrollContactThreadToBottom()
  } catch (error) {
    contactDialog.error = error instanceof Error ? error.message : 'Unable to load messages.'
    message.error(contactDialog.error)
  } finally {
    contactDialog.loading = false
  }
}

async function sendContactMessage() {
  if (!contactDialog.target) {
    return
  }
  const viewerId = currentTravelerId.value
  if (!viewerId) {
    message.error('Unable to determine your traveler profile. Please sign in again.')
    return
  }

  const content = contactDialog.input.trim()
  if (!content) {
    message.warning('Enter a message before sending.')
    return
  }

  contactDialog.sending = true
  contactDialog.error = ''

  const payload = {
    senderType: currentTravelerType,
    senderID: viewerId,
    receiverType: contactDialog.target.authorType,
    receiverID: contactDialog.target.authorId,
    listingID: contactDialog.target.listingId ?? null,
    postID: contactDialog.target.postId ?? null,
    content,
  }

  try {
    const response = await fetch(MESSAGES_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    const data = await readJsonResponse(
      response,
      `Failed to send message (${response.status})`
    )
    const saved = data?.message ?? {}
    contactDialog.messages.push(
      normaliseConversationMessage(
        {
          ...payload,
          listingId: payload.listingID,
          id: saved.id ?? saved.messageId ?? null,
          messageID: saved.id ?? saved.messageId ?? null,
          sentAt: saved.sentAt ?? saved.sent_at ?? new Date().toISOString().replace('T', ' ').slice(0, 19),
        },
        contactDialog.messages.length
      )
    )
    contactDialog.input = ''
    scrollContactThreadToBottom()
  } catch (error) {
    contactDialog.error = error instanceof Error ? error.message : 'Unable to send message.'
    message.error(contactDialog.error)
  } finally {
    contactDialog.sending = false
  }
}

function normaliseContactTarget(post) {
  if (!post) {
    return null
  }
  const authorId =
    Number(post.authorId ?? post.authorID ?? post.travelerId ?? post.travelerID ?? 0)
  if (!authorId) {
    return null
  }
  const typeKey =
    typeof post.authorType === 'string' && post.authorType.trim() !== ''
      ? post.authorType.trim().toLowerCase()
      : 'traveler'
  const authorType = normaliseMessageAccountType(typeKey) || 'Traveler'
  const authorName = post.authorName || post.authorUsername || 'Traveler'
  const authorUsername = post.authorUsername || ''
  const linkedPostId = resolveLinkedPostId(post)
  const potentialListingId = Number(post.listingId ?? post.listingID ?? 0)

  return {
    authorId,
    authorType,
    authorTypeRaw: typeKey,
    authorName,
    authorUsername,
    authorAvatar: post.authorAvatar || '',
    authorInitials: computeInitialsFromName(authorName || authorUsername || 'Traveler'),
    postId: linkedPostId,
    listingId: Number.isFinite(potentialListingId) && potentialListingId > 0 ? potentialListingId : null,
    caption: post.caption || '',
  }
}

function resolveLinkedPostId(post) {
  if (!post || typeof post !== 'object') {
    return null
  }

  const candidates = [
    post.postId,
    post.postID,
    post.communityPostId,
    post.community_post_id,
    post.messagePostId,
    post.message_post_id,
  ]

  for (const candidate of candidates) {
    const numeric = Number(candidate)
    if (Number.isInteger(numeric) && numeric > 0) {
      return numeric
    }
  }

  return null
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

function isOwnMessage(entry) {
  const viewerId = currentTravelerId.value
  if (!viewerId) {
    return false
  }
  const senderType = (entry?.senderType ?? '').toLowerCase()
  const senderId = Number(entry?.senderId ?? entry?.senderID ?? 0)
  return senderType === currentTravelerType.toLowerCase() && senderId === Number(viewerId)
}

function formatMessageTimestamp(value) {
  if (!value) {
    return ''
  }
  const asString = String(value)
  const maybeIso = asString.includes('T') ? asString : asString.replace(' ', 'T')
  const date = new Date(maybeIso)
  if (Number.isNaN(date.getTime())) {
    return asString
  }
  return messageTimestampFormatter.format(date)
}

function closeContactDialog() {
  contactDialog.visible = false
}

function scrollContactThreadToBottom() {
  nextTick(() => {
    const el = contactThreadRef.value
    if (el && typeof el.scrollHeight === 'number') {
      el.scrollTop = el.scrollHeight
    }
  })
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

function normaliseMessageAccountType(type) {
  const mapping = {
    traveler: 'Traveler',
    traveller: 'Traveler',
    operator: 'Operator',
    business: 'Operator',
    tourismoperator: 'Operator',
    admin: 'Admin',
    administrator: 'Admin',
  }
  const key = String(type ?? '').toLowerCase()
  return mapping[key] ?? ''
}

function computeInitialsFromName(label) {
  if (!label) {
    return 'TR'
  }
  const initials = String(label)
    .split(/\s+/)
    .filter(Boolean)
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
  return initials ? initials.toUpperCase() : 'TR'
}

async function safeJson(response) {
  try {
    return await response.json()
  } catch (error) {
    return null
  }
}

const summaryCards = computed(() => [
  {
    key: 'trips',
    label: 'Trips planned',
    value: metrics.value.tripsPlanned,
    type: 'number',
    accent: 'linear-gradient(135deg, #70c3ff, #6c63ff)',
  },
  {
    key: 'eco-points',
    label: 'Eco points collected',
    value: metrics.value.ecoPoints,
    type: 'number',
    accent: 'linear-gradient(135deg, #42b883, #8fd3f4)',
  },
  {
    key: 'saved',
    label: 'Saved eco stays',
    value: metrics.value.savedSpots,
    type: 'number',
    accent: 'linear-gradient(135deg, #ff9a9e, #fad0c4)',
  },
  {
    key: 'impact',
    label: 'Impact badges',
    value: metrics.value.impactBadges,
    type: 'number',
    accent: 'linear-gradient(135deg, #a18cd1, #fbc2eb)',
  },
  {
    key: 'upcoming',
    label: 'Next activity',
    value: metrics.value.nextTrip,
    type: 'text',
    accent: 'linear-gradient(135deg, #f6d365, #fda085)',
  },
])

const heroStats = computed(() => [
  {
    key: 'carbon',
    label: 'Carbon saved',
    value: metrics.value.carbonSaved,
    suffix: ' tCOÃ¢â€šâ€še',
    icon: 'ri-planet-line',
  },
  {
    key: 'pledges',
    label: 'Community pledges',
    value: metrics.value.pledges,
    icon: 'ri-hand-heart-line',
  },
  {
    key: 'guides',
    label: 'Shared guides',
    value: metrics.value.sharedGuides,
    icon: 'ri-map-pin-user-line',
  },
  {
    key: 'impactScore',
    label: 'Impact score',
    value: metrics.value.impactScore,
    suffix: '/100',
    icon: 'ri-star-sparkle-line',
  },
])

const hasDestinations = computed(() => destinationGroups.value.length > 0)
const hasExperiences = computed(() => experiences.value.length > 0)
const hasTrips = computed(() => upcomingTrips.value.length > 0)
const hasStays = computed(() => stays.value.length > 0)
const hasTransport = computed(() => transport.value.length > 0)
const hasCompanions = computed(() => companions.value.length > 0)
const hasIntegrations = computed(() => integrations.value.length > 0)
const hasInsights = computed(() => insights.value.length > 0)

const sidebarCollapsed = ref(false)
const expandedSidebarStyle = computed(() => ({
  padding: '18px 16px',
  alignItems: 'flex-start',
  gap: '10px',
}))
const collapsedSidebarStyle = computed(() => ({
  padding: '12px 0',
  alignItems: 'center',
  justifyContent: 'center',
  gap: '14px',
}))
const expandedMenuContainerStyle = computed(() => ({
  padding: '0 8px 16px',
}))
const collapsedMenuContainerStyle = computed(() => ({
  padding: '0 6px 16px',
  display: 'flex',
  flexDirection: 'column',
  alignItems: 'center',
  gap: '12px',
}))
</script>

<template>
  <n-layout has-sider style="min-height: 100vh; background: var(--body-color);">
    <n-layout-sider
      bordered
      collapse-mode="width"
      :collapsed-width="64"
      :width="220"
      :collapsed="sidebarCollapsed"
      show-trigger
      @collapse="sidebarCollapsed = true"
      @expand="sidebarCollapsed = false"
    >
      <n-space
        vertical
        size="small"
        class="sidebar-brand"
        :style="sidebarCollapsed ? collapsedSidebarStyle : expandedSidebarStyle"
      >
        <div class="sidebar-brand__logo">
          <img
            v-if="sidebarCollapsed"
            :src="COLLAPSED_LOGO_SRC"
            alt="Traveler Hub logo"
            class="sidebar-brand__image"
          />
          <n-gradient-text
            v-else
            type="info"
            style="font-size: 1.1rem; font-weight: 600;"
          >
            Traveler Hub
          </n-gradient-text>
        </div>
        <n-text v-if="!sidebarCollapsed" depth="3">Navigate modules</n-text>
        <n-switch v-model:value="sidebarCollapsed" size="small" round />
      </n-space>
      <div :style="sidebarCollapsed ? collapsedMenuContainerStyle : expandedMenuContainerStyle">
        <n-menu
          :options="sidebarOptions"
          :value="selectedMenu"
          :indent="16"
          :collapsed="sidebarCollapsed"
          :collapsed-icon-size="20"
          @update:value="handleMenuSelect"
        />
      </div>
    </n-layout-sider>

    <n-layout>
      <n-layout-header bordered style="padding: 20px 32px; background: transparent;">
        <n-space justify="space-between" align="center" wrap>
          <n-space align="center" size="large">
            <n-avatar
              round
              size="large"
              :src="traveler.avatarUrl || undefined"
              :style="traveler.avatarUrl ? undefined : avatarFallbackStyle"
            >
              <template v-if="!traveler.avatarUrl">{{ traveler.initials }}</template>
            </n-avatar>
            <div>
              <n-text depth="3">Hello, traveler</n-text>
              <div style="font-size: 1.35rem; font-weight: 600;">
                {{ traveler.displayName }}
              </div>
            </div>
          </n-space>
          <n-space>
            <n-input round clearable placeholder="Search eco stays, guides, or itineraries" style="min-width: 280px;">
              <template #suffix>
                <n-icon size="18">
                  <i class="ri-search-2-line" />
                </n-icon>
              </template>
            </n-input>
            <n-button type="primary" round>
              Start new plan
            </n-button>
          </n-space>
        </n-space>
      </n-layout-header>

      <n-layout-content embedded style="padding: 24px 32px;">
        <div v-if="selectedMenu === 'weather'" class="weather-panel">
          <TravelerWeatherWidget />
        </div>
        <div v-else-if="selectedMenu === 'community'" class="community-panel">
          <TravelerSocialFeed
            :posts="communityFeedPosts"
            :categories="communityFeedCategories"
            :current-user="traveler"
            @contact="handleCommunityContact"
          />
        </div>
        <div v-else-if="selectedMenu === 'saved-posts'" class="community-panel">
          <TravelerSavedPosts
            :categories="communityFeedCategories"
            :current-user="traveler"
          />
        </div>
        <div v-else-if="selectedMenu === 'messages'" class="messages-panel">
          <TravelerMessages :current-user="traveler" />
        </div>
        <div v-else-if="selectedMenu === 'notifications'" class="notifications-panel">
          <NotificationCenter
            recipient-type="Traveler"
            :recipient-id="currentTravelerId"
            title="Traveler notifications"
            description="Admins and operators share updates with you here."
          />
        </div>
        <div v-else-if="selectedMenu === 'marketplace'" class="marketplace-panel">
          <TravelerMarketplace ref="marketplaceRef" :current-user="traveler" @contact="handleMarketplaceContact" />
        </div>
        <div v-else class="dashboard-main">
          <n-space vertical size="large">
            <n-card :segmented="{ content: true }" :style="{
              background: 'linear-gradient(135deg, rgba(66, 184, 131, 0.12), rgba(108, 99, 255, 0.12))',
              border: '1px solid rgba(66, 184, 131, 0.24)',
            }">
              <n-grid cols="1 m:2" :x-gap="18" :y-gap="18" align="center">
                <n-grid-item>
                  <n-space vertical size="small">
                    <n-tag type="success" size="small" bordered>Traveler spotlight</n-tag>
                    <div style="font-size: 1.8rem; font-weight: 700;">
                      Craft journeys that protect MalaysiaÃ¢â‚¬â„¢s wild places
                    </div>
                    <n-text depth="3">
                      Plan flexible itineraries, track your eco impact, and stay in touch with responsible guides.
                    </n-text>
                    <n-space>
                      <n-button type="primary" round>
                        Continue last itinerary
                      </n-button>
                      <n-button tertiary type="primary" round>
                        Explore eco pledges
                      </n-button>
                    </n-space>
                  </n-space>
                </n-grid-item>
                <n-grid-item>
                  <n-grid cols="2" :x-gap="16" :y-gap="16">
                    <n-grid-item v-for="stat in heroStats" :key="stat.key">
                      <n-statistic :label="stat.label" :value="stat.value" :suffix="stat.suffix">
                        <template #prefix>
                          <n-icon size="20">
                            <i :class="stat.icon" />
                          </n-icon>
                        </template>
                      </n-statistic>
                    </n-grid-item>
                  </n-grid>
                </n-grid-item>
              </n-grid>
            </n-card>

            <n-grid cols="1 m:2 l:5" :x-gap="16" :y-gap="16">
              <n-grid-item v-for="card in summaryCards" :key="card.key">
                <n-card size="medium" :segmented="{ content: true, footer: false }" :style="{
                  background: card.accent,
                  color: '#fff',
                }">
                  <n-space vertical size="small">
                    <n-text depth="3" style="color: rgba(255, 255, 255, 0.85);">
                      {{ card.label }}
                    </n-text>
                    <div v-if="card.type === 'number'" style="display: flex; align-items: baseline; gap: 6px;">
                      <n-number-animation :from="0" :to="card.value" :duration="1200" show-separator />
                    </div>
                    <div v-else style="font-size: 1.1rem; font-weight: 600;">
                      {{ card.value }}
                    </div>
                  </n-space>
                </n-card>
              </n-grid-item>
            </n-grid>
            <n-card title="Featured experiences" :segmented="{ content: true }">
              <template v-if="hasExperiences">
                <n-carousel autoplay dot-type="line" draggable>
                  <n-carousel-item v-for="experience in experiences" :key="experience.key ?? experience.title">
                    <div :style="{
                      height: '280px',
                      borderRadius: '20px',
                      backgroundImage: `linear-gradient(135deg, rgba(9, 54, 34, 0.55), rgba(9, 54, 34, 0.15)), url(${experience.image})`,
                      backgroundSize: 'cover',
                      backgroundPosition: 'center',
                      display: 'flex',
                      flexDirection: 'column',
                      justifyContent: 'flex-end',
                      padding: '28px',
                      color: '#fff',
                    }">
                      <div style="font-size: 1.65rem; font-weight: 700;">{{ experience.title }}</div>
                      <div style="max-width: 520px; margin-top: 6px;">
                        {{ experience.description }}
                      </div>
                      <n-space style="margin-top: 16px;">
                        <n-button round type="primary">See itinerary</n-button>
                        <n-button round quaternary>Save to planner</n-button>
                      </n-space>
                    </div>
                  </n-carousel-item>
                </n-carousel>
              </template>
              <template v-else>
                <n-empty description="Curate signature eco journeys to highlight them here." />
              </template>
            </n-card>

            <n-card :segmented="{ content: true }" title="Destination inspiration">
              <template v-if="hasDestinations">
                <n-tabs v-model:value="activeDestinationTab" type="segment">
                  <n-tab-pane v-for="tab in destinationTabs" :key="tab" :name="tab" :tab="tab">
                    <n-grid cols="1 m:2 l:3" :x-gap="18" :y-gap="18">
                      <n-grid-item v-for="destination in activeDestinations" :key="destination.key ?? destination.name">
                        <n-card size="medium" :segmented="{ content: true }" style="overflow: hidden;">
                          <template #cover>
                            <img v-if="destination.image" :src="destination.image" :alt="destination.name"
                              style="width: 100%; height: 180px; object-fit: cover;" />
                          </template>
                          <n-space vertical size="small">
                            <div style="font-size: 1.1rem; font-weight: 600;">
                              {{ destination.name }}
                            </div>
                            <n-text depth="3">
                              {{ destination.location }} Ã‚Â· {{ destination.duration }}
                            </n-text>
                            <n-tag v-if="destination.tag" type="success" size="small" bordered>
                              {{ destination.tag }}
                            </n-tag>
                            <n-button tertiary type="primary">
                              View itinerary
                            </n-button>
                          </n-space>
                        </n-card>
                      </n-grid-item>
                    </n-grid>
                  </n-tab-pane>
                </n-tabs>
              </template>
              <template v-else>
                <n-empty description="Add destination groups to inspire your traveler." />
              </template>
            </n-card>

            <n-grid cols="1 m:3" :x-gap="16" :y-gap="16">
              <n-grid-item span="1 m:2">
                <n-card title="Upcoming journeys" :segmented="{ content: true }">
                  <template v-if="hasTrips">
                    <n-timeline size="large">
                      <n-timeline-item v-for="trip in upcomingTrips" :key="trip.id ?? trip.title" :title="trip.title"
                        :time="`${trip.location} Ã‚Â· ${trip.duration}`">
                        <n-text depth="3">{{ trip.focus }}</n-text>
                        <template #footer>
                          <n-button text type="primary">Open trip board</n-button>
                        </template>
                      </n-timeline-item>
                    </n-timeline>
                  </template>
                  <template v-else>
                    <n-empty description="No upcoming trips scheduled." />
                  </template>
                </n-card>
              </n-grid-item>

              <n-grid-item>
                <n-space vertical size="large">
                  <n-card title="Sustainable stays" :segmented="{ content: true }">
                    <template v-if="hasStays">
                      <n-list bordered :show-divider="false">
                        <n-list-item v-for="stay in stays" :key="stay.id ?? stay.name">
                          <n-space justify="space-between" align="center" style="width: 100%;">
                            <div>
                              <div style="font-weight: 600;">{{ stay.name }}</div>
                              <n-text depth="3">{{ stay.location }}</n-text>
                            </div>
                            <n-tag size="small" type="success" bordered>{{ stay.price }}</n-tag>
                          </n-space>
                        </n-list-item>
                      </n-list>
                    </template>
                    <template v-else>
                      <n-empty description="Connect your sustainable stays feed to populate this list." />
                    </template>
                    <template #footer>
                      <n-button block tertiary type="primary">Browse eco stays</n-button>
                    </template>
                  </n-card>

                  <BookingLiveStays default-city="Kuala Lumpur" />

                  <n-card title="Preferred transport" :segmented="{ content: true }">
                    <template v-if="hasTransport">
                      <n-space wrap>
                        <n-button v-for="mode in transport" :key="mode.key ?? mode.label" round quaternary>
                          <n-icon size="18" v-if="mode.icon">
                            <i :class="mode.icon" />
                          </n-icon>
                          <span style="margin-left: 6px;">{{ mode.label }}</span>
                        </n-button>
                      </n-space>
                    </template>
                    <template v-else>
                      <n-empty description="Transport providers will appear once connected." />
                    </template>
                  </n-card>
                </n-space>
              </n-grid-item>
            </n-grid>

            <n-grid cols="1 m:2" :x-gap="16" :y-gap="16">
              <n-grid-item>
                <n-card title="Travel companions" :segmented="{ content: true }">
                  <template v-if="hasCompanions">
                    <n-avatar-group size="medium">
                      <n-avatar v-for="url in companions" :key="url" :src="url" />
                    </n-avatar-group>
                  </template>
                  <template v-else>
                    <n-empty description="Invite friends to plan together." />
                  </template>
                  <template #footer>
                    <n-space justify="space-between" align="center">
                      <n-text depth="3">Collaborate on itineraries and share travel notes.</n-text>
                      <n-button text type="primary">Manage invitations</n-button>
                    </n-space>
                  </template>
                </n-card>
              </n-grid-item>

              <n-grid-item>
                <n-card title="Connected apps" :segmented="{ content: true }">
                  <template v-if="hasIntegrations">
                    <n-space wrap>
                      <n-tag v-for="app in integrations" :key="app.key ?? app" type="info" size="large" bordered>
                        {{ app.label ?? app }}
                      </n-tag>
                    </n-space>
                  </template>
                  <template v-else>
                    <n-empty description="Link trip planning apps to sync data here." />
                  </template>
                  <template #footer>
                    <n-button text type="primary">Manage integrations</n-button>
                  </template>
                </n-card>
              </n-grid-item>
            </n-grid>

            <n-card title="Eco travel playbook" :segmented="{ content: true }">
              <template v-if="hasInsights">
                <n-collapse>
                  <n-collapse-item v-for="item in insights" :key="item.key ?? item.title" :title="item.title">
                    <n-text depth="3">{{ item.description }}</n-text>
                  </n-collapse-item>
                </n-collapse>
              </template>
              <template v-else>
                <n-empty description="Add best-practice tips to guide mindful travel." />
              </template>
            </n-card>
          </n-space>
        </div>
      </n-layout-content>
    </n-layout>
  </n-layout>

  <n-modal
    v-model:show="contactDialog.visible"
    preset="card"
    :mask-closable="false"
    :closable="false"
    style="max-width: 560px"
  >
    <template #header>
      <n-space align="center" size="small">
        <n-avatar
          round
          size="medium"
          :src="contactDialog.target?.authorAvatar || undefined"
          :style="contactDialog.target?.authorAvatar ? undefined : avatarFallbackStyle"
        >
          <template v-if="!contactDialog.target?.authorAvatar">
            {{ contactDialog.target?.authorInitials ?? 'TR' }}
          </template>
        </n-avatar>
        <div class="contact-modal__identity">
          <div class="contact-modal__name">
            {{ contactDialog.target?.authorName || 'Traveler' }}
          </div>
          <n-text v-if="contactDialog.target?.authorUsername" depth="3">
            @{{ contactDialog.target.authorUsername }}
          </n-text>
        </div>
      </n-space>
    </template>

    <n-space vertical size="large">
      <n-alert
        v-if="contactDialog.error"
        type="error"
        closable
        @close="contactDialog.error = ''"
      >
        {{ contactDialog.error }}
      </n-alert>

      <n-spin :show="contactDialog.loading">
        <div class="contact-thread" ref="contactThreadRef">
          <template v-if="contactDialog.messages.length">
            <div
              v-for="msg in contactDialog.messages"
              :key="msg.id ?? `${msg.sentAt}-${msg.senderId}`"
              :class="[
                'contact-thread__message',
                { 'contact-thread__message--own': isOwnMessage(msg) },
              ]"
            >
              <div class="contact-thread__timestamp">
                {{ formatMessageTimestamp(msg.sentAt) }}
              </div>
              <div class="contact-thread__bubble">
                {{ msg.content }}
              </div>
            </div>
          </template>
          <div v-else class="contact-thread__empty">
            Start the conversation with a friendly introduction.
          </div>
        </div>
      </n-spin>

      <n-input
        v-model:value="contactDialog.input"
        type="textarea"
        :autosize="{ minRows: 3, maxRows: 5 }"
        maxlength="2000"
        show-count
        placeholder="Introduce yourself, ask a question, or propose a collaboration."
      />

      <n-space justify="end">
        <n-button tertiary @click="closeContactDialog" :disabled="contactDialog.sending">
          Cancel
        </n-button>
        <n-button
          type="primary"
          :loading="contactDialog.sending"
          :disabled="!contactDialog.input.trim()"
          @click="sendContactMessage"
        >
          Send message
        </n-button>
      </n-space>
    </n-space>
  </n-modal>
</template>

<style scoped>
:global(body) {
  background: var(--body-color);
}

.sidebar-brand {
  display: flex;
  width: 100%;
}

.sidebar-brand__logo {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
}

.sidebar-brand__image {
  width: 38px;
  height: 38px;
  object-fit: contain;
  border-radius: 8px;
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.12);
}

.dashboard-main {
  display: block;
}

.weather-panel {
  width: 100%;
  max-width: none;
}

.weather-panel :deep(.n-card) {
  box-shadow: none;
  border-radius: 16px;
}

.community-panel {
  width: 100%;
  max-width: none;
}

.messages-panel {
  width: 100%;
  max-width: none;
  padding-bottom: 24px;
}

.notifications-panel {
  max-width: 960px;
  margin: 0 auto;
  padding-bottom: 24px;
}

.marketplace-panel {
  width: 100%;
  max-width: none;
  padding-bottom: 24px;
}

.contact-modal__identity {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.contact-modal__name {
  font-weight: 600;
  font-size: 1rem;
}

.contact-thread {
  max-height: 320px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding-right: 4px;
}

.contact-thread__message {
  max-width: 78%;
  align-self: flex-start;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.contact-thread__message--own {
  align-self: flex-end;
}

.contact-thread__bubble {
  background: rgba(15, 23, 42, 0.05);
  padding: 10px 14px;
  border-radius: 16px;
  color: #1f2933;
  line-height: 1.45;
  box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.04);
}

.contact-thread__message--own .contact-thread__bubble {
  background: rgba(24, 160, 88, 0.12);
  color: #14532d;
  box-shadow: inset 0 0 0 1px rgba(24, 160, 88, 0.18);
}

.contact-thread__timestamp {
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.45);
}

.contact-thread__empty {
  text-align: center;
  font-size: 0.9rem;
  color: rgba(15, 23, 42, 0.45);
  padding: 16px 0;
}
</style>
