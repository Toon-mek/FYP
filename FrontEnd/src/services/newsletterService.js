const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const NEWSLETTER_ENDPOINT = `${API_BASE}/newsletter/subscribe.php`

async function handleResponse(response) {
  let data = null
  try {
    data = await response.json()
  } catch {
    data = null
  }
  if (!response.ok) {
    const message = data?.error || 'Unable to send newsletter email.'
    const error = new Error(message)
    error.payload = data
    throw error
  }
  return data ?? {}
}

export async function sendNewsletterEmail({ email, name }) {
  const payload = {
    email: typeof email === 'string' ? email.trim() : '',
    name: typeof name === 'string' ? name.trim() : '',
  }
  const response = await fetch(NEWSLETTER_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  return handleResponse(response)
}
