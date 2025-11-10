<script setup>
import { computed, watch } from 'vue'

const preferences = defineModel('preferences', { type: Object, required: true })

const props = defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['generate', 'reset'])

const destinationOptions = [
  'Kuala Lumpur',
  'Penang',
  'Langkawi',
  'Kota Kinabalu',
  'Kuching',
  'Cameron Highlands',
  'Melaka',
]

const travelStyleOptions = [
  { label: 'Culture & heritage', value: 'culture' },
  { label: 'Nature & wildlife', value: 'nature' },
  { label: 'Adventure', value: 'adventure' },
  { label: 'Food trail', value: 'food' },
  { label: 'Relax & wellness', value: 'relax' },
]

const paceOptions = [
  { label: 'Relaxed', value: 'relaxed' },
  { label: 'Balanced', value: 'balanced' },
  { label: 'Full throttle', value: 'fast' },
]

const accommodationOptions = [
  { label: 'Comfort (boutique & eco stays)', value: 'comfort' },
  { label: 'Premium (4-star city hotels)', value: 'premium' },
  { label: 'Luxury (5-star resorts)', value: 'luxury' },
]

const interestOptions = [
  'UNESCO heritage',
  'Night markets',
  'Cultural workshops',
  'Hiking',
  'Island hopping',
  'Community projects',
  'Marine life',
  'Street food',
]

const budgetMarks = {
  0: 'RM0',
  1500: 'RM1.5k',
  3000: 'RM3k',
  4500: 'RM4.5k',
  6000: 'RM6k+',
}

const DEFAULT_BUDGET_RANGE = [0, 2200]

const budgetRange = computed({
  get() {
    const range = preferences.value.budgetRange
    if (Array.isArray(range) && range.length >= 2) {
      return range
    }
    const fallback = deriveBudgetRange(preferences.value.budget)
    preferences.value.budgetRange = fallback
    return fallback
  },
  set(next) {
    const updated = normaliseBudgetRange(next)
    preferences.value.budgetRange = updated
    preferences.value.budget = Math.round((updated[0] + updated[1]) / 2)
  },
})

const minBudget = computed({
  get: () => budgetRange.value[0],
  set: (value) => {
    const numeric = Number(value)
    budgetRange.value = [numeric, budgetRange.value[1]]
  },
})

const maxBudget = computed({
  get: () => budgetRange.value[1],
  set: (value) => {
    const numeric = Number(value)
    budgetRange.value = [budgetRange.value[0], numeric]
  },
})

const durationLabel = computed(() => {
  const start = preferences.value?.startDate
  const end = preferences.value?.endDate
  if (!start || !end) return ''
  const startDate = new Date(start)
  const endDate = new Date(end)
  if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) {
    return ''
  }
  const diff = Math.abs(
    Math.floor((endDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24)),
  )
  return `${diff + 1} day trip`
})

watch(
  () => [preferences.value.startDate, preferences.value.durationDays],
  ([start, duration]) => {
    if (!start || !duration || duration <= 0) {
      return
    }
    const startDate = new Date(start)
    if (Number.isNaN(startDate.getTime())) {
      return
    }
    const endDate = new Date(startDate)
    endDate.setDate(startDate.getDate() + duration - 1)
    const formattedEnd = formatDate(endDate)
    if (preferences.value.endDate !== formattedEnd) {
      preferences.value.endDate = formattedEnd
    }
  },
)

watch(
  () => [preferences.value.startDate, preferences.value.endDate],
  ([start, end]) => {
    if (!start || !end) {
      preferences.value.durationDays = null
      return
    }
    const startDate = new Date(start)
    const endDate = new Date(end)
    if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) {
      return
    }
    const diff =
      Math.floor((endDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24)) + 1
    if (diff > 0 && preferences.value.durationDays !== diff) {
      preferences.value.durationDays = diff
    }
  },
)

if (!preferences.value.accommodation) {
  preferences.value.accommodation = 'comfort'
}

function normaliseBudgetRange(range) {
  const candidate = Array.isArray(range) ? range : []
  let min = Number(candidate[0])
  let max = Number(candidate[1])
  if (!Number.isFinite(min)) {
    min = DEFAULT_BUDGET_RANGE[0]
  }
  if (!Number.isFinite(max)) {
    max = DEFAULT_BUDGET_RANGE[1]
  }
  if (max < min) {
    ;[min, max] = [max, min]
  }
  if (max - min < 200) {
    max = min + 200
  }
  min = Math.max(0, Math.round(min))
  max = Math.max(min + 100, Math.round(max))
  return [min, max]
}

function deriveBudgetRange(seed) {
  const base = Number(seed)
  if (!Number.isFinite(base) || base <= 0) {
    return [...DEFAULT_BUDGET_RANGE]
  }
  const spread = Math.max(300, Math.round(base * 0.4))
  const min = Math.max(0, base - spread)
  const max = base + spread
  return normaliseBudgetRange([min, max])
}

function handleGenerate() {
  emit('generate')
}

function handleReset() {
  emit('reset')
}

function formatDate(date) {
  return date.toISOString().slice(0, 10)
}
</script>

<template>
  <n-card
    size="small"
    :segmented="{ content: true, footer: true }"
    class="trip-preferences-card"
    title="Input travel preferences"
  >
    <n-form label-placement="top" size="large" class="trip-preferences-form">
      <n-grid cols="1 m:2" :x-gap="18" :y-gap="12">
        <n-form-item label="Trip title" path="title">
          <n-input
            v-model:value="preferences.title"
            maxlength="120"
            placeholder="Eg. Penang eco food crawl"
            :disabled="props.disabled"
          />
        </n-form-item>

        <n-form-item path="destination">
          <template #label>
            <div class="form-label">
              Destination focus
              <n-tooltip trigger="hover">
                <template #trigger>
                  <n-icon size="14">
                    <i class="ri-question-line" />
                  </n-icon>
                </template>
                Choose the primary city or region you want to explore.
              </n-tooltip>
            </div>
          </template>
          <n-select
            v-model:value="preferences.destination"
            :options="destinationOptions.map((label) => ({ label, value: label }))"
            filterable
            :disabled="props.disabled"
            placeholder="Choose a Malaysian city or region"
          />
        </n-form-item>

        <n-form-item path="startDate">
          <template #label>
            <div class="form-label">
              Start date
              <n-tooltip trigger="hover">
                <template #trigger>
                  <n-icon size="14">
                    <i class="ri-question-line" />
                  </n-icon>
                </template>
                Pick the date you plan to arrive at the destination.
              </n-tooltip>
            </div>
          </template>
          <n-date-picker
            v-model:value="preferences.startDate"
            type="date"
            value-format="yyyy-MM-dd"
            :disabled="props.disabled"
            clearable
          />
        </n-form-item>

        <n-form-item path="endDate">
          <template #label>
            <div class="form-label">
              End date
              <n-tooltip trigger="hover">
                <template #trigger>
                  <n-icon size="14">
                    <i class="ri-question-line" />
                  </n-icon>
                </template>
                We will calculate your total travel days from these dates.
              </n-tooltip>
            </div>
          </template>
          <n-date-picker
            v-model:value="preferences.endDate"
            type="date"
            value-format="yyyy-MM-dd"
            :disabled="props.disabled"
            clearable
          />
        </n-form-item>

        <n-form-item path="durationDays">
          <template #label>
            <div class="form-label">
              Number of travel days
              <n-tooltip trigger="hover">
                <template #trigger>
                  <n-icon size="14">
                    <i class="ri-question-line" />
                  </n-icon>
                </template>
                Enter how many full days you want the itinerary to cover. We use this to set your end date.
              </n-tooltip>
            </div>
          </template>
          <n-input-number
            v-model:value="preferences.durationDays"
            :min="1"
            :max="21"
            :disabled="props.disabled"
          >
            <template #suffix>days</template>
          </n-input-number>
        </n-form-item>

        <n-form-item label="Travel party size" path="groupSize">
          <n-input-number
            v-model:value="preferences.groupSize"
            :min="1"
            :max="12"
            :disabled="props.disabled"
          >
            <template #suffix>people</template>
          </n-input-number>
        </n-form-item>

        <n-form-item label="Accommodation style" path="accommodation">
          <n-radio-group
            v-model:value="preferences.accommodation"
            :disabled="props.disabled"
          >
            <n-radio-button
              v-for="option in accommodationOptions"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </n-radio-button>
          </n-radio-group>
        </n-form-item>

        <n-form-item path="groupType">
          <template #label>
            <div class="form-label">
              Group type
              <n-tooltip trigger="hover">
                <template #trigger>
                  <n-icon size="14">
                    <i class="ri-question-line" />
                  </n-icon>
                </template>
                Tell us who you are traveling with so we can match suitable activities.
              </n-tooltip>
            </div>
          </template>
          <n-select
            v-model:value="preferences.groupType"
            placeholder="Select group type"
            :options="[
              { label: 'Solo traveler', value: 'solo' },
              { label: 'Couple getaway', value: 'couple' },
              { label: 'Family trip', value: 'family' },
              { label: 'Friends / group', value: 'friends' },
            ]"
            :disabled="props.disabled"
          />
        </n-form-item>

        <n-form-item label="Budget range (MYR)" path="budgetRange">
          <n-space vertical>
            <n-slider
              v-model:value="budgetRange"
              range
              :step="100"
              :min="0"
              :max="6000"
              :marks="budgetMarks"
              :disabled="props.disabled"
            />
            <n-space size="small">
              <n-input-number
                v-model:value="minBudget"
                size="small"
                :min="0"
                :max="maxBudget"
                :disabled="props.disabled"
              >
                <template #prefix>RM</template>
                <template #suffix>min</template>
              </n-input-number>
              <n-input-number
                v-model:value="maxBudget"
                size="small"
                :min="minBudget"
                :max="8000"
                :disabled="props.disabled"
              >
                <template #prefix>RM</template>
                <template #suffix>max</template>
              </n-input-number>
            </n-space>
          </n-space>
        </n-form-item>

        <n-form-item path="travelStyles">
          <template #label>
            <div class="form-label">
              Travel themes
              <n-tooltip trigger="hover">
                <template #trigger>
                  <n-icon size="14">
                    <i class="ri-question-line" />
                  </n-icon>
                </template>
                Pick the styles that best describe your ideal trip. At least one is required.
              </n-tooltip>
            </div>
          </template>
          <n-checkbox-group
            v-model:value="preferences.travelStyles"
            :disabled="props.disabled"
          >
            <n-space item-style="margin-bottom: 8px;" wrap>
              <n-checkbox
                v-for="style in travelStyleOptions"
                :key="style.value"
                :value="style.value"
              >
                {{ style.label }}
              </n-checkbox>
            </n-space>
          </n-checkbox-group>
        </n-form-item>

        <n-form-item label="Top interests" path="interests">
          <n-select
            v-model:value="preferences.interests"
            multiple
            tag
            :options="interestOptions.map((label) => ({ label, value: label }))"
            :disabled="props.disabled"
            placeholder="Pick up to 4 key interests"
            max-tag-count="responsive"
          />
        </n-form-item>

        <n-form-item label="Preferred pace" path="travelPace">
          <n-radio-group
            v-model:value="preferences.travelPace"
            :disabled="props.disabled"
          >
            <n-radio-button
              v-for="option in paceOptions"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </n-radio-button>
          </n-radio-group>
        </n-form-item>

        <n-form-item label="Trip visibility" path="visibility">
          <n-select
            v-model:value="preferences.visibility"
            :options="[
              { label: 'Private (only me)', value: 'Private' },
              { label: 'Shared (friends & family)', value: 'Shared' },
              { label: 'Public inspiration', value: 'Public' },
            ]"
            :disabled="props.disabled"
          />
        </n-form-item>

      </n-grid>
    </n-form>

    <template #footer>
      <n-space justify="space-between" align="center" wrap>
        <n-text depth="3">{{ durationLabel }}</n-text>
        <n-space>
          <n-button secondary :disabled="props.loading" @click="handleReset">
            Reset preferences
          </n-button>
          <n-button
            type="primary"
            :loading="props.loading"
            :disabled="props.disabled"
            @click="handleGenerate"
          >
            Generate itinerary
          </n-button>
        </n-space>
      </n-space>
    </template>
  </n-card>
</template>

<style scoped>
.form-label {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-weight: 600;
}

.trip-preferences-form {
  max-height: calc(100vh - 220px);
  overflow-y: auto;
  padding-right: 4px;
}
</style>
