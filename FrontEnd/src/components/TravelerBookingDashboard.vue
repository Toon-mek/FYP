<script setup>
import { computed } from 'vue'

const props = defineProps({
  packageData: {
    type: Object,
    default: () => null,
  },
})

const emit = defineEmits(['back-to-saved', 'view-payment-dashboard'])
const hasPackage = computed(() => Boolean(props.packageData))
const summary = computed(() => props.packageData?.summary ?? {})
const selections = computed(() => props.packageData?.selections ?? { experiences: [], stays: [] })
const experienceSections = computed(() => selections.value?.experiences ?? [])
const stayEntries = computed(() => selections.value?.stays ?? [])
const travelDateBounds = computed(() => deriveTravelDateBounds(summary.value))
const totalTripNights = computed(() =>
  deriveTotalTripNights(travelDateBounds.value, summary.value, stayEntries.value.length),
)
const allocatedStayEntries = computed(() =>
  allocateStayEntries({
    stays: stayEntries.value,
    totalNights: totalTripNights.value,
    startDate: travelDateBounds.value.start,
    currency: baseCurrency.value,
  }),
)
const totalSelections = computed(() => {
  const experiencesCount = experienceSections.value.reduce(
    (sum, section) => sum + (section.picks?.length ?? 0),
    0,
  )
  return experiencesCount + stayEntries.value.length
})
const costSummary = computed(() => props.packageData?.costSummary ?? null)
const baseCurrency = computed(() => costSummary.value?.currency || props.packageData?.currency || 'MYR')

const derivedCostSummary = computed(() => {
  const categories = {}
  const picks = []
  let total = 0
  const currency = baseCurrency.value

  const track = (theme, entry) => {
    const info = deriveEntryPrice(entry)
    if (!info.amount) {
      return
    }
    const themeKey = theme || 'experience'
    const canonicalThemeKey = canonicalTheme(themeKey) || themeKey
    categories[canonicalThemeKey] = (categories[canonicalThemeKey] ?? 0) + info.amount
    total += info.amount
    picks.push({
      id: entry.id ?? entry.title,
      theme: canonicalThemeKey,
      title: entry.title ?? '',
      subtitle: entry.subtitle ?? '',
      price: info.amount,
      currency: info.currency || currency,
      display: info.label,
    })
  }

  experienceSections.value.forEach((section) => {
    section?.picks?.forEach((pick) => track(section.theme ?? section.label ?? 'experience', pick))
  })
  allocatedStayEntries.value.forEach((stay) => {
    if (stay.totalAmount && stay.totalAmount > 0) {
      const themeKey = canonicalTheme('stay') || 'stay'
      categories[themeKey] = (categories[themeKey] ?? 0) + stay.totalAmount
      total += stay.totalAmount
      picks.push({
        id: stay.id ?? stay.title,
        theme: themeKey,
        title: stay.title ?? '',
        subtitle: stay.dateLabel || stay.subtitle || '',
        price: stay.totalAmount,
        currency: stay.currency || currency,
        display: stay.priceLabel || deriveEntryPrice(stay).label,
      })
    } else {
      track('stay', stay)
    }
  })

  return {
    total,
    currency,
    categories,
    picks,
  }
})

const effectiveCostSummary = computed(() => {
  if (costSummary.value && Number(costSummary.value.total) > 0) {
    return costSummary.value
  }
  if (derivedCostSummary.value.total > 0) {
    return derivedCostSummary.value
  }
  return null
})

const payableAmount = computed(() => {
  const summaryValue = effectiveCostSummary.value
  if (!summaryValue || !Number.isFinite(Number(summaryValue.total))) {
    return 0
  }
  return Math.max(Number(summaryValue.total) || 0, 0)
})
const payableCurrency = computed(() => effectiveCostSummary.value?.currency || baseCurrency.value)
const canSimulatePayment = computed(() => hasPackage.value && payableAmount.value > 0)

const totalLabel = computed(() => {
  const summary = effectiveCostSummary.value
  if (!summary || !Number.isFinite(Number(summary.total)) || Number(summary.total) <= 0) {
    return 'Not available'
  }
  return formatCurrencyAmount(summary.total, summary.currency || baseCurrency.value)
})

const breakdown = computed(() => {
  const summaryValue = effectiveCostSummary.value
  if (!summaryValue?.categories) {
    return []
  }
  const currency = summaryValue.currency || baseCurrency.value
  const totals = {}
  Object.entries(summaryValue.categories).forEach(([key, amount]) => {
    const canonical = canonicalTheme(key)
    if (!canonical) {
      return
    }
    totals[canonical] = (totals[canonical] ?? 0) + (Number(amount) || 0)
  })
  const total = Object.values(totals).reduce((sum, value) => sum + value, 0)
  return PRIMARY_BREAKDOWN_THEMES.map((theme) => {
    const numericAmount = totals[theme] || 0
    if (!numericAmount) {
      return null
    }
    return {
      theme,
      label: COST_THEME_LABELS[theme],
      amount: numericAmount,
      share: total > 0 ? Math.round((numericAmount / total) * 100) : 0,
      display: formatCurrencyAmount(numericAmount, currency),
    }
  }).filter(Boolean)
})

const pickRows = computed(() => {
  const summary = effectiveCostSummary.value
  if (summary?.picks?.length) {
    const currency = summary.currency || baseCurrency.value
    return summary.picks.map((pick) => ({
      ...pick,
      display: formatCurrencyAmount(pick.price, pick.currency || currency),
    }))
  }
  const rows = []
  experienceSections.value.forEach((section) => {
    section?.picks?.forEach((pick) => {
      rows.push({
        id: pick.id ?? pick.title,
        theme: section.theme ?? section.label ?? 'experience',
        title: pick.title,
        subtitle: pick.subtitle,
        display: deriveEntryPrice(pick).label,
      })
    })
  })
  return rows
})

const heroTitle = computed(() => toTitleCase(props.packageData?.title || 'Select A Saved Package'))
const destinationLabel = computed(() =>
  toTitleCase(props.packageData?.destination || summary.value?.destination || 'Awaiting Destination'),
)
const heroTagline = computed(() =>
  toTitleCase(summary.value?.tagline || 'Signature Booking Dashboard'),
)
const heroDescription = computed(
  () =>
    summary.value?.description ||
    'Bespoke stays and sensory adventures harmonised for a serene premium journey.',
)
const heroTags = computed(() => {
  const tags = []
  if (props.packageData?.destination) {
    tags.push(destinationLabel.value)
  }
  if (summary.value?.tripType) {
    tags.push(toTitleCase(summary.value.tripType))
  }
  if (summary.value?.mood) {
    tags.push(toTitleCase(summary.value.mood))
  }
  if (totalSelections.value > 0) {
    tags.push(`${totalSelections.value} Selections`)
  }
  return [...new Set(tags.filter(Boolean))].slice(0, 3)
})
const journeyFacts = computed(() => {
  const facts = []
  if (summary.value?.dateRange) {
    facts.push({
      label: 'Travel Window',
      value: summary.value.dateRange,
    })
  }
  if (props.packageData?.destination) {
    facts.push({
      label: 'Destination',
      value: destinationLabel.value,
    })
  }
  if (summary.value?.climate) {
    facts.push({
      label: 'Climate Vibe',
      value: toTitleCase(summary.value.climate),
    })
  }
  if (summary.value?.curationNote) {
    facts.push({
      label: 'Curation Note',
      value: summary.value.curationNote,
    })
  } else {
    facts.push({
      label: 'Curation Note',
      value: 'Handpicked stays and sensory-rich activities for effortless exploring.',
    })
  }
  return facts.slice(0, 4)
})

function openPaymentDashboard() {
  if (!hasPackage.value) {
    return
  }
  emit('view-payment-dashboard', { package: props.packageData })
}

function formatCurrencyAmount(amount, currency = 'MYR') {
  if (!Number.isFinite(Number(amount)) || Number(amount) <= 0) {
    return ''
  }
  const safeAmount = Number(amount)
  try {
    return new Intl.NumberFormat('en-MY', {
      style: 'currency',
      currency,
      maximumFractionDigits: safeAmount % 1 === 0 ? 0 : 2,
    }).format(safeAmount)
  } catch {
    const rounded = safeAmount % 1 === 0 ? safeAmount.toFixed(0) : safeAmount.toFixed(2)
    return `${currency} ${rounded}`
  }
}

function normalisePriceValue(candidate) {
  if (candidate == null || candidate === '') {
    return null
  }
  if (typeof candidate === 'number') {
    return Number.isFinite(candidate) ? candidate : null
  }
  if (typeof candidate === 'string') {
    const numeric = Number(candidate.replace(/[^0-9.]/g, ''))
    return Number.isFinite(numeric) ? numeric : null
  }
  if (typeof candidate === 'object') {
    if (candidate.value != null) return normalisePriceValue(candidate.value)
    if (candidate.amount != null) return normalisePriceValue(candidate.amount)
    if (candidate.min != null || candidate.max != null) {
      const min = normalisePriceValue(candidate.min)
      const max = normalisePriceValue(candidate.max)
      if (min != null && max != null) return (min + max) / 2
      return min ?? max ?? null
    }
  }
  return null
}

function entryPriceLabel(entry, pkg = props.packageData) {
  return deriveEntryPrice(entry, pkg).label || ''
}

const COST_THEME_LABELS = {
  hotel: 'Hotel Escapes',
  adventure: 'Adventure Thrills',
  relax: 'Relax & Wellness',
  city: 'City Highlights',
}
const PRIMARY_BREAKDOWN_THEMES = Object.keys(COST_THEME_LABELS)
const COST_THEME_ALIASES = {
  stay: 'hotel',
  stays: 'hotel',
  accommodation: 'hotel',
  accommodations: 'hotel',
  lodging: 'hotel',
  resort: 'hotel',
  resorts: 'hotel',
  hotels: 'hotel',
  hotelescapes: 'hotel',
  adventurethrills: 'adventure',
  adventures: 'adventure',
  thrill: 'adventure',
  thrills: 'adventure',
  explore: 'adventure',
  relaxwellness: 'relax',
  relaxandwellness: 'relax',
  relaxation: 'relax',
  wellness: 'relax',
  spa: 'relax',
  chill: 'relax',
  cityhighlights: 'city',
  cityescape: 'city',
  urban: 'city',
  culture: 'city',
}

function goBack() {
  emit('back-to-saved')
}

function deriveEntryPrice(entry, pkg = props.packageData) {
  if (!entry) {
    return { amount: null, currency: baseCurrency.value, label: '' }
  }
  const textCandidate =
    entry.priceText || entry.priceLabel || entry.price_label || entry.price_text || ''
  const amountCandidates = [
    entry.price,
    entry.amount,
    entry.cost,
    entry.metadata?.price,
    entry.metadata?.amount,
    entry.metadata?.cost,
  ]
  let amount =
    amountCandidates
      .map((candidate) => normalisePriceValue(candidate))
      .find((value) => value != null && value > 0) ?? null

  if (amount == null) {
    const parsed = parsePriceFromText(textCandidate)
    if (parsed != null) {
      amount = parsed
    }
  }

  const currencyCandidates = [
    entry.currency,
    entry.metadata?.currency,
    detectCurrencyFromText(textCandidate),
    pkg?.costSummary?.currency,
    pkg?.currency,
    baseCurrency.value,
  ]
  const currency = currencyCandidates.find((value) => typeof value === 'string' && value.trim()) || 'MYR'

  return {
    amount,
    currency,
    label: amount != null && amount > 0 ? formatCurrencyAmount(amount, currency) : textCandidate.trim(),
  }
}

function parsePriceFromText(text) {
  if (!text || typeof text !== 'string') {
    return null
  }
  const matches = text.replace(/,/g, '').match(/(\d+(?:\.\d+)?)/g)
  if (!matches?.length) {
    return null
  }
  const numbers = matches.map((value) => Number(value)).filter((value) => Number.isFinite(value))
  if (!numbers.length) {
    return null
  }
  if (numbers.length === 1) {
    return numbers[0]
  }
  return Math.round((numbers.reduce((sum, value) => sum + value, 0) / numbers.length) * 100) / 100
}

function detectCurrencyFromText(text) {
  if (!text || typeof text !== 'string') {
    return null
  }
  if (/MYR|RM/i.test(text)) {
    return 'MYR'
  }
  if (/USD|\$/i.test(text)) {
    return 'USD'
  }
  if (/EUR|€/.test(text)) {
    return 'EUR'
  }
  if (/SGD/i.test(text)) {
    return 'SGD'
  }
  return null
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
}

function canonicalTheme(theme) {
  if (theme == null) {
    return null
  }
  const cleaned = theme
    .toString()
    .toLowerCase()
    .replace(/&/g, 'and')
    .replace(/[^a-z]/g, '')
  if (!cleaned) {
    return null
  }
  if (PRIMARY_BREAKDOWN_THEMES.includes(cleaned)) {
    return cleaned
  }
  return COST_THEME_ALIASES[cleaned] || null
}

const DAY_MS = 24 * 60 * 60 * 1000

function deriveTravelDateBounds(summary = {}) {
  if (!summary) return { start: null, end: null }
  const start = normaliseDateInput(summary.startDate)
  const end = normaliseDateInput(summary.endDate)
  if (start && end) {
    return { start, end }
  }
  return parseDateRangeLabel(summary.dateRange)
}

function deriveTotalTripNights(bounds, summary = {}, stayCount = 1) {
  if (bounds?.start && bounds?.end && bounds.end > bounds.start) {
    const diff = Math.round((bounds.end.getTime() - bounds.start.getTime()) / DAY_MS)
    if (diff > 0) {
      return diff
    }
  }
  const numericDuration = Number(summary.durationDays)
  if (Number.isFinite(numericDuration) && numericDuration > 0) {
    return Math.max(1, Math.round(numericDuration))
  }
  const extracted = extractDurationDaysFromLabel(summary.durationLabel)
  if (extracted) {
    return Math.max(1, extracted)
  }
  return Math.max(stayCount, 1)
}

function allocateStayEntries({ stays = [], totalNights = 1, startDate = null, currency = 'MYR' } = {}) {
  if (!Array.isArray(stays) || stays.length === 0) {
    return []
  }
  const normalized = stays.map((stay) => {
    const priceInfo = deriveEntryPrice(stay)
    const nightlyAmount = priceInfo.amount ?? null
    const stayCurrency = priceInfo.currency || currency
    const desired = Number(
      stay.metadata?.nights ??
        stay.metadata?.duration ??
        stay.nights ??
        stay.duration ??
        stay.metadata?.nightsBooked,
    )
    const desiredNights = Number.isFinite(desired) && desired > 0 ? Math.floor(desired) : 1
    return {
      raw: stay,
      nightlyAmount,
      fallbackLabel: priceInfo.label,
      currency: stayCurrency,
      desiredNights,
    }
  })
  const minimumTotal = Math.max(totalNights, normalized.length)
  const allocations = normalized.map((entry) => entry.desiredNights || 1)
  let currentTotal = allocations.reduce((sum, value) => sum + value, 0)
  if (currentTotal < minimumTotal) {
    allocations[allocations.length - 1] += minimumTotal - currentTotal
  } else if (currentTotal > minimumTotal) {
    let excess = currentTotal - minimumTotal
    for (let i = allocations.length - 1; i >= 0 && excess > 0; i -= 1) {
      const reducible = Math.min(Math.max(allocations[i] - 1, 0), excess)
      if (reducible > 0) {
        allocations[i] -= reducible
        excess -= reducible
      }
    }
  }
  let cursor = startDate ? new Date(startDate.getTime()) : null
  return normalized.map((entry, index) => {
    const nights = Math.max(1, allocations[index] || 1)
    let dateLabel = ''
    if (cursor) {
      const startLabel = formatShortDate(cursor)
      const lastNight = addDaysToDate(cursor, nights - 1)
      dateLabel = nights > 1 ? `${startLabel} → ${formatShortDate(lastNight)}` : startLabel
      cursor = addDaysToDate(cursor, nights)
    } else {
      dateLabel = nights > 1 ? `Night ${index + 1} - ${index + nights}` : `Night ${index + 1}`
    }
    const totalAmount =
      entry.nightlyAmount != null ? Math.round(entry.nightlyAmount * nights * 100) / 100 : null
    return {
      ...entry.raw,
      allocatedNights: nights,
      nightlyAmount: entry.nightlyAmount,
      totalAmount,
      currency: entry.currency,
      dateLabel,
      priceLabel:
        totalAmount != null
          ? formatCurrencyAmount(totalAmount, entry.currency)
          : entry.fallbackLabel || '',
    }
  })
}

function normaliseDateInput(value) {
  if (!value) {
    return null
  }
  if (value instanceof Date) {
    const copy = new Date(value.getTime())
    copy.setHours(0, 0, 0, 0)
    return copy
  }
  if (typeof value === 'number') {
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) {
      return null
    }
    date.setHours(0, 0, 0, 0)
    return date
  }
  if (typeof value === 'string') {
    let candidate = value.trim()
    if (!candidate) {
      return null
    }
    if (!/\d{4}/.test(candidate)) {
      const year = new Date().getFullYear()
      candidate = `${candidate} ${year}`
    }
    const parsed = new Date(candidate)
    if (Number.isNaN(parsed.getTime())) {
      return null
    }
    parsed.setHours(0, 0, 0, 0)
    return parsed
  }
  return null
}

function parseDateRangeLabel(label) {
  if (!label || typeof label !== 'string') {
    return { start: null, end: null }
  }
  const [startPart, endPart] = label.split('-').map((part) => part.trim())
  if (!startPart || !endPart) {
    return { start: null, end: null }
  }
  const start = normaliseDateInput(startPart)
  let end = normaliseDateInput(endPart)
  if (start && end && end < start) {
    end.setFullYear(end.getFullYear() + 1)
  }
  return { start, end }
}

function extractDurationDaysFromLabel(label) {
  if (!label || typeof label !== 'string') {
    return null
  }
  const match = label.match(/(\d+)\s+day/i)
  if (match) {
    const value = Number(match[1])
    return Number.isFinite(value) ? value : null
  }
  return null
}

function addDaysToDate(date, days) {
  const copy = new Date(date.getTime())
  copy.setDate(copy.getDate() + days)
  copy.setHours(0, 0, 0, 0)
  return copy
}

function formatShortDate(date) {
  return date.toLocaleDateString('en-MY', { month: 'short', day: 'numeric' })
}
</script>

<template>
  <div class="booking-dashboard">
    <div class="booking-dashboard__glow booking-dashboard__glow--emerald" />
    <div class="booking-dashboard__glow booking-dashboard__glow--azure" />

    <div v-if="hasPackage" class="booking-dashboard__shell">
      <section class="booking-dashboard__hero-card">
        <div class="booking-dashboard__hero-text">
          <p class="booking-dashboard__eyebrow">{{ heroTagline }}</p>
          <h1>{{ heroTitle }}</h1>
          <p class="booking-dashboard__date">
            {{ summary.dateRange || 'Awaiting Travel Dates' }}
          </p>
          <p class="booking-dashboard__description">
            {{ heroDescription }}
          </p>
          <div v-if="heroTags.length" class="booking-dashboard__hero-tags">
            <span v-for="tag in heroTags" :key="tag" class="booking-dashboard__tag">{{ tag }}</span>
          </div>
        </div>
        <div class="booking-dashboard__hero-stats">
          <div class="booking-dashboard__hero-stat">
            <p>Total Package</p>
            <strong>{{ totalLabel }}</strong>
            <small>All taxes and curated fees</small>
          </div>
          <div class="booking-dashboard__hero-stat">
            <p>Destination</p>
            <strong>{{ destinationLabel }}</strong>
            <small>Tailored for your pace</small>
          </div>
          <div class="booking-dashboard__hero-stat">
            <p>Selections</p>
            <strong>{{ totalSelections }}</strong>
            <small>Experiences & stays</small>
          </div>
        </div>
        <div class="booking-dashboard__hero-actions">
          <n-button
            size="large"
            round
            type="primary"
            class="booking-dashboard__hero-button booking-dashboard__hero-button--primary"
            :disabled="!canSimulatePayment"
            @click="openPaymentDashboard"
          >
            Go To Checkout
          </n-button>
          <n-button
            size="large"
            round
            quaternary
            class="booking-dashboard__hero-button booking-dashboard__hero-button--ghost"
            @click="goBack"
          >
            Back To Saved Places
          </n-button>
        </div>
      </section>

      <div class="booking-dashboard__content-grid">
        <div class="booking-dashboard__primary">
          <article class="booking-dashboard__panel booking-dashboard__panel--timeline">
            <div class="booking-dashboard__panel-header">
              <div>
                <h3>Experience Showcase</h3>
                <p>Immersive highlights crafted for this itinerary.</p>
              </div>
              <n-tag round type="success">{{ pickRows.length }} Curated Picks</n-tag>
            </div>

            <n-empty v-if="!pickRows.length" description="No priced experiences recorded." />
            <div v-else class="booking-dashboard__experience-list">
              <article
                v-for="row in pickRows"
                :key="row.id"
                class="booking-dashboard__experience-card"
              >
                <div class="booking-dashboard__experience-marker">
                  <span>{{ COST_THEME_LABELS[row.theme] || toTitleCase(row.theme) }}</span>
                </div>
                <div>
                  <h4>{{ row.title }}</h4>
                  <p v-if="row.subtitle">{{ row.subtitle }}</p>
                </div>
                <div class="booking-dashboard__price-chip">
                  {{ row.display || 'Included' }}
                </div>
              </article>
            </div>
          </article>

          <article class="booking-dashboard__panel booking-dashboard__panel--stays">
            <div class="booking-dashboard__panel-header">
              <div>
                <h3>Sanctuary Stays</h3>
                <p>Handpicked retreats with restful energy.</p>
              </div>
              <n-tag round>{{ allocatedStayEntries.length }} Stays</n-tag>
            </div>

            <n-empty v-if="!allocatedStayEntries.length" description="No stays saved in this package." />
            <div v-else class="booking-dashboard__stay-grid">
              <article v-for="stay in allocatedStayEntries" :key="stay.id" class="booking-dashboard__stay-card">
                <div
                  class="booking-dashboard__stay-photo"
                  :class="{ 'booking-dashboard__stay-photo--empty': !stay.photoUrl }"
                  :style="stay.photoUrl ? { backgroundImage: `url(${stay.photoUrl})` } : undefined"
                />
                <div class="booking-dashboard__stay-body">
                  <div>
                    <h4>{{ stay.title }}</h4>
                    <p v-if="stay.subtitle">{{ stay.subtitle }}</p>
                  </div>
                  <div class="booking-dashboard__stay-footer">
                    <span v-if="stay.dateLabel" class="booking-dashboard__stay-date">{{ stay.dateLabel }}</span>
                    <span class="booking-dashboard__price-chip">{{ stay.priceLabel || entryPriceLabel(stay) || 'Rate On Request' }}</span>
                  </div>
                </div>
              </article>
            </div>
          </article>
        </div>

        <aside class="booking-dashboard__secondary">
          <article class="booking-dashboard__panel booking-dashboard__panel--cost">
            <div class="booking-dashboard__panel-header">
              <div>
                <h3>Investment Breakdown</h3>
                <p>Track where your travel energy flows.</p>
              </div>
            </div>
            <div v-if="breakdown.length" class="booking-dashboard__breakdown-grid">
              <div v-for="entry in breakdown" :key="entry.theme" class="booking-dashboard__breakdown-card">
                <div class="booking-dashboard__breakdown-row">
                  <span>{{ entry.label }}</span>
                  <strong>{{ entry.display }}</strong>
                </div>
                <div class="booking-dashboard__progress">
                  <div
                    class="booking-dashboard__progress-value"
                    :style="{ width: `${Math.min(entry.share, 100)}%` }"
                  />
                </div>
                <small>{{ entry.share }}% of trip budget</small>
              </div>
            </div>
            <n-empty v-else description="No price data for this package." />
          </article>

          <article class="booking-dashboard__panel booking-dashboard__panel--facts">
            <div class="booking-dashboard__panel-header">
              <div>
                <h3>Itinerary Snapshot</h3>
                <p>Luxe cues to set the mood.</p>
              </div>
            </div>
            <ul class="booking-dashboard__facts">
              <li v-for="fact in journeyFacts" :key="fact.label">
                <span>{{ fact.label }}</span>
                <strong>{{ fact.value }}</strong>
              </li>
            </ul>
          </article>
        </aside>
      </div>
    </div>

    <div v-else class="booking-dashboard__empty-state">
      <n-empty description="Choose A Signature Package">
        <template #default>
          <p>Select "View booking price" from any saved escape to unveil its premium breakdown.</p>
        </template>
        <template #extra>
          <n-button type="primary" size="large" round @click="goBack">Go To Saved Places</n-button>
        </template>
      </n-empty>
    </div>
  </div>
</template>

<style scoped>
.booking-dashboard {
  position: relative;
  width: 100%;
  min-height: 100%;
  padding: 32px;
  background: radial-gradient(circle at top, rgba(34, 197, 94, 0.08), rgba(59, 130, 246, 0.06)) #f8fafc;
  color: #0f172a;
  overflow: hidden;
}

.booking-dashboard__glow {
  position: absolute;
  width: 420px;
  height: 420px;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.55;
  z-index: 0;
}

.booking-dashboard__glow--emerald {
  top: -120px;
  left: -80px;
  background: rgba(52, 211, 153, 0.7);
}

.booking-dashboard__glow--azure {
  bottom: -140px;
  right: -60px;
  background: rgba(96, 165, 250, 0.65);
}

.booking-dashboard__shell {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 32px;
  z-index: 1;
}

.booking-dashboard__hero-card {
  display: flex;
  flex-wrap: wrap;
  gap: 32px;
  padding: 36px;
  border-radius: 36px;
  background: linear-gradient(125deg, rgba(255, 255, 255, 0.9), rgba(248, 250, 252, 0.82));
  border: 1px solid rgba(255, 255, 255, 0.5);
  box-shadow: 0 30px 80px rgba(15, 23, 42, 0.12);
  backdrop-filter: blur(18px);
}

.booking-dashboard__hero-text {
  flex: 1 1 320px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.booking-dashboard__hero-text p {
  margin: 0;
}

.booking-dashboard__eyebrow {
  letter-spacing: 0.2em;
  text-transform: uppercase;
  font-size: 0.8rem;
  color: rgba(15, 23, 42, 0.6);
}

.booking-dashboard__hero-text h1 {
  margin: 0;
  font-size: 2.4rem;
}

.booking-dashboard__date {
  font-weight: 600;
  color: rgba(15, 23, 42, 0.8);
}

.booking-dashboard__description {
  color: rgba(15, 23, 42, 0.7);
  max-width: 640px;
}

.booking-dashboard__hero-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.booking-dashboard__tag {
  padding: 6px 14px;
  border-radius: 999px;
  background: rgba(59, 130, 246, 0.12);
  border: 1px solid rgba(59, 130, 246, 0.3);
  font-size: 0.85rem;
  font-weight: 600;
}

.booking-dashboard__hero-stats {
  flex: 1 1 280px;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
  align-items: stretch;
}

.booking-dashboard__hero-stat {
  padding: 18px;
  border-radius: 24px;
  background: rgba(255, 255, 255, 0.85);
  border: 1px solid rgba(15, 23, 42, 0.06);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4);
}

.booking-dashboard__hero-stat p {
  margin: 0;
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.6);
  text-transform: capitalize;
}

.booking-dashboard__hero-stat strong {
  display: block;
  margin-top: 6px;
  font-size: 1.6rem;
}

.booking-dashboard__hero-stat small {
  display: block;
  margin-top: 6px;
  color: rgba(15, 23, 42, 0.55);
}

.booking-dashboard__hero-actions {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
  margin-top: 18px;
}

.booking-dashboard__hero-button {
  width: 100%;
}

.booking-dashboard__hero-button--primary {
  box-shadow: 0 18px 30px rgba(16, 185, 129, 0.25);
}


.booking-dashboard__content-grid {
  display: grid;
  grid-template-columns: minmax(0, 2.2fr) minmax(280px, 1fr);
  gap: 32px;
}

.booking-dashboard__primary {
  display: flex;
  flex-direction: column;
  gap: 28px;
}

.booking-dashboard__secondary {
  display: flex;
  flex-direction: column;
  gap: 28px;
}

.booking-dashboard__panel {
  position: relative;
  padding: 28px;
  border-radius: 28px;
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid rgba(15, 23, 42, 0.08);
  box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
  backdrop-filter: blur(16px);
}

.booking-dashboard__panel-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 18px;
}

.booking-dashboard__panel-header h3 {
  margin: 0;
  font-size: 1.2rem;
  text-transform: capitalize;
}

.booking-dashboard__panel-header p {
  margin: 4px 0 0;
  color: rgba(15, 23, 42, 0.65);
}

.booking-dashboard__experience-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.booking-dashboard__experience-card {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 18px;
  align-items: center;
  padding: 16px 20px;
  border-radius: 22px;
  border: 1px solid rgba(15, 23, 42, 0.06);
  background: rgba(248, 250, 252, 0.9);
}

.booking-dashboard__experience-card h4 {
  margin: 0;
  font-size: 1.05rem;
}

.booking-dashboard__experience-card p {
  margin: 4px 0 0;
  color: rgba(15, 23, 42, 0.6);
}

.booking-dashboard__experience-marker {
  padding: 8px 14px;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(59, 130, 246, 0.2));
  font-size: 0.85rem;
  font-weight: 600;
  text-transform: capitalize;
}

.booking-dashboard__price-chip {
  padding: 6px 14px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.05);
  font-weight: 600;
}

.booking-dashboard__stay-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 18px;
}

.booking-dashboard__stay-card {
  display: grid;
  grid-template-columns: minmax(180px, 220px) 1fr;
  border-radius: 24px;
  overflow: hidden;
  border: 1px solid rgba(15, 23, 42, 0.08);
  background: rgba(248, 250, 252, 0.9);
}

.booking-dashboard__stay-photo {
  width: 100%;
  height: 100%;
  min-height: 200px;
  background-size: cover;
  background-position: center;
}

.booking-dashboard__stay-photo--empty {
  background: linear-gradient(135deg, rgba(15, 23, 42, 0.08), rgba(148, 163, 184, 0.2));
}

.booking-dashboard__stay-body {
  padding: 18px 20px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 12px;
}

.booking-dashboard__stay-body h4 {
  margin: 0;
}

.booking-dashboard__stay-body p {
  margin: 4px 0 0;
  color: rgba(15, 23, 42, 0.6);
}

.booking-dashboard__stay-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.booking-dashboard__stay-date {
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.55);
}

.booking-dashboard__breakdown-grid {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.booking-dashboard__breakdown-card {
  padding: 16px;
  border-radius: 20px;
  border: 1px solid rgba(15, 23, 42, 0.06);
  background: rgba(248, 250, 252, 0.95);
}

.booking-dashboard__breakdown-row {
  display: flex;
  justify-content: space-between;
  font-weight: 600;
  margin-bottom: 8px;
}

.booking-dashboard__progress {
  width: 100%;
  height: 6px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.08);
  overflow: hidden;
}

.booking-dashboard__progress-value {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(135deg, #10b981, #3b82f6);
}

.booking-dashboard__breakdown-card small {
  display: block;
  margin-top: 8px;
  color: rgba(15, 23, 42, 0.55);
}

.booking-dashboard__facts {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.booking-dashboard__facts li {
  display: flex;
  flex-direction: column;
  padding-bottom: 10px;
  border-bottom: 1px solid rgba(15, 23, 42, 0.08);
}

.booking-dashboard__facts li:last-child {
  border-bottom: none;
}

.booking-dashboard__facts span {
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.15em;
  color: rgba(15, 23, 42, 0.6);
}

.booking-dashboard__facts strong {
  margin-top: 4px;
  font-size: 1.05rem;
}

.booking-dashboard__empty-state {
  position: relative;
  z-index: 1;
  padding: 64px 32px;
}

@media (max-width: 1024px) {
  .booking-dashboard {
    padding: 24px;
  }
  .booking-dashboard__content-grid {
    grid-template-columns: 1fr;
  }
  .booking-dashboard__hero-card {
    padding: 28px;
  }
  .booking-dashboard__hero-actions {
    grid-template-columns: 1fr;
  }
  .booking-dashboard__stay-card {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 640px) {
  .booking-dashboard {
    padding: 20px 16px;
  }
  .booking-dashboard__hero-text h1 {
    font-size: 2rem;
  }
  .booking-dashboard__panel {
    padding: 22px;
  }
  .booking-dashboard__experience-card {
    grid-template-columns: 1fr;
    text-align: left;
  }
  .booking-dashboard__experience-marker {
    width: fit-content;
  }
  .booking-dashboard__price-chip {
    justify-self: flex-start;
  }
}
</style>
