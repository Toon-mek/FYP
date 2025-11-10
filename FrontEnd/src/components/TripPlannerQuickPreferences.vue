<script setup>
import { computed, nextTick, ref } from 'vue'

const preferences = defineModel('preferences', { type: Object, required: true })
const panelRef = ref(null)

const props = defineProps({
  disabled: {
    type: Boolean,
    default: false,
  },
  loading: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['confirm', 'advanced', 'reset'])

const groupTypeOptions = [
  { label: 'Solo', value: 'solo', icon: 'ri-user-line' },
  { label: 'Couple', value: 'couple', icon: 'ri-heart-line' },
  { label: 'Family', value: 'family', icon: 'ri-home-heart-line' },
  { label: 'Friends', value: 'friends', icon: 'ri-team-line' },
  { label: 'Elderly', value: 'elderly', icon: 'ri-user-3-line' },
]

const themeOptions = [
  { label: 'Culture', value: 'culture' },
  { label: 'Nature', value: 'nature' },
  { label: 'Food', value: 'food' },
  { label: 'Adventure', value: 'adventure' },
  { label: 'Relax', value: 'relax' },
  { label: 'Cityscape', value: 'city' },
  { label: 'Historical', value: 'historical' },
]

const paceOptions = [
  { label: 'Relaxed', value: 'relaxed' },
  { label: 'Balanced', value: 'balanced' },
  { label: 'Ambitious', value: 'fast' },
]

const accommodationOptions = [
  { label: 'Comfort', value: 'comfort', description: 'Boutique & homestays' },
  { label: 'Premium', value: 'premium', description: '4-star city hotels' },
  { label: 'Luxury', value: 'luxury', description: '5-star resorts & villas' },
]

const paceDescriptions = {
  relaxed: 'Easy mornings & long lunches',
  balanced: '3-4 highlights per day',
  fast: 'Packed sunrise-to-night',
}

const budgetMarks = computed(() => ({
  0: 'RM0',
  1500: 'RM1.5k',
  3000: 'RM3k',
  4500: 'RM4.5k',
  6000: 'RM6k+',
}))

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

if (!preferences.value.accommodation) {
  preferences.value.accommodation = 'comfort'
}

function scrollToTop() {
  nextTick(() => {
    if (panelRef.value) {
      panelRef.value.scrollTop = 0
    }
  })
}

defineExpose({ scrollToTop })

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

function toggleTheme(theme) {
  const list = new Set(preferences.value.travelStyles ?? [])
  if (list.has(theme)) {
    list.delete(theme)
  } else {
    list.add(theme)
  }
  preferences.value.travelStyles = Array.from(list)
}

function confirm() {
  emit('confirm')
}

function adjustGroupSize(delta) {
  const current = Number(preferences.value.groupSize) || 1
  const next = Math.min(20, Math.max(1, current + delta))
  preferences.value.groupSize = next
}
</script>

<template>
  <div ref="panelRef" class="quick-pref-panel">
    <div class="pref-grid">
      <section class="pref-card pref-card--context">
        <div class="pref-card__heading">
          <p class="pref-card__title">Group profile</p>
          <p class="pref-card__subtitle">Tell us who's traveling so we can pace each day properly.</p>
        </div>

        <div class="pref-field">
          <label class="field-label">
            <span>Who are you traveling with?</span>
            <n-tooltip trigger="hover">
              <template #trigger>
                <n-icon size="14">
                  <i class="ri-question-line" />
                </n-icon>
              </template>
              Matching the group type helps us recommend age-appropriate listings and activities.
            </n-tooltip>
          </label>
          <div class="chip-grid">
            <n-button
              v-for="option in groupTypeOptions"
              :key="option.value"
              size="small"
              tertiary
              :type="preferences.groupType === option.value ? 'primary' : 'default'"
              :disabled="props.disabled"
              :class="['group-chip', { 'group-chip--active': preferences.groupType === option.value }]"
              @click="preferences.groupType = option.value"
            >
              <n-icon size="14">
                <i :class="option.icon" />
              </n-icon>
              {{ option.label }}
            </n-button>
          </div>
        </div>

        <div class="pref-field-row">
        <div class="pref-field">
          <label class="field-label">
            <span>Group size</span>
            <n-tooltip trigger="hover">
              <template #trigger>
                <n-icon size="14">
                  <i class="ri-question-line" />
                </n-icon>
              </template>
              We use this to estimate per-person costs and recommend suitable stays.
            </n-tooltip>
          </label>
          <div class="group-size-picker">
            <button
              type="button"
              class="group-size-button"
              :disabled="props.disabled || preferences.groupSize <= 1"
              @click="adjustGroupSize(-1)"
            >
              <i class="ri-subtract-line" />
            </button>
            <div class="group-size-display">
              <span class="group-size-value">{{ preferences.groupSize }}</span>
              <small>people</small>
            </div>
            <button
              type="button"
              class="group-size-button"
              :disabled="props.disabled || preferences.groupSize >= 20"
              @click="adjustGroupSize(1)"
            >
              <i class="ri-add-line" />
            </button>
          </div>
        </div>

        <div class="pref-field">
          <label class="field-label">
            <span>Travel pace</span>
              <n-tooltip trigger="hover">
                <template #trigger>
                  <n-icon size="14">
                    <i class="ri-question-line" />
                  </n-icon>
                </template>
                Relaxed fits two key activities per day, while ambitious squeezes in more stops.
              </n-tooltip>
            </label>
            <div class="pace-options">
              <button
                v-for="option in paceOptions"
                :key="option.value"
                type="button"
                class="pace-chip"
                :class="{ 'pace-chip--active': preferences.travelPace === option.value }"
                :disabled="props.disabled"
                @click="preferences.travelPace = option.value"
              >
                <span>{{ option.label }}</span>
                <small>{{ paceDescriptions[option.value] }}</small>
              </button>
            </div>
          </div>
        </div>
      </section>

      <section class="pref-card pref-card--interests">
        <div class="pref-card__heading">
          <p class="pref-card__title">Experiences & stay</p>
          <p class="pref-card__subtitle">Select the vibes you want and how comfy the stay should be.</p>
        </div>

        <div class="pref-field">
          <label class="field-label">
            <span>Preferred travel themes</span>
            <n-tooltip trigger="hover">
              <template #trigger>
                <n-icon size="14">
                  <i class="ri-question-line" />
                </n-icon>
              </template>
              Pick at least one theme so the AI can balance food, culture, nature, and adventure days.
            </n-tooltip>
          </label>
          <div class="chip-grid chip-grid--pill">
            <n-button
              v-for="option in themeOptions"
              :key="option.value"
              size="small"
              quaternary
              round
              :type="preferences.travelStyles?.includes(option.value) ? 'primary' : 'default'"
              :disabled="props.disabled"
              :class="['theme-chip', { 'theme-chip--active': preferences.travelStyles?.includes(option.value) }]"
              @click="toggleTheme(option.value)"
            >
              {{ option.label }}
            </n-button>
          </div>
        </div>

        <div class="pref-field">
          <label class="field-label">
            <span>Accommodation style</span>
            <n-tooltip trigger="hover">
              <template #trigger>
                <n-icon size="14">
                  <i class="ri-question-line" />
                </n-icon>
              </template>
              Pick the comfort level so we can match suitable hotels or eco-stays.
            </n-tooltip>
          </label>
          <div class="accommodation-options">
            <button
              v-for="option in accommodationOptions"
              :key="option.value"
              type="button"
              class="accommodation-card"
              :class="{ 'accommodation-card--active': preferences.accommodation === option.value }"
              :disabled="props.disabled"
              @click="preferences.accommodation = option.value"
            >
              <span class="accommodation-card__title">{{ option.label }}</span>
              <span class="accommodation-card__subtitle">{{ option.description }}</span>
            </button>
          </div>
        </div>
      </section>

      <section class="pref-card pref-card--budget">
        <div class="pref-card__heading">
          <p class="pref-card__title">Budget (MYR)</p>
          <p class="pref-card__subtitle">
            Provide your comfort range so we can balance premium and budget-friendly ideas.
          </p>
        </div>
        <div class="budget-slider">
          <n-slider
            v-model:value="budgetRange"
            range
            :step="100"
            :min="0"
            :max="6000"
            :marks="budgetMarks"
            :disabled="props.disabled"
          />
        </div>
        <div class="budget-input-grid">
          <div class="budget-input">
            <span class="budget-input__label">Minimum</span>
            <n-input-number
              v-model:value="minBudget"
              size="small"
              :min="0"
              :max="maxBudget"
              :disabled="props.disabled"
            >
              <template #prefix>RM</template>
            </n-input-number>
          </div>
          <div class="budget-input">
            <span class="budget-input__label">Maximum</span>
            <n-input-number
              v-model:value="maxBudget"
              size="small"
              :min="minBudget"
              :max="8000"
              :disabled="props.disabled"
            >
              <template #prefix>RM</template>
            </n-input-number>
          </div>
        </div>
      </section>
    </div>

    <div class="pref-footer">
      <n-text depth="3" class="required-note">
        All preference fields are required before we generate a day-by-day itinerary.
      </n-text>
      <n-button tertiary size="small" :disabled="props.loading" @click="$emit('reset')">
        Reset
      </n-button>
    </div>
  </div>
</template>

<style scoped>
.quick-pref-panel {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 6px;
  box-sizing: border-box;
}

.pref-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 18px;
}

.pref-card {
  background: #fff;
  border: 1px solid rgba(15, 23, 42, 0.08);
  border-radius: 20px;
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 18px;
  box-shadow: 0 12px 32px rgba(15, 23, 42, 0.05);
}

.pref-card--context {
  background: linear-gradient(135deg, #ecf6ff, #f5f5ff);
  border: none;
  box-shadow: 0 20px 40px rgba(59, 130, 246, 0.12);
}

.pref-card--interests {
  background: linear-gradient(135deg, #fff4ec, #f0fbf7);
  border: none;
  box-shadow: 0 20px 40px rgba(16, 185, 129, 0.12);
}

.pref-card--budget {
  background: linear-gradient(135deg, #fff7ed, #f4f6ff);
  border: none;
}

.pref-card__heading {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.pref-card__title {
  font-weight: 600;
  font-size: 1rem;
  color: rgba(15, 23, 42, 0.95);
}

.pref-card__subtitle {
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.6);
}

.pref-field {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.pref-field-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
}

.field-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
  color: rgba(15, 23, 42, 0.9);
}

.chip-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.chip-grid :deep(.n-button) {
  border-radius: 40px;
  padding: 0 14px;
  border-color: rgba(15, 23, 42, 0.15);
}

.chip-grid--segments :deep(.n-button) {
  border-radius: 14px;
  padding: 10px 16px;
  min-width: 130px;
  justify-content: flex-start;
}

.chip-grid--pill :deep(.n-button) {
  font-weight: 500;
}

:deep(.group-chip) {
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.7);
  border: 1px solid transparent;
  padding: 0 18px;
  box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
  color: rgba(15, 23, 42, 0.85);
  transition: transform 0.15s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}

:deep(.group-chip--active) {
  border-color: rgba(22, 163, 74, 0.5);
  box-shadow: 0 8px 20px rgba(22, 163, 74, 0.2);
  background: rgba(187, 247, 208, 0.7);
  color: #065f46;
}

:deep(.group-chip:hover:not(.group-chip--active)) {
  transform: translateY(-1px);
  border-color: rgba(15, 23, 42, 0.15);
}

:deep(.theme-chip) {
  border-radius: 24px;
  padding: 0 16px;
  border: 1px solid rgba(15, 23, 42, 0.12);
  background: rgba(255, 255, 255, 0.6);
  color: rgba(15, 23, 42, 0.7);
  transition: color 0.2s ease, background 0.2s ease, border-color 0.2s ease;
}

:deep(.theme-chip--active) {
  background: rgba(34, 197, 94, 0.15);
  color: #047857;
  border-color: rgba(34, 197, 94, 0.4);
  box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.15);
}

:deep(.theme-chip:hover:not(.theme-chip--active)) {
  border-color: rgba(15, 23, 42, 0.18);
  color: rgba(15, 23, 42, 0.9);
}

.group-size-picker {
  display: flex;
  align-items: center;
  gap: 10px;
  background: rgba(255, 255, 255, 0.85);
  border-radius: 16px;
  padding: 10px 14px;
  border: 1px solid rgba(15, 23, 42, 0.1);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4);
}

.group-size-button {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: none;
  background: linear-gradient(135deg, #ecfccb, #a7f3d0);
  color: #065f46;
  font-size: 18px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.2s ease;
}

.group-size-button:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.group-size-button:not(:disabled):hover {
  transform: translateY(-1px);
  box-shadow: 0 8px 16px rgba(16, 185, 129, 0.25);
}

.group-size-display {
  flex: 1;
  text-align: center;
  display: flex;
  flex-direction: column;
  line-height: 1.1;
}

.group-size-value {
  font-size: 1.4rem;
  font-weight: 700;
  color: rgba(15, 23, 42, 0.9);
}

.group-size-display small {
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.6);
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.accommodation-options {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
}

.accommodation-card {
  border: 1px solid rgba(15, 23, 42, 0.12);
  border-radius: 16px;
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  background: #fff;
  text-align: left;
  font-family: inherit;
  cursor: pointer;
  transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.15s ease;
}

.accommodation-card:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.accommodation-card:hover:not(:disabled),
.accommodation-card--active {
  border-color: rgba(34, 197, 94, 0.6);
  box-shadow: 0 8px 20px rgba(34, 197, 94, 0.15);
  transform: translateY(-1px);
}

.accommodation-card__title {
  font-weight: 600;
  color: rgba(15, 23, 42, 0.95);
}

.accommodation-card__subtitle {
  font-size: 0.8rem;
  color: rgba(15, 23, 42, 0.6);
}

.pace-options {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 10px;
}

.pace-chip {
  border-radius: 16px;
  border: 1px solid rgba(15, 23, 42, 0.12);
  background: rgba(255, 255, 255, 0.8);
  padding: 12px 14px;
  text-align: left;
  display: flex;
  flex-direction: column;
  gap: 4px;
  cursor: pointer;
  transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
  font-weight: 600;
  color: rgba(15, 23, 42, 0.9);
}

.pace-chip small {
  font-size: 0.75rem;
  text-transform: none;
  font-weight: 500;
  color: rgba(15, 23, 42, 0.7);
}

.pace-chip--active {
  border-color: rgba(34, 197, 94, 0.75);
  background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(187, 247, 208, 0.7));
  box-shadow: 0 12px 24px rgba(16, 185, 129, 0.25);
  color: #047857;
}

.pace-chip:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.pace-chip:not(:disabled):hover {
  border-color: rgba(34, 197, 94, 0.4);
}

.budget-input-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 14px;
}

.budget-input {
  display: flex;
  flex-direction: column;
  gap: 6px;
  background: rgba(255, 255, 255, 0.85);
  border: 1px solid rgba(15, 23, 42, 0.08);
  border-radius: 14px;
  padding: 10px 12px;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
}

.budget-slider {
  padding: 8px 4px 4px;
}

.budget-slider :deep(.n-slider) {
  --n-rail-color: rgba(255, 255, 255, 0.6);
  --n-rail-color-hover: rgba(255, 255, 255, 0.8);
}

.budget-slider :deep(.n-slider-rail) {
  height: 6px;
  border-radius: 999px;
  background: linear-gradient(90deg, rgba(16, 185, 129, 0.1), rgba(234, 179, 8, 0.12));
}

.budget-slider :deep(.n-slider-fill) {
  background: linear-gradient(90deg, #10b981, #22c55e);
}

.budget-slider :deep(.n-slider-handle) {
  width: 16px;
  height: 16px;
  border: 2px solid #10b981;
  box-shadow: 0 6px 15px rgba(16, 185, 129, 0.3);
}

.budget-input__label {
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgba(15, 23, 42, 0.6);
}

.budget-input :deep(.n-input-number) {
  border: none;
  background: transparent;
  font-weight: 600;
}

.budget-input :deep(.n-input-number-input) {
  text-align: left;
}

.pref-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 4px 4px;
  border-top: 1px solid rgba(15, 23, 42, 0.08);
}

.required-note {
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.7);
}
</style>
