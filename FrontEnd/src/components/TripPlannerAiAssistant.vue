<script setup>
import { computed, ref, watch, onBeforeUnmount, nextTick } from 'vue'

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

const timelineSteps = computed(() =>
  props.conversation.map((entry, index) => {
    const resolvedStatus = entry.status ?? 'pending'
    const meta = statusMeta.value[resolvedStatus] ?? statusMeta.value.pending
    return {
      ...entry,
      order: index + 1,
      status: resolvedStatus,
      meta,
      isDone: resolvedStatus === 'done',
      isRunning: resolvedStatus === 'running',
      isError: resolvedStatus === 'error',
      isPending: !resolvedStatus || resolvedStatus === 'pending',
    }
  }),
)

const hasConversation = computed(() => timelineSteps.value.length > 0)
const completedCount = computed(() => timelineSteps.value.filter((step) => step.isDone).length)
const timelineProgress = computed(() => {
  if (!timelineSteps.value.length) {
    return 0
  }
  return Math.min(100, Math.round((completedCount.value / timelineSteps.value.length) * 100))
})
const timelineComplete = computed(() => timelineProgress.value >= 100)
const tripRequestStep = computed(() => timelineSteps.value.find((step) => step.title === 'Trip request'))
const completedSteps = computed(() => timelineSteps.value.filter((step) => step.isDone))
const runningStep = computed(() => timelineSteps.value.find((step) => step.isRunning))
const nextPendingStep = computed(() =>
  timelineSteps.value.find((step) => !step.isDone && !step.isError && !step.isRunning),
)
const statusSummary = computed(() => ({
  done: completedSteps.value.length,
  total: timelineSteps.value.length,
  runningLabel: runningStep.value?.title || 'Cruising',
  nextTitle: nextPendingStep.value?.title || 'Everything locked in',
  nextMessage:
    nextPendingStep.value?.message ||
    (timelineComplete.value ? 'Review your curated picks anytime.' : 'AI is tying up final details.'),
}))
const heroMood = computed(() => {
  if (!timelineSteps.value.length) {
    return 'Gemini is ready to craft slow, cozy adventures. Tell it where to begin.'
  }
  if (timelineComplete.value) {
    return 'Everything is ready. Review the picks below or fine-tune your selections.'
  }
  if (timelineProgress.value >= 60) {
    return 'Itinerary magic is almost ready — customise any detail while we wrap up.'
  }
  return 'Gathering travel vibes, calculating routes, and curating local favorites for you.'
})

const fireworkBursts = Object.freeze([0, 1, 2, 3])
const fireworksActive = ref(false)
let fireworksTimer = null
const shouldScrollToCuration = ref(false)
let scrollAttemptHandle = null

watch(
  () => timelineComplete.value,
  (complete) => {
    clearTimeout(fireworksTimer)
    if (complete) {
      fireworksActive.value = true
      fireworksTimer = setTimeout(() => {
        fireworksActive.value = false
      }, 3200)
    } else {
      fireworksActive.value = false
    }
  },
)

watch(
  () => props.conversation.map((entry) => ({ id: entry.id, status: entry.status })),
  () => {
    // Resets scroll trigger if conversation is cleared
    if (!props.conversation.length) {
      shouldScrollToCuration.value = false
    }
  },
  { immediate: true, deep: true },
)

onBeforeUnmount(() => {
  clearTimeout(fireworksTimer)
  if (scrollAttemptHandle) {
    clearTimeout(scrollAttemptHandle)
  }
})

watch(
  () => fireworksActive.value,
  (active, previous) => {
    if (!active && previous && timelineComplete.value) {
      requestScrollToCuration()
    }
  },
)

watch(
  () => props.curation?.visible,
  (visible) => {
    if (visible) {
      queueScrollToCuration()
    }
  },
)

function requestScrollToCuration() {
  shouldScrollToCuration.value = true
  queueScrollToCuration()
}

function queueScrollToCuration(retry = 0) {
  if (!shouldScrollToCuration.value) return
  if (scrollAttemptHandle) {
    clearTimeout(scrollAttemptHandle)
  }
  const delay = retry === 0 ? 350 : 500
  scrollAttemptHandle = setTimeout(() => {
    const success = attemptScrollToCuration()
    if (!success && retry < 6) {
      queueScrollToCuration(retry + 1)
    }
  }, delay)
}

function attemptScrollToCuration() {
  if (!shouldScrollToCuration.value) return true
  const pane = document.querySelector('.ai-curation-pane')
  if (pane && pane.offsetHeight > 200) {
    pane.scrollIntoView({ behavior: 'smooth', block: 'start' })
    shouldScrollToCuration.value = false
    return true
  }
  return false
}

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
      return ''
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

function experiencePriceLabel(item) {
  if (!item) return null
  if (item.priceText) {
    return item.priceText
  }
  if (item.metadata?.priceRange?.text) {
    return item.metadata.priceRange.text
  }
  return null
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
  <n-card size="small" class="ai-assistant-card">
    <section class="assistant-hero" :class="{ 'assistant-hero--idle': !hasConversation }">
      <span class="assistant-hero__orb assistant-hero__orb--one" aria-hidden="true"></span>
      <span class="assistant-hero__orb assistant-hero__orb--two" aria-hidden="true"></span>
      <div class="assistant-hero__header">
        <div class="assistant-hero__meta">
          <p class="assistant-hero__kicker">
            <i class="ri-sparkling-2-line" />
            Gemini trip studio
          </p>
          <h3>{{ tripRequestStep ? 'Cozy trip underway' : 'Plan with a chill co-pilot' }}</h3>
          <p v-if="tripRequestStep" class="assistant-hero__summary">
            {{ tripRequestStep.message }}
          </p>
          <p class="assistant-hero__mood">{{ heroMood }}</p>
        </div>
        <article v-if="hasConversation" class="assistant-insight-card assistant-insight-card--inline">
          <p class="assistant-insight__label">Milestones</p>
          <strong>{{ statusSummary.done }} / {{ statusSummary.total || 'Infinity' }}</strong>
          <span>
            {{ timelineComplete ? 'Every stage is locked in.' : `${statusSummary.runningLabel} is live.` }}
          </span>
        </article>
      </div>
      <div class="assistant-progress-block">
        <div class="assistant-progress" v-if="hasConversation">
          <div class="assistant-progress__label">
            <span>Trip readiness</span>
          </div>
          <div class="assistant-progress__track">
            <div class="assistant-progress__fill" :style="{ width: `${timelineProgress}%` }">
              <span
                class="assistant-progress__runner"
                :class="{ 'assistant-progress__runner--finish': timelineComplete }"
              >
                <i class="ri-roadster-line" />
              </span>
            </div>
            <span class="assistant-progress__flag">
              <i class="ri-flag-2-line" />
            </span>
          </div>
          <div class="assistant-progress__meta">
            {{ timelineProgress }}% {{ timelineComplete ? '— ready to dive in.' : '— racing to the finish line.' }}
          </div>
        </div>
        <div class="assistant-progress assistant-progress--idle" v-else>
          <div class="assistant-progress__label">
            <span>Trip readiness</span>
          </div>
          <div class="assistant-progress__track assistant-progress__track--idle">
            <div class="assistant-progress__pulse" />
          </div>
          <div class="assistant-progress__meta">Start a request to light up the track.</div>
        </div>
      </div>
      <transition name="fireworks">
        <div v-if="fireworksActive" class="assistant-fireworks">
          <span
            v-for="burst in fireworkBursts"
            :key="burst"
            :class="['assistant-firework', `assistant-firework--${burst}`]"
          />
        </div>
      </transition>
    </section>

    <div v-if="hasConversation" class="assistant-timeline-wrapper">
      <transition-group name="timeline-fade" tag="div" class="assistant-timeline">
        <article
          v-for="step in timelineSteps"
          :key="step.id"
          class="timeline-step"
          :class="{
            'timeline-step--done': step.isDone,
            'timeline-step--running': step.isRunning,
            'timeline-step--error': step.isError,
          }"
        >
          <div class="timeline-step__indicator">
            <span class="timeline-step__bullet">
              <i v-if="step.isDone" class="ri-check-line" />
              <i v-else-if="step.isError" class="ri-error-warning-line" />
              <i v-else class="ri-sparkling-2-line" />
            </span>
            <span class="timeline-step__rail" />
          </div>
          <div class="timeline-step__body">
            <header>
              <div>
                <p class="timeline-step__title">{{ step.title }}</p>
                <p class="timeline-step__message">{{ step.message }}</p>
              </div>
              <n-tag size="tiny" :type="step.meta?.tone ?? 'default'" class="timeline-step__tag">
                <n-icon size="12" v-if="step.meta?.icon">
                  <i :class="step.meta.icon" />
                </n-icon>
                {{ step.meta?.label ?? step.status ?? 'Pending' }}
              </n-tag>
            </header>
          </div>
        </article>
      </transition-group>
    </div>
    <n-empty v-else description="No AI activity yet." class="assistant-empty">
      <template #extra>
        Provide your preferences and tap "Plan a trip with AI".
      </template>
    </n-empty>

    <div v-if="curation.visible" class="ai-curation-pane">
      <header class="curation-header">
        <div>
          <span class="curation-label">Select experiences & stays</span>
          <p>Tap to pick at least one choice per theme. Gemini will prioritise what you select.</p>
        </div>
        <n-button text size="small" class="planner-button planner-button--text" @click="$emit('auto-fill')">
          Auto pick top
        </n-button>
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
                  <span v-if="experiencePriceLabel(item)">
                    {{ experiencePriceLabel(item) }}
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
                  </div>
                  <div class="curation-stay-card__price" v-if="stayPriceDisplay(item)">
                    {{ stayPriceDisplay(item) }}
                  </div>
                </div>
                <div class="curation-stay-card__rating-row" v-if="stayProviderTag(item)">
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
        <n-button
          tertiary
          size="small"
          class="planner-button planner-button--ghost"
          @click="$emit('cancel-curation')"
        >
          Cancel
        </n-button>
        <n-button
          size="small"
          type="primary"
          class="planner-button planner-button--primary"
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
}

.ai-assistant-card :deep(.n-card__content) {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.assistant-hero {
  position: relative;
  overflow: hidden;
  isolation: isolate;
  background: linear-gradient(145deg, rgba(236, 253, 248, 0.95), rgba(219, 234, 254, 0.85));
  border-radius: 24px;
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  align-items: flex-start;
  border: 1px solid rgba(14, 116, 144, 0.1);
  box-shadow: 0 18px 40px rgba(15, 118, 110, 0.15);
}

.assistant-hero::before {
  content: '';
  position: absolute;
  inset: 10%;
  background: radial-gradient(circle at 30% 20%, rgba(59, 130, 246, 0.2), transparent 55%),
    radial-gradient(circle at 80% 20%, rgba(16, 185, 129, 0.2), transparent 45%);
  filter: blur(20px);
  z-index: 0;
  animation: aurora 12s ease-in-out infinite alternate;
}

.assistant-hero__orb {
  position: absolute;
  border-radius: 50%;
  filter: blur(0);
  opacity: 0.25;
  z-index: 0;
  animation: float 16s ease-in-out infinite;
  pointer-events: none;
}

.assistant-hero__orb--one {
  width: 120px;
  height: 120px;
  background: radial-gradient(circle, rgba(14, 116, 144, 0.6), transparent 60%);
  top: -20px;
  right: 40px;
}

.assistant-hero__orb--two {
  width: 160px;
  height: 160px;
  background: radial-gradient(circle, rgba(16, 185, 129, 0.55), transparent 65%);
  bottom: -40px;
  left: -20px;
  animation-duration: 22s;
}

.assistant-hero--idle {
  background: linear-gradient(145deg, rgba(248, 250, 255, 0.95), rgba(240, 249, 244, 0.9));
}

.assistant-hero__meta {
  display: flex;
  flex-direction: column;
  gap: 4px;
  position: relative;
  z-index: 1;
}

.assistant-hero__header {
  width: 100%;
  display: flex;
  justify-content: space-between;
  gap: 18px;
  flex-wrap: wrap;
  align-items: flex-start;
}

.assistant-hero__kicker {
  text-transform: uppercase;
  font-size: 0.7rem;
  letter-spacing: 0.1em;
  color: rgba(14, 116, 144, 0.75);
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 0;
}

.assistant-hero__meta h3 {
  margin: 0;
  font-size: 1.2rem;
}

.assistant-hero__summary {
  margin: 0;
  font-weight: 600;
  color: #0f172a;
}

.assistant-hero__mood {
  margin: 0;
  color: rgba(15, 23, 42, 0.65);
  font-size: 0.9rem;
}

.assistant-hero__header {
  width: 100%;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 18px;
  flex-wrap: wrap;
}

.assistant-progress-block {
  width: 100%;
  margin-top: 18px;
}

.assistant-progress {
  display: flex;
  flex-direction: column;
  gap: 6px;
  align-items: flex-start;
  width: 100%;
  z-index: 1;
}

.assistant-progress__label {
  width: 100%;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 600;
  color: rgba(15, 23, 42, 0.82);
}

.assistant-progress__track {
  position: relative;
  width: 100%;
  height: 14px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.45);
  overflow: visible;
  border: 1px solid rgba(14, 116, 144, 0.25);
}

.assistant-progress__track--idle {
  background: rgba(255, 255, 255, 0.6);
}

.assistant-progress__fill {
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  border-radius: inherit;
  background: linear-gradient(120deg, rgba(16, 185, 129, 0.95), rgba(56, 189, 248, 0.9));
  transition: width 0.6s cubic-bezier(0.65, 0, 0.35, 1);
  display: flex;
  justify-content: flex-end;
  align-items: center;
  padding-right: 4px;
}

.assistant-progress__runner {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: #fff;
  display: grid;
  place-items: center;
  color: #0f172a;
  font-size: 1rem;
  box-shadow: 0 6px 18px rgba(16, 185, 129, 0.45);
  transform: translateY(-4px);
  transition: transform 0.4s ease, box-shadow 0.4s ease;
}

.assistant-progress__runner--finish {
  transform: translateY(-6px) scale(1.05);
  box-shadow: 0 10px 22px rgba(16, 185, 129, 0.55);
}

.assistant-progress__flag {
  position: absolute;
  right: -6px;
  top: -10px;
  background: #fff;
  border-radius: 8px;
  padding: 4px;
  font-size: 0.8rem;
  color: rgba(15, 23, 42, 0.65);
  box-shadow: 0 8px 16px rgba(15, 23, 42, 0.15);
}

.assistant-progress__pulse {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: rgba(14, 116, 144, 0.25);
  animation: ripple 2.6s ease-in-out infinite;
  margin: -8px auto 0;
}

.assistant-progress__meta {
  font-size: 0.8rem;
  color: rgba(15, 23, 42, 0.65);
  width: 100%;
  text-align: left;
}

.assistant-progress--idle {
  align-items: flex-start;
}

.assistant-insight-card {
  position: relative;
  padding: 12px 16px;
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.75);
  border: 1px solid rgba(14, 116, 144, 0.12);
  box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.assistant-insight-card::after {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: inherit;
  background: radial-gradient(circle at top right, rgba(16, 185, 129, 0.15), transparent 50%);
  pointer-events: none;
}

.assistant-insight-card strong {
  font-size: 1.1rem;
  color: #0f172a;
}

.assistant-insight-card span {
  font-size: 0.82rem;
  color: rgba(15, 23, 42, 0.6);
}

.assistant-insight__label {
  margin: 0;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: rgba(14, 116, 144, 0.7);
}

.assistant-insight-card--inline {
  min-width: 220px;
  max-width: 320px;
  margin-left: auto;
}

.assistant-fireworks {
  position: absolute;
  inset: 0;
  pointer-events: none;
  overflow: hidden;
  z-index: 2;
}

.assistant-firework {
  position: absolute;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 2px solid rgba(255, 255, 255, 0.8);
  animation: firework-burst 1.4s ease-out infinite;
  opacity: 0;
  mix-blend-mode: screen;
}

.assistant-firework--0 {
  top: 25%;
  left: 18%;
  border-color: rgba(16, 185, 129, 0.9);
  animation-delay: 0s;
}

.assistant-firework--1 {
  top: 15%;
  right: 18%;
  border-color: rgba(59, 130, 246, 0.9);
  animation-delay: 0.2s;
}

.assistant-firework--2 {
  bottom: 24%;
  left: 34%;
  border-color: rgba(249, 115, 22, 0.9);
  animation-delay: 0.35s;
}

.assistant-firework--3 {
  bottom: 16%;
  right: 30%;
  border-color: rgba(236, 72, 153, 0.9);
  animation-delay: 0.55s;
}


.travel-pill__kicker {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
  color: rgba(14, 116, 144, 0.9);
}

.assistant-timeline-wrapper {
  border-radius: 22px;
  border: 1px solid rgba(15, 23, 42, 0.08);
  padding: 12px 14px;
  background: rgba(255, 255, 255, 0.9);
  box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
}

.assistant-timeline {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.timeline-step {
  display: flex;
  gap: 12px;
  padding: 10px 0;
}

.timeline-step__indicator {
  position: relative;
  width: 32px;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.timeline-step__bullet {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 2px solid rgba(14, 116, 144, 0.25);
  display: flex;
  align-items: center;
  justify-content: center;
  background: #fff;
  color: rgba(14, 116, 144, 0.9);
  box-shadow: 0 6px 12px rgba(14, 116, 144, 0.18);
  z-index: 1;
}

.timeline-step__rail {
  flex: 1;
  width: 2px;
  background: linear-gradient(180deg, rgba(14, 116, 144, 0.2), rgba(15, 23, 42, 0));
  margin-top: 6px;
}

.timeline-step__body {
  flex: 1;
  border-radius: 18px;
  padding: 10px 14px;
  background: rgba(241, 245, 249, 0.7);
  border: 1px solid rgba(15, 23, 42, 0.05);
  position: relative;
  overflow: hidden;
}

.timeline-step__body::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(120deg, rgba(255, 255, 255, 0.25), transparent);
  opacity: 0;
  pointer-events: none;
}

.timeline-step__body header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.timeline-step__title {
  margin: 0;
  font-weight: 600;
}

.timeline-step__message {
  margin: 2px 0 0;
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.65);
}

.timeline-step--done .timeline-step__body {
  background: rgba(236, 253, 245, 0.9);
  border-color: rgba(16, 185, 129, 0.25);
}

.timeline-step--running .timeline-step__body::after {
  opacity: 1;
  animation: shimmer 2.8s linear infinite;
}

.timeline-step--done .timeline-step__bullet {
  background: #10b981;
  color: #fff;
  border-color: #10b981;
}

.timeline-step--running .timeline-step__bullet {
  animation: pulse 1.8s infinite;
}

.timeline-step--error .timeline-step__body::after {
  background: linear-gradient(120deg, rgba(254, 226, 226, 0.7), transparent);
  opacity: 1;
}

.timeline-step--error .timeline-step__body {
  background: rgba(254, 242, 242, 0.9);
  border-color: rgba(220, 38, 38, 0.25);
}

.assistant-empty {
  border-radius: 20px;
  border: 1px dashed rgba(15, 23, 42, 0.1);
  padding: 20px;
  background: rgba(255, 255, 255, 0.7);
}

.ai-curation-pane {
  margin-top: 4px;
  background: #fff;
  border: 1px solid rgba(15, 118, 110, 0.15);
  border-radius: 20px;
  padding: 16px;
  box-shadow: 0 25px 45px rgba(15, 118, 110, 0.12);
  display: flex;
  flex-direction: column;
  gap: 14px;
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
  gap: 18px;
  overflow-x: auto;
  scroll-behavior: smooth;
  padding-bottom: 10px;
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
  min-width: 260px;
  max-width: 320px;
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

.fade-blur-enter-active,
.fade-blur-leave-active {
  transition: opacity 0.3s ease, transform 0.3s ease;
}

.fade-blur-enter-from,
.fade-blur-leave-to {
  opacity: 0;
  transform: translateY(10px);
  filter: blur(4px);
}

.timeline-fade-enter-active,
.timeline-fade-leave-active {
  transition: all 0.25s ease;
}

.timeline-fade-enter-from,
.timeline-fade-leave-to {
  opacity: 0;
  transform: translateY(8px);
}

.fireworks-enter-active,
.fireworks-leave-active {
  transition: opacity 0.3s ease;
}

.fireworks-enter-from,
.fireworks-leave-to {
  opacity: 0;
}

@keyframes aurora {
  0% {
    transform: translateY(0);
  }
  100% {
    transform: translateY(-12px);
  }
}

@keyframes float {
  0% {
    transform: translateY(0) scale(1);
  }
  50% {
    transform: translateY(-14px) scale(1.05);
  }
  100% {
    transform: translateY(6px) scale(0.98);
  }
}

@keyframes shimmer {
  0% {
    transform: translateX(-100%);
  }
  100% {
    transform: translateX(100%);
  }
}

@keyframes ripple {
  0% {
    transform: scale(0.8);
    opacity: 0.8;
  }
  70% {
    transform: scale(1.6);
    opacity: 0;
  }
  100% {
    opacity: 0;
  }
}

@keyframes pulse {
  0% {
    box-shadow: 0 0 0 0 rgba(14, 116, 144, 0.4);
  }
  70% {
    box-shadow: 0 0 0 8px rgba(14, 116, 144, 0);
  }
  100% {
    box-shadow: 0 0 0 0 rgba(14, 116, 144, 0);
  }
}

@keyframes firework-burst {
  0% {
    transform: scale(0.3);
    opacity: 0;
  }
  20% {
    opacity: 1;
  }
  70% {
    opacity: 0.7;
  }
  100% {
    transform: scale(3);
    opacity: 0;
  }
}

</style>
.travel-route__weather-stop--sunny {
  background: linear-gradient(145deg, rgba(254, 243, 199, 0.8), rgba(253, 224, 71, 0.4));
}

.travel-route__weather-stop--warm {
  background: linear-gradient(145deg, rgba(254, 235, 200, 0.8), rgba(248, 181, 129, 0.35));
}

.travel-route__weather-stop--storm {
  background: linear-gradient(145deg, rgba(191, 219, 254, 0.8), rgba(59, 130, 246, 0.35));
}

.travel-route__weather-stop--night {
  background: linear-gradient(145deg, rgba(30, 41, 59, 0.8), rgba(15, 23, 42, 0.65));
  color: rgba(248, 250, 255, 0.9);
}
