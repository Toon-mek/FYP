<script setup>
import { computed, h, reactive, ref, watch, nextTick, provide, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { NIcon, NBadge, useMessage, useNotification } from 'naive-ui'
import TravelerWeatherWidget from './TravelerWeatherWidget.vue'
import BookingLiveStays from './BookingLiveStays.vue'
import TravelerSocialFeed from './TravelerSocialFeed.vue'
import TravelerSavedPosts from './TravelerSavedPosts.vue'
import TravelerMessages from './TravelerMessages.vue'
import TravelerMarketplace from './TravelerMarketplace.vue'
import NotificationCenter from './NotificationCenter.vue'
import TripPlannerModule from './TripPlannerModule.vue'
import TravelerSavedPlaces from './TravelerSavedPlaces.vue'
import TravelerBookingDashboard from './TravelerBookingDashboard.vue'
import TravelerPaymentDashboard from './TravelerPaymentDashboard.vue'
import TravelerPaymentHistory from './TravelerPaymentHistory.vue'
import { notificationFeedSymbol, useNotificationFeed } from '../composables/useNotificationFeed.js'
import { extractProfileImage } from '../utils/profileImage.js'
import { fetchConfirmedBookings } from '../services/paymentSimulationService.js'

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

const travelModeIcons = {
  airTakeoff: 'ri-flight-takeoff-line',
  airLand: 'ri-flight-land-line',
  ferryShip: 'ri-ship-line',
  ferryAnchor: 'ri-anchor-line',
  roadBus: 'ri-bus-2-line',
  roadCar: 'ri-car-line',
}
const travelModeLabels = {
  air: 'Wheels up',
  ferry: 'Casting off',
  road: 'Road trip',
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

const renderIconWithBadge = (name, count) => () => {
  const icon = h(NIcon, null, { default: () => h('i', { class: name }) })
  if (!count || count === 0) return icon
  return h(NBadge, { value: count, max: 99, show: count > 0 }, { default: () => icon })
}

const unreadNotificationCount = computed(() => {
  return travelerNotificationFeed.unreadCount?.value ?? 0
})

const baseUpcomingTrips = ref(Array.isArray(props.upcomingTrips) ? [...props.upcomingTrips] : [])
const confirmedUpcomingTrips = ref([])
const countdownTicker = ref(Date.now())
let countdownInterval = null

watch(
  () => props.upcomingTrips,
  (next) => {
    baseUpcomingTrips.value = Array.isArray(next) ? [...next] : []
  },
  { immediate: true },
)

const upcomingTrips = computed(() =>
  mergeTrips(baseUpcomingTrips.value, confirmedUpcomingTrips.value)
    .map((trip) => enhanceTripWithCountdown(trip, new Date(countdownTicker.value)))
    .sort(sortTripsChronologically),
)
const nextTrip = computed(() => upcomingTrips.value[0] ?? null)
const malaysiaClockDisplay = computed(() =>
  malaysiaClockFormatter.format(new Date(countdownTicker.value)),
)

onMounted(() => {
  if (typeof window === 'undefined') {
    return
  }
  countdownInterval = window.setInterval(() => {
    countdownTicker.value = Date.now()
  }, 1000)
})

onBeforeUnmount(() => {
  if (typeof window === 'undefined') {
    return
  }
  if (countdownInterval) {
    window.clearInterval(countdownInterval)
    countdownInterval = null
  }
})
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
const malaysiaClockFormatter = new Intl.DateTimeFormat(undefined, {
  timeZone: 'Asia/Kuala_Lumpur',
  dateStyle: 'medium',
  timeStyle: 'medium',
})
const tripShortDateFormatter = new Intl.DateTimeFormat(undefined, {
  month: 'short',
  day: 'numeric',
})
const tripLongDateFormatter = new Intl.DateTimeFormat(undefined, {
  month: 'short',
  day: 'numeric',
  year: 'numeric',
})
const tripWeekdayFormatter = new Intl.DateTimeFormat(undefined, {
  weekday: 'short',
  month: 'short',
  day: 'numeric',
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

const pausedTravelIconStyle = Object.freeze({
  left: 'calc(100% - 20px)',
  animation: 'none',
})

const avatarFallbackStyle = {
  background: 'linear-gradient(135deg, rgba(36, 198, 220, 0.18), rgba(81, 74, 157, 0.18))',
  color: '#1f2933',
  fontWeight: '600',
}

const sidebarOptions = [
  { key: 'dashboard', label: 'Dashboard Overview', icon: renderIcon('ri-compass-3-line') },
  { key: 'weather', label: 'Weather Outlook', icon: renderIcon('ri-sun-cloudy-line') },
  { key: 'community', label: 'Community Feed', icon: renderIcon('ri-hashtag') },
  { key: 'saved-posts', label: 'Saved Posts', icon: renderIcon('ri-bookmark-line') },
  { key: 'messages', label: 'Messages', get icon() { return renderIconWithBadge('ri-chat-3-line', unreadMessagesCount.value) } },
  { key: 'notifications', label: 'Notifications', get icon() { return renderIconWithBadge('ri-notification-3-line', unreadNotificationCount.value) } },
  { key: 'marketplace', label: 'Marketplace', icon: renderIcon('ri-store-3-line') },
  { key: 'trips', label: 'Trip planner', icon: renderIcon('ri-calendar-event-line') },
  { key: 'saved', label: 'Saved places', icon: renderIcon('ri-heart-3-line') },
  { key: 'booking-dashboard', label: 'Booking dashboard', icon: renderIcon('ri-cash-line') },
  { key: 'payment-dashboard', label: 'Payment simulations', icon: renderIcon('ri-bank-card-line') },
  { key: 'payment-history', label: 'Booking history', icon: renderIcon('ri-archive-2-line') },
]

const route = useRoute()
const router = useRouter()
const message = useMessage()
const notification = useNotification()
const selectableModules = sidebarOptions.filter((item) => !item.disabled).map((item) => item.key)
const selectedMenu = ref('dashboard')
const marketplaceRef = ref(null)
const travelerMessagesRef = ref(null)

const unreadMessagesCount = computed(() => {
  return travelerMessagesRef.value?.totalUnreadCount ?? totalUnreadFromPolling.value
})

// Background message polling for notifications
const BACKGROUND_MESSAGE_POLL_INTERVAL = 10000 // 10 seconds
let backgroundMessagePollHandle = null
const previousMessageCounts = new Map()
const totalUnreadFromPolling = ref(0)

async function pollMessagesInBackground() {
  const travelerId = currentTravelerId.value
  if (!travelerId) return
  
  try {
    const API_BASE = import.meta.env.VITE_API_BASE || '/api'
    const params = new URLSearchParams({
      view: 'threads',
      currentType: 'Traveler',
      currentId: String(travelerId),
    })
    const response = await fetch(`${API_BASE}/messages.php?${params.toString()}`)
    if (!response.ok) return
    
    const payload = await response.json()
    const threads = Array.isArray(payload?.threads) ? payload.threads : []
    
    // Calculate total unread count
    let totalUnread = 0
    
    // Check for new messages
    threads.forEach(thread => {
      const threadKey = `${thread.participantType}-${thread.participantId}`
      const previousUnread = previousMessageCounts.get(threadKey) || 0
      const currentUnread = thread.unreadCount || 0
      
      totalUnread += currentUnread
      
      if (currentUnread > previousUnread && currentUnread > 0 && selectedMenu.value !== 'messages') {
        // Show notification only if not viewing messages
        notification.info({
          title: `New message from ${thread.participantName || 'Unknown'}`,
          content: thread.lastMessageContent || 'You have a new message',
          duration: 5000,
          keepAliveOnHover: true,
        })
      }
      
      previousMessageCounts.set(threadKey, currentUnread)
    })
    
    totalUnreadFromPolling.value = totalUnread
  } catch (error) {
    console.error('Background message poll failed:', error)
  }
}

function startBackgroundMessagePolling() {
  stopBackgroundMessagePolling()
  backgroundMessagePollHandle = setInterval(pollMessagesInBackground, BACKGROUND_MESSAGE_POLL_INTERVAL)
  pollMessagesInBackground() // Initial poll
}

function stopBackgroundMessagePolling() {
  if (backgroundMessagePollHandle) {
    clearInterval(backgroundMessagePollHandle)
    backgroundMessagePollHandle = null
  }
}

onMounted(() => {
  startBackgroundMessagePolling()
})

onBeforeUnmount(() => {
  stopBackgroundMessagePolling()
})
const bookingDashboardPackage = ref(null)
const paymentDashboardPackage = ref(null)
const moduleRefreshKeys = reactive({})
sidebarOptions.forEach(({ key }) => {
  moduleRefreshKeys[key] = 0
})
const manualNavigationModules = new Set(['booking-dashboard', 'payment-dashboard'])

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
      if (selectedMenu.value !== moduleKey) {
        navigateToModule(moduleKey, {
          resetContext: manualNavigationModules.has(moduleKey),
          refreshIfSame: false,
        })
      }
    } else if (!moduleKey) {
      navigateToModule('dashboard', { refreshIfSame: false })
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
  router.replace({ query: nextQuery }).catch(() => { })
})

watch(selectedMenu, (value) => {
  refreshModule(value)
})

function handleBookingDashboardView(payload) {
  bookingDashboardPackage.value = payload?.package ?? null
  navigateToModule('booking-dashboard', { refreshIfSame: false })
}

function handleBookingDashboardBack() {
  navigateToModule('saved', { refreshIfSame: false })
}

function handlePaymentDashboardView(payload) {
  paymentDashboardPackage.value = payload?.package ?? bookingDashboardPackage.value ?? null
  navigateToModule('payment-dashboard', { refreshIfSame: false })
}

function handlePaymentDashboardBack() {
  navigateToModule('booking-dashboard', { refreshIfSame: false })
}

function handlePaymentAuthorized(payload) {
  const pkg = payload?.package ?? paymentDashboardPackage.value ?? bookingDashboardPackage.value
  const entry = createTripEntryFromPackage(pkg, payload)
  if (!entry) {
    return
  }
  confirmedUpcomingTrips.value = [
    entry,
    ...confirmedUpcomingTrips.value.filter((trip) => getTripKey(trip) !== getTripKey(entry)),
  ]
}

async function loadConfirmedBookings() {
  const travelerIdValue = currentTravelerId.value
  if (!travelerIdValue) {
    confirmedUpcomingTrips.value = []
    return
  }
  try {
    const { bookings } = await fetchConfirmedBookings(travelerIdValue)
    confirmedUpcomingTrips.value = (bookings ?? [])
      .map((booking) => createTripEntryFromBookingRecord(booking))
      .filter(Boolean)
  } catch (error) {
    message.warning(error?.message || 'Unable to load confirmed bookings.')
  }
}

watch(
  () => route.query.listingId,
  (listingId) => {
    if (!listingId) {
      return
    }
    navigateToModule('marketplace', { refreshIfSame: false })
    const numeric = Number(listingId)
    if (Number.isFinite(numeric)) {
      nextTick(() => {
        marketplaceRef.value?.openListingById?.(numeric)
      })
    }
    const nextQuery = { ...route.query }
    delete nextQuery.listingId
    router.replace({ query: nextQuery }).catch(() => { })
  },
  { immediate: true }
)

watch(
  currentTravelerId,
  (travelerId) => {
    if (travelerId) {
      loadConfirmedBookings()
    } else {
      confirmedUpcomingTrips.value = []
    }
  },
  { immediate: true }
)

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

function handleMenuSelect(val) {
  navigateToModule(val, { resetContext: manualNavigationModules.has(val) })
}

function refreshModule(key) {
  if (!key) {
    return
  }
  if (!Object.prototype.hasOwnProperty.call(moduleRefreshKeys, key)) {
    moduleRefreshKeys[key] = 0
  }
  moduleRefreshKeys[key] += 1
}

function resetModuleContext(key) {
  if (key === 'booking-dashboard') {
    bookingDashboardPackage.value = null
  }
  if (key === 'payment-dashboard') {
    paymentDashboardPackage.value = null
    bookingDashboardPackage.value = null
  }
}

function navigateToModule(key, { resetContext = false, refreshIfSame = true } = {}) {
  if (!key) {
    return
  }
  if (resetContext) {
    resetModuleContext(key)
  }
  if (selectedMenu.value === key) {
    if (refreshIfSame) {
      refreshModule(key)
    }
    return
  }
  selectedMenu.value = key
}

function openTripPlanner() {
  navigateToModule('trips')
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

function createTripEntryFromPackage(pkg, context = {}) {
  if (!pkg) {
    return null
  }
  const summary = pkg.summary ?? context.summary ?? {}
  const summaryDates = deriveDatesFromSummary(summary)
  return {
    id:
      context?.receipt?.receiptNo ||
      context?.session?.bookingRef ||
      pkg.packageId ||
      pkg.packageID ||
      `trip-${Date.now()}`,
    title: pkg.title || summary.title || 'Upcoming journey',
    location: summary.destination || pkg.destination || 'To be announced',
    duration: summary.dateRange || summary.durationLabel || 'Flexible dates',
    startDate:
      summary.startDate ||
      summaryDates.start ||
      pkg.startDate ||
      context?.session?.startDate ||
      null,
    endDate:
      summary.endDate ||
      summaryDates.end ||
      pkg.endDate ||
      context?.session?.endDate ||
      null,
    status: pkg.status || context.status || 'confirmed',
    focus:
      summary.curationNote ||
      summary.description ||
      'Payment confirmed. Your itinerary is now locked in.',
    paidAt: context?.receipt?.paidAt || context?.session?.updatedAt || new Date().toISOString(),
  }
}

function createTripEntryFromBookingRecord(record) {
  if (!record) {
    return null
  }
  const summary = record.packageSummary ?? {}
  const summaryDates = deriveDatesFromSummary(summary)
  return {
    id: record.receiptNo || record.bookingRef || `booking-${record.sessionId}`,
    title: record.packageTitle || summary.title || 'Upcoming journey',
    location: summary.destination || record.packageDestination || 'To be announced',
    duration: summary.dateRange || summary.durationLabel || 'Flexible dates',
    startDate:
      summary.startDate ||
      summaryDates.start ||
      record.packageSummary?.startDate ||
      record.startDate ||
      null,
    endDate:
      summary.endDate ||
      summaryDates.end ||
      record.packageSummary?.endDate ||
      record.endDate ||
      null,
    status: record.status || 'confirmed',
    focus:
      summary.curationNote ||
      summary.description ||
      (record.paidAt ? `Payment recorded ${formatTimelineDate(record.paidAt)}` : 'Payment recorded.'),
    paidAt: record.paidAt || record.updatedAt || record.createdAt,
  }
}

function mergeTrips(baseList, confirmedList) {
  const list = Array.isArray(baseList) ? [...baseList] : []
  ;(Array.isArray(confirmedList) ? confirmedList : []).forEach((trip) => {
    if (!trip) {
      return
    }
    const key = getTripKey(trip)
    const existingIndex = list.findIndex((item) => getTripKey(item) === key)
    if (existingIndex !== -1) {
      list.splice(existingIndex, 1)
    }
    list.unshift(trip)
  })
  return list
}

function sortTripsChronologically(a, b) {
  if (!a && !b) {
    return 0
  }
  if (!a) {
    return 1
  }
  if (!b) {
    return -1
  }
  const statusOrder = {
    'in-progress': -1,
    upcoming: 0,
    unscheduled: 1,
  }
  const aWeight = statusOrder[a.status] ?? 2
  const bWeight = statusOrder[b.status] ?? 2
  if (aWeight !== bWeight) {
    return aWeight - bWeight
  }
  const aStart = getTripStartTimestamp(a)
  const bStart = getTripStartTimestamp(b)
  if (aStart !== bStart) {
    return aStart - bStart
  }
  return (a.title || '').localeCompare(b.title || '')
}

function getTripStartTimestamp(trip) {
  if (!trip?.startDate) {
    return Number.POSITIVE_INFINITY
  }
  const timestamp = Date.parse(trip.startDate)
  return Number.isNaN(timestamp) ? Number.POSITIVE_INFINITY : timestamp
}

function getTripKey(trip) {
  if (!trip) {
    return ''
  }
  return trip.id || `${trip.title || 'trip'}-${trip.location || 'anywhere'}`
}

const DAY_MS = 24 * 60 * 60 * 1000

function normaliseTripStatus(value) {
  if (!value) {
    return ''
  }
  return String(value).trim().toLowerCase()
}

function enhanceTripWithCountdown(trip, referenceDate = new Date()) {
  if (!trip) return trip
  const bounds = resolveTripBounds(trip)
  const sourceStatus = normaliseTripStatus(trip.status)
  const countdown = buildTripCountdownInfo(bounds, referenceDate, sourceStatus)
  const status = determineTripStatus(bounds, referenceDate, sourceStatus)
  const travelMode = determineTripTravelMode(trip)
  return {
    ...trip,
    startDate: bounds.start ? bounds.start.toISOString() : trip.startDate ?? null,
    endDate: bounds.end ? bounds.end.toISOString() : trip.endDate ?? null,
    countdownLabel: countdown.label,
    countdownValue: countdown.value,
    countdownUnit: countdown.unit,
    countdownTone: countdown.tone,
    countdownState: countdown.state,
    status: status.value,
    statusLabel: status.label,
    statusTone: status.tone,
    displayDateRange: formatTripDateRange(bounds),
    dayRangeLabel: formatTripDayRange(bounds),
    sourceStatus: sourceStatus || null,
    travelMode,
  }
}

function resolveTripBounds(trip) {
  const parsedRange = parseDateRangeLabel(trip.duration || '')
  const start = normaliseDateInput(trip.startDate) || parsedRange.start
  const end = normaliseDateInput(trip.endDate) || parsedRange.end
  return { start, end }
}

function determineTripStatus(bounds, now = new Date(), providedStatus = '') {
  const { start, end } = bounds
  const normalized = normaliseTripStatus(providedStatus)
  if (normalized === 'cancelled') {
    return { value: 'cancelled', label: 'Cancelled', tone: 'error' }
  }
  if (normalized === 'refunded') {
    return { value: 'refunded', label: 'Refunded', tone: 'warning' }
  }
  if (normalized === 'pending' || normalized === 'awaiting_authorization') {
    return { value: 'pending', label: 'Pending payment', tone: 'warning' }
  }
  if (normalized === 'failed') {
    return { value: 'failed', label: 'Payment failed', tone: 'error' }
  }
  if (normalized === 'processing' || normalized === 'authorizing') {
    return { value: 'processing', label: 'Processing', tone: 'info' }
  }
  if (normalized === 'authorized' || normalized === 'authorised') {
    return { value: 'authorized', label: 'Authorized', tone: 'success' }
  }
  if (normalized === 'confirmed') {
    return { value: 'confirmed', label: 'Confirmed', tone: 'info' }
  }
  if (normalized === 'completed') {
    return { value: 'completed', label: 'Completed', tone: 'default' }
  }
  // Treat confirmed / authorized bookings using timeline position
  if (end && now > end) {
    return { value: 'completed', label: 'Completed', tone: 'default' }
  }
  if (start && now >= start && (!end || now <= end)) {
    return { value: 'in-progress', label: 'In progress', tone: 'success' }
  }
  if (!start && end) {
    return now <= end
      ? { value: 'in-progress', label: 'In progress', tone: 'success' }
      : { value: 'completed', label: 'Completed', tone: 'default' }
  }
  if (start && now < start) {
    return { value: 'upcoming', label: 'Upcoming', tone: 'info' }
  }
  return { value: 'unscheduled', label: 'Awaiting dates', tone: 'warning' }
}

function buildTripCountdownInfo(bounds, now = new Date(), providedStatus = '') {
  const { start, end } = bounds
  const normalized = normaliseTripStatus(providedStatus)
  if (normalized === 'cancelled') {
    return {
      label: 'Booking cancelled',
      value: null,
      unit: '',
      tone: 'error',
      state: 'cancelled',
    }
  }
  if (normalized === 'refunded') {
    return {
      label: 'Payment refunded',
      value: null,
      unit: '',
      tone: 'warning',
      state: 'refunded',
    }
  }
  if (normalized === 'pending' || normalized === 'awaiting_authorization') {
    return {
      label: 'Awaiting payment authorization',
      value: null,
      unit: '',
      tone: 'warning',
      state: 'open',
    }
  }
  if (normalized === 'failed') {
    return {
      label: 'Payment failed',
      value: null,
      unit: '',
      tone: 'error',
      state: 'urgent',
    }
  }

  if (!start) {
    if (end && now > end) {
      return {
        label: 'Trip completed',
        value: null,
        unit: '',
        tone: 'default',
        state: 'done',
      }
    }
    return {
      label: 'Awaiting schedule',
      value: null,
      unit: '',
      tone: 'warning',
      state: 'open',
    }
  }
  if (now < start) {
    const diffMs = start - now
    const diffDays = Math.floor(diffMs / DAY_MS)
    if (diffDays > 1) {
      return {
        label: `Starts in ${diffDays} days`,
        value: diffDays,
        unit: diffDays === 1 ? 'day' : 'days',
        tone: diffDays <= 7 ? 'warning' : 'info',
        state: diffDays <= 3 ? 'soon' : 'calm',
      }
    }
    if (diffDays === 1) {
      return {
        label: 'Starts tomorrow',
        value: 1,
        unit: 'day',
        tone: 'warning',
        state: 'soon',
      }
    }
    const diffHours = Math.max(1, Math.round(diffMs / (60 * 60 * 1000)))
    return {
      label: diffHours <= 1 ? 'Starts within the hour' : `Starts in ${diffHours} hours`,
      value: diffHours,
      unit: diffHours === 1 ? 'hour' : 'hours',
      tone: 'error',
      state: 'urgent',
    }
  }
  if (end && now <= end) {
    return {
      label: 'Trip in progress',
      value: null,
      unit: '',
      tone: 'success',
      state: 'live',
    }
  }
  if (!end) {
    return {
      label: 'Trip in progress',
      value: null,
      unit: '',
      tone: 'success',
      state: 'live',
    }
  }
  return {
    label: 'Trip completed',
    value: null,
    unit: '',
    tone: 'default',
    state: 'done',
  }
}

function normaliseDateInput(value) {
  if (!value) return null
  if (value instanceof Date) {
    const copy = new Date(value.getTime())
    copy.setHours(0, 0, 0, 0)
    return copy
  }
  if (typeof value === 'number') {
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return null
    date.setHours(0, 0, 0, 0)
    return date
  }
  if (typeof value === 'string') {
    const trimmed = value.trim()
    if (!trimmed) return null
    const parsed = new Date(trimmed)
    if (Number.isNaN(parsed.getTime())) return null
    parsed.setHours(0, 0, 0, 0)
    return parsed
  }
  return null
}

function parseDateRangeLabel(value) {
  if (!value || typeof value !== 'string') {
    return { start: null, end: null }
  }
  const normalised = value.replace('–', '-')
  if (!normalised.includes('-')) {
    return { start: null, end: null }
  }
  const [startPart, endPart] = normalised.split('-').map((part) => part.trim())
  return {
    start: normaliseDateInput(startPart),
    end: normaliseDateInput(endPart),
  }
}

function formatTimelineDate(value) {
  if (!value) {
    return ''
  }
  try {
    return messageTimestampFormatter.format(new Date(value))
  } catch (error) {
    return value
  }
}

function deriveDatesFromSummary(summary) {
  if (!summary || typeof summary !== 'object') {
    return { start: null, end: null }
  }
  const durationLabel = typeof summary.durationLabel === 'string' ? summary.durationLabel : ''
  const dateRangeLabel = typeof summary.dateRange === 'string' ? summary.dateRange : ''
  const isoMatches =
    durationLabel.match(/\d{4}-\d{2}-\d{2}/g) || dateRangeLabel.match(/\d{4}-\d{2}-\d{2}/g) || []
  if (isoMatches.length) {
    return {
      start: isoMatches[0] || null,
      end: isoMatches[1] || isoMatches[0] || null,
    }
  }
  return {
    start: summary.startDate || summary.start || null,
    end: summary.endDate || summary.end || null,
  }
}

function determineTripTravelMode(trip) {
  const label = String(trip?.location || trip?.title || '').toLowerCase()
  if (!label) {
    return 'road'
  }
  const ferryDestinations = [
    'tioman',
    'redang',
    'perhentian',
    'pangkor',
    'kapas',
    'tenggol',
    'lang tengah',
    'sibu island',
    'raut',
    'pulau',
  ]
  const airDestinations = [
    'langkawi',
    'sabah',
    'sarawak',
    'borneo',
    'penang',
    'kota kinabalu',
    'sandakan',
    'tawau',
    'miri',
    'kuching',
    'labuan',
  ]
  if (ferryDestinations.some((keyword) => label.includes(keyword))) {
    return 'ferry'
  }
  if (airDestinations.some((keyword) => label.includes(keyword))) {
    return 'air'
  }
  return 'road'
}

const travelIconVariantRandomiser = (() => {
  const registry = {
    road: { toggle: false, cache: new Map(), variants: ['roadCar', 'roadBus'] },
    ferry: { toggle: false, cache: new Map(), variants: ['ferryShip', 'ferryAnchor'] },
  }
  return (mode, tripKey) => {
    const entry = registry[mode]
    if (!entry) {
      return null
    }
    const key = tripKey || ''
    if (entry.cache.has(key)) {
      return entry.cache.get(key)
    }
    entry.toggle = !entry.toggle
    const variant = entry.variants[entry.toggle ? 0 : 1]
    entry.cache.set(key, variant)
    return variant
  }
})()

const travelVariantClassMap = {
  roadCar: 'journey-travel-icon--roadcar',
  roadBus: 'journey-travel-icon--road',
  airTakeoff: 'journey-travel-icon--air',
  airLand: 'journey-travel-icon--airland',
  ferryShip: 'journey-travel-icon--ferry',
  ferryAnchor: 'journey-travel-icon--ferryalt',
}

function getTravelModeIconData(mode, tripKey) {
  const variant = travelIconVariantRandomiser(mode, tripKey)
  if (variant) {
    return {
      icon: travelModeIcons[variant] ?? travelModeIcons.roadBus,
      class: travelVariantClassMap[variant] ?? 'journey-travel-icon--road',
    }
  }
  const fallbackKey =
    mode === 'air' ? 'airTakeoff' : mode === 'ferry' ? 'ferryShip' : mode === 'road' ? 'roadBus' : 'roadBus'
  return {
    icon: travelModeIcons[fallbackKey],
    class: travelVariantClassMap[fallbackKey] ?? 'journey-travel-icon--road',
  }
}

function getTravelModeLabel(mode) {
  return travelModeLabels[mode] ?? travelModeLabels.road
}

function formatTripDateRange(bounds) {
  const { start, end } = bounds
  if (start && end) {
    const sameYear = start.getFullYear() === end.getFullYear()
    const formatter = sameYear ? tripShortDateFormatter : tripLongDateFormatter
    const startLabel = formatter.format(start)
    const endLabel = tripLongDateFormatter.format(end)
    return `${startLabel} → ${endLabel}`
  }
  if (start) {
    return `From ${tripLongDateFormatter.format(start)}`
  }
  if (end) {
    return `Until ${tripLongDateFormatter.format(end)}`
  }
  return ''
}

function formatTripDayRange(bounds) {
  const { start, end } = bounds
  if (start && end) {
    return `${tripWeekdayFormatter.format(start)} → ${tripWeekdayFormatter.format(end)}`
  }
  if (start) {
    return tripWeekdayFormatter.format(start)
  }
  if (end) {
    return tripWeekdayFormatter.format(end)
  }
  return ''
}

const hasTrips = computed(() => upcomingTrips.value.length > 0)

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
const toggleSidebar = () => {
  sidebarCollapsed.value = !sidebarCollapsed.value
}
const handleSidebarClick = (event) => {
  if (event.target.closest('.n-menu')) {
    return
  }
  toggleSidebar()
}

defineExpose({
  jumpToModule: handleMenuSelect,
})
</script>

<template>
  <n-layout has-sider style="min-height: 100vh; background: var(--body-color);">
    <n-layout-sider bordered collapse-mode="width" :collapsed-width="64" :width="220" :collapsed="sidebarCollapsed"
      @collapse="sidebarCollapsed = true" @expand="sidebarCollapsed = false" @click="handleSidebarClick">
      <n-space vertical size="small" class="sidebar-brand"
        :style="sidebarCollapsed ? collapsedSidebarStyle : expandedSidebarStyle">
        <div class="sidebar-brand__logo">
          <img v-if="sidebarCollapsed" :src="COLLAPSED_LOGO_SRC" alt="Traveler Hub logo" class="sidebar-brand__image" />
          <n-gradient-text v-else type="info" style="font-size: 1.1rem; font-weight: 600;">
            Traveler Hub
          </n-gradient-text>
        </div>
        <n-text v-if="!sidebarCollapsed" depth="3">Navigate modules</n-text>
      </n-space>
      <div :style="sidebarCollapsed ? collapsedMenuContainerStyle : expandedMenuContainerStyle">
        <n-menu :options="sidebarOptions" :value="selectedMenu" :indent="16" :collapsed="sidebarCollapsed"
          :collapsed-icon-size="20" @update:value="handleMenuSelect" @click.stop />
      </div>
    </n-layout-sider>

    <n-layout>
      <n-layout-header bordered style="padding: 20px 32px; background: transparent;">
          <n-space justify="space-between" align="center" wrap>
            <n-space align="center" size="large">
              <n-avatar round size="large" :src="traveler.avatarUrl || undefined"
                :style="traveler.avatarUrl ? undefined : avatarFallbackStyle">
                <template v-if="!traveler.avatarUrl">{{ traveler.initials }}</template>
              </n-avatar>
            <div>
              <n-text depth="3">Hello, traveler</n-text>
              <div style="font-size: 1.35rem; font-weight: 600;">
                {{ traveler.displayName }}
              </div>
              </div>
            </n-space>
          <div class="header-clock" aria-live="polite">{{ malaysiaClockDisplay }}</div>
        </n-space>
      </n-layout-header>

      <n-layout-content embedded style="padding: 24px 32px;">
        <div v-if="selectedMenu === 'weather'" class="weather-panel">
          <TravelerWeatherWidget :key="`weather-${moduleRefreshKeys.weather}`" />
        </div>
        <div v-else-if="selectedMenu === 'community'" class="community-panel">
          <TravelerSocialFeed
            :key="`community-${moduleRefreshKeys.community}`"
            :posts="communityFeedPosts"
            :categories="communityFeedCategories"
            :current-user="traveler"
            @contact="handleCommunityContact" />
        </div>
        <div v-else-if="selectedMenu === 'saved-posts'" class="community-panel scroll-panel">
          <TravelerSavedPosts
            :key="`saved-posts-${moduleRefreshKeys['saved-posts']}`"
            :categories="communityFeedCategories"
            :current-user="traveler"
          />
        </div>
        <div v-else-if="selectedMenu === 'messages'" class="messages-panel">
          <TravelerMessages
            ref="travelerMessagesRef"
            :key="`messages-${moduleRefreshKeys.messages}`"
            :current-user="traveler"
          />
        </div>
        <div v-else-if="selectedMenu === 'notifications'">
          <NotificationCenter
            :key="`notifications-${moduleRefreshKeys.notifications}`"
            recipient-type="Traveler"
            :recipient-id="currentTravelerId"
            title="Traveler notifications"
            description="Admins and operators share updates with you here." />
        </div>
        <div v-else-if="selectedMenu === 'marketplace'" class="marketplace-panel scroll-panel">
          <TravelerMarketplace
            :key="`marketplace-${moduleRefreshKeys.marketplace}`"
            ref="marketplaceRef"
            :current-user="traveler"
            @contact="handleMarketplaceContact"
          />
        </div>
        <div v-else-if="selectedMenu === 'trips'" class="trip-planner-panel">
          <TripPlannerModule
            :key="`trips-${moduleRefreshKeys.trips}`"
            :traveler-id="currentTravelerId"
            :traveler-name="traveler.displayName"
          />
        </div>
        <div v-else-if="selectedMenu === 'saved'" class="saved-places-panel">
          <TravelerSavedPlaces
            :key="`saved-${moduleRefreshKeys.saved}`"
            :traveler-id="currentTravelerId"
            @view-booking-dashboard="handleBookingDashboardView"
          />
        </div>
        <div v-else-if="selectedMenu === 'booking-dashboard'" class="booking-dashboard-panel">
          <TravelerBookingDashboard
            :key="`booking-${moduleRefreshKeys['booking-dashboard']}`"
            :package-data="bookingDashboardPackage"
            @back-to-saved="handleBookingDashboardBack"
            @view-payment-dashboard="handlePaymentDashboardView"
          />
        </div>
        <div v-else-if="selectedMenu === 'payment-dashboard'" class="booking-dashboard-panel">
          <TravelerPaymentDashboard
            :key="`payment-${moduleRefreshKeys['payment-dashboard']}`"
            :package-data="paymentDashboardPackage || bookingDashboardPackage"
            @back-to-booking="handlePaymentDashboardBack"
            @payment-authorized="handlePaymentAuthorized"
          />
        </div>
        <div v-else-if="selectedMenu === 'payment-history'" class="booking-dashboard-panel">
          <TravelerPaymentHistory
            :key="`history-${moduleRefreshKeys['payment-history']}`"
            :traveler-id="currentTravelerId"
          />
        </div>
        <div v-else class="dashboard-main scroll-panel" :key="`dashboard-${moduleRefreshKeys.dashboard}`">
          <n-space vertical size="large">
            <n-grid cols="1 m:3" :x-gap="16" :y-gap="16">
              <n-grid-item span="1 m:2">
                <n-card title="Upcoming journeys" :segmented="{ content: true }">
                  <template #header-extra>
                    <n-tag v-if="hasTrips" round size="small" type="success">
                      {{ upcomingTrips.length }} {{ upcomingTrips.length === 1 ? 'journey' : 'journeys' }}
                    </n-tag>
                  </template>
                  <template v-if="hasTrips">
                    <div class="journey-list" aria-live="polite">
                      <article
                        v-for="trip in upcomingTrips"
                        :key="trip.id ?? trip.title"
                        class="journey-card"
                        :class="[
                          `journey-card--${trip.status}`,
                          trip.countdownState ? `journey-card--${trip.countdownState}` : null,
                        ]"
                      >
                        <div class="journey-card__header">
                          <div>
                            <div class="journey-card__eyebrow">{{ trip.location }}</div>
                            <div class="journey-card__title">{{ trip.title }}</div>
                          </div>
                          <n-tag round size="small" :type="trip.statusTone">
                            {{ trip.statusLabel }}
                          </n-tag>
                        </div>
                        <div class="journey-card__dates-row">
                          <div class="journey-card__dates">
                            <i class="ri-calendar-line" aria-hidden="true"></i>
                            <span>{{ trip.displayDateRange || trip.duration }}</span>
                          </div>
                          <div class="journey-card__countdown" v-if="trip.countdownValue !== null">
                            <div class="journey-countdown-chip">
                              <span class="journey-countdown-chip__value">{{ trip.countdownValue }}</span>
                              <span class="journey-countdown-chip__unit">{{ trip.countdownUnit }}</span>
                            </div>
                            <span class="journey-countdown-chip__label">{{ trip.countdownLabel }}</span>
                          </div>
                          <div class="journey-card__countdown journey-card__countdown--text" v-else>
                            {{ trip.countdownLabel }}
                          </div>
                        </div>
                        <div class="journey-card__travel">
                          <div
                            class="journey-travel-track"
                            :class="`journey-travel-track--${trip.travelMode}`"
                          >
                            <span class="journey-travel-track__dot journey-travel-track__dot--origin"></span>
                            <span class="journey-travel-track__line"></span>
                            <span class="journey-travel-track__dot journey-travel-track__dot--dest"></span>
                            <span
                              class="journey-travel-icon"
                              :class="[
                                getTravelModeIconData(trip.travelMode, getTripKey(trip)).class,
                                { 'journey-travel-icon--paused': trip.status === 'in-progress' },
                              ]"
                              :style="trip.status === 'in-progress' ? pausedTravelIconStyle : undefined">
                              <i :class="getTravelModeIconData(trip.travelMode, getTripKey(trip)).icon" aria-hidden="true"></i>
                            </span>
                          </div>
                          <div class="journey-travel-track__labels">
                            <span>{{ getTravelModeLabel(trip.travelMode) }}</span>
                            <span>{{ trip.location }}</span>
                          </div>
                        </div>
                        <p class="journey-card__focus">{{ trip.focus }}</p>
                      </article>
                    </div>
                  </template>
                  <template v-else>
                    <n-empty description="No upcoming trips scheduled.">
                      <n-button type="primary" size="small" @click="openTripPlanner">
                        Plan your next escape
                      </n-button>
                    </n-empty>
                  </template>
                </n-card>
              </n-grid-item>
            </n-grid>
          </n-space>
        </div>
      </n-layout-content>
    </n-layout>
  </n-layout>

  <n-modal v-model:show="contactDialog.visible" preset="card" :mask-closable="false" :closable="false"
    style="max-width: 560px">
    <template #header>
      <n-space align="center" size="small">
        <n-avatar round size="medium" :src="contactDialog.target?.authorAvatar || undefined"
          :style="contactDialog.target?.authorAvatar ? undefined : avatarFallbackStyle">
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
      <n-alert v-if="contactDialog.error" type="error" closable @close="contactDialog.error = ''">
        {{ contactDialog.error }}
      </n-alert>

      <n-spin :show="contactDialog.loading">
        <div class="contact-thread" ref="contactThreadRef">
          <template v-if="contactDialog.messages.length">
            <div v-for="msg in contactDialog.messages" :key="msg.id ?? `${msg.sentAt}-${msg.senderId}`" :class="[
              'contact-thread__message',
              { 'contact-thread__message--own': isOwnMessage(msg) },
            ]">
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

      <n-input v-model:value="contactDialog.input" type="textarea" :autosize="{ minRows: 3, maxRows: 5 }"
        maxlength="2000" show-count placeholder="Introduce yourself, ask a question, or propose a collaboration." />

      <n-space justify="end">
        <n-button tertiary @click="closeContactDialog" :disabled="contactDialog.sending">
          Cancel
        </n-button>
        <n-button type="primary" :loading="contactDialog.sending" :disabled="!contactDialog.input.trim()"
          @click="sendContactMessage">
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

.scroll-panel {
  max-height: clamp(420px, 70vh, 860px);
  overflow-y: auto;
  padding-right: 6px;
  scrollbar-gutter: stable;
  overscroll-behavior: contain;
}

.scroll-panel::-webkit-scrollbar {
  width: 8px;
}

.scroll-panel::-webkit-scrollbar-thumb {
  background: rgba(15, 59, 39, 0.25);
  border-radius: 999px;
}

.scroll-panel::-webkit-scrollbar-track {
  background: transparent;
}

@media (max-width: 768px) {
  .scroll-panel {
    max-height: none;
    overflow: visible;
    padding-right: 0;
  }
}

.trip-planner-panel {
  width: 100%;
}

.saved-places-panel {
  width: 100%;
  margin: 0;
}

.messages-panel {
  width: 100%;
  max-width: none;
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

.journey-list {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
  align-items: stretch;
}

.header-clock {
  padding: 10px 20px;
  border-radius: 18px;
  background: radial-gradient(circle at 10% 20%, rgba(59, 130, 246, 0.16), rgba(16, 185, 129, 0.1));
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
  font-weight: 600;
  font-size: 1.05rem;
  color: #0f172a;
  white-space: nowrap;
}

.journey-card {
  border: 1px solid rgba(15, 23, 42, 0.05);
  border-radius: 18px;
  padding: 20px;
  background: #fff;
  box-shadow: 0 12px 32px rgba(15, 23, 42, 0.05);
  transition: transform 0.25s ease, box-shadow 0.25s ease;
}

.journey-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
}

.journey-card__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
}

.journey-card__eyebrow {
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: rgba(15, 23, 42, 0.5);
  margin-bottom: 4px;
}

.journey-card__title {
  font-size: 1.1rem;
  font-weight: 600;
  color: #0f172a;
}

.journey-card__dates-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 12px;
}

.journey-card__dates {
  display: flex;
  align-items: center;
  gap: 8px;
  color: rgba(15, 23, 42, 0.78);
  font-weight: 500;
}

.journey-card__dates i {
  color: rgba(15, 23, 42, 0.3);
  font-size: 1.1rem;
}

.journey-card__countdown {
  display: flex;
  align-items: center;
  gap: 12px;
  font-weight: 600;
  color: #0f172a;
}

.journey-card__countdown--text {
  font-size: 0.95rem;
  color: rgba(15, 23, 42, 0.65);
}

.journey-card__travel {
  margin-top: 14px;
}

.journey-travel-track {
  position: relative;
  height: 34px;
  border-radius: 999px;
  background: linear-gradient(90deg, rgba(14, 165, 233, 0.12), rgba(59, 130, 246, 0.08));
  overflow: hidden;
}

.journey-travel-track::after {
  content: '';
  position: absolute;
  inset: 0;
  background: rgba(14, 165, 233, 0.18);
  opacity: 0.5;
  pointer-events: none;
  z-index: 0;
}

.journey-travel-track__line {
  position: absolute;
  left: 20px;
  right: 20px;
  top: 50%;
  height: 2px;
  transform: translateY(-50%);
  background: rgba(15, 23, 42, 0.15);
  border-radius: 999px;
  overflow: hidden;
  z-index: 1;
}

.journey-travel-track__dot {
  position: absolute;
  top: 50%;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #0ea5e9;
  transform: translate(-50%, -50%);
  box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.15);
  z-index: 2;
}

.journey-travel-track__dot--origin {
  left: 20px;
}

.journey-travel-track__dot--dest {
  right: 20px;
  background: #2563eb;
  box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
}

.journey-travel-icon {
  position: absolute;
  top: 50%;
  left: 20px;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 10px 20px rgba(15, 23, 42, 0.15);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.1rem;
  transform: translate(-50%, -50%);
  animation: none;
  z-index: 3;
}

.journey-travel-icon--air {
  color: #2563eb;
  animation: journey-travel-air 7s ease-in-out infinite;
}
.journey-travel-icon--airland {
  color: #38bdf8;
  animation: journey-travel-air 7s ease-in-out infinite;
}

.journey-travel-icon--ferry {
  color: #0ea5e9;
  animation: journey-travel-ferry 8s ease-in-out infinite;
}
.journey-travel-icon--ferryalt {
  color: #0891b2;
  animation: journey-travel-ferry 8s ease-in-out infinite;
}

.journey-travel-icon--road {
  color: #10b981;
  animation: journey-travel-road 6s ease-in-out infinite;
}
.journey-travel-icon--roadcar {
  color: #f97316;
  animation: journey-travel-road 6s ease-in-out infinite;
}

.journey-travel-track__labels {
  display: flex;
  justify-content: space-between;
  font-size: 0.75rem;
  margin-top: 6px;
  color: rgba(15, 23, 42, 0.55);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.journey-travel-track__labels span:last-child {
  color: rgba(37, 99, 235, 0.9);
}

.journey-travel-track--road .journey-travel-track__line {
  background: rgba(16, 185, 129, 0.55);
}

.journey-travel-track--ferry .journey-travel-track__line {
  background: rgba(14, 165, 233, 0.6);
}

.journey-travel-track--air .journey-travel-track__line {
  background: rgba(79, 70, 229, 0.55);
}

@keyframes journey-travel-road {
  0% {
    left: 20px;
  }
  50% {
    left: calc(100% - 20px);
  }
  100% {
    left: 20px;
  }
}

@keyframes journey-travel-ferry {
  0% {
    left: 20px;
    margin-top: 0;
  }
  25% {
    margin-top: -4px;
  }
  50% {
    left: calc(100% - 20px);
    margin-top: 4px;
  }
  75% {
    margin-top: -2px;
  }
  100% {
    left: 20px;
    margin-top: 0;
  }
}

@keyframes journey-travel-air {
  0% {
    left: 20px;
    top: 50%;
    transform: translate(-50%, -50%) rotate(0deg);
  }
  25% {
    top: 37%;
    transform: translate(-50%, -50%) rotate(12deg);
  }
  50% {
    left: calc(100% - 20px);
    top: 62%;
    transform: translate(-50%, -50%) rotate(-10deg);
  }
  75% {
    top: 45%;
    transform: translate(-50%, -50%) rotate(14deg);
  }
  100% {
    left: 20px;
    top: 50%;
    transform: translate(-50%, -50%) rotate(0deg);
  }
}

.journey-travel-icon--paused {
  animation: none !important;
  animation-play-state: paused;
}

@keyframes journey-track-flow {
  0% {
    background-position: 0% 50%;
  }
  50% {
    background-position: 100% 50%;
  }
  100% {
    background-position: 0% 50%;
  }
}

@keyframes journey-line-scroll {
  0% {
    background-position: 0 0;
  }
  100% {
    background-position: 120px 0;
  }
}

.journey-countdown-chip {
  display: flex;
  align-items: baseline;
  gap: 6px;
  background: rgba(59, 130, 246, 0.12);
  color: #1d4ed8;
  padding: 6px 12px;
  border-radius: 999px;
}

.journey-countdown-chip__value {
  font-size: 1.2rem;
  font-weight: 700;
  line-height: 1;
}

.journey-countdown-chip__unit {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.journey-countdown-chip__label {
  font-size: 0.9rem;
  color: rgba(15, 23, 42, 0.7);
}

.journey-card__focus {
  margin: 12px 0 0;
  color: rgba(15, 23, 42, 0.75);
  line-height: 1.4;
}

.journey-card--in-progress {
  border-color: rgba(16, 185, 129, 0.45);
  box-shadow: 0 12px 32px rgba(5, 150, 105, 0.1);
}

.journey-card--unscheduled,
.journey-card--open {
  border-style: dashed;
}

.journey-card--live {
  background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(59, 130, 246, 0.05));
}

.journey-card--soon {
  border-color: rgba(251, 191, 36, 0.55);
}

.journey-card--urgent {
  border-color: rgba(239, 68, 68, 0.45);
  background: linear-gradient(135deg, rgba(239, 68, 68, 0.08), rgba(251, 191, 36, 0.05));
}

.journey-card--calm {
  border-color: rgba(59, 130, 246, 0.2);
}

.journey-card--done {
  opacity: 0.75;
}

.journey-card--confirmed {
  border-color: rgba(37, 99, 235, 0.35);
}

.journey-card--authorized {
  border-color: rgba(16, 185, 129, 0.55);
}

.journey-card--cancelled {
  border-color: rgba(239, 68, 68, 0.5);
  background: linear-gradient(135deg, rgba(239, 68, 68, 0.05), rgba(15, 23, 42, 0.02));
}

.journey-card--refunded {
  border-color: rgba(234, 179, 8, 0.45);
  background: linear-gradient(135deg, rgba(234, 179, 8, 0.08), rgba(15, 23, 42, 0.02));
}

.journey-card--pending,
.journey-card--processing {
  border-style: dashed;
}

.journey-card--failed {
  border-color: rgba(220, 38, 38, 0.45);
  background: linear-gradient(135deg, rgba(220, 38, 38, 0.08), rgba(239, 68, 68, 0.03));
}

@media (max-width: 1400px) {
  .journey-list {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 1200px) {
  .journey-list {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 768px) {
  .journey-list {
    grid-template-columns: 1fr;
  }

  .header-clock {
    width: 100%;
    text-align: left;
  }

  .journey-travel-track__labels {
    flex-direction: column;
    gap: 2px;
  }

  .journey-card__header {
    flex-direction: column;
  }

  .journey-card__countdown {
    justify-content: flex-start;
    width: 100%;
  }
}
</style>
