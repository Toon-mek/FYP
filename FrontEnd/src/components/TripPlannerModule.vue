<script setup>
import { computed, h, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useDialog, useMessage } from 'naive-ui'
import TripPlannerPreferencesForm from './TripPlannerPreferencesForm.vue'
import TripPlannerItineraryBoard from './TripPlannerItineraryBoard.vue'
import TripPlannerAiAssistant from './TripPlannerAiAssistant.vue'
import TripPlannerMap from './TripPlannerMap.vue'
import TripPlannerQuickPreferences from './TripPlannerQuickPreferences.vue'
import {
  fetchItineraries,
  createItinerary,
  updateItinerary,
  deleteItinerary,
  searchPlacesAutocomplete,
  fetchPlaceDetails,
  searchPlacesByText,
  generateAiItinerary,
  buildStaticMapUrl,
  reverseGeocode,
  fetchHotelsByCoordinates,
  fetchBookingHotelReviewScores,
  fetchBookingHotelPhotos,
  searchBookingAttractions,
  searchBookingAttractionLocations,
  buildPlacePhotoUrl,
  fetchTravelInsights,
  savePlacesPackage,
} from '../services/tripPlannerService.js'

const DEFAULT_ORIGIN = ''
const TRENDING_DESTINATIONS = [
  { label: 'Kuala Lumpur', secondary: 'Malaysia', lat: 3.139, lng: 101.6869 },
  { label: 'George Town', secondary: 'Malaysia', lat: 5.4141, lng: 100.3288 },
  { label: 'Malacca', secondary: 'Malaysia', lat: 2.1896, lng: 102.2501 },
  { label: 'Johor Bahru', secondary: 'Malaysia', lat: 1.4927, lng: 103.7414 },
  { label: 'Ipoh', secondary: 'Malaysia', lat: 4.5975, lng: 101.0901 },
]
const MAPS_JS_KEY = import.meta.env.VITE_GOOGLE_MAPS_KEY || ''
const ACCOMMODATION_LABELS = {
  comfort: 'Comfort',
  premium: 'Premium',
  luxury: 'Luxury',
}
const STYLE_PRICE_HINTS = {
  comfort: 'RM 240 / night',
  premium: 'RM 360 / night',
  luxury: 'RM 520 / night',
}
const ORIGIN_COORDINATES = {
  Cheras: { lat: 3.0856, lng: 101.7441 },
  'Kuala Lumpur': { lat: 3.139, lng: 101.6869 },
  'George Town': { lat: 5.4141, lng: 100.3288 },
  'Kota Kinabalu': { lat: 5.9804, lng: 116.0735 },
  Kuching: { lat: 1.5533, lng: 110.3592 },
  'Johor Bahru': { lat: 1.4927, lng: 103.7414 },
}
const KNOWN_DESTINATION_COORDINATES = {
  'melaka': { label: 'Melaka', lat: 2.1896, lng: 102.2501 },
  'george town': { label: 'George Town', lat: 5.4141, lng: 100.3288 },
  'kuala lumpur': { label: 'Kuala Lumpur', lat: 3.139, lng: 101.6869 },
  'johor bahru': { label: 'Johor Bahru', lat: 1.4927, lng: 103.7414 },
  'ipoh': { label: 'Ipoh', lat: 4.5975, lng: 101.0901 },
  'langkawi': { label: 'Langkawi', lat: 6.3529, lng: 99.7915 },
  'kuantan': { label: 'Kuantan', lat: 3.8077, lng: 103.326 },
  'kota bharu': { label: 'Kota Bharu', lat: 6.1248, lng: 102.2548 },
  'kota kinabalu': { label: 'Kota Kinabalu', lat: 5.9804, lng: 116.0735 },
  'kuching': { label: 'Kuching', lat: 1.5533, lng: 110.3592 },
  'kuala terengganu': { label: 'Kuala Terengganu', lat: 5.329, lng: 103.137 },
  'cameron highlands': { label: 'Cameron Highlands', lat: 4.4706, lng: 101.3779 },
  'genting highlands': { label: 'Genting Highlands', lat: 3.4214, lng: 101.7926 },
  'port dickson': { label: 'Port Dickson', lat: 2.5228, lng: 101.7953 },
  'desaru': { label: 'Desaru', lat: 1.5548, lng: 104.2584 },
  'taman negara': { label: 'Taman Negara', lat: 4.7085, lng: 102.3656 },
  'tioman island': { label: 'Tioman Island', lat: 2.8174, lng: 104.1453 },
  'perhentian islands': { label: 'Perhentian Islands', lat: 5.9125, lng: 102.745 },
}
const DESTINATION_SYNONYMS = {
  'melaka': ['melaka', 'malacca', 'melacca'],
  'george town': ['george town', 'penang', 'penang island'],
  'kuala lumpur': ['kuala lumpur', 'kl'],
  'langkawi': ['langkawi', 'pulau langkawi'],
  'johor bahru': ['johor bahru', 'jb'],
  'kota bharu': ['kota bharu'],
  'kota kinabalu': ['kota kinabalu'],
  'cameron highlands': ['cameron highlands', 'brinchang'],
  'genting highlands': ['genting highlands'],
  'port dickson': ['port dickson'],
  'desaru': ['desaru'],
  'taman negara': ['taman negara'],
  'tioman island': ['tioman island'],
  'perhentian islands': ['perhentian islands'],
}

function normaliseDestinationKey(raw) {
  if (!raw) return null
  const key = raw.trim().toLowerCase()
  if (Object.prototype.hasOwnProperty.call(KNOWN_DESTINATION_COORDINATES, key)) {
    return key
  }
  for (const [canonical, synonyms] of Object.entries(DESTINATION_SYNONYMS)) {
    if (canonical === key) {
      return canonical
    }
    if ((synonyms || []).some((entry) => entry.toLowerCase() === key)) {
      return canonical
    }
  }
  return null
}

function createDestinationMatcher(targetLabel) {
  if (!targetLabel) {
    return {
      canonical: null,
      match: () => true,
    }
  }
  const canonical = normaliseDestinationKey(targetLabel)
  const searchTerms = new Set()
  if (canonical) {
    searchTerms.add(canonical)
    ;(DESTINATION_SYNONYMS[canonical] || []).forEach((term) => searchTerms.add(term.toLowerCase()))
  }
  searchTerms.add(targetLabel.trim().toLowerCase())
  const terms = Array.from(searchTerms).filter(Boolean)
  return {
    canonical,
    match: (candidateLabel) => {
      if (!candidateLabel) return false
      const candidateKey = normaliseDestinationKey(candidateLabel)
      if (canonical && candidateKey) {
        return candidateKey === canonical
      }
      const candidateLower = candidateLabel.trim().toLowerCase()
      return terms.some((term) => candidateLower.includes(term))
    },
  }
}

const DEFAULT_MAP_CENTER = '4.2105,101.9758'
const DEFAULT_BUDGET_RANGE = [0, 2200]
const DEFAULT_SEGMENT_TIME_SLOTS = ['08:30:00', '11:30:00', '14:30:00', '17:30:00', '20:00:00', '22:00:00']
const MEAL_TIME_SLOT = '12:30:00'
const LODGING_TIME_SLOT = '22:00:00'
const SLOT_CATEGORY_ORDER = ['morning', 'meal', 'afternoon', 'evening', 'nightlife', 'lodging']
const NON_LODGING_CATEGORIES = SLOT_CATEGORY_ORDER.filter((category) => category !== 'lodging')
const CATEGORY_TIME_MAP = {
  morning: '08:30:00',
  brunch: '10:30:00',
  breakfast: '08:00:00',
  meal: MEAL_TIME_SLOT,
  lunch: MEAL_TIME_SLOT,
  afternoon: '15:00:00',
  evening: '18:30:00',
  dinner: '18:30:00',
  nightlife: '21:30:00',
  night: '21:30:00',
  stay: LODGING_TIME_SLOT,
  lodging: LODGING_TIME_SLOT,
  flex: '14:00:00',
}
const CATEGORY_COST_DEFAULTS = {
  morning: 70,
  meal: 35,
  afternoon: 80,
  evening: 95,
  nightlife: 75,
  brunch: 40,
  breakfast: 30,
  flex: 40,
  default: 60,
}
const ACCOMMODATION_COST_BY_STYLE = {
  comfort: 250,
  premium: 480,
  luxury: 820,
}
const LODGING_SEGMENT_TITLES = {
  comfort: 'Comfort stay check-in',
  premium: 'Premium stay check-in',
  luxury: 'Luxury stay check-in',
}
const LODGING_SEGMENT_DESCRIPTIONS = {
  comfort: 'Rest in a boutique eco-stay with breakfast included.',
  premium: 'Check into a 4-star city hotel with full amenities.',
  luxury: 'Relax in a 5-star suite with concierge services.',
}
const THEME_BLUEPRINTS = {
  culture: {
    provider: 'google',
    label: 'Culture & arts',
    description: 'Local arts, performance venues, community spaces, and lived traditions.',
    query: (destination) => `cultural experiences and art venues in ${destination}, Malaysia`,
    type: 'tourist_attraction',
    icon: 'ri-bank-line',
  },
  food: {
    provider: 'google',
    label: 'Food trail picks',
    description: 'Local eats, kopitiams, and signature restaurants.',
    query: (destination) => `must try food in ${destination}, Malaysia`,
    type: 'restaurant',
    icon: 'ri-restaurant-2-line',
  },
  relax: {
    provider: 'booking-attractions',
    label: 'Relax & wellness',
    description: 'Spas, hot springs, and calming escapes.',
    query: (destination) => `wellness spa in ${destination}, Malaysia`,
    type: 'spa',
    booking: {
      taxonomySlugs: ['tours'],
      keywordIncludes: ['spa', 'wellness', 'massage', 'hot spring', 'retreat'],
    },
    icon: 'ri-leaf-line',
  },
  nature: {
    provider: 'google',
    label: 'Nature escapes',
    description: 'National parks, mangrove cruises, and rainforest walks.',
    query: (destination) => `nature attractions near ${destination}, Malaysia`,
    type: 'park',
    radius: 45000,
    icon: 'ri-mountain-line',
  },
  adventure: {
    provider: 'booking-attractions',
    label: 'Adventure thrills',
    description: 'Zip-lines, theme parks, treks, and active fun.',
    query: (destination) => `adventure activities in ${destination}, Malaysia`,
    type: 'tourist_attraction',
    radius: 40000,
    booking: {
      taxonomySlugs: ['tours', 'nature-outdoor'],
      keywordIncludes: ['adventure', 'forest', 'river', 'rafting', 'park', 'caves', 'trail'],
    },
    icon: 'ri-compass-3-line',
  },
  city: {
    provider: 'booking-attractions',
    label: 'City highlights',
    description: 'Modern city walks, rooftop views, and galleries.',
    query: (destination) => `city experiences in ${destination}, Malaysia`,
    type: 'tourist_attraction',
    radius: 30000,
    booking: {
      taxonomySlugs: ['tours', 'museums-arts-culture'],
      keywordIncludes: ['city', 'heritage', 'museum', 'street', 'tour', 'gallery'],
    },
    icon: 'ri-building-4-line',
  },
  historical: {
    provider: 'google',
    label: 'Heritage trails',
    description: 'Museums, forts, and UNESCO-listed landmarks.',
    query: (destination) => `historical places in ${destination}, Malaysia`,
    type: 'tourist_attraction',
    radius: 35000,
    icon: 'ri-ancient-gate-line',
  },
}
const BOOKING_THEME_KEYS = new Set(['nature', 'adventure', 'city', 'historical'])
const COST_SUMMARY_THEMES = new Set(['travel', 'adventure', 'city', 'relax'])
const accommodationCache = new Map()
const MAX_ACCOMMODATION_CACHE = 8
const MAX_ACCOMMODATION_RESULTS = 12
const MAX_THEME_RESULTS = 24
const SAVED_PLACES_REFRESH_EVENT = 'traveler-saved-places-refresh'

function buildTrendingDestinationOptions() {
  const options = TRENDING_DESTINATIONS.map((item, index) => ({
    label: item.label,
    value: `trend-${index}`,
    secondaryText: item.secondary,
    lat: item.lat,
    lng: item.lng,
    isTrending: true,
  }))
  if (options.length) {
    options.unshift({
      label: 'Trending destinations',
      value: '__trending-header',
      isHeader: true,
      disabled: true,
    })
  }
  return options
}

const props = defineProps({
  travelerId: {
    type: [Number, String],
    default: null,
  },
  travelerName: {
    type: String,
    default: 'Traveler',
  },
})

const message = useMessage()
const dialog = useDialog()

const plannerPreferences = ref(createDefaultPreferences(props.travelerName))
const heroForm = reactive({
  origin: plannerPreferences.value.origin,
  originPlace:
    typeof plannerPreferences.value.originLat === 'number' && typeof plannerPreferences.value.originLng === 'number'
      ? {
          lat: plannerPreferences.value.originLat,
          lng: plannerPreferences.value.originLng,
          placeId: plannerPreferences.value.originPlaceId || '',
        }
      : null,
  destinationInput: plannerPreferences.value.destination ?? '',
  destination: null,
  dateRange:
    plannerPreferences.value.startDate && plannerPreferences.value.endDate
      ? [plannerPreferences.value.startDate, plannerPreferences.value.endDate]
      : null,
})
const destinationSuggestions = ref([])
destinationSuggestions.value = buildTrendingDestinationOptions()
const placeSearchLoading = ref(false)
const destinationDetailsLoading = ref(false)
const placesSessionToken = ref(createPlacesSessionToken())
const originSuggestions = ref([])
const originSuggestionMap = ref(new Map())
const originSearchLoading = ref(false)
const originSessionToken = ref(createPlacesSessionToken())
const preferencesDrawerVisible = ref(false)

const aiState = reactive({
  running: false,
  error: '',
  plan: null,
})
const aiConversation = ref([])
const quickPreferencesRef = ref(null)
const detectingOrigin = ref(false)
const calendarPopoverVisible = ref(false)
const calendarDraft = ref(
  heroForm.dateRange && heroForm.dateRange.length === 2 ? [...heroForm.dateRange] : null,
)
const plannerActivated = ref(false)
const showItineraryBoard = ref(false)
const latestTravelStats = ref(null)
const curatedExperiences = reactive({
  contextKey: '',
  loading: false,
  visible: false,
  error: '',
  themeResults: [],
  stayResults: [],
  selections: {
    experiences: reactive(new Map()),
    stays: reactive(new Map()),
  },
  lastSelections: null,
  resolver: null,
})
const renderDestinationOption = ({ option }) => {
  if (option.isHeader) {
    return h('div', { class: 'destination-option destination-option--header' }, option.label)
  }
  return h('div', { class: 'destination-option' }, [
    h('div', { class: 'destination-option__icon' }, [h('i', { class: 'ri-map-pin-2-line' })]),
    h('div', { class: 'destination-option__meta' }, [
      h('div', { class: 'destination-option__title' }, option.label),
      option.secondaryText
        ? h('div', { class: 'destination-option__subtitle' }, option.secondaryText)
        : null,
    ]),
  ])
}

const itineraries = ref([])
const loadingList = ref(false)
const saving = ref(false)
const generating = ref(false)

const editor = reactive({
  itineraryId: null,
  title: plannerPreferences.value.title,
  startDate: plannerPreferences.value.startDate,
  endDate: plannerPreferences.value.endDate,
  visibility: plannerPreferences.value.visibility,
  items: [],
})
const deletedItemIds = ref(new Set())
const selectedItineraryId = ref(null)
const travelerReady = computed(() => Number(props.travelerId) > 0)
const curatedConfirmDisabled = computed(() => {
  if (!curatedExperiences.themeResults.length) {
    return false
  }
  return curatedExperiences.themeResults.some(
    (section) => (curatedExperiences.selections.experiences.get(section.theme)?.size ?? 0) === 0,
  )
})

const itemEditor = reactive({
  show: false,
  mode: 'create',
  form: createItemDraft({
    date: plannerPreferences.value.startDate,
  }),
})

onMounted(() => {
  if (travelerReady.value) {
    loadItineraries()
  }
})

watch(
  () => props.travelerId,
  (next) => {
    if (Number(next) > 0) {
      loadItineraries()
    }
  },
)

watch(
  () => plannerPreferences.value,
  (next) => {
    if (editor.itineraryId) {
      return
    }
    editor.title = next.title
    editor.startDate = ensureIsoDate(next.startDate) || editor.startDate
    editor.endDate = ensureIsoDate(next.endDate) || editor.endDate
    editor.visibility = next.visibility
  },
  { deep: true },
)

watch(
  () => heroForm.dateRange,
  (range) => {
    if (Array.isArray(range) && range[0] && range[1]) {
      const startIso = ensureIsoDate(range[0])
      const endIso = ensureIsoDate(range[1])
      if (startIso && endIso) {
        plannerPreferences.value.startDate = startIso
        plannerPreferences.value.endDate = endIso
        editor.startDate = startIso
        editor.endDate = endIso
        plannerPreferences.value.durationDays = calculateDurationDays(startIso, endIso)
        calendarDraft.value = [startIso, endIso]
      }
    } else {
      calendarDraft.value = null
    }
  },
)

let originSearchHandle = null
let originResolveHandle = null
let suppressOriginWatcher = true

watch(
  () => heroForm.origin,
  (value) => {
    plannerPreferences.value.origin = value
    latestTravelStats.value = null
    if (suppressOriginWatcher) {
      return
    }
    const trimmed = value?.trim() ?? ''
    if (!trimmed.length) {
      originSuggestionMap.value = new Map()
      originSuggestions.value = []
      clearTimeout(originResolveHandle)
      return
    }
    clearTimeout(originSearchHandle)
    originSearchHandle = setTimeout(() => fetchOriginSuggestions(trimmed), 320)
    clearTimeout(originResolveHandle)
    originResolveHandle = setTimeout(() => resolveOriginFromFreeText(trimmed), 900)
  },
)

nextTick(() => {
  suppressOriginWatcher = false
})

watch(
  () => heroForm.destination,
  (value) => {
    if (!value) return
    plannerPreferences.value.destination = value.description ?? value.name ?? heroForm.destinationInput
    plannerPreferences.value.destinationPlaceId = value.placeId
    plannerPreferences.value.destinationLat = value.lat
    plannerPreferences.value.destinationLng = value.lng
    latestTravelStats.value = null
    if (!editor.itineraryId) {
      editor.title = `${value.mainText ?? value.description} eco getaway`
      plannerPreferences.value.title = editor.title
    }
  },
)

let destinationSearchHandle = null
watch(
  () => heroForm.destinationInput,
  (value) => {
    if (!value || value.length < 2) {
      destinationSuggestions.value = buildTrendingDestinationOptions()
      if (!value?.trim()) {
        heroForm.destination = null
        plannerPreferences.value.destination = ''
      }
      return
    }
    clearTimeout(destinationSearchHandle)
    destinationSearchHandle = setTimeout(() => fetchDestinationSuggestions(value), 320)
    const trimmed = value.trim()
    if (trimmed && !heroForm.destination) {
      plannerPreferences.value.destination = trimmed
    }
  },
)

watch(
  () => plannerPreferences.value.destination,
  (destination) => {
    const normalised = destination ?? ''
    if (heroForm.destinationInput !== normalised) {
      heroForm.destinationInput = normalised
    }
    if (!normalised) {
      heroForm.destination = null
    }
  },
)

watch(
  () => [plannerPreferences.value.startDate, plannerPreferences.value.endDate],
  ([start, end]) => {
    if (!start || !end) {
      heroForm.dateRange = null
      return
    }
    const [currentStart, currentEnd] = heroForm.dateRange ?? []
    if (currentStart !== start || currentEnd !== end) {
      heroForm.dateRange = [start, end]
    }
  },
)

watch(
  () => [plannerPreferences.value.startDate, plannerPreferences.value.durationDays],
  ([start, duration]) => {
    if (!start || !duration || duration <= 0) {
      return
    }
    const startDate = new Date(start)
    if (Number.isNaN(startDate.getTime())) {
      return
    }
    const newEnd = formatDate(addDays(startDate, duration - 1))
    if (plannerPreferences.value.endDate !== newEnd) {
      plannerPreferences.value.endDate = newEnd
    }
    const [currentStart, currentEnd] = heroForm.dateRange ?? []
    if (currentStart !== start || currentEnd !== newEnd) {
      heroForm.dateRange = [start, newEnd]
    }
  },
)

watch(
  () => plannerPreferences.value.budgetRange,
  (range) => {
    const normalised = normaliseBudgetRange(range)
    if (!arraysEqual(range, normalised)) {
      plannerPreferences.value.budgetRange = normalised
      return
    }
    const average = Math.round((normalised[0] + normalised[1]) / 2)
    if (plannerPreferences.value.budget !== average) {
      plannerPreferences.value.budget = average
    }
  },
  { deep: true, immediate: true },
)

watch(
  () => calendarPopoverVisible.value,
  (visible) => {
    if (visible) {
      calendarDraft.value = heroForm.dateRange && heroForm.dateRange.length === 2 ? [...heroForm.dateRange] : null
    }
  },
)

watch(
  () => [
    heroForm.destination?.placeId,
    heroForm.destination?.lat,
    heroForm.destination?.lng,
    plannerPreferences.value.accommodation,
    plannerPreferences.value.startDate,
    plannerPreferences.value.endDate,
    JSON.stringify((plannerPreferences.value.travelStyles ?? []).slice().sort()),
  ],
  () => {
    resetCuratedSelections()
  },
)

const heroDurationLabel = computed(() => {
  const start = plannerPreferences.value.startDate
  const end = plannerPreferences.value.endDate
  if (!start || !end) return ''
  const days = calculateDurationDays(start, end)
  return `${days} day${days > 1 ? 's' : ''} - ${start} to ${end}`
})

const heroDateLabel = computed(() => {
  if (!heroForm.dateRange || heroForm.dateRange.length !== 2) {
    return 'Check-in date - Check-out date'
  }
  const [start, end] = heroForm.dateRange
  if (!start || !end) {
    return 'Check-in date - Check-out date'
  }
  return `${formatDisplayDate(start)} - ${formatDisplayDate(end)}`
})

const calendarDraftSummary = computed(() => {
  if (!Array.isArray(calendarDraft.value) || calendarDraft.value.length !== 2) {
    return 'Select your check-in and check-out dates'
  }
  const [start, end] = calendarDraft.value
  if (!start || !end) {
    return 'Select your check-in and check-out dates'
  }
  return `${formatDisplayDate(start)} - ${formatDisplayDate(end)}`
})

const calendarDraftNightsLabel = computed(() => {
  if (!Array.isArray(calendarDraft.value) || calendarDraft.value.length !== 2) {
    return ''
  }
  const [start, end] = calendarDraft.value
  if (!start || !end) {
    return ''
  }
  const days = calculateDurationDays(start, end)
  if (!Number.isFinite(days) || days <= 0) {
    return ''
  }
  return `${days} day${days > 1 ? 's' : ''}`
})

const plannerMeta = computed(() => ({
  startDate: editor.startDate,
  endDate: editor.endDate,
  visibility: editor.visibility,
  budget: plannerPreferences.value.budget,
  budgetRange: plannerPreferences.value.budgetRange ?? [],
  accommodation: plannerPreferences.value.accommodation || 'comfort',
  groupSize: plannerPreferences.value.groupSize,
  groupType: plannerPreferences.value.groupType,
  travelPace: plannerPreferences.value.travelPace,
  travelStyles: plannerPreferences.value.travelStyles ?? [],
  interests: plannerPreferences.value.interests ?? [],
}))

const canSave = computed(() => Boolean(editor.title && editor.startDate && editor.endDate && editor.items.length))
const shouldShowItineraryBoard = computed(
  () =>
    showItineraryBoard.value &&
    (plannerActivated.value ||
      Boolean(editor.itineraryId) ||
      Boolean(selectedItineraryId.value) ||
      editor.items.length > 0),
)

const mapMarkers = computed(() => {
  const markers = new Set()
  if (heroForm.destination?.lat && heroForm.destination?.lng) {
    markers.add(`${heroForm.destination.lat},${heroForm.destination.lng}`)
  }
  editor.items.forEach((item) => {
    if (item.latitude && item.longitude) {
      markers.add(`${item.latitude},${item.longitude}`)
    }
  })
  return Array.from(markers)
})

const mapCenter = computed(() => {
  if (heroForm.destination?.lat && heroForm.destination?.lng) {
    return `${heroForm.destination.lat},${heroForm.destination.lng}`
  }
  if (editor.items.length) {
    const first = editor.items.find((item) => item.latitude && item.longitude)
    if (first) {
      return `${first.latitude},${first.longitude}`
    }
  }
  return DEFAULT_MAP_CENTER
})

const mapImageUrl = computed(() =>
  buildStaticMapUrl({
    center: mapCenter.value,
    zoom: heroForm.destination?.lat ? 8 : 5,
    markers: mapMarkers.value,
  }),
)

const mapOrigin = computed(() => {
  if (heroForm.originPlace?.lat && heroForm.originPlace?.lng) {
    return { lat: heroForm.originPlace.lat, lng: heroForm.originPlace.lng, label: heroForm.origin }
  }
  const coordinate = findOriginPresetCoordinate(heroForm.origin)
  return coordinate ? { ...coordinate, label: heroForm.origin } : null
})

const mapDays = computed(() => {
  const grouped = {}
  editor.items.forEach((item) => {
    if (typeof item.latitude !== 'number' || typeof item.longitude !== 'number') {
      return
    }
    const key = item.date || 'unscheduled'
    if (!grouped[key]) {
      grouped[key] = { date: key, items: [] }
    }
    grouped[key].items.push(item)
  })
  return Object.values(grouped).sort((a, b) => a.date.localeCompare(b.date))
})

async function fetchOriginSuggestions(query) {
  originSearchLoading.value = true
  try {
    const result = await searchPlacesAutocomplete(query, {
      sessionToken: originSessionToken.value,
      region: 'MY',
      language: 'en',
    })
    if (result.sessionToken) {
      originSessionToken.value = result.sessionToken
    }
    const predictions = result.data?.predictions ?? []
    const map = new Map()
    originSuggestions.value = predictions.map((prediction) => {
      const meta = {
        placeId: prediction.place_id,
        description: prediction.description,
        mainText: prediction.structured_formatting?.main_text ?? prediction.description,
        secondaryText: prediction.structured_formatting?.secondary_text ?? '',
      }
      map.set(prediction.place_id, meta)
      return {
        label: prediction.description,
        value: prediction.description,
        placeId: prediction.place_id,
        secondaryText: meta.secondaryText,
      }
    })
    originSuggestionMap.value = map
  } catch (error) {
    console.error(error)
  } finally {
    originSearchLoading.value = false
  }
}

async function handleOriginSelect(value, option) {
  const placeId = option?.placeId ?? Array.from(originSuggestionMap.value.values()).find(
    (entry) => entry.description === value,
  )?.placeId
  const label = option?.label ?? value
  suppressOriginWatcher = true
  heroForm.origin = label
  nextTick(() => {
    suppressOriginWatcher = false
  })
  if (placeId) {
    const meta = originSuggestionMap.value.get(placeId) ?? {
      description: label,
      secondaryText: option?.secondaryText ?? '',
    }
    await loadOriginDetails(placeId, label, meta)
  } else {
    applyOriginDetails({ label })
  }
}

async function loadOriginDetails(placeId, fallbackLabel, meta = {}) {
  try {
    const { data } = await fetchPlaceDetails(placeId, { sessionToken: originSessionToken.value })
    const details = data?.result ?? {}
    applyOriginDetails({
      label: details.name ?? fallbackLabel,
      placeId,
      lat: details.geometry?.location?.lat ?? meta.lat ?? null,
      lng: details.geometry?.location?.lng ?? meta.lng ?? null,
      address: details.formatted_address ?? meta.secondaryText ?? fallbackLabel,
    })
  } catch (error) {
    console.error(error)
    applyOriginDetails({
      label: fallbackLabel,
      placeId,
      lat: meta.lat ?? null,
      lng: meta.lng ?? null,
      address: meta.secondaryText ?? fallbackLabel,
    })
  }
}

async function fetchDestinationSuggestions(query) {
  placeSearchLoading.value = true
  try {
    const result = await searchPlacesAutocomplete(query, {
      sessionToken: placesSessionToken.value,
      region: 'MY',
      language: 'en',
      types: 'locality,administrative_area_level_1,administrative_area_level_2',
    })
    if (result.sessionToken) {
      placesSessionToken.value = result.sessionToken
    }
    const predictions = result.data?.predictions ?? []
    destinationSuggestions.value = predictions.map((prediction) => ({
      label: prediction.description,
      value: prediction.place_id,
      mainText: prediction.structured_formatting?.main_text ?? prediction.description,
      secondaryText: prediction.structured_formatting?.secondary_text ?? '',
    }))
  } catch (error) {
    console.error(error)
  } finally {
    placeSearchLoading.value = false
  }
}

async function handleDestinationSelect(value, option = null) {
  const selected =
    option ||
    destinationSuggestions.value.find((entry) => entry.value === value) ||
    destinationSuggestions.value.find((entry) => entry.label === value)
  if (!selected || selected.isHeader) return
  heroForm.destinationInput = selected.label
  if (selected.isTrending && typeof selected.lat === 'number' && typeof selected.lng === 'number') {
    applyDestinationDetails({
      label: selected.label,
      lat: selected.lat,
      lng: selected.lng,
      address: selected.secondaryText,
    })
    return
  }
  await loadDestinationDetails(selected)
}

async function loadDestinationDetails(option) {
  destinationDetailsLoading.value = true
  try {
    const { data } = await fetchPlaceDetails(option.value, { sessionToken: placesSessionToken.value })
    const details = data?.result ?? {}
    applyDestinationDetails({
      label: option.label,
      placeId: option.value,
      lat: details.geometry?.location?.lat ?? null,
      lng: details.geometry?.location?.lng ?? null,
      address: details.formatted_address ?? option.secondaryText ?? option.label,
      mainText: option.mainText,
    })
  } catch (error) {
    console.error(error)
  } finally {
    destinationDetailsLoading.value = false
  }
}

async function ensureDestinationCoordinates() {
  if (
    heroForm.destination &&
    typeof heroForm.destination.lat === 'number' &&
    typeof heroForm.destination.lng === 'number'
  ) {
    return true
  }
  const placeId = heroForm.destination?.placeId || plannerPreferences.value.destinationPlaceId
  if (placeId) {
    await loadDestinationDetails({
      value: placeId,
      label: heroForm.destination?.description || plannerPreferences.value.destination || heroForm.destinationInput,
      secondaryText: heroForm.destination?.address || '',
      mainText: heroForm.destination?.mainText || '',
    })
    if (
      heroForm.destination &&
      typeof heroForm.destination.lat === 'number' &&
      typeof heroForm.destination.lng === 'number'
    ) {
      return true
    }
  }
  const query =
    heroForm.destinationInput ||
    plannerPreferences.value.destination ||
    heroForm.destination?.description ||
    ''
  if (!query) {
    return false
  }
  try {
    const response = await searchPlacesByText({
      query: `${query}, Malaysia`,
      region: 'MY',
      language: 'en',
    })
    const first = response?.data?.results?.[0]
    if (first) {
      applyDestinationDetails({
        label: first.name ?? first.formatted_address ?? query,
        placeId: first.place_id,
        lat: first.geometry?.location?.lat ?? null,
        lng: first.geometry?.location?.lng ?? null,
        address: first.formatted_address ?? first.vicinity ?? query,
        mainText: first.name ?? query,
      })
      return (
        heroForm.destination &&
        typeof heroForm.destination.lat === 'number' &&
        typeof heroForm.destination.lng === 'number'
      )
    }
  } catch (error) {
    console.error('Fallback destination lookup failed', error)
  }
  try {
    const auto = await searchPlacesAutocomplete(query, {
      region: 'MY',
      language: 'en',
      types: 'locality,administrative_area_level_1,administrative_area_level_2',
    })
    const prediction = auto?.data?.predictions?.[0]
    if (prediction) {
      await loadDestinationDetails({
        value: prediction.place_id,
        label: prediction.description,
        secondaryText: prediction.description,
        mainText: prediction.structured_formatting?.main_text ?? prediction.description,
      })
      return (
        heroForm.destination &&
        typeof heroForm.destination.lat === 'number' &&
        typeof heroForm.destination.lng === 'number'
      )
    }
  } catch (error) {
    console.error('Autocomplete lookup failed', error)
  }
  const known = lookupKnownDestination(query)
  if (known) {
    applyDestinationDetails({
      label: known.label,
      placeId: '',
      lat: known.lat,
      lng: known.lng,
      address: known.label,
      mainText: known.label,
    })
    return true
  }
  return false
}

function lookupKnownDestination(raw) {
  if (!raw) return null
  const key = normaliseDestinationKey(raw)
  if (!key) {
    return null
  }
  return KNOWN_DESTINATION_COORDINATES[key] ?? null
}

function findOriginPresetCoordinate(label) {
  if (!label) return null
  const lookup = String(label).trim().toLowerCase()
  if (!lookup) return null
  for (const [key, coordinate] of Object.entries(ORIGIN_COORDINATES)) {
    if (key.toLowerCase() === lookup) {
      return coordinate
    }
  }
  return null
}

function originInputMatchesResolved(input) {
  const value = input?.trim().toLowerCase()
  if (!value) return false
  const place = heroForm.originPlace
  if (!place) {
    return false
  }
  const candidates = [place.address, place.label, heroForm.origin, plannerPreferences.value.origin]
    .filter(Boolean)
    .map((entry) => String(entry).trim().toLowerCase())
  return candidates.includes(value)
}

async function resolveOriginFromFreeText(query) {
  const trimmed = query?.trim()
  if (!trimmed) return
  if (originInputMatchesResolved(trimmed)) {
    return
  }
  const preset = findOriginPresetCoordinate(trimmed)
  if (preset) {
    applyOriginDetails({
      label: trimmed,
      placeId: '',
      lat: preset.lat,
      lng: preset.lng,
      address: trimmed,
    })
    return
  }
  const searchQuery = /malaysia/i.test(trimmed) ? trimmed : `${trimmed}, Malaysia`
  try {
    const response = await searchPlacesByText({
      query: searchQuery,
      region: 'MY',
      language: 'en',
      radius: 60000,
    })
    const first = response?.data?.results?.[0]
    const lat = first?.geometry?.location?.lat
    const lng = first?.geometry?.location?.lng
    if (!first || !Number.isFinite(lat) || !Number.isFinite(lng)) {
      return
    }
    applyOriginDetails({
      label: first.name ?? first.formatted_address ?? trimmed,
      placeId: first.place_id ?? '',
      lat,
      lng,
      address: first.formatted_address ?? first.vicinity ?? trimmed,
    })
  } catch (error) {
    console.warn('Origin free-text resolve failed', error)
  }
}

function applyOriginDetails(details, { syncPreferences = true } = {}) {
  const label = details.label ?? ''
  const placeId = details.placeId ?? ''
  const lat = typeof details.lat === 'number' ? details.lat : null
  const lng = typeof details.lng === 'number' ? details.lng : null
  suppressOriginWatcher = true
  heroForm.origin = label
  heroForm.originPlace =
    lat !== null && lng !== null
      ? {
          lat,
          lng,
          placeId,
          address: details.address ?? '',
        }
      : null
  originSuggestions.value = []
  nextTick(() => {
    suppressOriginWatcher = false
  })
  if (syncPreferences) {
    plannerPreferences.value.origin = label
    plannerPreferences.value.originPlaceId = placeId
    plannerPreferences.value.originLat = lat
    plannerPreferences.value.originLng = lng
  }
}

function handleOriginResetFromPreferences() {
  applyOriginDetails(
    {
      label: plannerPreferences.value.origin,
      placeId: plannerPreferences.value.originPlaceId,
      lat: plannerPreferences.value.originLat,
      lng: plannerPreferences.value.originLng,
    },
    { syncPreferences: false },
  )
}

function applyDestinationDetails(details) {
  heroForm.destination = {
    name: details.name ?? details.label ?? '',
    description: details.label ?? details.name ?? '',
    placeId: details.placeId ?? '',
    lat: typeof details.lat === 'number' ? details.lat : null,
    lng: typeof details.lng === 'number' ? details.lng : null,
    address: details.address ?? details.secondaryText ?? details.label ?? '',
    mainText: details.mainText ?? details.label ?? '',
  }
  plannerPreferences.value.destination = heroForm.destination.description
  plannerPreferences.value.destinationPlaceId = heroForm.destination.placeId
  plannerPreferences.value.destinationLat = heroForm.destination.lat
  plannerPreferences.value.destinationLng = heroForm.destination.lng
}

function resetCuratedSelections() {
  curatedExperiences.contextKey = ''
  curatedExperiences.themeResults = []
  curatedExperiences.stayResults = []
  curatedExperiences.selections.experiences = reactive(new Map())
  curatedExperiences.selections.stays = reactive(new Map())
  curatedExperiences.lastSelections = null
}

async function handleDetectOrigin() {
  if (detectingOrigin.value) return
  detectingOrigin.value = true
  try {
    const result = await acquireBestAvailablePosition()
    await hydrateOriginFromCoordinates(result.coords, result.meta)
  } catch (error) {
    console.error(error)
    message.error(error?.message || 'Unable to detect your location right now.')
  } finally {
    detectingOrigin.value = false
  }
}

async function acquireBestAvailablePosition() {
  const geoSupported = typeof navigator !== 'undefined' && !!navigator.geolocation
  if (geoSupported) {
    try {
      const precise = await watchForPrecisePosition({ desiredAccuracy: 40, maxWait: 12000 })
      return { coords: precise.coords, meta: { source: 'browser', accuracy: precise.coords?.accuracy ?? null } }
    } catch (error) {
      console.warn('High-accuracy watch failed, falling back to single read.', error)
      try {
        const single = await getBrowserPositionOnce()
        return { coords: single.coords, meta: { source: 'browser', accuracy: single.coords?.accuracy ?? null } }
      } catch (innerError) {
        console.warn('Single geolocation read failed, attempting network lookup.', innerError)
      }
    }
  }
  const network = await fetchNetworkEstimatedPosition()
  return network
}

function getBrowserPositionOnce(options = {}) {
  return new Promise((resolve, reject) => {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
      reject(new Error('Geolocation is not supported in this browser.'))
      return
    }
    navigator.geolocation.getCurrentPosition(
      resolve,
      reject,
      Object.assign({ enableHighAccuracy: true, maximumAge: 0, timeout: 15000 }, options),
    )
  })
}

function watchForPrecisePosition({ desiredAccuracy = 50, maxWait = 12000 } = {}) {
  return new Promise((resolve, reject) => {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
      reject(new Error('Geolocation is not supported in this browser.'))
      return
    }
    const geo = navigator.geolocation
    let resolved = false
    let bestPosition = null
    let watchId = null
    let timer = null
    const finish = (position, error) => {
      if (resolved) return
      resolved = true
      if (watchId !== null) {
        geo.clearWatch(watchId)
      }
      if (timer) {
        clearTimeout(timer)
      }
      if (error) {
        reject(error)
      } else {
        resolve(position)
      }
    }
    watchId = geo.watchPosition(
      (pos) => {
        if (!bestPosition || (pos.coords?.accuracy ?? Infinity) < (bestPosition.coords?.accuracy ?? Infinity)) {
          bestPosition = pos
        }
        if ((pos.coords?.accuracy ?? Infinity) <= desiredAccuracy) {
          finish(pos)
        }
      },
      (error) => finish(null, error),
      { enableHighAccuracy: true, maximumAge: 0, timeout: maxWait },
    )
    timer = setTimeout(() => {
      if (bestPosition) {
        finish(bestPosition)
      } else {
        finish(null, new Error('Unable to lock onto a precise GPS coordinate.'))
      }
    }, maxWait)
  })
}

async function fetchNetworkEstimatedPosition() {
  try {
    const response = await fetch('https://ipapi.co/json/')
    if (!response.ok) {
      throw new Error('Network lookup failed.')
    }
    const data = await response.json()
    const latitude = Number(data.latitude)
    const longitude = Number(data.longitude)
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
      throw new Error('Network lookup did not return coordinates.')
    }
    return {
      coords: {
        latitude,
        longitude,
        accuracy:
          Number(data.accuracy) ||
          Number(data.location?.accuracy_radius) ||
          Number(data.postal?.accuracy) ||
          null,
      },
      meta: {
        source: 'network',
        fallbackLabel: [data.city, data.region].filter(Boolean).join(', ') || data.country_name || 'Approximate area',
      },
    }
  } catch (error) {
    console.error('Network-based geolocation failed.', error)
    throw new Error('Unable to approximate your location from the network.')
  }
}

async function hydrateOriginFromCoordinates(coords = {}, meta = {}) {
  const latitude = Number(coords.latitude)
  const longitude = Number(coords.longitude)
  if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
    throw new Error('Detected coordinates are invalid.')
  }
  try {
    const { data } = await reverseGeocode(latitude, longitude)
    const result =
      data?.results?.find((entry) =>
        ['street_address', 'premise', 'sublocality', 'locality', 'administrative_area_level_2'].some((type) =>
          entry.types?.includes(type),
        ),
      ) || data?.results?.[0]
    const label =
      extractLocalityName(result?.address_components) ||
      result?.formatted_address ||
      formatCoordinateLabel(latitude, longitude)
    applyOriginDetails({
      label,
      placeId: result?.place_id ?? '',
      lat: latitude,
      lng: longitude,
      address: result?.formatted_address ?? label,
    })
    const prefix = meta?.source === 'network' ? 'Approximate starting point set to' : 'Starting point set to'
    message.success(`${prefix} ${label}`)
  } catch (error) {
    console.error(error)
    const fallbackLabel = meta?.fallbackLabel ?? formatCoordinateLabel(latitude, longitude)
    applyOriginDetails({
      label: fallbackLabel,
      lat: latitude,
      lng: longitude,
    })
    const warning =
      meta?.source === 'network'
        ? 'Used approximate network coordinates because GPS was unavailable.'
        : 'Used raw coordinates because we could not resolve your address.'
    message.warning(warning)
  }
}

function extractLocalityName(components = []) {
  const priority = [
    'locality',
    'sublocality',
    'sublocality_level_1',
    'sublocality_level_2',
    'neighborhood',
    'administrative_area_level_2',
    'administrative_area_level_1',
  ]
  for (const type of priority) {
    const match = components.find((component) => component.types?.includes(type))
    if (match) {
      return match.long_name || match.short_name
    }
  }
  return null
}

async function computeTravelInsights() {
  const origin = resolveOriginCoordinates()
  const destination = resolveDestinationCoordinates()
  if (!origin || !destination) {
    return null
  }
  try {
    const result = await fetchTravelInsights({
      origin,
      destination,
      mode: 'driving',
    })
    return result
  } catch (error) {
    console.error('Travel insights fetch failed', error)
    return null
  }
}

function formatTravelInsightSummary(insight) {
  if (!insight) {
    return 'Travel stats unavailable.'
  }
  const parts = []
  if (insight.distanceText) {
    parts.push(insight.distanceText)
  }
  if (insight.durationText) {
    parts.push(insight.durationText)
  }
  const warnings = insight.route?.warnings ?? []
  if (warnings.length) {
    parts.push(warnings.slice(0, 1).join(', '))
  }
  return parts.join(' Â· ') || 'Travel stats unavailable.'
}

function resolveOriginCoordinates() {
  if (
    heroForm.originPlace &&
    typeof heroForm.originPlace.lat === 'number' &&
    typeof heroForm.originPlace.lng === 'number'
  ) {
    return {
      lat: heroForm.originPlace.lat,
      lng: heroForm.originPlace.lng,
      label: heroForm.originPlace.label ?? heroForm.origin,
    }
  }
  const fallback = findOriginPresetCoordinate(heroForm.origin || plannerPreferences.value.origin)
  if (fallback) {
    return {
      ...fallback,
      label: heroForm.origin || plannerPreferences.value.origin,
    }
  }
  return null
}

function resolveDestinationCoordinates() {
  if (
    heroForm.destination &&
    typeof heroForm.destination.lat === 'number' &&
    typeof heroForm.destination.lng === 'number'
  ) {
    return {
      lat: heroForm.destination.lat,
      lng: heroForm.destination.lng,
      label: heroForm.destination.description ?? plannerPreferences.value.destination,
    }
  }
  if (
    typeof plannerPreferences.value.destinationLat === 'number' &&
    typeof plannerPreferences.value.destinationLng === 'number'
  ) {
    return {
      lat: plannerPreferences.value.destinationLat,
      lng: plannerPreferences.value.destinationLng,
      label: plannerPreferences.value.destination,
    }
  }
  return null
}

async function ensureCuratedSelections() {
  const selectedThemes = (plannerPreferences.value.travelStyles ?? [])
    .map((theme) => String(theme ?? '').toLowerCase())
    .filter((theme) => Boolean(THEME_BLUEPRINTS[theme]))
  const needsStayOptions = Boolean(plannerPreferences.value.accommodation)
  if (!selectedThemes.length && !needsStayOptions) {
    curatedExperiences.lastSelections = { experiences: [], stays: [] }
    return curatedExperiences.lastSelections
  }
  const contextKey = buildCurationContextKey()
  if (
    curatedExperiences.contextKey !== contextKey ||
    (!curatedExperiences.themeResults.length && !curatedExperiences.stayResults.length)
  ) {
    await loadCuratedSuggestions(selectedThemes, contextKey)
  }
  if (curatedExperiences.error) {
    message.error(curatedExperiences.error)
  }
  if (!curatedExperiences.themeResults.length && !curatedExperiences.stayResults.length) {
    curatedExperiences.lastSelections = { experiences: [], stays: [] }
    return curatedExperiences.lastSelections
  }
  const selection = await presentCurationModal()
  if (!selection) {
    return null
  }
  curatedExperiences.lastSelections = selection
  await persistSelectionsAsPackage(selection)
  return selection
}

function buildCurationContextKey() {
  const destinationKey =
    heroForm.destination?.placeId ||
    heroForm.destination?.description ||
    plannerPreferences.value.destination ||
    heroForm.destinationInput ||
    ''
  const coordsKey =
    typeof heroForm.destination?.lat === 'number' && typeof heroForm.destination?.lng === 'number'
      ? `${heroForm.destination.lat.toFixed(4)},${heroForm.destination.lng.toFixed(4)}`
      : ''
  const stylesKey = JSON.stringify((plannerPreferences.value.travelStyles ?? []).slice().sort())
  const accommodation = plannerPreferences.value.accommodation ?? ''
  const datesKey = `${plannerPreferences.value.startDate || ''}|${plannerPreferences.value.endDate || ''}`
  return [destinationKey, coordsKey, stylesKey, accommodation, datesKey].join('|')
}

async function loadCuratedSuggestions(themeKeys, contextKey) {
  curatedExperiences.loading = true
  curatedExperiences.error = ''
  curatedExperiences.themeResults = []
  curatedExperiences.stayResults = []
  curatedExperiences.selections.experiences = reactive(new Map())
  curatedExperiences.selections.stays = reactive(new Map())
  try {
    let lat = heroForm.destination?.lat ?? plannerPreferences.value.destinationLat ?? null
    let lng = heroForm.destination?.lng ?? plannerPreferences.value.destinationLng ?? null
    let destinationLabel =
      heroForm.destination?.description ?? plannerPreferences.value.destination ?? heroForm.destinationInput ?? ''
    destinationLabel = destinationLabel ? destinationLabel.trim() : ''
    if (!Number.isFinite(lat)) {
      lat = null
    }
    if (!Number.isFinite(lng)) {
      lng = null
    }
    if ((!Number.isFinite(lat) || !Number.isFinite(lng)) && destinationLabel) {
      const knownDest = lookupKnownDestination(destinationLabel)
      if (knownDest) {
        lat = knownDest.lat
        lng = knownDest.lng
        destinationLabel = knownDest.label
      }
    }
    const themeResults = []
    for (const theme of themeKeys) {
      const blueprint = THEME_BLUEPRINTS[theme]
      if (!blueprint) continue
      let section = null
      if (Number.isFinite(lat) && Number.isFinite(lng)) {
        section = await fetchThemeRecommendations(theme, {
          lat,
          lng,
          destination: destinationLabel,
        })
      }
      if (!section || !section.items?.length) {
        section = buildFallbackExperienceSection(theme, destinationLabel)
      }
      if (section && section.items?.length) {
        curatedExperiences.selections.experiences.set(section.theme, reactive(new Map()))
        themeResults.push(section)
      }
    }
    let stayResults = []
      if (Number.isFinite(lat) && Number.isFinite(lng)) {
        stayResults = await fetchAccommodationOptions({
          lat,
          lng,
          destination: destinationLabel,
      })
    }
    if (!stayResults.length) {
      stayResults = buildFallbackStayOptions(
        destinationLabel,
        plannerPreferences.value.accommodation,
        null,
        MAX_ACCOMMODATION_RESULTS,
      )
    }
    curatedExperiences.themeResults = themeResults
    curatedExperiences.stayResults = stayResults
    if (!curatedExperiences.themeResults.length && !curatedExperiences.stayResults.length) {
      throw new Error('Unable to fetch experience suggestions. Please refine your destination and try again.')
    }
    curatedExperiences.contextKey = contextKey
  } catch (error) {
    console.error('Curation load failed', error)
    curatedExperiences.error = error?.message || 'Unable to curate experiences.'
  } finally {
    curatedExperiences.loading = false
  }
}

async function fetchThemeRecommendations(theme, context) {
  const blueprint = THEME_BLUEPRINTS[theme]
  if (!blueprint) {
    return null
  }
  if (blueprint.provider === 'google') {
    return fetchGoogleThemeRecommendations(theme, blueprint, context)
  }
  if (blueprint.provider === 'booking-attractions') {
    const bookingSection = await fetchBookingAttractionRecommendations(theme, blueprint, context)
    if (bookingSection?.items?.length) {
      return bookingSection
    }
    return fetchGoogleThemeRecommendations(theme, { ...blueprint, provider: 'google' }, context)
  }
  if (blueprint.provider === 'booking') {
    const items = await fetchBookingThemeOptions(theme, blueprint, context)
    return {
      theme,
      provider: 'booking',
      label: blueprint.label,
      description: blueprint.description,
      icon: blueprint.icon,
      items,
    }
  }
  return null
}

async function fetchGoogleThemeRecommendations(theme, blueprint, context) {
  const queryDestination = context.destination || plannerPreferences.value.destination || 'Malaysia'
  const response = await searchPlacesByText({
    query: blueprint.query(queryDestination),
    lat: context.lat,
    lng: context.lng,
    radius: blueprint.radius ?? 25000,
    type: blueprint.type,
  })
  const candidates = response?.data?.results ?? response?.results ?? []
  const items = candidates
    .map((place) => normaliseGooglePlace(place, theme, queryDestination))
    .filter(Boolean)
    .slice(0, MAX_THEME_RESULTS)
  return {
    theme,
    provider: 'google',
    label: blueprint.label,
    description: blueprint.description,
    icon: blueprint.icon,
    items,
  }
}

async function fetchBookingThemeOptions(theme, blueprint, context) {
  try {
    const startDate = plannerPreferences.value.startDate
    const endDate = plannerPreferences.value.endDate
    const styleLevel = plannerPreferences.value.accommodation || 'comfort'
    if (!startDate || !endDate) {
      return []
    }
    const adults = Math.max(1, plannerPreferences.value.groupSize || 2)
    const rooms = Math.max(1, Math.ceil(adults / 2))
    const response = await fetchHotelsByCoordinates({
      lat: context.lat,
      lng: context.lng,
      arrivalDate: startDate,
      departureDate: endDate,
      adults,
      rooms,
      currency: 'MYR',
      locale: 'en-gb',
      extra: {
        keyword: blueprint.keyword,
        categories_filter_ids: buildAccommodationFilterFromTheme(theme),
      },
    })
    const candidates = normaliseBookingCandidates(response)
    let hotels = dedupeStayEntries(candidates.map((hotel) => normaliseBookingHotel(hotel, theme)).filter(Boolean))
    if (hotels.length < MAX_ACCOMMODATION_RESULTS) {
      const googleHotels = await fetchGoogleLodgingOptions(
        context,
        MAX_ACCOMMODATION_RESULTS - hotels.length,
        blueprint.keyword || theme,
      )
      hotels = mergeAccommodationLists(hotels, googleHotels, MAX_ACCOMMODATION_RESULTS)
    }
    if (!hotels.length) {
      return buildFallbackStayOptions(
        context.destination || blueprint.label,
        plannerPreferences.value.accommodation,
        theme,
        MAX_ACCOMMODATION_RESULTS,
      )
    }
    const shortlist = await fillMissingGoogleStayPrices(hotels.slice(0, MAX_ACCOMMODATION_RESULTS), {
      lat: context.lat,
      lng: context.lng,
      startDate,
      endDate,
      adults,
      rooms,
      styleLevel,
    })
    return await maybeEnrichBookingStays(shortlist)
  } catch (error) {
    console.error('Booking curation failed', error)
    return []
  }
}

async function fetchBookingAttractionRecommendations(theme, blueprint, context) {
  try {
    const location = await resolveBookingAttractionLocation(context)
    if (!location?.id) {
      return null
    }
    const params = {
      id: location.id,
      currencyCode: 'MYR',
      languageCode: 'en-gb',
      orderBy: blueprint.booking?.orderBy ?? 'trending',
    }
    if (location.ufi) {
      params.ufiFilters = [location.ufi]
    }
    if (Array.isArray(blueprint.booking?.typeFilters)) {
      params.typeFilters = blueprint.booking.typeFilters
    }
    if (Array.isArray(blueprint.booking?.priceFilters)) {
      params.priceFilters = blueprint.booking.priceFilters
    }
    const response = await searchBookingAttractions(params)
    const items = applyBookingAttractionThemeFilters(
      normaliseBookingAttractions(response, {
        theme,
        label: blueprint.label,
        destination: context.destination || plannerPreferences.value.destination || 'Malaysia',
      }),
      blueprint,
    ).slice(0, MAX_THEME_RESULTS)
    if (!items.length) {
      return null
    }
    return {
      theme,
      provider: 'booking',
      label: blueprint.label,
      description: blueprint.description,
      icon: blueprint.icon,
      items,
    }
  } catch (error) {
    console.warn('Booking attractions fetch failed', error)
    return null
  }
}

async function fetchAccommodationOptions(context) {
  let startDate = plannerPreferences.value.startDate
  let endDate = plannerPreferences.value.endDate
  if (!startDate || !endDate) {
    const leadStart = addDays(new Date(), 30)
    startDate = formatDate(leadStart)
    endDate = formatDate(addDays(leadStart, 2))
  }
  const adults = Math.max(1, plannerPreferences.value.groupSize || 2)
  const rooms = Math.max(1, Math.ceil(adults / 2))
  const styleLevel = plannerPreferences.value.accommodation || 'comfort'
  const cacheKey = buildAccommodationCacheKey({
    lat: context.lat,
    lng: context.lng,
    startDate,
    endDate,
    adults,
    rooms,
    style: styleLevel,
  })
  const cached = readAccommodationCache(cacheKey)
  if (cached) {
    return cached
  }
  try {
    const response = await fetchHotelsByCoordinates({
      lat: context.lat,
      lng: context.lng,
      arrivalDate: startDate,
      departureDate: endDate,
      adults,
      rooms,
      currency: 'MYR',
      locale: 'en-gb',
      extra: buildAccommodationFilters(styleLevel),
    })
    const candidates = normaliseBookingCandidates(response)
    let hotels = dedupeStayEntries(candidates.map((hotel) => normaliseBookingHotel(hotel, 'stay')).filter(Boolean))
    if (hotels.length < MAX_ACCOMMODATION_RESULTS) {
      const googleHotels = await fetchGoogleLodgingOptions(context, MAX_ACCOMMODATION_RESULTS - hotels.length)
      hotels = mergeAccommodationLists(hotels, googleHotels, MAX_ACCOMMODATION_RESULTS)
    }
    const shortlist = hotels.length
      ? hotels.slice(0, MAX_ACCOMMODATION_RESULTS)
      : buildFallbackStayOptions(context.destination, styleLevel, null, MAX_ACCOMMODATION_RESULTS)
    const pricedShortlist = await fillMissingGoogleStayPrices(shortlist, {
      lat: context.lat,
      lng: context.lng,
      startDate,
      endDate,
      adults,
      rooms,
      styleLevel,
    })
    const hydrated = await maybeEnrichBookingStays(pricedShortlist)
    memoiseAccommodationResult(cacheKey, hydrated)
    return hydrated
  } catch (error) {
    const fallback = buildFallbackStayOptions(context.destination, styleLevel, null, MAX_ACCOMMODATION_RESULTS)
    if (isRateLimitError(error)) {
      console.warn('Accommodation options fetch throttled. Using curated stays instead.', error?.message || '')
    } else {
      console.error('Accommodation options fetch failed', error)
    }
    memoiseAccommodationResult(cacheKey, fallback)
    return fallback
  }
}

async function fetchGoogleLodgingOptions(context, limit = MAX_ACCOMMODATION_RESULTS, keyword = '') {
  const queryDestination = context.destination || plannerPreferences.value.destination || 'Malaysia'
  const hasCoordinates = Number.isFinite(context.lat) && Number.isFinite(context.lng)
  const query =
    keyword && keyword !== 'stay'
      ? `${keyword} stays in ${queryDestination}, Malaysia`
      : `hotels in ${queryDestination}, Malaysia`
  try {
    const response = await searchPlacesByText({
      query,
      lat: hasCoordinates ? context.lat : undefined,
      lng: hasCoordinates ? context.lng : undefined,
      radius: hasCoordinates ? 45000 : undefined,
      type: 'lodging',
      region: 'MY',
    })
    const candidates = response?.data?.results ?? response?.results ?? []
    return candidates
      .map((place) => normaliseGooglePlace(place, 'stay', queryDestination))
      .filter(Boolean)
      .slice(0, limit)
  } catch (error) {
    console.warn('Google lodging fetch failed', error)
    return []
  }
}

function mergeAccommodationLists(primary = [], secondary = [], limit = MAX_ACCOMMODATION_RESULTS) {
  const merged = []
  const identifiers = new Set()
  const pushUnique = (item) => {
    if (!item) return
    const key = buildStayIdentifier(item)
    if (key && identifiers.has(key)) {
      return
    }
    merged.push(item)
    if (key) {
      identifiers.add(key)
    }
  }
  primary.forEach(pushUnique)
  secondary.forEach(pushUnique)
  return merged.slice(0, limit)
}

function dedupeStayEntries(entries = []) {
  if (!Array.isArray(entries) || !entries.length) {
    return []
  }
  const identifiers = new Set()
  const deduped = []
  entries.forEach((item) => {
    if (!item) {
      return
    }
    const key = buildStayIdentifier(item)
    if (key && identifiers.has(key)) {
      return
    }
    deduped.push(item)
    if (key) {
      identifiers.add(key)
    }
  })
  return deduped
}

function buildStayIdentifier(item) {
  if (!item) {
    return null
  }
  const candidates = [
    item.metadata?.hotelId,
    item.metadata?.placeId,
    item.id,
    item.metadata?.provider === 'google' ? item.metadata?.placeId : null,
    item.metadata?.provider === 'booking' ? item.metadata?.url : null,
    item.title && item.subtitle ? `${item.title}|${item.subtitle}` : null,
    item.title,
  ]
  for (const candidate of candidates) {
    if (typeof candidate === 'string' && candidate.trim()) {
      return candidate.trim().toLowerCase()
    }
  }
  return null
}

async function maybeEnrichBookingStays(list = []) {
  if (!Array.isArray(list) || !list.length) {
    return list
  }
  const needsEnrichment = list.some(
    (item) => item?.metadata?.provider === 'booking' && item?.metadata?.hotelId,
  )
  if (!needsEnrichment) {
    return list
  }
  try {
    return await enrichBookingStaysWithDetails(list)
  } catch (error) {
    console.warn('Booking stay enrichment failed', error)
    return list
  }
}

async function enrichBookingStaysWithDetails(list = []) {
  if (!Array.isArray(list) || !list.length) {
    return list
  }
  const reviewTargets = []
  const photoTargets = []
  const reviewSet = new Set()
  const photoSet = new Set()
  list.forEach((item) => {
    const hotelId = item?.metadata?.hotelId
    if (!hotelId) {
      return
    }
    const missingScore = !hasFiniteNumber(item?.rating) || !item?.metadata?.reviewSummary
    const missingReviewCount = !hasFiniteNumber(item?.reviews) || !hasFiniteNumber(item?.metadata?.reviewCount)
    if ((missingScore || missingReviewCount) && !reviewSet.has(hotelId)) {
      reviewSet.add(hotelId)
      reviewTargets.push(hotelId)
    }
    if ((!item?.photoUrl || !item.photoUrl.trim()) && !photoSet.has(hotelId)) {
      photoSet.add(hotelId)
      photoTargets.push(hotelId)
    }
  })
  const [reviewMap, photoMap] = await Promise.all([
    reviewTargets.length ? fetchBookingHotelReviewScores(reviewTargets) : Promise.resolve(new Map()),
    photoTargets.length ? fetchBookingHotelPhotos(photoTargets, { limit: 1 }) : Promise.resolve(new Map()),
  ])
  return list.map((entry) => {
    if (!entry) {
      return entry
    }
    const next = { ...entry, metadata: { ...(entry.metadata || {}) } }
    const hotelId = next.metadata.hotelId
    if (hotelId) {
      const reviewData = reviewMap.get(hotelId)
      if (reviewData) {
        const score = coerceFiniteNumber(reviewData.score)
        const reviewCount = coerceFiniteNumber(reviewData.count)
        if (!hasFiniteNumber(next.rating) && score !== null) {
          next.rating = Math.round(score * 10) / 10
        }
        if (!hasFiniteNumber(next.metadata.rating) && score !== null) {
          next.metadata.rating = Math.round(score * 10) / 10
        }
        if (!hasFiniteNumber(next.reviews) && reviewCount !== null) {
          next.reviews = Math.max(0, Math.round(reviewCount))
        }
        if (!hasFiniteNumber(next.metadata.reviewCount) && reviewCount !== null) {
          next.metadata.reviewCount = Math.max(0, Math.round(reviewCount))
        }
        if (!next.metadata.reviewSummary && typeof reviewData.summary === 'string' && reviewData.summary.trim()) {
          next.metadata.reviewSummary = reviewData.summary.trim()
        }
      }
      const photos = photoMap.get(hotelId)
      if (photos && photos.length && (!next.photoUrl || !next.photoUrl.trim())) {
        next.photoUrl = photos[0]
      }
    }
    return next
  })
}

function coerceFiniteNumber(value) {
  if (value === '' || value === null || value === undefined) {
    return null
  }
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value
  }
  const numeric = Number(value)
  if (Number.isFinite(numeric)) {
    return numeric
  }
  if (typeof value === 'string') {
    const cleaned = Number(value.replace(/[^0-9.]/g, ''))
    if (Number.isFinite(cleaned)) {
      return cleaned
    }
  }
  return null
}

function hasFiniteNumber(value) {
  return coerceFiniteNumber(value) !== null
}

async function fillMissingGoogleStayPrices(list = [], context = {}) {
  if (!Array.isArray(list) || !list.length) {
    return list
  }
  const targets = list.filter((item) => item?.metadata?.provider === 'google' && !item?.priceText)
  if (!targets.length) {
    return list
  }
  let referenceHotels = []
  if (
    Number.isFinite(context.lat) &&
    Number.isFinite(context.lng) &&
    context.startDate &&
    context.endDate
  ) {
    try {
      const response = await fetchHotelsByCoordinates({
        lat: context.lat,
        lng: context.lng,
        arrivalDate: context.startDate,
        departureDate: context.endDate,
        adults: context.adults,
        rooms: context.rooms,
        currency: 'MYR',
        locale: 'en-gb',
        orderBy: 'distance',
      })
      const candidates = normaliseBookingCandidates(response)
      referenceHotels = dedupeStayEntries(
        candidates.map((hotel) => normaliseBookingHotel(hotel, 'stay')).filter(Boolean),
      )
    } catch (error) {
      console.warn('Booking price reference fetch failed', error)
    }
  }
  const fallbackPriceLabel = buildStylePriceFallback(context.styleLevel)
  return list.map((item) => {
    if (!item || item.metadata?.provider !== 'google' || item.priceText) {
      return item
    }
    const match = referenceHotels.length
      ? findMatchingBookingReferenceForGoogleStay(item, referenceHotels)
      : null
    const next = { ...item, metadata: { ...(item.metadata || {}) } }
    if (match && match.priceText) {
      next.priceText = match.priceText
      next.metadata.price = match.metadata?.price ?? next.metadata.price ?? null
      next.metadata.currency = match.metadata?.currency ?? next.metadata.currency ?? 'MYR'
    } else if (fallbackPriceLabel) {
      next.priceText = fallbackPriceLabel
    }
    return next
  })
}

function buildStylePriceFallback(styleLevel) {
  const key = typeof styleLevel === 'string' ? styleLevel.toLowerCase() : 'comfort'
  if (STYLE_PRICE_HINTS[key]) {
    return STYLE_PRICE_HINTS[key]
  }
  return STYLE_PRICE_HINTS.comfort
}

function findMatchingBookingReferenceForGoogleStay(googleStay, bookingCandidates = []) {
  if (!googleStay) {
    return null
  }
  const googleName = normaliseStayNameKey(googleStay.title)
  const googleCity = normaliseStayNameKey(googleStay.metadata?.city)
  const googleLat = Number(googleStay.metadata?.lat)
  const googleLng = Number(googleStay.metadata?.lng)
  let bestMatch = null
  let bestScore = 0
  bookingCandidates.forEach((candidate) => {
    if (!candidate) {
      return
    }
    const candidateName = normaliseStayNameKey(candidate.title)
    if (!candidateName) {
      return
    }
    let score = 0
    if (googleName && candidateName) {
      if (googleName === candidateName) {
        score += 0.7
      } else if (googleName.includes(candidateName) || candidateName.includes(googleName)) {
        score += 0.5
      }
    }
    const candidateCity = normaliseStayNameKey(candidate.metadata?.city)
    if (googleCity && candidateCity && googleCity === candidateCity) {
      score += 0.2
    }
    const distanceKm = computeDistanceKm(
      googleLat,
      googleLng,
      Number(candidate.metadata?.lat),
      Number(candidate.metadata?.lng),
    )
    if (distanceKm !== null) {
      if (distanceKm <= 0.5) {
        score += 0.5
      } else if (distanceKm <= 1.5) {
        score += 0.3
      } else if (distanceKm <= 3) {
        score += 0.1
      }
    }
    if (score > bestScore && score >= 0.6) {
      bestScore = score
      bestMatch = candidate
    }
  })
  return bestMatch
}

function normaliseStayNameKey(value) {
  if (!value) {
    return ''
  }
  return String(value)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

function computeDistanceKm(lat1, lng1, lat2, lng2) {
  if (
    !Number.isFinite(lat1) ||
    !Number.isFinite(lng1) ||
    !Number.isFinite(lat2) ||
    !Number.isFinite(lng2)
  ) {
    return null
  }
  const toRad = (deg) => (deg * Math.PI) / 180
  const R = 6371
  const dLat = toRad(lat2 - lat1)
  const dLng = toRad(lng2 - lng1)
  const a =
    Math.sin(dLat / 2) * Math.sin(dLat / 2) +
    Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) * Math.sin(dLng / 2)
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a))
  return R * c
}

function resolveFirstPhotoFromList(collection) {
  if (!Array.isArray(collection) || !collection.length) {
    return null
  }
  for (const entry of collection) {
    if (typeof entry === 'string' && entry.trim()) {
      return entry.trim()
    }
    if (entry && typeof entry === 'object') {
      const candidate = pickFirstString(
        entry.url_original,
        entry.url,
        entry.url_max,
        entry.url_max300,
        entry.url_1440,
        entry.url_square60,
        entry.photo_url,
        entry.image_url,
      )
      if (candidate) {
        return candidate
      }
    }
  }
  return null
}

function pickFirstString(...values) {
  for (const value of values) {
    if (typeof value === 'string') {
      const trimmed = value.trim()
      if (trimmed) {
        return trimmed
      }
    }
  }
  return null
}

async function resolveBookingAttractionLocation(context) {
  const destinationLabel = context.destination || plannerPreferences.value.destination || ''
  try {
    const payload = await searchBookingAttractionLocations({
      query: destinationLabel || undefined,
      latitude: Number.isFinite(context.lat) ? context.lat : undefined,
      longitude: Number.isFinite(context.lng) ? context.lng : undefined,
    })
    const locations = normaliseBookingAttractionLocations(payload)
    if (!locations.length) {
      return null
    }
    if (destinationLabel) {
      const exact = locations.find((entry) =>
        entry.name?.toLowerCase().includes(destinationLabel.toLowerCase()),
      )
      if (exact) {
        return exact
      }
    }
    return locations[0]
  } catch (error) {
    console.warn('Booking attraction location lookup failed', error)
    return null
  }
}

function normaliseBookingAttractionLocations(payload) {
  if (Array.isArray(payload?.data?.destinations) && payload.data.destinations.length) {
    return payload.data.destinations
      .map((destination) => ({
        id: destination.id ?? null,
        name: destination.cityName ?? destination.name ?? '',
        lat: destination.latitude ?? null,
        lng: destination.longitude ?? null,
        ufi: destination.ufi ?? null,
      }))
      .filter((entry) => entry && entry.id)
  }
  const containers = [
    payload?.data?.data,
    payload?.data?.locations,
    payload?.data,
    payload?.result,
    payload?.locations,
    payload,
  ]
  for (const container of containers) {
    if (Array.isArray(container)) {
      return container
        .map((entry) => {
          if (!entry || typeof entry !== 'object') {
            return null
          }
          const lat = coerceFiniteNumber(entry.latitude ?? entry.lat)
          const lng = coerceFiniteNumber(entry.longitude ?? entry.lng)
          return {
            id: entry.id ?? entry.location_id ?? entry.uuid ?? null,
            name: entry.name ?? entry.city ?? entry.title ?? '',
            lat: lat !== null ? lat : null,
            lng: lng !== null ? lng : null,
            ufi: entry.ufi ?? entry.location_ufi ?? null,
          }
        })
        .filter((entry) => entry && entry.id)
    }
    if (container && typeof container === 'object') {
      const values = Object.values(container)
      if (values.some((value) => Array.isArray(value))) {
        return values
          .filter(Array.isArray)
          .flat()
          .map((entry) => {
            if (!entry || typeof entry !== 'object') {
              return null
            }
            const lat = coerceFiniteNumber(entry.latitude ?? entry.lat)
            const lng = coerceFiniteNumber(entry.longitude ?? entry.lng)
            return {
              id: entry.id ?? entry.location_id ?? entry.uuid ?? null,
              name: entry.name ?? entry.city ?? entry.title ?? '',
              lat: lat !== null ? lat : null,
              lng: lng !== null ? lng : null,
              ufi: entry.ufi ?? entry.location_ufi ?? null,
            }
          })
          .filter((entry) => entry && entry.id)
      }
    }
  }
  return []
}

function normaliseBookingAttractions(payload, meta) {
  const list = extractBookingAttractionList(payload)
  return list
    .map((attraction) => normaliseBookingAttraction(attraction, meta))
    .filter(Boolean)
}

function extractBookingAttractionList(payload) {
  const containers = [
    payload?.data?.data,
    payload?.data?.results,
    payload?.data?.items,
    payload?.data?.attractions,
    payload?.result,
    payload?.items,
    payload?.attractions,
    payload?.data,
    payload,
  ]
  for (const container of containers) {
    if (Array.isArray(container)) {
      return container
    }
    if (container && typeof container === 'object') {
      const values = Object.values(container).filter((value) => Array.isArray(value))
      if (values.length) {
        return values[0]
      }
    }
  }
  return []
}

function normaliseBookingAttraction(attraction, meta = {}) {
  if (!attraction || typeof attraction !== 'object') {
    return null
  }
  const title =
    attraction.name ?? attraction.title ?? attraction.attraction_name ?? attraction.short_title ?? null
  if (!title) {
    return null
  }
  const addressParts = [
    attraction.address ?? attraction.full_address ?? null,
    attraction.city ?? attraction.city_name ?? null,
    attraction.country ?? attraction.country_name ?? null,
  ]
    .filter((part) => typeof part === 'string' && part.trim())
  const subtitle =
    addressParts.join(', ') || attraction.short_description || meta.destination || 'Malaysia'
  const ratingCandidate =
    attraction.review_score ??
    attraction.rating ??
    attraction.review_score_avg ??
    attraction.attr_score ??
    attraction.reviewsStats?.combinedNumericStats?.average ??
    null
  const rating =
    ratingCandidate != null && Number.isFinite(Number(ratingCandidate))
      ? Math.round(Number(ratingCandidate) * 10) / 10
      : null
  const reviewsCandidate =
    attraction.review_count ??
    attraction.review_nr ??
    attraction.number_of_reviews ??
    attraction.reviewsStats?.combinedNumericStats?.total ??
    attraction.reviewsStats?.allReviewsCount ??
    null
  const reviews =
    reviewsCandidate != null && Number.isFinite(Number(reviewsCandidate))
      ? Math.max(0, Math.round(Number(reviewsCandidate)))
      : null
  const priceInfo = resolveAttractionPriceInfo(attraction)
  const imageFromArray =
    Array.isArray(attraction.images) && attraction.images.length
      ? attraction.images
          .map((img) => {
            if (typeof img === 'string') return img
            if (img && typeof img === 'object') {
              return img.url ?? img.image_url ?? img.href ?? img.src ?? null
            }
            return null
          })
          .find((entry) => typeof entry === 'string' && entry.trim())
      : null
  const photoUrl =
    pickFirstString(
      attraction.main_photo_url,
      attraction.photo_url,
      attraction.image_url,
      attraction.cover_image,
      attraction.primaryPhoto?.small,
      attraction.primaryPhoto?.medium,
      attraction.primaryPhoto?.large,
      imageFromArray,
    ) || ''
  const attractionId = attraction.id ?? attraction.attraction_id ?? attraction.product_id ?? null
  return {
    id: `booking-attraction-${attractionId || createLocalId()}`,
    provider: 'booking',
    title,
    subtitle,
    rating,
    reviews,
    priceText: priceInfo.text,
    photoUrl,
    tags: [meta.label || 'Experience'],
    metadata: {
      provider: 'booking',
      theme: meta.theme,
      attractionId,
      price: priceInfo.amount,
      currency: priceInfo.currency,
      rating,
      reviewCount: reviews,
      address: subtitle,
      taxonomySlug: attraction.taxonomySlug ?? null,
      description: attraction.shortDescription ?? attraction.short_description ?? '',
    },
    raw: attraction,
  }
}

function resolveAttractionPriceInfo(attraction) {
  const amountCandidates = [
    attraction.price_from,
    attraction.price?.from,
    attraction.starting_price,
    attraction.lowest_price,
    attraction.price_from_value,
    attraction.price?.amount,
    attraction.representativePrice?.chargeAmount,
    attraction.representativePrice?.publicAmount,
  ]
  let amount = null
  for (const candidate of amountCandidates) {
    const numeric = coerceFiniteNumber(candidate)
    if (numeric !== null) {
      amount = numeric
      break
    }
  }
  const currency =
    attraction.price_currency ??
    attraction.currency_code ??
    attraction.price?.currency ??
    attraction.representativePrice?.currency ??
    'MYR'
  let text =
    attraction.price_display ??
    attraction.price_text ??
    (amount !== null ? formatCurrencyDisplay(amount, currency) : null)
  if (text && amount !== null && !text.toLowerCase().includes('ticket')) {
    text = `${formatCurrencyDisplay(amount, currency)} / ticket`
  }
  return {
    amount,
    currency,
    text,
  }
}

function applyBookingAttractionThemeFilters(items = [], blueprint) {
  if (!Array.isArray(items) || !items.length) {
    return []
  }
  let filtered = [...items]
  const taxonomySlugs = blueprint?.booking?.taxonomySlugs
  if (Array.isArray(taxonomySlugs) && taxonomySlugs.length) {
    filtered = filtered.filter((item) => {
      const slug = (item.metadata?.taxonomySlug || item.raw?.taxonomySlug || '').trim()
      return slug && taxonomySlugs.includes(slug)
    })
  }
  const keywordIncludes = blueprint?.booking?.keywordIncludes
  if (Array.isArray(keywordIncludes) && keywordIncludes.length) {
    const keywords = keywordIncludes.map((keyword) => keyword.toLowerCase())
    const matchKeywords = (value) => {
      if (!value) return false
      const lower = value.toLowerCase()
      return keywords.some((keyword) => lower.includes(keyword))
    }
    const keywordMatches = filtered.filter(
      (item) =>
        matchKeywords(item.title) ||
        matchKeywords(item.subtitle) ||
        matchKeywords(item.metadata?.description),
    )
    if (keywordMatches.length) {
      filtered = keywordMatches
    }
  }
  return filtered.length ? filtered : items
}

function buildAccommodationFilterFromTheme(theme) {
  if (!BOOKING_THEME_KEYS.has(theme)) {
    return undefined
  }
  switch (theme) {
    case 'nature':
      return 'theme_id::nature'
    case 'adventure':
      return 'theme_id::adventure'
    case 'city':
      return 'theme_id::city_trip'
    case 'historical':
      return 'theme_id::heritage'
    default:
      return undefined
  }
}

function buildAccommodationFilters(level = 'comfort') {
  switch (level) {
    case 'luxury':
      return { categories_filter_ids: 'class::5' }
    case 'premium':
      return { categories_filter_ids: 'class::4' }
    default:
      return { categories_filter_ids: 'class::3,class::2' }
  }
}

function buildAccommodationCacheKey({ lat, lng, startDate, endDate, adults, rooms, style }) {
  const parts = [
    Number.isFinite(lat) ? Number(lat).toFixed(3) : 'na',
    Number.isFinite(lng) ? Number(lng).toFixed(3) : 'na',
    style || 'comfort',
    startDate || '',
    endDate || '',
    adults,
    rooms,
  ]
  return parts.join('|')
}

function memoiseAccommodationResult(key, value) {
  accommodationCache.set(key, value)
  if (accommodationCache.size > MAX_ACCOMMODATION_CACHE) {
    const oldestKey = accommodationCache.keys().next().value
    if (oldestKey) {
      accommodationCache.delete(oldestKey)
    }
  }
}

function readAccommodationCache(key) {
  return accommodationCache.get(key)
}

function isRateLimitError(error) {
  if (!error) return false
  if (error.status === 429) return true
  const message = typeof error.message === 'string' ? error.message : ''
  return /429/.test(message) || /Too Many Requests/i.test(message)
}

function buildSyntheticStaysForDestination(destinationLabel, style, count, themeFilter, existing = []) {
  if (!count || count <= 0) {
    return []
  }
  const safeDestination = destinationLabel || 'Malaysia'
  const pool = SYNTHETIC_STAY_TEMPLATES[style] || SYNTHETIC_STAY_TEMPLATES.comfort
  const filteredPool =
    themeFilter && themeFilter !== 'stay'
      ? pool.filter((template) => !template.themes || template.themes.includes(themeFilter))
      : pool
  const templatePool = filteredPool.length ? filteredPool : pool
  const existingTitles = new Set(existing.map((stay) => stay.title?.toLowerCase?.() ?? ''))
  const placeholders = []

  let templateIndex = 0
  let variantIndex = 0

  const buildTitle = (templateTitle, attempt) => {
    const base =
      templateTitle.indexOf('{city}') >= 0
        ? templateTitle.replace('{city}', safeDestination)
        : `${safeDestination} ${templateTitle}`
    if (!existingTitles.has(base.toLowerCase()) && attempt === 0) {
      return base
    }
    const adjective = SYNTHETIC_NAME_ADJECTIVES[attempt % SYNTHETIC_NAME_ADJECTIVES.length]
    return `${safeDestination} ${adjective} ${templateTitle.replace('{city}', '').trim()}`.replace(/\s+/g, ' ').trim()
  }

  while (placeholders.length < count && templateIndex < templatePool.length + SYNTHETIC_NAME_ADJECTIVES.length) {
    const template = templatePool[templateIndex % templatePool.length]
    const title = buildTitle(template.title, variantIndex)
    const lower = title.toLowerCase()
    if (!existingTitles.has(lower)) {
      existingTitles.add(lower)
      placeholders.push({
        title,
        city: safeDestination,
        price: template.price,
        label: template.label,
        styles: template.styles.slice(),
        themes: template.themes.slice(),
        photoUrl: template.photoUrl,
      })
      templateIndex += 1
      variantIndex = 0
    } else {
      variantIndex += 1
      if (variantIndex >= SYNTHETIC_NAME_ADJECTIVES.length) {
        templateIndex += 1
        variantIndex = 0
      }
    }
  }

  return placeholders
}

function dedupeStays(stays = []) {
  const seen = new Set()
  return stays.filter((stay) => {
    const key = `${(stay.title || '').toLowerCase()}|${(stay.subtitle || '').toLowerCase()}`
    if (seen.has(key)) {
      return false
    }
    seen.add(key)
    return true
  })
}

function buildFallbackExperienceSection(theme, destinationLabel) {
  const matcher = createDestinationMatcher(destinationLabel)
  const themedActivities = curatedMalaysiaActivities
    .flatMap((entry) =>
      entry.activities.map((activity) => ({
        ...activity,
        sourceDestination: entry.destination,
      })),
    )
    .filter((activity) => activity.tags?.includes(theme))
  if (!themedActivities.length) {
    return null
  }
  const label = THEME_BLUEPRINTS[theme]?.label ?? `Suggested ${theme}`
  const trimmedLabel = (destinationLabel || '').trim()
  const destinationScoped =
    trimmedLabel.length > 0
      ? themedActivities.filter((activity) => matcher.match(activity.sourceDestination))
      : themedActivities
  const pool = trimmedLabel.length > 0 && destinationScoped.length ? destinationScoped : themedActivities
  return {
    theme,
    provider: 'fallback',
    label,
    description: `Handpicked picks inspired by ${destinationLabel || 'Malaysia'}.`,
    icon: THEME_BLUEPRINTS[theme]?.icon ?? 'ri-compass-3-line',
    items: pool.slice(0, 5).map((activity, index) => {
      const photoSeed = [activity.title, activity.sourceDestination || destinationLabel, theme]
        .filter(Boolean)
        .join(' ')
      return {
        id: `fallback-${theme}-${activity.sourceDestination}-${index}`,
        provider: 'fallback',
        title: activity.title,
        subtitle: activity.sourceDestination,
        rating: null,
        reviews: null,
        priceText: activity.estimatedCost ? `RM ${activity.estimatedCost}` : null,
        photoUrl: activity.photoUrl || `https://source.unsplash.com/600x400/?${encodeURIComponent(photoSeed)}`,
        tags: [activity.sourceDestination],
        metadata: {
          theme,
          provider: 'fallback',
          address: activity.sourceDestination,
          notes: activity.notes ?? '',
        },
      }
    }),
  }
}

function buildFallbackStayOptions(
  destinationLabel,
  preferredStyle = 'comfort',
  themeFilter = null,
  minCount = MAX_ACCOMMODATION_RESULTS,
) {
  const label = (destinationLabel || '').trim()
  const style = (preferredStyle || 'comfort').toLowerCase()
  const matcher = createDestinationMatcher(label)
  const baseMatches = FALLBACK_STAYS.filter((stay) => {
    const styleOk = !stay.styles || stay.styles.includes(style)
    const themeOk = !themeFilter || !stay.themes || stay.themes.includes(themeFilter)
    return styleOk && themeOk
  })
  const destinationScoped =
    label && label.toLowerCase() !== 'malaysia'
      ? baseMatches.filter((stay) => matcher.match(stay.city ?? stay.label ?? ''))
      : baseMatches
  let source = destinationScoped.slice()

  if (!source.length && label) {
    source = buildSyntheticStaysForDestination(label, style, minCount, themeFilter)
  }

  if (label && source.length > 0 && source.length < minCount) {
    const synthetic = buildSyntheticStaysForDestination(label, style, minCount - source.length, themeFilter, source)
    source = source.concat(synthetic)
  }

  if (!label && source.length < minCount) {
    const pool = baseMatches.length ? baseMatches : FALLBACK_STAYS
    const extras = pool.filter((stay) => !source.includes(stay)).slice(0, minCount - source.length)
    source = source.concat(extras.length ? extras : FALLBACK_STAYS.slice(0, minCount - source.length))
  }

  if (!source.length) {
    source = FALLBACK_STAYS.slice(0, minCount)
  }

  source = dedupeStays(source).slice(0, Math.max(minCount, source.length))

  return source.map((stay, idx) => ({
    id: `fallback-stay-${idx}`,
    provider: 'fallback',
    title: stay.title,
    subtitle: `${stay.city || label || 'Malaysia'} - ${stay.label}`,
    rating: null,
    reviews: null,
    priceText: stay.price ? `RM ${stay.price} / night` : null,
    photoUrl:
      stay.photoUrl ||
      `https://source.unsplash.com/600x400/?hotel ${encodeURIComponent(stay.city || label || 'malaysia stay')}`,
    tags: [stay.label],
    metadata: {
      theme: 'stay',
      provider: 'fallback',
      address: stay.city || label,
      city: stay.city || label || '',
      country: stay.country || 'Malaysia',
      price: stay.price,
      styles: stay.styles ?? [],
      themes: stay.themes ?? [],
      destination: stay.city || label,
    },
  }))
}
function normaliseGooglePlace(place, theme, destinationLabel = '') {
  if (!place) return null
  const photoRef = place.photos?.[0]?.photo_reference
  const priceRangeInfo = normaliseGooglePriceRange(place.price_range ?? place.priceRange ?? null)
  const priceText =
    place.price_text || place.priceText || priceRangeInfo?.text || formatPriceLevel(place.price_level ?? null)
  const seedParts = [destinationLabel, place.name, theme].filter(Boolean)
  const fallbackPhoto = seedParts.length
    ? `https://source.unsplash.com/600x400/?${encodeURIComponent(seedParts.join(' '))}`
    : ''
  const photoUrl = photoRef ? buildPlacePhotoUrl(photoRef, { maxWidth: 480 }) : fallbackPhoto
  return {
    id: `google-${place.place_id}`,
    provider: 'google',
    title: place.name ?? 'Experience',
    subtitle: place.formatted_address ?? place.vicinity ?? '',
    rating: place.rating ?? null,
    reviews: place.user_ratings_total ?? null,
    priceText,
    photoUrl,
    tags: [THEME_BLUEPRINTS[theme]?.label ?? theme],
    metadata: {
      lat: place.geometry?.location?.lat ?? null,
      lng: place.geometry?.location?.lng ?? null,
      address: place.formatted_address ?? place.vicinity ?? '',
      city: deriveCityFromAddress(place.short_address ?? place.vicinity ?? place.formatted_address ?? '', destinationLabel),
      theme,
      provider: 'google',
      placeId: place.place_id,
      openingHours: place.opening_hours?.weekday_text ?? [],
      openNow: place.opening_hours?.open_now ?? null,
      priceRange: priceRangeInfo,
      reviewCount: place.user_ratings_total ?? null,
      rating: place.rating ?? null,
    },
  }
}

function normaliseGooglePriceRange(range) {
  if (!range || typeof range !== 'object') {
    return null
  }
  const extractCandidate = (...keys) => {
    for (const key of keys) {
      if (range[key] != null) {
        return range[key]
      }
    }
    return null
  }
  const rawCurrency =
    extractCandidate('currency', 'currency_code', 'currencyCode') ||
    (range.start && range.start.currency) ||
    (range.end && range.end.currency) ||
    (range.startPrice && range.startPrice.currency) ||
    (range.endPrice && range.endPrice.currency) ||
    'MYR'
  const currency = typeof rawCurrency === 'string' && rawCurrency.trim() ? rawCurrency.trim().toUpperCase() : 'MYR'

  const parseValue = (value) => {
    if (value == null) return null
    if (typeof value === 'number' && Number.isFinite(value)) return value
    if (typeof value === 'string') {
      const parsed = parseFloat(value.replace(/[^0-9.]/g, ''))
      return Number.isFinite(parsed) ? parsed : null
    }
    if (typeof value === 'object') {
      if (value.value != null) return parseValue(value.value)
      if (value.units != null || value.nanos != null) {
        const units = Number(value.units ?? 0)
        const nanos = Number(value.nanos ?? 0) / 1_000_000_000
        const total = units + nanos
        return Number.isFinite(total) ? total : null
      }
    }
    return null
  }

  const startValue = parseValue(
    extractCandidate('start', 'start_value', 'startPrice', 'min', 'min_price', 'minPrice', 'minimum'),
  )
  const endValue = parseValue(extractCandidate('end', 'end_value', 'endPrice', 'max', 'max_price', 'maxPrice', 'maximum'))

  const textCandidates = [
    extractCandidate('text', 'display', 'price_text', 'priceText', 'price'),
    Array.isArray(range.textValues) ? range.textValues.join(' - ') : null,
    range.label,
  ].filter((candidate) => typeof candidate === 'string' && candidate.trim())

  let text = textCandidates.length ? textCandidates[0].trim() : null
  if (!text) {
    if (startValue != null && endValue != null) {
      text = `${formatCurrencyDisplay(startValue, currency)} - ${formatCurrencyDisplay(endValue, currency)} / night`
    } else if (startValue != null) {
      text = `${formatCurrencyDisplay(startValue, currency)} / night`
    } else if (endValue != null) {
      text = `${formatCurrencyDisplay(endValue, currency)} / night`
    }
  }

  return {
    text: text || null,
    start: startValue,
    end: endValue,
    currency,
  }
}

function deriveCityFromAddress(address, fallback = '') {
  if (!address) {
    return fallback || ''
  }
  const parts = String(address)
    .split(',')
    .map((part) => part.trim())
    .filter(Boolean)
  if (!parts.length) {
    return fallback || ''
  }
  if (parts.length === 1) {
    return parts[0]
  }
  return parts[parts.length - 2] || parts[0] || fallback || ''
}

function parsePriceCandidate(value) {
  if (value == null) return null
  if (typeof value === 'number' && Number.isFinite(value)) return value
  if (typeof value === 'string') {
    const cleaned = value.replace(/[^0-9.,]/g, '').replace(/,/g, '')
    const parsed = parseFloat(cleaned)
    return Number.isFinite(parsed) ? parsed : null
  }
  if (typeof value === 'object') {
    if (value.value != null) return parsePriceCandidate(value.value)
    if (value.amount != null) return parsePriceCandidate(value.amount)
    if (value.price != null) return parsePriceCandidate(value.price)
    if (value.gross_amount != null) return parsePriceCandidate(value.gross_amount)
    if (value.grossPrice != null) return parsePriceCandidate(value.grossPrice)
  }
  return null
}

function formatCurrencyDisplay(amount, currency = 'MYR') {
  if (amount == null) return null
  const cur = (currency || 'MYR').toUpperCase()
  try {
    return new Intl.NumberFormat('en-MY', {
      style: 'currency',
      currency: cur,
      maximumFractionDigits: 0,
    }).format(Number(amount))
  } catch (error) {
    return `${cur} ${Number(amount).toFixed(0)}`
  }
}

function resolveBookingPriceInfo(hotel, fallbackPrice, fallbackCurrency = 'MYR') {
  const formattedCandidates = [
    hotel.price_display,
    hotel.display_price,
    hotel.price_string,
    hotel.formatted_price,
    hotel.price_formatted,
    hotel.price_display_value,
    hotel.min_total_price_text,
    hotel.priceBreakdown?.currency_price_display,
    hotel.price_breakdown?.currency_price_display,
    hotel.composite_price_breakdown?.all_inclusive_amount_per_stay?.currency_string,
    hotel.composite_price_breakdown?.all_inclusive_amount?.currency_string,
  ]
  const rawFormatted = formattedCandidates.find((value) => typeof value === 'string' && value.trim())

  const numericCandidates = [
    fallbackPrice,
    hotel.min_total_price,
    hotel.min_price,
    hotel.min_rate,
    hotel.price,
    hotel.price?.value,
    hotel.price?.amount,
    hotel.price_breakdown?.gross_price,
    hotel.price_breakdown?.gross_price?.value,
    hotel.price_breakdown?.total,
    hotel.price_breakdown?.all_inclusive_amount?.value,
    hotel.priceBreakdown?.grossPrice?.value,
    hotel.priceBreakdown?.all_inclusive_amount?.value,
    hotel.composite_price_breakdown?.gross_amount_per_night?.value,
    hotel.composite_price_breakdown?.gross_amount?.value,
    hotel.composite_price_breakdown?.all_inclusive_amount?.value,
    hotel.offers &&
      Array.isArray(hotel.offers) &&
      hotel.offers[0] &&
      (hotel.offers[0].price?.value ?? hotel.offers[0].price),
  ]

  let priceValue = null
  for (const candidate of numericCandidates) {
    const parsed = parsePriceCandidate(candidate)
    if (parsed != null) {
      priceValue = parsed
      break
    }
  }

  const currencyCandidates = [
    hotel.currency,
    hotel.currencycode,
    hotel.price?.currency,
    hotel.price_breakdown?.currency,
    hotel.priceBreakdown?.grossPrice?.currency,
    hotel.priceBreakdown?.grossPrice?.currency_code,
    hotel.composite_price_breakdown?.gross_amount?.currency,
    hotel.composite_price_breakdown?.all_inclusive_amount?.currency,
    hotel.offers &&
      Array.isArray(hotel.offers) &&
      hotel.offers[0] &&
      (hotel.offers[0].currency || hotel.offers[0].price?.currency),
  ]
  let currency = fallbackCurrency
  for (const candidate of currencyCandidates) {
    if (typeof candidate === 'string' && candidate.trim()) {
      currency = candidate.trim().toUpperCase()
      break
    }
  }

  const priceText = rawFormatted || formatCurrencyDisplay(priceValue, currency)
  return { priceValue, currency, priceText }
}

function normaliseBookingHotel(hotel, theme) {
  if (!hotel) return null
  const property = hotel.property && typeof hotel.property === 'object' ? hotel.property : {}
  const canonicalHotelId =
    hotel.hotel_id ?? hotel.id ?? hotel.property_id ?? hotel.propertyId ?? hotel.hotelId ?? hotel.uuid ?? null
  const normalizedHotelId = canonicalHotelId != null ? String(canonicalHotelId) : null
  const fallbackPrice =
    hotel.min_total_price ??
    hotel.priceBreakdown?.grossPrice?.value ??
    hotel.composite_price_breakdown?.gross_amount_per_night?.value ??
    hotel.composite_price_breakdown?.gross_amount?.value ??
    null
  const fallbackCurrency =
    hotel.priceBreakdown?.grossPrice?.currency ||
    hotel.composite_price_breakdown?.gross_amount?.currency ||
    hotel.currencycode ||
    hotel.currency ||
    'MYR'
  const lat = hotel.latitude ?? hotel.lat ?? null
  const lng = hotel.longitude ?? hotel.lng ?? null
  const title = hotel.hotel_name || hotel.name || hotel.property_name || null
  if (!title) {
    return null
  }
  const addressParts = [
    hotel.address ?? hotel.address_trans ?? null,
    hotel.city ?? hotel.city_name ?? hotel.district ?? null,
    hotel.country ?? hotel.country_trans ?? hotel.region ?? null,
  ]
    .filter((part) => typeof part === 'string' && part.trim().length)
    .map((part) => part.trim())
  const subtitle = addressParts.join(', ')
  const priceInfo = resolveBookingPriceInfo(hotel, fallbackPrice, fallbackCurrency)
  const ratingCandidate =
    hotel.review_score ??
    hotel.reviewScore ??
    hotel.review_score_avg ??
    hotel.rating ??
    hotel.reviewScoreWord ??
    property.reviewScore ??
    property.rating ??
    null
  const ratingNumber = ratingCandidate != null ? Number(ratingCandidate) : null
  const rating = Number.isFinite(ratingNumber) ? Math.round(ratingNumber * 10) / 10 : null
  const reviewCountCandidate =
    hotel.review_nr ??
    hotel.reviewCount ??
    hotel.review_number ??
    hotel.number_of_reviews ??
    hotel.property_review_count ??
    property.review_nr ??
    property.reviewCount ??
    property.review_count ??
    null
  const reviewCountNumber = reviewCountCandidate != null ? Number(reviewCountCandidate) : null
  const reviewCount = Number.isFinite(reviewCountNumber) ? Math.max(0, Math.round(reviewCountNumber)) : null
  const reviewSummary = hotel.review_score_word ?? hotel.reviewScoreWord ?? property.reviewScoreWord ?? null
  const photoUrl =
    pickFirstString(
      hotel.max_photo_url,
      hotel.main_photo_url,
      hotel.photoMainUrl,
      hotel.photo_url,
      hotel.image_url,
      hotel.image?.url,
      resolveFirstPhotoFromList(hotel.photoUrls),
      resolveFirstPhotoFromList(hotel.photos),
    ) || ''
  return {
    id: `booking-${normalizedHotelId || createLocalId()}`,
    provider: 'booking',
    title,
    subtitle: subtitle || hotel.city || 'Malaysia',
    rating,
    reviews: reviewCount,
    priceText: priceInfo.priceText,
    photoUrl,
    tags: [THEME_BLUEPRINTS[theme]?.label ?? 'Stay'],
    metadata: {
      lat,
      lng,
      address: subtitle || hotel.address || hotel.city || '',
      city: hotel.city_name || hotel.city || hotel.district || '',
      country: hotel.country || hotel.country_trans || hotel.region || '',
      theme,
      provider: 'booking',
      currency: priceInfo.currency,
      price: priceInfo.priceValue,
      hotelId: normalizedHotelId,
      url: hotel.url ?? '',
      reviewCount,
      reviewSummary,
      rating,
    },
  }
}

function normaliseBookingCandidates(response) {
  const sources = [response?.data?.result, response?.data?.data, response?.data]
  for (const source of sources) {
    if (Array.isArray(source)) {
      return source
    }
    if (source && typeof source === 'object') {
      const values = Object.values(source).filter((item) => item && typeof item === 'object')
      if (values.length) {
        return values
      }
    }
  }
  return []
}

function formatPriceLevel(level) {
  if (typeof level !== 'number') {
    return null
  }
  switch (level) {
    case 0:
      return 'RM 0 (Free)'
    case 1:
      return 'RM 160+ / night'
    case 2:
      return 'RM 320+ / night'
    case 3:
      return 'RM 520+ / night'
    case 4:
      return 'RM 820+ / night'
    default:
      return null
  }
}

function presentCurationModal() {
  curatedExperiences.visible = true
  return new Promise((resolve) => {
    curatedExperiences.resolver = resolve
  })
}

function resolveCurationModal(payload) {
  if (typeof curatedExperiences.resolver === 'function') {
    curatedExperiences.resolver(payload)
  }
  curatedExperiences.resolver = null
}

function confirmCurationSelections() {
  const payload = serialiseCurationSelections()
  curatedExperiences.visible = false
  resolveCurationModal(payload)
}

function cancelCurationSelections() {
  curatedExperiences.visible = false
  resolveCurationModal(null)
}

function autoFillCurationSelections() {
  curatedExperiences.themeResults.forEach((section) => {
    const bucket = curatedExperiences.selections.experiences.get(section.theme) ?? reactive(new Map())
    curatedExperiences.selections.experiences.set(section.theme, bucket)
    if (!bucket.size && section.items?.[0]) {
      bucket.set(section.items[0].id, section.items[0])
    }
  })
  if (!curatedExperiences.selections.stays.size && curatedExperiences.stayResults?.[0]) {
    curatedExperiences.selections.stays.set(curatedExperiences.stayResults[0].id, curatedExperiences.stayResults[0])
  }
}

function toggleExperienceSelection(theme, item) {
  if (!theme || !item) return
  const bucket = curatedExperiences.selections.experiences.get(theme) ?? reactive(new Map())
  curatedExperiences.selections.experiences.set(theme, bucket)
  if (bucket.has(item.id)) {
    bucket.delete(item.id)
  } else {
    bucket.set(item.id, item)
  }
}

function toggleStaySelection(item) {
  if (!item) return
  const bucket = curatedExperiences.selections.stays
  if (bucket.has(item.id)) {
    bucket.delete(item.id)
  } else {
    bucket.set(item.id, item)
  }
}

function serialiseCurationSelections() {
  const experiences = curatedExperiences.themeResults
    .map((section) => {
      const bucket = curatedExperiences.selections.experiences.get(section.theme)
      if (!bucket || !bucket.size) {
        return null
      }
      return {
        theme: section.theme,
        label: section.label,
        picks: Array.from(bucket.values()).map((item) => ({
          id: item.id,
          title: item.title,
          subtitle: item.subtitle,
          rating: item.rating ?? null,
          reviews: item.reviews ?? null,
          priceText: item.priceText ?? null,
          provider: item.provider,
          tags: item.tags ?? [],
          photoUrl: item.photoUrl ?? '',
          metadata: item.metadata ?? {},
        })),
      }
    })
    .filter(Boolean)
  const stays = Array.from(curatedExperiences.selections.stays.values()).map((item) => ({
    id: item.id,
    title: item.title,
    subtitle: item.subtitle,
    rating: item.rating ?? null,
    reviews: item.reviews ?? null,
    priceText: item.priceText ?? null,
    provider: item.provider,
    tags: item.tags ?? [],
    photoUrl: item.photoUrl ?? '',
    metadata: item.metadata ?? {},
  }))
  return { experiences, stays }
}

function buildPackageCostSummary(selection) {
  const summary = {
    total: 0,
    currency: 'MYR',
    categories: {},
    picks: [],
  }
  if (!selection) {
    return summary
  }
  const ensureCurrency = (candidate) => {
    if (candidate && typeof candidate === 'string' && candidate.trim()) {
      summary.currency = candidate.trim().toUpperCase()
    }
  }
  const trackPick = (theme, pick) => {
    if (!COST_SUMMARY_THEMES.has(theme)) {
      return
    }
    const priceInfo = extractSelectionPriceInfo(pick)
    if (!priceInfo || !priceInfo.price || priceInfo.price <= 0) {
      return
    }
    const themeKey = theme || 'travel'
    ensureCurrency(priceInfo.currency)
    summary.categories[themeKey] = (summary.categories[themeKey] ?? 0) + priceInfo.price
    summary.total += priceInfo.price
    summary.picks.push({
      theme: themeKey,
      id: pick?.id ?? null,
      title: pick?.title ?? '',
      price: priceInfo.price,
      currency: priceInfo.currency || summary.currency,
    })
  }
  selection.experiences?.forEach((section) => {
    section?.picks?.forEach((pick) => trackPick(section.theme, pick))
  })
  summary.total = Math.round(summary.total * 100) / 100
  Object.keys(summary.categories).forEach((key) => {
    summary.categories[key] = Math.round(summary.categories[key] * 100) / 100
  })
  summary.totalFormatted =
    summary.total > 0 ? formatCurrencyDisplay(summary.total, summary.currency) : null
  return summary
}

function extractSelectionPriceInfo(entry) {
  if (!entry) {
    return null
  }
  const metadata = entry.metadata || {}
  const priceCandidates = [
    entry.priceValue,
    metadata.priceValue,
    metadata.price,
    metadata.pricing,
    metadata.priceRange?.start,
    metadata.priceRange?.value,
    metadata.price_range?.start,
    metadata.price_range?.value,
    metadata.priceRange?.min,
    metadata.price_range?.min,
    metadata.representativePrice?.chargeAmount,
    metadata.representativePrice?.publicAmount,
    metadata.priceMin,
    metadata.priceMax,
  ]
  let price = null
  for (const candidate of priceCandidates) {
    const value = normalisePriceCandidate(candidate)
    if (value != null) {
      price = value
      break
    }
  }
  if (price == null) {
    const parsed = parsePriceFromText(entry.priceText)
    if (parsed != null) {
      price = parsed
    }
  }
  const currencyCandidates = [
    metadata.currency,
    metadata.priceCurrency,
    metadata.priceRange?.currency,
    metadata.price_range?.currency,
    metadata.representativePrice?.currency,
    detectCurrencyFromText(entry.priceText),
  ]
  const currency = (currencyCandidates.find((value) => typeof value === 'string' && value.trim()) || 'MYR')
    .toUpperCase()
  if (price == null || price <= 0) {
    return null
  }
  return { price, currency }
}

function normalisePriceCandidate(candidate) {
  if (candidate == null || candidate === '') {
    return null
  }
  if (typeof candidate === 'number') {
    return Number.isFinite(candidate) ? candidate : null
  }
  if (typeof candidate === 'string') {
    const cleaned = Number(candidate.replace(/[^0-9.]/g, ''))
    return Number.isFinite(cleaned) ? cleaned : null
  }
  if (typeof candidate === 'object') {
    if (candidate.value != null) {
      return normalisePriceCandidate(candidate.value)
    }
    if (candidate.amount != null) {
      return normalisePriceCandidate(candidate.amount)
    }
    if (candidate.min != null && candidate.max != null) {
      const min = normalisePriceCandidate(candidate.min)
      const max = normalisePriceCandidate(candidate.max)
      if (min != null && max != null) {
        return (min + max) / 2
      }
      return min ?? max ?? null
    }
  }
  return null
}

function parsePriceFromText(text) {
  if (!text || typeof text !== 'string') {
    return null
  }
  const matches = text.replace(/,/g, '').match(/(\d+(?:\.\d+)?)/g)
  if (!matches || !matches.length) {
    return null
  }
  const numbers = matches.map((value) => Number(value)).filter((value) => Number.isFinite(value))
  if (!numbers.length) {
    return null
  }
  if (numbers.length === 1) {
    return numbers[0]
  }
  const avg = numbers.reduce((sum, value) => sum + value, 0) / numbers.length
  return Math.round(avg * 100) / 100
}

function detectCurrencyFromText(text) {
  if (!text || typeof text !== 'string') {
    return null
  }
  if (/MYR|RM/i.test(text)) {
    return 'MYR'
  }
  if (/USD|\$/i.test(text)) {
    return 'USD'
  }
  if (/EUR|€/i.test(text)) {
    return 'EUR'
  }
  if (/SGD/i.test(text)) {
    return 'SGD'
  }
  return null
}

async function persistSelectionsAsPackage(selection) {
  if (!props.travelerId) {
    return
  }
  if (
    !selection ||
    (!Array.isArray(selection.experiences) || selection.experiences.length === 0) &&
      (!Array.isArray(selection.stays) || selection.stays.length === 0)
  ) {
    return
  }
  const destination = derivePackageDestination()
  const costSummary = buildPackageCostSummary(selection)
  const payload = {
    travelerId: Number(props.travelerId),
    title: buildPackageTitle(destination),
    destination,
    coverPhoto: derivePackageCover(selection),
    summary: buildPackageSummary(selection, destination),
    selections: selection,
    costSummary,
    totalCost: costSummary.total,
    currency: costSummary.currency,
  }
  try {
    await savePlacesPackage(payload)
    message.success('Selections saved to Saved places.')
    window.dispatchEvent(
      new CustomEvent(SAVED_PLACES_REFRESH_EVENT, {
        detail: { travelerId: Number(props.travelerId) },
      }),
    )
  } catch (error) {
    console.error('Failed to save selections package', error)
    message.warning('Selections saved locally. Connect later to sync with Saved places.')
  }
}

function buildPackageTitle(destination) {
  const base = destination ? `${destination} experience kit` : 'Travel experience kit'
  return base
}

function derivePackageDestination() {
  return (
    plannerPreferences.value.destination ||
    heroForm.destination?.description ||
    heroForm.destinationInput ||
    'Malaysia'
  )
}

function derivePackageCover(selection) {
  const stayPhoto = selection.stays?.find((stay) => stay.photoUrl)?.photoUrl
  if (stayPhoto) {
    return stayPhoto
  }
  for (const section of selection.experiences || []) {
    const match = section.picks?.find((pick) => pick.photoUrl)
    if (match?.photoUrl) {
      return match.photoUrl
    }
  }
  return ''
}

function buildPackageSummary(selection, destination) {
  return {
    destination,
    dateRange: heroDateLabel.value,
    durationLabel: heroDurationLabel.value,
    travelStyles: plannerPreferences.value.travelStyles ?? [],
    accommodation: plannerPreferences.value.accommodation ?? '',
    group: {
      type: plannerPreferences.value.groupType ?? '',
      size: plannerPreferences.value.groupSize ?? null,
    },
    budgetRange: plannerPreferences.value.budgetRange ?? null,
    createdAt: new Date().toISOString(),
    counts: {
      experiences: selection.experiences?.reduce((total, section) => total + (section.picks?.length || 0), 0) ?? 0,
      stays: selection.stays?.length ?? 0,
    },
  }
}

function formatCoordinateLabel(lat, lng) {
  return `My location (${lat.toFixed(4)}, ${lng.toFixed(4)})`
}

async function loadItineraries() {
  if (!travelerReady.value) {
    return
  }
  loadingList.value = true
  try {
    const { itineraries: records = [] } = await fetchItineraries(props.travelerId)
    itineraries.value = records.map(normaliseItineraryRecord)
  } catch (error) {
    console.error(error)
    message.error(error?.message || 'Unable to load itineraries')
  } finally {
    loadingList.value = false
  }
}

function normaliseItineraryRecord(record) {
  const metadata = typeof record.metadata === 'string' ? safeParseJson(record.metadata) : record.metadata
  const storedPreferences =
    typeof record.preferences === 'string'
      ? safeParseJson(record.preferences)
      : record.preferences
  const preferenceSource = storedPreferences || metadata || {}
const summary = typeof record.summary === 'string' && record.summary.trim().startsWith('{')
  ? safeParseJson(record.summary)
  : record.summary
  const startDate = ensureIsoDate(record.startDate)
  const endDate = ensureIsoDate(record.endDate)
  const items = (record.items ?? []).map((item) => ({
    ...item,
    localId: `server-${item.itemId}`,
    date: ensureIsoDate(item.date) || startDate,
  }))
  const prefMeta = {
    travelStyles: preferenceSource?.travelStyles ?? [],
    interests: preferenceSource?.interests ?? [],
    groupSize: preferenceSource?.groupSize ?? record.groupSize ?? null,
    groupType: preferenceSource?.groupType ?? record.groupType ?? '',
    budget: preferenceSource?.budget ?? record.totalBudget ?? null,
    budgetRange:
      Array.isArray(preferenceSource?.budgetRange) && preferenceSource.budgetRange.length >= 2
        ? preferenceSource.budgetRange
        : null,
    accommodation: preferenceSource?.accommodation ?? plannerPreferences.value.accommodation ?? 'comfort',
    travelPace:
      preferenceSource?.travelPace ??
      preferenceSource?.pace ??
      plannerPreferences.value.travelPace ??
      'balanced',
    durationDays: preferenceSource?.durationDays ?? record.durationDays ?? null,
    notes: preferenceSource?.notes ?? '',
  }
  if (!prefMeta.budgetRange && prefMeta.budget) {
    prefMeta.budgetRange = normaliseBudgetRange([
      prefMeta.budget * 0.7,
      prefMeta.budget * 1.3,
    ])
  }
  return {
    itineraryId: record.itineraryId,
    travelerId: record.travelerId,
    title: record.title,
    startDate,
    endDate,
    origin: record.origin ?? preferenceSource?.origin ?? plannerPreferences.value.origin,
  originPlaceId: preferenceSource?.originPlaceId ?? metadata?.originPlaceId ?? '',
    originLat: preferenceSource?.originLat ?? metadata?.originLat ?? null,
    originLng: preferenceSource?.originLng ?? metadata?.originLng ?? null,
    destination: record.destination ?? preferenceSource?.destination ?? metadata?.destination ?? record.title,
    accommodation: prefMeta.accommodation,
    visibility: record.visibility,
    durationDays: record.durationDays,
    coverImage: record.coverImage ?? metadata?.coverImage ?? '',
    summary: typeof summary === 'string' ? summary : summary?.description ?? '',
    items,
    preferences: prefMeta,
  }
}

function safeParseJson(value) {
  try {
    return JSON.parse(value)
  } catch {
    return null
  }
}

function handleSelectItinerary(itinerary) {
  if (!itinerary) return
  selectedItineraryId.value = itinerary.itineraryId
  editor.itineraryId = itinerary.itineraryId
  editor.title = itinerary.title
  const startIso = ensureIsoDate(itinerary.startDate)
  const endIso = ensureIsoDate(itinerary.endDate)
  editor.startDate = startIso
  editor.endDate = endIso
  editor.visibility = itinerary.visibility
  editor.items = itinerary.items.map((item) => ({ ...item }))
  plannerPreferences.value.title = itinerary.title
  plannerPreferences.value.startDate = startIso
  plannerPreferences.value.endDate = endIso
  const applied = applyPreferencesToState(itinerary.preferences ?? {}, itinerary)
  heroForm.dateRange = applied.startDate && applied.endDate ? [applied.startDate, applied.endDate] : null
  heroForm.destinationInput = applied.destination ?? itinerary.title
  handleOriginResetFromPreferences()
  deletedItemIds.value = new Set()
  plannerActivated.value = true
  showItineraryBoard.value = true
}

function handleDuplicateItinerary(itinerary) {
  if (!itinerary) return
  selectedItineraryId.value = null
  editor.itineraryId = null
  editor.title = `${itinerary.title} (Copy)`
  const startIso = ensureIsoDate(itinerary.startDate)
  const endIso = ensureIsoDate(itinerary.endDate)
  editor.startDate = startIso
  editor.endDate = endIso
  editor.visibility = itinerary.visibility
  editor.items = itinerary.items.map((item) => ({
    ...item,
    itemId: null,
    localId: createLocalId(),
  }))
  const applied = applyPreferencesToState(itinerary.preferences ?? {}, {
    ...itinerary,
    title: editor.title,
    startDate: startIso,
    endDate: endIso,
  })
  heroForm.dateRange = applied.startDate && applied.endDate ? [applied.startDate, applied.endDate] : null
  handleOriginResetFromPreferences()
  deletedItemIds.value = new Set()
  message.info('Duplicated itinerary as a new draft')
  plannerActivated.value = true
  showItineraryBoard.value = true
}

function handleNewDraft() {
  plannerPreferences.value = createDefaultPreferences(props.travelerName)
  syncHeroFormFromPreferences()
  editor.itineraryId = null
  editor.title = plannerPreferences.value.title
  editor.startDate = plannerPreferences.value.startDate
  editor.endDate = plannerPreferences.value.endDate
  editor.visibility = plannerPreferences.value.visibility
  editor.items = []
  deletedItemIds.value = new Set()
  selectedItineraryId.value = null
  plannerActivated.value = true
  showItineraryBoard.value = false
}

function syncHeroFormFromPreferences() {
  applyOriginDetails(
    {
      label: plannerPreferences.value.origin,
      placeId: plannerPreferences.value.originPlaceId,
      lat: plannerPreferences.value.originLat,
      lng: plannerPreferences.value.originLng,
    },
    { syncPreferences: false },
  )
  heroForm.destinationInput = plannerPreferences.value.destination ?? ''
  heroForm.destination = null
  const startIso = ensureIsoDate(plannerPreferences.value.startDate)
  const endIso = ensureIsoDate(plannerPreferences.value.endDate)
  heroForm.dateRange = startIso && endIso ? [startIso, endIso] : null
}

function handlePreferencesReset() {
  plannerPreferences.value = createDefaultPreferences(props.travelerName)
  syncHeroFormFromPreferences()
}

function openPreferencesDrawer() {
  preferencesDrawerVisible.value = true
}

function handleQuickPreferencesConfirm() {
  syncHeroFormFromPreferences()
  message.success('Preferences applied')
}

function handleQuickPreferencesReset() {
  handlePreferencesReset()
  message.info('Preferences reset to smart defaults')
}

function confirmCalendarSelection(range) {
  if (Array.isArray(range) && range[0] && range[1]) {
    const startIso = ensureIsoDate(range[0])
    const endIso = ensureIsoDate(range[1])
    heroForm.dateRange = startIso && endIso ? [startIso, endIso] : null
  }
  calendarPopoverVisible.value = false
}

function applyQuickDuration(days) {
  const base =
    (calendarDraft.value && calendarDraft.value[0]) ||
    heroForm.dateRange?.[0] ||
    plannerPreferences.value.startDate ||
    formatDate(new Date())
  const start = new Date(base)
  if (Number.isNaN(start.getTime())) {
    return
  }
  const end = formatDate(addDays(start, Math.max(days - 1, 0)))
  const startFormatted = formatDate(start)
  calendarDraft.value = [startFormatted, end]
}

async function handlePlanWithAi() {
  const validationError = validatePreferences()
  if (validationError) {
    message.warning(validationError)
    return
  }
  if (!travelerReady.value) {
    message.warning('Please sign in as a traveler first.')
    return
  }
  if (!plannerPreferences.value.startDate || !plannerPreferences.value.endDate) {
    message.warning('Choose your travel dates to continue.')
    return
  }
  const destinationName =
    heroForm.destination?.description || heroForm.destinationInput || plannerPreferences.value.destination
  if (!destinationName) {
    message.warning('Please select a Malaysian destination.')
    return
  }
  const coordsReady = await ensureDestinationCoordinates()
  if (!coordsReady) {
    message.warning('Destination not pinned on the map. Using fallback places for this run.')
  }

  plannerActivated.value = true
  showItineraryBoard.value = false
  // Always treat a new AI plan as a fresh draft so saving creates a new itinerary
  editor.itineraryId = null
  selectedItineraryId.value = null
  deletedItemIds.value = new Set()
  const payload = {
    travelerId: props.travelerId,
    origin: heroForm.origin,
    destination: destinationName,
    startDate: plannerPreferences.value.startDate,
    endDate: plannerPreferences.value.endDate,
    durationDays:
      plannerPreferences.value.durationDays ||
      calculateDurationDays(plannerPreferences.value.startDate, plannerPreferences.value.endDate),
    interests: plannerPreferences.value.interests,
    travelStyles: plannerPreferences.value.travelStyles,
    groupSize: plannerPreferences.value.groupSize,
    groupType: plannerPreferences.value.groupType,
    budget: plannerPreferences.value.budget,
    budgetRange: plannerPreferences.value.budgetRange,
    budgetMin: plannerPreferences.value.budgetRange?.[0] ?? null,
    budgetMax: plannerPreferences.value.budgetRange?.[1] ?? null,
    accommodation: plannerPreferences.value.accommodation,
  }

  aiConversation.value = []
  pushAiConversation({
    title: 'Trip request',
    message: `Planning ${payload.durationDays}-day trip from ${payload.origin} to ${payload.destination}.`,
    status: 'done',
  })
  pushAiConversation({
    title: 'Analyze preferences',
    message: 'Analyzing your travel goals, group size, and comfort choices...',
    status: 'running',
  })
  const travelConversationTitle = 'Travel distance'
  pushAiConversation({
    title: travelConversationTitle,
    message: 'Calculating estimated distance and drive time between your start and destination...',
    status: 'running',
  })
  const themeConversationTitle = 'Theme curation'
  pushAiConversation({
    title: themeConversationTitle,
    message: 'Curating Google Places picks for every travel theme you selected...',
    status: 'pending',
  })
  const stayConversationTitle = 'Stay curation'
  pushAiConversation({
    title: stayConversationTitle,
    message: 'Fetching Booking.com stays that match your accommodation style...',
    status: 'pending',
  })
  pushAiConversation({
    title: 'Generate itinerary',
    message: 'Creating an optimized day-by-day schedule that fits your inputs...',
    status: 'pending',
  })
  pushAiConversation({
    title: 'Plot routes',
    message: 'Preparing map-friendly routes for each day...',
    status: 'pending',
  })

  const travelInsights = await computeTravelInsights()
  if (travelInsights) {
    latestTravelStats.value = travelInsights
    updateConversationStatus(travelConversationTitle, 'done', formatTravelInsightSummary(travelInsights))
  } else {
    updateConversationStatus(
      travelConversationTitle,
      'error',
      'Could not determine distance automatically. Continue planning manually.',
    )
  }

  updateConversationStatus(themeConversationTitle, 'running', 'Gathering curated experiences...')
  updateConversationStatus(stayConversationTitle, 'pending', 'Waiting for stay shortlists...')
  const curatedSelection = await ensureCuratedSelections()
  if (curatedSelection === null) {
    updateConversationStatus(themeConversationTitle, 'error', 'Selection cancelled.')
    updateConversationStatus(stayConversationTitle, 'error', 'Selection cancelled.')
    message.info('Trip planning cancelled before AI generation.')
    return
  }
  const experienceSummary = curatedSelection.experiences
    .map((entry) => `${entry.label}: ${entry.picks.map((pick) => pick.title).join(', ')}`)
    .join(' | ')
  updateConversationStatus(
    themeConversationTitle,
    'done',
    experienceSummary || 'Proceeding with AI-curated activities.',
  )
  const staySummary = curatedSelection.stays.length
    ? curatedSelection.stays
        .map((stay, index) => `${index + 1}. ${stay.title}${stay.priceText ? ` (${stay.priceText})` : ''}`)
        .join(' | ')
    : 'Proceeding with AI-curated stays.'
  updateConversationStatus(stayConversationTitle, 'done', staySummary)

  updateConversationStatus('Generate itinerary', 'done', 'Saved your picks without generating a timeline.')
  updateConversationStatus('Plot routes', 'done', 'Use Saved places to build routes later.')
  message.success('Selections saved. Review them under Saved places.')
  aiState.plan = null
  aiState.error = ''
  aiState.running = false
  return

  payload.travelStats = travelInsights
  payload.selectedExperiences = curatedSelection.experiences
  payload.selectedStays = curatedSelection.stays

  aiState.running = true
  aiState.error = ''
  try {
    const { plan } = await generateAiItinerary(payload)
    updateConversationStatus('Analyze preferences', 'done', 'Preferences processed.')
    const applied = applyAiPlan(plan, payload)
    if (applied) {
      updateConversationStatus('Generate itinerary', 'done', 'AI itinerary ready. Review below.')
      updateConversationStatus('Plot routes', 'done', 'Routes updated on the map.')
      message.success('AI itinerary generated. Review and tweak below.')
    } else {
      updateConversationStatus('Generate itinerary', 'error', 'AI returned incomplete data. Showing a basic draft.')
      fallbackToManualPlan()
    }
  } catch (error) {
    console.error(error)
    aiState.error = error?.message || 'AI planner failed'
    updateConversationStatus('Generate itinerary', 'error', aiState.error)
    message.error(aiState.error)
    fallbackToManualPlan()
  } finally {
    aiState.running = false
  }
}
function pushAiConversation(entry) {
  aiConversation.value.push({
    id: createLocalId(),
    ...entry,
  })
}

function updateConversationStatus(title, status, message) {
  const target = aiConversation.value.find((entry) => entry.title === title)
  if (target) {
    target.status = status
    if (message) {
      target.message = message
    }
  }
}

function applyAiPlan(plan, context) {
  if (!plan) return false
  aiState.plan = plan
  const title = plan?.summary?.title ?? `${context.destination} adventure`
  editor.title = title
  plannerPreferences.value.title = title
  const startIso = ensureIsoDate(context.startDate || plannerPreferences.value.startDate)
  const endIso = ensureIsoDate(context.endDate || plannerPreferences.value.endDate)
  if (startIso) {
    editor.startDate = startIso
    plannerPreferences.value.startDate = startIso
  }
  if (endIso) {
    editor.endDate = endIso
    plannerPreferences.value.endDate = endIso
  }
  const derivedDuration = calculateDurationDays(editor.startDate, editor.endDate)
  if (derivedDuration > 0) {
    plannerPreferences.value.durationDays = derivedDuration
  }
  if (startIso && endIso) {
    heroForm.dateRange = [startIso, endIso]
  }
  const items = []
  const rawStart =
    startIso ||
    ensureIsoDate(context.startDate) ||
    ensureIsoDate(plannerPreferences.value.startDate) ||
    formatDate(new Date())
  const startDate = new Date(rawStart)
  const baseDate = Number.isNaN(startDate.getTime()) ? new Date() : startDate
  plan.days?.forEach((day, index) => {
    const date = ensureIsoDate(day.date) || formatDate(addDays(baseDate, index))
    const timelineSegments = collectTimelineSegments(day, context.destination, index)
    if (!timelineSegments.length) {
      timelineSegments.push({
        title: day.theme || `${context.destination} day ${index + 1}`,
        description: 'Explore freely based on your preferences.',
        category: 'flex',
      })
    }
    let slotCursor = 0
    timelineSegments
      .filter(Boolean)
      .forEach((segment) => {
        const fallbackTime = deriveSegmentTime(segment, slotCursor)
        items.push(
          createItemFromSegment(
            segment,
            date,
            fallbackTime,
            `Experience ${index + 1}`,
            segment.category || inferCategoryFromSlot(slotCursor),
          ),
        )
        slotCursor += 1
      })
  })
  const derivedDurationFromDates =
    startIso && endIso ? calculateDurationDays(startIso, endIso) : plannerPreferences.value.durationDays
  const desiredDuration = Math.max(
    plannerPreferences.value.durationDays || 0,
    Array.isArray(plan.days) ? plan.days.length : 0,
    derivedDurationFromDates || 0,
    1,
  )
  const populatedDates = new Set(items.map((item) => item.date).filter(Boolean))
  for (let dayIndex = 0; dayIndex < desiredDuration; dayIndex += 1) {
    const targetDate = formatDate(addDays(baseDate, dayIndex))
    if (populatedDates.has(targetDate)) continue
    const fallbackSegments = buildFallbackSegmentsForDay(targetDate, dayIndex)
    if (fallbackSegments.length) {
      fallbackSegments.forEach((segment, slotIdx) => {
        items.push(
          createItemFromSegment(
            segment,
            targetDate,
            deriveSegmentTime(segment, slotIdx),
            `${context.destination} ideas`,
            segment.category,
          ),
        )
      })
    } else {
      items.push(
        createItemFromSegment(
          {
            title: `${context.destination} discovery time`,
            description: 'Add activities for this day.',
            category: 'flex',
          },
          targetDate,
          DEFAULT_SEGMENT_TIME_SLOTS[0],
          `${context.destination} discovery time`,
          'flex',
        ),
      )
    }
    populatedDates.add(targetDate)
  }
  if (!items.length) {
    items.push(
      createItemFromSegment(
        {
          title: `${context.destination} highlights`,
          description: 'AI summary provided but no specific segments returned.',
          category: 'flex',
        },
        startIso || ensureIsoDate(context.startDate) || plannerPreferences.value.startDate,
        '09:00:00',
        `${context.destination} highlights`,
        'flex',
      ),
    )
  }
  editor.items = items
  return true
}

function collectTimelineSegments(day, destination, dayIndex) {
  const baseSegments = Array.isArray(day?.segments) ? [...day.segments] : []
  const meals = Array.isArray(day?.meals) ? day.meals : []
  meals.forEach((meal) => {
    const converted = convertMealToSegment(meal)
    if (converted) {
      baseSegments.push(converted)
    }
  })
  if (day?.lodging) {
    const stay = convertLodgingToSegment(day.lodging, destination, dayIndex)
    if (stay) {
      baseSegments.push(stay)
    }
  }
  return baseSegments
}

function convertMealToSegment(meal) {
  if (!meal) return null
  const estimatedCost =
    typeof meal.estimatedCost === 'number'
      ? meal.estimatedCost
      : Number(meal.price || meal.budget) || null
  return {
    title: meal.name || meal.place || 'Meal break',
    description: meal.notes || '',
    address: meal.place || '',
    time: meal.time || MEAL_TIME_SLOT,
    category: meal.category || 'meal',
    estimatedCost,
  }
}

function convertLodgingToSegment(lodging, destination, dayIndex) {
  if (!lodging) return null
  if (typeof lodging === 'string') {
    return {
      title: lodging,
      description: `Stay near ${destination}`,
      category: 'lodging',
      time: LODGING_TIME_SLOT,
    }
  }
  const nightlyRate =
    typeof lodging.estimatedCost === 'number'
      ? lodging.estimatedCost
      : Number(lodging.rate || lodging.price) || null
  return {
    title:
      lodging.name ||
      lodging.title ||
      lodging.property ||
      `Preferred stay - Day ${dayIndex + 1}`,
    description: lodging.notes || lodging.description || '',
    address: lodging.address || lodging.area || '',
    time: lodging.time || LODGING_TIME_SLOT,
    category: lodging.category || 'lodging',
    estimatedCost: nightlyRate,
  }
}

function deriveSegmentTime(segment, slotIndex) {
  if (segment?.time) return segment.time
  const normalisedCategory = (segment?.category || '').toLowerCase()
  if (CATEGORY_TIME_MAP[normalisedCategory]) {
    return CATEGORY_TIME_MAP[normalisedCategory]
  }
  if (segment?.title) {
    const title = segment.title.toLowerCase()
    if (title.includes('breakfast')) return CATEGORY_TIME_MAP.breakfast
    if (title.includes('lunch')) return MEAL_TIME_SLOT
    if (title.includes('dinner')) return CATEGORY_TIME_MAP.dinner
    if (title.includes('sunset')) return CATEGORY_TIME_MAP.evening
  }
  const safeIndex = Math.min(slotIndex, DEFAULT_SEGMENT_TIME_SLOTS.length - 1)
  return DEFAULT_SEGMENT_TIME_SLOTS[safeIndex]
}

function createItemFromSegment(segment, date, fallbackTime, defaultTitle, fallbackCategory = '') {
  const normalisedCategory = (segment.category || fallbackCategory || '').toLowerCase()
  return {
    itemId: null,
    localId: createLocalId(),
    title: segment.title || segment.theme || defaultTitle,
    date,
    time: normaliseTime(segment.time || fallbackTime),
    notes: buildSegmentNotes(segment),
    address: segment.address || '',
    latitude: typeof segment.latitude === 'number' ? segment.latitude : null,
    longitude: typeof segment.longitude === 'number' ? segment.longitude : null,
    category: normalisedCategory || '',
    score: resolveSegmentCost(segment, normalisedCategory),
  }
}

function buildSegmentNotes(segment) {
  const lines = []
  ;['description', 'tips', 'notes', 'mealNotes'].forEach((field) => {
    const value = segment[field]
    if (value) {
      lines.push(String(value))
    }
  })
  if (segment.address) {
    lines.push(segment.address)
  }
  return lines.filter(Boolean).join(' ')
}

function resolveSegmentCost(segment, category) {
  const candidateKeys = [
    'estimatedCost',
    'price',
    'cost',
    'budget',
    'minPrice',
    'maxPrice',
    'amount',
    'value',
  ]
  for (const key of candidateKeys) {
    const numeric = Number(segment?.[key])
    if (Number.isFinite(numeric) && numeric >= 0) {
      return Math.round(numeric)
    }
  }
  const breakdownValue =
    segment?.priceBreakdown?.grossPrice?.value ?? segment?.priceBreakdown?.grossPrice?.amount
  if (Number.isFinite(Number(breakdownValue))) {
    return Math.round(Number(breakdownValue))
  }
  return estimateCostForCategory(category)
}

function estimateCostForCategory(category = '') {
  const normalised = category || 'default'
  if (normalised === 'lodging' || normalised === 'stay') {
    const style = plannerPreferences.value.accommodation || 'comfort'
    return ACCOMMODATION_COST_BY_STYLE[style] ?? ACCOMMODATION_COST_BY_STYLE.comfort
  }
  const base = CATEGORY_COST_DEFAULTS[normalised] ?? CATEGORY_COST_DEFAULTS.default
  const dailyBudget = calculateDailyBudgetEstimate()
  if (!dailyBudget) {
    return base
  }
  const scale = Math.min(2, Math.max(0.6, dailyBudget / 500))
  return Math.round(base * scale)
}

function calculateDailyBudgetEstimate() {
  const range = plannerPreferences.value.budgetRange ?? DEFAULT_BUDGET_RANGE
  const normalised = normaliseBudgetRange(range)
  const average = Math.max(0, Math.round((normalised[0] + normalised[1]) / 2))
  const days =
    plannerPreferences.value.durationDays ||
    calculateDurationDays(plannerPreferences.value.startDate, plannerPreferences.value.endDate)
  if (!days || days <= 0) {
    return average
  }
  return Math.round(average / days)
}

function buildFallbackSegmentsForDay(date, dayIndex) {
  const fallbackPreferences = {
    ...plannerPreferences.value,
    startDate: date,
    endDate: date,
    durationDays: 1,
  }
  const generated = generateActivitiesFromPreferences(fallbackPreferences)
  if (!generated.length) {
    return []
  }
  return generated
    .filter((item) => !item.date || ensureIsoDate(item.date) === date)
    .map((item, index) => {
      const inferredCategory = (item.category || inferCategoryFromSlot(index)).toLowerCase()
      return {
        title: item.title,
        description: item.notes,
        category: inferredCategory,
        estimatedCost: item.score ?? null,
        time: item.time || deriveSegmentTime({ category: inferredCategory }, index),
        address: item.address || '',
      }
    })
}

function inferCategoryFromSlot(index) {
  return SLOT_CATEGORY_ORDER[index % SLOT_CATEGORY_ORDER.length]
}

async function handleSaveItinerary() {
  if (!travelerReady.value) {
    message.error('Please log in to save itineraries.')
    return
  }
  if (!canSave.value) {
    message.warning('Add a few activities before saving your itinerary.')
    return
  }

  saving.value = true
  try {
    const startIso = ensureIsoDate(editor.startDate)
    const endIso = ensureIsoDate(editor.endDate)
    if (!startIso || !endIso) {
      message.error('Valid start and end dates are required before saving.')
      saving.value = false
      return
    }
    editor.startDate = startIso
    editor.endDate = endIso
    const preferencePayload = buildPreferencePayload()
    const payload = {
      travelerId: Number(props.travelerId),
      title: editor.title,
      startDate: startIso,
      endDate: endIso,
      visibility: editor.visibility,
      origin: plannerPreferences.value.origin,
      destination: plannerPreferences.value.destination,
      summary: aiState.plan?.summary ?? null,
      aiPlan: aiState.plan ?? null,
      metadata: preferencePayload,
      preferences: preferencePayload,
      totalBudget:
        plannerPreferences.value.budgetRange?.[1] ??
        plannerPreferences.value.budget ??
        null,
      items: editor.items.map(serialiseItem),
    }
    let saved
    if (editor.itineraryId) {
      payload.itineraryId = editor.itineraryId
      payload.deletedItemIds = Array.from(deletedItemIds.value)
      const { itinerary } = await updateItinerary(payload)
      saved = normaliseItineraryRecord(itinerary)
      itineraries.value = itineraries.value.map((item) =>
        item.itineraryId === saved.itineraryId ? saved : item,
      )
      message.success('Itinerary updated.')
    } else {
      const { itinerary } = await createItinerary(payload)
      saved = normaliseItineraryRecord(itinerary)
      itineraries.value = [saved, ...itineraries.value]
      message.success('Itinerary saved.')
    }
    editor.itineraryId = saved.itineraryId
    selectedItineraryId.value = saved.itineraryId
    editor.items = saved.items.map((item) => ({ ...item }))
    deletedItemIds.value = new Set()
  } catch (error) {
    console.error(error)
    message.error(error?.message || 'Failed to save itinerary')
  } finally {
    saving.value = false
  }
}

function serialiseItem(item) {
  const isoDate = ensureIsoDate(item.date) || ensureIsoDate(editor.startDate)
  return {
    itemId: item.itemId || null,
    title: item.title,
    date: isoDate || null,
    time: item.time,
    notes: item.notes,
    listingId: item.listingId ?? null,
    placeId: item.placeId ?? null,
    latitude: item.latitude ?? null,
    longitude: item.longitude ?? null,
    address: item.address ?? null,
    photoUrl: item.photoUrl ?? null,
    category: item.category ?? null,
    score: item.score ?? null,
  }
}

function handleRemoveItem(target) {
  if (!target) return
  const matcher = (item) =>
    (target.localId && item.localId === target.localId) ||
    (target.itemId && item.itemId === target.itemId)
  const index = editor.items.findIndex(matcher)
  if (index === -1) return
  const [removed] = editor.items.splice(index, 1)
  if (removed?.itemId) {
    deletedItemIds.value.add(removed.itemId)
  }
}

function openItemEditor(date = null) {
  itemEditor.mode = 'create'
  Object.assign(itemEditor.form, createItemDraft({ date: date || editor.startDate }))
  itemEditor.show = true
}

function editItem(target) {
  if (!target) return
  itemEditor.mode = 'edit'
  Object.assign(
    itemEditor.form,
    createItemDraft({
      ...target,
      itemId: target.itemId ?? null,
      localId: target.localId ?? createLocalId(),
    }),
  )
  itemEditor.show = true
}

function saveItemFromEditor() {
  if (!itemEditor.form.title || !itemEditor.form.date) {
    message.error('Activity title and date are required.')
    return
  }
  const payload = { ...itemEditor.form }
  if (itemEditor.mode === 'edit') {
    const index = editor.items.findIndex(
      (item) =>
        (payload.localId && item.localId === payload.localId) ||
        (payload.itemId && item.itemId === payload.itemId),
    )
    if (index !== -1) {
      editor.items[index] = payload
    }
  } else {
    editor.items.push(payload)
  }
  itemEditor.show = false
}

function createItemDraft(overrides = {}) {
  return {
    itemId: overrides.itemId ?? null,
    localId: overrides.localId ?? createLocalId(),
    title: overrides.title ?? '',
    date: overrides.date ?? '',
    time: overrides.time ?? '09:00:00',
    notes: overrides.notes ?? '',
    listingId: overrides.listingId ?? null,
    placeId: overrides.placeId ?? null,
    latitude: overrides.latitude ?? null,
    longitude: overrides.longitude ?? null,
    address: overrides.address ?? '',
    photoUrl: overrides.photoUrl ?? '',
    category: overrides.category ?? '',
    score: overrides.score ?? null,
  }
}

function createLocalId() {
  return `local-${Math.random().toString(36).slice(2, 9)}`
}

function duplicateCurrentPlan() {
  const source =
    activeItinerary.value ||
    {
      itineraryId: null,
      title: editor.title,
      startDate: editor.startDate,
      endDate: editor.endDate,
      visibility: editor.visibility,
      items: editor.items.map((item) => ({ ...item })),
    }
  handleDuplicateItinerary(source)
}

function handleDeleteItinerary(itinerary) {
  if (!itinerary?.itineraryId) return
  dialog.warning({
    title: 'Delete itinerary',
    content: `Remove \"${itinerary.title}\" permanently?`,
    positiveText: 'Delete',
    negativeText: 'Cancel',
    onPositiveClick: async () => {
      try {
        await deleteItinerary(props.travelerId, itinerary.itineraryId)
        itineraries.value = itineraries.value.filter(
          (item) => item.itineraryId !== itinerary.itineraryId,
        )
        if (selectedItineraryId.value === itinerary.itineraryId) {
          handleNewDraft()
        }
        message.success('Itinerary deleted.')
      } catch (error) {
        console.error(error)
        message.error(error?.message || 'Unable to delete itinerary')
      }
    },
  })
}

async function handleGeneratePlan() {
  const validationError = validatePreferences()
  if (validationError) {
    message.warning(validationError)
    return
  }
  if (!plannerPreferences.value.startDate || !plannerPreferences.value.endDate) {
    message.warning('Set travel dates first.')
    return
  }
  generating.value = true
  showItineraryBoard.value = false
  try {
    plannerActivated.value = true
    const generatedItems = generateActivitiesFromPreferences(plannerPreferences.value)
    editor.itineraryId = null
    editor.title = plannerPreferences.value.title
    editor.startDate = plannerPreferences.value.startDate
    editor.endDate = plannerPreferences.value.endDate
    editor.visibility = plannerPreferences.value.visibility
    editor.items = generatedItems
    deletedItemIds.value = new Set()
    selectedItineraryId.value = null
    message.success('Draft generated. Adjust details before saving.')
  } catch (error) {
    console.error(error)
    message.error(error?.message || 'Unable to generate itinerary')
  } finally {
    generating.value = false
  }
}

function fallbackToManualPlan() {
  handleGeneratePlan()
  updateConversationStatus('Plot routes', 'done', 'Previewing simple route suggestions.')
}

function validatePreferences() {
  const prefs = plannerPreferences.value
  if (!prefs.destination) return 'Please choose a destination.'
  if (!prefs.startDate || !prefs.endDate) return 'Start and end dates are required.'
  if (!prefs.durationDays || prefs.durationDays <= 0) {
    return 'Enter how many travel days you would like.'
  }
  if (!prefs.travelStyles || prefs.travelStyles.length === 0) {
    return 'Select at least one travel theme.'
  }
  const budgetRange = Array.isArray(prefs.budgetRange) ? prefs.budgetRange : []
  if (budgetRange.length < 2 || !Number.isFinite(Number(budgetRange[0])) || !Number.isFinite(Number(budgetRange[1])) || budgetRange[1] <= budgetRange[0]) {
    return 'Select a valid budget range.'
  }
  if (!prefs.groupType) {
    return 'Select a group type.'
  }
  if (!prefs.groupSize || prefs.groupSize <= 0) {
    return 'Provide your group size.'
  }
  if (!prefs.accommodation) {
    return 'Choose your accommodation style.'
  }
  return ''
}

const curatedMalaysiaActivities = [
  {
    destination: 'Kuala Lumpur',
    activities: [
      {
        title: 'Batu Caves sunrise climb',
        tags: ['culture', 'adventure'],
        notes: 'Beat the heat and learn about the Hindu shrines.',
      },
      {
        title: 'Heritage walk at Merdeka Square',
        tags: ['culture'],
        notes: 'Stories around colonial-era landmarks.',
      },
      {
        title: 'Eco escape at KL Forest Eco Park',
        tags: ['nature'],
        notes: 'Walk canopy bridges minutes from the city.',
      },
      {
        title: 'Jalan Alor night market tasting',
        tags: ['food'],
        notes: 'Sample plant-forward Malaysian classics.',
      },
    ],
  },
  {
    destination: 'Penang',
    activities: [
      {
        title: 'Street art & clan jetty cycling loop',
        tags: ['culture', 'adventure'],
        notes: 'Cycle through George Town murals and jetty villages.',
      },
      {
        title: 'Nyonya cooking class',
        tags: ['food', 'culture'],
        notes: 'Use local produce to learn Peranakan recipes.',
      },
      {
        title: 'Penang Hill forest therapy',
        tags: ['nature'],
        notes: 'Cooler hike with rainforest guides.',
      },
    ],
  },
  {
    destination: 'Melaka',
    activities: [
      {
        title: 'Dutch Square heritage walk',
        tags: ['culture', 'historical', 'city'],
        notes: 'Explore Stadthuys, Christ Church, and colonial icons.',
      },
      {
        title: 'Jonker Street night market tasting',
        tags: ['food', 'culture'],
        notes: 'Chicken rice balls, nyonya laksa, and cendol treats.',
      },
      {
        title: 'Melaka River sunset cruise',
        tags: ['relax', 'city'],
        notes: 'Glide past murals and riverside cafes at dusk.',
      },
      {
        title: 'A Famosa fortress climb',
        tags: ['adventure', 'historical'],
        notes: 'Hike up St. Paulâ€™s Hill for panoramic views.',
      },
      {
        title: 'Cheng Ho Cultural Museum tour',
        tags: ['culture', 'historical'],
        notes: 'Trace admiral Zheng Heâ€™s voyages through Melaka.',
      },
      {
        title: 'Klebang beach kite evening',
        tags: ['relax', 'nature'],
        notes: 'Fly kites and sip coconut shakes by the sea.',
      },
    ],
  },
]

const FALLBACK_STAYS = [
  {
    title: 'Boutique heritage stay',
    city: 'George Town',
    price: 260,
    label: 'Heritage',
    styles: ['comfort', 'premium'],
    themes: ['culture', 'historical', 'city'],
    photoUrl: 'https://images.unsplash.com/photo-1505691938895-1758d7feb511?auto=format&fit=crop&w=800&q=60',
  },
  {
    title: 'Eco river lodge',
    city: 'Kuala Lumpur Fringe',
    price: 220,
    label: 'Nature',
    styles: ['comfort'],
    themes: ['nature', 'relax'],
    photoUrl: 'https://images.unsplash.com/photo-1505691723518-36a5ac3be353?auto=format&fit=crop&w=800&q=60',
  },
  {
    title: 'City rooftop hotel',
    city: 'Johor Bahru',
    price: 310,
    label: 'Cityscape',
    styles: ['premium'],
    themes: ['city', 'food'],
    photoUrl: 'https://images.unsplash.com/photo-1484154218962-a197022b5858?auto=format&fit=crop&w=800&q=60',
  },
  {
    title: 'Luxury island villa',
    city: 'Langkawi',
    price: 780,
    label: 'Luxury',
    styles: ['luxury'],
    themes: ['relax', 'adventure'],
    photoUrl: 'https://images.unsplash.com/photo-1501117716987-c8e1ecb210cc?auto=format&fit=crop&w=800&q=60',
  },
  {
    title: 'Jonker Peranakan suites',
    city: 'Melaka',
    price: 320,
    label: 'Heritage',
    styles: ['premium'],
    themes: ['culture', 'historical', 'city'],
    photoUrl: 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?auto=format&fit=crop&w=800&q=60',
  },
  {
    title: 'Melaka riverside loft',
    city: 'Melaka',
    price: 360,
    label: 'Cityscape',
    styles: ['premium'],
    themes: ['city', 'food', 'relax'],
    photoUrl: 'https://images.unsplash.com/photo-1496417263034-38ec4f0b665a?auto=format&fit=crop&w=800&q=60',
  },
  {
    title: 'Straits wellness retreat',
    city: 'Melaka Coast',
    price: 540,
    label: 'Relax',
    styles: ['luxury'],
    themes: ['relax', 'nature'],
    photoUrl: 'https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=800&q=60',
  },
  {
    title: 'Peranakan courtyard inn',
    city: 'Melaka',
    price: 250,
    label: 'Comfort',
    styles: ['comfort'],
    themes: ['culture', 'city'],
    photoUrl: 'https://images.unsplash.com/photo-1505691723518-36a5ac3be353?auto=format&fit=crop&w=800&q=60',
  },
  {
    title: 'River market loft',
    city: 'Melaka',
    price: 290,
    label: 'Cityscape',
    styles: ['comfort', 'premium'],
    themes: ['food', 'city'],
    photoUrl: 'https://images.unsplash.com/photo-1484154218962-a197022b5858?auto=format&fit=crop&w=800&q=60',
  },
  {
    title: 'Cheng Ho boutique stay',
    city: 'Melaka',
    price: 410,
    label: 'Premium',
    styles: ['premium'],
    themes: ['culture', 'historical'],
    photoUrl: 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=800&q=60',
  },
]

const SYNTHETIC_STAY_TEMPLATES = {
  comfort: [
    {
      title: '{city} Garden courtyard inn',
      label: 'Comfort',
      styles: ['comfort'],
      themes: ['culture', 'city'],
      price: 230,
      photoUrl: 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=800&q=60',
    },
    {
      title: '{city} Community homestay',
      label: 'Comfort',
      styles: ['comfort'],
      themes: ['food', 'culture'],
      price: 210,
      photoUrl: 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?auto=format&fit=crop&w=800&q=60',
    },
    {
      title: '{city} Riverside studio',
      label: 'Comfort',
      styles: ['comfort'],
      themes: ['relax', 'city'],
      price: 240,
      photoUrl: 'https://images.unsplash.com/photo-1496417263034-38ec4f0b665a?auto=format&fit=crop&w=800&q=60',
    },
    {
      title: '{city} Artisan loft',
      label: 'Comfort',
      styles: ['comfort'],
      themes: ['city', 'culture'],
      price: 260,
      photoUrl: 'https://images.unsplash.com/photo-1444419988131-046ed4e5ffd6?auto=format&fit=crop&w=800&q=60',
    },
    {
      title: '{city} Straits townhouse',
      label: 'Comfort',
      styles: ['comfort'],
      themes: ['culture', 'city'],
      price: 250,
      photoUrl: 'https://images.unsplash.com/photo-1493809842364-78817add7ffb?auto=format&fit=crop&w=800&q=60',
    },
    {
      title: '{city} Heritage duplex',
      label: 'Comfort',
      styles: ['comfort'],
      themes: ['culture', 'historical'],
      price: 255,
      photoUrl: 'https://images.unsplash.com/photo-1484154218962-a197022b5858?auto=format&fit=crop&w=800&q=60',
    },
  ],
  premium: [
    {
      title: '{city} Boutique riverside suites',
      label: 'Premium',
      styles: ['premium'],
      themes: ['city', 'culture'],
      price: 360,
      photoUrl: 'https://images.unsplash.com/photo-1505691723518-36a5ac3be353?auto=format&fit=crop&w=800&q=60',
    },
    {
      title: '{city} Designer loft residence',
      label: 'Premium',
      styles: ['premium'],
      themes: ['city', 'food'],
      price: 390,
      photoUrl: 'https://images.unsplash.com/photo-1496417263034-38ec4f0b665a?auto=format&fit=crop&w=800&q=60',
    },
    {
      title: '{city} Heritage terraced suite',
      label: 'Premium',
      styles: ['premium'],
      themes: ['culture', 'city'],
      price: 420,
      photoUrl: 'https://images.unsplash.com/photo-1444419988131-046ed4e5ffd6?auto=format&fit=crop&w=800&q=60',
    },
    {
      title: '{city} Gallery loft hotel',
      label: 'Premium',
      styles: ['premium'],
      themes: ['culture', 'city'],
      price: 440,
      photoUrl: 'https://images.unsplash.com/photo-1493809842364-78817add7ffb?auto=format&fit=crop&w=800&q=60',
    },
  ],
  luxury: [
    {
      title: '{city} Straits private villa',
      label: 'Luxury',
      styles: ['luxury'],
      themes: ['relax', 'nature'],
      price: 680,
      photoUrl: 'https://images.unsplash.com/photo-1501117716987-c8e1ecb210cc?auto=format&fit=crop&w=800&q=60',
    },
    {
      title: '{city} Skyline grand residence',
      label: 'Luxury',
      styles: ['luxury'],
      themes: ['city', 'relax'],
      price: 720,
      photoUrl: 'https://images.unsplash.com/photo-1484154218962-a197022b5858?auto=format&fit=crop&w=800&q=60',
    },
    {
      title: '{city} Coastal spa retreat',
      label: 'Luxury',
      styles: ['luxury'],
      themes: ['relax', 'nature'],
      price: 760,
      photoUrl: 'https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=800&q=60',
    },
    {
      title: '{city} Heritage grand manor',
      label: 'Luxury',
      styles: ['luxury'],
      themes: ['culture', 'relax'],
      price: 790,
      photoUrl: 'https://images.unsplash.com/photo-1496417263034-38ec4f0b665a?auto=format&fit=crop&w=800&q=60',
    },
  ],
}

const SYNTHETIC_NAME_ADJECTIVES = ['Artisan', 'Boutique', 'Riverside', 'Coastal', 'Garden', 'Skyline', 'Heritage', 'Straits', 'Modern', 'Vintage']

function generateActivitiesFromPreferences(preferences) {
  const start = new Date(preferences.startDate)
  const end = new Date(preferences.endDate)
  const totalDays =
    Math.floor((end.getTime() - start.getTime()) / (1000 * 60 * 60 * 24)) + 1 || 1
  const pool = pickActivityPool(preferences.destination, preferences.travelStyles)
  const generated = []
  for (let dayIndex = 0; dayIndex < totalDays; dayIndex += 1) {
    const dayDate = new Date(start)
    dayDate.setDate(start.getDate() + dayIndex)
    const dateString = formatDate(dayDate)
    const dayActivities = selectDayActivities(
      pool,
      dayIndex,
      preferences.travelPace,
      NON_LODGING_CATEGORIES.length,
    )
    NON_LODGING_CATEGORIES.forEach((category, slotIndex) => {
      const activity = dayActivities[slotIndex]
      const slotTime =
        CATEGORY_TIME_MAP[category] ||
        DEFAULT_SEGMENT_TIME_SLOTS[slotIndex % DEFAULT_SEGMENT_TIME_SLOTS.length]
      generated.push({
        itemId: null,
        localId: createLocalId(),
        title: activity.title,
        date: dateString,
        time: slotTime,
        category,
        notes: activity.notes,
        score: activity.estimatedCost ?? estimateCostForCategory(category),
        address: activity.address || '',
      })
    })
    const lodgingSegment = buildLodgingSegment(preferences)
    generated.push({
      itemId: null,
      localId: createLocalId(),
      title: lodgingSegment.title,
      date: dateString,
      time: CATEGORY_TIME_MAP.lodging,
      category: 'lodging',
      notes: lodgingSegment.description,
      score: lodgingSegment.estimatedCost,
      address: lodgingSegment.address || '',
    })
  }
  return generated
}

function pickActivityPool(destination, styles = []) {
  const preferred = curatedMalaysiaActivities.find(
    (entry) => entry.destination === destination,
  )
  const pool = preferred?.activities ?? curatedMalaysiaActivities.flatMap((entry) => entry.activities)
  const styleSet = new Set(styles ?? [])
  if (!styleSet.size) {
    return pool
  }
  const ranked = pool
    .map((activity) => ({
      activity,
      score: activity.tags?.some((tag) => styleSet.has(tag)) ? 2 : 1,
    }))
    .sort((a, b) => b.score - a.score)
    .map((item) => item.activity)
  return ranked
}

function selectDayActivities(pool, dayIndex, travelPace = 'balanced', targetCount = NON_LODGING_CATEGORIES.length) {
  const intensity =
    travelPace === 'fast' ? targetCount : travelPace === 'relaxed' ? Math.ceil(targetCount * 0.6) : Math.ceil(targetCount * 0.8)
  if (!pool.length) {
    return Array.from({ length: targetCount }, (_, idx) => createFlexibleActivity(NON_LODGING_CATEGORIES[idx]))
  }
  const selected = []
  for (let i = 0; i < intensity; i += 1) {
    const poolIndex = (dayIndex * intensity + i) % pool.length
    selected.push(pool[poolIndex])
  }
  while (selected.length < targetCount) {
    selected.push(createFlexibleActivity(NON_LODGING_CATEGORIES[selected.length] || 'flex'))
  }
  return selected
}

function createFlexibleActivity(category = 'flex') {
  const readable = category === 'meal' ? 'Food break' : category === 'evening' ? 'Sunset stroll' : 'Flexible block'
  return {
    title: readable,
    notes: 'Use this slot for spontaneous finds or personal downtime.',
    estimatedCost: estimateCostForCategory(category),
    category,
  }
}

function buildLodgingSegment(preferences) {
  const style = preferences.accommodation || 'comfort'
  return {
    title: LODGING_SEGMENT_TITLES[style] ?? 'Preferred stay check-in',
    description: LODGING_SEGMENT_DESCRIPTIONS[style] ?? 'Rest up for tomorrowâ€™s adventures.',
    estimatedCost: ACCOMMODATION_COST_BY_STYLE[style] ?? ACCOMMODATION_COST_BY_STYLE.comfort,
    category: 'lodging',
  }
}

function calculateDurationDays(start, end) {
  if (!start || !end) return 0
  const startDate = new Date(start)
  const endDate = new Date(end)
  return Math.max(1, Math.floor((endDate - startDate) / (1000 * 60 * 60 * 24)) + 1)
}

function ensureIsoDate(value) {
  if (value === null || value === undefined || value === '') {
    return ''
  }
  if (typeof value === 'string') {
    const trimmed = value.trim()
    if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
      return trimmed
    }
    const parsed = Date.parse(trimmed)
    if (!Number.isNaN(parsed)) {
      return formatDate(new Date(parsed))
    }
    return ''
  }
  if (value instanceof Date && !Number.isNaN(value.getTime())) {
    return formatDate(value)
  }
  if (typeof value === 'number' && Number.isFinite(value)) {
    const date = new Date(value)
    if (!Number.isNaN(date.getTime())) {
      return formatDate(date)
    }
  }
  return ''
}

function buildPreferencePayload() {
  return {
    travelStyles: plannerPreferences.value.travelStyles ?? [],
    interests: plannerPreferences.value.interests ?? [],
    groupSize: plannerPreferences.value.groupSize ?? null,
    groupType: plannerPreferences.value.groupType ?? null,
    budget: plannerPreferences.value.budget ?? null,
    budgetRange: plannerPreferences.value.budgetRange ?? null,
    budgetMin: plannerPreferences.value.budgetRange?.[0] ?? null,
    budgetMax: plannerPreferences.value.budgetRange?.[1] ?? null,
    accommodation: plannerPreferences.value.accommodation ?? null,
    travelPace: plannerPreferences.value.travelPace ?? null,
    durationDays: plannerPreferences.value.durationDays ?? null,
    notes: plannerPreferences.value.notes ?? '',
    origin: plannerPreferences.value.origin ?? '',
    destination: plannerPreferences.value.destination ?? heroForm.destination?.description ?? '',
    originPlaceId: plannerPreferences.value.originPlaceId ?? heroForm.originPlace?.placeId ?? '',
    originLat:
      plannerPreferences.value.originLat ??
      (typeof heroForm.originPlace?.lat === 'number' ? heroForm.originPlace.lat : null),
    originLng:
      plannerPreferences.value.originLng ??
      (typeof heroForm.originPlace?.lng === 'number' ? heroForm.originPlace.lng : null),
    curatedExperiences: curatedExperiences.lastSelections ?? null,
    travelStats: latestTravelStats.value,
  }
}

function applyPreferencesToState(source = {}, itinerary = {}) {
  const current = plannerPreferences.value
  const startIso = ensureIsoDate(itinerary.startDate ?? source.startDate ?? current.startDate)
  const endIso = ensureIsoDate(itinerary.endDate ?? source.endDate ?? current.endDate)
  const next = {
    ...current,
    title: itinerary.title ?? current.title,
    startDate: startIso || current.startDate,
    endDate: endIso || current.endDate,
    durationDays:
      source.durationDays ??
      itinerary.durationDays ??
      (startIso && endIso ? calculateDurationDays(startIso, endIso) : current.durationDays),
    groupSize: Object.prototype.hasOwnProperty.call(source, 'groupSize') ? source.groupSize : null,
    groupType: source.groupType ?? '',
    budget: Object.prototype.hasOwnProperty.call(source, 'budget') ? source.budget : null,
    budgetRange: Array.isArray(source.budgetRange) ? [...source.budgetRange] : null,
    accommodation: source.accommodation ?? itinerary.accommodation ?? current.accommodation ?? 'comfort',
    travelStyles: Array.isArray(source.travelStyles) ? [...source.travelStyles] : [],
    interests: Array.isArray(source.interests) ? [...source.interests] : [],
    travelPace: source.travelPace ?? source.pace ?? current.travelPace ?? 'balanced',
    notes: typeof source.notes === 'string' ? source.notes : '',
    origin: itinerary.origin ?? source.origin ?? current.origin,
    originPlaceId: itinerary.originPlaceId ?? source.originPlaceId ?? current.originPlaceId,
    originLat:
      typeof itinerary.originLat === 'number'
        ? itinerary.originLat
        : typeof source.originLat === 'number'
          ? source.originLat
          : current.originLat,
    originLng:
      typeof itinerary.originLng === 'number'
        ? itinerary.originLng
        : typeof source.originLng === 'number'
          ? source.originLng
          : current.originLng,
    destination: itinerary.destination ?? source.destination ?? current.destination,
  }
  next.durationDays = next.durationDays || calculateDurationDays(next.startDate, next.endDate)
  plannerPreferences.value = next
  return next
}

function formatDate(date) {
  const year = date.getFullYear()
  const month = `${date.getMonth() + 1}`.padStart(2, '0')
  const day = `${date.getDate()}`.padStart(2, '0')
  return `${year}-${month}-${day}`
}

function formatDisplayDate(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleDateString('en-MY', { month: 'short', day: 'numeric' })
}

function addDays(date, days) {
  const copy = new Date(date)
  copy.setDate(copy.getDate() + days)
  return copy
}

function normaliseTime(value) {
  if (!value) return '09:00:00'
  if (/^\d{2}:\d{2}:\d{2}$/.test(value)) return value
  if (/^\d{2}:\d{2}$/.test(value)) return `${value}:00`
  return '09:00:00'
}

function normaliseBudgetRange(range) {
  const candidate = Array.isArray(range) ? range : []
  let min = Number(candidate[0])
  let max = Number(candidate[1])
  if (!Number.isFinite(min)) {
    min = DEFAULT_BUDGET_RANGE[0]
  }
  if (!Number.isFinite(max)) {
    max = DEFAULT_BUDGET_RANGE[1]
  }
  if (max < min) {
    ;[min, max] = [max, min]
  }
  if (max - min < 200) {
    max = min + 200
  }
  min = Math.max(0, Math.round(min))
  max = Math.max(min + 100, Math.round(max))
  return [min, max]
}

function deriveBudgetRange(seed) {
  const base = Number(seed)
  if (!Number.isFinite(base) || base <= 0) {
    return [...DEFAULT_BUDGET_RANGE]
  }
  const spread = Math.max(300, Math.round(base * 0.4))
  const min = Math.max(0, base - spread)
  const max = base + spread
  return normaliseBudgetRange([min, max])
}

function arraysEqual(a, b) {
  if (!Array.isArray(a) || !Array.isArray(b)) {
    return false
  }
  if (a.length !== b.length) {
    return false
  }
  return a.every((value, index) => value === b[index])
}

function createDefaultPreferences(name = 'Traveler') {
  const today = new Date()
  const start = new Date(today)
  start.setDate(today.getDate() + 7)
  const end = new Date(start)
  end.setDate(start.getDate() + 3)
  const firstName = name?.split(' ')?.[0] ?? 'Traveler'
  const budgetRange = [...DEFAULT_BUDGET_RANGE]
  return {
    title: `${firstName}'s Malaysia eco escape`,
    origin: '',
    originPlaceId: '',
    originLat: null,
    originLng: null,
    destination: '',
    destinationPlaceId: '',
    destinationLat: null,
    destinationLng: null,
    startDate: formatDate(start),
    endDate: formatDate(end),
    durationDays: 4,
    groupSize: 2,
    groupType: 'couple',
    budgetRange,
    budget: Math.round((budgetRange[0] + budgetRange[1]) / 2),
    accommodation: 'comfort',
    travelStyles: ['balanced'],
    interests: ['Food trail'],
    travelPace: 'balanced',
    visibility: 'Private',
    notes: '',
  }
}

function createPlacesSessionToken() {
  return typeof crypto !== 'undefined' && crypto.randomUUID
    ? crypto.randomUUID()
    : `session-${Math.random().toString(36).slice(2, 10)}`
}
</script>

<template>
  <n-space vertical size="large" class="trip-planner-shell">
    <section class="planner-hero">
      <n-grid cols="1 l:5" :x-gap="18" :y-gap="18">
        <n-grid-item :span="3">
          <n-card size="large" class="planner-hero-card">
            <n-space vertical size="large">
              <div>
                <div class="planner-field-label">Starting from</div>
                <div class="planner-input-shell">
                  <i class="ri-hotel-bed-line planner-input-icon" />
                  <n-auto-complete
                    v-model:value="heroForm.origin"
                    :options="originSuggestions"
                    :loading="originSearchLoading"
                    placeholder="Where are you starting?"
                    clearable
                    style="width: 100%;"
                    @select="(value, option) => handleOriginSelect(value, option)"
                  />
                  <n-tooltip trigger="hover" placement="bottom">
                    <template #trigger>
                      <n-button
                        tertiary
                        size="small"
                        class="planner-detect-button"
                        :loading="detectingOrigin"
                        @click="handleDetectOrigin"
                      >
                        <n-icon size="18">
                          <i class="ri-crosshair-2-line" />
                        </n-icon>
                      </n-button>
                    </template>
                    Detect my location
                  </n-tooltip>
                </div>
              </div>

              <n-space vertical size="small">
                <div class="planner-field-label">Heading to</div>
                <n-auto-complete
                  class="planner-destination-input"
                  v-model:value="heroForm.destinationInput"
                  :options="destinationSuggestions"
                  size="large"
                  placeholder="City or landmark in Malaysia"
                  :loading="placeSearchLoading"
                  clearable
                  dropdown-class="planner-destination-menu"
                  :render-option="renderDestinationOption"
                  @select="(value, option) => handleDestinationSelect(value, option)"
                />
              </n-space>

              <n-space vertical size="small">
                <div class="planner-field-label">Date / duration</div>
                <n-popover
                  v-model:show="calendarPopoverVisible"
                  trigger="click"
                  placement="bottom-start"
                  :show-arrow="false"
                  :style="{ padding: '0', borderRadius: '18px' }"
                >
                  <template #trigger>
                    <button class="planner-date-trigger">
                      <div class="planner-date-trigger__icons">
                        <i class="ri-calendar-check-line" />
                      </div>
                      <span>{{ heroDateLabel }}</span>
                    </button>
                  </template>
                  <div class="planner-calendar-panel">
                    <div class="planner-calendar-header">
                      <div>
                        <div class="planner-calendar-chip">Plan your stay</div>
                        <div class="planner-calendar-subtitle">{{ calendarDraftSummary }}</div>
                      </div>
                      <n-tag v-if="calendarDraftNightsLabel" round size="small" type="success">
                        {{ calendarDraftNightsLabel }}
                      </n-tag>
                    </div>
                    <n-date-picker
                      v-model:value="calendarDraft"
                      type="daterange"
                      panel
                      value-format="yyyy-MM-dd"
                      :is-date-disabled="(ts) => ts < Date.now() - 86400000"
                    />
                    <div class="planner-calendar-footer">
                      <div class="planner-quick-range">
                        <span>Quick picks:</span>
                        <n-button size="tiny" @click="applyQuickDuration(1)">+ 1 day</n-button>
                        <n-button size="tiny" @click="applyQuickDuration(2)">+ 2 days</n-button>
                        <n-button size="tiny" @click="applyQuickDuration(3)">+ 3 days</n-button>
                        <n-button size="tiny" @click="applyQuickDuration(7)">+ 7 days</n-button>
                      </div>
                      <n-space>
                        <n-button text size="small" @click="calendarPopoverVisible = false">Cancel</n-button>
                        <n-button type="primary" size="small" @click="confirmCalendarSelection(calendarDraft)">
                          Apply
                        </n-button>
                      </n-space>
                    </div>
                  </div>
                </n-popover>
              </n-space>

              <n-space vertical size="small" class="planner-hero-actions">
                <TripPlannerQuickPreferences
                  class="planner-inline-preferences"
                  ref="quickPreferencesRef"
                  v-model:preferences="plannerPreferences"
                  :disabled="aiState.running || generating"
                  :loading="aiState.running || generating"
                  @confirm="handleQuickPreferencesConfirm"
                  @reset="handleQuickPreferencesReset"
                  @advanced="openPreferencesDrawer"
                />
                <n-space justify="end">
                  <n-button type="primary" :loading="aiState.running" @click="handlePlanWithAi">
                    Plan a trip with AI
                  </n-button>
                </n-space>
              </n-space>
            </n-space>
          </n-card>
        </n-grid-item>
      </n-grid>
    </section>

    <section class="planner-intelligence">
      <n-grid cols="1 l:5" :x-gap="18" :y-gap="18">
        <n-grid-item :span="3">
          <TripPlannerAiAssistant
            :conversation="aiConversation"
            :curation="curatedExperiences"
            :curation-disabled="curatedConfirmDisabled"
            @toggle-experience="toggleExperienceSelection"
            @toggle-stay="toggleStaySelection"
            @auto-fill="autoFillCurationSelections"
            @cancel-curation="cancelCurationSelections"
            @confirm-curation="confirmCurationSelections"
          />
        </n-grid-item>
        <n-grid-item :span="2">
          <TripPlannerMap
            :api-key="MAPS_JS_KEY"
            :origin="mapOrigin"
            :destination="heroForm.destination"
            :days="mapDays"
            height="380px"
          />
        </n-grid-item>
      </n-grid>
    </section>

    <n-card
      v-if="plannerActivated && editor.items.length && !showItineraryBoard"
      class="itinerary-preview-callout"
    >
      <n-space justify="space-between" align="center" wrap>
        <div>
          <div class="callout-title">Itinerary draft ready</div>
          <n-text depth="3">
            Open the board only when youâ€™re ready to fine-tune each day.
          </n-text>
        </div>
        <n-button type="primary" size="small" @click="showItineraryBoard = true">
          Open itinerary draft
        </n-button>
      </n-space>
    </n-card>

    <section v-if="aiState.plan || aiState.error" class="planner-ai-panel">
      <n-card>
        <n-space justify="space-between" align="center" style="width: 100%;" wrap>
          <div>
            <div class="section-title">AI itinerary insight</div>
            <n-text depth="3">
              {{ aiState.plan?.summary?.tagline ?? 'Gemini suggests daily highlights for your trip.' }}
            </n-text>
          </div>
          <n-button text type="primary" @click="duplicateCurrentPlan">
            Duplicate current plan
          </n-button>
        </n-space>
        <n-alert v-if="aiState.error" type="error" style="margin-top: 12px;">
          {{ aiState.error }}
        </n-alert>
        <n-space v-else-if="aiState.plan?.summary?.dailyHighlights" style="margin-top: 12px;" wrap>
          <n-tag
            v-for="(highlight, index) in aiState.plan.summary.dailyHighlights"
            :key="index"
            type="info"
            round
          >
            {{ highlight }}
          </n-tag>
        </n-space>
      </n-card>
    </section>

    <section v-if="shouldShowItineraryBoard" class="planner-board">
      <div class="planner-board__toolbar">
        <n-tag type="info" size="small">AI draft</n-tag>
        <n-button text size="small" @click="showItineraryBoard = false">Hide itinerary</n-button>
      </div>
      <TripPlannerItineraryBoard
        :title="plannerMeta.title"
        :start-date="plannerMeta.startDate"
        :end-date="plannerMeta.endDate"
        :visibility="plannerMeta.visibility"
        :items="editor.items"
        :loading="generating || aiState.running"
        :budget="plannerMeta.budget"
        :budget-range="plannerMeta.budgetRange"
        :accommodation="plannerMeta.accommodation"
        :group-size="plannerMeta.groupSize"
        :group-type="plannerMeta.groupType"
        :travel-pace="plannerMeta.travelPace"
        :travel-styles="plannerMeta.travelStyles"
        :interests="plannerMeta.interests"
        @add-item="openItemEditor"
        @edit-item="editItem"
        @remove-item="handleRemoveItem"
      />

      <n-space justify="space-between" align="center" wrap>
        <n-text depth="3">
          Save your itinerary to keep editing it from any device or regenerate with new filters anytime.
        </n-text>
        <n-space>
          <n-button tertiary :disabled="!editor.items.length" @click="duplicateCurrentPlan">
            Copy as new draft
          </n-button>
          <n-button type="primary" :loading="saving" :disabled="!canSave" @click="handleSaveItinerary">
            {{ editor.itineraryId ? 'Update itinerary' : 'Save itinerary' }}
          </n-button>
        </n-space>
        </n-space>
      </section>
 
      <n-drawer v-model:show="preferencesDrawerVisible" width="520" placement="right">
      <n-drawer-content title="Travel preferences" closable>
        <TripPlannerPreferencesForm
          v-model:preferences="plannerPreferences"
          :disabled="!travelerReady"
          :loading="generating"
          @generate="handleGeneratePlan"
          @reset="handlePreferencesReset"
        />
      </n-drawer-content>
    </n-drawer>

    <n-drawer v-model:show="itemEditor.show" width="420" placement="right">
      <n-drawer-content :title="itemEditor.mode === 'edit' ? 'Edit activity' : 'Add activity'" closable>
        <n-form label-placement="top">
          <n-form-item label="Activity title" required>
            <n-input v-model:value="itemEditor.form.title" placeholder="Eg. Mangrove kayak tour" />
          </n-form-item>
          <n-form-item label="Date" required>
            <n-date-picker v-model:value="itemEditor.form.date" type="date" value-format="yyyy-MM-dd" />
          </n-form-item>
          <n-form-item label="Time">
            <n-time-picker
              v-model:value="itemEditor.form.time"
              format="HH:mm"
              value-format="HH:mm:ss"
              clearable
            />
          </n-form-item>
          <n-form-item label="Notes">
            <n-input
              v-model:value="itemEditor.form.notes"
              type="textarea"
              :autosize="{ minRows: 3, maxRows: 5 }"
              placeholder="Add reminders, ticket info, or meetup points."
            />
          </n-form-item>
          <n-form-item label="Address / meetup point">
            <n-input v-model:value="itemEditor.form.address" placeholder="Optional address or coordinates" />
          </n-form-item>
        </n-form>
        <template #footer>
          <n-space justify="space-between">
            <n-button tertiary @click="itemEditor.show = false">Cancel</n-button>
            <n-button type="primary" @click="saveItemFromEditor">
              {{ itemEditor.mode === 'edit' ? 'Update activity' : 'Add activity' }}
            </n-button>
          </n-space>
        </template>
    </n-drawer-content>
  </n-drawer>

  </n-space>
</template>

<style scoped>
.trip-planner-shell {
  width: 100%;
}

.planner-hero-card {
  background: linear-gradient(135deg, rgba(49, 130, 206, 0.08), rgba(14, 165, 233, 0.09));
  border: 1px solid rgba(59, 130, 246, 0.15);
}

.planner-field-label {
  font-weight: 600;
  color: #1f2937;
}

.planner-input-shell {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #fff;
  border: 1px solid rgba(8, 47, 73, 0.12);
  border-radius: 12px;
  padding: 6px 10px;
}

.planner-input-icon {
  font-size: 18px;
  color: rgba(15, 23, 42, 0.5);
}

.planner-detect-button {
  border: none;
  padding: 4px;
  color: #2563eb;
}

:global(.destination-option) {
  display: flex;
  gap: 12px;
  align-items: center;
  padding: 10px 2px;
}

:global(.destination-option--header) {
  font-size: 0.7rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: rgba(15, 23, 42, 0.5);
  padding: 6px 4px;
}

:global(.destination-option__icon) {
  width: 32px;
  height: 32px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(21, 128, 61, 0.1);
  color: #15803d;
  font-size: 18px;
}

:global(.destination-option__meta) {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
}

:global(.destination-option__title) {
  font-weight: 600;
  color: rgba(15, 23, 42, 0.95);
}

:global(.destination-option__subtitle) {
  font-size: 0.8rem;
  color: rgba(15, 23, 42, 0.6);
}

.planner-destination-input :deep(.n-input) {
  border-radius: 18px;
  border: 2px solid transparent;
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
}

.planner-destination-input :deep(.n-input:not(.n-input--disabled):hover) {
  border-color: rgba(34, 197, 94, 0.3);
}

.planner-destination-input :deep(.n-input--focus) {
  border-color: rgba(34, 197, 94, 0.6);
  box-shadow: 0 12px 32px rgba(34, 197, 94, 0.2);
}

:global(.planner-destination-menu) {
  border-radius: 18px;
  padding: 8px 18px 12px;
  box-shadow: 0 25px 60px rgba(15, 23, 42, 0.25);
  border: none;
  background: #fff;
}

:global(.planner-destination-menu .n-base-select-option__content) {
  padding: 8px 0;
}

:global(.planner-destination-menu .n-base-select-option) {
  border-radius: 14px;
  padding: 6px 8px;
}

:global(.planner-destination-menu .n-base-select-option--pending) {
  background: rgba(34, 197, 94, 0.08);
}

.planner-date-trigger {
  width: 100%;
  border: 1px solid rgba(8, 47, 73, 0.12);
  border-radius: 12px;
  padding: 10px 12px;
  display: flex;
  gap: 10px;
  align-items: center;
  background: #fff;
  font-weight: 600;
}

.planner-date-trigger__icons i {
  font-size: 18px;
  color: rgba(15, 23, 42, 0.6);
}

.planner-calendar-panel {
  padding: 18px;
  width: 520px;
  border-radius: 20px;
  background: linear-gradient(145deg, rgba(240, 249, 255, 0.95), rgba(252, 247, 241, 0.95));
  box-shadow:
    0 24px 60px rgba(15, 23, 42, 0.2),
    0 2px 6px rgba(15, 23, 42, 0.08);
}

.planner-calendar-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
  gap: 12px;
}

.planner-calendar-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: 999px;
  background: rgba(16, 185, 129, 0.12);
  color: #047857;
  font-weight: 600;
  font-size: 0.85rem;
}

.planner-calendar-subtitle {
  font-size: 0.9rem;
  color: rgba(15, 23, 42, 0.7);
  margin-top: 4px;
}

.planner-calendar-panel :deep(.n-date-panel) {
  background: transparent;
  box-shadow: none;
}

.planner-calendar-panel :deep(.n-date-panel-calendar) {
  border-radius: 16px;
  overflow: hidden;
  padding-bottom: 6px;
}

.planner-calendar-panel :deep(.n-date-panel-calendar__month) {
  padding-top: 6px;
  font-weight: 600;
  letter-spacing: 0.05rem;
  color: #0f172a;
}

.planner-calendar-panel :deep(.n-date-panel-weekdays) {
  font-weight: 500;
  color: rgba(15, 23, 42, 0.6);
}

.planner-calendar-panel :deep(.n-date-panel-date) {
  border-radius: 12px;
  transition: transform 0.15s ease, background 0.2s ease;
}

.planner-calendar-panel :deep(.n-date-panel-date:hover) {
  transform: translateY(-2px);
  background: rgba(34, 197, 94, 0.12);
  color: #065f46;
}

.planner-calendar-panel :deep(.n-date-panel-date--selected) {
  background: linear-gradient(120deg, #34d399, #10b981);
  color: #fff;
  box-shadow: 0 8px 20px rgba(16, 185, 129, 0.35);
}

.planner-calendar-panel :deep(.n-date-panel-date--current) {
  border: 1px solid rgba(34, 197, 94, 0.6);
  color: #059669;
}

.planner-calendar-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 12px;
}

.planner-quick-range {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.65);
}

.planner-quick-range :deep(.n-button) {
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.04);
  border: none;
  font-weight: 600;
  color: rgba(15, 23, 42, 0.7);
}

.planner-quick-range :deep(.n-button:hover) {
  background: rgba(16, 185, 129, 0.15);
  color: #047857;
}

.planner-hero-actions {
  border-top: 1px solid rgba(15, 23, 42, 0.08);
  padding-top: 12px;
}

.planner-inline-preferences {
  width: 100%;
  border: 1px solid rgba(15, 23, 42, 0.05);
  border-radius: 24px;
  padding: 16px;
  background: linear-gradient(120deg, rgba(244, 247, 255, 0.9), rgba(255, 250, 244, 0.85));
}

.planner-inline-preferences :deep(.quick-pref-panel) {
  width: 100%;
  max-height: none;
}

.planner-inline-preferences :deep(.pref-footer) {
  padding-left: 0;
  padding-right: 0;
}

.planner-intelligence {
  width: 100%;
}

.planner-ai-panel,
.planner-saved,
.planner-board {
  width: 100%;
}

.section-title {
  font-size: 1.2rem;
  font-weight: 600;
}

.saved-grid {
  margin-top: 16px;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 16px;
}

.saved-card__image {
  width: 100%;
  height: 140px;
  border-radius: 12px;
  background-size: cover;
  background-position: center;
  margin-bottom: 12px;
}

.planner-board {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.planner-board__toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.itinerary-preview-callout {
  border: 1px dashed rgba(34, 197, 94, 0.5);
  background: rgba(240, 253, 244, 0.6);
  margin-bottom: 12px;
}

.callout-title {
  font-weight: 600;
  font-size: 1rem;
  margin-bottom: 4px;
}

</style>
