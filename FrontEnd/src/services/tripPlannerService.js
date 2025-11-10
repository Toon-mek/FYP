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
