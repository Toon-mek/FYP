const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const PAYMENT_ENDPOINT = `${API_BASE}/traveler/payment_simulation.php`

async function parseResponse(response) {
  const contentType = response.headers.get('content-type') || ''
  const expectsJson = contentType.includes('application/json')
  let bodyText = ''
  let payload = null

  try {
    bodyText = await response.text()
  } catch {
    bodyText = ''
  }

  if (expectsJson && bodyText) {
    try {
      payload = JSON.parse(bodyText)
    } catch (error) {
      const parseError = new Error(`Unexpected response format: ${bodyText.slice(0, 120)}`)
      parseError.status = response.status
      parseError.raw = bodyText
      throw parseError
    }
  } else if (bodyText) {
    try {
      payload = JSON.parse(bodyText)
    } catch {
      payload = { raw: bodyText }
    }
  }

  if (!response.ok) {
    const message = payload?.error || payload?.message || `Payment simulation failed (${response.status})`
    const error = new Error(message)
    error.status = response.status
    error.payload = payload
    throw error
  }

  return payload ?? {}
}

export async function fetchPaymentMethods() {
  const response = await fetch(`${PAYMENT_ENDPOINT}?action=methods`, { method: 'GET' })
  return parseResponse(response)
}

export async function fetchPaymentHistory(travelerId, limit = 5) {
  if (!travelerId) {
    return { sessions: [] }
  }
  const params = new URLSearchParams({ action: 'history', travelerId: String(travelerId), limit: String(limit) })
  const response = await fetch(`${PAYMENT_ENDPOINT}?${params.toString()}`, { method: 'GET' })
  return parseResponse(response)
}

export async function startPaymentSession(payload) {
  const response = await fetch(`${PAYMENT_ENDPOINT}?action=start`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  return parseResponse(response)
}

export async function authorizePaymentSession(payload) {
  const response = await fetch(`${PAYMENT_ENDPOINT}?action=authorize`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  return parseResponse(response)
}

export async function retryPaymentSession(sessionId) {
  if (!sessionId) {
    throw new Error('sessionId is required')
  }
  const response = await fetch(`${PAYMENT_ENDPOINT}?action=retry`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ sessionId }),
  })
  return parseResponse(response)
}

export async function fetchPaymentSession(sessionId) {
  if (!sessionId) {
    return { session: null }
  }
  const params = new URLSearchParams({ action: 'session', sessionId: String(sessionId) })
  const response = await fetch(`${PAYMENT_ENDPOINT}?${params.toString()}`, { method: 'GET' })
  return parseResponse(response)
}

export async function fetchConfirmedBookings(travelerId) {
  if (!travelerId) {
    return { bookings: [] }
  }
  const params = new URLSearchParams({
    action: 'bookings',
    travelerId: String(travelerId),
  })
  const response = await fetch(`${PAYMENT_ENDPOINT}?${params.toString()}`, { method: 'GET' })
  return parseResponse(response)
}
