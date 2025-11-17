import { computed, reactive, ref } from 'vue'
import {
  isValidCorporateEmail,
  isValidEmail,
  isValidFullName,
  isValidPassword,
  isValidPhone,
  isValidTravelerEmail,
  passwordStrength,
} from '../utils/validators.js'

export function useAccountFormValidation(form, editingUserIdRef = ref(null), options = {}) {
  const nameField = options.nameField || 'name'
  const phoneField = options.phoneField || 'phone'
  const companyNameField = options.companyNameField || 'companyName'
  const passwordField = options.passwordField || 'password'
  const confirmPasswordField = options.confirmPasswordField || 'confirmPassword'
  const requireConfirm = options.requireConfirm ?? true
  const accountTypeResolver = options.accountTypeResolver ?? (() => form?.type ?? 'Traveler')
  const includeName = options.includeName ?? (nameField in form)
  const includePhone = options.includePhone ?? (phoneField in form)
  const includeCompanyName = options.includeCompanyName ?? (companyNameField in form)
  const includePassword = options.includePassword ?? false
  const isLoginMode = options.isLoginMode ?? false

  const fieldTouched = reactive({})
  const ensureField = (key) => {
    if (!(key in fieldTouched)) {
      fieldTouched[key] = false
    }
  }
  if (includeName) ensureField(nameField)
  if (includeCompanyName) ensureField(companyNameField)
  ensureField('email')
  if (includePhone) ensureField(phoneField)
  if (includePassword) {
    ensureField(passwordField)
    if (requireConfirm) ensureField(confirmPasswordField)
  }

  const attemptedSubmit = ref(false)

  const shouldShowValidation = (key) =>
    (key in fieldTouched ? fieldTouched[key] : false) || attemptedSubmit.value
  const editingUserId = computed(() => editingUserIdRef?.value ?? null)
  const normalizedAccountType = () => {
    const type = accountTypeResolver?.() ?? form?.type ?? 'Traveler'
    return String(type || '').trim().toLowerCase()
  }

  const nameValidation = computed(() => {
    if (!includeName) {
      return { status: null, message: '' }
    }
    const rawValue = form[nameField]
    const value = typeof rawValue === 'string' ? rawValue.trim() : rawValue ?? ''
    const show = shouldShowValidation(nameField)
    if (!value) {
      return show ? { status: 'error', message: 'Full name is required.' } : { status: null, message: '' }
    }
    if (!isValidFullName(value)) {
      return show
        ? {
            status: 'error',
            message: 'Full name must be 1-25 characters using letters, spaces, apostrophes, periods, or hyphens.',
          }
        : { status: null, message: '' }
    }
    const firstChar = value.charAt(0)
    if (firstChar !== firstChar.toUpperCase() || firstChar === firstChar.toLowerCase()) {
      return show
        ? { status: 'error', message: 'Full name must start with a capital letter.' }
        : { status: null, message: '' }
    }
    return show ? { status: 'success', message: 'Looks good.' } : { status: null, message: '' }
  })

  const companyNameValidation = computed(() => {
    if (!includeCompanyName) {
      return { status: null, message: '' }
    }
    const type = normalizedAccountType()
    if (type !== 'operator') {
      return { status: null, message: '' }
    }
    const rawValue = form[companyNameField]
    const value = typeof rawValue === 'string' ? rawValue.trim() : rawValue ?? ''
    const show = shouldShowValidation(companyNameField)
    if (!value) {
      return show ? { status: 'error', message: 'Business name is required.' } : { status: null, message: '' }
    }
    if (value.length < 1 || value.length > 100) {
      return show
        ? { status: 'error', message: 'Business name must be 1-100 characters.' }
        : { status: null, message: '' }
    }
    const firstChar = value.charAt(0)
    if (firstChar !== firstChar.toUpperCase() || firstChar === firstChar.toLowerCase()) {
      return show
        ? { status: 'error', message: 'Business name must start with a capital letter.' }
        : { status: null, message: '' }
    }
    const wordCount = value.split(/\s+/).filter(word => word.length > 0).length
    if (wordCount < 1 || wordCount > 10) {
      return show
        ? { status: 'error', message: 'Business name must contain 1-10 words.' }
        : { status: null, message: '' }
    }
    return show ? { status: 'success', message: 'Looks good.' } : { status: null, message: '' }
  })

  const emailValidation = computed(() => {
    const value = form.email?.trim() ?? ''
    const show = shouldShowValidation('email')
    if (!value) {
      return show ? { status: 'error', message: 'Email address is required.' } : { status: null, message: '' }
    }
    if (!isValidEmail(value)) {
      return show ? { status: 'error', message: 'Enter a valid email address.' } : { status: null, message: '' }
    }
    const type = normalizedAccountType()
    if (type === 'traveler' && !isValidTravelerEmail(value)) {
      return show
        ? { status: 'error', message: 'Traveler accounts must use gmail.com, yahoo.com, or hotmail.com.' }
        : { status: null, message: '' }
    }
    if (type !== 'traveler' && !isValidCorporateEmail(value)) {
      return show
        ? { status: 'error', message: 'Use a company or institutional domain (not gmail/yahoo/hotmail).' }
        : { status: null, message: '' }
    }
    return show ? { status: 'success', message: 'Email looks valid.' } : { status: null, message: '' }
  })

  const phoneValidation = computed(() => {
    if (!includePhone || normalizedAccountType() === 'admin') {
      return { status: null, message: '' }
    }
    const rawValue = form[phoneField]
    const value = typeof rawValue === 'string' ? rawValue.trim() : rawValue ?? ''
    const show = shouldShowValidation(phoneField)
    if (!value) {
      return { status: null, message: '' }
    }
    if (!isValidPhone(value)) {
      return show
        ? { status: 'error', message: 'Use a Malaysian phone format, e.g. +60 12-345 6789.' }
        : { status: null, message: '' }
    }
    return show ? { status: 'success', message: 'Valid contact number.' } : { status: null, message: '' }
  })

  const passwordValidation = computed(() => {
    if (!includePassword) {
      return { status: null, message: '' }
    }
    const rawValue = form[passwordField]
    const value = typeof rawValue === 'string' ? rawValue.trim() : rawValue ?? ''
    const show = shouldShowValidation(passwordField)
    if (!value) {
      if (!editingUserId.value && show) {
        return { status: 'error', message: 'Password is required.' }
      }
      return { status: null, message: '' }
    }
    
    // For login mode, only check if password is filled
    if (isLoginMode) {
      return show ? { status: 'success', message: 'Looks good.' } : { status: null, message: '' }
    }
    
    if (!isValidPassword(value)) {
      return show
        ? { status: 'error', message: 'Use 6-20 characters with letters and numbers.' }
        : { status: null, message: '' }
    }
    if (!show) {
      return { status: null, message: '' }
    }
    const strength = passwordStrength(value)
    if (strength.label === 'Weak') {
      return { status: 'error', message: 'Password is very weak. Add more characters, numbers, and symbols.' }
    }
    if (strength.label === 'Moderate') {
      return { status: 'warning', message: 'Password is acceptable but could be stronger.' }
    }
    return { status: 'success', message: 'Strong password.' }
  })

  const confirmValidation = computed(() => {
    if (!includePassword || !requireConfirm) {
      return { status: null, message: '' }
    }
    const passwordValue = form[passwordField]?.trim() ?? ''
    const confirmValue = form[confirmPasswordField]?.trim() ?? ''
    const requireConfirmField =
      (!editingUserId.value || passwordValue !== '') && shouldShowValidation(confirmPasswordField)
    if (!requireConfirmField) {
      return { status: null, message: '' }
    }
    if (!confirmValue) {
      return { status: 'error', message: 'Confirm your password.' }
    }
    if (passwordValue !== confirmValue) {
      return { status: 'error', message: 'Passwords do not match.' }
    }
    return { status: 'success', message: 'Passwords match.' }
  })

  function markFieldTouched(field) {
    if (field in fieldTouched) {
      fieldTouched[field] = true
    }
  }

  function resetTouchedState() {
    Object.keys(fieldTouched).forEach((key) => {
      fieldTouched[key] = false
    })
    attemptedSubmit.value = false
  }

  function validateBeforeSubmit() {
    attemptedSubmit.value = true
    const validations = [
      nameValidation.value,
      companyNameValidation.value,
      emailValidation.value,
      phoneValidation.value,
      passwordValidation.value,
      confirmValidation.value,
    ]
    return validations.every((validation) => validation.status !== 'error')
  }

  return {
    nameValidation,
    companyNameValidation,
    emailValidation,
    phoneValidation,
    passwordValidation,
    confirmValidation,
    markFieldTouched,
    resetTouchedState,
    validateBeforeSubmit,
  }
}

