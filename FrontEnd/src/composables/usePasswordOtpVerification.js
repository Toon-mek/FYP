import { computed, onBeforeUnmount, reactive } from 'vue'

const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const OTP_COOLDOWN_SECONDS = 60

export function usePasswordOtpVerification(options) {
  const otpState = reactive({
    code: '',
    requestToken: '',
    resetToken: '',
    expiresAt: '',
    verified: false,
    sending: false,
    verifying: false,
    error: '',
    success: '',
    secondsUntilResend: 0,
  })

  let cooldownTimer = null

  function cleanupCooldown() {
    if (cooldownTimer) {
      if (typeof window !== 'undefined') {
        window.clearInterval(cooldownTimer)
      }
      cooldownTimer = null
    }
  }

  function startCooldown(seconds = OTP_COOLDOWN_SECONDS) {
    cleanupCooldown()
    const duration = Number.isFinite(seconds) ? Math.max(0, Math.floor(seconds)) : OTP_COOLDOWN_SECONDS
    otpState.secondsUntilResend = duration
    if (duration <= 0 || typeof window === 'undefined') {
      return
    }
    cooldownTimer = window.setInterval(() => {
      if (otpState.secondsUntilResend <= 1) {
        cleanupCooldown()
        otpState.secondsUntilResend = 0
        return
      }
      otpState.secondsUntilResend -= 1
    }, 1000)
  }

  onBeforeUnmount(() => {
    cleanupCooldown()
  })

  const targetEmail = computed(() => {
    const email = typeof options.resolveEmail === 'function' ? options.resolveEmail() : ''
    return typeof email === 'string' ? email.trim() : ''
  })

  const canSendOtp = computed(() => !otpState.sending && otpState.secondsUntilResend <= 0)
  const canVerifyOtp = computed(() => {
    if (otpState.verifying) return false
    if (!otpState.requestToken) return false
    return /^\d{6}$/.test((otpState.code || '').trim())
  })

  function resetOtpState() {
    cleanupCooldown()
    otpState.code = ''
    otpState.requestToken = ''
    otpState.resetToken = ''
    otpState.expiresAt = ''
    otpState.verified = false
    otpState.sending = false
    otpState.verifying = false
    otpState.error = ''
    otpState.success = ''
    otpState.secondsUntilResend = 0
  }

  async function sendOtp() {
    otpState.error = ''
    otpState.success = ''
    const email = targetEmail.value
    if (!email) {
      otpState.error = 'Account email is required before sending the security code.'
      return false
    }
    otpState.sending = true
    try {
      const response = await fetch(`${API_BASE}/auth/forgot_password_send_otp.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          accountType: options.accountType,
          email,
        }),
      })
      const data = await response.json().catch(() => null)
      if (!response.ok || !data?.ok) {
        throw new Error(data?.error || 'Unable to send the security code right now.')
      }
      otpState.requestToken = data.requestToken
      otpState.expiresAt = data.expiresAt || ''
      otpState.code = ''
      otpState.resetToken = ''
      otpState.verified = false
      otpState.success = data.message || 'Security code sent to your inbox.'
      startCooldown(OTP_COOLDOWN_SECONDS)
      return true
    } catch (error) {
      const message =
        error instanceof Error ? error.message || 'Unable to send the security code.' : 'Unable to send the security code.'
      otpState.error = message
      const matchedSeconds = message.match(/(\d+)\s*seconds/i)
      if (matchedSeconds) {
        startCooldown(Number(matchedSeconds[1]))
      }
      return false
    } finally {
      otpState.sending = false
    }
  }

  async function verifyOtp() {
    otpState.error = ''
    otpState.success = ''
    if (!otpState.requestToken) {
      otpState.error = 'Send a security code to your email first.'
      return false
    }
    const email = targetEmail.value
    if (!email) {
      otpState.error = 'Account email is required to verify the security code.'
      return false
    }
    const code = (otpState.code || '').trim()
    if (!/^\d{6}$/.test(code)) {
      otpState.error = 'Enter the 6-digit code we emailed you.'
      return false
    }
    otpState.verifying = true
    try {
      const response = await fetch(`${API_BASE}/auth/forgot_password_verify_otp.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          accountType: options.accountType,
          email,
          requestToken: otpState.requestToken,
          otp: code,
        }),
      })
      const data = await response.json().catch(() => null)
      if (!response.ok || !data?.ok) {
        throw new Error(data?.error || 'Unable to verify that code.')
      }
      otpState.resetToken = data.resetToken
      otpState.verified = true
      otpState.success = data.message || 'Security code verified.'
      otpState.error = ''
      return true
    } catch (error) {
      otpState.resetToken = ''
      otpState.verified = false
      otpState.error =
        error instanceof Error ? error.message || 'Unable to verify that code.' : 'Unable to verify that code.'
      return false
    } finally {
      otpState.verifying = false
    }
  }

  return {
    otpState,
    targetEmail,
    canSendOtp,
    canVerifyOtp,
    secondsUntilResend: computed(() => otpState.secondsUntilResend),
    sendOtp,
    verifyOtp,
    resetOtpState,
  }
}
