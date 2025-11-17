<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { NTag, NButton } from 'naive-ui'
import { useMessage } from 'naive-ui'
import { fetchConfirmedBookings, fetchPaymentHistory } from '../services/paymentSimulationService.js'
import SimplePagination from './shared/SimplePagination.vue'

const props = defineProps({
  travelerId: {
    type: [Number, String],
    default: null,
  },
})

const message = useMessage()
const bookings = ref([])
const loading = ref(false)
const expandedCards = reactive(new Set())
const currentPage = ref(1)
const pageSize = 3
const HISTORY_EVENT = 'traveler-booking-history-refresh'

const normalizedTravelerId = computed(() => {
  const id = props.travelerId
  if (id == null) return null
  const numeric = Number(id)
  return Number.isFinite(numeric) ? numeric : null
})

const bookingCount = computed(() => bookings.value.length)
const totalAmount = computed(() =>
  bookings.value.reduce((sum, entry) => sum + Number(entry.amount || 0), 0),
)
const lastPaidDate = computed(() => {
  if (!bookings.value.length) return ''
  const sorted = [...bookings.value].sort(
    (a, b) => new Date(b.paidAt || b.updatedAt) - new Date(a.paidAt || a.updatedAt),
  )
  return formatHistoryDate(sorted[0].paidAt || sorted[0].updatedAt)
})

// Pagination
const pageCount = computed(() => Math.ceil(bookings.value.length / pageSize))
const paginatedBookings = computed(() => {
  const start = (currentPage.value - 1) * pageSize
  const end = start + pageSize
  return bookings.value.slice(start, end)
})

watch(
  normalizedTravelerId,
  (travelerId) => {
    if (travelerId) {
      loadBookings()
    } else {
      bookings.value = []
    }
  },
  { immediate: true },
)

onMounted(() => {
  window.addEventListener(HISTORY_EVENT, handleHistoryRefresh)
})

onBeforeUnmount(() => {
  window.removeEventListener(HISTORY_EVENT, handleHistoryRefresh)
})

async function loadBookings() {
  const travelerId = normalizedTravelerId.value
  if (!travelerId) {
    bookings.value = []
    return
  }
  loading.value = true
  try {
    const [bookingsResponse, historyResponse] = await Promise.all([
      fetchConfirmedBookings(travelerId),
      fetchPaymentHistory(travelerId, 25),
    ])
    const archivedBookings = Array.isArray(bookingsResponse?.bookings) ? bookingsResponse.bookings : []
    const sessionFallback =
      Array.isArray(historyResponse?.sessions) && historyResponse.sessions.length
        ? historyResponse.sessions.map(convertSessionToBooking)
        : []
    bookings.value = mergeBookingEntries([...archivedBookings, ...sessionFallback])
    expandedCards.clear()
    currentPage.value = 1 // Reset to first page when reloading
  } catch (error) {
    message.warning(error?.message || 'Unable to load booking history.')
  } finally {
    loading.value = false
  }
}

function handleHistoryRefresh(event) {
  const target = Number(event?.detail?.travelerId ?? 0)
  if (!target || target !== normalizedTravelerId.value) {
    return
  }
  loadBookings()
}

function toggleDetails(key) {
  if (expandedCards.has(key)) {
    expandedCards.delete(key)
  } else {
    expandedCards.add(key)
  }
}

function isExpanded(key) {
  return expandedCards.has(key)
}

function formatHistoryDate(value) {
  if (!value) {
    return ''
  }
  try {
    return new Intl.DateTimeFormat(undefined, {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(new Date(value))
  } catch (error) {
    return value
  }
}

function bookingDateLabel(booking) {
  const summary = booking?.packageSummary ?? {}
  return summary.dateRange || summary.durationLabel || 'Flexible dates'
}

function bookingSummaryNote(booking) {
  const summary = booking?.packageSummary ?? {}
  return summary.curationNote || summary.description || 'Payment recorded. Your host will be in touch.'
}

function formatCurrencyAmount(amount, currency = 'MYR') {
  if (!Number.isFinite(Number(amount)) || Number(amount) <= 0) {
    return `${currency} 0`
  }
  const safeAmount = Number(amount)
  try {
    return new Intl.NumberFormat('en-MY', {
      style: 'currency',
      currency,
      maximumFractionDigits: safeAmount % 1 === 0 ? 0 : 2,
    }).format(safeAmount)
  } catch (error) {
    const rounded = safeAmount % 1 === 0 ? safeAmount.toFixed(0) : safeAmount.toFixed(2)
    return `${currency} ${rounded}`
  }
}

function summaryTags(booking) {
  const summary = booking?.packageSummary ?? {}
  const tags = []
  if (summary.tripType) tags.push(summary.tripType)
  if (summary.mood) tags.push(summary.mood)
  if (summary.travelStyles) {
    tags.push(...summary.travelStyles.slice(0, 2))
  }
  if (summary.destination) tags.push(summary.destination)
  return [...new Set(tags)].slice(0, 4)
}

function selectionCount(booking) {
  const selections =
    booking?.packageSelections ??
    booking?.packageSummary?.selections ??
    booking?.selections ??
    {}
  const experiences = Array.isArray(selections.experiences) ? selections.experiences.length : 0
  const stays = Array.isArray(selections.stays) ? selections.stays.length : 0
  return experiences + stays
}

function primarySelectionList(booking) {
  const selections =
    booking?.packageSelections ??
    booking?.packageSummary?.selections ??
    booking?.selections ??
    {}
  const list = []
  if (Array.isArray(selections.experiences)) {
    selections.experiences.forEach((section) => {
      section?.picks?.forEach((pick) => {
        list.push({
          title: pick.title,
          subtitle: pick.subtitle,
          theme: section?.label || section?.theme,
        })
      })
    })
  }
  if (Array.isArray(selections.stays)) {
    selections.stays.forEach((stay) => {
      list.push({
        title: stay.title,
        subtitle: stay.subtitle,
        theme: 'Stay',
      })
    })
  }
  return list.slice(0, 4)
}

function convertSessionToBooking(session) {
  if (!session || typeof session !== 'object') {
    return null
  }
  if (session.status && session.status !== 'authorized') {
    return null
  }
  const summaryCandidate =
    session.packageSummary ||
    session.summary ||
    session.clientContext?.packageSnapshot?.summary ||
    session.clientContext?.packageSnapshot ||
    {}
  const summarySource = ensurePlainObject(summaryCandidate)
  const selectionsCandidate =
    session.packageSelections ||
    session.clientContext?.packageSnapshot?.selections ||
    summarySource?.selections ||
    null
  if (!summarySource.curationNote && session.method?.displayName) {
    summarySource.curationNote = `Processed via ${session.method.displayName}`
  }
  return normalizeBookingEntry({
    historyId: null,
    sessionId: session.sessionId ?? null,
    travelerId: session.travelerId ?? null,
    packageId: session.packageId ?? null,
    bookingRef: session.bookingRef || session.sessionId || null,
    packageTitle: session.packageTitle || summaryCandidate?.title || 'Confirmed journey',
    packageDestination:
      session.packageDestination ||
      summarySource.destination ||
      summarySource.location ||
      session.destination ||
      '',
    packageSummary: summarySource,
    packageSelections: selectionsCandidate,
    amount: session.amount,
    currency: session.currency || 'MYR',
    status: 'confirmed',
    receiptNo: session.receiptNo || null,
    paidAt: session.paidAt || session.updatedAt || session.createdAt || null,
    createdAt: session.createdAt || null,
  })
}

function mergeBookingEntries(entries = []) {
  let fallbackKey = 0
  const merged = new Map()
  entries
    .filter((entry) => entry && typeof entry === 'object')
    .forEach((entry) => {
      const normalized = normalizeBookingEntry(entry)
      if (!normalized) {
        return
      }
      const key =
        normalized.bookingRef ||
        normalized.receiptNo ||
        String(normalized.sessionId || normalized.historyId || `temp-${fallbackKey++}`)
      const existing = merged.get(key)
      if (!existing) {
        merged.set(key, normalized)
        return
      }
      merged.set(key, mergeBookingPair(existing, normalized))
    })
  return Array.from(merged.values()).sort(
    (a, b) => bookingSortTimestamp(b) - bookingSortTimestamp(a),
  )
}

function normalizeBookingEntry(entry) {
  if (!entry || typeof entry !== 'object') {
    return null
  }
  const summary = ensurePlainObject(entry.packageSummary)
  const selections = ensurePlainObject(
    entry.packageSelections ||
      entry.selections ||
      summary.selections ||
      null,
  )
  const bookingRef = deriveBookingRef(entry)
  const amount = Number(entry.amount)
  return {
    historyId: entry.historyId ?? entry.historyID ?? null,
    sessionId: entry.sessionId ?? entry.sessionID ?? null,
    travelerId: entry.travelerId ?? entry.travelerID ?? null,
    packageId: entry.packageId ?? entry.packageID ?? null,
    bookingRef,
    packageTitle:
      entry.packageTitle ||
      entry.title ||
      summary.title ||
      'Confirmed journey',
    packageDestination:
      entry.packageDestination ||
      entry.destination ||
      summary.destination ||
      '',
    packageSummary: summary,
    packageSelections: isNonEmptyObject(selections) ? selections : null,
    amount: Number.isFinite(amount) ? amount : 0,
    currency: entry.currency || 'MYR',
    status: entry.status || 'confirmed',
    receiptNo: entry.receiptNo || entry.bookingRefReceipt || null,
    paidAt: entry.paidAt ?? entry.updatedAt ?? entry.createdAt ?? null,
    createdAt: entry.createdAt ?? null,
  }
}

function mergeBookingPair(primary, secondary) {
  return {
    historyId: preferValue(primary.historyId, secondary.historyId, null),
    sessionId: preferValue(primary.sessionId, secondary.sessionId, null),
    travelerId: preferValue(primary.travelerId, secondary.travelerId, null),
    packageId: preferValue(primary.packageId, secondary.packageId, null),
    bookingRef: preferValue(primary.bookingRef, secondary.bookingRef, ''),
    packageTitle: preferValue(primary.packageTitle, secondary.packageTitle, 'Confirmed journey'),
    packageDestination: preferValue(primary.packageDestination, secondary.packageDestination, ''),
    packageSummary: preferObject(primary.packageSummary, secondary.packageSummary),
    packageSelections: preferObject(primary.packageSelections, secondary.packageSelections, null),
    amount: Number.isFinite(primary.amount) ? primary.amount : secondary.amount,
    currency: preferValue(primary.currency, secondary.currency, 'MYR'),
    status: preferValue(primary.status, secondary.status, 'confirmed'),
    receiptNo: preferValue(primary.receiptNo, secondary.receiptNo, null),
    paidAt: preferValue(primary.paidAt, secondary.paidAt, primary.createdAt, secondary.createdAt, null),
    createdAt: preferValue(primary.createdAt, secondary.createdAt, null),
  }
}

function deriveBookingRef(entry) {
  const candidates = [
    entry.bookingRef,
    entry.receiptNo,
    entry.sessionId ? `SESSION-${entry.sessionId}` : '',
    entry.historyId ? `HISTORY-${entry.historyId}` : '',
  ]
  for (const candidate of candidates) {
    if (candidate && String(candidate).trim()) {
      return String(candidate).trim()
    }
  }
  return ''
}

function bookingSortTimestamp(booking) {
  const dateValue = booking?.paidAt || booking?.createdAt
  if (!dateValue) {
    return 0
  }
  const timestamp = new Date(dateValue).getTime()
  return Number.isFinite(timestamp) ? timestamp : 0
}

function ensurePlainObject(value) {
  if (!isPlainObject(value)) {
    return {}
  }
  return { ...value }
}

function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function isNonEmptyObject(value) {
  return isPlainObject(value) && Object.keys(value).length > 0
}

function preferValue(...values) {
  for (const value of values) {
    if (value === undefined || value === null) {
      continue
    }
    if (typeof value === 'string') {
      const trimmed = value.trim()
      if (!trimmed) {
        continue
      }
      return trimmed
    }
    return value
  }
  return null
}

function preferObject(primary, secondary, fallback = {}) {
  if (isNonEmptyObject(primary)) {
    return primary
  }
  if (isNonEmptyObject(secondary)) {
    return secondary
  }
  return fallback
}
</script>

<template>
  <section class="payment-history">
    <header class="history-hero">
      <div>
        <p class="history-hero__eyebrow">Journey Ledger</p>
        <h1>Booking History</h1>
        <p>Every confirmed itinerary, neatly archived with its receipt.</p>
      </div>
      <div class="history-metrics">
        <div class="history-metric">
          <span>Total Trips</span>
          <strong>{{ bookingCount }}</strong>
        </div>
        <div class="history-metric">
          <span>Total Spend</span>
          <strong>{{ formatCurrencyAmount(totalAmount) }}</strong>
        </div>
        <div class="history-metric">
          <span>Last Paid</span>
          <strong>{{ lastPaidDate || 'Awaiting first booking' }}</strong>
        </div>
      </div>
    </header>

    <div v-if="loading" class="booking-history__empty">Loading booking history...</div>
    <div v-else-if="!bookings.length" class="booking-history__empty">
      No confirmed journeys recorded yet.
    </div>
    <div v-else class="booking-history__grid">
      <article
        v-for="booking in paginatedBookings"
        :key="booking.receiptNo || booking.bookingRef || booking.sessionId"
        class="booking-card"
      >
        <div class="booking-card__timeline" />
        <div class="booking-card__body">
          <div class="booking-card__header">
            <div>
              <p class="booking-card__date">{{ bookingDateLabel(booking) }}</p>
              <h2>{{ booking.packageTitle || 'Confirmed journey' }}</h2>
              <div class="booking-card__tags">
                <span
                  v-for="tag in summaryTags(booking)"
                  :key="tag"
                  class="booking-card__tag"
                >{{ tag }}</span>
              </div>
            </div>
            <n-tag size="small" type="success">Confirmed</n-tag>
          </div>

            <div class="booking-card__facts">
              <div>
                <span>Destination</span>
                <strong>{{ booking.packageDestination || booking.destination || booking.packageSummary?.destination || 'To be announced' }}</strong>
              </div>
            <div>
              <span>Amount</span>
              <strong>{{ formatCurrencyAmount(booking.amount, booking.currency) }}</strong>
            </div>
            <div>
              <span>Receipt</span>
              <strong>{{ booking.receiptNo || booking.bookingRef }}</strong>
            </div>
            <div>
              <span>Paid</span>
              <strong>{{ formatHistoryDate(booking.paidAt) }}</strong>
            </div>
            <div>
              <span>Selections</span>
              <strong>{{ selectionCount(booking) }} curated picks</strong>
            </div>
          </div>

          <p class="booking-card__note">{{ bookingSummaryNote(booking) }}</p>

          <div v-if="primarySelectionList(booking).length" class="booking-card__selections">
            <h3>Highlights</h3>
            <div class="booking-card__selection-list">
              <div
                v-for="(item, index) in primarySelectionList(booking)"
                :key="index"
                class="booking-card__selection"
              >
                <span>{{ item.theme || 'Experience' }}</span>
                <strong>{{ item.title }}</strong>
                <small>{{ item.subtitle }}</small>
              </div>
            </div>
          </div>

          <n-button text type="primary" class="booking-card__toggle" @click="toggleDetails(booking.bookingRef)">
            {{ isExpanded(booking.bookingRef) ? 'Hide details' : 'View payment details' }}
          </n-button>

          <div v-if="isExpanded(booking.bookingRef)" class="booking-card__details">
            <div>
              <span>Status</span>
              <strong>{{ booking.status || 'Confirmed' }}</strong>
            </div>
            <div>
              <span>Booking reference</span>
              <strong>{{ booking.bookingRef }}</strong>
            </div>
            <div v-if="booking.packageSummary?.curationNote">
              <span>Curation note</span>
              <strong>{{ booking.packageSummary.curationNote }}</strong>
            </div>
            <div>
              <span>Created</span>
              <strong>{{ formatHistoryDate(booking.createdAt || booking.paidAt) }}</strong>
            </div>
          </div>
        </div>
      </article>
    </div>

    <!-- Pagination -->
    <div v-if="!loading && bookings.length > pageSize" style="margin-top: 24px; display: flex; justify-content: center;">
      <SimplePagination 
        v-model:page="currentPage" 
        :page-count="pageCount"
        :page-size="pageSize"
      />
    </div>
  </section>
</template>

<style scoped>
.payment-history {
  padding: 32px;
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.history-hero {
  border-radius: 32px;
  padding: 32px;
  background: linear-gradient(135deg, rgba(34, 197, 94, 0.2), rgba(59, 130, 246, 0.18));
  color: #0f172a;
  box-shadow: 0 25px 60px rgba(15, 23, 42, 0.15);
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 24px;
}

.history-hero__eyebrow {
  text-transform: uppercase;
  letter-spacing: 0.3em;
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.7);
}

.history-metrics {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 18px;
}

.history-metric {
  border-radius: 20px;
  background: rgba(255, 255, 255, 0.9);
  border: 1px solid rgba(15, 23, 42, 0.08);
  padding: 16px;
  text-align: center;
}

.history-metric span {
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.55);
  text-transform: uppercase;
  letter-spacing: 0.15em;
}

.history-metric strong {
  display: block;
  font-size: 1.6rem;
  margin-top: 8px;
}

.booking-history__grid {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.booking-card {
  position: relative;
  border-radius: 28px;
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid rgba(15, 23, 42, 0.08);
  box-shadow: 0 18px 60px rgba(15, 23, 42, 0.08);
  overflow: hidden;
}

.booking-card__timeline {
  position: absolute;
  inset: 0;
  background: radial-gradient(circle at top left, rgba(59, 130, 246, 0.24), transparent 60%);
  pointer-events: none;
}

.booking-card__body {
  position: relative;
  padding: 28px;
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.booking-card__header {
  display: flex;
  justify-content: space-between;
  gap: 18px;
  flex-wrap: wrap;
}

.booking-card__date {
  margin: 0;
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.2em;
  color: rgba(15, 23, 42, 0.45);
}

.booking-card__tags {
  margin-top: 10px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.booking-card__tag {
  padding: 6px 12px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.08);
  font-size: 0.75rem;
  text-transform: capitalize;
}

.booking-card__facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 16px;
}

.booking-card__facts span {
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.5);
  text-transform: uppercase;
  letter-spacing: 0.18em;
}

.booking-card__facts strong {
  display: block;
  margin-top: 4px;
}

.booking-card__note {
  margin-top: 8px;
  color: rgba(15, 23, 42, 0.65);
}

.booking-card__selections h3 {
  margin: 0 0 8px;
}

.booking-card__selection-list {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
}

.booking-card__selection {
  border-radius: 16px;
  border: 1px solid rgba(15, 23, 42, 0.08);
  padding: 12px;
  background: rgba(248, 250, 252, 0.9);
}

.booking-card__selection span {
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.6);
  text-transform: uppercase;
  letter-spacing: 0.16em;
}

.booking-card__selection strong {
  display: block;
  margin-top: 4px;
}

.booking-card__selection small {
  color: rgba(15, 23, 42, 0.55);
}

.booking-card__toggle {
  align-self: flex-start;
  margin-top: 8px;
}

.booking-card__details {
  border-top: 1px solid rgba(15, 23, 42, 0.08);
  margin-top: 10px;
  padding-top: 12px;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
}

.booking-card__details span {
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.5);
  text-transform: uppercase;
  letter-spacing: 0.18em;
}

.booking-card__details strong {
  display: block;
  margin-top: 4px;
}

.booking-history__empty {
  text-align: center;
  color: rgba(15, 23, 42, 0.55);
  padding: 32px 0;
}

@media (max-width: 640px) {
  .payment-history {
    padding: 20px 16px;
  }
  .booking-card__body {
    padding: 22px;
  }
  .history-hero {
    padding: 24px;
  }
}
</style>
