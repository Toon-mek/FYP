<template>
  <n-card title="Live stays from Booking.com" :segmented="{ content: true }">
    <template #header-extra>
      <n-space size="small">
        <n-input
          v-model:value="bookingSearchCity"
          size="small"
          clearable
          placeholder="City in Malaysia"
          style="width: 200px;"
        />
        <n-button size="small" type="primary" :loading="bookingLoading" @click="fetchBookingHotels">
          {{ bookingLoading ? 'Loading...' : 'Refresh' }}
        </n-button>
      </n-space>
    </template>
    <n-spin :show="bookingLoading">
      <template v-if="bookingError">
        <n-alert type="error" show-icon closable @close="clearBookingError">
          {{ bookingError }}
        </n-alert>
      </template>
      <template v-else-if="hasBookingHotels">
        <div class="booking-hotels-grid">
          <article v-for="hotel in bookingHotels" :key="hotel.id" class="booking-hotel-card">
            <div v-if="hotel.photo" class="booking-hotel-photo">
              <img :src="hotel.photo" :alt="hotel.name" loading="lazy" />
            </div>
            <header class="booking-hotel-header">
              <strong class="booking-hotel-name">{{ hotel.name }}</strong>
              <span class="booking-hotel-address">{{ hotel.address }}</span>
            </header>
            <div class="booking-hotel-meta" v-if="hotel.reviewScore || hotel.reviewCount">
              <span v-if="hotel.reviewScore" class="booking-rating">
                &#9733; {{ hotel.reviewScore.toFixed(1) }}
              </span>
              <span v-if="hotel.reviewCount" class="booking-reviews">
                ({{ hotel.reviewCount }} reviews)
              </span>
            </div>
            <div v-if="hotel.priceDisplay" class="booking-hotel-price">
              {{ hotel.priceDisplay }} <span class="booking-price-suffix">/ night</span>
            </div>
            <ul v-if="hotel.highlights.length" class="booking-hotel-tags">
              <li v-for="label in hotel.highlights" :key="label">{{ label }}</li>
            </ul>
          </article>
        </div>
      </template>
      <template v-else>
        <n-empty description="Enter a city to preview Booking.com stays." />
      </template>
    </n-spin>
    <template #footer>
      <n-space vertical size="small">
        <n-text depth="3">
          Preview data only. Checkout flow remains on this platform for demo purposes; no real Booking.com reservations
          are created.
        </n-text>
        <n-text depth="3" v-if="bookingLastSyncedLabel">
          Last synced {{ bookingLastSyncedLabel }}.
        </n-text>
      </n-space>
    </template>
  </n-card>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'

const API_BASE = import.meta.env.VITE_API_BASE || '/api'

const props = defineProps({
  defaultCity: {
    type: String,
    default: 'Kuala Lumpur',
  },
})

const bookingSearchCity = ref(props.defaultCity)
const bookingHotels = ref([])
const bookingLoading = ref(false)
const bookingError = ref('')
const bookingLastUpdated = ref(null)

const hasBookingHotels = computed(() => bookingHotels.value.length > 0)
const bookingLastSyncedLabel = computed(() => {
  if (!bookingLastUpdated.value) {
    return ''
  }

  try {
    return bookingLastUpdated.value.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  } catch (error) {
    console.warn('Failed to format sync time', error)
    return bookingLastUpdated.value.toLocaleTimeString()
  }
})

function formatIsoDate(date) {
  return date.toISOString().split('T')[0]
}

function addDays(date, days) {
  const next = new Date(date)
  next.setDate(next.getDate() + days)
  return next
}

function coerceArray(payload) {
  if (Array.isArray(payload)) {
    return payload
  }

  if (payload && typeof payload === 'object') {
    if (Array.isArray(payload.data)) {
      return payload.data
    }
    if (Array.isArray(payload.result)) {
      return payload.result
    }
    if (Array.isArray(payload.results)) {
      return payload.results
    }
    if (Array.isArray(payload.items)) {
      return payload.items
    }
  }

  return []
}

function normaliseDestinationList(rawResponse) {
  if (!rawResponse) {
    return []
  }

  const inner = rawResponse.data ?? rawResponse.details ?? rawResponse
  let destinations = coerceArray(inner)
  if (destinations.length === 0 && inner && typeof inner === 'object') {
    destinations = coerceArray(inner.destinations ?? inner.suggestions ?? Object.values(inner)[0])
  }

  return destinations
}

function normaliseHotelList(rawResponse) {
  if (!rawResponse) {
    return []
  }

  const inner = rawResponse.data ?? rawResponse.results ?? rawResponse
  let hotels = coerceArray(inner)

  if (hotels.length === 0 && inner && typeof inner === 'object') {
    hotels = coerceArray(
      inner.hotels ??
        inner.result ??
        inner.properties ??
        inner.items ??
        (Array.isArray(inner.data) ? inner.data : [])
    )
  }

  return hotels
}

function mapHotel(raw) {
  if (!raw || typeof raw !== 'object') {
    return null
  }

  const property = raw.property && typeof raw.property === 'object' ? raw.property : {}

  const priceBlock =
    raw.price_breakdown ??
    raw.composite_price_breakdown ??
    raw.price ??
    raw.priceModal ??
    property.price_breakdown ??
    raw.priceBreakdown ??
    property.priceBreakdown ??
    {}

  const amountCandidate =
    priceBlock.gross_price ??
    priceBlock.grossPrice?.value ??
    priceBlock.value ??
    raw.min_total_price ??
    raw.composite_price_breakdown?.gross_amount_per_night?.value ??
    raw.priceBreakdown?.grossPrice?.value ??
    property.priceBreakdown?.grossPrice?.value ??
    null
  const amountParsed = amountCandidate !== null ? Number(amountCandidate) : null
  const amount = amountParsed !== null && Number.isFinite(amountParsed) ? amountParsed : null
  const currency =
    priceBlock.currency ??
    raw.currency ??
    raw.price_breakdown?.currency ??
    raw.composite_price_breakdown?.gross_amount_per_night?.currency ??
    raw.priceBreakdown?.grossPrice?.currency ??
    property.priceBreakdown?.grossPrice?.currency ??
    'MYR'

  const reviewScoreCandidate =
    raw.review_score ?? raw.reviewScore ?? raw.rating ?? raw.reviewScoreWord ?? property.reviewScore ?? null
  const reviewScore =
    reviewScoreCandidate !== null && Number.isFinite(Number(reviewScoreCandidate))
      ? Number(reviewScoreCandidate)
      : null

  const reviewCountCandidate = raw.review_nr ?? raw.reviewCount ?? raw.review_count ?? property.reviewCount ?? null
  const reviewCount =
    reviewCountCandidate !== null && Number.isFinite(Number(reviewCountCandidate))
      ? Number(reviewCountCandidate)
      : null

  const distanceCandidate =
    raw.distance_to_cc ??
    raw.distance_to_city_centre ??
    raw.city_center_distance ??
    raw.distance ??
    property.distance ??
    null
  let distanceLabel = null
  const distanceNumber = distanceCandidate !== null ? Number(distanceCandidate) : null
  if (distanceNumber !== null && Number.isFinite(distanceNumber)) {
    distanceLabel = `${distanceNumber.toFixed(1)} km to centre`
  }

  const highlightCandidates = [
    raw.district ?? raw.city_trans ?? raw.city ?? raw.country_trans ?? property.countryCode,
    distanceLabel,
    raw.class ? `${raw.class} star rating` : null,
    raw.review_score_word ?? property.reviewScoreWord ?? null,
  ].filter(Boolean)

  const fallbackId = raw.hotel_id ?? raw.id ?? raw.property_id ?? raw.uuid ?? raw.name ?? property.id
  const safeId = fallbackId ?? `hotel-${Math.random().toString(36).slice(2)}`

  const hotelName = raw.hotel_name ?? raw.property_name ?? raw.name ?? property.name ?? 'Unnamed stay'
  const mainPhoto =
    raw.main_photo_url ??
    raw.photoMainUrl ??
    raw.image?.url ??
    raw.max_photo_url ??
    property.main_photo_url ??
    property.mainPhotoUrl ??
    null
  const photoFromArray = Array.isArray(raw.photoUrls) && raw.photoUrls.length ? raw.photoUrls[0] : null
  const propertyPhotoFromArray =
    Array.isArray(property.photoUrls) && property.photoUrls.length ? property.photoUrls[0] : null

  const address =
    raw.address ??
    raw.address_trans ??
    raw.city_trans ??
    raw.district ??
    raw.country_trans ??
    property.countryCode ??
    ''

  let priceDisplay = null
  if (amount !== null && currency) {
    try {
      priceDisplay = new Intl.NumberFormat('en-MY', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
      }).format(amount)
    } catch (error) {
      console.warn('Unable to format price', error)
      priceDisplay = `${currency} ${amount}`
    }
  }

  return {
    id: safeId,
    name: hotelName,
    photo: mainPhoto ?? photoFromArray ?? propertyPhotoFromArray ?? null,
    reviewScore,
    reviewCount,
    price: amount,
    currency,
    priceDisplay,
    address,
    highlights: Array.isArray(highlightCandidates) ? highlightCandidates : [],
    raw,
  }
}

async function fetchBookingHotels() {
  const city = bookingSearchCity.value.trim()
  if (!city) {
    bookingError.value = 'Enter a city or destination name.'
    bookingHotels.value = []
    return
  }

  bookingLoading.value = true
  bookingError.value = ''

  try {
    const destinationParams = new URLSearchParams({
      resource: 'destinations',
      query: city,
    })

    const destinationResponse = await fetch(
      `${API_BASE}/external/booking_proxy.php?${destinationParams.toString()}`
    )
    const destinationPayload = await destinationResponse.json()

    if (!destinationResponse.ok || destinationPayload.error) {
      throw new Error(
        destinationPayload?.details?.message ?? destinationPayload?.error ?? 'Destination lookup failed.'
      )
    }

    const destinations = normaliseDestinationList(destinationPayload.data ?? destinationPayload)
    const selectedDestination =
      destinations.find((item) => String(item.country)?.toLowerCase().includes('malaysia')) ?? destinations[0]

    if (!selectedDestination) {
      throw new Error(`No destinations found for "${city}".`)
    }

    const destId =
      selectedDestination.dest_id ??
      selectedDestination.destination_id ??
      selectedDestination.id ??
      selectedDestination.destId
    const destType =
      selectedDestination.dest_type ??
      selectedDestination.destination_type ??
      selectedDestination.type ??
      'city'

    if (!destId) {
      throw new Error('Destination identifier missing in RapidAPI response.')
    }

    const today = new Date()
    const checkIn = formatIsoDate(addDays(today, 7))
    const checkOut = formatIsoDate(addDays(today, 8))
    const searchType = String(destType || 'city').toUpperCase()

    const hotelParams = new URLSearchParams({
      resource: 'hotels',
      dest_id: String(destId),
      search_type: searchType,
      arrival_date: checkIn,
      departure_date: checkOut,
      adults: '2',
      room_qty: '1',
      currency_code: 'MYR',
      units: 'metric',
      languagecode: 'en-gb',
    })

    const hotelsResponse = await fetch(
      `${API_BASE}/external/booking_proxy.php?${hotelParams.toString()}`
    )
    const hotelsPayload = await hotelsResponse.json()

    if (!hotelsResponse.ok || hotelsPayload.error) {
      throw new Error(hotelsPayload?.details?.message ?? hotelsPayload?.error ?? 'Hotel search failed.')
    }

    const rawHotels = normaliseHotelList(hotelsPayload.data ?? hotelsPayload)
    const mappedHotels = rawHotels.map((item) => mapHotel(item)).filter((item) => item !== null).slice(0, 6)

    if (mappedHotels.length === 0) {
      bookingError.value = 'No hotel results were returned. Check your RapidAPI quota or parameters.'
      bookingHotels.value = []
    } else {
      bookingHotels.value = mappedHotels
      bookingLastUpdated.value = new Date()
    }
  } catch (error) {
    console.error('Booking.com fetch failed', error)
    bookingError.value =
      error instanceof Error ? error.message : 'Unexpected error while contacting Booking.com API.'
    bookingHotels.value = []
  } finally {
    bookingLoading.value = false
  }
}

function clearBookingError() {
  bookingError.value = ''
}

onMounted(() => {
  fetchBookingHotels()
})
</script>

<style scoped>
.booking-hotels-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
}

.booking-hotel-card {
  background: #fff;
  border: 1px solid rgba(0, 0, 0, 0.06);
  border-radius: 12px;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-height: 220px;
}

.booking-hotel-photo {
  width: 100%;
  height: 140px;
  overflow: hidden;
  border-radius: 10px;
}

.booking-hotel-photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.booking-hotel-name {
  font-size: 1.05rem;
  display: block;
}

.booking-hotel-address {
  font-size: 0.85rem;
  color: rgba(0, 0, 0, 0.55);
}

.booking-hotel-meta {
  font-size: 0.85rem;
  color: rgba(0, 0, 0, 0.8);
  display: flex;
  gap: 6px;
  align-items: center;
  flex-wrap: wrap;
}

.booking-rating {
  font-weight: 600;
  color: #f08a24;
}

.booking-hotel-price {
  font-weight: 600;
  font-size: 0.95rem;
}

.booking-price-suffix {
  font-size: 0.8rem;
  color: rgba(0, 0, 0, 0.65);
}

.booking-hotel-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 0;
  margin: 0;
  list-style: none;
}

.booking-hotel-tags li {
  font-size: 0.75rem;
  background: rgba(66, 184, 131, 0.12);
  border: 1px solid rgba(66, 184, 131, 0.24);
  border-radius: 999px;
  padding: 4px 10px;
}
</style>
