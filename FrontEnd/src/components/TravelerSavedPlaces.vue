<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useMessage } from 'naive-ui'
import { fetchSavedPlacePackages, deleteSavedPlacePackage } from '../services/tripPlannerService.js'

const props = defineProps({
  travelerId: {
    type: [Number, String],
    required: true,
  },
})

const packages = ref([])
const loading = ref(false)
const message = useMessage()
const detailsVisible = ref(false)
const activePackage = ref(null)
const heroPhoto = computed(() => (activePackage.value ? computeCoverPhoto(activePackage.value) : ''))
const stayCarouselRef = ref(null)

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
</script>

<template>
  <div class="saved-places-feed">
    <n-card
      title="Saved places"
      :segmented="{ content: true, footer: false }"
      class="saved-places-card"
    >
      <n-spin :show="loading">
        <n-empty
          v-if="!packages.length && !loading"
          description="You haven't saved any experience packages yet."
        >
          <template #extra>
            Plan a trip and pick experiences to save them here.
          </template>
        </n-empty>

        <div v-else class="saved-place-grid">
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
                class="saved-package-cover"
                :class="{ 'saved-package-cover--empty': !computeCoverPhoto(pkg) }"
                :style="computeCoverPhoto(pkg) ? { backgroundImage: `url(${computeCoverPhoto(pkg)})` } : undefined"
              >
                <div v-if="!computeCoverPhoto(pkg)" class="saved-package-cover__placeholder">
                  <i class="ri-image-2-line" aria-hidden="true" />
                  <span>Photo coming soon</span>
                </div>
                <div class="saved-package-cover__overlay">
                  <div class="saved-package-cover__meta">
                    <n-tag v-if="pkg.destination" round size="small" type="success">
                      {{ pkg.destination }}
                    </n-tag>
                    <span>{{ formatTimestamp(pkg.createdAt) }}</span>
                  </div>
                  <div class="saved-package-cover__title">
                    {{ pkg.title || 'Saved package' }}
                  </div>
                  <div class="saved-package-cover__summary" v-if="pkg.summary?.dateRange">
                    {{ pkg.summary.dateRange }}
                    <span v-if="pkg.summary?.durationLabel">
                      - {{ pkg.summary.durationLabel }}
                    </span>
                  </div>
                  <div class="saved-package-cover__stats">
                    <n-tag round size="tiny" type="info">
                      {{ packageSelectionCount(pkg) }} selections
                    </n-tag>
                    <span>Click to view details</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="saved-package-card__caption">
              <div class="saved-package-card__caption-title">
                {{ pkg.destination || pkg.title || 'Traveler package' }}
              </div>
              <n-text depth="3">
                {{ pkg.summary?.themeSummary || 'Saved picks from your planner' }}
              </n-text>
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
                  class="saved-package-delete"
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
    </n-card>
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
                  <span>{{ packageSelectionCount(activePackage) }} total selections</span>
                  <span v-if="activePackage.summary?.themeSummary">{{ activePackage.summary.themeSummary }}</span>
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
                  <p class="package-modal__section-eyebrow">{{ section.theme || section.label }}</p>
                  <h4>{{ section.label }}</h4>
                </div>
                <div class="package-modal__section-chip">
                  {{ section.picks?.length ?? 0 }} picked
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
                    <div class="package-modal__card-title">{{ pick.title }}</div>
                    <div class="package-modal__card-subtitle">{{ pick.subtitle }}</div>
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
                      <div class="stay-card__title">{{ stay.title }}</div>
                      <div class="stay-card__subtitle">{{ stay.subtitle }}</div>
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
        <n-button @click="closePackageDetails">Close</n-button>
      </n-space>
    </template>
  </n-modal>
</template>

<style scoped>
.saved-places-feed {
  width: 100%;
  padding-bottom: 32px;
}

.saved-places-card {
  width: 100%;
  display: block;
  border-radius: 20px;
  box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
}

.saved-place-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 20px;
  align-items: stretch;
}

@media (min-width: 1360px) {
  .saved-place-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

.saved-package-card {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.saved-package-card__interactive {
  border: none;
  background: none;
  padding: 0;
  width: 100%;
  border-radius: 18px;
  overflow: hidden;
  cursor: pointer;
  box-shadow: 0 12px 30px rgba(15, 23, 42, 0.15);
  transition: transform 0.25s ease, box-shadow 0.25s ease;
  display: block;
}

.saved-package-card__interactive:hover {
  transform: translateY(-3px);
  box-shadow: 0 18px 38px rgba(15, 23, 42, 0.18);
}

.saved-package-card__interactive:focus-visible {
  outline: 3px solid #22d3ee;
  outline-offset: 3px;
}

.saved-package-cover {
  position: relative;
  width: 100%;
  padding-top: 72%;
  background: linear-gradient(135deg, #1d3557, #457b9d);
  background-size: cover;
  background-position: center;
}

.saved-package-cover--empty {
  background: linear-gradient(135deg, #cfd9df, #e2ebf0);
}

.saved-package-cover__placeholder {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  gap: 6px;
  color: rgba(15, 23, 42, 0.65);
  background: rgba(255, 255, 255, 0.45);
  font-weight: 600;
}

.saved-package-cover__placeholder i {
  font-size: 1.8rem;
}

.saved-package-cover__overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(180deg, rgba(0, 0, 0, 0.04), rgba(0, 0, 0, 0.8));
  color: #fff;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  padding: 16px;
  gap: 8px;
}

.saved-package-cover__meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.82rem;
  opacity: 0.9;
}

.saved-package-cover__title {
  font-size: 1.05rem;
  font-weight: 700;
}

.saved-package-cover__summary {
  font-size: 0.82rem;
  opacity: 0.85;
}

.saved-package-cover__stats {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.95;
}

.saved-package-card__caption {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 0 4px;
}

.saved-package-card__caption-title {
  font-weight: 600;
  font-size: 0.95rem;
  color: #0f172a;
}

.saved-package-card__caption :deep(.n-text) {
  font-size: 0.82rem;
  color: rgba(15, 23, 42, 0.65);
}

.saved-package-delete {
  position: absolute;
  top: 12px;
  right: 16px;
  z-index: 5;
  background: rgba(15, 23, 42, 0.45);
  color: #fff;
  backdrop-filter: blur(4px);
}

.saved-package-delete:hover {
  color: #fff;
  background: rgba(15, 23, 42, 0.6);
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
