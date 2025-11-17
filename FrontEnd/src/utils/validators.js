const NAME_REGEX = /^(?=.{1,25}$)[A-Za-z][A-Za-z\s'.-]{0,24}$/u
const EMAIL_REGEX = /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/u
const PHONE_REGEX =
  /^(?:\+?60[-\s]?1[0-9][-\s]?\d{3,4}[-\s]?\d{4}|0?1[0-9][-\s]?\d{3,4}[-\s]?\d{4}|0?3[-\s]?\d{4}[-\s]?\d{4}|0?(?:4[0-9]|5[0-9]|6[0-9]|7[0-9]|8[0-9]|9[0-9])[-\s]?\d{3}[-\s]?\d{4})$/u
const PASSWORD_REGEX = /^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d!@#$%^&*()_+\-=[\]{};':"\\|,.<>/?]{6,20}$/u
const PIN_REGEX = /^\d{6}$/u
const CARDHOLDER_NAME_REGEX = /^[A-Za-z\s.'-]+$/u
const VISA_CARD_REGEX = /^4\d{15}$/
const AMEX_CARD_REGEX = /^3[47]\d{13}$/
const MASTERCARD_REGEX = /^(5[1-5]\d{14}|2(22[1-9]|2[3-9]\d|[3-6]\d{2}|7[01]\d|720)\d{12})$/
const EXPIRY_DATE_REGEX = /^(0[1-9]|1[0-2])\/([0-9]{2})$/
const PERSONAL_EMAIL_DOMAINS = ['gmail.com', 'yahoo.com', 'hotmail.com']

export function isValidFullName(value = '') {
  return NAME_REGEX.test(value.trim())
}

export function isValidEmail(value = '') {
  return EMAIL_REGEX.test(value.trim())
}

function extractDomain(value = '') {
  const match = value.trim().toLowerCase().match(/@([\w.-]+)$/u)
  return match ? match[1] : ''
}

export function isValidTravelerEmail(value = '') {
  if (!isValidEmail(value)) return false
  const domain = extractDomain(value)
  return PERSONAL_EMAIL_DOMAINS.includes(domain)
}

export function isValidCorporateEmail(value = '') {
  if (!isValidEmail(value)) return false
  const domain = extractDomain(value)
  return domain !== '' && !PERSONAL_EMAIL_DOMAINS.includes(domain)
}

export function isValidPhone(value = '') {
  if (!value) return true
  return PHONE_REGEX.test(value.trim())
}

export function isValidPassword(value = '') {
  return PASSWORD_REGEX.test(value.trim())
}

export function passwordStrength(value = '') {
  const trimmed = value.trim()
  if (!trimmed) {
    return { score: 0, label: 'Empty' }
  }
  let score = 0
  if (trimmed.length >= 8) score += 1
  if (/[A-Z]/.test(trimmed) && /[a-z]/.test(trimmed)) score += 1
  if (/\d/.test(trimmed)) score += 1
  if (/[!@#$%^&*()_+\-=[\]{};':"\\|,.<>/?]/.test(trimmed)) score += 1
  if (trimmed.length >= 12) score += 1

  if (score >= 4) {
    return { score, label: 'Strong' }
  }
  if (score >= 2) {
    return { score, label: 'Moderate' }
  }
  return { score, label: 'Weak' }
}

export const validationPatterns = {
  name: NAME_REGEX,
  email: EMAIL_REGEX,
  phone: PHONE_REGEX,
  password: PASSWORD_REGEX,
}

export const emailDomainPolicies = {
  traveler: PERSONAL_EMAIL_DOMAINS,
}

// Legacy vee-validate style helpers kept for other components
export const emailPattern = EMAIL_REGEX
export const phonePattern = /[0-9+\-\s]{6,}/
export const urlPattern = /^https?:\/\//i

export function required(message = 'This field is required') {
  return { required: true, message, trigger: ['input', 'blur'] }
}

export function emailRule(message = 'Please enter a valid email') {
  return {
    validator: (_, value) => (emailPattern.test(String(value ?? '')) ? true : new Error(message)),
    trigger: ['blur'],
  }
}

export function minLengthRule(min, message) {
  const finalMessage = message || `Must be at least ${min} characters`
  return {
    validator: (_, value) => (String(value ?? '').length >= min ? true : new Error(finalMessage)),
    trigger: ['blur'],
  }
}

export function phoneRule(message = 'Enter a valid phone number') {
  return {
    validator: (_, value) => {
      if (!value) return true
      return phonePattern.test(String(value)) ? true : new Error(message)
    },
    trigger: ['blur'],
  }
}

export function urlRule(message = 'Use a valid URL (include http/https)') {
  return {
    validator: (_, value) => {
      if (!value) return true
      return urlPattern.test(String(value)) ? true : new Error(message)
    },
    trigger: ['blur'],
  }
}

export function isValidPIN(value = '') {
  return PIN_REGEX.test(String(value).trim())
}

export function isValidCardholderName(value = '') {
  const trimmed = value.trim()
  return trimmed.length >= 3 && CARDHOLDER_NAME_REGEX.test(trimmed)
}

export function isValidCardNumber(value = '') {
  const digitsOnly = String(value).replace(/[\s-]/g, '')
  return VISA_CARD_REGEX.test(digitsOnly) || 
         AMEX_CARD_REGEX.test(digitsOnly) || 
         MASTERCARD_REGEX.test(digitsOnly)
}

export function isValidExpiryDate(value = '') {
  const trimmed = value.trim()
  if (!EXPIRY_DATE_REGEX.test(trimmed)) {
    return false
  }
  
  // Check if not expired
  const [month, year] = trimmed.split('/')
  const expiryDate = new Date(2000 + parseInt(year), parseInt(month), 0)
  const today = new Date()
  
  return expiryDate >= today
}

export { PHONE_REGEX, PIN_REGEX, EXPIRY_DATE_REGEX }
