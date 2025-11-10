<script setup>
import { Loader } from '@googlemaps/js-api-loader'
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'

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

const mapEl = ref(null)
const mapInstance = ref(null)
const polylines = ref([])
const markers = ref([])
let loader = null

const hasApiKey = computed(() => typeof props.apiKey === 'string' && props.apiKey.trim().length > 0)

onMounted(async () => {
  if (!hasApiKey.value || !mapEl.value) {
    return
  }
  loader = new Loader({
    apiKey: props.apiKey,
    version: 'weekly',
    libraries: ['places'],
  })
  await loader.load()
  mapInstance.value = new google.maps.Map(mapEl.value, {
    center: { lat: 4.2105, lng: 101.9758 },
    zoom: 6,
    mapTypeControl: false,
    streetViewControl: false,
    fullscreenControl: false,
  })
  redrawMap()
})

onBeforeUnmount(() => {
  clearMapArtifacts()
})

watch(
  () => [props.origin, props.destination, props.days],
  () => {
    redrawMap()
  },
  { deep: true },
)

function redrawMap() {
  if (!mapInstance.value) {
    return
  }
  clearMapArtifacts()
  const bounds = new google.maps.LatLngBounds()

  const addMarker = (point, label, iconOptions = {}) => {
    if (!point || typeof point.lat !== 'number' || typeof point.lng !== 'number') {
      return
    }
    const marker = new google.maps.Marker({
      position: point,
      map: mapInstance.value,
      label,
      icon: iconOptions.icon,
    })
    markers.value.push(marker)
    bounds.extend(point)
  }

  if (props.origin?.lat && props.origin?.lng) {
    addMarker({ lat: props.origin.lat, lng: props.origin.lng }, 'A')
  }

  if (props.destination?.lat && props.destination?.lng) {
    addMarker({ lat: props.destination.lat, lng: props.destination.lng }, 'B')
  }

  const dayColors = ['#2563eb', '#16a34a', '#f97316', '#a855f7', '#dc2626']

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
            fillColor: dayColors[index % dayColors.length],
            fillOpacity: 0.9,
            strokeWeight: 0,
          },
        })
      }
    })
    if (dayPath.length > 1) {
      const polyline = new google.maps.Polyline({
        path: dayPath,
        strokeColor: dayColors[index % dayColors.length],
        strokeOpacity: 0.85,
        strokeWeight: 4,
        map: mapInstance.value,
      })
      polylines.value.push(polyline)
      dayPath.forEach((pt) => bounds.extend(pt))
    }
  })

  if (!bounds.isEmpty()) {
    mapInstance.value.fitBounds(bounds, 64)
  }
}

function clearMapArtifacts() {
  polylines.value.forEach((poly) => poly.setMap(null))
  markers.value.forEach((marker) => marker.setMap(null))
  polylines.value = []
  markers.value = []
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
        <n-tag v-if="!hasApiKey" type="warning" size="small">Map key missing</n-tag>
      </div>
    </template>
    <div class="map-container">
      <div v-if="hasApiKey" ref="mapEl" class="map-element" />
      <n-empty v-else description="Provide VITE_GOOGLE_MAPS_KEY to enable maps." />
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
</style>
