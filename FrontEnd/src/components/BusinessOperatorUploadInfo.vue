<script setup>
import { computed, reactive, ref, watch } from 'vue'
import {
  NAlert,
  NButton,
  NCard,
  NForm,
  NFormItem,
  NGrid,
  NGridItem,
  NInput,
  NInputNumber,
  NSelect,
  NSlider,
  NSpace,
  NText,
} from 'naive-ui'

const props = defineProps({
  apiBase: {
    type: String,
    required: true,
  },
  operatorId: {
    type: [Number, String],
    default: null,
  },
})

const emit = defineEmits(['listing-created', 'operator-updated', 'request-scroll-top'])

const categoryOptions = [
  { label: 'Hotel (Agoda API)', value: 'Hotel (Agoda API)' },
  { label: 'Homestay', value: 'Homestay' },
  { label: 'Eco-tour', value: 'Eco-tour' },
  { label: 'Food & Beverage', value: 'Food & Beverage' },
  { label: 'Cultural activity', value: 'Cultural activity' },
  { label: 'Wellness', value: 'Wellness' },
  { label: 'Others', value: 'Others' },
]

const PRICE_RANGE_MAX = 2000
const PRICE_RANGE_STEP = 10
const DEFAULT_PRICE_RANGE = { min: 80, max: 220 }

const formState = reactive({
  name: '',
  category: null,
  type: 'Small Business',
  phone: '',
  email: '',
  address: '',
  website: '',
  description: '',
  highlights: '',
  priceRange: { ...DEFAULT_PRICE_RANGE },
})

const formErrors = reactive({})
const submissionSuccess = ref('')
const submissionError = ref('')
const isSubmittingListing = ref(false)

const priceRangeSliderValue = computed({
  get: () => [formState.priceRange.min, formState.priceRange.max],
  set: (value) => {
    if (!Array.isArray(value) || value.length < 2) return
    let [min, max] = value
    min = Number.isFinite(Number(min)) ? Number(min) : 0
    max = Number.isFinite(Number(max)) ? Number(max) : min
    min = Math.min(PRICE_RANGE_MAX, Math.max(0, Math.round(min)))
    max = Math.min(PRICE_RANGE_MAX, Math.max(Math.round(max), min))
    formState.priceRange.min = min
    formState.priceRange.max = max
  },
})

function sanitisePriceInput(rawValue, fallback) {
  const numeric = Number(rawValue)
  if (!Number.isFinite(numeric)) {
    return fallback
  }
  return Math.min(PRICE_RANGE_MAX, Math.max(0, Math.round(numeric)))
}

watch(
  () => formState.priceRange.min,
  (value) => {
    const sanitized = sanitisePriceInput(value, DEFAULT_PRICE_RANGE.min)
    if (sanitized !== value) {
      formState.priceRange.min = sanitized
      return
    }
    if (formState.priceRange.max < sanitized) {
      formState.priceRange.max = sanitized
    }
  },
)

watch(
  () => formState.priceRange.max,
  (value) => {
    const sanitized = sanitisePriceInput(value, formState.priceRange.min)
    if (sanitized !== value) {
      formState.priceRange.max = sanitized
      return
    }
    if (sanitized < formState.priceRange.min) {
      formState.priceRange.min = sanitized
    }
  },
)

function validateForm() {
  const errors = {}

  if (!formState.name.trim()) errors.name = 'Business name is required.'
  if (!formState.category) errors.category = 'Select the category that best fits your service.'
  if (!formState.phone.trim()) errors.phone = 'Provide a contact number for travelers.'
  if (!formState.email.trim()) {
    errors.email = 'Email address is required.'
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formState.email)) {
    errors.email = 'Enter a valid email format.'
  }
  if (formState.website && !/^(https?:\/\/)([\w.-]+)(:\d+)?(\/.*)?$/i.test(formState.website.trim())) {
    errors.website = 'Enter a valid URL including http:// or https://.'
  }
  if (!formState.address.trim()) errors.address = 'Physical address helps travelers locate you.'
  if (!formState.description.trim()) errors.description = 'Describe your services and highlights.'
  const minPrice = Number(formState.priceRange.min)
  const maxPrice = Number(formState.priceRange.max)
  if (
    !Number.isFinite(minPrice) ||
    !Number.isFinite(maxPrice) ||
    minPrice < 0 ||
    maxPrice <= minPrice
  ) {
    errors.priceRange = 'Set a valid minimum and maximum price.'
  }

  Object.keys(formErrors).forEach((key) => delete formErrors[key])
  Object.assign(formErrors, errors)

  return Object.keys(errors).length === 0
}

function resetForm() {
  formState.name = ''
  formState.category = null
  formState.type = 'Small Business'
  formState.phone = ''
  formState.email = ''
  formState.address = ''
  formState.website = ''
  formState.description = ''
  formState.highlights = ''
  formState.priceRange.min = DEFAULT_PRICE_RANGE.min
  formState.priceRange.max = DEFAULT_PRICE_RANGE.max
  submissionError.value = ''
}

async function submitListing() {
  if (!validateForm()) {
    submissionSuccess.value = ''
    return
  }

  if (!props.operatorId) {
    submissionError.value = 'Operator account not loaded. Please refresh and try again.'
    return
  }

  isSubmittingListing.value = true
  submissionError.value = ''
  submissionSuccess.value = ''

  const payload = {
    operatorId: props.operatorId,
    name: formState.name.trim(),
    category: formState.category,
    description: formState.description.trim(),
    address: formState.address.trim(),
    phone: formState.phone.trim(),
    email: formState.email.trim(),
    website: formState.website.trim() || null,
    highlights: formState.highlights?.trim() || '',
    priceRange: {
      min: formState.priceRange.min,
      max: formState.priceRange.max,
    },
  }

  try {
    const response = await fetch(`${props.apiBase}/operator/listings.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })

    const result = await response.json().catch(() => null)
    if (!response.ok || !result || result.ok !== true || !result.listing) {
      throw new Error(result?.error || `Failed to submit listing (HTTP ${response.status})`)
    }

    submissionSuccess.value =
      result.message ??
      `Listing ${result.listing.id ?? result.listing.listingId ?? ''} submitted successfully. Status set to Pending Review.`
    submissionError.value = ''
    emit('listing-created', result.listing)
    if (result.operator) {
      emit('operator-updated', result.operator)
    }
    resetForm()
    emit('request-scroll-top')
  } catch (error) {
    submissionError.value = error instanceof Error ? error.message : 'Unable to submit listing.'
  } finally {
    isSubmittingListing.value = false
  }
}
</script>

<template>
  <n-space vertical size="large">
    <n-grid cols="1 m:3" :x-gap="16" :y-gap="16">
      <n-grid-item>
        <n-card size="small" :segmented="{ content: true }" style="height: 100%;">
          <n-space vertical size="small">
            <div style="font-weight: 600;">1. Business registration form</div>
            <n-text depth="3">
              Captures business name, category, contact number, email, and address with examples so operators know exactly what to submit.
            </n-text>
          </n-space>
        </n-card>
      </n-grid-item>
      <n-grid-item>
        <n-card size="small" :segmented="{ content: true }" style="height: 100%;">
          <n-space vertical size="small">
            <div style="font-weight: 600;">2. Service description & highlights</div>
            <n-text depth="3">
              Outline packages, specialties, or sustainability pledges so travelers quickly understand the differentiators.
            </n-text>
          </n-space>
        </n-card>
      </n-grid-item>
      <n-grid-item>
        <n-card size="small" :segmented="{ content: true }" style="height: 100%;">
          <n-space vertical size="small">
            <div style="font-weight: 600;">3. System review & storage</div>
            <n-text depth="3">
              Submissions enter Pending Review for administrative approval before publication, maintaining authenticity and quality.
            </n-text>
          </n-space>
        </n-card>
      </n-grid-item>
    </n-grid>

    <n-card id="upload-info" title="Business registration" :segmented="{ content: true }">
      <n-form label-placement="top" label-width="auto">
        <n-grid cols="1 m:2" :x-gap="18" :y-gap="18">
          <n-grid-item>
            <n-form-item label="Business name" :feedback="formErrors.name" :validation-status="formErrors.name ? 'error' : undefined">
              <n-input v-model:value="formState.name" placeholder="e.g. Sungai Palas Eco Homestay" />
            </n-form-item>
          </n-grid-item>
          <n-grid-item>
            <n-form-item label="Service category" :feedback="formErrors.category" :validation-status="formErrors.category ? 'error' : undefined">
              <n-select v-model:value="formState.category" :options="categoryOptions" placeholder="Select category" />
            </n-form-item>
          </n-grid-item>
          <n-grid-item>
            <n-form-item label="Contact number" :feedback="formErrors.phone" :validation-status="formErrors.phone ? 'error' : undefined">
              <n-input v-model:value="formState.phone" placeholder="e.g. +60 12-345 6789" />
            </n-form-item>
          </n-grid-item>
          <n-grid-item>
            <n-form-item label="Contact email" :feedback="formErrors.email" :validation-status="formErrors.email ? 'error' : undefined">
              <n-input v-model:value="formState.email" placeholder="e.g. hello@business.my" />
            </n-form-item>
          </n-grid-item>
          <n-grid-item>
            <n-form-item label="Business address" :feedback="formErrors.address" :validation-status="formErrors.address ? 'error' : undefined">
              <n-input v-model:value="formState.address" placeholder="Street, city, state" />
            </n-form-item>
          </n-grid-item>
          <n-grid-item>
            <n-form-item
              label="Website / booking link"
              :feedback="formErrors.website"
              :validation-status="formErrors.website ? 'error' : undefined"
            >
              <n-input v-model:value="formState.website" placeholder="Optional URL" />
            </n-form-item>
          </n-grid-item>
          <n-grid-item span="2">
            <n-form-item
              label="Typical price range (per person)"
              :feedback="formErrors.priceRange"
              :validation-status="formErrors.priceRange ? 'error' : undefined"
            >
              <div class="price-range-control">
                <n-slider
                  v-model:value="priceRangeSliderValue"
                  range
                  :step="PRICE_RANGE_STEP"
                  :min="0"
                  :max="PRICE_RANGE_MAX"
                />
                <n-space :wrap="false" :size="12">
                  <n-input-number
                    v-model:value="formState.priceRange.min"
                    :min="0"
                    :max="PRICE_RANGE_MAX"
                    :step="PRICE_RANGE_STEP"
                    prefix="RM"
                    size="small"
                  />
                  <n-input-number
                    v-model:value="formState.priceRange.max"
                    :min="0"
                    :max="PRICE_RANGE_MAX"
                    :step="PRICE_RANGE_STEP"
                    prefix="RM"
                    size="small"
                  />
                </n-space>
              </div>
            </n-form-item>
          </n-grid-item>
          <n-grid-item span="2">
            <n-form-item label="Service description" :feedback="formErrors.description" :validation-status="formErrors.description ? 'error' : undefined">
              <n-input
                v-model:value="formState.description"
                type="textarea"
                :rows="4"
                placeholder="Share what makes your offering unique, sustainability practices, or packages."
              />
            </n-form-item>
          </n-grid-item>
          <n-grid-item span="2">
            <n-form-item label="Highlights (optional)">
              <n-input
                v-model:value="formState.highlights"
                type="textarea"
                :rows="2"
                placeholder="List key selling points or certifications."
              />
            </n-form-item>
          </n-grid-item>
        </n-grid>

        <n-space justify="end" style="margin-top: 12px;">
          <n-button tertiary type="primary" @click.prevent="resetForm">Clear form</n-button>
          <n-button
            type="primary"
            :loading="isSubmittingListing"
            :disabled="isSubmittingListing"
            @click.prevent="submitListing"
          >
            Submit for review
          </n-button>
        </n-space>
      </n-form>

      <n-alert
        v-if="submissionError"
        type="error"
        title="Unable to submit listing"
        show-icon
        style="margin-top: 16px;"
      >
        {{ submissionError }}
      </n-alert>
      <n-alert
        v-else-if="submissionSuccess"
        type="success"
        title="Submission received"
        show-icon
        style="margin-top: 16px;"
      >
        {{ submissionSuccess }}
      </n-alert>
      <n-alert
        v-else-if="Object.keys(formErrors || {}).length"
        type="warning"
        title="Please resolve the highlighted fields"
        show-icon
        style="margin-top: 16px;"
      >
        The form mirrors the report workflow: all required information must be valid before the system stores your listing.
      </n-alert>
    </n-card>
  </n-space>
</template>

<style scoped>
.price-range-control {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
</style>
