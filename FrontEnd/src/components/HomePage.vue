<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import NewsletterSignup from '@/components/home/NewsletterSignup.vue'
import { buildPlacePhotoUrl, buildStaticMapUrl, searchPlacesByText, fetchPlaceDetails } from '@/services/tripPlannerService'

const { tm } = useI18n()

const hero = computed(() => tm('home.hero') ?? {})
const highlights = computed(() => tm('home.highlights') ?? [])
const highlightsHeader = computed(() => tm('home.highlightsSection') ?? {})
const destinations = computed(() => tm('home.destinations') ?? [])
const destinationsHeader = computed(() => tm('home.destinationsSection') ?? {})
const tipsSection = computed(() => tm('home.tipsSection') ?? {})
const travelTips = computed(() => tm('home.travelTips') ?? [])
const heroStats = [
  { label: 'Protected hectares', value: '1.2M', description: 'Mangroves, peat swamps, and montane rainforests cared for by local guardians.' },
  { label: 'Community partners', value: '480+', description: 'Cooperatives, guides, and artisans powering people-first journeys.' },
  { label: 'Plastic saved yearly', value: '8.4T', description: 'Straws worth of waste you keep out of rivers with refill-friendly itineraries.' },
]

const trailMoments = [
  {
    name: 'Langkawi blue hour paddles',
    location: 'Langkawi Geoforest Park',
    description: 'Kayak through karst tunnels at dawn while rangers stream mangrove health telemetry to your dashboard.',
    tags: ['Mangrove', 'Citizen science', 'Sunrise'],
    query: 'Langkawi mangrove kayak tour Malaysia',
    fallbackImage: 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=1200&q=80',
    disableMapFallback: true,
  },
  {
    name: 'Cameron highland slow brews',
    location: 'Cameron Highlands',
    description: 'Cycle misty tea terraces, roast single-origin leaves, and journal with agro-ecologists in solar huts.',
    tags: ['Tea ritual', 'Cycling', 'Agro-forest'],
    query: 'Cameron Highlands tea plantation sunrise Malaysia',
    fallbackImage: 'https://images.unsplash.com/photo-1441974231531-c6227db76b6e?auto=format&fit=crop&w=1200&q=80',
    disableMapFallback: true,
  },
  {
    name: 'Kinabatangan night symphony',
    location: 'Sabah floodplains',
    description: 'Float bioluminescent waters as community spotters log proboscis monkeys and fireflies for conservation AI.',
    tags: ['River cruise', 'Wildlife', 'Night watch'],
    query: 'Kinabatangan River cruise night wildlife Malaysia',
    fallbackImage: 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1200&q=80',
    disableMapFallback: true,
  },
]

const artisanFeatures = [
  {
    title: 'Water refill constellations',
    description: 'Map BYO bottle stops at cafes, rest houses, and longhouses crowd-sourced by travelers.',
    accent: 'Refill & reduce',
  },
  {
    title: 'Solar ferry transfers',
    description: 'Book coastal hops powered by hybrid catamarans to cut your island commute emissions.',
    accent: 'Move lightly',
  },
  {
    title: 'Circular craft markets',
    description: 'Meet makers turning discarded fishing nets into keepsakes with transparent pricing.',
    accent: 'Shop regenerative',
  },
]

const highlightIcons = [
  { label: 'LEAF', icon: '🌿' },
  { label: 'TIDE', icon: '🌊' },
  { label: 'ALLY', icon: '🤝' },
  { label: 'EARTH', icon: '🌍' },
  { label: 'DROP', icon: '💧' },
]

const popularPlaces = [
  {
    name: 'Taman Negara Canopy',
    location: 'Pahang Rainforest',
    description: 'Traverse a 40-meter-high canopy walkway while community guides interpret sounds of the 130-million-year-old forest.',
    stats: [
      { label: 'Eco score', value: '96 / 100' },
      { label: 'Best light', value: 'Jun - Sep' },
      { label: 'River health', value: '89% pristine' },
    ],
    activities: ['Canopy walk', 'Night trek', 'River safari'],
  },
  {
    name: 'Sipadan Blue Caves',
    location: 'Sabah',
    description: 'Live-aboard dive and snorkel expeditions limited to 176 permits per day to protect turtles and reef sharks.',
    stats: [
      { label: 'Permit slots', value: '176 / day' },
      { label: 'Visibility', value: '30m+' },
      { label: 'Carbon offset', value: '1.6kg / dive' },
    ],
    activities: ['Drift dive', 'Reef clean-up', 'Turtle watch'],
  },
  {
    name: 'Sekinchan Sunrise Paddies',
    location: 'Selangor',
    description: 'Cycle golden rice terraces with agro-ecologists and stay in stilt homestays powered by solar pumps.',
    stats: [
      { label: 'Cycle loops', value: '25 km' },
      { label: 'Harvest months', value: 'May & Nov' },
      { label: 'Water saved', value: '34% smart irrigation' },
    ],
    activities: ['Field to table', 'Village ride', 'Photo lab'],
  },
  {
    name: 'Semporna Reef Villages',
    location: 'Sabah archipelago',
    description: 'Snorkel lush coral gardens, then learn Bajau Laut weaving traditions that fund reef nurseries.',
    stats: [
      { label: 'Coral recovery', value: '76%' },
      { label: 'Snorkel depth', value: '5 - 12m' },
      { label: 'Sea plastic', value: '-2.4kg / visitor' },
    ],
    activities: ['Glass kayak', 'Weaving class', 'Reef nursery'],
  },
]

const destinationCoordinates = {
  'Perhentian Islands': { lat: 5.906308, lng: 102.734362, zoom: 11 },
  'Belum Rainforest Reserve': { lat: 5.545299, lng: 101.331047, zoom: 9 },
  'Kota Kinabalu Wetlands': { lat: 5.986868, lng: 116.095108, zoom: 13 },
  'Taman Negara Canopy': { lat: 4.4529, lng: 102.401, zoom: 9 },
  'Sipadan Blue Caves': { lat: 4.1149, lng: 118.6283, zoom: 11 },
  'Sekinchan Sunrise Paddies': { lat: 3.5071, lng: 101.0984, zoom: 11 },
  'Semporna Reef Villages': { lat: 4.4811, lng: 118.6118, zoom: 10 },
}

const destinationMedia = ref({})
const popularMedia = ref({})
const trailMedia = ref({})
const destinationLoading = ref(false)
const popularLoading = ref(false)
const trailLoading = ref(false)
const destinationError = ref('')
const popularError = ref('')
const trailError = ref('')

const highlightedStories = computed(() =>
  (highlights.value ?? []).map((item, index) => {
    const palette = highlightIcons[index % highlightIcons.length]
    return {
      ...item,
      badge: palette.label,
      emoji: palette.icon,
    }
  }),
)

function buildMapPreview(name) {
  const preset = destinationCoordinates[name]
  if (!preset) return ''
  const center = `${preset.lat},${preset.lng}`
  const markers = [`color:0x2F855A|${center}`]
  return buildStaticMapUrl({ center, zoom: preset.zoom ?? 8, markers, size: '640x400' })
}

async function fetchPlacesMedia(list, errorRef) {
  if (!Array.isArray(list) || !list.length) {
    return {}
  }
  const results = await Promise.all(
    list.map(async (destination) => {
      const manualImage =
        [destination.image, destination.imageUrl, destination.photo, destination.photoUrl]
          .map((value) => (typeof value === 'string' ? value.trim() : ''))
          .find(Boolean) || ''
      const manualAddress =
        destination.manualAddress ||
        destination.address ||
        destination.location ||
        destination.state ||
        'Malaysia'
      if (manualImage) {
        return {
          name: destination.name,
          photoUrl: manualImage,
          rating: destination.manualRating ?? null,
          total: destination.manualReviewCount ?? null,
          address: manualAddress,
          fallback: manualImage,
        }
      }
      const query = destination.query || `${destination.name} ${destination.location ?? 'Malaysia'}`
      try {
        const payload = await searchPlacesByText({
          query,
          type: 'tourist_attraction',
          language: 'en',
          region: 'MY',
        })
        const candidates = Array.isArray(payload?.results)
          ? payload.results
          : Array.isArray(payload?.data)
          ? payload.data
          : []
        const place = candidates.length ? candidates[0] : payload?.result
        let photoRef = place?.photos?.[0]?.photo_reference || place?.photos?.[0]?.name
        const placeId = place?.place_id || place?.placeId || place?.id
        let rating = place?.rating ?? null
        let total = place?.user_ratings_total ?? null
        let address = place?.formatted_address || place?.vicinity || `${destination.location}, Malaysia`

        if (!photoRef && placeId) {
          try {
            const detailPayload = await fetchPlaceDetails(placeId, {
              fields: 'photos,rating,user_ratings_total,formatted_address',
            })
            const detail = detailPayload?.result || detailPayload?.data || detailPayload
            if (detail) {
              photoRef = detail?.photos?.[0]?.photo_reference || detail?.photos?.[0]?.name || photoRef
              if (!rating) rating = detail?.rating ?? rating
              if (!total) total = detail?.user_ratings_total ?? total
              address = detail?.formatted_address || address
            }
          } catch (detailError) {
            console.warn('Place detail lookup failed', placeId, detailError)
          }
        }

        const photoUrl = photoRef ? buildPlacePhotoUrl(photoRef, { maxWidth: 1200 }) : ''
        const allowMapFallback = destination.disableMapFallback ? false : true
        const computedFallback =
          destination.fallbackImage || (allowMapFallback ? buildMapPreview(destination.name) : '')
        return {
          name: destination.name,
          photoUrl: photoUrl || computedFallback,
          rating,
          total,
          address,
          fallback: computedFallback,
        }
      } catch (error) {
        console.warn('Destination lookup failed', query, error)
        if (errorRef) {
          errorRef.value = 'Some live destination images are unavailable right now.'
        }
        return {
          name: destination.name,
          photoUrl:
            destination.fallbackImage ||
            (destination.disableMapFallback ? '' : buildMapPreview(destination.name)),
          rating: null,
          total: null,
          address: `${destination.location}, Malaysia`,
          fallback:
            destination.fallbackImage ||
            (destination.disableMapFallback ? '' : buildMapPreview(destination.name)),
        }
      }
    }),
  )
  const mapped = {}
  results.forEach((entry) => {
    if (entry?.name) {
      mapped[entry.name] = entry
    }
  })
  return mapped
}

async function loadPlacesMedia(list, targetRef, loadingRef, errorRef) {
  if (!Array.isArray(list) || !list.length) {
    targetRef.value = {}
    return
  }
  try {
    if (loadingRef) loadingRef.value = true
    if (errorRef) errorRef.value = ''
    targetRef.value = await fetchPlacesMedia(list, errorRef)
  } finally {
    if (loadingRef) loadingRef.value = false
  }
}

async function loadDestinationMedia(list) {
  await loadPlacesMedia(list, destinationMedia, destinationLoading, destinationError)
}

async function loadPopularMedia(list) {
  await loadPlacesMedia(list, popularMedia, popularLoading, popularError)
}

async function loadTrailMedia(list) {
  await loadPlacesMedia(list, trailMedia, trailLoading, trailError)
}

watch(
  () => destinations.value,
  (list) => {
    if (Array.isArray(list) && list.length) {
      loadDestinationMedia(list)
    } else {
      destinationMedia.value = {}
    }
  },
  { immediate: true },
)

loadPopularMedia(popularPlaces)
loadTrailMedia(trailMoments)
</script>

<template>
  <main class="home">
    <section class="hero" id="hero">
      <div class="hero-content">
        <p class="hero-tag">{{ hero.tagline }}</p>
        <h1>{{ hero.title }}</h1>
        <p class="hero-subtitle">{{ hero.subtitle }}</p>
        <div v-if="hero.secondaryCta" class="hero-actions">
          <a class="btn ghost hero-btn" href="#tips">{{ hero.secondaryCta }}</a>
        </div>
        <dl class="hero-stats">
          <div v-for="stat in heroStats" :key="stat.label" class="hero-stat">
            <dd>{{ stat.value }}</dd>
            <dt>{{ stat.label }}</dt>
            <p>{{ stat.description }}</p>
          </div>
        </dl>
      </div>
    </section>

    <section class="eco-highlights" id="about">
      <header class="section-head">
        <p class="section-kicker">Nature-first promise</p>
        <h2>{{ highlightsHeader.title }}</h2>
        <p>{{ highlightsHeader.subtitle }}</p>
      </header>
      <div class="highlight-grid">
        <article v-for="(highlight, index) in highlightedStories" :key="highlight.title" class="highlight-card">
          <div class="highlight-avatar">
            <span>{{ highlight.emoji }}</span>
          </div>
          <div class="highlight-content">
            <div class="highlight-pill">
              <span class="highlight-pill-index">Pillar 0{{ index + 1 }}</span>
              <span class="highlight-pill-label">{{ highlight.badge }}</span>
            </div>
            <h3>{{ highlight.title }}</h3>
            <p>{{ highlight.description }}</p>
          </div>
        </article>
      </div>
    </section>

    <section class="living-destinations" id="destinations">
      <header class="section-head">
        <p class="section-kicker">Curated journeys</p>
        <h2>{{ destinationsHeader.title }}</h2>
        <p>{{ destinationsHeader.subtitle }}</p>
      </header>
      <div class="destinations-grid" :class="{ 'is-loading': destinationLoading }">
        <article v-for="destination in destinations" :key="destination.name" class="destination-card">
          <div class="destination-media">
            <img
              v-if="destinationMedia[destination.name]?.photoUrl"
              :src="destinationMedia[destination.name].photoUrl"
              :alt="destination.name"
              loading="lazy"
            />
            <div v-else class="image-skeleton"></div>
            <span class="destination-label">{{ destination.location }}</span>
          </div>
          <div class="destination-body">
            <div class="destination-heading">
              <h3>{{ destination.name }}</h3>
              <p>{{ destination.location }}</p>
            </div>
            <p class="destination-description">{{ destination.description }}</p>
            <div class="destination-tags">
              <span v-for="tag in destination.tags" :key="tag">{{ tag }}</span>
            </div>
            <div class="destination-meta">
              <p v-if="destinationMedia[destination.name]?.rating">
                Rating {{ destinationMedia[destination.name].rating }} &middot;
                {{ destinationMedia[destination.name].total ?? '-' }} reviews
              </p>
              <p>{{ destinationMedia[destination.name]?.address }}</p>
            </div>
          </div>
        </article>
      </div>
      <p v-if="destinationError" class="destination-error">{{ destinationError }}</p>
    </section>

    <section class="popular-places" id="popular">
      <header class="section-head">
        <p class="section-kicker">Most loved escapes</p>
        <h2>Popular Malaysian routes with live imagery</h2>
        <p>Tap into Booking Live Stays, Trip Planner AI, and Google Places photography to preview high-demand eco journeys.</p>
      </header>
      <div class="popular-grid" :class="{ 'is-loading': popularLoading }">
        <article v-for="place in popularPlaces" :key="place.name" class="popular-card">
          <div class="popular-media">
            <img
              v-if="popularMedia[place.name]?.photoUrl"
              :src="popularMedia[place.name].photoUrl"
              :alt="place.name"
              loading="lazy"
            />
            <div v-else class="image-skeleton"></div>
            <div class="popular-pill">Live preview</div>
          </div>
          <div class="popular-body">
            <div class="popular-header">
              <h3>{{ place.name }}</h3>
              <p>{{ place.location }}</p>
            </div>
            <p class="popular-description">{{ place.description }}</p>
            <ul class="popular-stats">
              <li v-for="stat in place.stats" :key="stat.label">
                <span>{{ stat.label }}</span>
                <strong>{{ stat.value }}</strong>
              </li>
            </ul>
            <div class="popular-tags">
              <span v-for="activity in place.activities" :key="activity">{{ activity }}</span>
            </div>
            <p class="destination-meta">
              <span>{{ popularMedia[place.name]?.address }}</span>
              <span v-if="popularMedia[place.name]?.rating">Rating {{ popularMedia[place.name].rating }}</span>
            </p>
          </div>
        </article>
      </div>
      <p v-if="popularError" class="destination-error">{{ popularError }}</p>
    </section>

    <section class="immersion-trails">
      <header class="section-head">
        <p class="section-kicker">Design your rhythm</p>
        <h2>Trail mixes for mindful travelers</h2>
        <p>Each mix streams a real place photo from Google Places so you can feel the atmosphere before you pack.</p>
      </header>
      <div class="trail-grid" :class="{ 'is-loading': trailLoading }">
        <article v-for="moment in trailMoments" :key="moment.name" class="trail-card">
          <div class="trail-media">
            <img
              v-if="trailMedia[moment.name]?.photoUrl"
              :src="trailMedia[moment.name].photoUrl"
              :alt="moment.name"
              loading="lazy"
            />
            <div v-else class="image-skeleton"></div>
            <span class="trail-location">{{ moment.location }}</span>
          </div>
          <div class="trail-body">
            <h3>{{ moment.name }}</h3>
            <p>{{ moment.description }}</p>
            <div class="trail-badges">
              <span v-for="badge in moment.tags" :key="badge">{{ badge }}</span>
            </div>
            <p class="trail-meta">{{ trailMedia[moment.name]?.address || moment.location }}</p>
          </div>
        </article>
      </div>
      <p v-if="trailError" class="destination-error">{{ trailError }}</p>
    </section>

    <section class="artisan-marquee">
      <header class="section-head">
        <p class="section-kicker">Impact in motion</p>
        <h2>Ecotech perks for every itinerary</h2>
        <p>Tap into tools that celebrate artisans, conserve water, and keep your trip emissions-light.</p>
      </header>
      <div class="artisan-grid">
        <article v-for="feature in artisanFeatures" :key="feature.title" class="artisan-card">
          <p class="artisan-accent">{{ feature.accent }}</p>
          <h3>{{ feature.title }}</h3>
          <p>{{ feature.description }}</p>
        </article>
      </div>
    </section>

    <section class="slow-travel" id="tips">
      <div class="slow-travel-card">
        <div class="slow-travel-copy">
          <p class="section-kicker">{{ tipsSection.kicker }}</p>
          <h2>{{ tipsSection.title }}</h2>
          <p>{{ tipsSection.subtitle }}</p>
        </div>
        <ul class="tips-list">
          <li v-for="tip in travelTips" :key="tip">
            <span>+</span>
            <p>{{ tip }}</p>
          </li>
        </ul>
      </div>
    </section>

    <NewsletterSignup />
  </main>
</template>

<style scoped>
.home {
  display: flex;
  flex-direction: column;
  gap: 4.5rem;
  padding: 0 1.5rem 5rem;
  background: linear-gradient(180deg, #fffaf3 0%, #f5fbf5 55%, #ffffff 100%);
  color: #0f2b21;
}

.hero {
  position: relative;
  display: grid;
  gap: 3rem;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  padding: 4rem clamp(1.25rem, 4vw, 4rem);
  background: linear-gradient(135deg, #fff4ea, #e4f6ea);
  border-radius: 40px;
  color: #103124;
  overflow: hidden;
  box-shadow: 0 30px 60px rgba(146, 168, 157, 0.45);
}

.hero::after {
  content: '';
  position: absolute;
  inset: 0;
  background: radial-gradient(circle at 25% 20%, rgba(255, 255, 255, 0.8), transparent 45%);
  pointer-events: none;
}

.hero-content {
  position: relative;
  z-index: 1;
}

.hero-tag {
  text-transform: uppercase;
  letter-spacing: 0.2em;
  font-size: 0.85rem;
  margin-bottom: 1rem;
  color: #6c7c72;
}

.hero h1 {
  margin: 0;
  font-size: clamp(2.5rem, 4vw, 3.75rem);
  line-height: 1.1;
  color: #0f2b21;
}

.hero-subtitle {
  margin: 1.2rem 0 2rem;
  font-size: 1.15rem;
  color: #3c5045;
}

.hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 2.5rem;
}

.hero-btn {
  min-width: 190px;
  text-align: center;
}

.hero-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 1.5rem;
  margin: 0;
}

.hero-stat {
  padding: 1rem 1.25rem;
  border-radius: 20px;
  background: rgba(255, 255, 255, 0.9);
  border: 1px solid rgba(224, 200, 178, 0.6);
}

.hero-stat dd {
  margin: 0;
  font-size: 1.8rem;
  font-weight: 700;
  color: #103124;
}

.hero-stat dt {
  margin: 0.25rem 0;
  text-transform: uppercase;
  font-size: 0.9rem;
  letter-spacing: 0.15em;
  color: #7b8e80;
}

.hero-stat p {
  margin: 0;
  color: #3e5246;
  font-size: 0.95rem;
}


.section-head {
  text-align: left;
  margin-bottom: 2rem;
}

.section-kicker {
  text-transform: uppercase;
  font-size: 0.85rem;
  letter-spacing: 0.3em;
  margin: 0 0 0.75rem;
  color: #6b8074;
}

.section-head h2 {
  margin: 0;
  font-size: clamp(2rem, 3vw, 2.8rem);
  color: #0f3020;
}

.section-head p {
  margin: 0.75rem 0 0;
  color: #4d6458;
  max-width: 720px;
}

.eco-highlights {
  padding: 0 clamp(0.5rem, 4vw, 2rem);
}

.highlight-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 1.5rem;
}

.highlight-card {
  display: flex;
  align-items: center;
  gap: 1.25rem;
  padding: 1.5rem 1.75rem;
  border-radius: 22px;
  border: 1px solid rgba(225, 239, 230, 0.9);
  background: linear-gradient(120deg, rgba(255, 255, 255, 0.85), rgba(233, 248, 239, 0.9));
  box-shadow: 0 12px 30px rgba(104, 123, 111, 0.18);
}

.highlight-avatar {
  width: 64px;
  height: 64px;
  border-radius: 18px;
  background: #0f3926;
  color: #f5fff7;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  flex-shrink: 0;
  box-shadow: 0 10px 18px rgba(15, 57, 38, 0.3);
}

.highlight-content h3 {
  margin: 0.1rem 0 0.35rem;
  font-size: 1.25rem;
  color: #0f2b21;
}

.highlight-content p {
  margin: 0;
  color: #506459;
}

.highlight-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.35rem;
}

.highlight-pill-index {
  font-size: 0.65rem;
  letter-spacing: 0.35em;
  text-transform: uppercase;
  color: #c69455;
}

.highlight-pill-label {
  font-size: 0.65rem;
  letter-spacing: 0.35em;
  text-transform: uppercase;
  color: #3b5d4e;
  background: rgba(59, 93, 78, 0.08);
  padding: 0.25rem 0.6rem;
  border-radius: 999px;
}

.living-destinations {
  padding: clamp(1rem, 4vw, 2.5rem);
  border-radius: 36px;
  background: linear-gradient(145deg, #f0f9f3, #fffdf6);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6), 0 25px 45px rgba(182, 199, 192, 0.4);
}

.destinations-grid {
  display: grid;
  gap: 2rem;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
}

.destinations-grid.is-loading::after {
  content: 'Refreshing imagery...';
  position: absolute;
  inset: auto 1.5rem 1.5rem auto;
  background: rgba(255, 255, 255, 0.9);
  color: #1f3b2c;
  padding: 0.5rem 1rem;
  border-radius: 999px;
  font-size: 0.85rem;
}

.destinations-grid {
  position: relative;
}

.destination-card {
  display: flex;
  flex-direction: column;
  border-radius: 28px;
  background: #ffffff;
  overflow: hidden;
  border: 1px solid #eae1d2;
  box-shadow: 0 25px 50px rgba(183, 197, 189, 0.4);
}

.destination-media {
  position: relative;
  aspect-ratio: 4 / 3;
  overflow: hidden;
}

.destination-media img,
.image-skeleton {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.image-skeleton {
  background: linear-gradient(120deg, #e8f4ec, #d1e5d9, #e8f4ec);
  animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
  0% {
    background-position: -200px 0;
  }
  100% {
    background-position: 200px 0;
  }
}

.destination-label {
  position: absolute;
  top: 1rem;
  left: 1rem;
  background: rgba(10, 34, 22, 0.8);
  color: #f6fff8;
  padding: 0.45rem 0.9rem;
  border-radius: 999px;
  font-size: 0.85rem;
}

.destination-body {
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
}

.destination-heading {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.destination-heading h3 {
  margin: 0;
  font-size: 1.4rem;
  color: #0f2b21;
}

.destination-heading p {
  margin: 0;
  color: #5b6d62;
}

.destination-description {
  margin: 0;
  color: #425548;
}

.destination-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.destination-tags span {
  padding: 0.35rem 0.75rem;
  border-radius: 999px;
  background: #fff4e6;
  font-size: 0.85rem;
  color: #714e2f;
}

.destination-meta {
  font-size: 0.9rem;
  color: #4f6458;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.destination-error {
  margin: 1.5rem 0 0;
  color: #365a46;
  font-weight: 600;
}

.popular-places {
  padding: 0 clamp(0.5rem, 4vw, 2rem);
}

.popular-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 1.5rem;
  position: relative;
}

.popular-card {
  border-radius: 28px;
  overflow: hidden;
  background: #ffffff;
  border: 1px solid #e2e9e3;
  box-shadow: 0 25px 55px rgba(162, 176, 168, 0.3);
  display: flex;
  flex-direction: column;
}

.popular-media {
  position: relative;
  aspect-ratio: 5 / 3;
  overflow: hidden;
}

.popular-media img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.popular-pill {
  position: absolute;
  top: 1rem;
  right: 1rem;
  background: rgba(255, 255, 255, 0.9);
  color: #184933;
  padding: 0.4rem 0.9rem;
  border-radius: 999px;
  font-size: 0.8rem;
  letter-spacing: 0.2em;
}

.popular-body {
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
}

.popular-header h3 {
  margin: 0;
  font-size: 1.35rem;
  color: #0f2b21;
}

.popular-header p {
  margin: 0.25rem 0 0;
  color: #5d6f63;
}

.popular-description {
  margin: 0;
  color: #405346;
}

.popular-stats {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 0.85rem;
}

.popular-stats li {
  padding: 0.75rem 0.95rem;
  border-radius: 16px;
  background: #f6fbf5;
  border: 1px solid #e0ecdf;
}

.popular-stats span {
  display: block;
  font-size: 0.78rem;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: #6a7e70;
  margin-bottom: 0.25rem;
}

.popular-stats strong {
  font-size: 1rem;
  color: #0f2b21;
}

.popular-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.popular-tags span {
  padding: 0.3rem 0.75rem;
  border-radius: 999px;
  background: #fff2e3;
  font-size: 0.85rem;
  color: #80522a;
}

.popular-grid.is-loading::after {
  content: 'Refreshing imagery...';
  position: absolute;
  inset: auto 1.5rem 1.5rem auto;
  background: rgba(255, 255, 255, 0.9);
  color: #1f3b2c;
  padding: 0.4rem 0.8rem;
  border-radius: 999px;
  font-size: 0.8rem;
}

.popular-body .destination-meta {
  margin-top: 0.35rem;
  gap: 0.25rem;
}

.immersion-trails,
.artisan-marquee {
  padding: 0 clamp(0.5rem, 4vw, 2rem);
}

.trail-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 1.5rem;
  position: relative;
}

.trail-grid.is-loading::after {
  content: 'Loading trail imagery...';
  position: absolute;
  inset: auto 1rem 1rem auto;
  background: rgba(255, 255, 255, 0.95);
  color: #1f3b2c;
  padding: 0.4rem 0.75rem;
  border-radius: 999px;
  font-size: 0.8rem;
}

.trail-card {
  border-radius: 28px;
  overflow: hidden;
  background: #ffffff;
  border: 1px solid #f3e2cf;
  box-shadow: 0 20px 45px rgba(197, 172, 144, 0.25);
  display: flex;
  flex-direction: column;
}

.trail-media {
  position: relative;
  aspect-ratio: 4 / 3;
  overflow: hidden;
}

.trail-media img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.trail-location {
  position: absolute;
  top: 1rem;
  left: 1rem;
  background: rgba(255, 255, 255, 0.9);
  color: #1d3b2c;
  padding: 0.35rem 0.75rem;
  border-radius: 999px;
  font-size: 0.8rem;
  letter-spacing: 0.15em;
}

.trail-body {
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.trail-body h3 {
  margin: 0;
  font-size: 1.35rem;
  color: #0f2b21;
}

.trail-body p {
  margin: 0;
  color: #495f52;
}

.trail-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.trail-badges span {
  padding: 0.3rem 0.8rem;
  border-radius: 999px;
  background: #fff2e3;
  font-size: 0.8rem;
  color: #8a572c;
}

.trail-meta {
  font-size: 0.9rem;
  color: #5b6f63;
}

.artisan-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 1.5rem;
}

.artisan-card {
  padding: 1.75rem;
  border-radius: 24px;
  background: #ffffff;
  border: 1px solid #e1efe6;
  box-shadow: 0 15px 35px rgba(12, 66, 41, 0.08);
}

.artisan-accent {
  text-transform: uppercase;
  letter-spacing: 0.3em;
  font-size: 0.75rem;
  color: #5b7a69;
  margin: 0 0 0.75rem;
}

.artisan-card h3 {
  margin: 0 0 0.5rem;
  font-size: 1.3rem;
  color: #0f2b21;
}

.artisan-card p {
  margin: 0;
  color: #4a5f52;
}

.slow-travel {
  padding: 0 clamp(0.5rem, 4vw, 2rem);
}

.slow-travel-card {
  border-radius: 32px;
  background: linear-gradient(135deg, #fff5ec, #e8f8ed);
  color: #0f2b21;
  padding: clamp(1.5rem, 5vw, 3rem);
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 2rem;
  box-shadow: 0 25px 60px rgba(191, 189, 164, 0.5);
}

.slow-travel-copy h2 {
  margin: 0;
  font-size: clamp(2rem, 3vw, 2.6rem);
}

.slow-travel-copy p {
  margin: 0.75rem 0 0;
  color: #3f5347;
}

.tips-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.tips-list li {
  display: flex;
  gap: 1rem;
  padding: 1rem 1.25rem;
  border-radius: 18px;
  background: #ffffff;
  border: 1px solid rgba(255, 200, 150, 0.4);
  box-shadow: 0 14px 25px rgba(229, 192, 153, 0.2);
}

.tips-list span {
  font-size: 1.25rem;
  line-height: 1.4;
  color: #c0763b;
}

.tips-list p {
  margin: 0;
  color: #2f4738;
}

@media (max-width: 720px) {
  .hero {
    padding: 3rem 1.25rem;
  }

  .hero-actions {
    flex-direction: column;
  }

  .pulse-meter {
    min-width: unset;
  }

  .destinations-grid.is-loading::after {
    position: static;
    display: inline-block;
    margin-top: 1rem;
  }

  .slow-travel-card {
    grid-template-columns: 1fr;
  }
}
</style>
