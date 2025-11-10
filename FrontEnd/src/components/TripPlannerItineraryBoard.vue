<script setup>
import { computed } from 'vue'

const accommodationLabels = {
  comfort: 'Comfort stays',
  premium: 'Premium stays',
  luxury: 'Luxury stays',
}

const categoryLabels = {
  morning: 'Morning',
  afternoon: 'Afternoon',
  evening: 'Evening',
  nightlife: 'Night',
  night: 'Night',
  meal: 'Meal',
  dining: 'Meal',
  lunch: 'Meal',
  breakfast: 'Breakfast',
  lodging: 'Stay',
  stay: 'Stay',
  flex: 'Flexible',
}

const groupTypeLabels = {
  solo: 'Solo trip',
  couple: 'Couple escape',
  family: 'Family trip',
  friends: 'Friends getaway',
  elderly: 'Senior-friendly',
}

const timelineTypeMap = {
  morning: 'info',
  brunch: 'info',
  breakfast: 'info',
  meal: 'success',
  lunch: 'success',
  afternoon: 'default',
  evening: 'warning',
  nightlife: 'warning',
  night: 'warning',
  stay: 'success',
  lodging: 'success',
  flex: 'info',
}

const timelineColorMap = {
  morning: '#0ea5e9',
  meal: '#10b981',
  lunch: '#10b981',
  afternoon: '#818cf8',
  evening: '#f59e0b',
  nightlife: '#f97316',
  stay: '#22d3ee',
  lodging: '#22d3ee',
  flex: '#94a3b8',
}

const categoryIconMap = {
  morning: 'ri-sun-line',
  brunch: 'ri-sun-line',
  breakfast: 'ri-sun-line',
  meal: 'ri-restaurant-2-line',
  lunch: 'ri-restaurant-2-line',
  afternoon: 'ri-landscape-line',
  evening: 'ri-contrast-drop-2-line',
  nightlife: 'ri-moon-clear-line',
  night: 'ri-moon-clear-line',
  stay: 'ri-hotel-bed-line',
  lodging: 'ri-hotel-bed-line',
  flex: 'ri-compass-3-line',
}

const props = defineProps({
  title: {
    type: String,
    default: '',
  },
  startDate: {
    type: String,
    default: '',
  },
  endDate: {
    type: String,
    default: '',
  },
  visibility: {
    type: String,
    default: 'Private',
  },
  items: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
  budget: {
    type: Number,
    default: null,
  },
  budgetRange: {
    type: Array,
    default: () => [],
  },
  groupSize: {
    type: Number,
    default: null,
  },
  travelPace: {
    type: String,
    default: '',
  },
  travelStyles: {
    type: Array,
    default: () => [],
  },
  interests: {
    type: Array,
    default: () => [],
  },
  groupType: {
    type: String,
    default: '',
  },
  accommodation: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['add-item', 'edit-item', 'remove-item'])

const styleLabels = {
  culture: 'Culture',
  nature: 'Nature',
  adventure: 'Adventure',
  food: 'Food trail',
  relax: 'Wellness',
  city: 'Cityscape',
  historical: 'Heritage',
  balanced: 'Balanced mix',
}

const dayFormatter = new Intl.DateTimeFormat(undefined, {
  weekday: 'short',
  month: 'short',
  day: 'numeric',
})

const timeFormatter = new Intl.DateTimeFormat(undefined, {
  hour: '2-digit',
  minute: '2-digit',
})

const durationDays = computed(() => {
  if (!props.startDate || !props.endDate) return null
  const start = new Date(props.startDate)
  const end = new Date(props.endDate)
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return null
  const diff = Math.floor((end.getTime() - start.getTime()) / (1000 * 60 * 60 * 24))
  return diff + 1
})

const groupedDays = computed(() => {
  const buckets = new Map()
  for (const item of props.items ?? []) {
    const dateKey = item.date || ''
    if (!dateKey) continue
    if (!buckets.has(dateKey)) {
      buckets.set(dateKey, [])
    }
    buckets.get(dateKey).push(item)
  }
  const sorted = Array.from(buckets.entries()).sort(([a], [b]) => (a < b ? -1 : 1))
  return sorted.map(([date, items]) => ({
    date,
    label: dayFormatter.format(new Date(date)),
    items: [...items].sort(sortItemsWithinDay),
  }))
})

const unscheduledItems = computed(() =>
  (props.items ?? []).filter((item) => !item.date),
)

const travelStyleBadges = computed(() =>
  (props.travelStyles ?? []).map((style) => styleLabels[style] ?? style),
)

const interestBadges = computed(() => {
  const entries = Array.isArray(props.interests) ? props.interests.filter(Boolean) : []
  return entries.slice(0, 4)
})

const groupTypeLabel = computed(() =>
  props.groupType ? groupTypeLabels[props.groupType] ?? props.groupType : '',
)

const accommodationLabel = computed(() => accommodationLabels[props.accommodation] ?? null)

function sortItemsWithinDay(a, b) {
  if (a.time === b.time) {
    return a.title.localeCompare(b.title)
  }
  if (!a.time) return 1
  if (!b.time) return -1
  return a.time < b.time ? -1 : 1
}

function formatTime(value) {
  if (!value) return 'Flexible'
  const date = new Date(`1970-01-01T${value}`)
  if (Number.isNaN(date.getTime())) {
    return value
  }
  return timeFormatter.format(date)
}

function formatCurrency(value) {
  const numeric = Number(value)
  if (!Number.isFinite(numeric) || numeric < 0) {
    return null
  }
  return numeric.toLocaleString(undefined, { maximumFractionDigits: 0 })
}

function formatBudgetSpan(range) {
  if (!Array.isArray(range) || range.length < 2) {
    return ''
  }
  const [min, max] = range
  const minLabel = formatCurrency(min)
  const maxLabel = formatCurrency(max)
  if (minLabel && maxLabel) {
    return `RM${minLabel} - RM${maxLabel}`
  }
  if (minLabel) {
    return `RM${minLabel}`
  }
  if (maxLabel) {
    return `Under RM${maxLabel}`
  }
  return ''
}

function formatCostTag(value) {
  const formatted = formatCurrency(value)
  return formatted ? `RM${formatted}` : null
}

function formatCategoryTag(value) {
  if (!value) return null
  const key = String(value).toLowerCase()
  return categoryLabels[key] ?? value.charAt(0).toUpperCase() + value.slice(1)
}

function timelineType(category) {
  const key = (category || '').toLowerCase()
  return timelineTypeMap[key] || 'info'
}

function timelineColor(category) {
  const key = (category || '').toLowerCase()
  return timelineColorMap[key] || undefined
}

function timelineIconClass(category) {
  const key = (category || '').toLowerCase()
  return categoryIconMap[key] || 'ri-compass-3-line'
}
</script>

<template>
  <n-card size="small" :segmented="{ content: true }" class="trip-itinerary-card">
    <template #header>
      <n-space align="center" justify="space-between" wrap>
        <div>
          <div class="itinerary-card__title">{{ title || 'New itinerary draft' }}</div>
          <n-text depth="3">
            {{ startDate }} - {{ endDate }}
            <template v-if="durationDays">&bull; {{ durationDays }} days</template>
            <template v-if="visibility">&bull; {{ visibility }}</template>
          </n-text>
        </div>
        <n-space size="small" align="center" wrap>
          <n-tag type="success" v-if="groupSize" round size="small">
            {{ groupSize }} traveler{{ groupSize > 1 ? 's' : '' }}
          </n-tag>
          <n-tag v-if="groupTypeLabel" round size="small" type="info">
            {{ groupTypeLabel }}
          </n-tag>
          <n-tag type="info" v-if="budgetRange?.length" round size="small">
            Budget: {{ formatBudgetSpan(budgetRange) }}
          </n-tag>
          <n-tag type="info" v-else-if="budget" round size="small">
            Budget ~ RM{{ formatCurrency(budget) }}
          </n-tag>
          <n-tag v-if="accommodationLabel" round size="small" type="success">
            {{ accommodationLabel }}
          </n-tag>
          <n-tag
            v-for="style in travelStyleBadges"
            :key="style"
            round
            size="small"
            type="warning"
          >
            {{ style }}
          </n-tag>
          <n-tag v-if="travelPace" round size="small" type="default">
            Pace: {{ travelPace }}
          </n-tag>
          <n-tag
            v-for="interest in interestBadges"
            :key="interest"
            round
            size="small"
            type="info"
          >
            {{ interest }}
          </n-tag>
        </n-space>
      </n-space>
    </template>

    <n-spin :show="loading">
      <div v-if="!groupedDays.length" class="trip-itinerary-empty">
        <n-empty
          description="No activities scheduled yet. Generate a plan or add activities by day."
        />
        <n-button tertiary size="small" type="primary" style="margin-top: 12px;" @click="emit('add-item', null)">
          Add first activity
        </n-button>
      </div>
      <div v-else class="trip-itinerary-grid">
        <n-card
          v-for="(day, index) in groupedDays"
          :key="day.date"
          :title="`Day ${index + 1}`"
          class="trip-itinerary-day"
        >
          <template #header-extra>
            <n-space align="center" size="small">
              <n-text depth="3">{{ day.label }}</n-text>
              <n-button text size="tiny" @click="emit('add-item', day.date)">Add activity</n-button>
            </n-space>
          </template>
          <n-timeline size="small" class="trip-itinerary-timeline">
            <n-timeline-item
            v-for="item in day.items"
            :key="item.localId || item.itemId"
            :type="timelineType(item.category)"
            :color="timelineColor(item.category)"
            :line-type="item.category === 'flex' ? 'dashed' : 'solid'"
            class="trip-timeline-item"
          >
            <template #icon>
              <n-icon :class="['timeline-icon', `timeline-icon--${item.category || 'default'}`]">
                <i :class="timelineIconClass(item.category)" />
              </n-icon>
            </template>
            <div class="timeline-item">
              <div class="timeline-item__body">
                <div class="timeline-item__details">
                  <div class="timeline-item__title">{{ item.title }}</div>
                  <n-text depth="3" class="timeline-item__notes" v-if="item.notes">
                    {{ item.notes }}
                  </n-text>
                  <n-text depth="3" v-if="item.address" class="timeline-item__notes">
                    {{ item.address }}
                  </n-text>
                  <n-space size="small" align="center" wrap>
                    <n-tag v-if="formatCategoryTag(item.category)" size="tiny" round>
                      {{ formatCategoryTag(item.category) }}
                    </n-tag>
                    <n-tag v-if="formatCostTag(item.score)" size="tiny" round type="success">
                      {{ formatCostTag(item.score) }}
                    </n-tag>
                  </n-space>
                </div>
                <div class="timeline-item__time">{{ formatTime(item.time) }}</div>
              </div>
              <div class="timeline-item__actions">
                <n-button text size="tiny" @click="emit('edit-item', item)">Edit</n-button>
                <n-popconfirm
                  @positive-click="emit('remove-item', item)"
                  positive-text="Remove"
                  negative-text="Cancel"
                >
                  <template #trigger>
                    <n-button text type="error" size="tiny">Delete</n-button>
                  </template>
                  Remove {{ item.title }} from this day?
                </n-popconfirm>
              </div>
            </div>
            </n-timeline-item>
          </n-timeline>
        </n-card>
      </div>

      <n-card
        v-if="unscheduledItems.length"
        title="Flexible items"
        class="trip-itinerary-day"
        size="small"
      >
        <n-space vertical>
          <n-alert type="info" :bordered="false">
            These placeholders still need a date. Use “Schedule” to assign them to a day.
          </n-alert>
          <n-card
            v-for="item in unscheduledItems"
            :key="item.localId || item.itemId"
            size="small"
            :bordered="false"
            embedded
          >
            <div class="timeline-item">
              <div class="timeline-item__meta">
                <div class="timeline-item__title">{{ item.title }}</div>
                <n-text v-if="item.notes" depth="3">{{ item.notes }}</n-text>
              </div>
              <n-space size="small">
                <n-button text size="tiny" @click="emit('edit-item', item)">Schedule</n-button>
                <n-button text type="error" size="tiny" @click="emit('remove-item', item)">Remove</n-button>
              </n-space>
            </div>
          </n-card>
        </n-space>
      </n-card>
    </n-spin>
  </n-card>
</template>

<style scoped>
.trip-itinerary-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 16px;
}

.trip-itinerary-empty {
  text-align: center;
  padding: 32px 0;
}

.timeline-item {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.timeline-item__meta {
  flex: 1;
  min-width: 0;
}

.timeline-item__time {
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.65);
  margin-bottom: 2px;
}

.timeline-item__title {
  font-weight: 600;
}

.timeline-item__headline {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
}

.timeline-item__notes {
  display: block;
  margin-top: 4px;
}

.trip-itinerary-card {
  width: 100%;
}

.trip-itinerary-day :deep(.n-card__content) {
  padding-top: 0;
}

.trip-itinerary-timeline {
  margin-top: 8px;
}

.trip-itinerary-timeline :deep(.n-timeline-item-content) {
  width: 100%;
}

.trip-timeline-item :deep(.n-timeline-item__icon) {
  width: 32px;
  height: 32px;
}

.timeline-icon {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(59, 130, 246, 0.12);
  color: #1d4ed8;
  font-size: 16px;
}

.timeline-icon--meal {
  background: rgba(16, 185, 129, 0.15);
  color: #047857;
}

.timeline-icon--afternoon {
  background: rgba(99, 102, 241, 0.15);
  color: #4338ca;
}

.timeline-icon--evening,
.timeline-icon--nightlife,
.timeline-icon--night {
  background: rgba(245, 158, 11, 0.2);
  color: #b45309;
}

.timeline-icon--lodging,
.timeline-icon--stay {
  background: rgba(14, 165, 233, 0.18);
  color: #0369a1;
}

.timeline-icon--flex {
  background: rgba(148, 163, 184, 0.2);
  color: #475569;
}

.timeline-item__body {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
}

.timeline-item__details {
  flex: 1;
  min-width: 0;
}

.timeline-item__time {
  font-weight: 600;
  color: rgba(15, 23, 42, 0.7);
  min-width: 64px;
  text-align: right;
  font-size: 0.85rem;
}

.timeline-item__actions {
  display: flex;
  justify-content: flex-end;
  gap: 4px;
  margin-top: 6px;
}
</style>
