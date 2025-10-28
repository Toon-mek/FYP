<script setup>
import { computed, h, reactive, ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import {
  NAlert,
  NAvatar,
  NButton,
  NCard,
  NDataTable,
  NEmpty,
  NForm,
  NFormItem,
  NGradientText,
  NGrid,
  NGridItem,
  NIcon,
  NInput,
  NLayout,
  NLayoutContent,
  NLayoutHeader,
  NLayoutSider,
  NList,
  NListItem,
  NMenu,
  NModal,
  NNumberAnimation,
  NSelect,
  NSpace,
  NTabPane,
  NTabs,
  NTag,
  NText,
  NTimeline,
  NTimelineItem,
  NUpload,
} from 'naive-ui'
import { usePrimaryHeaderAction } from '../composables/usePrimaryHeaderAction'

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

const API_BASE = import.meta.env.VITE_API_BASE || '/api'

const defaultOperator = {
  fullName: 'Operator',
  username: 'operator01',
}

const MAX_MEDIA_SIZE_BYTES = 5 * 1024 * 1024
const SUPPORTED_MEDIA_TYPES = [
  'image/jpeg',
  'image/png',
  'image/gif',
  'image/webp',
  'application/pdf',
  'video/mp4',
  'video/quicktime',
]
const MEDIA_VALIDATION_MESSAGE =
  'Files must be JPG, PNG, GIF, WEBP, PDF, MP4, or MOV and smaller than 5MB.'
const URL_PATTERN = /^(https?:\/\/)([\w.-]+)(:\d+)?(\/.*)?$/i

const remoteOperator = ref(null)
const isDashboardLoading = ref(false)
const dashboardError = ref(null)
const lastLoadedOperatorId = ref(null)

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
      .join('')
      .slice(0, 2)
      .toUpperCase()

  return {
    ...incoming,
    displayName,
    initials,
  }
})

const partnerCategories = [
  {
    key: 'hotels',
    title: 'Hotels integrated via Agoda API',
    description:
      'Hotel partners connect through Agoda. Reservations flow through Agoda, while this dashboard mirrors booking history so operators and travelers can review the same record even as room availability updates in real time via Agoda.',
    highlight:
      'Real-time availability remains governed by Agoda; Malaysia Sustainable Travel stores booking details for history and reporting.',
    icon: 'ri-building-4-line',
  },
  {
    key: 'local',
    title: 'Small local businesses',
    description:
      'Homestays, food stalls, eco-tours, and cultural activity hosts can publish listings without third-party fees. They manage descriptions, menus, and contact info directly so travelers can reach out and book responsibly.',
    highlight:
      'Designed for non-technical owners; simple forms, media uploads, and visibility controls keep content current.',
    icon: 'ri-leaf-line',
  },
]

const workflowModules = [
  {
    key: 'upload-info',
    title: 'Upload Business Info',
    description:
      'Register your business with structured fields for name, category, address, contact details, and a service summary. The system validates required details and stores the profile for administrator review.',
    icon: 'ri-file-add-line',
  },
  {
    key: 'upload-media',
    title: 'Upload Photos/Menus',
    description:
      'Add accommodation photos, tour highlights, brochures, or PDF menus to strengthen listing appeal. Media assets can be marked as primary or updated whenever offerings change.',
    icon: 'ri-image-add-line',
  },
  {
    key: 'manage-listings',
    title: 'Manage Listings',
    description:
      'Use the dashboard to edit published information, toggle visibility (Active, Hidden, Pending Review), or delete retired listings. Each save shows a confirmation message to prevent accidental data loss.',
    icon: 'ri-settings-4-line',
  },
]

const guidelineTab = ref('upload')

const guidelinesChecklist = [
  'Complete every required field in the business registration form with accurate contact and address details.',
  'Highlight sustainability practices, service specialties, and unique packages so travelers understand your value.',
  'Upload at least one high-quality image or menu before requesting administrator review.',
  'Use Hide listing when pausing services to preserve history instead of deleting the listing entirely.',
]

const uploadFlow = [
  {
    title: 'Navigate to Business Listing',
    description: 'Open the Upload Business Info view from the sidebar.',
  },
  {
    title: 'Enter core details',
    description:
      'Fill in business name, category (hotel, homestay, food, etc.), contact number, email, and address.',
  },
  {
    title: 'Describe services',
    description:
      'Share specialties, sustainability highlights, and packages to differentiate your business.',
  },
  {
    title: 'Submit for review',
    description:
      'System validates the form, stores the profile, and sets status to Pending Review for administrators.',
  },
  {
    title: 'View confirmation',
    description:
      'A success message appears and the listing card shows in the dashboard with Pending Review state.',
  },
]

const manageFlow = [
  {
    title: 'Open operator dashboard',
    description: 'Select Manage Listings to review all submitted businesses.',
  },
  {
    title: 'Load listing details',
    description: 'Click Edit to update descriptions, contacts, or categories with immediate feedback.',
  },
  {
    title: 'Manage media',
    description: 'Upload or replace photos/menus through the media manager to keep visuals current.',
  },
  {
    title: 'Toggle visibility',
    description:
      'Hide or unhide listings to control traveler access without losing historical information.',
  },
  {
    title: 'Confirm updates',
    description:
      'System saves changes, shows confirmation, and refreshes the listing status table.',
  },
]

const guidelineResources = [
  {
    title: 'Pending review policy',
    description:
      'Listings stay hidden until administrators authenticate submissions. Expect email notifications once approved.',
  },
  {
    title: 'Media quality tips',
    description:
      'Use clear landscape images (>=1200px wide) and PDF menus under 5MB to speed up verification.',
  },
  {
    title: 'Communication etiquette',
    description:
      'Respond to traveler enquiries within 24 hours and ensure phone/email details remain updated.',
  },
]

const categoryOptions = [
  { label: 'Hotel (Agoda API)', value: 'Hotel (Agoda API)' },
  { label: 'Homestay', value: 'Homestay' },
  { label: 'Eco-tour', value: 'Eco-tour' },
  { label: 'Food & Beverage', value: 'Food & Beverage' },
  { label: 'Cultural activity', value: 'Cultural activity' },
  { label: 'Wellness', value: 'Wellness' },
  { label: 'Others', value: 'Others' },
]

const formState = reactive({
  name: '',
  category: null,
  type: 'Small Business',
  phone: '',
  email: '',
  address: '',
  website: '',
  description: '',
  highlights: '',
})

const formErrors = reactive({})
const submissionSuccess = ref(null)
const submissionError = ref(null)
const isSubmittingListing = ref(false)

const listings = ref(props.listings.length ? cloneRecords(props.listings) : [])
const mediaLibrary = ref(props.mediaAssets.length ? cloneRecords(props.mediaAssets) : [])

watch(
  () => props.listings,
  (value) => {
    if (value && value.length) {
      listings.value = cloneRecords(value)
    }
  },
  { deep: true },
)

watch(
  () => props.mediaAssets,
  (value) => {
    if (value && value.length) {
      mediaLibrary.value = cloneRecords(value)
    }
  },
  { deep: true },
)

watch(
  () => props.operator?.id,
  (operatorId) => {
    if (!operatorId) {
      remoteOperator.value = null
      lastLoadedOperatorId.value = null
      return
    }
    if (operatorId !== lastLoadedOperatorId.value) {
      loadOperatorDashboard(operatorId)
    }
  },
  { immediate: true },
)

const listingMetrics = computed(() => {
  const metrics = {
    total: listings.value.length,
    active: 0,
    pending: 0,
    hidden: 0,
  }

  listings.value.forEach((listing) => {
    if (listing.status === 'Pending Review') {
      metrics.pending += 1
    } else if (listing.status === 'Hidden') {
      metrics.hidden += 1
    } else {
      metrics.active += 1
    }
  })

  return metrics
})

const summaryCards = computed(() => [
  {
    key: 'total',
    label: 'Total listings',
    value: listingMetrics.value.total,
    type: 'number',
    accent: 'linear-gradient(135deg, #70c3ff, #6c63ff)',
  },
  {
    key: 'pending',
    label: 'Pending review',
    value: listingMetrics.value.pending,
    type: 'number',
    accent: 'linear-gradient(135deg, #f6d365, #fda085)',
  },
  {
    key: 'active',
    label: 'Active listings',
    value: listingMetrics.value.active,
    type: 'number',
    accent: 'linear-gradient(135deg, #42b883, #8fd3f4)',
  },
  {
    key: 'hidden',
    label: 'Hidden listings',
    value: listingMetrics.value.hidden,
    type: 'number',
    accent: 'linear-gradient(135deg, #a18cd1, #fbc2eb)',
  },
  {
    key: 'agoda',
    label: 'Agoda sync status',
    value: 'Bookings mirrored from Agoda history',
    type: 'text',
    accent: 'linear-gradient(135deg, #ff9a9e, #fad0c4)',
  },
])

const recentListings = computed(() => listings.value.slice(0, 3))

const mediaListingOptions = computed(() =>
  listings.value
    .map((listing) => {
      const numericId = extractListingId(listing)
      if (numericId == null) return null
      return { label: listing.name, value: numericId }
    })
    .filter(Boolean),
)

const listingNameById = computed(() => {
  const map = new Map()
  listings.value.forEach((listing) => {
    const numericId = extractListingId(listing)
    if (numericId != null) {
      map.set(numericId, listing.name)
    }
  })
  return map
})

const sidebarOptions = [
  { key: 'overview', label: 'Dashboard overview', icon: renderIcon('ri-dashboard-line') },
  { key: 'upload-info', label: 'Upload business info', icon: renderIcon('ri-file-add-line') },
  { key: 'media-manager', label: 'Upload photos / menus', icon: renderIcon('ri-image-add-line') },
  { key: 'manage-listings', label: 'Manage listings', icon: renderIcon('ri-list-settings-line') },
  { key: 'guidelines', label: 'Operator guidelines', icon: renderIcon('ri-graduation-cap-line') },
]

const hashToSection = {
  overview: 'overview',
  'upload-info': 'upload-info',
  'media-manager': 'media-manager',
  'listings-panel': 'manage-listings',
  'manage-listings': 'manage-listings',
  guidelines: 'guidelines',
}

const sectionToHash = Object.entries(hashToSection).reduce((acc, [hash, section]) => {
  if (!acc[section]) {
    acc[section] = hash
  }
  return acc
}, /** @type {Record<string, string>} */ ({}))

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
const activeSection = ref('overview')
const searchQuery = ref('')
const isSearchActive = ref(false)
const isSelectingSearchResult = ref(false)
const searchInput = ref(null)

watch(activeSection, () => {
  searchQuery.value = ''
  isSearchActive.value = false
  isSelectingSearchResult.value = false
  searchInput.value?.blur()
})

const sectionMeta = {
  overview: {
    title: 'Tourism Operator Control Center',
    subtitle: 'Track listing health, synchronise Agoda bookings, and uplift local partners.',
  },
  'upload-info': {
    title: 'Business Registration Module',
    subtitle: 'Capture business profiles for hotels and community-based operators.',
  },
  'media-manager': {
    title: 'Media Manager',
    subtitle: 'Upload photos, menus, and brochures to strengthen listing appeal.',
  },
  'manage-listings': {
    title: 'Listing Management',
    subtitle: 'Edit details, toggle visibility, and maintain up-to-date information.',
  },
  guidelines: {
    title: 'Operator Guidelines',
    subtitle: 'Follow the documented workflows to keep submissions accurate and review-ready.',
  },
}

const headerActionsMap = {
  overview: [
    { key: 'start-registration', label: 'Start registration flow', type: 'primary', target: 'upload-info' },
    { key: 'open-guidelines', label: 'Review guidelines', tertiary: true, target: 'guidelines' },
  ],
  'upload-info': [
    {
      key: 'save-draft',
      label: 'Save draft',
      type: 'primary',
      modal: {
        title: 'Draft saved locally',
        description:
          'Hook this action to your API to persist form progress. For now the system simulates a successful save while you develop backend endpoints.',
      },
    },
    {
      key: 'view-sample',
      label: 'View sample entry',
      tertiary: true,
      modal: {
        title: 'Sample business profile',
        description:
          'Use the Rainforest Homestay example from your FYP documentation to demonstrate how a completed submission should look.',
      },
    },
  ],
  'media-manager': [
    { key: 'focus-media', label: 'Add new media', type: 'primary', target: 'media-manager-action' },
    {
      key: 'publish-media',
      label: 'Publish pending media',
      tertiary: true,
      modal: {
        title: 'Pending media queue',
        description:
          'Once moderation tools are ready, link this action to publish all approved assets in bulk.',
      },
    },
  ],
  'manage-listings': [
    {
      key: 'bulk-update',
      label: 'Bulk update listings',
      type: 'primary',
      modal: {
        title: 'Bulk update placeholder',
        description:
          'Wire this action to a batch editing workflow or spreadsheet import when the backend is prepared.',
      },
    },
    {
      key: 'export',
      label: 'Download CSV',
      tertiary: true,
      modal: {
        title: 'Export listings',
        description:
          'Connect this control to a reporting service to share listing data with stakeholders.',
      },
    },
  ],
  guidelines: [
    {
      key: 'download-handbook',
      label: 'Download handbook',
      type: 'primary',
      modal: {
        title: 'Operator handbook',
        description:
          'Attach your final PDF or Google Drive link here so operators can review policies before submitting listings.',
      },
    },
    { key: 'back-overview', label: 'Back to overview', tertiary: true, target: 'overview' },
  ],
}

const activeSectionMeta = computed(() => sectionMeta[activeSection.value] ?? sectionMeta.overview)
const headerButtons = computed(
  () => headerActionsMap[activeSection.value] ?? headerActionsMap.overview,
)

const { primaryAction: primaryHeaderAction, secondaryActions: secondaryHeaderActions } =
  usePrimaryHeaderAction(headerButtons)

const normalizedSearchQuery = computed(() => searchQuery.value.trim().toLowerCase())

function normalizeSearchValue(value) {
  if (!value) return ''
  return value
    .trim()
    .toLowerCase()
    .replace(/^(a|an|the)\s+/i, '')
}

function startsWithTerm(value, term) {
  if (!value || !term) return false
  return normalizeSearchValue(value).startsWith(term)
}

const dashboardSearchItems = computed(() => {
  const term = normalizedSearchQuery.value
  if (!term) return []

  const items = []

  summaryCards.value.forEach((card) => {
    if (startsWithTerm(card.label, term)) {
      items.push({
        key: `summary-${card.key}`,
        title: card.label,
        subtitle: 'Dashboard metric',
        action: () => goToSection('overview'),
      })
    }
  })

  partnerCategories.forEach((partner) => {
    if (
      startsWithTerm(partner.title, term) ||
      startsWithTerm(partner.description, term)
    ) {
      items.push({
        key: `partner-${partner.key}`,
        title: partner.title,
        subtitle: 'Dashboard partner category',
        action: () => goToSection('overview'),
      })
    }
  })

  return items
})

const businessInfoSearchItems = computed(() => {
  const term = normalizedSearchQuery.value
  if (!term) return []

  const items = []
  categoryOptions.forEach((option) => {
    if (startsWithTerm(option.label, term)) {
      items.push({
        key: `category-${option.value}`,
        title: option.label,
        subtitle: 'Business info category',
        action: () => {
          goToSection('upload-info')
          formState.category = option.value
        },
      })
    }
  })

  uploadFlow.forEach((step, index) => {
    if (
      startsWithTerm(step.title, term) ||
      startsWithTerm(step.description, term)
    ) {
      items.push({
        key: `upload-step-${index}`,
        title: step.title,
        subtitle: 'Business info workflow',
        action: () => goToSection('upload-info'),
      })
    }
  })

  return items
})

const mediaSearchItems = computed(() => {
  const term = normalizedSearchQuery.value
  if (!term) return []

  return mediaLibrary.value
    .filter((asset) => {
      const labelMatch = startsWithTerm(asset.label, term)
      const fileMatch = startsWithTerm(asset.fileName, term)
      return labelMatch || fileMatch
    })
    .map((asset) => ({
      key: `media-${asset.id}`,
      title: asset.label,
      subtitle: `${asset.type}${asset.fileName ? ` - ${asset.fileName}` : ''}`,
      action: () => {
        goToSection('media-manager')
        openMediaEdit(asset)
      },
    }))
})

const listingSearchItems = computed(() => {
  const term = normalizedSearchQuery.value
  if (!term) return []

  return listings.value
    .filter((listing) => {
      const tokens = [listing.name, listing.category, listing.address, listing.id]
      return tokens.some((token) => startsWithTerm(token, term))
    })
    .map((listing) => ({
      key: `listing-${listing.id}`,
      title: listing.name,
      subtitle: `${listing.category} - ${listing.address}`,
      action: () => {
        goToSection('manage-listings')
        nextTick(() => openPreview(listing))
      },
    }))
})

const guidelineSearchItems = computed(() => {
  const term = normalizedSearchQuery.value
  if (!term) return []

  const items = []

  guidelinesChecklist.forEach((point, index) => {
    if (startsWithTerm(point, term)) {
      items.push({
        key: `guideline-check-${index}`,
        title: point,
        subtitle: 'Guidelines checklist',
        action: () => goToSection('guidelines'),
      })
    }
  })

  uploadFlow.forEach((step, index) => {
    if (startsWithTerm(step.title, term)) {
      items.push({
        key: `guideline-upload-${index}`,
        title: step.title,
        subtitle: 'Upload flow step',
        action: () => {
          goToSection('guidelines')
          guidelineTab.value = 'upload'
        },
      })
    }
  })

  manageFlow.forEach((step, index) => {
    if (startsWithTerm(step.title, term)) {
      items.push({
        key: `guideline-manage-${index}`,
        title: step.title,
        subtitle: 'Manage flow step',
        action: () => {
          goToSection('guidelines')
          guidelineTab.value = 'manage'
        },
      })
    }
  })

  guidelineResources.forEach((resource, index) => {
    if (startsWithTerm(resource.title, term)) {
      items.push({
        key: `guideline-resource-${index}`,
        title: resource.title,
        subtitle: 'Best-practice resource',
        action: () => goToSection('guidelines'),
      })
    }
  })

  return items
})

const searchResults = computed(() => {
  const term = normalizedSearchQuery.value
  if (!term) return []

  const sections = []

  if (activeSection.value === 'overview') {
    if (dashboardSearchItems.value.length) {
      sections.push({
        key: 'dashboard',
        title: 'Dashboard overview',
        items: dashboardSearchItems.value,
      })
    }
  } else if (activeSection.value === 'upload-info') {
    if (businessInfoSearchItems.value.length) {
      sections.push({
        key: 'business-info',
        title: 'Upload business info',
        items: businessInfoSearchItems.value,
      })
    }
  } else if (activeSection.value === 'media-manager') {
    if (mediaSearchItems.value.length) {
      sections.push({
        key: 'media',
        title: 'Media library',
        items: mediaSearchItems.value,
      })
    }
  } else if (activeSection.value === 'manage-listings') {
    if (listingSearchItems.value.length) {
      sections.push({
        key: 'listings',
        title: 'Manage listings',
        items: listingSearchItems.value,
      })
    }
  } else if (activeSection.value === 'guidelines') {
    const guidelineItems = guidelineSearchItems.value
    if (guidelineItems.length) {
      sections.push({
        key: 'guidelines',
        title: 'Operator guidelines',
        items: guidelineItems,
      })
    }
  }

  return sections
})

const hasSearchResults = computed(() =>
  searchResults.value.some((section) => section.items.length > 0),
)

const showSearchOverlay = computed(
  () => isSearchActive.value && normalizedSearchQuery.value.length > 0,
)

const actionModal = reactive({
  visible: false,
  title: '',
  description: '',
  primaryLabel: 'Close',
})

const guidelineFlowMeta = computed(() =>
  guidelineTab.value === 'upload'
    ? {
        title: 'Upload Business Info flow',
        subtitle: 'Step-by-step journey from your documented use case.',
      }
    : {
        title: 'Manage Business Listing flow',
        subtitle: 'Operations after submission: edit, hide/unhide, delete.',
      },
)

function validateForm() {
  const errors = {}

  if (!formState.name.trim()) errors.name = 'Business name is required.'
  if (!formState.category) errors.category = 'Select the category that best fits your service.'
  if (!formState.phone.trim()) errors.phone = 'Provide a contact number for travelers.'
  if (!formState.email.trim()) errors.email = 'Email address is required.'
  if (formState.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formState.email)) {
    errors.email = 'Enter a valid email format.'
  }
  if (formState.website && !URL_PATTERN.test(formState.website.trim())) {
    errors.website = 'Enter a valid URL including http:// or https://.'
  }
  if (!formState.address.trim()) errors.address = 'Physical address helps travelers locate you.'
  if (!formState.description.trim()) errors.description = 'Describe your services and highlights.'

  Object.keys(formErrors).forEach((key) => {
    delete formErrors[key]
  })

  Object.assign(formErrors, errors)

  return Object.keys(errors).length === 0
}

function resetForm() {
  formState.name = ''
  formState.category = null
  formState.type = 'Small Business'
  formState.phone = ''
  formState.email = ''
  formState.address = ''
  formState.website = ''
  formState.description = ''
  formState.highlights = ''
  submissionError.value = null
}

async function submitListing() {
  if (!validateForm()) {
    submissionSuccess.value = null
    return
  }

  const operatorId = operator.value?.id ?? props.operator?.id ?? null
  if (!operatorId) {
    submissionError.value = 'Operator account not loaded. Please refresh and try again.'
    return
  }

  isSubmittingListing.value = true
  submissionError.value = null
  submissionSuccess.value = null

  const payload = {
    operatorId,
    name: formState.name.trim(),
    category: formState.category,
    description: formState.description.trim(),
    address: formState.address.trim(),
    phone: formState.phone.trim(),
    email: formState.email.trim(),
    website: formState.website.trim() || null,
    highlights: formState.highlights?.trim() || '',
  }

  try {
    const response = await fetch(`${API_BASE}/operator/listings.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })

    const result = await response.json().catch(() => null)
    if (!response.ok || !result || result.ok !== true || !result.listing) {
      throw new Error(result?.error || `Failed to submit listing (HTTP ${response.status})`)
    }

    listings.value = [
      result.listing,
      ...listings.value.filter((item) => extractListingId(item) !== result.listing.listingId),
    ]

    submissionSuccess.value =
      result.message ??
      `Listing ${result.listing.id} submitted successfully. Status set to Pending Review.`
    submissionError.value = null
    if (result.operator) {
      remoteOperator.value = { ...(remoteOperator.value ?? {}), ...result.operator }
    }
    resetForm()
    scrollContentToTop()
  } catch (error) {
    submissionError.value =
      error instanceof Error ? error.message : 'Unable to submit listing. Please try again.'
  } finally {
    isSubmittingListing.value = false
  }
}

const mediaTypes = [
  { label: 'Accommodation photo', value: 'Accommodation photo' },
  { label: 'Food menu (PDF)', value: 'Food menu' },
  { label: 'Tour highlight', value: 'Tour highlight' },
  { label: 'Promotional brochure', value: 'Promotional brochure' },
]

const primaryOptions = [
  { label: 'No', value: false },
  { label: 'Yes, mark as primary visual', value: true },
]

const newMedia = reactive({
  label: '',
  type: 'Accommodation photo',
  isPrimary: false,
  listingId: null,
  file: null,
  fileName: '',
  mimeType: '',
  fileSize: 0,
  autoType: null,
})

const mediaErrors = reactive({})
const mediaConfirmation = ref('')
const isMediaSaving = ref(false)
const mediaEditModalVisible = ref(false)
const mediaEditForm = reactive({
  id: null,
  label: '',
  type: 'Accommodation photo',
  isPrimary: false,
  fileName: '',
  mimeType: '',
})
const mediaEditErrors = reactive({})

watch(
  mediaListingOptions,
  (options) => {
    if (!options.length) {
      newMedia.listingId = null
      return
    }
    if (!options.some((option) => option.value === newMedia.listingId)) {
      newMedia.listingId = options[0].value
    }
  },
  { immediate: true },
)

const mediaSelectionMessage = ref('')

function detectMediaType(file) {
  const mime = file.type ?? ''
  const name = file.name ?? ''
  if (mime.startsWith('image/') || /\.(png|jpe?g|gif|webp|heic)$/i.test(name)) {
    return 'Accommodation photo'
  }
  if (mime === 'application/pdf' || /\.pdf$/i.test(name)) {
    return 'Food menu (PDF)'
  }
  if (/\.(mp4|mov|avi)$/i.test(name)) {
    return 'Tour highlight'
  }
  return 'Promotional brochure'
}

function isSupportedMediaFile(file) {
  if (!file) return false
  if (file.size && file.size > MAX_MEDIA_SIZE_BYTES) {
    return false
  }
  const mime = file.type ?? detectMimeFromName(file.name ?? '')
  return SUPPORTED_MEDIA_TYPES.includes(mime)
}

function detectMimeFromName(name) {
  const extension = name.split('.').pop()?.toLowerCase()
  switch (extension) {
    case 'jpg':
    case 'jpeg':
      return 'image/jpeg'
    case 'png':
      return 'image/png'
    case 'gif':
      return 'image/gif'
    case 'webp':
      return 'image/webp'
    case 'pdf':
      return 'application/pdf'
    case 'mp4':
      return 'video/mp4'
    case 'mov':
      return 'video/quicktime'
    default:
      return ''
  }
}

function formatBytes(bytes) {
  if (!bytes) return 'N/A'
  const units = ['B', 'KB', 'MB', 'GB']
  let size = bytes
  let unitIndex = 0
  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024
    unitIndex += 1
  }
  return `${size.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`
}

function handleMediaFileSelect({ file }) {
  const rawFile = file?.file ?? file
  if (!rawFile) {
    return false
  }

  if (!isSupportedMediaFile(rawFile)) {
    mediaErrors.file = MEDIA_VALIDATION_MESSAGE
    newMedia.file = null
    newMedia.fileName = ''
    newMedia.mimeType = ''
    newMedia.fileSize = 0
    newMedia.autoType = null
    mediaSelectionMessage.value = ''
    return false
  }

  const detectedType = detectMediaType(rawFile)
  newMedia.file = rawFile
  newMedia.fileName = rawFile.name ?? ''
  newMedia.mimeType = rawFile.type ?? ''
  newMedia.fileSize = rawFile.size ?? 0
  newMedia.type = detectedType
  newMedia.autoType = detectedType
  mediaSelectionMessage.value = `Detected ${detectedType.toLowerCase()} from ${rawFile.name}.`
  mediaConfirmation.value = ''

  if (mediaErrors.file) {
    delete mediaErrors.file
  }

  return false
}

function clearMediaSelection() {
  newMedia.file = null
  newMedia.fileName = ''
  newMedia.mimeType = ''
  newMedia.fileSize = 0
  newMedia.autoType = null
  mediaSelectionMessage.value = ''
  if (mediaErrors.file) {
    delete mediaErrors.file
  }
}

async function addMediaAsset() {
  const errors = {}
  if (!newMedia.label.trim()) errors.label = 'Provide a title for the photo or menu.'
  if (!newMedia.file) errors.file = 'Select a media file to attach.'
  if (!newMedia.listingId) errors.listingId = 'Choose the listing this media belongs to.'

  Object.keys(mediaErrors).forEach((key) => delete mediaErrors[key])
  Object.assign(mediaErrors, errors)

  if (Object.keys(errors).length > 0) {
    mediaConfirmation.value = ''
    return
  }

  const operatorId = operator.value?.id ?? props.operator?.id ?? null
  if (!operatorId) {
    mediaErrors.upload = 'Operator account not loaded. Please refresh and try again.'
    return
  }

  const formData = new FormData()
  formData.append('operatorId', operatorId)
  formData.append('listingId', newMedia.listingId)
  formData.append('title', newMedia.label.trim())
  formData.append('type', newMedia.type)
  formData.append('isPrimary', newMedia.isPrimary ? 'true' : 'false')
  formData.append('file', newMedia.file, newMedia.file.name ?? 'upload.bin')

  isMediaSaving.value = true
  mediaConfirmation.value = ''
  if (mediaErrors.upload) delete mediaErrors.upload

  try {
    const response = await fetch(`${API_BASE}/operator/upload_media.php`, {
      method: 'POST',
      body: formData,
    })

    let payload = null
    try {
      payload = await response.json()
    } catch (error) {
      // JSON parse errors handled below
    }

    if (!response.ok || !payload || payload.ok !== true) {
      const message =
        (payload && typeof payload === 'object' && payload.error) ||
        `Failed to upload media (HTTP ${response.status})`
      throw new Error(message)
    }

    const asset = normalizeMediaAsset(payload.asset)
    if (asset.isPrimary && asset.listingId != null) {
      mediaLibrary.value = mediaLibrary.value.map((item) =>
        item.listingId === asset.listingId ? { ...item, isPrimary: false } : item,
      )
    }
    mediaLibrary.value = [asset, ...mediaLibrary.value]

    mediaConfirmation.value = `${asset.label} uploaded successfully.`
    mediaSelectionMessage.value = ''
    newMedia.label = ''
    newMedia.type = 'Accommodation photo'
    newMedia.isPrimary = false
    clearMediaSelection()
  } catch (error) {
    mediaErrors.upload = error instanceof Error ? error.message : 'Failed to upload media.'
  } finally {
    isMediaSaving.value = false
  }
}

function openMediaEdit(asset) {
  mediaEditForm.id = asset.id
  mediaEditForm.label = asset.label
  mediaEditForm.type = asset.type
  mediaEditForm.isPrimary = asset.isPrimary
  mediaEditForm.fileName = asset.fileName
  mediaEditForm.mimeType = asset.mimeType
  Object.keys(mediaEditErrors).forEach((key) => delete mediaEditErrors[key])
  mediaEditModalVisible.value = true
}

function saveMediaEdits() {
  const errors = {}
  if (!mediaEditForm.label?.trim()) errors.label = 'Provide a title for the media asset.'
  if (!mediaEditForm.type) errors.type = 'Select a media type.'

  Object.keys(mediaEditErrors).forEach((key) => delete mediaEditErrors[key])
  Object.assign(mediaEditErrors, errors)

  if (Object.keys(errors).length > 0) {
    return
  }

  const shouldSetPrimary = Boolean(mediaEditForm.isPrimary)
  mediaLibrary.value = mediaLibrary.value.map((asset) => {
    if (asset.id === mediaEditForm.id) {
      return {
        ...asset,
        label: mediaEditForm.label.trim(),
        type: mediaEditForm.type,
        isPrimary: shouldSetPrimary,
        lastUpdated: new Date().toISOString(),
      }
    }
    return shouldSetPrimary ? { ...asset, isPrimary: false } : asset
  })

  mediaConfirmation.value = `${mediaEditForm.label.trim()} updated successfully.`
  mediaEditModalVisible.value = false
}

function markPrimaryMedia(target) {
  mediaLibrary.value = mediaLibrary.value.map((asset) => ({
    ...asset,
    isPrimary: asset.id === target.id,
  }))
  mediaConfirmation.value = `${target.label} is now marked as the primary visual.`
}

function removeMediaAsset(target) {
  mediaLibrary.value = mediaLibrary.value.filter((asset) => asset.id !== target.id)
  mediaConfirmation.value = `${target.label} removed from the gallery.`
}

const previewModalVisible = ref(false)
const previewListing = ref(null)
const editModalVisible = ref(false)
const editForm = reactive({})
const editErrors = reactive({})
const autoSaveMessage = ref('')
const listingActionError = ref(null)
const isListingSaving = ref(false)

const statusOptions = [
  { label: 'Active', value: 'Active' },
  { label: 'Pending Review', value: 'Pending Review' },
  { label: 'Hidden', value: 'Hidden' },
]

const visibilityOptions = [
  { label: 'Visible', value: 'Visible' },
  { label: 'Hidden', value: 'Hidden' },
]

const listingColumns = computed(() => [
  {
    title: 'Listing',
    key: 'name',
    render(row) {
      return h('div', { class: 'listing-name-cell' }, [
        h('div', { class: 'listing-name' }, row.name),
        h('div', { class: 'listing-meta' }, `${row.category} - ${row.address}`),
      ])
    },
  },
  {
    title: 'Status',
    key: 'status',
    render(row) {
      const type = row.status === 'Active' ? 'success' : row.status === 'Hidden' ? 'warning' : 'info'
      return h(
        NTag,
        { type, bordered: false },
        { default: () => row.status },
      )
    },
  },
  {
    title: 'Visibility',
    key: 'visibility',
    render(row) {
      const type = row.visibility === 'Visible' ? 'success' : 'default'
      return h(
        NTag,
        { type, bordered: true },
        { default: () => row.visibility },
      )
    },
  },
  {
    title: 'Last updated',
    key: 'lastUpdated',
    render(row) {
      const date = new Date(row.lastUpdated)
      return date.toLocaleString()
    },
  },
  {
    title: 'Actions',
    key: 'actions',
    render(row) {
      return h(
        NSpace,
        { size: 'small' },
        {
          default: () => [
            h(
              NButton,
              {
                text: true,
                size: 'small',
                onClick: () => openEdit(row),
              },
              { default: () => 'Edit' },
            ),
            h(
              NButton,
              {
                text: true,
                size: 'small',
                onClick: () => toggleVisibility(row),
              },
              { default: () => (row.visibility === 'Visible' ? 'Hide' : 'Unhide') },
            ),
            h(
              NButton,
              {
                text: true,
                size: 'small',
                onClick: () => openPreview(row),
              },
              { default: () => 'Preview' },
            ),
            h(
              NButton,
              {
                text: true,
                size: 'small',
                type: 'error',
                onClick: () => deleteListing(row),
              },
              { default: () => 'Delete' },
            ),
          ],
        },
      )
    },
  },
])

function openPreview(listing) {
  previewListing.value = { ...listing }
  previewModalVisible.value = true
}

function openEdit(listing) {
  editForm.id = listing.id
  editForm.name = listing.name
  editForm.phone = listing.contact?.phone || ''
  editForm.email = listing.contact?.email || ''
  editForm.address = listing.address
  editForm.highlight = listing.highlight
  editForm.status = listing.status
  editForm.visibility = listing.visibility
  editForm.category = listing.category
  Object.keys(editErrors).forEach((key) => delete editErrors[key])
  editModalVisible.value = true
}

function validateEditForm() {
  const errors = {}
  if (!editForm.name?.trim()) errors.name = 'Name is required.'
  if (!editForm.email?.trim()) {
    errors.email = 'Email is required.'
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(editForm.email)) {
    errors.email = 'Provide a valid email.'
  }
  if (!editForm.phone?.trim()) errors.phone = 'Phone number is required.'
  if (!editForm.address?.trim()) errors.address = 'Address is required.'

  Object.keys(editErrors).forEach((key) => delete editErrors[key])
  Object.assign(editErrors, errors)

  return Object.keys(errors).length === 0
}

async function saveListingEdits() {
  if (!validateEditForm()) {
    return
  }

  const operatorId = operator.value?.id ?? props.operator?.id ?? null
  const listingIdNumeric = extractListingId(editForm)
  if (!operatorId || listingIdNumeric == null) {
    listingActionError.value = 'Unable to identify listing for update.'
    return
  }

  isListingSaving.value = true
  listingActionError.value = null
  autoSaveMessage.value = ''

  try {
    const response = await fetch(`${API_BASE}/operator/listings.php`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        operatorId,
        listingId: listingIdNumeric,
        name: editForm.name.trim(),
        status: editForm.status,
        visibility: editForm.visibility,
        address: editForm.address.trim(),
        phone: editForm.phone.trim(),
        email: editForm.email.trim(),
        description: editForm.highlight?.trim() || '',
      }),
    })

    const result = await response.json().catch(() => null)
    if (!response.ok || !result || result.ok !== true || !result.listing) {
      throw new Error(result?.error || `Failed to update listing (HTTP ${response.status})`)
    }

    listings.value = listings.value.map((listing) =>
      extractListingId(listing) === result.listing.listingId ? result.listing : listing,
    )

    if (result.operator) {
      remoteOperator.value = { ...(remoteOperator.value ?? {}), ...result.operator }
    }

    autoSaveMessage.value =
      result.message ?? `Listing updated and saved at ${new Date().toLocaleString()}.`
    listingActionError.value = null
    editModalVisible.value = false
  } catch (error) {
    listingActionError.value =
      error instanceof Error ? error.message : 'Unable to update listing. Please try again.'
  } finally {
    isListingSaving.value = false
  }
}

async function toggleVisibility(listing) {
  const operatorId = operator.value?.id ?? props.operator?.id ?? null
  const listingIdNumeric = extractListingId(listing)
  if (!operatorId || listingIdNumeric == null) {
    listingActionError.value = 'Unable to toggle listing visibility.'
    return
  }

  const nextVisibility = listing.visibility === 'Visible' ? 'Hidden' : 'Visible'
  const nextStatus =
    nextVisibility === 'Visible'
      ? listing.status === 'Pending Review'
        ? 'Pending Review'
        : 'Active'
      : 'Hidden'

  listingActionError.value = null
  autoSaveMessage.value = ''

  try {
    const response = await fetch(`${API_BASE}/operator/listings.php`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        operatorId,
        listingId: listingIdNumeric,
        visibility: nextVisibility,
        status: nextStatus,
      }),
    })

    const result = await response.json().catch(() => null)
    if (!response.ok || !result || result.ok !== true || !result.listing) {
      throw new Error(result?.error || `Failed to update visibility (HTTP ${response.status})`)
    }

    listings.value = listings.value.map((item) =>
      extractListingId(item) === result.listing.listingId ? result.listing : item,
    )
    if (result.operator) {
      remoteOperator.value = { ...(remoteOperator.value ?? {}), ...result.operator }
    }
    autoSaveMessage.value =
      result.message ?? `${result.listing.name} visibility updated successfully.`
  } catch (error) {
    listingActionError.value =
      error instanceof Error ? error.message : 'Unable to toggle listing visibility.'
  }
}

async function deleteListing(listing) {
  if (typeof window !== 'undefined') {
    const proceed = window.confirm(`Delete ${listing.name}? This action cannot be undone.`)
    if (!proceed) {
      return
    }
  }

  const operatorId = operator.value?.id ?? props.operator?.id ?? null
  const listingIdNumeric = extractListingId(listing)
  if (!operatorId || listingIdNumeric == null) {
    listingActionError.value = 'Unable to delete listing.'
    return
  }

  listingActionError.value = null
  autoSaveMessage.value = ''

  try {
    const response = await fetch(`${API_BASE}/operator/listings.php`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        operatorId,
        listingId: listingIdNumeric,
      }),
    })

    const result = await response.json().catch(() => null)
    if (!response.ok || !result || result.ok !== true) {
      throw new Error(result?.error || `Failed to delete listing (HTTP ${response.status})`)
    }

    listings.value = listings.value.filter(
      (item) => extractListingId(item) !== listingIdNumeric,
    )
    autoSaveMessage.value = result.message ?? `${listing.name} removed from the platform.`
  } catch (error) {
    listingActionError.value =
      error instanceof Error ? error.message : 'Unable to delete listing. Please try again.'
  }
}

function handleMenuSelect(key) {
  goToSection(key)
}

function goToSection(section) {
  if (!section) return
  activeSection.value = section
  if (section === 'guidelines') {
    guidelineTab.value = 'upload'
  }
  scrollContentToTop()
  if (typeof window !== 'undefined') {
    const hash = sectionToHash[section]
    if (hash) {
      window.location.hash = hash
    }
  }

  if (section !== 'upload-info') {
    submissionError.value = null
    submissionSuccess.value = null
  }
  if (section !== 'manage-listings') {
    listingActionError.value = null
    autoSaveMessage.value = ''
  }
}

function handleSearchFocus() {
  isSearchActive.value = true
}

function handleSearchBlur() {
  setTimeout(() => {
    if (isSelectingSearchResult.value) {
      return
    }
    isSearchActive.value = false
  }, 120)
}

function selectSearchResult(item) {
  if (!item) return
  isSelectingSearchResult.value = true
  if (typeof item.action === 'function') {
    item.action()
  }
  searchQuery.value = ''
  searchInput.value?.blur()
  nextTick(() => {
    isSelectingSearchResult.value = false
    isSearchActive.value = false
  })
}

function handleSearchKeydown(event) {
  if (event.key !== 'Enter') return
  const firstSection = searchResults.value[0]
  const firstItem = firstSection?.items?.[0]
  if (firstItem) {
    event.preventDefault()
    selectSearchResult(firstItem)
  }
}

function scrollContentToTop() {
  nextTick(() => {
    const container = document.getElementById('operator-scroll-root')
    if (container) {
      container.scrollTo({ top: 0, behavior: 'smooth' })
    }
  })
}

function focusElement(id) {
  nextTick(() => {
    const el = document.getElementById(id)
    if (el) {
      el.focus()
      el.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }
  })
}

function handleAction(action) {
  if (!action) return
  if (action.target) {
    if (action.target === 'media-manager-action') {
      goToSection('media-manager')
      focusElement('operator-media-title')
      return
    }
    goToSection(action.target)
    return
  }
  if (action.modal) {
    actionModal.title = action.modal.title
    actionModal.description = action.modal.description
    actionModal.primaryLabel = action.modal.primaryLabel ?? 'Close'
    actionModal.visible = true
    return
  }
  actionModal.title = 'Action triggered'
  actionModal.description =
    'This button is wired and ready for integration. Connect it to your API workflow when backend endpoints are available.'
  actionModal.primaryLabel = 'Close'
  actionModal.visible = true
}

function resolveSectionFromHash(hash) {
  const value = (hash || '').replace(/^#/, '')
  return hashToSection[value] ?? null
}

function handleOperatorNavigate(event) {
  const detail = typeof event.detail === 'string' ? event.detail : ''
  const section = resolveSectionFromHash(detail)
  if (section) {
    goToSection(section)
  }
}

async function loadOperatorDashboard(operatorId) {
  if (!operatorId) return
  if (isDashboardLoading.value) return

  isDashboardLoading.value = true
  dashboardError.value = null

  try {
    const response = await fetch(
      `${API_BASE}/operator/dashboard.php?operatorId=${encodeURIComponent(operatorId)}`,
    )

    let payload = null
    try {
      payload = await response.json()
    } catch (error) {
      // Ignore JSON parsing error for now; handled by response.ok block
    }

    if (!response.ok) {
      const message =
        (payload && typeof payload === 'object' && payload.error) ||
        `Failed to load dashboard data (HTTP ${response.status})`
      throw new Error(message)
    }

    if (!payload || payload.ok !== true) {
      throw new Error((payload && payload.error) || 'Failed to load dashboard data.')
    }

    if (payload.operator && typeof payload.operator === 'object') {
      remoteOperator.value = payload.operator
    }

    if (Array.isArray(payload.listings)) {
      listings.value = cloneRecords(payload.listings.map(normalizeListingRecord))
    } else {
      listings.value = []
    }

    if (Array.isArray(payload.mediaAssets)) {
      mediaLibrary.value = cloneRecords(payload.mediaAssets.map(normalizeMediaAsset))
    } else {
      mediaLibrary.value = []
    }

    lastLoadedOperatorId.value = operatorId
  } catch (error) {
    console.error('Failed to load operator dashboard', error)
    dashboardError.value =
      error instanceof Error ? error.message : 'Unable to load operator dashboard data.'
  } finally {
    isDashboardLoading.value = false
  }
}

onMounted(() => {
  console.log('BusinessOperatorDashboard mounted')
  if (typeof window === 'undefined') return
  window.addEventListener('operator:navigate', handleOperatorNavigate)
  const initial = resolveSectionFromHash(window.location.hash)
  if (initial && initial !== activeSection.value) {
    goToSection(initial)
  }
})

onBeforeUnmount(() => {
  if (typeof window === 'undefined') return
  window.removeEventListener('operator:navigate', handleOperatorNavigate)
})

function normalizeListingRecord(record) {
  const source = record && typeof record === 'object' ? record : {}
  const listingId = source.listingId ?? null
  const contactSource =
    source.contact && typeof source.contact === 'object' ? source.contact : {}

  return {
    ...source,
    id:
      source.id ??
      (listingId != null ? `LST-${String(listingId).padStart(4, '0')}` : `LST-${cryptoRandomId()}`),
    listingId,
    name: source.name ?? 'Untitled listing',
    category: source.category ?? 'Uncategorised',
    type: source.type ?? 'Business',
    status: source.status ?? 'Pending Review',
    visibility: source.visibility ?? 'Hidden',
    lastUpdated: source.lastUpdated ?? new Date().toISOString(),
    address: source.address ?? '',
    highlight: source.highlight ?? '',
    reviewNotes: source.reviewNotes ?? '',
    contact: {
      phone: contactSource.phone ?? '',
      email: contactSource.email ?? '',
    },
  }
}

function normalizeMediaAsset(record) {
  const source = record && typeof record === 'object' ? record : {}
  const mediaId = source.mediaId ?? null
  const listingIdRaw = source.listingId ?? source.listingID ?? null
  const listingId =
    typeof listingIdRaw === 'number'
      ? listingIdRaw
      : typeof listingIdRaw === 'string' && listingIdRaw !== ''
        ? Number.parseInt(listingIdRaw, 10)
        : null
  const fileName =
    source.fileName ?? (source.url && typeof source.url === 'string' ? source.url.split('/').pop() : null)
  const mimeType = source.mimeType ?? (fileName ? inferMimeFromName(fileName) : null)
  const fileSize =
    typeof source.fileSize === 'number'
      ? source.fileSize
      : typeof source.fileSize === 'string' && source.fileSize !== ''
        ? Number(source.fileSize)
        : null

  return {
    id:
      source.id ??
      (mediaId != null ? `MED-${String(mediaId).padStart(4, '0')}` : `MED-${cryptoRandomId()}`),
    mediaId,
    listingId,
    listingName:
      source.listingName ?? (listingId != null ? listingNameById.value.get(listingId) : undefined) ?? '',
    label: source.label ?? fileName ?? 'Media asset',
    type: source.type ?? 'Image',
    status: source.status ?? 'Published',
    isPrimary: Boolean(source.isPrimary),
    lastUpdated: source.lastUpdated ?? new Date().toISOString(),
    fileName,
    mimeType,
    fileSize,
    url: source.url ?? null,
  }
}

function inferMimeFromName(fileName) {
  if (!fileName) return null
  const extension = fileName.split('.').pop()?.toLowerCase()
  switch (extension) {
    case 'jpg':
    case 'jpeg':
      return 'image/jpeg'
    case 'png':
      return 'image/png'
    case 'gif':
      return 'image/gif'
    case 'webp':
      return 'image/webp'
    case 'pdf':
      return 'application/pdf'
    case 'mp4':
      return 'video/mp4'
    case 'mov':
      return 'video/quicktime'
    default:
      return null
  }
}

function cryptoRandomId() {
  const globalCrypto = typeof globalThis !== 'undefined' ? globalThis.crypto : undefined
  if (globalCrypto && globalCrypto.getRandomValues) {
    const array = new Uint32Array(1)
    globalCrypto.getRandomValues(array)
    return array[0].toString(36).slice(0, 6)
  }
  return Math.random().toString(36).slice(2, 8)
}

function cloneRecords(items) {
  return items.map((item) => ({ ...item, contact: item.contact ? { ...item.contact } : undefined }))
}

function extractListingId(listing) {
  if (!listing || typeof listing !== 'object') return null
  if (listing.listingId != null) {
    const numeric = Number(listing.listingId)
    return Number.isNaN(numeric) ? null : numeric
  }
  if (typeof listing.id === 'string') {
    const match = listing.id.match(/(\d+)/)
    if (match && match[1]) {
      const numeric = Number(match[1])
      return Number.isNaN(numeric) ? null : numeric
    }
  }
  return null
}

function renderIcon(name) {
  return () => h(NIcon, null, { default: () => h('i', { class: name }) })
}
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
      <n-space vertical size="small" :style="sidebarCollapsed ? collapsedSidebarStyle : expandedSidebarStyle">
        <template v-if="sidebarCollapsed">
          <n-avatar size="large" style="background: var(--primary-color-hover); color: white;">MS</n-avatar>
        </template>
        <template v-else>
          <n-gradient-text type="info" style="font-size: 1.1rem; font-weight: 600;">
            Tourism Operator Hub
          </n-gradient-text>
          <n-text depth="3">Manage Malaysia Sustainable listings</n-text>
        </template>
        <n-switch v-model:value="sidebarCollapsed" size="small" round>
          <template #checked>Collapsed</template>
          <template #unchecked>Expanded</template>
        </n-switch>
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
                <n-avatar round size="large" style="background: var(--primary-color-hover); color: white;">
                  {{ operator.initials }}
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
            <div class="operator-header__controls">
              <div class="operator-search">
                <n-input
                  ref="searchInput"
                  v-model:value="searchQuery"
                  round
                  clearable
                  placeholder="Search listings, media, or categories"
                  style="min-width: 280px;"
                  @focus="handleSearchFocus"
                  @blur="handleSearchBlur"
                  @keydown="handleSearchKeydown"
                  @clear="searchQuery = ''"
                >
                  <template #suffix>
                    <n-icon size="18">
                      <i class="ri-search-2-line" />
                    </n-icon>
                  </template>
                </n-input>
                <transition name="fade-in-scale">
                  <n-card
                    v-if="showSearchOverlay"
                    bordered
                    size="small"
                    class="operator-search__panel"
                  >
                    <template v-if="hasSearchResults">
                      <div
                        v-for="section in searchResults"
                        :key="section.key"
                        class="operator-search__section"
                      >
                        <div class="operator-search__section-title">{{ section.title }}</div>
                        <n-list :show-divider="false" :bordered="false">
                          <n-list-item v-for="item in section.items" :key="item.key">
                            <div
                              class="operator-search__item"
                              @mousedown.prevent="selectSearchResult(item)"
                            >
                              <div class="operator-search__item-title">{{ item.title }}</div>
                              <div class="operator-search__item-subtitle">{{ item.subtitle }}</div>
                            </div>
                          </n-list-item>
                        </n-list>
                      </div>
                    </template>
                    <n-empty v-else description="No matches found." />
                  </n-card>
                </transition>
              </div>
              <div class="operator-header__primary" v-if="primaryHeaderAction">
                <n-button
                  data-test="header-primary-action"
                  :type="primaryHeaderAction.type ?? 'default'"
                  :tertiary="primaryHeaderAction.tertiary"
                  :quaternary="primaryHeaderAction.quaternary"
                  round
                  size="large"
                  @click="handleAction(primaryHeaderAction)"
                >
                  {{ primaryHeaderAction.label }}
                </n-button>
              </div>
            </div>
          </div>
          <n-space v-if="secondaryHeaderActions.length" wrap class="operator-header__actions">
            <n-button
              v-for="action in secondaryHeaderActions"
              :key="action.key"
              :type="action.type ?? 'default'"
              :tertiary="action.tertiary"
              :quaternary="action.quaternary"
              round
              @click="handleAction(action)"
            >
              {{ action.label }}
            </n-button>
          </n-space>
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
                    <n-space>
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
                          <div v-if="card.type === 'number'" class="summary-card__value">
                            <n-number-animation :from="0" :to="card.value" :duration="1200" show-separator />
                          </div>
                          <div v-else class="summary-card__text">
                            {{ card.value }}
                          </div>
                        </n-space>
                      </n-card>
                    </n-grid-item>
                  </n-grid>
                </n-grid-item>
              </n-grid>
            </n-card>

            <n-card title="Partner categories" :segmented="{ content: true }">
              <n-grid cols="1 m:2" :x-gap="18" :y-gap="18">
                <n-grid-item v-for="partner in partnerCategories" :key="partner.key">
                  <n-card size="medium" :segmented="{ content: true }" style="height: 100%;">
                    <n-space vertical size="small">
                      <n-space align="center" size="small">
                        <n-icon size="20">
                          <i :class="partner.icon" />
                        </n-icon>
                        <div style="font-size: 1.15rem; font-weight: 600;">{{ partner.title }}</div>
                      </n-space>
                      <n-text depth="3">{{ partner.description }}</n-text>
                      <n-tag type="info" size="small" bordered>{{ partner.highlight }}</n-tag>
                    </n-space>
                  </n-card>
                </n-grid-item>
              </n-grid>
            </n-card>

            <n-card title="Business listing workflow" :segmented="{ content: true }">
              <n-grid cols="1 m:3" :x-gap="16" :y-gap="16">
                <n-grid-item v-for="module in workflowModules" :key="module.key">
                  <n-card size="small" :segmented="{ content: true }" style="height: 100%;">
                    <n-space vertical size="small">
                      <n-space align="center" size="small">
                        <n-icon size="18">
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

            <n-card title="Latest submissions" :segmented="{ content: true }">
              <template v-if="recentListings.length">
                <n-list bordered :show-divider="false">
                  <n-list-item v-for="item in recentListings" :key="item.id">
                    <n-space justify="space-between" align="center" style="width: 100%;">
                      <n-space vertical size="small">
                        <span style="font-weight: 600;">{{ item.name }}</span>
                        <n-text depth="3">{{ item.category }} - {{ item.address }}</n-text>
                      </n-space>
                      <n-space size="small">
                        <n-tag type="info" size="small" bordered>{{ item.status }}</n-tag>
                        <n-button text type="primary" size="small" @click="openPreview(item)">
                          Preview
                        </n-button>
                      </n-space>
                    </n-space>
                  </n-list-item>
                </n-list>
              </template>
              <template v-else>
                <n-empty description="No listings submitted yet." />
              </template>
            </n-card>
          </n-space>
        </template>

        <template v-else-if="activeSection === 'upload-info'">
          <n-space vertical size="large">
            <n-grid cols="1 m:3" :x-gap="16" :y-gap="16">
              <n-grid-item>
                <n-card size="small" :segmented="{ content: true }" style="height: 100%;">
                  <n-space vertical size="small">
                    <div style="font-weight: 600;">1. Business registration form</div>
                    <n-text depth="3">
                      Captures business name, category, contact number, email, and address with examples so operators know exactly what to submit.
                    </n-text>
                  </n-space>
                </n-card>
              </n-grid-item>
              <n-grid-item>
                <n-card size="small" :segmented="{ content: true }" style="height: 100%;">
                  <n-space vertical size="small">
                    <div style="font-weight: 600;">2. Service description & highlights</div>
                    <n-text depth="3">
                      Outline packages, specialties, or sustainability pledges so travelers quickly understand the differentiators.
                    </n-text>
                  </n-space>
                </n-card>
              </n-grid-item>
              <n-grid-item>
                <n-card size="small" :segmented="{ content: true }" style="height: 100%;">
                  <n-space vertical size="small">
                    <div style="font-weight: 600;">3. System review & storage</div>
                    <n-text depth="3">
                      Submissions enter Pending Review for administrative approval before publication, maintaining authenticity and quality.
                    </n-text>
                  </n-space>
                </n-card>
              </n-grid-item>
            </n-grid>

            <n-card id="upload-info" title="Business registration" :segmented="{ content: true }">
              <n-form label-placement="top" label-width="auto">
                <n-grid cols="1 m:2" :x-gap="18" :y-gap="18">
                  <n-grid-item>
                    <n-form-item label="Business name" :feedback="formErrors.name" :validation-status="formErrors.name ? 'error' : undefined">
                      <n-input v-model:value="formState.name" placeholder="e.g. Sungai Palas Eco Homestay" />
                    </n-form-item>
                  </n-grid-item>
                  <n-grid-item>
                    <n-form-item label="Service category" :feedback="formErrors.category" :validation-status="formErrors.category ? 'error' : undefined">
                      <n-select v-model:value="formState.category" :options="categoryOptions" placeholder="Select category" />
                    </n-form-item>
                  </n-grid-item>
                  <n-grid-item>
                    <n-form-item label="Contact number" :feedback="formErrors.phone" :validation-status="formErrors.phone ? 'error' : undefined">
                      <n-input v-model:value="formState.phone" placeholder="e.g. +60 12-345 6789" />
                    </n-form-item>
                  </n-grid-item>
                  <n-grid-item>
                    <n-form-item label="Contact email" :feedback="formErrors.email" :validation-status="formErrors.email ? 'error' : undefined">
                      <n-input v-model:value="formState.email" placeholder="e.g. hello@business.my" />
                    </n-form-item>
                  </n-grid-item>
                  <n-grid-item>
                    <n-form-item label="Business address" :feedback="formErrors.address" :validation-status="formErrors.address ? 'error' : undefined">
                      <n-input v-model:value="formState.address" placeholder="Street, city, state" />
                    </n-form-item>
                  </n-grid-item>
                  <n-grid-item>
                    <n-form-item
                      label="Website / booking link"
                      :feedback="formErrors.website"
                      :validation-status="formErrors.website ? 'error' : undefined"
                    >
                      <n-input v-model:value="formState.website" placeholder="Optional URL" />
                    </n-form-item>
                  </n-grid-item>
                  <n-grid-item span="2">
                    <n-form-item label="Service description" :feedback="formErrors.description" :validation-status="formErrors.description ? 'error' : undefined">
                      <n-input
                        v-model:value="formState.description"
                        type="textarea"
                        :rows="4"
                        placeholder="Share what makes your offering unique, sustainability practices, or packages."
                      />
                    </n-form-item>
                  </n-grid-item>
                  <n-grid-item span="2">
                    <n-form-item label="Highlights (optional)">
                      <n-input
                        v-model:value="formState.highlights"
                        type="textarea"
                        :rows="2"
                        placeholder="List key selling points or certifications."
                      />
                    </n-form-item>
                  </n-grid-item>
                </n-grid>

                <n-space justify="end" style="margin-top: 12px;">
                  <n-button tertiary type="primary" @click.prevent="resetForm">Clear form</n-button>
                  <n-button
                    type="primary"
                    :loading="isSubmittingListing"
                    :disabled="isSubmittingListing"
                    @click.prevent="submitListing"
                  >
                    Submit for review
                  </n-button>
                </n-space>
              </n-form>

              <n-alert
                v-if="submissionError"
                type="error"
                title="Unable to submit listing"
                show-icon
                style="margin-top: 16px;"
              >
                {{ submissionError }}
              </n-alert>
              <n-alert
                v-else-if="submissionSuccess"
                type="success"
                title="Submission received"
                show-icon
                style="margin-top: 16px;"
              >
                {{ submissionSuccess }}
              </n-alert>
              <n-alert
                v-else-if="Object.keys(formErrors).length"
                type="warning"
                title="Please resolve the highlighted fields"
                show-icon
                style="margin-top: 16px;"
              >
                The form mirrors the report workflow: all required information must be valid before the system stores your listing.
              </n-alert>
            </n-card>
          </n-space>
        </template>

        <template v-else-if="activeSection === 'media-manager'">
          <n-space vertical size="large">
            <n-card id="media-manager" title="Upload photos and menus" :segmented="{ content: true }">
              <n-text depth="3">
                Add visuals that reinforce your business story. Use clear names so administrators can verify assets during review.
              </n-text>

              <n-form label-placement="top" label-width="auto" style="margin-top: 16px;">
                <n-grid cols="1 m:3" :x-gap="16" :y-gap="16">
                  <n-grid-item>
                    <n-form-item label="Media title" :feedback="mediaErrors.label" :validation-status="mediaErrors.label ? 'error' : undefined">
                      <n-input id="operator-media-title" v-model:value="newMedia.label" placeholder="e.g. Sunset boardwalk photo" />
                    </n-form-item>
                  </n-grid-item>
                  <n-grid-item>
                    <n-form-item label="Type">
                      <n-space vertical size="small" style="width: 100%;">
                        <n-select v-model:value="newMedia.type" :options="mediaTypes" />
                        <n-alert v-if="newMedia.autoType" type="info" size="small" bordered>
                          Detected: {{ newMedia.autoType }} (adjust if needed)
                        </n-alert>
                      </n-space>
                    </n-form-item>
                  </n-grid-item>
                  <n-grid-item>
                    <n-form-item
                      label="Linked listing"
                      :feedback="mediaErrors.listingId"
                      :validation-status="mediaErrors.listingId ? 'error' : undefined"
                    >
                      <n-select
                        v-model:value="newMedia.listingId"
                        :options="mediaListingOptions"
                        :disabled="!mediaListingOptions.length"
                        placeholder="Select linked listing"
                      />
                    </n-form-item>
                  </n-grid-item>
                  <n-grid-item>
                    <n-form-item label="Primary asset">
                      <n-select v-model:value="newMedia.isPrimary" :options="primaryOptions" />
                    </n-form-item>
                  </n-grid-item>
                  <n-grid-item span="1 m:3">
                    <n-form-item label="Attachment" :feedback="mediaErrors.file" :validation-status="mediaErrors.file ? 'error' : undefined">
                      <n-upload
                        :max="1"
                        :show-file-list="false"
                        accept=".jpg,.jpeg,.png,.gif,.webp,.heic,.pdf,.mp4,.mov"
                        @before-upload="handleMediaFileSelect"
                      >
                        <n-button tertiary type="primary">Select file</n-button>
                      </n-upload>
                      <template v-if="newMedia.fileName">
                        <div class="media-file-preview">
                          <div class="media-file-preview__header">
                            <n-text depth="3">
                              {{ newMedia.fileName }}<span v-if="newMedia.fileSize"> ({{ formatBytes(newMedia.fileSize) }})</span>
                            </n-text>
                            <n-button text type="error" size="tiny" @click="clearMediaSelection">
                              Remove file
                            </n-button>
                          </div>
                          <n-text v-if="newMedia.mimeType" depth="3">MIME: {{ newMedia.mimeType }}</n-text>
                          <n-tag type="info" size="small" bordered>
                            Detected type: {{ newMedia.type }}
                          </n-tag>
                        </div>
                      </template>
                    </n-form-item>
                  </n-grid-item>
                </n-grid>

                <n-alert
                  v-if="!mediaListingOptions.length"
                  type="warning"
                  size="small"
                  show-icon
                  style="margin-top: 12px;"
                >
                  Submit a business listing before uploading media so the asset can be linked.
                </n-alert>

                <n-space justify="end" style="margin-top: 12px;">
                  <n-button
                    type="primary"
                    :loading="isMediaSaving"
                    :disabled="isMediaSaving || !mediaListingOptions.length"
                    @click.prevent="addMediaAsset"
                  >
                    Add media
                  </n-button>
                </n-space>
              </n-form>

              <n-alert
                v-if="mediaSelectionMessage"
                type="info"
                show-icon
                style="margin-top: 12px;"
              >
                {{ mediaSelectionMessage }}
              </n-alert>
              <n-alert
                v-else-if="mediaConfirmation"
                type="success"
                show-icon
                style="margin-top: 12px;"
              >
                {{ mediaConfirmation }}
              </n-alert>
              <n-alert
                v-else-if="mediaErrors.upload"
                type="error"
                show-icon
                style="margin-top: 12px;"
              >
                {{ mediaErrors.upload }}
              </n-alert>
            </n-card>

            <n-card title="Media library" :segmented="{ content: true }">
              <template v-if="mediaLibrary.length">
                <n-grid cols="1 m:2" :x-gap="18" :y-gap="18">
                  <n-grid-item v-for="asset in mediaLibrary" :key="asset.id">
                    <n-card size="small" :segmented="{ content: true, footer: true }" style="height: 100%;">
                      <n-space vertical size="small">
                        <div style="font-weight: 600;">{{ asset.label }}</div>
                        <n-tag type="info" size="small" bordered>{{ asset.type }}</n-tag>
                        <n-text depth="3">Status: {{ asset.status }}</n-text>
                        <n-text depth="3">Last updated: {{ new Date(asset.lastUpdated).toLocaleString() }}</n-text>
                        <n-text depth="3">
                          File: {{ asset.fileName || '-' }}<span v-if="asset.fileSize"> ({{ formatBytes(asset.fileSize) }})</span>
                        </n-text>
                        <n-text depth="3">
                          Linked listing: {{ asset.listingName || '-' }}
                        </n-text>
                        <n-text v-if="asset.mimeType" depth="3">MIME: {{ asset.mimeType }}</n-text>
                        <n-tag v-if="asset.isPrimary" type="success" size="small" bordered>Primary visual</n-tag>
                      </n-space>
                      <template #footer>
                        <n-space justify="space-between" align="center">
                          <n-space size="small">
                            <n-button text type="primary" @click="openMediaEdit(asset)">
                              Edit
                            </n-button>
                            <n-button text type="primary" @click="markPrimaryMedia(asset)">
                              {{ asset.isPrimary ? 'Primary' : 'Make primary' }}
                            </n-button>
                          </n-space>
                          <n-button text type="error" @click="removeMediaAsset(asset)">
                            Remove
                          </n-button>
                        </n-space>
                      </template>
                    </n-card>
                  </n-grid-item>
                </n-grid>
              </template>
              <template v-else>
                <n-empty description="No media uploaded yet." />
              </template>
            </n-card>
          </n-space>
        </template>

        <template v-else-if="activeSection === 'manage-listings'">
          <n-space vertical size="large">
            <n-card id="listings-panel" title="Manage listings" :segmented="{ content: true }">
              <n-text depth="3">
                Control visibility, update contact details, or remove listings. Each action mirrors the Manage Business Listing use case from your documentation.
              </n-text>

              <n-alert
                v-if="listingActionError"
                type="error"
                show-icon
                style="margin-top: 16px;"
              >
                {{ listingActionError }}
              </n-alert>
              <n-alert v-if="autoSaveMessage" type="success" show-icon style="margin-top: 16px;">
                {{ autoSaveMessage }}
              </n-alert>

              <template v-if="listings.length">
                <n-data-table :columns="listingColumns" :data="listings" :single-line="false" style="margin-top: 16px;" />
              </template>
              <n-empty v-else description="No listings yet. Submit a business profile to get started." style="margin-top: 16px;" />
            </n-card>
          </n-space>
        </template>

        <template v-else>
          <n-space vertical size="large">
            <n-card title="Operator guidelines checklist" :segmented="{ content: true }">
              <n-space vertical size="small">
                <n-text depth="3">Follow these checkpoints before submitting or updating listings.</n-text>
                <n-list bordered :show-divider="false">
                  <n-list-item v-for="item in guidelinesChecklist" :key="item">
                    {{ item }}
                  </n-list-item>
                </n-list>
              </n-space>
            </n-card>

            <n-card :title="guidelineFlowMeta.title" :segmented="{ content: true }">
              <n-text depth="3">{{ guidelineFlowMeta.subtitle }}</n-text>
              <n-tabs
                v-model:value="guidelineTab"
                type="segment"
                size="small"
                style="margin-top: 16px;"
              >
                <n-tab-pane name="upload" tab="Upload Business Info">
                  <n-timeline size="large">
                    <n-timeline-item v-for="step in uploadFlow" :key="step.title" :title="step.title">
                      <n-text depth="3">{{ step.description }}</n-text>
                    </n-timeline-item>
                  </n-timeline>
                </n-tab-pane>
                <n-tab-pane name="manage" tab="Manage Business Listing">
                  <n-timeline size="large">
                    <n-timeline-item v-for="step in manageFlow" :key="step.title" :title="step.title">
                      <n-text depth="3">{{ step.description }}</n-text>
                    </n-timeline-item>
                  </n-timeline>
                </n-tab-pane>
              </n-tabs>
            </n-card>

            <n-card title="Best-practice resources" :segmented="{ content: true }">
              <n-list bordered :show-divider="false">
                <n-list-item v-for="resource in guidelineResources" :key="resource.title">
                  <n-space vertical size="small">
                    <span style="font-weight: 600;">{{ resource.title }}</span>
                    <n-text depth="3">{{ resource.description }}</n-text>
                  </n-space>
                </n-list-item>
              </n-list>
            </n-card>
          </n-space>
        </template>
      </n-layout-content>
    </n-layout>
  </n-layout>

  <n-modal
    v-model:show="actionModal.visible"
    preset="card"
    :title="actionModal.title || 'Action triggered'"
  >
    <n-text depth="3">{{ actionModal.description }}</n-text>
    <template #footer>
      <n-space justify="end">
        <n-button type="primary" @click="actionModal.visible = false">{{ actionModal.primaryLabel }}</n-button>
      </n-space>
    </template>
  </n-modal>

  <n-modal v-model:show="previewModalVisible" preset="card" :title="previewListing?.name || 'Preview listing'">
    <n-space vertical size="small" v-if="previewListing">
      <n-text depth="3">Category: {{ previewListing.category }}</n-text>
      <n-text depth="3">Status: {{ previewListing.status }} - Visibility: {{ previewListing.visibility }}</n-text>
      <n-text depth="3">Address: {{ previewListing.address }}</n-text>
      <n-text depth="3">Contact: {{ previewListing.contact?.phone }} - {{ previewListing.contact?.email }}</n-text>
      <n-text depth="3">Highlights: {{ previewListing.highlight }}</n-text>
      <n-text depth="3">Review notes: {{ previewListing.reviewNotes }}</n-text>
      <n-text depth="3">Last updated: {{ new Date(previewListing.lastUpdated).toLocaleString() }}</n-text>
    </n-space>
    <template #footer>
      <n-space justify="end">
        <n-button type="primary" @click="previewModalVisible = false">Close preview</n-button>
      </n-space>
    </template>
  </n-modal>

  <n-modal v-model:show="mediaEditModalVisible" preset="card" title="Edit media asset">
    <n-space vertical size="large">
      <n-form label-placement="top" label-width="auto">
        <n-form-item label="Title" :feedback="mediaEditErrors.label" :validation-status="mediaEditErrors.label ? 'error' : undefined">
          <n-input v-model:value="mediaEditForm.label" />
        </n-form-item>
        <n-form-item label="Type" :feedback="mediaEditErrors.type" :validation-status="mediaEditErrors.type ? 'error' : undefined">
          <n-select v-model:value="mediaEditForm.type" :options="mediaTypes" />
        </n-form-item>
        <n-form-item label="Primary asset">
          <n-select v-model:value="mediaEditForm.isPrimary" :options="primaryOptions" />
        </n-form-item>
        <n-form-item label="File name">
          <n-input v-model:value="mediaEditForm.fileName" disabled />
        </n-form-item>
        <n-form-item label="MIME type">
          <n-input v-model:value="mediaEditForm.mimeType" disabled />
        </n-form-item>
      </n-form>
      <n-space justify="end">
        <n-button tertiary type="primary" @click="mediaEditModalVisible = false">Cancel</n-button>
        <n-button type="primary" @click="saveMediaEdits">Save changes</n-button>
      </n-space>
    </n-space>
  </n-modal>

  <n-modal v-model:show="editModalVisible" preset="card" title="Edit listing">
    <n-space vertical size="large">
      <n-form label-placement="top" label-width="auto">
        <n-form-item label="Listing name" :feedback="editErrors.name" :validation-status="editErrors.name ? 'error' : undefined">
          <n-input v-model:value="editForm.name" />
        </n-form-item>
        <n-form-item label="Email" :feedback="editErrors.email" :validation-status="editErrors.email ? 'error' : undefined">
          <n-input v-model:value="editForm.email" />
        </n-form-item>
        <n-form-item label="Phone" :feedback="editErrors.phone" :validation-status="editErrors.phone ? 'error' : undefined">
          <n-input v-model:value="editForm.phone" />
        </n-form-item>
        <n-form-item label="Address" :feedback="editErrors.address" :validation-status="editErrors.address ? 'error' : undefined">
          <n-input v-model:value="editForm.address" />
        </n-form-item>
        <n-form-item label="Highlights">
          <n-input v-model:value="editForm.highlight" type="textarea" :rows="3" />
        </n-form-item>
        <n-form-item label="Status">
          <n-select v-model:value="editForm.status" :options="statusOptions" />
        </n-form-item>
        <n-form-item label="Visibility">
          <n-select v-model:value="editForm.visibility" :options="visibilityOptions" />
        </n-form-item>
      </n-form>
      <n-space justify="end">
        <n-button tertiary type="primary" @click="editModalVisible = false">Cancel</n-button>
        <n-button type="primary" :loading="isListingSaving" :disabled="isListingSaving" @click="saveListingEdits">
          Save changes
        </n-button>
      </n-space>
    </n-space>
  </n-modal>
</template>

<style scoped>
:global(body) {
  background: var(--body-color);
}

.operator-name {
  font-size: 1.35rem;
  font-weight: 600;
}

.operator-header {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.operator-header__card {
  display: grid;
  grid-template-columns: minmax(240px, 1fr) minmax(260px, auto);
  gap: 24px;
  align-items: center;
  padding: 22px 28px;
  border-radius: 20px;
  background: linear-gradient(135deg, rgba(66, 184, 131, 0.16), rgba(108, 99, 255, 0.12));
  border: 1px solid rgba(66, 184, 131, 0.2);
  box-shadow: 0 20px 30px rgba(12, 58, 36, 0.08);
  min-height: 220px;
}

.operator-header__identity-block {
  display: flex;
  align-items: center;
}

.operator-header__identity {
  display: flex;
  align-items: center;
  gap: 16px;
}

.operator-header__details {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.operator-header__meta {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.operator-header__controls {
  display: flex;
  flex-direction: column;
  gap: 12px;
  align-items: flex-end;
  min-width: 260px;
  flex: 1 1 320px;
}

.operator-header__primary {
  display: flex;
  justify-content: flex-end;
  width: 100%;
}

.operator-header__actions {
  gap: 12px;
}

@media (max-width: 899px) {
  .operator-header__card {
    grid-template-columns: 1fr;
  }

  .operator-header__controls {
    align-items: flex-start;
  }

  .operator-header__primary {
    justify-content: flex-start;
  }
}

.summary-card__value,
.summary-card__text {
  display: flex;
  justify-content: center;
  align-items: center;
  width: 100%;
  text-align: center;
}

.summary-card__value {
  font-size: 1.6rem;
  font-weight: 700;
}

.summary-card__text {
  font-size: 1.05rem;
  font-weight: 600;
}

.section-title {
  font-size: 1.05rem;
  font-weight: 600;
  margin-top: 4px;
}

.listing-name {
  font-weight: 600;
}

.listing-meta {
  font-size: 0.85rem;
  color: rgba(0, 0, 0, 0.6);
}

.listing-name-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.media-file-preview {
  margin-top: 8px;
  padding: 10px 12px;
  border: 1px solid #e5e8eb;
  border-radius: 10px;
  background: #f8fafc;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.media-file-preview__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.operator-search {
  position: relative;
  flex: 1 1 280px;
  min-width: 280px;
  max-width: 420px;
}

.operator-search__panel {
  position: absolute;
  top: 48px;
  left: 0;
  width: 100%;
  max-width: 420px;
  z-index: 20;
  box-shadow: 0 16px 32px rgba(0, 0, 0, 0.12);
}

.operator-search__section {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 10px;
}

.operator-search__section:last-of-type {
  margin-bottom: 0;
}

.operator-search__section-title {
  font-size: 0.85rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: rgba(0, 0, 0, 0.55);
  padding: 2px 0;
}

.operator-search__item {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 8px 10px;
  border-radius: 8px;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.operator-search__item:hover {
  background: rgba(66, 184, 131, 0.08);
}

.operator-search__item-title {
  font-size: 0.95rem;
  font-weight: 600;
  color: #0b3b26;
}

.operator-search__item-subtitle {
  font-size: 0.8rem;
  color: rgba(0, 0, 0, 0.6);
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











