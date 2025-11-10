<script setup>
import { Loader } from '@googlemaps/js-api-loader'
import { computed, nextTick, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import * as L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png'
import markerIcon from 'leaflet/dist/images/marker-icon.png'
import markerShadow from 'leaflet/dist/images/marker-shadow.png'

L.Icon.Default.mergeOptions({
  iconRetinaUrl: markerIcon2x,
  iconUrl: markerIcon,
  shadowUrl: markerShadow,
})

const props = defineProps({
  apiKey: {
    type: String,
    default: '',
  },
  origin: {
    type: Object,
    default: () => null,
  },
  destination: {
    type: Object,
    default: () => null,
  },
  days: {
    type: Array,
    default: () => [],
  },
  height: {
    type: String,
    default: '360px',
  },
})

const hasApiKey = computed(() => typeof props.apiKey === 'string' && props.apiKey.trim().length > 0)
const DEFAULT_CENTER = { lat: 4.2105, lng: 101.9758 }
const DAY_COLORS = ['#2563eb', '#16a34a', '#f97316', '#a855f7', '#dc2626', '#0ea5e9']

const googleMapEl = ref(null)
const googleInstance = ref(null)
const googleMarkers = ref([])
const googlePolylines = ref([])
let googleLoader = null

const leafletEl = ref(null)
const leafletInstance = ref(null)
const leafletMarkers = ref([])
const leafletPolylines = ref([])

onMounted(() => {
  if (hasApiKey.value) {
    initGoogleMap()
  } else {
    initLeafletMap()
  }
})

onBeforeUnmount(() => {
  destroyGoogleMap()
  destroyLeafletMap()
})

watch(
  () => [props.origin, props.destination, props.days],
  () => {
    if (hasApiKey.value) {
      redrawGoogleMap()
    } else {
      redrawLeafletMap()
    }
  },
  { deep: true },
)

watch(hasApiKey, (useGoogle) => {
  if (useGoogle) {
    destroyLeafletMap()
    nextTick(() => initGoogleMap())
  } else {
    destroyGoogleMap()
    nextTick(() => initLeafletMap())
  }
})

async function initGoogleMap() {
  if (!hasApiKey.value || !googleMapEl.value) return
  googleLoader = new Loader({
    apiKey: props.apiKey,
    version: 'weekly',
    libraries: ['places'],
  })
  await googleLoader.load()
  googleInstance.value = new google.maps.Map(googleMapEl.value, {
    center: DEFAULT_CENTER,
    zoom: 6,
    mapTypeControl: false,
    streetViewControl: false,
    fullscreenControl: false,
  })
  redrawGoogleMap()
}

function destroyGoogleMap() {
  clearGoogleArtifacts()
  if (googleInstance.value) {
    googleInstance.value = null
  }
}

function redrawGoogleMap() {
  if (!googleInstance.value) return
  clearGoogleArtifacts()
  const bounds = new google.maps.LatLngBounds()

  const addMarker = (point, label, iconOptions = {}) => {
    if (!point || typeof point.lat !== 'number' || typeof point.lng !== 'number') {
      return
    }
    const marker = new google.maps.Marker({
      position: point,
      map: googleInstance.value,
      label,
      icon: iconOptions.icon,
    })
    googleMarkers.value.push(marker)
    bounds.extend(point)
  }

  if (props.origin?.lat && props.origin?.lng) {
    addMarker({ lat: props.origin.lat, lng: props.origin.lng }, 'A')
  }

  if (props.destination?.lat && props.destination?.lng) {
    addMarker({ lat: props.destination.lat, lng: props.destination.lng }, 'B')
  }

  props.days.forEach((day, index) => {
    if (!Array.isArray(day.items) || day.items.length === 0) {
      return
    }
    const dayPath = []
    day.items.forEach((item) => {
      if (typeof item.latitude === 'number' && typeof item.longitude === 'number') {
        const point = { lat: item.latitude, lng: item.longitude }
        dayPath.push(point)
        addMarker(point, `${index + 1}`, {
          icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 6,
            fillColor: DAY_COLORS[index % DAY_COLORS.length],
            fillOpacity: 0.9,
            strokeWeight: 0,
          },
        })
      }
    })
    if (dayPath.length > 1) {
      const polyline = new google.maps.Polyline({
        path: dayPath,
        strokeColor: DAY_COLORS[index % DAY_COLORS.length],
        strokeOpacity: 0.85,
        strokeWeight: 4,
        map: googleInstance.value,
      })
      googlePolylines.value.push(polyline)
      dayPath.forEach((pt) => bounds.extend(pt))
    }
  })

  if (!bounds.isEmpty()) {
    googleInstance.value.fitBounds(bounds, 64)
  } else {
    googleInstance.value.setCenter(DEFAULT_CENTER)
    googleInstance.value.setZoom(6)
  }
}

function clearGoogleArtifacts() {
  googlePolylines.value.forEach((poly) => poly.setMap(null))
  googleMarkers.value.forEach((marker) => marker.setMap(null))
  googlePolylines.value = []
  googleMarkers.value = []
}

function initLeafletMap() {
  if (!leafletEl.value || leafletInstance.value) return
  leafletInstance.value = L.map(leafletEl.value, {
    zoomControl: true,
    attributionControl: true,
  })
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; OpenStreetMap contributors',
  }).addTo(leafletInstance.value)
  redrawLeafletMap()
}

function destroyLeafletMap() {
  if (leafletInstance.value) {
    leafletInstance.value.remove()
    leafletInstance.value = null
  }
  leafletMarkers.value = []
  leafletPolylines.value = []
}

function redrawLeafletMap() {
  if (!leafletInstance.value) return
  leafletMarkers.value.forEach((marker) => marker.remove())
  leafletPolylines.value.forEach((poly) => poly.remove())
  leafletMarkers.value = []
  leafletPolylines.value = []

  const bounds = L.latLngBounds([])
  const addMarker = (point, label, options = {}) => {
    if (!point || typeof point.lat !== 'number' || typeof point.lng !== 'number') return
    const marker = L.marker(point, options).addTo(leafletInstance.value)
    if (label) {
      marker.bindTooltip(label, { permanent: true, direction: 'top', offset: [0, -4] })
    }
    leafletMarkers.value.push(marker)
    bounds.extend(point)
  }

  if (props.origin?.lat && props.origin?.lng) {
    addMarker({ lat: props.origin.lat, lng: props.origin.lng }, 'A')
  }
  if (props.destination?.lat && props.destination?.lng) {
    addMarker({ lat: props.destination.lat, lng: props.destination.lng }, 'B')
  }

  props.days.forEach((day, index) => {
    if (!Array.isArray(day.items) || day.items.length === 0) return
    const dayPath = []
    const color = DAY_COLORS[index % DAY_COLORS.length]
    day.items.forEach((item) => {
      if (typeof item.latitude === 'number' && typeof item.longitude === 'number') {
        const point = { lat: item.latitude, lng: item.longitude }
        dayPath.push(point)
        const circle = L.circleMarker(point, {
          radius: 6,
          color,
          fillColor: color,
          fillOpacity: 0.9,
          weight: 1,
        }).addTo(leafletInstance.value)
        circle.bindTooltip(`Day ${index + 1}`, { permanent: false })
        leafletMarkers.value.push(circle)
        bounds.extend(point)
      }
    })
    if (dayPath.length > 1) {
      const polyline = L.polyline(dayPath, {
        color,
        opacity: 0.85,
        weight: 4,
      }).addTo(leafletInstance.value)
      leafletPolylines.value.push(polyline)
    }
  })

  if (bounds.isValid()) {
    leafletInstance.value.fitBounds(bounds, { padding: [28, 28] })
  } else {
    leafletInstance.value.setView(DEFAULT_CENTER, 6)
  }
}
</script>

<template>
  <n-card size="small" class="trip-map-card" :style="{ height }">
    <template #header>
      <div class="trip-map-card__title">
        <div>
          <div class="title">Malaysia map preview</div>
          <n-text depth="3">Visualise your journey and daily stops.</n-text>
        </div>
        <n-tag v-if="hasApiKey" type="primary" size="small">Google Maps</n-tag>
        <n-tag v-else type="info" size="small">OpenStreetMap</n-tag>
      </div>
    </template>
    <div class="map-container">
      <div v-if="hasApiKey" ref="googleMapEl" class="map-element" />
      <div v-else ref="leafletEl" class="map-element" />
    </div>
  </n-card>
</template>

<style scoped>
.trip-map-card {
  height: 100%;
}

.trip-map-card__title {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.title {
  font-weight: 600;
  font-size: 1rem;
}

.map-container {
  width: 100%;
  height: calc(100% - 8px);
  position: relative;
}

.map-element {
  position: absolute;
  inset: 0;
}

.map-element :deep(.leaflet-container) {
  width: 100%;
  height: 100%;
  border-radius: 12px;
}
</style>
