<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useMessage } from 'naive-ui'
import { fetchSavedPlacePackages, deleteSavedPlacePackage } from '../services/tripPlannerService.js'

const props = defineProps({
  travelerId: {
    type: [Number, String],
    required: true,
  },
})

const emit = defineEmits(['view-booking-dashboard'])
const packages = ref([])
const loading = ref(false)
const message = useMessage()
const detailsVisible = ref(false)
const activePackage = ref(null)
const heroPhoto = computed(() => (activePackage.value ? computeCoverPhoto(activePackage.value) : ''))
const stayCarouselRef = ref(null)
const router = useRouter()
const route = useRoute()
const savedPackagesCount = computed(() => packages.value.length)
const totalSelectionsSaved = computed(() =>
  packages.value.reduce((sum, pkg) => sum + packageSelectionCount(pkg), 0),
)
const curatedDestinationList = computed(() => {
  const seen = new Set()
  const formatted = []
  packages.value.forEach((pkg) => {
    const label = toTitleCase(pkg.destination || '')
    if (label && !seen.has(label)) {
      seen.add(label)
      formatted.push(label)
    }
  })
  return formatted
})
const heroDescription = computed(() => {
  if (!savedPackagesCount.value) {
    return 'Pin your favourite eco stays and sensory excursions for quick booking later.'
  }
  if (curatedDestinationList.value.length) {
    return `Boutique escapes across ${curatedDestinationList.value.join(', ')} await your next chapter.`
  }
  return 'Handpicked stays and immersive experiences ready for your next itinerary.'
})
const heroMood = computed(() =>
  savedPackagesCount.value ? curatedDestinationList.value.join(' | ') || 'Curated Calm' : 'Awaiting Discovery',
)
const heroTags = computed(() => {
  const tags = [...curatedDestinationList.value.slice(0, 2)]
  if (totalSelectionsSaved.value) {
    tags.push(`${totalSelectionsSaved.value} Selections`)
  }
  if (savedPackagesCount.value) {
    tags.push(`${savedPackagesCount.value} Packages`)
  }
  return [...new Set(tags)].slice(0, 4)
})

function startPlanNewEscape() {
  const nextQuery = { ...route.query, module: 'trips' }
  router.push({ name: 'traveler', query: nextQuery })
}

function openBookingDashboard(pkg = activePackage.value) {
  if (!pkg) {
    return
  }
  emit('view-booking-dashboard', { package: pkg })
  closePackageDetails()
}

function packageDateSummary(pkg) {
  const segments = []
  if (pkg?.summary?.dateRange) {
    segments.push(pkg.summary.dateRange)
  }
  if (pkg?.summary?.durationLabel) {
    segments.push(pkg.summary.durationLabel)
  }
  return segments.join(' • ') || 'Awaiting travel dates'
}

function packageTagline(pkg) {
  if (pkg?.summary?.themeSummary) {
    return toTitleCase(pkg.summary.themeSummary)
  }
  return 'Sensory-rich moments curated for your next escape.'
}

async function loadPackages() {
  if (!props.travelerId) {
    packages.value = []
    return
  }
  loading.value = true
  try {
    const data = await fetchSavedPlacePackages(props.travelerId)
    packages.value = data.packages ?? []
  } catch (error) {
    console.error(error)
    message.error(error?.message || 'Saved places request failed.')
  } finally {
    loading.value = false
  }
}

function handleRefresh(event) {
  const target = Number(event?.detail?.travelerId ?? props.travelerId)
  if (Number(props.travelerId) !== target) {
    return
  }
  loadPackages()
}

onMounted(() => {
  loadPackages()
  window.addEventListener('traveler-saved-places-refresh', handleRefresh)
})

onBeforeUnmount(() => {
  window.removeEventListener('traveler-saved-places-refresh', handleRefresh)
})

async function handleDelete(pkg) {
  if (!props.travelerId || !pkg?.packageId) {
    return
  }
  try {
    await deleteSavedPlacePackage(props.travelerId, pkg.packageId)
    message.success('Removed from Saved places.')
    loadPackages()
  } catch (error) {
    console.error(error)
    message.error(error?.message || 'Unable to remove package.')
  }
}

function openPackageDetails(pkg) {
  activePackage.value = pkg
  detailsVisible.value = true
}

function closePackageDetails() {
  detailsVisible.value = false
  activePackage.value = null
}

function formatTimestamp(value) {
  if (!value) return ''
  try {
    return new Date(value).toLocaleString('en-MY', {
      dateStyle: 'medium',
      timeStyle: 'short',
    })
  } catch {
    return value
  }
}

function listTags(tags = []) {
  return Array.isArray(tags) && tags.length ? tags.join(', ') : null
}

function packageSelectionCount(pkg) {
  const experiences =
    pkg.selections?.experiences?.reduce((total, section) => total + (section.picks?.length ?? 0), 0) ?? 0
  const stays = pkg.selections?.stays?.length ?? 0
  return experiences + stays
}

function packageCostLabel(pkg) {
  const total = packageTotalCost(pkg)
  return total?.label ?? ''
}

function formatCurrencyAmount(amount, currency = 'MYR') {
  if (!Number.isFinite(amount) || amount <= 0) {
    return ''
  }
  try {
    return new Intl.NumberFormat('en-MY', {
      style: 'currency',
      currency: currency || 'MYR',
      maximumFractionDigits: amount % 1 === 0 ? 0 : 2,
    }).format(amount)
  } catch {
    const safeCurrency = currency || 'MYR'
    const rounded = amount % 1 === 0 ? amount.toFixed(0) : amount.toFixed(2)
    return `${safeCurrency} ${rounded}`
  }
}

function packageTotalCost(pkg) {
  const summary = pkg?.costSummary
  const total = Number(summary?.total ?? pkg?.totalCost ?? 0)
  if (!Number.isFinite(total) || total <= 0) {
    return null
  }
  const currency = summary?.currency || pkg?.currency || 'MYR'
  return {
    amount: Math.round(total * 100) / 100,
    currency,
    label: formatCurrencyAmount(total, currency),
  }
}

const COST_THEME_LABELS = {
  travel: 'Travel essentials',
  adventure: 'Adventure thrills',
  city: 'City highlights',
  relax: 'Relax & wellness',
}

function packageCostBreakdown(pkg) {
  const summary = pkg?.costSummary
  if (!summary?.categories || typeof summary.categories !== 'object') {
    return []
  }
  const currency = summary.currency || pkg?.currency || 'MYR'
  return Object.entries(summary.categories)
    .filter(([, amount]) => Number(amount) > 0)
    .map(([theme, amount]) => ({
      theme,
      label: COST_THEME_LABELS[theme] || theme,
      amount: Math.round(Number(amount) * 100) / 100,
      formatted: formatCurrencyAmount(Number(amount), currency),
    }))
    .sort((a, b) => b.amount - a.amount)
}

function computeCoverPhoto(pkg) {
  if (pkg.coverPhoto) return pkg.coverPhoto
  const stay = pkg.selections?.stays?.find((item) => item.photoUrl)?.photoUrl
  if (stay) return stay
  for (const section of pkg.selections?.experiences || []) {
    const match = section.picks?.find((pick) => pick.photoUrl)
    if (match?.photoUrl) return match.photoUrl
  }
  return ''
}

function scrollStayCarousel(direction = 'next') {
  const el = stayCarouselRef.value
  if (!el) return
  const amount = direction === 'next' ? 320 : -320
  el.scrollBy({ left: amount, behavior: 'smooth' })
}

function stayRatingValue(entry) {
  const rating = Number(entry?.rating ?? entry?.metadata?.rating)
  if (!Number.isFinite(rating)) {
    return null
  }
  return Math.round(rating * 10) / 10
}

function stayReviewLabel(entry) {
  const rawCount = entry?.reviews ?? entry?.metadata?.reviewCount
  let countLabel = ''
  if (rawCount != null && rawCount !== '') {
    const numeric = Number(rawCount)
    if (Number.isFinite(numeric) && numeric > 0) {
      try {
        countLabel = `${numeric.toLocaleString('en-MY')} reviews`
      } catch {
        countLabel = `${numeric} reviews`
      }
    } else if (typeof rawCount === 'string' && rawCount.trim()) {
      countLabel = rawCount.trim()
    }
  }
  const summaryRaw = entry?.metadata?.reviewSummary ?? entry?.reviewSummary
  const summary = typeof summaryRaw === 'string' ? summaryRaw.trim() : ''
  return [countLabel, summary].filter(Boolean).join(' | ')
}
function toTitleCase(value) {
  if (!value || typeof value !== 'string') {
    return ''
  }
  return value
    .split(' ')
    .filter(Boolean)
    .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1).toLowerCase())
    .join(' ')
}</script>

<template>
  <div class="saved-places-dashboard">
    <div class="saved-places-dashboard__glow saved-places-dashboard__glow--teal" />
    <div class="saved-places-dashboard__glow saved-places-dashboard__glow--sunset" />

    <section class="saved-places-dashboard__hero">
      <div class="saved-places-dashboard__hero-left">
        <div class="saved-places-dashboard__hero-badge">
          <i class="ri-compass-3-line" />
          Saved Places
        </div>
        <div class="saved-places-dashboard__hero-title">
          <h1>{{ savedPackagesCount ? 'Curated Escape Gallery' : 'Create Your Escape Library' }}</h1>
          <span class="saved-places-dashboard__hero-caption">Journey palette · {{ heroMood || 'Awaiting Discovery' }}</span>
        </div>
        <p class="saved-places-dashboard__description">
          {{ heroDescription }}
        </p>
        <div v-if="heroTags.length" class="saved-places-dashboard__hero-tags">
          <span v-for="tag in heroTags" :key="tag" class="saved-places-dashboard__tag">{{ tag }}</span>
        </div>
        <div class="saved-places-dashboard__hero-actions">
          <n-button type="primary" size="large" round class="saved-places-dashboard__cta" @click="startPlanNewEscape">
            Plan New Escape
          </n-button>
          <n-button quaternary size="large" round class="saved-places-dashboard__cta--ghost" :loading="loading" @click="loadPackages">
            Refresh Gallery
          </n-button>
        </div>
      </div>
      <div class="saved-places-dashboard__hero-panels">
        <article class="saved-places-dashboard__stat-card">
          <p>Saved Journeys</p>
          <strong>{{ savedPackagesCount }}</strong>
          <span>{{ curatedDestinationList.slice(0, 3).join(' | ') || 'No destinations yet' }}</span>
        </article>
        <article class="saved-places-dashboard__stat-card">
          <p>Total Selections</p>
          <strong>{{ totalSelectionsSaved }}</strong>
          <span>Experiences & Stays</span>
        </article>
        <article class="saved-places-dashboard__stat-card">
          <p>Destinations Tracked</p>
          <strong>{{ curatedDestinationList.length }}</strong>
          <span>{{ curatedDestinationList.join(' | ') || 'Awaiting Discovery' }}</span>
        </article>
      </div>
    </section>

    <section class="saved-places-dashboard__collection">
      <n-spin :show="loading">
        <n-empty
          v-if="!packages.length && !loading"
          description="No escapes pinned yet."
        >
          <template #default>
            <p>Plan a new trip and tap "Save This Package" to build your gallery.</p>
          </template>
        </n-empty>

        <div v-else class="saved-places-dashboard__grid">
          <article
            v-for="pkg in packages"
            :key="pkg.packageId"
            class="saved-package-card"
          >
            <div
              class="saved-package-card__interactive"
              role="button"
              tabindex="0"
              :aria-label="`Open saved package ${pkg.title || ''}`"
              @click="openPackageDetails(pkg)"
              @keyup.enter.prevent="openPackageDetails(pkg)"
              @keyup.space.prevent="openPackageDetails(pkg)"
            >
              <div
                class="saved-package-card__media"
                :class="{ 'saved-package-card__media--empty': !computeCoverPhoto(pkg) }"
                :style="computeCoverPhoto(pkg) ? { backgroundImage: `url(${computeCoverPhoto(pkg)})` } : undefined"
              >
                <div v-if="!computeCoverPhoto(pkg)" class="saved-package-card__placeholder">
                  <i class="ri-image-2-line" aria-hidden="true" />
                  <span>Awaiting Imagery</span>
                </div>
                <div class="saved-package-card__veil">
                  <div class="saved-package-card__meta">
                    <span class="saved-package-card__location-pill">
                      {{ toTitleCase(pkg.destination) || 'Curated Escape' }}
                    </span>
                    <span class="saved-package-card__timestamp">{{ formatTimestamp(pkg.createdAt) }}</span>
                  </div>
                  <div class="saved-package-card__headline">
                    <p class="saved-package-card__title">{{ toTitleCase(pkg.title || 'Saved Package') }}</p>
                    <p class="saved-package-card__dates">{{ packageDateSummary(pkg) }}</p>
                  </div>
                  <div class="saved-package-card__stats-row">
                    <p class="saved-package-card__tagline">{{ packageTagline(pkg) }}</p>
                    <div class="saved-package-card__stats">
                      <span class="saved-package-card__stat">{{ packageSelectionCount(pkg) }} Picks</span>
                      <span
                        v-if="packageCostLabel(pkg)"
                        class="saved-package-card__stat saved-package-card__stat--ghost"
                      >
                        {{ packageCostLabel(pkg) }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
              <div class="saved-package-card__body">
                <div>
                  <p class="saved-package-card__label">Destination</p>
                  <strong>{{ toTitleCase(pkg.destination) || 'Unspecified Destination' }}</strong>
                </div>
                <p class="saved-package-card__theme">
                  {{ pkg.summary?.themeSummary || 'Handpicked from your dreamboard itinerary.' }}
                </p>
                <div class="saved-package-card__footer">
                  <span>Open journey capsule</span>
                  <n-icon>
                    <i class="ri-arrow-right-line" />
                  </n-icon>
                </div>
              </div>
            </div>

            <n-popconfirm
              @positive-click="handleDelete(pkg)"
              positive-text="Remove"
              negative-text="Cancel"
            >
              <template #trigger>
                <n-button
                  quaternary
                  circle
                  size="small"
                  class="saved-package-card__delete"
                  @click.stop
                >
                  <n-icon>
                    <i class="ri-delete-bin-line" />
                  </n-icon>
                </n-button>
              </template>
              Remove this saved package?
            </n-popconfirm>
          </article>
        </div>
      </n-spin>
    </section>
  </div>
  <n-modal
    v-model:show="detailsVisible"
    :style="{ width: '940px', maxWidth: '96vw', maxHeight: '92vh' }"
    preset="card"
    :bordered="false"
    class="package-modal"
    scrollable
  >
    <template #header>
      <div class="package-modal__header">
        <div class="package-modal__title-group">
          <p class="package-modal__eyebrow">Saved package</p>
          <h3 class="package-modal__title">
            {{ activePackage?.title || 'Selections' }}
          </h3>
        </div>
        <n-tag v-if="activePackage?.destination" round type="success" size="medium">
          {{ activePackage.destination }}
        </n-tag>
      </div>
    </template>

    <div v-if="activePackage" class="package-modal__content">
      <div class="package-modal__sheet package-modal__sheet--split">
        <aside class="package-modal__summary-pane">
          <div class="package-modal__overview-card">
            <div
              class="package-modal__hero"
              :class="{ 'package-modal__hero--empty': !heroPhoto }"
              :style="heroPhoto ? { backgroundImage: `url(${heroPhoto})` } : undefined"
            >
              <div class="package-modal__hero-overlay">
                <span class="package-modal__hero-badge">
                  {{ activePackage.destination || 'Custom adventure' }}
                </span>
                <h4 class="package-modal__hero-title">
                  {{ activePackage.summary?.dateRange || 'Flexible travel dates' }}
                </h4>
                <p v-if="activePackage.summary?.durationLabel" class="package-modal__hero-subtitle">
                  {{ activePackage.summary.durationLabel }}
                </p>
                <div class="package-modal__hero-stats">
                  <span>{{ packageSelectionCount(activePackage) }} curated moments</span>
                  <span v-if="activePackage.summary?.themeSummary">{{
                    toTitleCase(activePackage.summary.themeSummary)
                  }}</span>
                </div>
              </div>
            </div>

            <div class="package-modal__meta-row">
            <div class="package-modal__meta-item">
              <div class="package-modal__meta-icon">
                <i class="ri-bookmark-line" />
              </div>
              <div>
                <span>Saved on</span>
                <strong>{{ formatTimestamp(activePackage.createdAt) }}</strong>
              </div>
            </div>
            <div class="package-modal__meta-item">
              <div class="package-modal__meta-icon">
                <i class="ri-calendar-line" />
              </div>
              <div>
                <span>Trip window</span>
                <strong>{{ activePackage.summary?.dateRange || 'To be confirmed' }}</strong>
              </div>
            </div>
            <div class="package-modal__meta-item">
              <div class="package-modal__meta-icon">
                <i class="ri-time-line" />
              </div>
              <div>
                <span>Duration</span>
                <strong>{{ activePackage.summary?.durationLabel || 'Flexible stay' }}</strong>
              </div>
            </div>
            <div class="package-modal__meta-item" v-if="packageCostLabel(activePackage)">
              <div class="package-modal__meta-icon">
                <i class="ri-money-dollar-circle-line" />
              </div>
              <div>
                <span>Tickets & activities</span>
                <strong>{{ packageCostLabel(activePackage) }}</strong>
                <small v-if="packageCostBreakdown(activePackage).length">
                  Covers Travel, Adventure, City & Relax picks
                </small>
              </div>
            </div>
            <div
              class="package-modal__meta-item package-modal__meta-item--accent"
              v-if="activePackage.summary?.themeSummary"
            >
              <div class="package-modal__meta-icon">
                <i class="ri-compass-3-line" />
              </div>
              <div>
                <span>Theme focus</span>
                <strong>{{ activePackage.summary.themeSummary }}</strong>
              </div>
            </div>
          </div>
          <div v-if="packageCostBreakdown(activePackage).length" class="package-cost-breakdown">
            <div
              v-for="entry in packageCostBreakdown(activePackage)"
              :key="entry.theme"
              class="package-cost-breakdown__row"
            >
              <span>{{ toTitleCase(entry.label) }}</span>
              <strong>{{ entry.formatted }}</strong>
            </div>
          </div>
        </div>
        </aside>

        <main class="package-modal__picks-pane">
          <section
            v-for="section in activePackage.selections?.experiences ?? []"
            :key="`modal-${section.theme}`"
            class="package-modal__section"
          >
              <div class="package-modal__section-card">
                <div class="package-modal__section-header">
                  <div>
                    <p class="package-modal__section-eyebrow">
                      {{ toTitleCase(section.theme || section.label || 'Experience Theme') }}
                    </p>
                    <h4>{{ toTitleCase(section.label || section.theme || 'Curated Highlights') }}</h4>
                  </div>
                  <div class="package-modal__section-chip">
                    {{ section.picks?.length ?? 0 }} picks curated
                  </div>
                </div>
                <div class="package-modal__list">
                  <article
                    v-for="pick in section.picks ?? []"
                  :key="pick.id"
                  class="package-modal__card"
                >
                  <div
                    class="package-modal__card-media"
                    :class="{ 'package-modal__card-media--empty': !pick.photoUrl }"
                    :style="pick.photoUrl ? { backgroundImage: `url(${pick.photoUrl})` } : undefined"
                  >
                    <i v-if="!pick.photoUrl" class="ri-image-line" aria-hidden="true" />
                  </div>
                  <div class="package-modal__card-body">
                    <div class="package-modal__card-title">
                      {{ toTitleCase(pick.title || 'Experience Moment') }}
                    </div>
                    <div class="package-modal__card-subtitle">
                      {{ toTitleCase(pick.subtitle || pick.location || 'Awaiting description') }}
                    </div>
                    <div v-if="pick.tags?.length" class="package-modal__card-tags">
                      {{ listTags(pick.tags) }}
                    </div>
                  </div>
                </article>
              </div>
            </div>
          </section>

          <section
            v-if="activePackage.selections?.stays?.length"
            class="package-modal__section"
          >
            <div class="package-modal__section-card package-modal__section-card--grid">
              <div class="package-modal__section-header">
                <div>
                  <p class="package-modal__section-eyebrow">Accommodations</p>
                  <h4>Stay shortlist</h4>
                </div>
                <div class="package-modal__section-chip package-modal__section-chip--success">
                  {{ activePackage.selections.stays.length }} pick{{ activePackage.selections.stays.length === 1 ? '' : 's' }}
                </div>
              </div>

              <div class="stay-carousel">
                <button
                  class="stay-carousel__nav stay-carousel__nav--prev"
                  type="button"
                  @click="scrollStayCarousel('prev')"
                >
                  <i class="ri-arrow-left-s-line" />
                </button>
                <div class="stay-carousel__track" ref="stayCarouselRef">
                  <article
                    v-for="stay in activePackage.selections.stays"
                    :key="stay.id"
                    class="stay-card"
                  >
                    <div
                      class="stay-card__photo"
                      :class="{ 'stay-card__photo--empty': !stay.photoUrl }"
                      :style="stay.photoUrl ? { backgroundImage: `url(${stay.photoUrl})` } : undefined"
                    >
                      <div class="stay-card__price" v-if="stay.priceText">{{ stay.priceText }}</div>
                      <i v-if="!stay.photoUrl" class="ri-hotel-line" aria-hidden="true" />
                    </div>
                  <div class="stay-card__body">
                    <div class="stay-card__title">{{ toTitleCase(stay.title || 'Boutique Stay') }}</div>
                    <div class="stay-card__subtitle">
                      {{ toTitleCase(stay.subtitle || stay.address || stay.location || 'Awaiting details') }}
                    </div>
                    <div class="stay-card__details">
                        <span>
                          <template v-if="stayRatingValue(stay) || stayReviewLabel(stay)">
                            <i class="ri-star-smile-line" />
                            <template v-if="stayRatingValue(stay)">
                              {{ stayRatingValue(stay) }}
                            </template>
                            <small v-if="stayReviewLabel(stay)">({{ stayReviewLabel(stay) }})</small>
                          </template>
                          <template v-else>
                            <span class="stay-card__rating-empty">Awaiting reviews</span>
                          </template>
                        </span>
                        <span>{{ stay.provider || 'Curated stay' }}</span>
                      </div>
                    </div>
                  </article>
                </div>
                <button
                  class="stay-carousel__nav stay-carousel__nav--next"
                  type="button"
                  @click="scrollStayCarousel('next')"
                >
                  <i class="ri-arrow-right-s-line" />
                </button>
              </div>
            </div>
          </section>
        </main>
      </div>
    </div>

    <template #action>
      <n-space justify="end">
        <n-button type="primary" size="large" @click="openBookingDashboard()">Open Booking Dashboard</n-button>
        <n-button size="large" @click="closePackageDetails">Close</n-button>
      </n-space>
    </template>
  </n-modal>
</template>

<style scoped>
.saved-places-dashboard {
  position: relative;
  width: 100%;
  min-height: 100%;
  padding: 32px;
  color: #0f172a;
  background: radial-gradient(circle at top, rgba(45, 212, 191, 0.12), rgba(99, 102, 241, 0.08)) #f8fafc;
  overflow: hidden;
}

.saved-places-dashboard__glow {
  position: absolute;
  width: 420px;
  height: 420px;
  border-radius: 50%;
  filter: blur(90px);
  opacity: 0.55;
  z-index: 0;
}

.saved-places-dashboard__glow--teal {
  top: -140px;
  left: -120px;
  background: rgba(16, 185, 129, 0.7);
}

.saved-places-dashboard__glow--sunset {
  bottom: -160px;
  right: -100px;
  background: rgba(253, 186, 116, 0.7);
}

.saved-places-dashboard__hero {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-columns: minmax(320px, 1.5fr) minmax(260px, 1fr);
  gap: 32px;
  padding: 36px;
  border-radius: 40px;
  background: linear-gradient(130deg, rgba(255, 255, 255, 0.96), rgba(248, 250, 252, 0.9));
  border: 1px solid rgba(255, 255, 255, 0.6);
  box-shadow: 0 35px 80px rgba(15, 23, 42, 0.12);
}

.saved-places-dashboard__hero-left {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.saved-places-dashboard__hero-badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 14px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.06);
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: rgba(15, 23, 42, 0.7);
}

.saved-places-dashboard__hero-badge i {
  font-size: 1rem;
}

.saved-places-dashboard__hero-title {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.saved-places-dashboard__hero-title h1 {
  margin: 0;
  font-size: 2.8rem;
  line-height: 1.15;
  letter-spacing: -0.02em;
}

.saved-places-dashboard__hero-caption {
  font-size: 0.9rem;
  color: rgba(15, 23, 42, 0.6);
  text-transform: uppercase;
  letter-spacing: 0.14em;
}

.saved-places-dashboard__description {
  margin: 0;
  color: rgba(15, 23, 42, 0.7);
  max-width: 620px;
}

.saved-places-dashboard__hero-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.saved-places-dashboard__tag {
  padding: 6px 14px;
  border-radius: 999px;
  border: 1px solid rgba(59, 130, 246, 0.35);
  background: rgba(59, 130, 246, 0.12);
  font-size: 0.85rem;
  font-weight: 600;
}

.saved-places-dashboard__hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.saved-places-dashboard__cta {
  box-shadow: 0 20px 40px rgba(16, 185, 129, 0.25);
}

.saved-places-dashboard__cta--ghost {
  border: 1px solid rgba(15, 23, 42, 0.12);
  background: rgba(255, 255, 255, 0.7);
  color: #0f172a;
}

.saved-places-dashboard__hero-panels {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.saved-places-dashboard__stat-card {
  padding: 20px;
  border-radius: 28px;
  border: 1px solid rgba(15, 23, 42, 0.08);
  background: rgba(255, 255, 255, 0.9);
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.saved-places-dashboard__stat-card p {
  margin: 0;
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.6);
  text-transform: uppercase;
  letter-spacing: 0.12em;
}

.saved-places-dashboard__stat-card strong {
  font-size: 2rem;
  line-height: 1.2;
}

.saved-places-dashboard__stat-card span {
  font-size: 0.9rem;
  color: rgba(15, 23, 42, 0.65);
}

.saved-places-dashboard__collection {
  position: relative;
  z-index: 1;
  margin-top: 32px;
}

.saved-places-dashboard__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 24px;
  padding-top: 12px;
}

.saved-package-card {
  position: relative;
  border-radius: 32px;
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid rgba(15, 23, 42, 0.08);
  box-shadow: 0 25px 60px rgba(15, 23, 42, 0.12);
  overflow: hidden;
}

.saved-package-card__interactive {
  display: flex;
  flex-direction: column;
  gap: 0;
  cursor: pointer;
  height: 100%;
}

.saved-package-card__interactive:focus-visible {
  outline: 3px solid #22d3ee;
  outline-offset: 4px;
}

.saved-package-card__media {
  position: relative;
  width: 100%;
  height: 240px;
  background-size: cover;
  background-position: center;
}

.saved-package-card__media--empty {
  background: linear-gradient(145deg, rgba(15, 23, 42, 0.15), rgba(148, 163, 184, 0.25));
}

.saved-package-card__placeholder {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: rgba(15, 23, 42, 0.7);
}

 .saved-package-card__veil {
   position: absolute;
   inset: 0;
   display: flex;
   flex-direction: column;
   justify-content: flex-end;
   gap: 14px;
   padding: 24px;
   color: #fff;
   background: linear-gradient(180deg, rgba(6, 12, 35, 0.08), rgba(6, 12, 35, 0.92));
 }

.saved-package-card__meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.85rem;
  opacity: 0.95;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.saved-package-card__location-pill {
  padding: 4px 12px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.2);
  font-size: 0.78rem;
}

.saved-package-card__timestamp {
  font-size: 0.78rem;
  letter-spacing: 0.1em;
  opacity: 0.85;
}

.saved-package-card__headline {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.saved-package-card__title {
  margin: 0;
  font-size: 1.3rem;
  text-transform: capitalize;
  letter-spacing: -0.01em;
}

.saved-package-card__dates {
  margin: 0;
  font-size: 0.92rem;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  opacity: 0.85;
}

.saved-package-card__stats-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: center;
}

.saved-package-card__tagline {
  margin: 0;
  font-size: 0.88rem;
  opacity: 0.85;
  max-width: 60%;
}

.saved-package-card__stats {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 10px;
  justify-content: flex-end;
}

.saved-package-card__stat {
  padding: 4px 12px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.2);
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.saved-package-card__stat--ghost {
  background: rgba(15, 23, 42, 0.25);
}

.saved-package-card__body {
  padding: 20px 22px 22px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.saved-package-card__body strong {
  font-size: 1.1rem;
  color: #0f172a;
}

.saved-package-card__label {
  margin: 0;
  font-size: 0.78rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: rgba(15, 23, 42, 0.55);
}

.saved-package-card__theme {
  margin: 0;
  font-size: 0.95rem;
  color: rgba(15, 23, 42, 0.7);
  line-height: 1.35;
}

.saved-package-card__footer {
  margin-top: auto;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.7);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.saved-package-card__delete {
  position: absolute;
  top: 16px;
  right: 16px;
  z-index: 2;
  background: rgba(15, 23, 42, 0.6);
  color: #fff;
  backdrop-filter: blur(4px);
}

@media (max-width: 1024px) {
  .saved-places-dashboard {
    padding: 24px;
  }
  .saved-places-dashboard__hero {
    grid-template-columns: 1fr;
    padding: 28px;
  }
  .saved-places-dashboard__hero-panels {
    flex-direction: row;
    flex-wrap: wrap;
  }
  .saved-places-dashboard__stat-card {
    flex: 1 1 200px;
  }
  .saved-places-dashboard__grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 640px) {
  .saved-places-dashboard {
    padding: 20px 16px;
  }
  .saved-places-dashboard__hero-title h1 {
    font-size: 2rem;
  }
  .saved-package-card__media {
    height: 200px;
  }
  .saved-package-card__footer {
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
  }
}
.package-modal__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
}

.package-modal__title-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.package-modal__eyebrow {
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.08em;
  color: rgba(15, 23, 42, 0.55);
  margin: 0;
}

.package-modal__title {
  font-size: 1.4rem;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
}

.package-modal__content {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.package-modal__sheet {
  background: linear-gradient(180deg, #f8fbff 0%, #ffffff 65%);
  padding: 22px;
  border-radius: 30px;
  border: 1px solid rgba(15, 23, 42, 0.05);
  box-shadow: 0 25px 55px rgba(15, 23, 42, 0.08);
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.package-modal__sheet--split {
  display: grid;
  grid-template-columns: minmax(260px, 320px) 1fr;
  gap: 24px;
  align-items: start;
}

.package-modal__hero {
  position: relative;
  width: 100%;
  height: 260px;
  border-radius: 26px;
  overflow: hidden;
  background: linear-gradient(135deg, #1d3557, #457b9d);
}

.package-modal__summary-pane {
  position: sticky;
  top: 12px;
}

.package-modal__picks-pane {
  display: flex;
  flex-direction: column;
  gap: 18px;
  max-height: 70vh;
  overflow-y: auto;
  padding-right: 6px;
}

.package-modal__picks-pane::-webkit-scrollbar {
  width: 6px;
}

.package-modal__picks-pane::-webkit-scrollbar-thumb {
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.2);
}

.package-modal__hero--empty {
  background: linear-gradient(135deg, #cfd9df, #e2ebf0);
}

.package-modal__hero-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  gap: 12px;
  padding: 24px;
  background: linear-gradient(180deg, rgba(0, 0, 0, 0.02), rgba(0, 0, 0, 0.85));
  color: #fff;
}

.package-modal__hero-badge {
  align-self: flex-start;
  padding: 4px 12px;
  border-radius: 999px;
  font-size: 0.78rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  background: rgba(255, 255, 255, 0.18);
}

.package-modal__hero-title {
  margin: 0;
  font-size: 1.5rem;
  font-weight: 700;
}

.package-modal__hero-subtitle {
  margin: 0;
  font-size: 1rem;
  opacity: 0.9;
}

.package-modal__hero-stats {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  opacity: 0.9;
}

.package-modal__overview-card {
  background: #fff;
  border-radius: 28px;
  border: 1px solid rgba(15, 23, 42, 0.08);
  box-shadow: 0 25px 45px rgba(15, 23, 42, 0.08);
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.package-modal__meta-row {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.package-modal__meta-item {
  flex: 1 1 180px;
  border-radius: 18px;
  padding: 14px 16px;
  background: #f6f7fb;
  border: 1px solid rgba(15, 23, 42, 0.06);
  display: flex;
  gap: 12px;
  align-items: center;
}

.package-modal__meta-item span {
  display: block;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: rgba(15, 23, 42, 0.5);
}

.package-modal__meta-item strong {
  display: block;
  margin-top: 6px;
  font-size: 1.05rem;
  color: #0f172a;
}

.package-modal__meta-item small {
  display: block;
  margin-top: 2px;
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.55);
}

.package-modal__meta-item--accent {
  background: linear-gradient(135deg, #e0fbfc, #f1fff7);
  border: 1px solid rgba(34, 197, 94, 0.35);
}

.package-modal__meta-icon {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(15, 23, 42, 0.08);
  color: #0f172a;
  font-size: 1.1rem;
}

.package-modal__meta-item--accent .package-modal__meta-icon {
  background: rgba(34, 197, 94, 0.15);
  color: #0f5132;
}

.package-cost-breakdown {
  margin-top: 16px;
  padding: 12px 16px;
  border-radius: 18px;
  border: 1px solid rgba(15, 23, 42, 0.08);
  background: rgba(15, 23, 42, 0.02);
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.package-cost-breakdown__row {
  display: flex;
  justify-content: space-between;
  font-size: 0.9rem;
  color: rgba(15, 23, 42, 0.75);
}

.package-cost-breakdown__row strong {
  font-weight: 600;
  color: #0f172a;
}

.package-modal__section {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.package-modal__section-card {
  background: #fff;
  border-radius: 24px;
  border: 1px solid rgba(15, 23, 42, 0.05);
  padding: 18px;
  box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.02);
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.package-modal__section-card--grid {
  padding-bottom: 6px;
}

.package-modal__section-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.package-modal__section-eyebrow {
  margin: 0;
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: rgba(15, 23, 42, 0.5);
}

.package-modal__section-header h4 {
  margin: 2px 0 0;
  font-size: 1.1rem;
  color: #0f172a;
}

.package-modal__section-count {
  display: flex;
  align-items: center;
}

.package-modal__section-chip {
  padding: 6px 14px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.06);
  font-size: 0.78rem;
  font-weight: 600;
  color: #0f172a;
}

.package-modal__section-chip--success {
  background: rgba(34, 197, 94, 0.15);
  color: #0f5132;
}

.package-modal__list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.package-modal__list--grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 14px;
}

.package-modal__card {
  display: flex;
  gap: 14px;
  border: 1px solid rgba(15, 23, 42, 0.08);
  border-radius: 18px;
  padding: 12px;
  background: #fff;
  box-shadow: 0 18px 38px rgba(15, 23, 42, 0.08);
}

.package-modal__card-media {
  width: 72px;
  height: 72px;
  border-radius: 14px;
  background-size: cover;
  background-position: center;
  background-color: rgba(15, 23, 42, 0.06);
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(15, 23, 42, 0.6);
  font-size: 22px;
}

.package-modal__card-media--empty {
  background-color: rgba(15, 23, 42, 0.08);
}

.package-modal__card-body {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.package-modal__card-title {
  font-weight: 600;
  color: #0f172a;
}

.package-modal__card-subtitle {
  font-size: 0.9rem;
  color: rgba(15, 23, 42, 0.65);
}

.package-modal__card-tags {
  font-size: 0.8rem;
  color: rgba(15, 23, 42, 0.6);
}

.stay-carousel {
  position: relative;
  padding: 0 42px;
}

.stay-carousel__track {
  display: flex;
  gap: 16px;
  overflow-x: auto;
  scroll-behavior: smooth;
  padding-bottom: 8px;
}

.stay-carousel__track::-webkit-scrollbar {
  height: 6px;
}

.stay-carousel__track::-webkit-scrollbar-thumb {
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.2);
}

.stay-carousel__nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: none;
  background: rgba(255, 255, 255, 0.9);
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.2rem;
  color: #0f172a;
}

.stay-carousel__nav--prev {
  left: 0;
}

.stay-carousel__nav--next {
  right: 0;
}

.stay-card {
  min-width: 260px;
  max-width: 300px;
  border-radius: 18px;
  border: 1px solid rgba(15, 23, 42, 0.08);
  overflow: hidden;
  background: #fff;
  box-shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
  flex-shrink: 0;
}

.stay-card__photo {
  height: 150px;
  background-size: cover;
  background-position: center;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(15, 23, 42, 0.7);
  font-size: 1.4rem;
}

.stay-card__photo--empty {
  background: rgba(15, 23, 42, 0.06);
}

.stay-card__price {
  position: absolute;
  top: 10px;
  left: 10px;
  background: rgba(0, 0, 0, 0.65);
  color: #fff;
  padding: 4px 10px;
  border-radius: 999px;
  font-weight: 600;
  font-size: 0.85rem;
}

.stay-card__body {
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.stay-card__title {
  font-weight: 600;
}

.stay-card__subtitle {
  font-size: 0.8rem;
  color: rgba(15, 23, 42, 0.6);
}

.stay-card__details {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: 0.8rem;
  color: rgba(15, 23, 42, 0.7);
}

.stay-card__details i {
  color: #f4b400;
  margin-right: 4px;
}

.stay-card__rating-empty {
  color: rgba(15, 23, 42, 0.5);
  font-size: 0.78rem;
}
</style>
