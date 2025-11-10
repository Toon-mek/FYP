<script setup>
import { computed, h, nextTick, onBeforeUnmount, onMounted, provide, ref, watch } from "vue"
import { useRoute, useRouter } from "vue-router"
import {
  NAlert,
  NAvatar,
  NButton,
  NCard,
  NEmpty,
  NGradientText,
  NGrid,
  NGridItem,
  NIcon,
  NLayout,
  NLayoutContent,
  NLayoutHeader,
  NLayoutSider,
  NList,
  NListItem,
  NMenu,
  NModal,
  NSpace,
  NSwitch,
  NTag,
  NText,
} from "naive-ui"
import BusinessOperatorGuidelines from "./BusinessOperatorGuidelines.vue"
import BusinessOperatorManageListings from "./BusinessOperatorManageListings.vue"
import BusinessOperatorMediaManager from "./BusinessOperatorMediaManager.vue"
import BusinessOperatorUploadInfo from "./BusinessOperatorUploadInfo.vue"
import SimplePagination from "./shared/SimplePagination.vue"
import { extractProfileImage } from "../utils/profileImage.js"
import NotificationCenter from "./NotificationCenter.vue"
import { notificationFeedSymbol, useNotificationFeed } from "../composables/useNotificationFeed.js"

const props = defineProps({
  operator: {
    type: Object,
    default: () => null,
  },
  listings: {
    type: Array,
    default: () => [],
  },
  mediaAssets: {
    type: Array,
    default: () => [],
  },
})

const route = useRoute()
const router = useRouter()

const API_BASE = import.meta.env.VITE_API_BASE || "/api"
const COLLAPSED_LOGO_SRC = "/Tourism Operator Hub.png"
const avatarFallbackStyle = {
  background: "var(--primary-color-hover)",
  color: "white",
}

function deriveAvatarInfo(source) {
  return extractProfileImage(source)
}

const defaultOperator = {
  fullName: "Operator",
  username: "operator01",
}

const remoteOperator = ref(null)
const isDashboardLoading = ref(false)
const dashboardError = ref(null)
const lastLoadedOperatorId = ref(null)

const listings = ref([])
const mediaLibrary = ref([])
const removalHistory = ref([])
const isRemovalHistoryLoading = ref(false)
const removalHistoryError = ref(null)
const lastRemovalHistoryOperatorId = ref(null)
const operatorListingTouches = ref({})
const RECENT_ACTIVITY_PAGE_SIZE = 3
const recentActivityPage = ref(1)
const storageAvailable =
  typeof window !== "undefined" && typeof window.localStorage !== "undefined"

function normaliseOperatorId(value) {
  if (value == null) {
    return null
  }
  if (typeof value === "number" && Number.isFinite(value)) {
    return value > 0 ? value : null
  }
  const asString = String(value).trim()
  if (asString === "") {
    return null
  }
  const numeric = Number(asString)
  if (!Number.isNaN(numeric) && numeric > 0) {
    return numeric
  }
  const match = asString.match(/(\d+)/)
  if (match && match[1]) {
    const parsed = Number(match[1])
    return Number.isNaN(parsed) ? null : parsed
  }
  return null
}

function deriveOperatorIdFromSource(source) {
  if (!source || typeof source !== "object") {
    return null
  }
  const keyCandidates = [
    "id",
    "operatorId",
    "operatorID",
    "operator_id",
    "accountId",
    "accountID",
    "account_id",
    "userId",
    "userID",
  ]
  for (const key of keyCandidates) {
    if (key in source && source[key] != null) {
      const derived = normaliseOperatorId(source[key])
      if (derived) {
        return derived
      }
    }
  }
  return null
}

const routedOperatorId = computed(() =>
  normaliseOperatorId(route.query.operatorId ?? route.params?.operatorId ?? null),
)

const resolvedOperatorId = computed(() => {
  const routedId = routedOperatorId.value
  if (routedId) {
    return routedId
  }
  const propId = deriveOperatorIdFromSource(props.operator)
  if (propId) {
    return propId
  }
  return null
})

watch(
  () => props.listings,
  (value) => {
    if (!Array.isArray(value)) return
    if (!arraysShallowEqual(value, listings.value)) {
      listings.value = cloneRecords(value)
    }
  },
  { deep: true, immediate: true },
)

watch(
  () => props.mediaAssets,
  (value) => {
    if (!Array.isArray(value)) return
    if (!arraysShallowEqual(value, mediaLibrary.value)) {
      mediaLibrary.value = cloneRecords(value)
    }
  },
  { deep: true, immediate: true },
)


const operator = computed(() => {
  const incoming = {
    ...defaultOperator,
    ...(props.operator ?? {}),
    ...(remoteOperator.value ?? {}),
  }
  const displayName = incoming.fullName || incoming.username || defaultOperator.fullName
  const initials =
    incoming.avatarInitials ||
    displayName
      .split(/\s+/)
      .map((part) => part[0])
      .join("")
      .slice(0, 2)
      .toUpperCase()
  const { relative: derivedAvatarPath, url: derivedAvatarUrl } = deriveAvatarInfo(incoming)
  const avatarUrl = derivedAvatarUrl || incoming.avatarUrl || ""
  const avatarPath = derivedAvatarPath || incoming.avatarPath || ""
  const derivedId = deriveOperatorIdFromSource(incoming) ?? resolvedOperatorId.value ?? null

  return {
    ...incoming,
    id: derivedId ?? incoming.id ?? null,
    operatorId: incoming.operatorId ?? derivedId ?? incoming.operatorID ?? null,
    operatorID: incoming.operatorID ?? derivedId ?? incoming.operatorId ?? null,
    displayName,
    initials,
    avatarUrl,
    avatarPath,
  }
})

const currentOperatorId = computed(
  () =>
    normaliseOperatorId(
      resolvedOperatorId.value ??
        deriveOperatorIdFromSource(operator.value) ??
        deriveOperatorIdFromSource(remoteOperator.value) ??
        null,
    ),
)

const listingTouchStorageKey = computed(() => {
  const id = normaliseOperatorId(currentOperatorId.value)
  return id ? `operator:${id}:listingTouches` : null
})

watch(
  currentOperatorId,
  (operatorId) => {
    const normalizedId = normaliseOperatorId(operatorId)
    if (!normalizedId) {
      remoteOperator.value = null
      listings.value = []
      mediaLibrary.value = []
      lastLoadedOperatorId.value = null
      return
    }
    if (normalizedId !== lastLoadedOperatorId.value) {
      loadOperatorDashboard(normalizedId)
    }
  },
  { immediate: true },
)

watch(
  currentOperatorId,
  () => {
    removalHistory.value = []
    lastRemovalHistoryOperatorId.value = null
  },
  { immediate: false },
)

watch(
  listingTouchStorageKey,
  (key) => {
    if (!storageAvailable || !key) {
      operatorListingTouches.value = {}
      return
    }
    try {
      const raw = window.localStorage.getItem(key)
      operatorListingTouches.value = raw ? JSON.parse(raw) : {}
    } catch {
      operatorListingTouches.value = {}
    }
  },
  { immediate: true },
)

const operatorNotificationFeed = useNotificationFeed({
  recipientType: computed(() => "Operator"),
  recipientId: currentOperatorId,
  listLimit: 25,
  pollInterval: 60000,
  announce: true,
})
provide(notificationFeedSymbol, operatorNotificationFeed)

const renderIcon = (name) => () => h(NIcon, null, { default: () => h("i", { class: name }) })

const sidebarOptions = computed(() => [
  { key: "overview", label: "Dashboard overview", icon: renderIcon("ri-dashboard-line") },
  { key: "upload-info", label: "Upload business info", icon: renderIcon("ri-file-add-line") },
  { key: "media-manager", label: "Upload photos / media", icon: renderIcon("ri-image-add-line") },
  { key: "manage-listings", label: "Manage listings", icon: renderIcon("ri-list-settings-line") },
  { key: "notifications", label: "Notifications", icon: renderIcon("ri-notification-3-line") },
  { key: "guidelines", label: "Operator guidelines", icon: renderIcon("ri-graduation-cap-line") },
])

const partnerCategories = [
  {
    key: "hotels",
    title: "Hotels integrated via Agoda API",
    description:
      "Hotel partners connect through Agoda. Reservations flow through Agoda, while this dashboard mirrors booking history so operators and travelers can review the same record even as room availability updates in real time via Agoda.",
    highlight:
      "Real-time availability remains governed by Agoda; Malaysia Sustainable Travel stores booking details for history and reporting.",
    icon: "ri-building-4-line",
  },
  {
    key: "local",
    title: "Small local businesses",
    description:
      "Homestays, food stalls, eco-tours, and cultural activity hosts can publish listings without third-party fees. They manage descriptions, menus, and contact info directly so travelers can reach out and book responsibly.",
    highlight:
      "Designed for non-technical owners; simple forms, media uploads, and visibility controls keep content current.",
    icon: "ri-leaf-line",
  },
]

const workflowModules = [
  {
    key: "upload-info",
    title: "Upload Business Info",
    description:
      "Register your business with structured fields for name, category, address, contact details, and a service summary. The system validates required details and stores the profile for administrator review.",
    icon: "ri-file-add-line",
  },
  {
    key: "media-manager",
    title: "Upload Photos / Media",
    description:
      "Add accommodation photos, tour highlights, brochures, or PDF menus to strengthen listing appeal. Media assets can be marked as primary or updated whenever offerings change.",
    icon: "ri-image-add-line",
  },
  {
    key: "manage-listings",
    title: "Manage Listings",
    description:
      "Use the dashboard to edit published information, toggle visibility, or remove retired listings. Each save shows a confirmation message to prevent accidental data loss.",
    icon: "ri-settings-4-line",
  },
]

const sidebarCollapsed = ref(false)
const expandedSidebarStyle = computed(() => ({
  padding: "18px 16px",
  alignItems: "flex-start",
  gap: "10px",
}))
const collapsedSidebarStyle = computed(() => ({
  padding: "12px 0",
  alignItems: "center",
  justifyContent: "center",
  gap: "14px",
}))
const expandedMenuContainerStyle = computed(() => ({
  padding: "0 8px 16px",
}))
const collapsedMenuContainerStyle = computed(() => ({
  padding: "0 6px 16px",
  display: "flex",
  flexDirection: "column",
  alignItems: "center",
  gap: "12px",
}))

const hashToSection = {
  overview: "overview",
  "upload-info": "upload-info",
  "media-manager": "media-manager",
  "listings-panel": "manage-listings",
  "manage-listings": "manage-listings",
  notifications: "notifications",
  guidelines: "guidelines",
}

const sectionMeta = {
  overview: {
    title: "Tourism Operator Control Center",
    subtitle: "Track listing health, synchronise Agoda bookings, and uplift local partners.",
  },
  "upload-info": {
    title: "Business Registration Module",
    subtitle: "Capture business profiles for hotels and community-based operators.",
  },
  "media-manager": {
    title: "Media Manager",
    subtitle: "Upload photos, menus, and brochures to strengthen listing appeal.",
  },
  "manage-listings": {
    title: "Listing Management",
    subtitle: "Edit details, toggle visibility, and maintain up-to-date information.",
  },
  notifications: {
    title: "Notifications",
    subtitle: "Track admin decisions and account updates shared with your team.",
  },
  guidelines: {
    title: "Operator Guidelines",
    subtitle: "Follow the documented workflows to keep submissions accurate and review-ready.",
  },
}

const activeSection = ref("overview")

const availableSectionKeys = computed(() => sidebarOptions.value.map((option) => option.key))

const normaliseSectionKey = (value) => {
  if (Array.isArray(value)) {
    return typeof value[0] === "string" ? value[0] : ""
  }
  return typeof value === "string" ? value : ""
}

const activeSectionMeta = computed(() => sectionMeta[activeSection.value] ?? sectionMeta.overview)

const listingMetrics = computed(() => {
  const metrics = {
    total: listings.value.length,
    active: 0,
    pending: 0,
    hidden: 0,
  }

  const pendingStatuses = ["pending review", "pending", "under review"]
  const hiddenStatuses = ["hidden", "inactive", "suspended", "draft"]
  const activeStatuses = ["active", "approved", "published"]

  listings.value.forEach((listing) => {
    const status = typeof listing.status === "string" ? listing.status.toLowerCase() : ""
    const visibility = typeof listing.visibility === "string" ? listing.visibility.toLowerCase() : ""

    if (pendingStatuses.includes(status)) {
      metrics.pending += 1
    }

    if (visibility === "hidden" || hiddenStatuses.includes(status)) {
      metrics.hidden += 1
      return
    }

    if (visibility === "visible" || activeStatuses.includes(status)) {
      metrics.active += 1
    }
  })

  return metrics
})

const summaryCards = computed(() => [
  {
    key: "total",
    label: "Total listings",
    value: listingMetrics.value.total,
    type: "number",
    accent: "linear-gradient(135deg, #70c3ff, #6c63ff)",
  },
  {
    key: "pending",
    label: "Pending review",
    value: listingMetrics.value.pending,
    type: "number",
    accent: "linear-gradient(135deg, #f6d365, #fda085)",
  },
  {
    key: "active",
    label: "Active listings",
    value: listingMetrics.value.active,
    type: "number",
    accent: "linear-gradient(135deg, #42b883, #0b3b26)",
  },
  {
    key: "hidden",
    label: "Hidden",
    value: listingMetrics.value.hidden,
    type: "number",
    accent: "linear-gradient(135deg, #cfd9df, #e2ebf0)",
  },
])

const recentActivityEntries = computed(() => {
  const normalizeTimestamp = (value, fallbackIndex) => {
    if (!value) {
      return { raw: null, order: -fallbackIndex }
    }
    const ms = Date.parse(value)
    if (Number.isNaN(ms)) {
      return { raw: value, order: -fallbackIndex }
    }
    return { raw: value, order: ms }
  }

  const activeEntries = listings.value.map((listing, index) => {
    const listingId = extractListingId(listing) ?? listing.id ?? `listing-${index}`
    const overrideTimestamp = operatorListingTouches.value[listingId] ?? null
    const baseTimestamp =
      listing.lastUpdated ??
      listing.lastUpdatedDisplay ??
      listing.submittedDate ??
      listing.createdAt ??
      null
    const timestamp = resolveEffectiveTimestamp(listingId, baseTimestamp, overrideTimestamp)
    const normalizedTimestamp = normalizeTimestamp(timestamp, index)
    return {
      id: listingId,
      listingId,
      name: listing.name ?? listing.businessName ?? "Untitled listing",
      category: listing.category ?? listing.type ?? "Uncategorised",
      address: listing.address ?? listing.location ?? "",
      status: listing.status ?? "Pending Review",
      statusType: resolveStatusTagType(listing.status),
      timestamp: normalizedTimestamp.raw,
      timestampOrder: normalizedTimestamp.order,
      timestampLabel: overrideTimestamp || listing.lastUpdated ? "Updated" : "Submitted",
      reason: null,
      isRemoved: false,
    }
  })

  const historyEntries = removalHistory.value.map((entry, index) => {
    const normalizedTimestamp = normalizeTimestamp(entry.removedAt, index)
    return {
      id: entry.id ?? entry.listingId ?? `removed-${index}`,
      listingId: entry.listingId ?? entry.id ?? `removed-${index}`,
      name: entry.businessName ?? "Archived listing",
      category: entry.category ?? "Uncategorised",
      address: entry.location ?? entry.details?.address ?? "",
      status: "Removed",
      statusType: "error",
      timestamp: normalizedTimestamp.raw,
      timestampOrder: normalizedTimestamp.order,
      timestampLabel: "Removed",
      reason: entry.removalReason ?? "",
      isRemoved: true,
    }
  })

  return [...activeEntries, ...historyEntries].sort((a, b) => b.timestampOrder - a.timestampOrder)
})

const recentListings = computed(() => {
  const start = (recentActivityPage.value - 1) * RECENT_ACTIVITY_PAGE_SIZE
  return recentActivityEntries.value.slice(start, start + RECENT_ACTIVITY_PAGE_SIZE)
})

const recentActivityItemCount = computed(() => recentActivityEntries.value.length)

watch(
  () => recentActivityEntries.value.length,
  (length) => {
    if (length === 0) {
      recentActivityPage.value = 1
      return
    }
    const maxPage = Math.max(1, Math.ceil(length / RECENT_ACTIVITY_PAGE_SIZE))
    if (recentActivityPage.value > maxPage) {
      recentActivityPage.value = maxPage
    }
  },
)

const sampleEntryModalVisible = ref(false)
const sampleMediaModalVisible = ref(false)
const sampleListingModalVisible = ref(false)

const quickActions = computed(() => {
  if (activeSection.value === "upload-info") {
    return [
      {
        key: "view-sample-entry",
        label: "View sample entry",
        description:
          "Use the Rainforest Homestay example from your FYP documentation to demonstrate how a completed submission should look.",
        buttonLabel: "Open sample",
        action: () => {
          sampleEntryModalVisible.value = true
        },
      },
    ]
  }

  if (activeSection.value === "media-manager") {
    return [
      {
        key: "view-sample-media",
        label: "Media upload guidelines",
        description: "Best practices for photo dimensions, captioning, and file sizes.",
        buttonLabel: "View sample asset",
        action: () => {
          sampleMediaModalVisible.value = true
        },
      },
    ]
  }

  if (activeSection.value === "manage-listings") {
    return [
      {
        key: "view-sample-listing",
        label: "Manage listing reference",
        description: "Preview how a pending listing appears before approval and which quick actions are available.",
        buttonLabel: "Preview sample listing",
        action: () => {
          sampleListingModalVisible.value = true
        },
      },
    ]
  }

  return []
})

function persistListingTouches() {
  const key = listingTouchStorageKey.value
  if (!storageAvailable || !key) {
    return
  }
  try {
    window.localStorage.setItem(key, JSON.stringify(operatorListingTouches.value))
  } catch {
    /* ignore storage failures */
  }
}

function recordListingTouch(listingId, timestamp = new Date().toISOString()) {
  if (!listingId) return
  operatorListingTouches.value = {
    ...(operatorListingTouches.value ?? {}),
    [listingId]: timestamp,
  }
  persistListingTouches()
}

function removeListingTouch(listingId) {
  if (!listingId || !operatorListingTouches.value?.[listingId]) {
    return
  }
  const next = { ...(operatorListingTouches.value ?? {}) }
  delete next[listingId]
  operatorListingTouches.value = next
  persistListingTouches()
}

function resolveEffectiveTimestamp(listingId, baseTimestamp, overrideTimestamp) {
  if (!overrideTimestamp) {
    return baseTimestamp
  }

  const overrideMs = Date.parse(overrideTimestamp)
  if (Number.isNaN(overrideMs)) {
    removeListingTouch(listingId)
    return baseTimestamp
  }

  const baseMs = baseTimestamp ? Date.parse(baseTimestamp) : Number.NaN
  if (Number.isNaN(baseMs) || overrideMs > baseMs) {
    return overrideTimestamp
  }

  // backend timestamp is newer or equal, discard stale override
  removeListingTouch(listingId)
  return baseTimestamp
}

function handleListingTouched(payload) {
  if (!payload) return
  const listingId = payload.listingId ?? extractListingId(payload.listing ?? null)
  const timestamp = payload.timestamp ?? new Date().toISOString()
  if (!listingId) return

  if (payload.removed) {
    removeListingTouch(listingId)
    return
  }

  recordListingTouch(listingId, timestamp)
}

function handleMenuSelect(section) {
  goToSection(section)
}

function goToSection(section) {
  if (!section) return
  activeSection.value = section
  if (typeof window !== "undefined") {
    clearOperatorLocationHash()
  }
  scrollContentToTop()
}

function scrollContentToTop() {
  nextTick(() => {
    const container = document.getElementById("operator-scroll-root")
    if (container) {
      container.scrollTo({ top: 0, behavior: "smooth" })
    }
  })
}

function handleListingCreated(listing) {
  if (!listing) return
  const normalized = normalizeListingRecord(listing)
  listings.value = [
    normalized,
    ...listings.value.filter((item) => extractListingId(item) !== extractListingId(normalized)),
  ]
  const listingId = extractListingId(normalized) ?? normalized.id ?? null
  if (listingId) {
    recordListingTouch(listingId, normalized.lastUpdated ?? new Date().toISOString())
  }
}

function handleOperatorUpdated(patch) {
  if (!patch || typeof patch !== "object") return
  remoteOperator.value = { ...(remoteOperator.value ?? {}), ...patch }
}

function handleScrollTopRequest() {
  scrollContentToTop()
}

function resolveSectionFromHash(hash) {
  const value = (hash || "").replace(/^#/, "")
  return hashToSection[value] ?? null
}

function clearOperatorLocationHash() {
  if (typeof window === "undefined") return
  if (!window.location.hash) return
  const { pathname, search } = window.location
  window.history.replaceState(null, "", `${pathname}${search}`)
}

function handleOperatorNavigate(event) {
  const detail = typeof event.detail === "string" ? event.detail : ""
  const section = resolveSectionFromHash(detail)
  if (section) {
    goToSection(section)
  }
}

function formatReadableDate(value) {
  if (!value) return "-"
  try {
    return new Intl.DateTimeFormat(undefined, {
      dateStyle: "medium",
      timeStyle: "short",
    }).format(new Date(value))
  } catch {
    return value
  }
}

function resolveStatusTagType(status) {
  if (!status || typeof status !== "string") {
    return "default"
  }
  const normalized = status.toLowerCase()
  if (["approved", "active", "visible", "published"].includes(normalized)) {
    return "success"
  }
  if (["pending", "pending review", "submitted", "under review"].includes(normalized)) {
    return "warning"
  }
  if (["rejected", "removed", "hidden"].includes(normalized)) {
    return "error"
  }
  return "info"
}

async function handleRemovalHistoryRequest() {
  const targetId = normaliseOperatorId(currentOperatorId.value)
  if (!targetId) {
    removalHistoryError.value = "Unable to determine the active operator account."
    return
  }
  await loadRemovalHistory(targetId)
}

function clearRemovalHistoryError() {
  removalHistoryError.value = null
}

async function loadRemovalHistory(operatorId, options = {}) {
  const { silent = false } = options
  const targetId = normaliseOperatorId(operatorId)
  if (!targetId) {
    if (!silent) {
      removalHistoryError.value = "Unable to determine the active operator account."
    }
    return
  }
  if (
    isRemovalHistoryLoading.value ||
    (silent && lastRemovalHistoryOperatorId.value === targetId && removalHistory.value.length)
  ) {
    return
  }

  isRemovalHistoryLoading.value = true
  if (!silent) {
    removalHistoryError.value = null
  }

  try {
    const response = await fetch(
      `${API_BASE}/operator/listing_history.php?operatorId=${encodeURIComponent(targetId)}`,
    )
    const payload = await response
      .json()
      .catch(() => null)
    if (!response.ok || !payload?.history || !Array.isArray(payload.history)) {
      throw new Error(payload?.error || `Failed to load removal history (HTTP ${response.status})`)
    }
    removalHistory.value = payload.history
    lastRemovalHistoryOperatorId.value = targetId
    recentActivityPage.value = 1
  } catch (error) {
    if (!silent) {
      removalHistoryError.value =
        error instanceof Error ? error.message : "Unable to load removal history."
    }
  } finally {
    isRemovalHistoryLoading.value = false
  }
}

watch(
  () => normaliseSectionKey(route.query.module),
  (moduleKey) => {
    if (moduleKey && availableSectionKeys.value.includes(moduleKey)) {
      activeSection.value = moduleKey
    } else if (!moduleKey && route.name === "operator") {
      activeSection.value = "overview"
    }
  },
  { immediate: true },
)

watch(
  activeSection,
  (next) => {
    const normalized = next && next !== "overview" ? next : null
    const current = normaliseSectionKey(route.query.module)
    if (normalized === current) {
      return
    }
    const nextQuery = { ...route.query }
    if (normalized) {
      nextQuery.module = normalized
    } else {
      delete nextQuery.module
    }
    router.replace({ query: nextQuery }).catch(() => {})
  },
  { immediate: false },
)

onMounted(() => {
  if (typeof window === "undefined") return
  window.addEventListener("operator:navigate", handleOperatorNavigate)
  const rawHash = window.location.hash
  const initial = resolveSectionFromHash(rawHash)
  if (initial && initial !== activeSection.value) {
    activeSection.value = initial
  }
  if (rawHash) {
    clearOperatorLocationHash()
  }
})

onBeforeUnmount(() => {
  if (typeof window === "undefined") return
  window.removeEventListener("operator:navigate", handleOperatorNavigate)
})

async function loadOperatorDashboard(operatorId) {
  const targetId = normaliseOperatorId(operatorId)
  if (!targetId) {
    remoteOperator.value = null
    listings.value = []
    mediaLibrary.value = []
    lastLoadedOperatorId.value = null
    return
  }
  if (isDashboardLoading.value && targetId === lastLoadedOperatorId.value) {
    return
  }

  isDashboardLoading.value = true
  dashboardError.value = null

  try {
    const response = await fetch(
      `${API_BASE}/operator/dashboard.php?operatorId=${encodeURIComponent(targetId)}`,
    )

    const payload = await response.json().catch(() => null)

    if (!response.ok || !payload) {
      throw new Error(payload?.error || `Failed to load dashboard (HTTP ${response.status})`)
    }

    if (Array.isArray(payload.listings)) {
      listings.value = cloneRecords(payload.listings.map(normalizeListingRecord))
    }

    if (Array.isArray(payload.mediaAssets)) {
      mediaLibrary.value = cloneRecords(payload.mediaAssets.map(normalizeMediaAsset))
    }

    if (payload.operator) {
      remoteOperator.value = { ...(remoteOperator.value ?? {}), ...payload.operator }
    }

    lastLoadedOperatorId.value = targetId
    loadRemovalHistory(targetId, { silent: true }).catch(() => {})
  } catch (error) {
    dashboardError.value =
      error instanceof Error ? error.message : "Unable to load operator dashboard."
  } finally {
    isDashboardLoading.value = false
  }
}

function arraysShallowEqual(next, current) {
  if (!Array.isArray(next) || !Array.isArray(current)) return false
  if (next.length !== current.length) return false
  for (let index = 0; index < next.length; index += 1) {
    const a = next[index]
    const b = current[index]
    if (JSON.stringify(a) !== JSON.stringify(b)) {
      return false
    }
  }
  return true
}

function cloneRecords(items) {
  return Array.isArray(items)
    ? items.map((item) => ({ ...item, contact: item.contact ? { ...item.contact } : undefined }))
    : []
}

function extractListingId(listing) {
  if (!listing || typeof listing !== "object") return null
  if (listing.listingId != null) {
    const numeric = Number(listing.listingId)
    return Number.isNaN(numeric) ? null : numeric
  }
  if (typeof listing.id === "string" || typeof listing.id === "number") {
    const numeric = Number(listing.id)
    if (!Number.isNaN(numeric) && numeric > 0) {
      return numeric
    }
    const match = String(listing.id).match(/(\d+)/)
    if (match && match[1]) {
      const parsed = Number(match[1])
      return Number.isNaN(parsed) ? null : parsed
    }
  }
  return null
}

function normalizeListingRecord(record) {
  const source = record && typeof record === "object" ? record : {}
  const listingId = source.listingId ?? null
  const contactSource =
    source.contact && typeof source.contact === "object" ? source.contact : {}

  return {
    ...source,
    id:
      source.id ??
      (listingId != null ? `LST-${String(listingId).padStart(4, "0")}` : `LST-${cryptoRandomId()}`),
    listingId,
    name: source.name ?? "Untitled listing",
    category: source.category ?? "Uncategorised",
    type: source.type ?? "Business",
    status: source.status ?? "Pending Review",
    visibility: source.visibility ?? "Hidden",
    lastUpdated: source.lastUpdated ?? new Date().toISOString(),
    address: source.address ?? "",
    highlight: source.highlight ?? "",
    reviewNotes: source.reviewNotes ?? "Awaiting administrator review.",
    contact: {
      phone: contactSource.phone ?? "",
      email: contactSource.email ?? "",
    },
  }
}

function normalizeMediaAsset(record) {
  const source = record && typeof record === "object" ? record : {}
  const mediaId = source.mediaId ?? source.id ?? cryptoRandomId()
  const listingIdRaw = source.listingId ?? source.listingID ?? null
  const listingId =
    typeof listingIdRaw === "number"
      ? listingIdRaw
      : typeof listingIdRaw === "string" && listingIdRaw !== ""
        ? Number.parseInt(listingIdRaw, 10)
        : null

  return {
    ...source,
    id: mediaId,
    mediaId,
    listingId,
    listingName: source.listingName ?? "",
    label: source.label ?? "Untitled asset",
    type: source.type ?? "Accommodation photo",
    status: source.status ?? "Published",
    isPrimary: Boolean(source.isPrimary),
    lastUpdated: source.lastUpdated ?? new Date().toISOString(),
    fileName: source.fileName ?? "",
    mimeType: source.mimeType ?? inferMimeType(source.fileName ?? ""),
    fileSize: source.fileSize ?? null,
    url: source.url ?? source.imageURL ?? "",
  }
}

function inferMimeType(fileName) {
  const extension = (fileName ?? "").toLowerCase().split(".").pop()
  switch (extension) {
    case "jpg":
    case "jpeg":
      return "image/jpeg"
    case "png":
      return "image/png"
    case "gif":
      return "image/gif"
    case "webp":
      return "image/webp"
    case "pdf":
      return "application/pdf"
    default:
      return null
  }
}

function cryptoRandomId() {
  if (typeof window !== "undefined" && window.crypto?.getRandomValues) {
    const array = new Uint32Array(1)
    window.crypto.getRandomValues(array)
    return array[0].toString(36).slice(0, 6)
  }
  return Math.random().toString(36).slice(2, 8)
}
</script>

<template>
  <n-layout
    class="operator-shell"
    has-sider
    style="min-height: 100vh; background: var(--body-color);"
  >
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
        :style="sidebarCollapsed ? collapsedSidebarStyle : expandedSidebarStyle"
        class="sidebar-brand"
      >
        <div class="sidebar-brand__logo">
          <img
            v-if="sidebarCollapsed"
            :src="COLLAPSED_LOGO_SRC"
            alt="Tourism Operator Hub logo"
            class="sidebar-brand__image"
          />
          <n-gradient-text v-else type="info" style="font-size: 1.1rem; font-weight: 600;">
            Tourism Operator Hub
          </n-gradient-text>
        </div>
        <n-text v-if="!sidebarCollapsed" depth="3">Manage Malaysia Sustainable listings</n-text>
        <n-switch v-model:value="sidebarCollapsed" size="small" round />
      </n-space>
      <div :style="sidebarCollapsed ? collapsedMenuContainerStyle : expandedMenuContainerStyle">
        <n-menu
          :options="sidebarOptions"
          :value="activeSection"
          :indent="16"
          :collapsed="sidebarCollapsed"
          :collapsed-icon-size="20"
          @update:value="handleMenuSelect"
        />
      </div>
    </n-layout-sider>

    <n-layout>
      <n-layout-header id="operator-top" bordered style="padding: 24px 32px; background: transparent;">
        <div class="operator-header">
          <div class="operator-header__card">
            <div class="operator-header__identity-block">
              <div class="operator-header__identity">
                <n-avatar
                  round
                  size="large"
                  :src="operator.avatarUrl || undefined"
                  :style="operator.avatarUrl ? undefined : avatarFallbackStyle"
                >
                  <template v-if="!operator.avatarUrl">{{ operator.initials }}</template>
                </n-avatar>
                <div class="operator-header__details">
                  <n-text depth="3">Hello, tourism operator</n-text>
                  <div class="operator-name">{{ operator.displayName }}</div>
                  <div class="operator-header__meta">
                    <div class="section-title">{{ activeSectionMeta.title }}</div>
                    <n-text depth="3">{{ activeSectionMeta.subtitle }}</n-text>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </n-layout-header>

      <n-layout-content id="operator-scroll-root" embedded style="padding: 24px 32px;">
        <n-alert
          v-if="dashboardError"
          type="error"
          closable
          style="margin-bottom: 16px;"
          @close="dashboardError = null"
        >
          {{ dashboardError }}
        </n-alert>
        <n-alert
          v-else-if="isDashboardLoading"
          type="info"
          style="margin-bottom: 16px;"
        >
          Syncing the latest operator data...
        </n-alert>

        <template v-if="activeSection === 'overview'">
          <n-space vertical size="large">
            <n-card
              :segmented="{ content: true }"
              :style="{
                background: 'linear-gradient(135deg, rgba(66, 184, 131, 0.12), rgba(108, 99, 255, 0.12))',
                border: '1px solid rgba(66, 184, 131, 0.24)',
              }"
            >
              <n-grid cols="1 m:2" :x-gap="18" :y-gap="18" align="center">
                <n-grid-item>
                  <n-space vertical size="small">
                    <n-tag type="success" size="small" bordered>Tourism Operator Subsystem</n-tag>
                    <div style="font-size: 1.8rem; font-weight: 700;">
                      Empower Malaysia's Eco-Tourism Partners
                    </div>
                    <n-text depth="3">
                      Publish authentic experiences, connect Agoda hotel data, and keep community-led offerings updated without technical hurdles.
                    </n-text>
                    <n-space justify="center">
                      <n-button type="primary" round @click="goToSection('upload-info')">
                        Start registration flow
                      </n-button>
                      <n-button tertiary type="primary" round @click="goToSection('guidelines')">
                        Review listing guidelines
                      </n-button>
                    </n-space>
                  </n-space>
                </n-grid-item>
                <n-grid-item>
                  <n-grid cols="1 m:2" :x-gap="16" :y-gap="16">
                    <n-grid-item v-for="card in summaryCards" :key="card.key">
                      <n-card
                        size="medium"
                        :segmented="{ content: true, footer: false }"
                        :style="{ background: card.accent, color: '#fff' }"
                      >
                        <n-space vertical size="small" align="center" style="text-align: center; width: 100%;">
                          <n-text depth="3" style="color: rgba(255, 255, 255, 0.85);">
                            {{ card.label }}
                          </n-text>
                          <div class="summary-card__value">{{ card.value }}</div>
                        </n-space>
                      </n-card>
                    </n-grid-item>
                  </n-grid>
                </n-grid-item>
              </n-grid>
            </n-card>

            <n-grid cols="1 m:2" :x-gap="16" :y-gap="16">
              <n-grid-item>
                <n-card title="Partner categories" :segmented="{ content: true }">
                  <n-space vertical size="medium">
                    <n-space
                      v-for="category in partnerCategories"
                      :key="category.key"
                      align="start"
                      style="gap: 12px;"
                    >
                      <n-icon size="22" style="color: var(--primary-color);">
                        <i :class="category.icon" />
                      </n-icon>
                      <div>
                        <div style="font-weight: 600;">{{ category.title }}</div>
                        <n-text depth="3">{{ category.description }}</n-text>
                        <n-text depth="3" style="font-style: italic; display: block; margin-top: 4px;">
                          {{ category.highlight }}
                        </n-text>
                      </div>
                    </n-space>
                  </n-space>
                </n-card>
              </n-grid-item>
              <n-grid-item>
                <n-card title="Recent submissions" :segmented="{ content: true }">
                  <template v-if="recentListings.length">
                    <n-list bordered :show-divider="false">
                      <n-list-item v-for="item in recentListings" :key="item.id ?? item.listingId">
                        <div class="recent-entry">
                          <div class="recent-entry__header">
                            <div>
                              <div class="recent-entry__title">{{ item.name }}</div>
                              <n-text depth="3">{{ item.category }} · {{ item.address }}</n-text>
                            </div>
                            <n-tag size="small" :type="item.statusType" bordered>{{ item.status }}</n-tag>
                          </div>
                          <n-text depth="3">
                            {{ item.timestampLabel }} {{ formatReadableDate(item.timestamp) }}
                          </n-text>
                          <n-alert
                            v-if="item.reason"
                            type="warning"
                            size="small"
                            class="recent-entry__reason"
                          >
                            <strong>Reason:</strong> {{ item.reason }}
                          </n-alert>
                        </div>
                      </n-list-item>
                    </n-list>
                    <n-space justify="end" style="margin-top: 12px;">
                      <SimplePagination
                        v-model:page="recentActivityPage"
                        :page-size="RECENT_ACTIVITY_PAGE_SIZE"
                        :item-count="recentActivityItemCount"
                      />
                    </n-space>
                  </template>
                  <template v-else>
                    <n-empty description="No listings submitted yet." />
                  </template>
                </n-card>
              </n-grid-item>
            </n-grid>

            <n-card title="Workflow modules" :segmented="{ content: true }">
              <n-grid cols="1 m:3" :x-gap="18" :y-gap="18">
                <n-grid-item v-for="module in workflowModules" :key="module.key">
                  <n-card size="small" :segmented="{ content: true }" style="height: 100%;">
                    <n-space vertical size="small">
                      <n-space align="center" size="small">
                        <n-icon size="18" style="color: var(--primary-color);">
                          <i :class="module.icon" />
                        </n-icon>
                        <div style="font-weight: 600;">{{ module.title }}</div>
                      </n-space>
                      <n-text depth="3">{{ module.description }}</n-text>
                      <n-button text type="primary" size="small" @click="goToSection(module.key)">
                        Open {{ module.title.toLowerCase() }}
                      </n-button>
                    </n-space>
                  </n-card>
                </n-grid-item>
              </n-grid>
            </n-card>
          </n-space>
        </template>

        <n-card
          v-if="quickActions.length"
          title="Quick actions"
          size="small"
          :segmented="{ content: true }"
          style="margin-bottom: 16px;"
        >
          <n-space vertical size="medium">
            <n-space
              v-for="item in quickActions"
              :key="item.key"
              class="quick-action-item"
              vertical
              size="small"
            >
              <div style="font-weight: 600;">{{ item.label }}</div>
              <n-text depth="3">{{ item.description }}</n-text>
              <n-button
                v-if="item.action"
                size="small"
                type="primary"
                @click="item.action()"
              >
                {{ item.buttonLabel ?? 'Open' }}
              </n-button>
            </n-space>
          </n-space>
        </n-card>

        <BusinessOperatorUploadInfo
          v-if="activeSection === 'upload-info'"
          :api-base="API_BASE"
          :operator-id="currentOperatorId"
          :operator-profile="operator"
          @listing-created="handleListingCreated"
          @operator-updated="handleOperatorUpdated"
          @request-scroll-top="handleScrollTopRequest"
        />

        <BusinessOperatorMediaManager
          v-else-if="activeSection === 'media-manager'"
          :api-base="API_BASE"
          :operator-id="currentOperatorId"
          :listings="listings"
          v-model:media-assets="mediaLibrary"
        />

        <BusinessOperatorManageListings
          v-else-if="activeSection === 'manage-listings'"
          :api-base="API_BASE"
          :operator-id="currentOperatorId"
          v-model:listings="listings"
          :removal-history="removalHistory"
          :removal-history-loading="isRemovalHistoryLoading"
          :removal-history-error="removalHistoryError"
          @operator-updated="handleOperatorUpdated"
          @request-removal-history="handleRemovalHistoryRequest"
          @clear-removal-history-error="clearRemovalHistoryError"
          @listing-touched="handleListingTouched"
        />

        <NotificationCenter
          v-else-if="activeSection === 'notifications'"
          recipient-type="Operator"
          :recipient-id="currentOperatorId"
          title="Operator notifications"
          description="Admins share verification feedback and listing decisions here."
        />

        <BusinessOperatorGuidelines v-else />
      </n-layout-content>
    </n-layout>
  </n-layout>

  <n-modal
    v-model:show="sampleEntryModalVisible"
    preset="card"
    title="Sample business entry"
    style="max-width: 520px;"
    :header-style="{ borderBottom: 'none' }"
    :content-style="{ paddingTop: '0' }"
  >
    <n-space vertical size="medium">
      <n-text depth="3">
        Rainforest Homestay - Cameron Highlands
      </n-text>
      <n-space vertical size="small">
        <n-text depth="3"><strong>Business name:</strong> Rainforest Homestay</n-text>
        <n-text depth="3"><strong>Category:</strong> Homestay</n-text>
        <n-text depth="3"><strong>Address:</strong> Jalan Batu 43, Brinchang, Cameron Highlands</n-text>
        <n-text depth="3"><strong>Phone:</strong> +60 12-345 6789</n-text>
        <n-text depth="3"><strong>Email:</strong> rainforest@homestay.my</n-text>
        <n-text depth="3">
          <strong>Description:</strong> Family-run homestay surrounded by tea terraces. Guests join organic farming activities, jungle treks, and cook traditional dishes with the hosts.
        </n-text>
        <n-text depth="3">
          <strong>Highlights:</strong> Sustainable farming workshops - Guided sunrise trek - Community hosted dinners.
        </n-text>
      </n-space>
    </n-space>
    <template #footer>
      <n-space justify="end">
        <n-button type="primary" @click="sampleEntryModalVisible = false">Close</n-button>
      </n-space>
    </template>
  </n-modal>

  <n-modal
    v-model:show="sampleMediaModalVisible"
    preset="card"
    title="Sample media asset"
    style="max-width: 520px;"
    :header-style="{ borderBottom: 'none' }"
    :content-style="{ paddingTop: '0' }"
  >
    <n-space vertical size="medium">
      <n-text depth="3">
        Filename: rainforest-homestay-exterior.jpg
      </n-text>
      <n-space vertical size="small">
        <n-text depth="3"><strong>Type:</strong> Accommodation photo</n-text>
        <n-text depth="3">
          <strong>Description:</strong> Sunset view of the homestay surrounded by tea terraces with guests enjoying the patio.
        </n-text>
        <n-text depth="3"><strong>Recommended specs:</strong> 1600x1067px - JPG - <= 2MB</n-text>
        <n-text depth="3">
          <strong>Caption guidance:</strong> Highlight ambience, sustainability touchpoints, or guest activities in one sentence.
        </n-text>
        <n-text depth="3"><strong>Primary asset:</strong> Yes - surfaces on traveler listing cards.</n-text>
      </n-space>
    </n-space>
    <template #footer>
      <n-space justify="end">
        <n-button type="primary" @click="sampleMediaModalVisible = false">Close</n-button>
      </n-space>
    </template>
  </n-modal>

  <n-modal
    v-model:show="sampleListingModalVisible"
    preset="card"
    title="Sample manage listing view"
    style="max-width: 520px;"
    :header-style="{ borderBottom: 'none' }"
    :content-style="{ paddingTop: '0' }"
  >
    <n-space vertical size="medium">
      <n-space vertical size="small">
        <n-text depth="3"><strong>Listing:</strong> Rainforest Homestay</n-text>
        <n-text depth="3"><strong>Status:</strong> Pending Review</n-text>
        <n-text depth="3"><strong>Visibility:</strong> Hidden (shows once approved)</n-text>
        <n-text depth="3"><strong>Last updated:</strong> 12 Mar 2025, 09:32 AM</n-text>
        <n-text depth="3">
          <strong>Quick actions:</strong> Edit contact details - Toggle visibility - Preview traveler view - Delete listing
        </n-text>
      </n-space>
      <n-text depth="3">
        Use this layout as a reference when confirming the Manage Listings table renders correctly after pulling data from the API.
      </n-text>
    </n-space>
    <template #footer>
      <n-space justify="end">
        <n-button type="primary" @click="sampleListingModalVisible = false">Close</n-button>
      </n-space>
    </template>
  </n-modal>
</template>

<style scoped>
:global(body) {
  background: var(--body-color);
}

.operator-header {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.operator-header__card {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 24px;
  border-radius: 18px;
  background: linear-gradient(135deg, rgba(66, 184, 131, 0.08), rgba(108, 99, 255, 0.08));
  border: 1px solid rgba(66, 184, 131, 0.16);
}

.operator-header__identity-block {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  justify-content: space-between;
  align-items: center;
}

.operator-header__identity {
  display: flex;
  gap: 16px;
  align-items: center;
}

.operator-header__details {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.operator-name {
  font-size: 1.35rem;
  font-weight: 700;
}

.section-title {
  font-size: 1.05rem;
  font-weight: 600;
  margin-top: 4px;
}

.summary-card__value {
  font-size: 1.9rem;
  font-weight: 700;
}

.quick-action-item :deep(.n-button) {
  align-self: flex-start;
}

.recent-entry {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.recent-entry__header {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  align-items: flex-start;
}

.recent-entry__title {
  font-weight: 600;
}

.recent-entry__reason :deep(.n-alert__body) {
  padding: 4px 0;
}

.dashboard-main {
  display: block;
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

.operator-shell :deep(:not(input):not(textarea)) {
  caret-color: transparent;
}

.fade-in-scale-enter-active,
.fade-in-scale-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.fade-in-scale-enter-from,
.fade-in-scale-leave-to {
  opacity: 0;
  transform: translateY(4px) scale(0.98);
}
</style>
