export const emailPattern = /^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$/i

export const phonePattern = /[0-9+\-\s]{6,}/

export const urlPattern = /^https?:\/\//i

export function required(message = 'This field is required') {
  return { required: true, message, trigger: ['input', 'blur'] }
}

export function emailRule(message = 'Please enter a valid email') {
  return {
    validator: (_, value) => (emailPattern.test(value) ? true : new Error(message)),
    trigger: ['blur'],
  }
}

export function minLengthRule(min, message) {
  const finalMessage = message || `Must be at least ${min} characters`
  return {
    validator: (_, value) => (String(value).length >= min ? true : new Error(finalMessage)),
    trigger: ['blur'],
  }
}

export function phoneRule(message = 'Enter a valid phone number') {
  return {
    validator: (_, value) => {
      if (!value) return true
      return phonePattern.test(value) ? true : new Error(message)
    },
    trigger: ['blur'],
  }
}

export function urlRule(message = 'Use a valid URL (include http/https)') {
  return {
    validator: (_, value) => {
      if (!value) return true
      return urlPattern.test(value) ? true : new Error(message)
    },
    trigger: ['blur'],
  }
}
