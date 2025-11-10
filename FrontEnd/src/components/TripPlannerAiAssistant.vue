<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
  conversation: {
    type: Array,
    default: () => [],
  },
  curation: {
    type: Object,
    default: () => ({}),
  },
  curationDisabled: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits([
  'toggle-experience',
  'toggle-stay',
  'auto-fill',
  'cancel-curation',
  'confirm-curation',
])

const statusMeta = computed(() => ({
  pending: { icon: 'ri-time-line', label: 'Pending', tone: 'default' },
  running: { icon: 'ri-loader-4-line', label: 'Working', tone: 'info' },
  done: { icon: 'ri-check-line', label: 'Done', tone: 'success' },
  error: { icon: 'ri-error-warning-line', label: 'Error', tone: 'error' },
}))

const stayCarouselRef = ref(null)
const themeCarouselRefs = ref(new Map())

function scrollStayCarousel(direction = 'next') {
  const el = stayCarouselRef.value
  if (!el) return
  const amount = direction === 'next' ? 320 : -320
  el.scrollBy({ behavior: 'smooth', left: amount })
}

function setThemeCarouselRef(key, el) {
  const map = themeCarouselRefs.value
  if (!el) {
    map.delete(key)
  } else {
    map.set(key, el)
  }
}

function scrollThemeCarousel(key, direction = 'next') {
  const el = themeCarouselRefs.value.get(key)
  if (!el) return
  const amount = direction === 'next' ? 260 : -260
  el.scrollBy({ behavior: 'smooth', left: amount })
}

function stayPriceLabel(item) {
  const raw =
    item?.priceText ||
    item?.metadata?.priceRange?.text ||
    null
  if (!raw) return 'Price unavailable'
  const normalized = raw.includes('/night') || raw.includes('/ night') ? raw : `${raw} / night`
  return normalized
}

function stayPriceDisplay(item) {
  const label = stayPriceLabel(item)
  return label === 'Price unavailable' ? null : label
}

function staySubtitle(item) {
  const primary = sanitizeSubtitle(item?.subtitle)
  const fallback =
    sanitizeSubtitle(item?.metadata?.address) ||
    sanitizeSubtitle(item?.metadata?.addressFull) ||
    'Malaysia'
  return primary || fallback
}

function stayLocationLabel(item) {
  const city =
    sanitizeSubtitle(item?.metadata?.city) ||
    sanitizeSubtitle(item?.metadata?.address) ||
    sanitizeSubtitle(item?.metadata?.addressFull)
  if (city) {
    return city
  }
  return sanitizeSubtitle(item?.subtitle) || 'Malaysia'
}

function stayProviderTag(item) {
  const provider = (item?.provider || item?.metadata?.provider || 'google').toLowerCase()
  switch (provider) {
    case 'google':
      return 'google'
    case 'booking':
      return 'booking.com'
    case 'fallback':
      return 'curated stay'
    default:
      return provider
  }
}

function stayThemeTag(item) {
  return item?.tags?.[0] || item?.metadata?.theme || 'Stay'
}

function experiencePriceLabel(item, theme) {
  if (!item) return null
  if (String(theme).toLowerCase() !== 'adventure') {
    return null
  }
  return item.priceText || null
}

function stayRatingValue(item) {
  const rating = Number(item?.rating ?? item?.metadata?.rating)
  if (!Number.isFinite(rating)) {
    return null
  }
  return Math.round(rating * 10) / 10
}

function stayReviewLabel(item) {
  const rawCount = item?.reviews ?? item?.metadata?.reviewCount
  const reviewSummary = sanitizeSubtitle(item?.metadata?.reviewSummary || item?.reviewSummary)
  const parts = []
  if (rawCount != null && rawCount !== '') {
    const countNumber = Number(rawCount)
    if (Number.isFinite(countNumber) && countNumber > 0) {
      let countLabel = `${countNumber}`
      try {
        countLabel = countNumber.toLocaleString('en-MY')
      } catch {
        countLabel = String(countNumber)
      }
      parts.push(`${countLabel} reviews`)
    } else if (typeof rawCount === 'string' && rawCount.trim()) {
      parts.push(rawCount.trim())
    }
  }
  if (reviewSummary) {
    parts.push(reviewSummary)
  }
  return parts.join(' | ')
}

function sanitizeSubtitle(value) {
  if (!value) return ''
  const trimmed = String(value).trim()
  if (!trimmed) return ''
  const lower = trimmed.toLowerCase()
  if (lower === 'fallback' || lower === 'curated stay') return ''
  return trimmed
}
</script>

<template>
  <n-card title="AI Assistant" size="small" class="ai-assistant-card">
    <div v-if="conversation.length" class="ai-chat">
      <div
        v-for="entry in conversation"
        :key="entry.id"
        class="ai-bubble ai-bubble--assistant"
      >
        <header class="ai-bubble__header">
          <span>{{ entry.title }}</span>
          <n-tag
            v-if="entry.status"
            size="tiny"
            :type="statusMeta[entry.status]?.tone ?? 'default'"
            class="ai-status-tag"
          >
            <n-icon size="12" v-if="statusMeta[entry.status]?.icon">
              <i :class="statusMeta[entry.status].icon" />
            </n-icon>
            {{ statusMeta[entry.status]?.label ?? entry.status }}
          </n-tag>
        </header>
        <p>{{ entry.message }}</p>
      </div>
    </div>
    <n-empty v-else description="No AI activity yet.">
      <template #extra>
        Provide your preferences and tap "Plan a trip with AI".
      </template>
    </n-empty>

    <div v-if="curation.visible" class="ai-bubble ai-bubble--assistant ai-curation-pane">
      <header class="curation-header">
        <div>
          <span class="curation-label">Select experiences & stays</span>
          <p>Tap to pick at least one choice per theme. Gemini will prioritise what you select.</p>
        </div>
        <n-button text size="small" @click="$emit('auto-fill')">Auto pick top</n-button>
      </header>
      <n-alert v-if="curation.error" type="warning" style="margin-bottom: 8px;">
        {{ curation.error }}
      </n-alert>

      <section
        v-for="section in curation.themeResults"
        :key="section.theme"
        class="curation-section"
      >
        <div class="curation-section__title">
          <i :class="section.icon" />
          <div>
            <strong>{{ section.label }}</strong>
            <small>{{ section.description }}</small>
          </div>
          <n-tag size="tiny" type="success">
            {{ curation.selections.experiences.get(section.theme)?.size ?? 0 }} picked
          </n-tag>
        </div>
        <div class="curation-theme-carousel">
          <button
            class="curation-theme-carousel__nav curation-theme-carousel__nav--prev"
            type="button"
            @click="scrollThemeCarousel(section.theme, 'prev')"
          >
            <i class="ri-arrow-left-s-line" />
          </button>
          <div
            class="curation-theme-carousel__track"
            :ref="(el) => setThemeCarouselRef(section.theme, el)"
          >
            <article
              v-for="item in section.items"
              :key="item.id"
              :class="[
                'curation-card',
                'experience-card',
                { 'curation-card--selected': curation.selections.experiences.get(section.theme)?.has(item.id) },
              ]"
              @click="$emit('toggle-experience', section.theme, item)"
            >
              <div v-if="item.photoUrl" class="curation-card__media experience-card__media">
                <img :src="item.photoUrl" :alt="item.title" />
                <div class="experience-card__price-pill" v-if="experiencePriceLabel(item, section.theme)">
                  {{ experiencePriceLabel(item, section.theme) }}
                </div>
              </div>
              <div class="curation-card__body experience-card__body">
                <div class="experience-card__headline">
                  <div class="curation-card__title">{{ item.title }}</div>
                  <n-tag size="tiny" type="info" bordered>{{ item.provider || 'google' }}</n-tag>
                </div>
                <div class="curation-card__subtitle">{{ item.subtitle }}</div>
                <div class="curation-card__meta">
                  <span v-if="item.rating">
                    <i class="ri-star-smile-line" />
                    {{ item.rating }}
                    <small v-if="item.reviews">({{ item.reviews }})</small>
                  </span>
                  <span v-if="experiencePriceLabel(item, section.theme)">
                    {{ experiencePriceLabel(item, section.theme) }}
                  </span>
                </div>
              </div>
            </article>
          </div>
          <button
            class="curation-theme-carousel__nav curation-theme-carousel__nav--next"
            type="button"
            @click="scrollThemeCarousel(section.theme, 'next')"
          >
            <i class="ri-arrow-right-s-line" />
          </button>
        </div>
      </section>

      <section v-if="curation.stayResults.length" class="curation-section">
        <div class="curation-section__title">
          <i class="ri-hotel-bed-line" />
          <div>
            <strong>Stay shortlist</strong>
            <small>Aligned with your comfort preference</small>
          </div>
          <n-tag size="tiny" type="info">
            {{ curation.selections.stays.size }} picked
          </n-tag>
        </div>

        <div class="curation-stay-carousel">
          <button
            class="curation-stay-carousel__nav curation-stay-carousel__nav--prev"
            type="button"
            @click="scrollStayCarousel('prev')"
          >
            <i class="ri-arrow-left-s-line" />
          </button>
          <div class="curation-stay-carousel__track" ref="stayCarouselRef">
            <article
              v-for="item in curation.stayResults"
              :key="item.id"
              :class="[
                'curation-stay-card',
                { 'curation-stay-card--selected': curation.selections.stays.has(item.id) },
              ]"
              @click="$emit('toggle-stay', item)"
            >
              <div
                class="curation-stay-card__photo"
                :class="{ 'curation-stay-card__photo--empty': !item.photoUrl }"
                :style="item.photoUrl ? { backgroundImage: `url(${item.photoUrl})` } : undefined"
              >
                <div class="curation-stay-card__price-pill">{{ stayPriceLabel(item) }}</div>
                <i v-if="!item.photoUrl" class="ri-hotel-line" aria-hidden="true" />
              </div>
              <div class="curation-stay-card__body">
                <div class="curation-stay-card__headline">
                  <div class="curation-stay-card__title">{{ item.title }}</div>
                  <n-tag size="tiny" type="info" bordered>{{ stayThemeTag(item) }}</n-tag>
                </div>
                <div class="curation-stay-card__address">{{ staySubtitle(item) }}</div>
                <div class="curation-stay-card__location" v-if="stayLocationLabel(item)">
                  <i class="ri-map-pin-line" aria-hidden="true" />
                  <span>{{ stayLocationLabel(item) }}</span>
                </div>
                <div class="curation-stay-card__meta-row">
                  <div class="curation-stay-card__rating-block">
                    <div v-if="stayRatingValue(item) || stayReviewLabel(item)" class="curation-stay-card__rating-score">
                      <i class="ri-star-smile-line" />
                      <strong v-if="stayRatingValue(item)">{{ stayRatingValue(item) }}</strong>
                      <span v-if="stayReviewLabel(item)">{{ stayReviewLabel(item) }}</span>
                    </div>
                    <span v-else class="curation-stay-card__rating-empty">Awaiting reviews</span>
                  </div>
                  <div class="curation-stay-card__price" v-if="stayPriceDisplay(item)">
                    {{ stayPriceDisplay(item) }}
                  </div>
                </div>
                <div class="curation-stay-card__rating-row">
                  <n-tag size="tiny" bordered type="primary">{{ stayProviderTag(item) }}</n-tag>
                </div>
              </div>
            </article>
          </div>
          <button
            class="curation-stay-carousel__nav curation-stay-carousel__nav--next"
            type="button"
            @click="scrollStayCarousel('next')"
          >
            <i class="ri-arrow-right-s-line" />
          </button>
        </div>
      </section>

      <footer class="curation-footer">
        <n-button tertiary size="small" @click="$emit('cancel-curation')">Cancel</n-button>
        <n-button
          size="small"
          type="primary"
          :disabled="curationDisabled"
          @click="$emit('confirm-curation')"
        >
          Use these picks
        </n-button>
      </footer>
    </div>
  </n-card>
</template>

<style scoped>
.ai-assistant-card {
  height: 100%;
  display: flex;
  flex-direction: column;
}

.ai-chat {
  display: flex;
  flex-direction: column;
  gap: 14px;
  margin-bottom: 12px;
}

.ai-bubble {
  padding: 12px 14px;
  border-radius: 18px;
  background: rgba(248, 250, 255, 0.8);
  border: 1px solid rgba(15, 23, 42, 0.08);
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
}

.ai-bubble__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  margin-bottom: 6px;
}

.ai-bubble p {
  margin: 0;
  color: rgba(15, 23, 42, 0.75);
  line-height: 1.45;
}

.ai-status-tag {
  text-transform: capitalize;
}

.ai-curation-pane {
  margin-top: 12px;
  background: #fff;
  border: 1px solid rgba(34, 197, 94, 0.25);
  box-shadow: 0 20px 40px rgba(34, 197, 94, 0.15);
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.curation-header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}

.curation-label {
  font-weight: 600;
  font-size: 1rem;
}

.curation-header p {
  margin: 4px 0 0;
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.65);
}

.curation-section {
  border: 1px solid rgba(15, 23, 42, 0.08);
  border-radius: 16px;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.curation-section__title {
  display: flex;
  align-items: center;
  gap: 10px;
}

.curation-section__title i {
  font-size: 18px;
  color: #0f766e;
}

.curation-section__title small {
  display: block;
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.6);
}

.curation-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 10px;
}

.curation-theme-carousel {
  position: relative;
  padding: 0 36px;
}

.curation-theme-carousel__track {
  display: flex;
  gap: 10px;
  overflow-x: auto;
  scroll-behavior: smooth;
  padding-bottom: 6px;
}

.curation-theme-carousel__track::-webkit-scrollbar {
  height: 4px;
}

.curation-theme-carousel__track::-webkit-scrollbar-thumb {
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.2);
}

.curation-theme-carousel__nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  width: 30px;
  height: 30px;
  border-radius: 50%;
  border: none;
  background: rgba(255, 255, 255, 0.95);
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 1rem;
  color: #0f172a;
}

.curation-theme-carousel__nav--prev {
  left: 0;
}

.curation-theme-carousel__nav--next {
  right: 0;
}

.curation-card {
  border: 1px solid rgba(15, 23, 42, 0.1);
  border-radius: 14px;
  cursor: pointer;
  overflow: hidden;
  background: #fff;
  transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.15s ease;
  display: flex;
  flex-direction: column;
}

.curation-card:hover {
  border-color: rgba(34, 197, 94, 0.5);
  box-shadow: 0 10px 20px rgba(34, 197, 94, 0.2);
  transform: translateY(-1px);
}

.curation-card--selected {
  border-color: rgba(34, 197, 94, 0.85);
  box-shadow: 0 15px 30px rgba(34, 197, 94, 0.25);
}

.curation-card__media {
  width: 100%;
  height: 110px;
  overflow: hidden;
}

.curation-card__media img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.curation-card__body {
  padding: 10px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.curation-card__title {
  font-weight: 600;
  font-size: 0.95rem;
}

.curation-card__subtitle {
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.65);
}

.curation-card__meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.65);
}

.experience-card {
  min-width: 240px;
  max-width: 280px;
}

.experience-card__media {
  position: relative;
  height: 130px;
}

.experience-card__price-pill {
  position: absolute;
  top: 8px;
  left: 8px;
  padding: 3px 10px;
  font-size: 0.72rem;
  border-radius: 999px;
  background: rgba(4, 120, 87, 0.92);
  color: #fff;
  font-weight: 600;
}

.experience-card__body {
  gap: 6px;
}

.experience-card__headline {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 6px;
}

.curation-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

.curation-stay-carousel {
  position: relative;
  padding: 0 40px;
}

.curation-stay-carousel__track {
  display: flex;
  gap: 14px;
  overflow-x: auto;
  scroll-behavior: smooth;
  padding-bottom: 6px;
}

.curation-stay-carousel__track::-webkit-scrollbar {
  height: 6px;
}

.curation-stay-carousel__track::-webkit-scrollbar-thumb {
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.25);
}

.curation-stay-carousel__nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  width: 34px;
  height: 34px;
  border-radius: 50%;
  border: none;
  background: rgba(255, 255, 255, 0.95);
  box-shadow: 0 12px 24px rgba(15, 23, 42, 0.15);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 1.1rem;
  color: #0f172a;
}

.curation-stay-carousel__nav--prev {
  left: 0;
}

.curation-stay-carousel__nav--next {
  right: 0;
}

.curation-stay-card {
  min-width: 230px;
  max-width: 280px;
  border: 1px solid rgba(15, 23, 42, 0.08);
  border-radius: 18px;
  overflow: hidden;
  background: #fff;
  box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
  cursor: pointer;
  transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}

.curation-stay-card--selected {
  border-color: rgba(34, 197, 94, 0.9);
  box-shadow: 0 16px 34px rgba(34, 197, 94, 0.25);
}

.curation-stay-card__photo {
  height: 150px;
  background-size: cover;
  background-position: center;
  position: relative;
  border-top-left-radius: 18px;
  border-top-right-radius: 18px;
  overflow: hidden;
}

.curation-stay-card__photo--empty {
  background: rgba(15, 23, 42, 0.06);
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(15, 23, 42, 0.6);
  font-size: 1.4rem;
}

.curation-stay-card__price-pill {
  position: absolute;
  top: 10px;
  left: 10px;
  z-index: 2;
  padding: 4px 12px;
  border-radius: 999px;
  background: rgba(4, 120, 87, 0.92);
  color: #fff;
  font-size: 0.78rem;
  font-weight: 600;
}
.curation-stay-card__body {
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.curation-stay-card__headline {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  align-items: flex-start;
}

.curation-stay-card__title {
  font-weight: 600;
}

.curation-stay-card__address {
  font-size: 0.82rem;
  color: rgba(15, 23, 42, 0.65);
  min-height: 36px;
}

.curation-stay-card__location {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.78rem;
  color: rgba(15, 23, 42, 0.7);
  margin-top: 2px;
}

.curation-stay-card__location i {
  color: rgba(15, 23, 42, 0.45);
}

.curation-stay-card__meta-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 8px;
  margin-top: 4px;
}

.curation-stay-card__rating-block {
  display: flex;
  flex-direction: column;
  font-size: 0.78rem;
  color: rgba(15, 23, 42, 0.75);
  gap: 2px;
}

.curation-stay-card__rating-score {
  display: flex;
  align-items: center;
  gap: 4px;
}

.curation-stay-card__rating-block i {
  color: #f4b400;
}

.curation-stay-card__rating-block strong {
  font-size: 0.95rem;
  color: #0f172a;
}

.curation-stay-card__price {
  font-weight: 600;
  font-size: 0.85rem;
  color: #065f46;
}

.curation-stay-card__rating-row {
  display: flex;
  justify-content: flex-start;
  align-items: center;
  font-size: 0.78rem;
  color: rgba(15, 23, 42, 0.7);
  margin-top: 6px;
}

.curation-stay-card__rating-empty {
  color: rgba(15, 23, 42, 0.5);
}

</style>
