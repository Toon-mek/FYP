const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const TRIP_ENDPOINT = `${API_BASE}/traveler/itineraries.php`
const GOOGLE_PLACES_ENDPOINT = `${API_BASE}/external/google_places.php`
const GOOGLE_ROUTES_ENDPOINT = `${API_BASE}/external/google_routes.php`
const MAPS_STATIC_ENDPOINT = `${API_BASE}/external/maps_static.php`
const BOOKING_PROXY_ENDPOINT = `${API_BASE}/external/booking_proxy.php`
const AI_ENDPOINT = `${API_BASE}/traveler/itinerary_ai.php`
const SAVED_PLACES_ENDPOINT = `${API_BASE}/traveler/saved_places.php`

async function handleResponse(response) {
  const contentType = response.headers.get('content-type') || ''
  const expectsJson = contentType.includes('application/json')
  let bodyText = ''
  let payload = null

  try {
    bodyText = await response.text()
  } catch {
    bodyText = ''
  }

  if (expectsJson && bodyText) {
    try {
      payload = JSON.parse(bodyText)
    } catch (err) {
      const parseError = new Error(
        `Unexpected response format: ${bodyText.slice(0, 120)}${bodyText.length > 120 ? '…' : ''}`,
      )
      parseError.status = response.status
      parseError.raw = bodyText
      throw parseError
    }
  }

  if (!response.ok) {
    const message =
      payload?.error ||
      payload?.message ||
      (bodyText ? bodyText.slice(0, 120) : `Trip planner request failed (${response.status})`)
    const error = new Error(message)
    error.payload = payload
    error.status = response.status
    error.raw = bodyText
    throw error
  }

  if (payload !== null) {
    return payload
  }

  if (bodyText) {
    return { raw: bodyText }
  }

  return {}
}

export async function fetchItineraries(travelerId) {
  if (!travelerId) {
    return { itineraries: [] }
  }
  const url = `${TRIP_ENDPOINT}?travelerId=${encodeURIComponent(travelerId)}`
  const response = await fetch(url, { method: 'GET' })
  return handleResponse(response)
}

export async function fetchItinerary(travelerId, itineraryId) {
  if (!travelerId || !itineraryId) {
    return { itinerary: null }
  }
  const url = `${TRIP_ENDPOINT}?travelerId=${encodeURIComponent(travelerId)}&itineraryId=${encodeURIComponent(itineraryId)}`
  const response = await fetch(url, { method: 'GET' })
  return handleResponse(response)
}

export async function createItinerary(payload) {
  const defaults = {
    origin: null,
    destination: null,
    summary: null,
    aiPlan: null,
    metadata: null,
    totalDays: payload.startDate && payload.endDate ? calculateDuration(payload.startDate, payload.endDate) : null,
    totalBudget: payload.totalBudget ?? null,
  }
  const response = await fetch(TRIP_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...defaults, ...payload }),
  })
  return handleResponse(response)
}

export async function updateItinerary(payload) {
  const defaults = {
    totalDays: payload.startDate && payload.endDate ? calculateDuration(payload.startDate, payload.endDate) : null,
    totalBudget: payload.totalBudget ?? null,
  }
  const response = await fetch(TRIP_ENDPOINT, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...defaults, ...payload }),
  })
  return handleResponse(response)
}

export async function deleteItinerary(travelerId, itineraryId) {
  const url = `${TRIP_ENDPOINT}?travelerId=${encodeURIComponent(travelerId)}&itineraryId=${encodeURIComponent(itineraryId)}`
  const response = await fetch(url, { method: 'DELETE' })
  return handleResponse(response)
}

export async function searchPlacesAutocomplete(
  query,
  { sessionToken, components, region, language, types } = {},
) {
  if (!query) return { data: [] }
  const params = new URLSearchParams({
    action: 'autocomplete',
    input: query,
  })
  if (sessionToken) {
    params.set('sessiontoken', sessionToken)
  }
  if (components) params.set('components', components)
  if (region) params.set('region', region)
  if (language) params.set('language', language)
  if (types) params.set('types', types)
  const response = await fetch(`${GOOGLE_PLACES_ENDPOINT}?${params.toString()}`)
  return handleResponse(response)
}

export async function fetchPlaceDetails(placeId, { sessionToken, fields } = {}) {
  if (!placeId) return { data: null }
  const params = new URLSearchParams({
    action: 'details',
    placeId,
  })
  if (sessionToken) {
    params.set('sessiontoken', sessionToken)
  }
  if (fields) {
    params.set('fields', fields)
  }
  const response = await fetch(`${GOOGLE_PLACES_ENDPOINT}?${params.toString()}`)
  return handleResponse(response)
}

export async function generateAiItinerary(preferences) {
  const response = await fetch(AI_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(preferences),
  })
  return handleResponse(response)
}

function calculateDuration(startDate, endDate) {
  const start = new Date(startDate)
  const end = new Date(endDate)
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return null
  return Math.max(1, Math.floor((end.getTime() - start.getTime()) / (1000 * 60 * 60 * 24)) + 1)
}

export function buildStaticMapUrl({ center = '4.2105,101.9758', zoom = 5, markers = [], size = '640x640' } = {}) {
  const params = new URLSearchParams({
    center,
    zoom: String(zoom),
    size,
  })
  markers.forEach((marker) => {
    params.append('markers', marker)
  })
  params.set('maptype', 'roadmap')
  return `${MAPS_STATIC_ENDPOINT}?${params.toString()}`
}

export async function reverseGeocode(lat, lng) {
  const params = new URLSearchParams({
    action: 'reverse_geocode',
    lat: String(lat),
    lng: String(lng),
  })
  const response = await fetch(`${GOOGLE_PLACES_ENDPOINT}?${params.toString()}`)
  return handleResponse(response)
}

export async function fetchTravelInsights({ origin, destination, mode = 'driving' } = {}) {
  if (!origin || !destination) {
    throw new Error('Origin and destination coordinates are required for travel insights.')
  }
  const originLat = Number(origin.lat ?? origin.latitude)
  const originLng = Number(origin.lng ?? origin.longitude)
  const destinationLat = Number(destination.lat ?? destination.latitude)
  const destinationLng = Number(destination.lng ?? destination.longitude)
  if (
    !Number.isFinite(originLat) ||
    !Number.isFinite(originLng) ||
    !Number.isFinite(destinationLat) ||
    !Number.isFinite(destinationLng)
  ) {
    throw new Error('Valid latitude and longitude values are required for travel insights.')
  }
  const params = new URLSearchParams({
    action: 'travel_insights',
    mode,
    originLat: String(originLat),
    originLng: String(originLng),
    destinationLat: String(destinationLat),
    destinationLng: String(destinationLng),
  })
  const response = await fetch(`${GOOGLE_ROUTES_ENDPOINT}?${params.toString()}`)
  return handleResponse(response)
}

export async function searchPlacesByText({
  query,
  location,
  lat,
  lng,
  radius,
  type,
  language = 'en',
  region = 'MY',
  pagetoken,
  opennow,
  minprice,
  maxprice,
} = {}) {
  if (!query) {
    throw new Error('query is required for text search')
  }
  const params = new URLSearchParams({
    action: 'textsearch',
    query,
    language,
    region,
  })
  if (pagetoken) {
    params.set('pagetoken', pagetoken)
  } else {
    const loc = location || (lat != null && lng != null ? `${lat},${lng}` : null)
    if (loc) params.set('location', loc)
    if (radius) params.set('radius', String(radius))
    if (type) params.set('type', type)
    if (typeof opennow === 'boolean') params.set('opennow', String(opennow))
    if (Number.isFinite(minprice)) params.set('minprice', String(minprice))
    if (Number.isFinite(maxprice)) params.set('maxprice', String(maxprice))
  }
  const response = await fetch(`${GOOGLE_PLACES_ENDPOINT}?${params.toString()}`)
  return handleResponse(response)
}

export async function searchPlacesNearby({
  location,
  lat,
  lng,
  radius,
  keyword,
  type,
  language = 'en',
  pagetoken,
  opennow,
  rankby,
} = {}) {
  const loc = location || (lat != null && lng != null ? `${lat},${lng}` : null)
  if (!loc) {
    throw new Error('location or lat/lng is required for nearby search')
  }
  const params = new URLSearchParams({
    action: 'nearbysearch',
    location: loc,
    language,
  })
  if (radius) params.set('radius', String(radius))
  if (keyword) params.set('keyword', keyword)
  if (type) params.set('type', type)
  if (typeof opennow === 'boolean') params.set('opennow', String(opennow))
  if (rankby === 'distance') params.set('rankby', 'distance')
  if (pagetoken) params.set('pagetoken', pagetoken)
  const response = await fetch(`${GOOGLE_PLACES_ENDPOINT}?${params.toString()}`)
  return handleResponse(response)
}

export function buildPlacePhotoUrl(photoReference, { maxWidth = 600, maxHeight } = {}) {
  if (!photoReference) return ''
  const params = new URLSearchParams({
    action: 'photo',
    maxwidth: String(maxWidth),
  })
  if (photoReference.startsWith('places/')) {
    params.set('name', photoReference)
  } else {
    params.set('photoRef', photoReference)
  }
  if (maxHeight) {
    params.set('maxheight', String(maxHeight))
  }
  return `${GOOGLE_PLACES_ENDPOINT}?${params.toString()}`
}

export async function fetchHotelsByCoordinates({
  lat,
  lng,
  arrivalDate,
  departureDate,
  adults = 2,
  rooms = 1,
  locale = 'en-gb',
  currency = 'MYR',
  orderBy = 'popularity',
  extra = {},
}) {
  if (typeof lat !== 'number' || typeof lng !== 'number') {
    throw new Error('Latitude and longitude are required to fetch hotels.')
  }
  const params = new URLSearchParams({
    resource: 'hotels-by-coordinates',
    latitude: String(lat),
    longitude: String(lng),
    arrival_date: arrivalDate,
    departure_date: departureDate,
    checkin_date: arrivalDate,
    checkout_date: departureDate,
    adults_number: String(adults),
    room_qty: String(rooms),
    locale,
    currency,
    order_by: orderBy,
  })
  Object.entries(extra || {}).forEach(([key, value]) => {
    if (value === undefined || value === null) return
    if (Array.isArray(value)) {
      value.forEach((entry) => params.append(key, entry))
    } else {
      params.set(key, String(value))
    }
  })
  const response = await fetch(`${BOOKING_PROXY_ENDPOINT}?${params.toString()}`)
  return handleResponse(response)
}

export async function searchBookingAttractionLocations({ query, latitude, longitude, radius = 50000 } = {}) {
  const params = new URLSearchParams({ resource: 'attractions-location' })
  if (query) params.set('query', query)
  if (Number.isFinite(latitude)) params.set('latitude', String(latitude))
  if (Number.isFinite(longitude)) params.set('longitude', String(longitude))
  if (Number.isFinite(radius)) params.set('radius', String(radius))
  const response = await fetch(`${BOOKING_PROXY_ENDPOINT}?${params.toString()}`)
  return handleResponse(response)
}

export async function searchBookingAttractions({
  id,
  page = 1,
  orderBy = 'trending',
  currencyCode = 'MYR',
  languageCode = 'en-gb',
  typeFilters = [],
  priceFilters = [],
  ufiFilters = [],
  ...extra
} = {}) {
  if (!id) {
    throw new Error('Booking attractions search requires an id parameter.')
  }
  const params = new URLSearchParams({
    resource: 'attractions-search',
    id: String(id),
    page: String(page),
    currency_code: currencyCode,
    languagecode: languageCode,
  })
  params.set('orderBy', orderBy)
  params.set('order_by', orderBy)
  const appendCsv = (key, value) => {
    const list = Array.isArray(value) ? value : [value]
    const entries = list
      .map((entry) => (typeof entry === 'string' ? entry.trim() : ''))
      .filter(Boolean)
    if (entries.length) {
      params.set(key, entries.join(','))
    }
  }
  appendCsv('typeFilters', typeFilters)
  appendCsv('priceFilters', priceFilters)
  appendCsv('ufiFilters', ufiFilters)
  Object.entries(extra || {}).forEach(([key, value]) => {
    if (value === undefined || value === null) {
      return
    }
    params.set(key, String(value))
  })
  const response = await fetch(`${BOOKING_PROXY_ENDPOINT}?${params.toString()}`)
  return handleResponse(response)
}

export async function fetchBookingHotelReviewScores(hotelIds, { languagecode = 'en-gb' } = {}) {
  const ids = normaliseBookingHotelIdList(hotelIds)
  if (!ids.length) {
    return new Map()
  }
  const results = new Map()
  await Promise.all(
    ids.map(async (hotelId) => {
      const params = new URLSearchParams({
        resource: 'hotel-review-scores',
        hotel_id: hotelId,
      })
      if (languagecode) {
        params.set('languagecode', languagecode)
      }
      try {
        const response = await fetch(`${BOOKING_PROXY_ENDPOINT}?${params.toString()}`)
        const payload = await handleResponse(response)
        const record = normaliseBookingReviewScore(payload)
        if (record) {
          results.set(record.hotelId || hotelId, record)
        }
      } catch (error) {
        console.warn(`Booking review score fetch failed for hotel ${hotelId}`, error)
      }
    }),
  )
  return results
}

export async function fetchBookingHotelPhotos(hotelIds, { limit = 4 } = {}) {
  const ids = normaliseBookingHotelIdList(hotelIds)
  if (!ids.length) {
    return new Map()
  }
  const results = new Map()
  await Promise.all(
    ids.map(async (hotelId) => {
      const params = new URLSearchParams({
        resource: 'hotel-photos',
        hotel_id: hotelId,
      })
      if (Number.isFinite(limit) && limit > 0) {
        params.set('limit', String(limit))
      }
      try {
        const response = await fetch(`${BOOKING_PROXY_ENDPOINT}?${params.toString()}`)
        const payload = await handleResponse(response)
        const photos = normaliseBookingPhotoList(payload)
        if (photos.length) {
          results.set(hotelId, photos.slice(0, limit > 0 ? limit : photos.length))
        }
      } catch (error) {
        console.warn(`Booking photo fetch failed for hotel ${hotelId}`, error)
      }
    }),
  )
  return results
}

function normaliseBookingHotelIdList(hotelIds) {
  const entries = Array.isArray(hotelIds) ? hotelIds : [hotelIds]
  const cleaned = entries
    .map((value) => {
      if (value == null) return ''
      if (typeof value === 'object') {
        if (typeof value.hotelId === 'string' && value.hotelId.trim()) {
          return value.hotelId.trim()
        }
        if (typeof value.metadata?.hotelId === 'string' && value.metadata.hotelId.trim()) {
          return value.metadata.hotelId.trim()
        }
        if (typeof value.id === 'string' && value.id.trim()) {
          return value.id.trim()
        }
      }
      const stringValue = String(value).trim()
      return stringValue
    })
    .filter(Boolean)
  return Array.from(new Set(cleaned))
}

function normaliseBookingReviewScore(payload) {
  const container = payload?.data ?? payload?.result ?? payload?.reviews ?? payload
  const candidates = extractBookingRecordCandidates(container)
  const record = candidates.find((entry) => entry && typeof entry === 'object')
  if (!record) {
    return null
  }
  const hotelId =
    record.hotel_id ??
    record.hotelId ??
    record.id ??
    record.property_id ??
    record.propertyId ??
    record.hotel ??
    null
  const scoreCandidate =
    record.review_score ??
    record.score ??
    record.average_score ??
    record.rating ??
    record.review_score_avg ??
    record.review?.score ??
    null
  const score = extractNumericScore(scoreCandidate)
  const countCandidate =
    record.review_nr ??
    record.review_count ??
    record.reviews_count ??
    record.number_of_reviews ??
    record.review_total ??
    record.count ??
    record.total ??
    record.review?.count ??
    record.review?.total ??
    null
  const count = extractInteger(countCandidate)
  const summary =
    record.review_score_word ??
    record.score_word ??
    record.review_summary ??
    record.summary ??
    record.review?.summary ??
    null
  if (!hotelId && score === null && count === null && !summary) {
    return null
  }
  return {
    hotelId: hotelId != null ? String(hotelId) : null,
    score,
    count,
    summary: typeof summary === 'string' && summary.trim() ? summary.trim() : null,
  }
}

function extractBookingRecordCandidates(raw) {
  if (!raw) {
    return []
  }
  if (Array.isArray(raw)) {
    return raw
  }
  if (typeof raw === 'object') {
    const nestedArrays = [raw.data, raw.result, raw.items, raw.list, raw.reviews, raw.review_scores].filter(Array.isArray)
    if (nestedArrays.length) {
      return nestedArrays[0]
    }
  }
  return [raw]
}

function normaliseBookingPhotoList(payload) {
  const container = payload?.data ?? payload?.result ?? payload?.photos ?? payload
  const list = []
  const pushUrl = (url) => {
    if (typeof url === 'string') {
      const trimmed = url.trim()
      if (trimmed) {
        list.push(trimmed)
      }
    }
  }
  const visitEntry = (entry) => {
    if (!entry || typeof entry !== 'object') {
      return
    }
    const candidates = [
      entry.url_original,
      entry.url_max,
      entry.url_max300,
      entry.url_max3000,
      entry.url_1440,
      entry.url_1280,
      entry.url_1024,
      entry.url_square60,
      entry.max_photo_url,
      entry.photo_url,
      entry.url,
      entry.image_url,
      entry.image?.url,
    ]
    candidates.forEach(pushUrl)
    if (Array.isArray(entry.urls)) {
      entry.urls.forEach(pushUrl)
    }
  }
  if (Array.isArray(container)) {
    container.forEach(visitEntry)
  } else if (container && typeof container === 'object') {
    if (Array.isArray(container.photos)) {
      container.photos.forEach(visitEntry)
    } else if (Array.isArray(container.data)) {
      container.data.forEach(visitEntry)
    } else if (Array.isArray(container.result)) {
      container.result.forEach(visitEntry)
    } else {
      visitEntry(container)
    }
  }
  return list
}

function extractNumericScore(value) {
  if (value == null || value === '') {
    return null
  }
  const number = Number(value)
  if (Number.isFinite(number)) {
    return Math.round(number * 10) / 10
  }
  if (typeof value === 'string') {
    const cleaned = Number(value.replace(/[^0-9.]/g, ''))
    if (Number.isFinite(cleaned)) {
      return Math.round(cleaned * 10) / 10
    }
  }
  return null
}

function extractInteger(value) {
  if (value == null || value === '') {
    return null
  }
  if (Number.isInteger(value)) {
    return value
  }
  const number = Number(value)
  if (Number.isFinite(number)) {
    return Math.round(number)
  }
  if (typeof value === 'string') {
    const cleaned = Number(value.replace(/[^0-9]/g, ''))
    if (Number.isFinite(cleaned)) {
      return Math.round(cleaned)
    }
  }
  return null
}

export async function fetchSavedPlacePackages(travelerId) {
  if (!travelerId) {
    return { packages: [] }
  }
  const url = `${SAVED_PLACES_ENDPOINT}?travelerId=${encodeURIComponent(travelerId)}`
  const response = await fetch(url, { method: 'GET' })
  return handleResponse(response)
}

export async function savePlacesPackage(payload) {
  const response = await fetch(SAVED_PLACES_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  return handleResponse(response)
}

export async function deleteSavedPlacePackage(travelerId, packageId) {
  const params = new URLSearchParams({
    travelerId: String(travelerId),
    packageId: String(packageId),
  })
  const response = await fetch(`${SAVED_PLACES_ENDPOINT}?${params.toString()}`, { method: 'DELETE' })
  return handleResponse(response)
}
