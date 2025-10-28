<script setup>
import { computed, h, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useMessage } from 'naive-ui'

const featuredCities = [
  // Peninsular Malaysia hubs + attractions
  { id: 'kuala-lumpur', name: 'Kuala Lumpur', state: 'Federal Territory', region: 'Peninsular Malaysia', latitude: 3.139, longitude: 101.6869, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'putrajaya', name: 'Putrajaya', state: 'Federal Territory', region: 'Peninsular Malaysia', latitude: 2.9264, longitude: 101.6964, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'shah-alam', name: 'Shah Alam', state: 'Selangor', region: 'Peninsular Malaysia', latitude: 3.0733, longitude: 101.5185, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'petaling-jaya', name: 'Petaling Jaya', state: 'Selangor', region: 'Peninsular Malaysia', latitude: 3.1073, longitude: 101.6067, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'subang-jaya', name: 'Subang Jaya', state: 'Selangor', region: 'Peninsular Malaysia', latitude: 3.043, longitude: 101.5815, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'george-town', name: 'George Town', state: 'Penang', region: 'Peninsular Malaysia', latitude: 5.4141, longitude: 100.3288, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'ipoh', name: 'Ipoh', state: 'Perak', region: 'Peninsular Malaysia', latitude: 4.5975, longitude: 101.0901, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'malacca', name: 'Malacca City', state: 'Melaka', region: 'Peninsular Malaysia', latitude: 2.1896, longitude: 102.2501, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'seremban', name: 'Seremban', state: 'Negeri Sembilan', region: 'Peninsular Malaysia', latitude: 2.7297, longitude: 101.9381, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'port-dickson', name: 'Port Dickson', state: 'Negeri Sembilan', region: 'Peninsular Malaysia', latitude: 2.5228, longitude: 101.7953, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'kuantan', name: 'Kuantan', state: 'Pahang', region: 'Peninsular Malaysia', latitude: 3.8168, longitude: 103.3317, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'kuala-terengganu', name: 'Kuala Terengganu', state: 'Terengganu', region: 'Peninsular Malaysia', latitude: 5.3294, longitude: 103.137, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'kota-bharu', name: 'Kota Bharu', state: 'Kelantan', region: 'Peninsular Malaysia', latitude: 6.1256, longitude: 102.2381, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'alor-setar', name: 'Alor Setar', state: 'Kedah', region: 'Peninsular Malaysia', latitude: 6.1264, longitude: 100.3675, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'johor-bahru', name: 'Johor Bahru', state: 'Johor', region: 'Peninsular Malaysia', latitude: 1.4927, longitude: 103.7414, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'langkawi', name: 'Langkawi', state: 'Kedah (Island)', region: 'Peninsular Malaysia', latitude: 6.35, longitude: 99.8, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'genting-highlands', name: 'Genting Highlands', state: 'Pahang/Selangor', region: 'Peninsular Malaysia', latitude: 3.4255, longitude: 101.7932, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'cameron-highlands', name: 'Cameron Highlands', state: 'Pahang', region: 'Peninsular Malaysia', latitude: 4.471, longitude: 101.3742, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'desaru', name: 'Desaru Coast', state: 'Johor (Coast)', region: 'Peninsular Malaysia', latitude: 1.5524, longitude: 104.3106, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'tioman-island', name: 'Tioman Island', state: 'Pahang (Island)', region: 'Peninsular Malaysia', latitude: 2.8176, longitude: 104.1602, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'redang-island', name: 'Redang Island', state: 'Terengganu (Island)', region: 'Peninsular Malaysia', latitude: 5.7833, longitude: 103.0167, timezone: 'Asia/Kuala_Lumpur' },
  { id: 'pangkor-island', name: 'Pangkor Island', state: 'Perak (Island)', region: 'Peninsular Malaysia', latitude: 4.228, longitude: 100.553, timezone: 'Asia/Kuala_Lumpur' },
  // East Malaysia highlights
  { id: 'kota-kinabalu', name: 'Kota Kinabalu', state: 'Sabah', region: 'East Malaysia', latitude: 5.9804, longitude: 116.0735, timezone: 'Asia/Kuching' },
  { id: 'sandakan', name: 'Sandakan', state: 'Sabah', region: 'East Malaysia', latitude: 5.845, longitude: 118.057, timezone: 'Asia/Kuching' },
  { id: 'tawau', name: 'Tawau', state: 'Sabah', region: 'East Malaysia', latitude: 4.2447, longitude: 117.891, timezone: 'Asia/Kuching' },
  { id: 'kundasang', name: 'Kundasang', state: 'Sabah', region: 'East Malaysia', latitude: 5.989, longitude: 116.565, timezone: 'Asia/Kuching' },
  { id: 'kuching', name: 'Kuching', state: 'Sarawak', region: 'East Malaysia', latitude: 1.5541, longitude: 110.3593, timezone: 'Asia/Kuching' },
  { id: 'miri', name: 'Miri', state: 'Sarawak', region: 'East Malaysia', latitude: 4.3999, longitude: 113.9914, timezone: 'Asia/Kuching' },
  { id: 'sibu', name: 'Sibu', state: 'Sarawak', region: 'East Malaysia', latitude: 2.2923, longitude: 111.825, timezone: 'Asia/Kuching' },
  { id: 'labuan', name: 'Labuan', state: 'Federal Territory', region: 'East Malaysia', latitude: 5.2831, longitude: 115.2308, timezone: 'Asia/Kuching' },
  { id: 'semporna', name: 'Semporna', state: 'Sabah', region: 'East Malaysia', latitude: 4.4818, longitude: 118.6112, timezone: 'Asia/Kuching' },
  { id: 'sipadan-island', name: 'Sipadan Island', state: 'Sabah (Island)', region: 'East Malaysia', latitude: 4.1083, longitude: 118.6283, timezone: 'Asia/Kuching' },
]

const featuredIds = new Set(featuredCities.map((city) => city.id))
const cityRegistry = ref({})
const savedCities = ref([])
const dynamicCities = ref([])

function registerCity(city) {
  if (!city?.id) return
  cityRegistry.value = { ...cityRegistry.value, [city.id]: city }
}

featuredCities.forEach(registerCity)

const searchQuery = ref('')
const searchLoading = ref(false)
const searchError = ref('')
let searchTimer = null

const selectedCityId = ref(featuredCities[0]?.id ?? '')
const loading = ref(false)
const error = ref('')
const lastUpdated = ref(null)
const forecast = ref([])

const message = useMessage()
const forecastCache = new Map()

const selectOptions = computed(() => {
  const groups = []

  if (searchQuery.value && (searchLoading.value || dynamicCities.value.length || searchError.value)) {
    const children =
      dynamicCities.value.length > 0
        ? dynamicCities.value.map((city) => ({
            label: city.name,
            value: city.id,
            city,
          }))
        : [
            {
              label: searchError.value || 'No matching places found (try another spelling)',
              value: '__no-results__',
              disabled: true,
            },
          ]
    groups.push({
      type: 'group',
      key: 'search',
      label: searchLoading.value ? 'Searching locations…' : `Search results${searchQuery.value ? ` for "${searchQuery.value}"` : ''}`,
      children,
    })
  }

  if (savedCities.value.length) {
    groups.push({
      type: 'group',
      key: 'saved',
      label: 'Saved locations',
      children: savedCities.value.map((city) => ({
        label: city.name,
        value: city.id,
        city,
      })),
    })
  }

  const featuredGrouped = featuredCities.reduce((acc, city) => {
    if (!acc[city.region]) acc[city.region] = []
    acc[city.region].push({
      label: city.name,
      value: city.id,
      city,
    })
    return acc
  }, /** @type {Record<string, Array<{ label: string; value: string; city: typeof featuredCities[number] }>>} */ ({}))

  Object.entries(featuredGrouped).forEach(([region, items]) => {
    groups.push({
      type: 'group',
      key: `featured-${region}`,
      label: region,
      children: items,
    })
  })

  return groups
})

const renderCityLabel = (option) => {
  if (!option.city) return option.label
  return h('div', { class: 'weather-city-option' }, [
    h('span', { class: 'weather-city-option__name' }, option.city.name),
    option.city.state ? h('span', { class: 'weather-city-option__meta' }, option.city.state) : null,
  ])
}

const renderSelectedCity = ({ option }) => {
  if (!option.city) return option.label
  return h('span', { class: 'weather-city-tag' }, [
    option.city.name,
    option.city.state ? h('span', { class: 'weather-city-tag__meta' }, option.city.state) : null,
  ])
}

const activeCity = computed(() => cityRegistry.value[selectedCityId.value] ?? featuredCities[0])

const lastUpdatedLabel = computed(() => {
  if (!lastUpdated.value) return ''
  return new Intl.DateTimeFormat('en-MY', {
    weekday: 'short',
    hour: '2-digit',
    minute: '2-digit',
  }).format(lastUpdated.value)
})

const dailyForecast = computed(() =>
  forecast.value.map((day) => {
    const date = new Date(day.date)
    const weekday = new Intl.DateTimeFormat('en-MY', { weekday: 'short' }).format(date)
    const dateLabel = new Intl.DateTimeFormat('en-MY', { day: 'numeric', month: 'short' }).format(date)
    const meta = getWeatherMeta(day.weatherCode)
    const isToday = date.toDateString() === new Date().toDateString()

    return {
      id: day.date,
      weekday,
      dateLabel,
      weatherLabel: meta.label,
      icon: meta.icon,
      maxTemp: day.maxTemp != null ? Math.round(day.maxTemp) : null,
      minTemp: day.minTemp != null ? Math.round(day.minTemp) : null,
      rainChance: day.precipitationChance,
      isToday,
    }
  }),
)

function getWeatherMeta(code) {
  if (code === 0) return { label: 'Clear sky', icon: 'ri-sun-line' }
  if ([1, 2].includes(code)) return { label: 'Partly cloudy', icon: 'ri-sun-cloudy-line' }
  if (code === 3) return { label: 'Overcast', icon: 'ri-cloudy-line' }
  if ([45, 48].includes(code)) return { label: 'Fog', icon: 'ri-foggy-line' }
  if ([51, 53, 55, 56, 57].includes(code)) return { label: 'Drizzle', icon: 'ri-drizzle-line' }
  if ([61, 63, 65].includes(code)) return { label: 'Rain', icon: 'ri-rainy-line' }
  if ([66, 67].includes(code)) return { label: 'Freezing rain', icon: 'ri-hail-line' }
  if ([71, 73, 75, 77].includes(code)) return { label: 'Snow', icon: 'ri-snowy-line' }
  if ([80, 81, 82].includes(code)) return { label: 'Rain showers', icon: 'ri-showers-line' }
  if ([85, 86].includes(code)) return { label: 'Snow showers', icon: 'ri-snowy-line' }
  if (code === 95) return { label: 'Thunderstorm', icon: 'ri-thunderstorms-line' }
  if ([96, 99].includes(code)) return { label: 'Severe storm', icon: 'ri-thunderstorms-fill' }
  return { label: 'Unknown', icon: 'ri-question-line' }
}

async function fetchForecast(city, { force } = { force: false }) {
  if (!city) return false

  if (!force && forecastCache.has(city.id)) {
    const cached = forecastCache.get(city.id)
    forecast.value = cached.data
    lastUpdated.value = cached.updatedAt
    return true
  }

  loading.value = true
  error.value = ''
  let success = false

  try {
    const params = new URLSearchParams({
      latitude: city.latitude,
      longitude: city.longitude,
      daily: 'weathercode,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
      timezone: city.timezone ?? 'auto',
    })

    const response = await fetch(`https://api.open-meteo.com/v1/forecast?${params.toString()}`)
    if (!response.ok) {
      throw new Error('Unable to load forecast right now.')
    }
    const data = await response.json()
    const days = Array.isArray(data.daily?.time)
      ? data.daily.time.map((date, index) => ({
          date,
          weatherCode: data.daily.weathercode?.[index] ?? null,
          maxTemp: data.daily.temperature_2m_max?.[index] ?? null,
          minTemp: data.daily.temperature_2m_min?.[index] ?? null,
          precipitationChance: data.daily.precipitation_probability_max?.[index] ?? null,
        }))
      : []

    forecast.value = days.slice(0, 7)
    lastUpdated.value = new Date()
    forecastCache.set(city.id, { data: forecast.value, updatedAt: lastUpdated.value })
    success = true
  } catch (err) {
    console.error(err)
    error.value = err instanceof Error ? err.message : 'Something went wrong while loading the forecast.'
  } finally {
    loading.value = false
  }

  return success
}

function handleRefresh() {
  const city = activeCity.value
  if (!city) return
  fetchForecast(city, { force: true }).then((ok) => {
    if (ok) {
      message.success('Forecast refreshed')
    } else if (error.value) {
      message.error(error.value)
    }
  })
}

function handleSearch(query) {
  searchQuery.value = query.trim()
  searchError.value = ''

  if (searchTimer) {
    clearTimeout(searchTimer)
    searchTimer = null
  }

  if (!searchQuery.value) {
    dynamicCities.value = []
    searchLoading.value = false
    return
  }

  if (searchQuery.value.length < 2) {
    dynamicCities.value = []
    return
  }

  searchLoading.value = true
  searchTimer = setTimeout(async () => {
    try {
      const params = new URLSearchParams({
        name: searchQuery.value,
        count: '12',
        language: 'en',
        format: 'json',
        country: 'MY',
      })
      const response = await fetch(`https://geocoding-api.open-meteo.com/v1/search?${params.toString()}`)
      if (!response.ok) {
        throw new Error('Unable to search for locations right now.')
      }
      const data = await response.json()
      const results = Array.isArray(data.results) ? data.results : []
      dynamicCities.value = results.map((place) => {
        const id = `geo-${place.id}`
        const isEastMalaysia =
          place.admin1?.includes('Sabah') ||
          place.admin1?.includes('Sarawak') ||
          place.timezone?.includes('Kuching') ||
          place.timezone === 'Asia/Kuching'
        const city = {
          id,
          name: place.name,
          state: place.admin2 || place.admin1 || '',
          region: isEastMalaysia ? 'East Malaysia' : 'Peninsular Malaysia',
          latitude: place.latitude,
          longitude: place.longitude,
          timezone: place.timezone || (isEastMalaysia ? 'Asia/Kuching' : 'Asia/Kuala_Lumpur'),
        }
        registerCity(city)
        return city
      })
      if (!results.length) {
        searchError.value = 'No matching places found (try another spelling)'
      }
    } catch (err) {
      console.error(err)
      dynamicCities.value = []
      searchError.value = err instanceof Error ? err.message : 'Search failed'
    } finally {
      searchLoading.value = false
    }
  }, 400)
}

watch(
  selectedCityId,
  () => {
    const city = activeCity.value
    if (!city) return

    if (!featuredIds.has(city.id) && !savedCities.value.some((saved) => saved.id === city.id)) {
      savedCities.value = [city, ...savedCities.value].slice(0, 8)
    }

    fetchForecast(city)
  },
  { immediate: true },
)

onMounted(() => {
  if (!featuredCities.length) {
    error.value = 'No cities configured for weather lookups.'
  }
})

onBeforeUnmount(() => {
  if (searchTimer) {
    clearTimeout(searchTimer)
  }
})
</script>

<template>
  <n-card title="Weather outlook" :segmented="{ content: true, footer: true }">
    <n-space justify="space-between" align="center">
      <div>
        <div style="font-weight: 600;">{{ activeCity?.name ?? 'Select a city' }}</div>
        <n-text depth="3">
          <template v-if="lastUpdatedLabel">Updated {{ lastUpdatedLabel }}</template>
          <template v-else>Daily highs, lows, and rain chances for Malaysia's key hubs.</template>
        </n-text>
      </div>
      <n-space size="small" align="center">
        <n-select
          v-model:value="selectedCityId"
          :options="selectOptions"
          size="small"
          style="width: 120px;"
          filterable
          remote
          :loading="searchLoading"
          :render-label="renderCityLabel"
          :render-tag="renderSelectedCity"
          @search="handleSearch"
          :consistent-menu-width="false"
        />
        <n-button size="small" tertiary :loading="loading" @click="handleRefresh">Refresh</n-button>
      </n-space>
    </n-space>

    <n-alert v-if="error" type="error" style="margin-top: 16px;">
      {{ error }}
    </n-alert>

    <n-text v-else-if="loading" depth="3" style="display: block; margin-top: 16px;">
      Loading the latest forecast…
    </n-text>

    <template v-else>
      <template v-if="dailyForecast.length">
        <div class="forecast-row">
          <div
            v-for="day in dailyForecast"
            :key="day.id"
            class="forecast-day"
            :class="{ 'forecast-day--today': day.isToday }"
          >
            <span v-if="day.isToday" class="forecast-day__badge">Today</span>
            <div class="forecast-day__header">
              <span class="forecast-day__weekday">{{ day.weekday }}</span>
              <n-text depth="3">{{ day.dateLabel }}</n-text>
            </div>
            <div class="forecast-day__icon">
              <n-icon size="24">
                <i :class="day.icon" />
              </n-icon>
            </div>
            <div class="forecast-day__temps">
              <span class="forecast-day__temp-max">{{ day.maxTemp != null ? `${day.maxTemp}°` : '--' }}</span>
              <span class="forecast-day__temp-min">{{ day.minTemp != null ? `${day.minTemp}°` : '--' }}</span>
            </div>
            <n-text depth="3" style="text-align: center;">{{ day.weatherLabel }}</n-text>
            <n-tag size="small" type="info" :bordered="false">
              Rain {{ day.rainChance ?? 0 }}%
            </n-tag>
          </div>
        </div>
      </template>
      <template v-else>
        <n-empty description="Forecast data will appear once available." style="margin-top: 16px;" />
      </template>
    </template>
  </n-card>
</template>

<style scoped>
.forecast-row {
  display: flex;
  gap: 14px;
  margin-top: 16px;
  overflow-x: auto;
  padding-bottom: 10px;
  scroll-snap-type: x mandatory;
}

.forecast-row::-webkit-scrollbar {
  height: 6px;
}

.forecast-row::-webkit-scrollbar-thumb {
  background: rgba(0, 0, 0, 0.15);
  border-radius: 3px;
}

.forecast-day {
  flex: 0 0 150px;
  background: #f5f8f6;
  border-radius: 20px;
  padding: 24px 14px 20px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: center;
  scroll-snap-align: start;
  position: relative;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.forecast-day:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 20px rgba(66, 184, 131, 0.12);
}

.forecast-day--today {
  background: linear-gradient(135deg, rgba(66, 184, 131, 0.12), rgba(66, 184, 131, 0.05));
  border: 1px solid rgba(66, 184, 131, 0.25);
}

.forecast-day__badge {
  position: absolute;
  left: 50%;
  transform: translateX(-50%);
  background: #42b883;
  color: #fff;
  font-size: 0.65rem;
  font-weight: 600;
  padding: 2px 10px;
  border-radius: 999px;
  letter-spacing: 0.02em;
  box-shadow: 0 4px 10px rgba(66, 184, 131, 0.2);
}

.forecast-day__header {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}

.forecast-day__weekday {
  font-weight: 600;
  font-size: 0.95rem;
}

.forecast-day__icon {
  color: #1aa67d;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
}

.forecast-day__temps {
  display: flex;
  gap: 8px;
  align-items: baseline;
  font-weight: 600;
}

.forecast-day__temp-max {
  font-size: 1.35rem;
}

.forecast-day__temp-min {
  color: rgba(0, 0, 0, 0.45);
}

:deep(.weather-city-option) {
  display: flex;
  flex-direction: column;
  line-height: 1.25;
}

:deep(.weather-city-option__name) {
  font-weight: 600;
}

:deep(.weather-city-option__meta) {
  font-size: 0.75rem;
  color: rgba(0, 0, 0, 0.55);
}

:deep(.weather-city-tag) {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
}

:deep(.weather-city-tag__meta) {
  font-size: 0.8rem;
  color: rgba(0, 0, 0, 0.45);
}
</style>
