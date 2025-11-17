<script setup>
import { computed, reactive, ref, watch, nextTick } from 'vue'
import { useMessage } from 'naive-ui'
import {
  PHONE_REGEX,
  EXPIRY_DATE_REGEX,
  isValidPIN,
  isValidEmail,
  isValidCardholderName,
  isValidCardNumber,
  isValidExpiryDate
} from '../utils/validators.js'
import {
  authorizePaymentSession,
  fetchPaymentMethods,
  retryPaymentSession,
  startPaymentSession,
} from '../services/paymentSimulationService.js'

const props = defineProps({
  packageData: {
    type: Object,
    default: () => null,
  },
})

const emit = defineEmits(['back-to-booking', 'payment-authorized'])
const message = useMessage()

const travelerId = computed(() => props.packageData?.travelerId ?? props.packageData?.travelerID ?? null)
const packageId = computed(() => props.packageData?.packageId ?? props.packageData?.packageID ?? null)
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
    const normalisedThemeKey = normaliseThemeKey(themeKey)
    if (isExcludedBillingTheme(normalisedThemeKey)) {
      return
    }
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
        display: stay.priceLabel || '',
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

const totalLabel = computed(() => {
  const summaryValue = effectiveCostSummary.value
  if (!summaryValue || !Number.isFinite(Number(summaryValue.total)) || Number(summaryValue.total) <= 0) {
    return 'Not available'
  }
  return formatCurrencyAmount(summaryValue.total, summaryValue.currency || baseCurrency.value)
})

const billingBreakdown = computed(() => {
  const summaryValue = effectiveCostSummary.value
  if (!summaryValue?.categories) {
    return []
  }
  const currency = summaryValue.currency || baseCurrency.value
  return Object.entries(summaryValue.categories)
    .map(([key, amount]) => ({
      theme: canonicalTheme(key) || key,
      label: COST_THEME_LABELS[canonicalTheme(key) || key] || toTitleCase(key),
      amount: Number(amount) || 0,
      display: formatCurrencyAmount(amount, currency),
    }))
    .filter((entry) => !isExcludedBillingTheme(entry.theme))
})

const billingLineItems = computed(() => {
  const summaryValue = effectiveCostSummary.value
  if (!summaryValue?.picks?.length) {
    return []
  }
  const currency = summaryValue.currency || baseCurrency.value
  return summaryValue.picks
    .map((pick) => ({
      id: pick.id ?? pick.title,
      title: pick.title ?? '',
      subtitle: pick.subtitle ?? '',
      theme: pick.theme,
      display: formatCurrencyAmount(pick.price, pick.currency || currency),
    }))
    .filter((pick) => !isExcludedBillingTheme(pick.theme))
})

const heroTitle = computed(() => toTitleCase(props.packageData?.title || 'Select A Saved Package'))
const destinationLabel = computed(() =>
  toTitleCase(props.packageData?.destination || summary.value?.destination || 'Awaiting Destination'),
)
const heroTagline = computed(() => toTitleCase(summary.value?.tagline || 'Payment Concierge'))
const heroDescription = computed(
  () =>
    summary.value?.description ||
    'Securely settle your curated itinerary using FPX, major cards, or Malaysian e-wallets.',
)
const heroTags = computed(() => {
  const tags = []
  if (props.packageData?.destination) {
    tags.push(destinationLabel.value)
  }
  if (summary.value?.tripType) {
    tags.push(toTitleCase(summary.value.tripType))
  }
  if (totalSelections.value > 0) {
    tags.push(`${totalSelections.value} Selections`)
  }
  return [...new Set(tags.filter(Boolean))].slice(0, 3)
})

const journeyFacts = computed(() => {
  const facts = []
  if (summary.value?.dateRange) {
    facts.push({ label: 'Travel Window', value: summary.value.dateRange })
  }
  if (props.packageData?.destination) {
    facts.push({ label: 'Destination', value: destinationLabel.value })
  }
  if (summary.value?.climate) {
    facts.push({ label: 'Climate Vibe', value: toTitleCase(summary.value.climate) })
  }
  facts.push({
    label: 'Curation Note',
    value:
      summary.value?.curationNote ||
      summary.value?.description ||
      'All payment events are recorded for compliance review.',
  })
  return facts.slice(0, 4)
})

const paymentMethods = ref([])
const paymentMethodsLoading = ref(false)
const paymentMethodsLoaded = ref(false)
const paymentMethodError = ref('')
const selectedPaymentMethodCode = ref(null)
const paymentForm = reactive({})
const paymentFormValidation = reactive({})
const paymentSession = ref(null)
const paymentEvents = ref([])
const paymentReceipt = ref(null)
const paymentStep = ref('method')
const paymentSubmitting = ref(false)
const simulatorSectionRef = ref(null)
const paymentSimulatorVisible = ref(false)
const selectedPaymentCategory = ref('')

const selectedPaymentMethod = computed(
  () => paymentMethods.value.find((method) => method.code === selectedPaymentMethodCode.value) || null,
)
const orderedPaymentMethods = computed(() => {
  return [...paymentMethods.value].sort((a, b) => {
    const orderA = Number.isFinite(Number(a.sortOrder)) ? Number(a.sortOrder) : 999
    const orderB = Number.isFinite(Number(b.sortOrder)) ? Number(b.sortOrder) : 999
    if (orderA !== orderB) {
      return orderA - orderB
    }
    return (a.displayName || '').localeCompare(b.displayName || '')
  })
})
const paymentMethodGroups = computed(() => {
  return orderedPaymentMethods.value.reduce((groups, method) => {
    const key = method.category || 'other'
    if (!groups[key]) {
      groups[key] = []
    }
    groups[key].push(method)
    return groups
  }, {})
})
const paymentCategoryList = computed(() => {
  const groups = paymentMethodGroups.value
  const keys = Object.keys(groups)
  if (!keys.length) {
    return []
  }
  const orderedKeys = [
    ...PAYMENT_CATEGORY_ORDER.filter((key) => groups[key]?.length),
    ...keys.filter((key) => !PAYMENT_CATEGORY_ORDER.includes(key)),
  ]
  return orderedKeys.map((key) => ({
    key,
    label: paymentCategoryLabel(key),
    methods: groups[key],
  }))
})
const visiblePaymentMethods = computed(() => {
  const current = paymentCategoryList.value.find((category) => category.key === selectedPaymentCategory.value)
  return current?.methods ?? []
})

function validatePaymentField(fieldKey, fieldConfig, value) {
  const trimmed = String(value || '').trim()

  // Check required
  if (fieldConfig.required && !trimmed) {
    return { status: 'error', message: 'This field is required' }
  }

  // If empty and not required, it's valid
  if (!trimmed) {
    return { status: 'success', message: '' }
  }

  // FPX Account holder name validation
  if (fieldKey === 'accountName') {
    if (!isValidCardholderName(trimmed)) {
      if (trimmed.length < 3) {
        return { status: 'error', message: 'Name must be at least 3 characters' }
      }
      return { status: 'error', message: 'Only letters, spaces, and common punctuation allowed' }
    }
    return { status: 'success', message: '' }
  }

  // FPX Bank User ID validation
  if (fieldKey === 'bankUserId') {
    if (trimmed.length < 3) {
      return { status: 'error', message: 'User ID must be at least 3 characters' }
    }
    return { status: 'success', message: '' }
  }

  // Cardholder name validation
  if (fieldKey === 'cardHolder') {
    if (!isValidCardholderName(trimmed)) {
      if (trimmed.length < 3) {
        return { status: 'error', message: 'Name must be at least 3 characters' }
      }
      return { status: 'error', message: 'Only letters, spaces, and common punctuation allowed' }
    }
    return { status: 'success', message: '' }
  }

  // Card number validation (Visa 16-digit or AmEx 15-digit)
  if (fieldKey === 'cardNumber') {
    if (!isValidCardNumber(trimmed)) {
      return { status: 'error', message: 'Enter a valid Visa, Mastercard, or American Express card number' }
    }
    return { status: 'success', message: '' }
  }

  // Expiry date validation (MM/YY)
  if (fieldKey === 'expiry') {
    if (!EXPIRY_DATE_REGEX.test(trimmed)) {
      return { status: 'error', message: 'Enter expiry as MM/YY (e.g., 12/28)' }
    }

    if (!isValidExpiryDate(trimmed)) {
      return { status: 'error', message: 'Card has expired' }
    }

    return { status: 'success', message: '' }
  }

  // E-wallet walletId validation (phone number OR email format)
  if (fieldKey === 'walletId') {
    const isPhone = PHONE_REGEX.test(trimmed)
    const isEmail = isValidEmail(trimmed)

    if (!isPhone && !isEmail) {
      return { status: 'error', message: 'Enter a valid phone number or email address' }
    }
    return { status: 'success', message: '' }
  }

  // PIN validation (6 digits)
  if (fieldKey === 'pin') {
    if (!isValidPIN(trimmed)) {
      return { status: 'error', message: 'PIN must be exactly 6 digits' }
    }
    return { status: 'success', message: '' }
  }

  // OTP validation (6 digits)
  if (fieldKey === 'otp' || fieldConfig.type === 'otp') {
    if (!isValidPIN(trimmed)) {
      return { status: 'error', message: 'OTP must be exactly 6 digits' }
    }
    return { status: 'success', message: '' }
  }

  // CVV/CID validation
  if (fieldKey === 'cvv') {
    const expectedLength = fieldConfig.length || 3
    if (!/^\d+$/.test(trimmed) || trimmed.length !== expectedLength) {
      return { status: 'error', message: `CVV must be ${expectedLength} digits` }
    }
    return { status: 'success', message: '' }
  }

  // General length validation
  if (fieldConfig.length && trimmed.length !== fieldConfig.length) {
    return { status: 'error', message: `Must be exactly ${fieldConfig.length} characters` }
  }

  // Default valid for other fields
  return { status: 'success', message: '' }
}

function validatePaymentForm() {
  const method = selectedPaymentMethod.value
  if (!method) return

  const fields = Array.isArray(method.fields) ? method.fields : []
  fields.forEach((field) => {
    const value = paymentForm[field.key]
    paymentFormValidation[field.key] = validatePaymentField(field.key, field, value)
  })
}

function handlePaymentFieldBlur(fieldKey, fieldConfig) {
  const value = paymentForm[fieldKey]
  paymentFormValidation[fieldKey] = validatePaymentField(fieldKey, fieldConfig, value)
}

function handlePaymentFieldInput(fieldKey, fieldConfig) {
  const value = paymentForm[fieldKey]

  // FPX Account holder name auto-uppercase
  if (fieldKey === 'accountName') {
    paymentForm[fieldKey] = String(value).toUpperCase()
  }

  // Cardholder name auto-uppercase
  if (fieldKey === 'cardHolder') {
    paymentForm[fieldKey] = String(value).toUpperCase()
  }

  // Real-time validation for certain fields
  if (fieldKey === 'pin' || fieldKey === 'otp' || fieldKey === 'cvv') {
    // Only allow digits
    paymentForm[fieldKey] = String(value).replace(/\D/g, '')

    // Limit length
    if (fieldConfig.length && paymentForm[fieldKey].length > fieldConfig.length) {
      paymentForm[fieldKey] = paymentForm[fieldKey].slice(0, fieldConfig.length)
    }
  }

  // Card number formatting (allow digits, spaces, and dashes)
  if (fieldKey === 'cardNumber') {
    paymentForm[fieldKey] = String(value).replace(/[^0-9\s-]/g, '')
  }

  // Expiry formatting (auto-add slash)
  if (fieldKey === 'expiry') {
    let cleaned = String(value).replace(/\D/g, '')
    if (cleaned.length >= 2) {
      cleaned = cleaned.slice(0, 2) + '/' + cleaned.slice(2, 4)
    }
    paymentForm[fieldKey] = cleaned.slice(0, 5) // MM/YY = 5 chars max
  }

  // Clear error on input if previously had error
  if (paymentFormValidation[fieldKey]?.status === 'error') {
    paymentFormValidation[fieldKey] = { status: undefined, message: '' }
  }
}

const methodFieldValidity = computed(() => {
  const method = selectedPaymentMethod.value
  if (!method) {
    return false
  }
  const fields = Array.isArray(method.fields) ? method.fields : []

  // Check all required fields are filled
  const allFilled = fields.every((field) => {
    if (!field.required) {
      return true
    }
    const value = paymentForm[field.key]
    return value !== undefined && value !== null && String(value).trim() !== ''
  })

  if (!allFilled) return false

  // Check all fields pass validation
  return fields.every((field) => {
    const validation = paymentFormValidation[field.key]
    if (!validation) {
      // Validate on-demand if not yet validated
      const value = paymentForm[field.key]
      const result = validatePaymentField(field.key, field, value)
      return result.status === 'success'
    }
    return validation.status === 'success' || !validation.status
  })
})
const canStartPaymentSession = computed(
  () => hasPackage.value && Boolean(selectedPaymentMethod.value) && methodFieldValidity.value,
)
const paymentStatusLabel = computed(() => formatPaymentStatus(paymentSession.value?.status))
const paymentStatusTagType = computed(() => {
  const tone = paymentStatusTone(paymentSession.value?.status)
  if (tone === 'success') return 'success'
  if (tone === 'danger') return 'error'
  if (tone === 'info') return 'info'
  return 'default'
})
const paymentTimeline = computed(() => paymentEvents.value ?? [])
const sessionBookingRef = computed(() => paymentSession.value?.bookingRef || '')
const paymentStepTitle = computed(() => {
  switch (paymentStep.value) {
    case 'authorize':
      return 'Authorize Payment'
    case 'result':
      return 'Payment Outcome'
    default:
      return 'Choose Payment Method'
  }
})

watch(
  () => props.packageData?.packageId,
  () => {
    paymentSession.value = null
    paymentEvents.value = []
    paymentReceipt.value = null
    paymentSimulatorVisible.value = false
    selectedPaymentCategory.value = ''
    setSelectedPaymentMethod(null)
  },
)

watch(
  hasPackage,
  (value) => {
    if (!value) {
      resetPaymentFlow()
      paymentMethods.value = []
      paymentSimulatorVisible.value = false
      selectedPaymentCategory.value = ''
      setSelectedPaymentMethod(null)
      return
    }
    void initialisePaymentSimulator()
  },
  { immediate: true },
)

watch(
  paymentCategoryList,
  (categories) => {
    if (!categories.length) {
      selectedPaymentCategory.value = ''
      setSelectedPaymentMethod(null)
      return
    }
    const exists = categories.some((category) => category.key === selectedPaymentCategory.value)
    if (!exists) {
      selectedPaymentCategory.value = ''
      setSelectedPaymentMethod(null)
    }
  },
  { immediate: true },
)

watch(
  visiblePaymentMethods,
  (methods) => {
    if (!methods.some((method) => method.code === selectedPaymentMethodCode.value)) {
      setSelectedPaymentMethod(null)
    }
  },
  { immediate: true },
)

watch(
  selectedPaymentMethodCode,
  () => {
    // Clear validation when payment method changes
    Object.keys(paymentFormValidation).forEach((key) => delete paymentFormValidation[key])
  },
)

async function initialisePaymentSimulator() {
  if (!hasPackage.value) {
    return
  }
  if (!paymentMethodsLoaded.value && !paymentMethodsLoading.value) {
    await loadPaymentMethods()
  }
  prepareFormFields()
}

function resetPaymentFlow() {
  paymentStep.value = 'method'
  paymentSubmitting.value = false
  paymentMethodError.value = ''
  Object.keys(paymentForm).forEach((key) => delete paymentForm[key])
}

async function loadPaymentMethods() {
  paymentMethodsLoading.value = true
  paymentMethodError.value = ''
  try {
    const { methods } = await fetchPaymentMethods()
    paymentMethods.value = Array.isArray(methods) ? methods : []
    paymentMethodsLoaded.value = true
  } catch (error) {
    const messageText = error?.message || 'Unable to load payment methods.'
    paymentMethodError.value = messageText
    message.error(messageText)
  } finally {
    paymentMethodsLoading.value = false
    prepareFormFields()
  }
}

function setSelectedPaymentMethod(code) {
  selectedPaymentMethodCode.value = code || null
  if (paymentStep.value === 'method') {
    prepareFormFields()
  }
}

function selectPaymentCategory(categoryKey) {
  if (selectedPaymentCategory.value === categoryKey) {
    return
  }
  selectedPaymentCategory.value = categoryKey
  setSelectedPaymentMethod(null)
}

function selectPaymentMethod(code) {
  if (paymentStep.value !== 'method') {
    return
  }
  const method = paymentMethods.value.find((entry) => entry.code === code)
  if (!method) {
    return
  }
  if (method.category) {
    selectedPaymentCategory.value = method.category
  }
  setSelectedPaymentMethod(code)
}

function prepareFormFields() {
  const method = selectedPaymentMethod.value
  Object.keys(paymentForm).forEach((key) => delete paymentForm[key])
  if (!method?.fields?.length) {
    return
  }
  method.fields.forEach((field) => {
    if (field?.key) {
      paymentForm[field.key] = ''
    }
  })
}

function collectFieldPayload() {
  const method = selectedPaymentMethod.value
  const payload = {}
  if (!method) {
    return payload
  }
  method.fields?.forEach((field) => {
    if (!field?.key) {
      return
    }
    const value = paymentForm[field.key]
    if (value == null) {
      return
    }
    const trimmed = typeof value === 'string' ? value.trim() : value
    if (trimmed === '') {
      return
    }
    payload[field.key] = trimmed
  })
  return payload
}

async function startPaymentSimulation() {
  if (!canStartPaymentSession.value) {
    // Validate and show specific errors
    validatePaymentForm()
    const hasErrors = Object.values(paymentFormValidation).some(v => v.status === 'error')
    if (hasErrors) {
      message.error('Please fix the validation errors before proceeding.')
    } else {
      message.warning('Fill in all required billing details to continue.')
    }
    return
  }
  if (!travelerId.value || !packageId.value) {
    message.error('Booking package information is incomplete.')
    return
  }
  paymentSubmitting.value = true
  try {
    const method = selectedPaymentMethod.value
    const fieldPayload = collectFieldPayload()
    const response = await startPaymentSession({
      travelerId: travelerId.value,
      packageId: packageId.value,
      amount: payableAmount.value,
      currency: payableCurrency.value,
      methodCode: method.code,
      customer: derivePayerProfile(fieldPayload),
      fields: fieldPayload,
      notes: summary.value?.curationNote || summary.value?.description || heroDescription.value,
      clientContext: deriveClientContext(),
    })
    paymentSession.value = response.session
    paymentEvents.value = response.events ?? []
    paymentReceipt.value = response.receipt ?? null
    paymentStep.value = 'authorize'
    message.success('Session ready. Continue with authorization.')
  } catch (error) {
    message.error(error?.message || 'Payment could not be initiated.')
  } finally {
    paymentSubmitting.value = false
  }
}

async function completeAuthorization(outcome = 'success') {
  if (!paymentSession.value?.sessionId) {
    message.error('An active payment session is required.')
    return
  }
  paymentSubmitting.value = true
  try {
    const fieldPayload = collectFieldPayload()
    const response = await authorizePaymentSession({
      sessionId: paymentSession.value.sessionId,
      outcome,
      remarks:
        outcome === 'success'
          ? ''
          : paymentForm.failureReason || 'Authentication declined during verification.',
      device: 'traveler-payment-dashboard',
      proofCode: paymentForm.otpProof || '',
      payer: derivePayerProfile(fieldPayload),
      fields: fieldPayload,
    })
    paymentSession.value = response.session
    paymentEvents.value = response.events ?? []
    paymentReceipt.value = response.receipt ?? null
    paymentStep.value = 'result'
    if (response.session?.status === 'authorized') {
      message.success('Payment authorized. A receipt is ready.')
      emit('payment-authorized', {
        session: response.session,
        receipt: response.receipt,
        package: props.packageData,
      })
      notifySavedPlacesRefresh()
      notifyBookingHistoryRefresh()
    } else if (response.session?.status === 'failed') {
      message.warning(response.session?.failureReason || 'Payment marked as failed.')
      exitPaymentFlow()
    }
  } catch (error) {
    message.error(error?.message || 'Authorization process failed.')
  } finally {
    paymentSubmitting.value = false
  }
}

async function retryAuthorization() {
  if (!paymentSession.value?.sessionId) {
    return
  }
  paymentSubmitting.value = true
  try {
    const response = await retryPaymentSession(paymentSession.value.sessionId)
    paymentSession.value = response.session
    paymentEvents.value = response.events ?? []
    paymentReceipt.value = response.receipt ?? null
    paymentStep.value = 'authorize'
    message.info('Retry initiated - please authorize again.')
  } catch (error) {
    message.error(error?.message || 'Retry could not be triggered.')
  } finally {
    paymentSubmitting.value = false
  }
}

function exitPaymentFlow() {
  paymentSession.value = null
  paymentEvents.value = []
  paymentReceipt.value = null
  resetPaymentFlow()
  selectedPaymentCategory.value = ''
  setSelectedPaymentMethod(null)
  paymentSimulatorVisible.value = false
}

function goBackToBooking() {
  emit('back-to-booking')
}

function openPaymentSimulator() {
  if (!hasPackage.value || payableAmount.value <= 0) {
    return
  }
  if (!paymentSimulatorVisible.value) {
    paymentSimulatorVisible.value = true
    nextTick(() => scrollToSimulator())
    return
  }
  scrollToSimulator()
}

function scrollToSimulator() {
  simulatorSectionRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function notifySavedPlacesRefresh() {
  if (!travelerId.value) {
    return
  }
  window.dispatchEvent(
    new CustomEvent('traveler-saved-places-refresh', {
      detail: { travelerId: Number(travelerId.value) },
    }),
  )
}

function notifyBookingHistoryRefresh() {
  if (!travelerId.value) {
    return
  }
  window.dispatchEvent(
    new CustomEvent('traveler-booking-history-refresh', {
      detail: { travelerId: Number(travelerId.value) },
    }),
  )
}

function deriveClientContext() {
  if (typeof navigator === 'undefined') {
    return {}
  }
  return {
    userAgent: navigator.userAgent,
    language: navigator.language,
    platform: navigator.platform,
    time: new Date().toISOString(),
  }
}

function derivePayerProfile(fields = {}) {
  const name =
    fields.accountName ||
    fields.cardHolder ||
    summary.value?.leadTraveler ||
    props.packageData?.travelerName ||
    props.packageData?.title ||
    'Traveler'
  return {
    name,
    email: summary.value?.contactEmail || props.packageData?.contactEmail || '',
    phone: summary.value?.contactPhone || props.packageData?.contactPhone || '',
  }
}

function formatPaymentStatus(status) {
  if (!status) {
    return ''
  }
  return PAYMENT_STATUS_LABELS[status] || toTitleCase(status)
}

function paymentStatusTone(status) {
  if (!status) {
    return 'muted'
  }
  return PAYMENT_STATUS_ACCENTS[status] || 'muted'
}

function formatHistoryDate(value) {
  if (!value) {
    return ''
  }
  try {
    return PAYMENT_HISTORY_DATE_FORMATTER.format(new Date(value))
  } catch {
    return value
  }
}

function formatPaymentEvent(type) {
  if (!type) {
    return ''
  }
  return PAYMENT_EVENT_LABELS[type] || toTitleCase(type.replace(/_/g, ' '))
}

function describeEventPayload(payload) {
  if (!payload) {
    return ''
  }
  if (payload.receiptNo) {
    return `Receipt ${payload.receiptNo}`
  }
  if (payload.bookingRef) {
    return payload.bookingRef
  }
  if (payload.displayName) {
    return payload.displayName
  }
  if (payload.remarks) {
    return payload.remarks
  }
  if (payload.amount && payload.currency) {
    return formatCurrencyAmount(payload.amount, payload.currency)
  }
  return ''
}

function paymentCategoryLabel(category) {
  if (!category) {
    return ''
  }
  return PAYMENT_CATEGORY_LABELS[category] || toTitleCase(category)
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
const EXCLUDED_BILLING_THEMES = new Set(['food', 'foods', 'culinary', 'dining', 'eat', 'eating'])
const PAYMENT_STATUS_LABELS = {
  authorized: 'Authorized',
  awaiting_authorization: 'Awaiting Authorization',
  initiated: 'Initiated',
  failed: 'Failed',
  expired: 'Expired',
  refunded: 'Refunded',
}
const PAYMENT_STATUS_ACCENTS = {
  authorized: 'success',
  awaiting_authorization: 'info',
  initiated: 'info',
  failed: 'danger',
  expired: 'muted',
  refunded: 'muted',
}
const PAYMENT_CATEGORY_LABELS = {
  fpx: 'FPX Online Banking',
  card: 'Card',
  ewallet: 'E-Wallet',
}
const PAYMENT_CATEGORY_ORDER = ['fpx', 'card', 'ewallet']
const PAYMENT_HISTORY_DATE_FORMATTER = new Intl.DateTimeFormat(undefined, {
  dateStyle: 'medium',
  timeStyle: 'short',
})
const PAYMENT_EVENT_LABELS = {
  created: 'Session created',
  method_selected: 'Method selected',
  authorization_started: 'Authorization started',
  authorization_completed: 'Authorization completed',
  authorization_failed: 'Authorization failed',
  receipt_generated: 'Receipt generated',
  retry_requested: 'Retry requested',
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

function normaliseThemeKey(theme) {
  if (theme == null) {
    return ''
  }
  return theme
    .toString()
    .toLowerCase()
    .replace(/&/g, 'and')
    .replace(/[^a-z]/g, '')
}

function isExcludedBillingTheme(theme) {
  const cleaned = typeof theme === 'string' ? normaliseThemeKey(theme) : ''
  if (!cleaned) {
    return false
  }
  return EXCLUDED_BILLING_THEMES.has(cleaned)
}

function canonicalTheme(theme) {
  const cleaned = normaliseThemeKey(theme)
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
  <div class="payment-dashboard">
    <div v-if="hasPackage" class="payment-dashboard__shell">
      <section class="payment-dashboard__hero-card">
        <div class="payment-dashboard__hero-text">
          <p class="payment-dashboard__eyebrow">{{ heroTagline }}</p>
          <h1>{{ heroTitle }}</h1>
          <p class="payment-dashboard__date">{{ summary.dateRange || 'Awaiting Travel Dates' }}</p>
          <p class="payment-dashboard__description">{{ heroDescription }}</p>
          <div v-if="heroTags.length" class="payment-dashboard__hero-tags">
            <span v-for="tag in heroTags" :key="tag" class="payment-dashboard__tag">{{ tag }}</span>
          </div>
        </div>
        <div class="payment-dashboard__hero-stats">
          <div class="payment-dashboard__hero-stat">
            <p>Total Package</p>
            <strong>{{ totalLabel }}</strong>
          </div>
          <div class="payment-dashboard__hero-stat">
            <p>Destination</p>
            <strong>{{ destinationLabel }}</strong>
          </div>
          <div class="payment-dashboard__hero-stat">
            <p>Selections</p>
            <strong>{{ totalSelections }}</strong>
          </div>
        </div>
        <div class="payment-dashboard__hero-actions">
          <n-button size="large" round type="primary"
            class="payment-dashboard__hero-button payment-dashboard__hero-button--primary"
            :disabled="!hasPackage || payableAmount <= 0" @click="openPaymentSimulator">
            Go To Checkout
          </n-button>
          <n-button size="large" round quaternary
            class="payment-dashboard__hero-button payment-dashboard__hero-button--ghost" @click="goBackToBooking">
            Back To Booking Dashboard
          </n-button>
        </div>
      </section>

      <div class="payment-dashboard__grid">
        <article class="payment-dashboard__panel payment-dashboard__panel--summary">
          <div class="payment-dashboard__panel-header">
            <div>
              <h3>Checkout Summary</h3>
              <p>FPX, card & e-wallet channels.</p>
            </div>
            <n-tag round :type="paymentStatusTagType">
              {{ paymentStatusLabel || 'Not Started' }}
            </n-tag>
          </div>
          <div class="booking-payment__summary">
            <div>
              <small>Amount Due</small>
              <strong>{{ totalLabel }}</strong>
              <p v-if="payableAmount <= 0" class="booking-payment__note">
                Add priced selections before proceeding to payment.
              </p>
              <p v-else class="booking-payment__note">Instant confirmation once payment clears.</p>
            </div>
            <n-button type="primary" size="large" round block :disabled="payableAmount <= 0"
              @click="openPaymentSimulator">
              Proceed To Payment
            </n-button>
          </div>
          <div v-if="billingBreakdown.length" class="billing-summary__breakdown">
            <div v-for="entry in billingBreakdown" :key="entry.theme" class="billing-summary__row">
              <span>{{ entry.label }}</span>
              <strong>{{ entry.display }}</strong>
            </div>
          </div>
          <div v-if="billingLineItems.length" class="billing-summary__items">
            <p>Line items</p>
            <ul>
              <li v-for="item in billingLineItems" :key="item.id">
                <div>
                  <strong>{{ item.title }}</strong>
                  <small v-if="item.subtitle">{{ item.subtitle }}</small>
                </div>
                <span>{{ item.display }}</span>
              </li>
            </ul>
          </div>
        </article>

        <article class="payment-dashboard__panel">
          <div class="payment-dashboard__panel-header">
            <div>
              <h3>Itinerary Snapshot</h3>
              <p>Luxe cues to set the mood.</p>
            </div>
          </div>
          <ul class="payment-dashboard__facts">
            <li v-for="fact in journeyFacts" :key="fact.label">
              <span>{{ fact.label }}</span>
              <strong>{{ fact.value }}</strong>
            </li>
          </ul>
        </article>
      </div>
    </div>

    <div v-else class="payment-dashboard__empty">
      <n-empty description="Select A Package">
        <template #default>
          <p>Open any saved escape and choose "Go to checkout" to manage payments here.</p>
        </template>
        <template #extra>
          <n-button type="primary" size="large" round @click="goBackToBooking">Back To Booking Dashboard</n-button>
        </template>
      </n-empty>
    </div>

    <section v-if="hasPackage && paymentSimulatorVisible" ref="simulatorSectionRef" class="payment-simulator">
      <header class="payment-modal__header">
        <div>
          <p class="payment-modal__eyebrow">Checkout</p>
          <h3>{{ heroTitle }}</h3>
        </div>
        <n-tag round type="info">{{ paymentStepTitle }}</n-tag>
      </header>

      <div class="payment-modal__layout">
        <aside class="payment-modal__summary">
          <div class="payment-modal__summary-card">
            <p class="payment-modal__summary-label">Package</p>
            <h4>{{ heroTitle }}</h4>
            <p class="payment-modal__summary-destination">{{ destinationLabel }}</p>
            <p class="payment-modal__summary-date">
              {{ summary.dateRange || 'Awaiting travel dates' }}
            </p>
            <div class="payment-modal__amount">
              <span>Amount due</span>
              <strong>{{ totalLabel }}</strong>
            </div>
          </div>
          <ul class="payment-modal__list">
            <li v-for="fact in journeyFacts" :key="fact.label">
              <span>{{ fact.label }}</span>
              <strong>{{ fact.value }}</strong>
            </li>
          </ul>
          <p class="payment-modal__disclaimer">Secure checkout - encrypted processing.</p>
        </aside>

        <section class="payment-modal__stage">
          <div v-if="paymentStep === 'method'" class="payment-modal__step">
            <p class="payment-modal__subtitle">Choose your preferred payment channel.</p>
            <div v-if="paymentMethodsLoading" class="payment-modal__skeleton">Loading methods...</div>
            <template v-else>
              <div v-if="paymentCategoryList.length" class="payment-methods__categories">
                <button v-for="category in paymentCategoryList" :key="category.key" type="button"
                  class="payment-category-chip" :class="{ 'is-active': category.key === selectedPaymentCategory }"
                  @click="selectPaymentCategory(category.key)">
                  <strong>{{ category.label }}</strong>
                  <span>{{ category.methods.length }} options</span>
                </button>
              </div>
              <div v-if="!selectedPaymentCategory" class="payment-methods__prompt">
                <p>Please choose your payment method.</p>
              </div>
              <div v-else-if="visiblePaymentMethods.length" class="payment-methods__grid">
                <button v-for="method in visiblePaymentMethods" :key="method.methodId" class="payment-method-card"
                  :class="{
                    'payment-method-card--active': method.code === selectedPaymentMethodCode,
                    'payment-method-card--disabled': paymentStep !== 'method',
                  }" type="button" @click="selectPaymentMethod(method.code)">
                  <span class="payment-method-card__category">
                    {{ paymentCategoryLabel(method.category) }}
                  </span>
                  <strong>{{ method.displayName }}</strong>
                  <p>{{ method.tagline }}</p>
                  <small>{{ method.processingTime }} | {{ method.feeLabel }}</small>
                </button>
              </div>
              <div v-else class="payment-methods__empty">
                <p>No payment channels available for this category.</p>
              </div>
            </template>
            <div v-if="selectedPaymentMethod" class="payment-form">
              <h4>{{ selectedPaymentMethod.displayName }} details</h4>
              <div v-for="field in selectedPaymentMethod.fields" :key="field.key" class="payment-form__field">
                <label :for="`payment-field-${field.key}`">
                  {{ field.label }}
                  <span v-if="field.required">*</span>
                </label>
                <input :id="`payment-field-${field.key}`" :type="field.type === 'password' ? 'password' : 'text'"
                  v-model="paymentForm[field.key]" :placeholder="field.placeholder" :maxlength="field.length || null"
                  :class="{
                    'input-error': paymentFormValidation[field.key]?.status === 'error',
                    'input-success': paymentFormValidation[field.key]?.status === 'success'
                  }" @blur="handlePaymentFieldBlur(field.key, field)"
                  @input="handlePaymentFieldInput(field.key, field)" />
                <div v-if="paymentFormValidation[field.key]?.message" class="payment-form__feedback" :class="{
                  'payment-form__feedback--error': paymentFormValidation[field.key]?.status === 'error',
                  'payment-form__feedback--success': paymentFormValidation[field.key]?.status === 'success'
                }">
                  {{ paymentFormValidation[field.key]?.message }}
                </div>
              </div>
              <div class="payment-modal__actions">
                <n-button type="primary" size="large" round :disabled="!canStartPaymentSession"
                  :loading="paymentSubmitting" @click="startPaymentSimulation">
                  Initiate Payment Session
                </n-button>
              </div>
            </div>
          </div>

          <div v-else-if="paymentStep === 'authorize'" class="payment-authorize">
            <div class="payment-authorize__badge">
              <span>Booking reference</span>
              <strong>{{ sessionBookingRef }}</strong>
            </div>
            <p class="payment-modal__subtitle">
              Complete FPX / card authorization or decline the attempt.
            </p>
            <div class="payment-modal__actions payment-modal__actions--split">
              <n-button type="primary" size="large" round :loading="paymentSubmitting"
                @click="completeAuthorization('success')">
                Approve Payment
              </n-button>
              <n-button tertiary size="large" round :loading="paymentSubmitting"
                @click="completeAuthorization('failed')">
                Cancel Payment
              </n-button>
            </div>
            <div v-if="paymentTimeline.length" class="payment-timeline">
              <div v-for="event in paymentTimeline" :key="event.eventId" class="payment-timeline__row">
                <div>
                  <strong>{{ formatPaymentEvent(event.type) }}</strong>
                  <span>{{ formatHistoryDate(event.createdAt) }}</span>
                </div>
                <p v-if="describeEventPayload(event.payload)">{{ describeEventPayload(event.payload) }}</p>
              </div>
            </div>
          </div>

          <div v-else class="payment-result" :class="`is-${paymentStatusTone(paymentSession?.status)}`">
            <h3>{{ paymentStatusLabel || 'Payment Complete' }}</h3>
            <p v-if="paymentSession?.failureReason">{{ paymentSession.failureReason }}</p>
            <p v-else>Transaction details have been recorded for your archives.</p>
            <div v-if="paymentReceipt" class="payment-receipt">
              <div>
                <span>Receipt #</span>
                <strong>{{ paymentReceipt.receiptNo }}</strong>
              </div>
              <div>
                <span>Paid at</span>
                <strong>{{ formatHistoryDate(paymentReceipt.paidAt) }}</strong>
              </div>
              <div>
                <span>Channel</span>
                <strong>{{ paymentReceipt.paymentChannel }}</strong>
              </div>
            </div>
            <div class="payment-modal__actions payment-modal__actions--split">
              <n-button v-if="paymentSession?.status === 'failed'" tertiary round size="large"
                @click="retryAuthorization">
                Retry Authorization
              </n-button>
            </div>
          </div>
        </section>
      </div>
    </section>

  </div>
</template>

<style scoped>
.payment-dashboard {
  position: relative;
  width: 100%;
  padding: 32px;
  background: radial-gradient(circle at top, rgba(34, 197, 94, 0.08), rgba(59, 130, 246, 0.06)) #f8fafc;
  color: #0f172a;
  min-height: 100%;
}

.payment-dashboard__shell {
  display: flex;
  flex-direction: column;
  gap: 32px;
}

.payment-dashboard__hero-card {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 24px;
  padding: 32px;
  border-radius: 28px;
  background: rgba(255, 255, 255, 0.9);
  box-shadow: 0 32px 80px rgba(15, 23, 42, 0.12);
  border: 1px solid rgba(15, 23, 42, 0.08);
}

.payment-dashboard__hero-text h1 {
  font-size: 2.4rem;
  margin: 0;
}

.payment-dashboard__hero-text p {
  margin: 0;
}

.payment-dashboard__eyebrow {
  text-transform: uppercase;
  font-size: 0.8rem;
  letter-spacing: 0.3em;
  color: rgba(15, 23, 42, 0.55);
}

.payment-dashboard__date {
  font-weight: 600;
  margin-top: 8px;
}

.payment-dashboard__description {
  margin-top: 6px;
  color: rgba(15, 23, 42, 0.65);
}

.payment-dashboard__hero-tags {
  margin-top: 10px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.payment-dashboard__tag {
  border-radius: 999px;
  padding: 6px 14px;
  background: rgba(15, 23, 42, 0.08);
  font-size: 0.8rem;
}

.payment-dashboard__hero-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 14px;
}

.payment-dashboard__hero-stat {
  border-radius: 20px;
  padding: 14px;
  background: rgba(248, 250, 252, 0.8);
  border: 1px solid rgba(15, 23, 42, 0.08);
}

.payment-dashboard__hero-stat p {
  margin: 0;
  color: rgba(15, 23, 42, 0.5);
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.15em;
}

.payment-dashboard__hero-stat strong {
  display: block;
  margin-top: 6px;
  font-size: 1.4rem;
}

.payment-dashboard__hero-actions {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
  align-items: center;
}

.payment-dashboard__hero-button {
  width: 100%;
}

.payment-dashboard__hero-button--primary {
  box-shadow: 0 18px 30px rgba(16, 185, 129, 0.25);
}

.payment-dashboard__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 24px;
}

.payment-dashboard__panel {
  border-radius: 24px;
  padding: 24px;
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid rgba(15, 23, 42, 0.08);
  box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
}

.payment-dashboard__panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
}

.payment-dashboard__panel-header h3 {
  margin: 0;
}

.payment-dashboard__facts {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.payment-dashboard__facts li {
  border-bottom: 1px solid rgba(15, 23, 42, 0.08);
  padding-bottom: 8px;
}

.payment-dashboard__facts li:last-child {
  border-bottom: none;
}

.payment-dashboard__facts span {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.15em;
  color: rgba(15, 23, 42, 0.55);
}

.payment-dashboard__facts strong {
  display: block;
  margin-top: 4px;
}

.booking-payment__summary {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-bottom: 18px;
}

.booking-payment__summary strong {
  display: block;
  font-size: 2rem;
  margin-top: 4px;
}

.booking-payment__note {
  margin: 4px 0 0;
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.6);
}

.booking-payment__history {
  border-top: 1px solid rgba(15, 23, 42, 0.08);
  padding-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.booking-payment__history-label {
  margin: 0;
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.12em;
  color: rgba(15, 23, 42, 0.55);
}

.booking-payment__history ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.booking-payment__history li {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 0.9rem;
}

.booking-payment__history-status {
  text-align: right;
}

.booking-payment__history-status span {
  display: block;
  font-weight: 600;
}

.booking-payment__history-status small {
  color: rgba(15, 23, 42, 0.5);
}

.booking-payment__history-status.is-success span {
  color: #059669;
}

.booking-payment__history-status.is-danger span {
  color: #dc2626;
}

.booking-payment__history-status.is-info span {
  color: #2563eb;
}

.booking-payment__history-status.is-muted span {
  color: rgba(15, 23, 42, 0.5);
}

.booking-payment__history--empty {
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.6);
}

.billing-summary__breakdown {
  margin-top: 16px;
  padding-top: 12px;
  border-top: 1px solid rgba(15, 23, 42, 0.08);
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.billing-summary__row {
  display: flex;
  justify-content: space-between;
  font-size: 0.92rem;
}

.billing-summary__row span {
  color: rgba(15, 23, 42, 0.6);
}

.billing-summary__row strong {
  font-weight: 600;
}

.billing-summary__items {
  margin-top: 18px;
}

.billing-summary__items p {
  margin: 0 0 8px;
  font-weight: 600;
}

.billing-summary__items ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.billing-summary__items li {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 0.9rem;
}

.billing-summary__items li strong {
  display: block;
}

.billing-summary__items li small {
  color: rgba(15, 23, 42, 0.55);
}

.billing-summary__items li span {
  font-weight: 600;
}

.payment-simulator {
  margin-top: 12px;
  padding-bottom: 40px;
}

.payment-modal__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
}

.payment-modal__eyebrow {
  margin: 0;
  color: rgba(15, 23, 42, 0.5);
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 0.18em;
}

.payment-modal__layout {
  display: flex;
  gap: 24px;
  flex-wrap: wrap;
}

.payment-modal__summary {
  flex: 0 0 32%;
  min-width: 240px;
  border-radius: 24px;
  padding: 18px;
  background: rgba(248, 250, 252, 0.95);
  display: flex;
  flex-direction: column;
  gap: 18px;
  border: 1px solid rgba(15, 23, 42, 0.08);
}

.payment-modal__summary-card {
  border-radius: 20px;
  padding: 18px;
  background: linear-gradient(135deg, rgba(59, 130, 246, 0.12), rgba(16, 185, 129, 0.12));
  border: 1px solid rgba(15, 23, 42, 0.05);
}

.payment-modal__summary-label {
  margin: 0 0 4px;
  font-size: 0.8rem;
  color: rgba(15, 23, 42, 0.6);
}

.payment-modal__summary-destination {
  margin: 2px 0;
  font-weight: 600;
}

.payment-modal__summary-date {
  margin: 0;
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.65);
}

.payment-modal__amount {
  margin-top: 12px;
}

.payment-modal__amount span {
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.6);
  display: block;
}

.payment-modal__amount strong {
  font-size: 1.6rem;
}

.payment-modal__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.payment-modal__history-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  font-size: 0.85rem;
}

.payment-modal__history-row strong {
  display: block;
}

.payment-modal__disclaimer {
  margin: 0;
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.55);
  text-transform: uppercase;
  letter-spacing: 0.2em;
}

.payment-modal__stage {
  flex: 1;
  border-radius: 24px;
  padding: 20px;
  background: rgba(255, 255, 255, 0.95);
  border: 1px solid rgba(15, 23, 42, 0.08);
  min-width: 320px;
}

.payment-modal__subtitle {
  margin-top: 0;
  color: rgba(15, 23, 42, 0.65);
}

.payment-modal__skeleton {
  padding: 16px;
  border-radius: 16px;
  background: rgba(15, 23, 42, 0.04);
  color: rgba(15, 23, 42, 0.5);
  font-size: 0.9rem;
}

.payment-methods__categories {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 18px;
}

.payment-methods__prompt {
  border: 1px dashed rgba(15, 23, 42, 0.25);
  border-radius: 18px;
  padding: 28px;
  text-align: center;
  color: rgba(15, 23, 42, 0.7);
  font-size: 0.95rem;
}

.payment-category-chip {
  border: 1px solid rgba(15, 23, 42, 0.12);
  border-radius: 999px;
  padding: 10px 16px;
  background: rgba(255, 255, 255, 0.7);
  cursor: pointer;
  display: flex;
  flex-direction: column;
  min-width: 150px;
  transition: border-color 0.2s, background 0.2s, color 0.2s;
}

.payment-category-chip strong {
  font-size: 0.85rem;
}

.payment-category-chip span {
  font-size: 0.7rem;
  color: rgba(15, 23, 42, 0.6);
}

.payment-category-chip.is-active {
  border-color: rgba(59, 130, 246, 0.6);
  background: rgba(59, 130, 246, 0.08);
}

.payment-methods__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 14px;
}

.payment-methods__empty {
  border: 1px dashed rgba(15, 23, 42, 0.2);
  border-radius: 18px;
  padding: 24px;
  text-align: center;
  color: rgba(15, 23, 42, 0.7);
}

.payment-method-card {
  border: 1px solid rgba(15, 23, 42, 0.12);
  border-radius: 18px;
  padding: 16px;
  text-align: left;
  background: rgba(248, 250, 252, 0.9);
  cursor: pointer;
  transition: transform 0.2s, border-color 0.2s;
}

.payment-method-card--active {
  border-color: rgba(16, 185, 129, 0.6);
  box-shadow: 0 12px 28px rgba(16, 185, 129, 0.15);
}

.payment-method-card--disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.payment-method-card__category {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.08);
  font-size: 0.7rem;
  margin-bottom: 8px;
}

.payment-method-card strong {
  display: block;
  font-size: 1.05rem;
}

.payment-method-card p {
  margin: 6px 0;
  color: rgba(15, 23, 42, 0.65);
}

.payment-method-card small {
  color: rgba(15, 23, 42, 0.55);
}

.payment-form {
  margin-top: 20px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.payment-form__field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.payment-form__field label {
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.75);
}

.payment-form__field input {
  border-radius: 12px;
  border: 1px solid rgba(15, 23, 42, 0.15);
  padding: 10px 12px;
  font-size: 0.95rem;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.payment-form__field input:focus {
  outline: none;
  border-color: rgba(16, 185, 129, 0.6);
}

.payment-form__field input.input-error {
  border-color: #d32f2f;
}

.payment-form__field input.input-error:focus {
  border-color: #d32f2f;
  box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.1);
}

.payment-form__field input.input-success {
  border-color: #52c41a;
}

.payment-form__field input.input-success:focus {
  border-color: #52c41a;
  box-shadow: 0 0 0 3px rgba(82, 196, 26, 0.1);
}

.payment-form__feedback {
  font-size: 0.8rem;
  margin-top: -2px;
  min-height: 18px;
}

.payment-form__feedback--error {
  color: #d32f2f;
}

.payment-form__feedback--success {
  color: #52c41a;
}

.payment-modal__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 16px;
}

.payment-modal__actions--split {
  justify-content: flex-start;
}

.payment-authorize__badge {
  padding: 12px 18px;
  border-radius: 16px;
  background: rgba(15, 23, 42, 0.05);
  border: 1px dashed rgba(15, 23, 42, 0.12);
  margin-bottom: 12px;
}

.payment-authorize__badge span {
  display: block;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: rgba(15, 23, 42, 0.55);
}

.payment-authorize__badge strong {
  font-size: 1.3rem;
}

.payment-timeline {
  margin-top: 20px;
  border-top: 1px solid rgba(15, 23, 42, 0.08);
  padding-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.payment-timeline__row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 0.9rem;
}

.payment-timeline__row strong {
  display: block;
}

.payment-timeline__row span {
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.55);
}

.payment-result {
  border-radius: 22px;
  border: 1px solid rgba(15, 23, 42, 0.08);
  padding: 20px;
  background: rgba(15, 23, 42, 0.02);
}

.payment-result.is-success {
  border-color: rgba(16, 185, 129, 0.4);
  background: rgba(16, 185, 129, 0.08);
}

.payment-result.is-danger {
  border-color: rgba(239, 68, 68, 0.4);
  background: rgba(239, 68, 68, 0.08);
}

.payment-result.is-muted {
  border-color: rgba(15, 23, 42, 0.08);
}

.payment-receipt {
  margin-top: 16px;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
}

.payment-receipt span {
  display: block;
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.55);
  text-transform: uppercase;
  letter-spacing: 0.12em;
}

.payment-receipt strong {
  display: block;
  margin-top: 4px;
}

.payment-dashboard__empty {
  padding: 80px 32px;
}

@media (max-width: 1024px) {
  .payment-dashboard {
    padding: 24px;
  }

  .payment-dashboard__hero-card {
    padding: 24px;
  }

  .payment-dashboard__grid {
    grid-template-columns: 1fr;
  }

  .payment-methods__grid {
    grid-template-columns: 1fr;
  }

  .booking-payment__history li {
    flex-direction: column;
    align-items: flex-start;
  }
}

@media (max-width: 640px) {
  .payment-dashboard {
    padding: 20px 16px;
  }

  .payment-dashboard__hero-text h1 {
    font-size: 2rem;
  }

  .payment-dashboard__hero-actions {
    grid-template-columns: 1fr;
  }

  .payment-modal__layout {
    flex-direction: column;
  }

  .payment-modal__summary {
    flex: 1 1 auto;
  }

  .payment-modal__actions {
    flex-direction: column;
  }
}
</style>
