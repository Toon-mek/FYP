<script setup>
import { computed, reactive, ref, watch } from 'vue'
import {
  emailRule,
  minLengthRule,
  phoneRule,
  required,
  urlRule,
} from '../utils/validators'

const emit = defineEmits(['login-success'])
const accountTypes = [
  {
    id: 'traveler',
    label: 'Traveler',
    title: 'Traveler account',
    description: 'Plan low-impact journeys, save eco stays, and access personalized guidance.',
    emailLabel: 'Email address',
    emailPlaceholder: 'traveler@email.com',
    passwordLabel: 'Password',
    submitLabel: 'Sign in',
    help: { label: 'Forgot password?', href: '#' },
    footer: { text: 'New traveler?', actionLabel: 'Create account', href: '#' },
  },
  {
    id: 'business',
    label: 'Business partner',
    title: 'Business partner account',
    description: 'Manage listings, track impact metrics, and connect with responsible travelers.',
    emailLabel: 'Business email',
    emailPlaceholder: 'contact@yourcompany.my',
    passwordLabel: 'Password',
    submitLabel: 'Partner login',
    help: { label: 'Need help?', href: '#' },
    footer: { text: 'Interested in collaborating?', actionLabel: 'Apply now', href: '#' },
  },
]

const selectedType = ref(accountTypes[0].id)
const activeType = computed(
  () => accountTypes.find((type) => type.id === selectedType.value) ?? accountTypes[0],
)

const loginFormRef = ref(null)
const loginForms = reactive({
  traveler: { email: '', password: '' },
  business: { email: '', password: '' },
})
const currentLoginForm = computed(() => loginForms[selectedType.value])
const selectedAccountType = computed(() =>
  selectedType.value === 'business' ? 'operator' : selectedType.value,
)

const loggingIn = ref(false)
const loginError = ref('')
const API_BASE = import.meta.env.VITE_API_BASE || '/api'

const loginRules = {
  email: [required('Email is required'), emailRule('Enter a valid email address')],
  password: [required('Password is required'), minLengthRule(6, 'Password must be at least 6 characters')],
}

const showTravelerSignup = ref(false)
const travelerFormRef = ref(null)
const travelerSubmitting = ref(false)
const travelerError = ref('')
const travelerForm = reactive({
  fullName: '',
  email: '',
  password: '',
  confirmPassword: '',
})
const travelerRules = {
  fullName: [required('Full name is required')],
  email: loginRules.email,
  password: loginRules.password,
  confirmPassword: [
    required('Confirm your password'),
    {
      validator: (_, value) =>
        value === travelerForm.password ? true : new Error('Passwords do not match'),
      trigger: ['blur'],
    },
  ],
}

const showBusinessApply = ref(false)
const operatorFormRef = ref(null)
const operatorSubmitting = ref(false)
const operatorError = ref('')
const operatorForm = reactive({
  companyName: '',
  contactPerson: '',
  email: '',
  phone: '',
  website: '',
  password: '',
  confirmPassword: '',
})
const operatorRules = {
  companyName: [required('Company name is required')],
  contactPerson: [required('Contact person is required')],
  email: loginRules.email,
  phone: [phoneRule()],
  website: [urlRule()],
  password: loginRules.password,
  confirmPassword: [
    required('Confirm the password'),
    {
      validator: (_, value) =>
        value === operatorForm.password ? true : new Error('Passwords do not match'),
      trigger: ['blur'],
    },
  ],
}

const dialogCardStyle = { width: 'min(520px, calc(100vw - 2.5rem))', margin: '0 auto' }
const dialogHeaderStyle = { padding: '1.25rem clamp(1.25rem, 3vw, 1.75rem) 0' }
const dialogContentStyle = { padding: '1.5rem clamp(1.25rem, 3vw, 1.75rem)' }
const dialogFooterStyle = { padding: '0 clamp(1.25rem, 3vw, 1.75rem) 1.25rem' }

function resetTravelerForm() {
  travelerForm.fullName = ''
  travelerForm.email = ''
  travelerForm.password = ''
  travelerForm.confirmPassword = ''
  travelerError.value = ''
  travelerFormRef.value?.restoreValidation()
}

function resetOperatorForm() {
  operatorForm.companyName = ''
  operatorForm.contactPerson = ''
  operatorForm.email = ''
  operatorForm.phone = ''
  operatorForm.website = ''
  operatorForm.password = ''
  operatorForm.confirmPassword = ''
  operatorError.value = ''
  operatorFormRef.value?.restoreValidation()
}

watch(selectedType, () => {
  loginError.value = ''
  loginFormRef.value?.restoreValidation()
})

watch(
  currentLoginForm,
  () => {
    if (loginError.value) loginError.value = ''
  },
  { deep: true },
)

watch(showTravelerSignup, (open) => {
  if (!open) resetTravelerForm()
})

watch(showBusinessApply, (open) => {
  if (!open) resetOperatorForm()
})

async function handleLogin(event) {
  if (event) event.preventDefault()
  loginError.value = ''

  const valid = await loginFormRef.value
    ?.validate()
    .then(() => true)
    .catch(() => false)

  if (!valid) return

  loggingIn.value = true

  try {
    const payloadBody = {
      accountType: selectedAccountType.value,
      email: currentLoginForm.value.email.trim(),
      password: currentLoginForm.value.password,
    }

    const response = await fetch(`${API_BASE}/auth/login.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payloadBody),
    })

    const text = await response.text()
    let payload = null
    if (text) {
      try {
        payload = JSON.parse(text)
      } catch (error) {
        throw new Error('Unexpected response from server')
      }
    }

    if (!response.ok || !payload?.ok) {
      throw new Error(payload?.error || 'Invalid credentials')
    }

    emit('login-success', payload)
  } catch (error) {
    loginError.value = error instanceof Error ? error.message : 'Unexpected error'
  } finally {
    loggingIn.value = false
  }
}

async function handleTravelerSignupSubmit(event) {
  if (event) event.preventDefault()
  travelerError.value = ''

  const valid = await travelerFormRef.value
    ?.validate()
    .then(() => true)
    .catch(() => false)

  if (!valid) return

  travelerSubmitting.value = true

  try {
    const response = await fetch(`${API_BASE}/auth/register_traveler.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        fullName: travelerForm.fullName.trim(),
        email: travelerForm.email.trim(),
        password: travelerForm.password,
      }),
    })

    const data = await response.json().catch(() => null)

    if (!response.ok || !data?.ok) {
      throw new Error(data?.error || 'Unable to create traveler account')
    }

    showTravelerSignup.value = false
    loginForms[selectedType.value].email = travelerForm.email.trim()
    loginForms[selectedType.value].password = ''
    resetTravelerForm()
  } catch (error) {
    travelerError.value = error instanceof Error ? error.message : 'Unexpected error'
  } finally {
    travelerSubmitting.value = false
  }
}

async function handleOperatorApplySubmit(event) {
  if (event) event.preventDefault()
  operatorError.value = ''

  const valid = await operatorFormRef.value
    ?.validate()
    .then(() => true)
    .catch(() => false)

  if (!valid) return

  operatorSubmitting.value = true

  try {
    const response = await fetch(`${API_BASE}/auth/register_operator.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        companyName: operatorForm.companyName.trim(),
        contactPerson: operatorForm.contactPerson.trim(),
        email: operatorForm.email.trim(),
        phone: operatorForm.phone.trim(),
        website: operatorForm.website.trim(),
        password: operatorForm.password,
      }),
    })

    const data = await response.json().catch(() => null)

    if (!response.ok || !data?.ok) {
      const detail = data?.details ? ` (${data.details})` : ''
      const message = data?.error || 'Unable to submit application'
      throw new Error(`${message}${detail}`)
    }

    showBusinessApply.value = false
    loginForms.business.email = operatorForm.email.trim()
    loginForms.business.password = operatorForm.password
    resetOperatorForm()
  } catch (error) {
    console.error('Operator application failed', error)
    operatorError.value = error instanceof Error ? error.message : 'Unexpected error'
  } finally {
    operatorSubmitting.value = false
  }
}
</script>

<template>
  <section class="login">
    <Transition name="fade-slide" appear>
      <n-card class="hero-card" :bordered="false">
        <n-space vertical size="large">
          <n-tag size="small" round strong :bordered="false" type="success">Welcome back</n-tag>
          <n-gradient-text type="success" class="hero-title">
            Sign in to Malaysia Sustainable Travel
          </n-gradient-text>
          <n-text depth="3" class="hero-copy">
            Select your account type to continue planning or managing sustainable journeys.
          </n-text>
        </n-space>
      </n-card>
    </Transition>

    <n-card class="login-card" :bordered="false" size="large" :segmented="{ content: true, footer: 'soft' }">
      <template #header>
        <n-tabs v-model:value="selectedType" type="segment" size="large" pane-style="padding: 0;">
          <n-tab-pane v-for="type in accountTypes" :key="type.id" :name="type.id" :tab="type.label" />
        </n-tabs>
      </template>

      <Transition name="fade-slide" mode="out-in">
        <div class="form-body" :key="activeType.id">
          <div class="form-heading">
            <h2>{{ activeType.title }}</h2>
            <p>{{ activeType.description }}</p>
          </div>

          <n-alert v-if="loginError" type="error" closable strong @close="loginError = ''">
            {{ loginError }}
          </n-alert>

          <n-form ref="loginFormRef" :model="currentLoginForm" :rules="loginRules" size="large" label-placement="top"
            label-width="auto" require-mark-placement="right-hanging">
            <n-form-item :label="activeType.emailLabel" path="email">
              <n-input v-model:value="currentLoginForm.email" type="text" :placeholder="activeType.emailPlaceholder"
                clearable size="large" />
            </n-form-item>

            <n-form-item :label="activeType.passwordLabel" path="password">
              <n-input v-model:value="currentLoginForm.password" type="password" show-password-on="mousedown"
                placeholder="Enter password" size="large" />
            </n-form-item>

            <n-form-item class="form-actions">
              <n-button type="primary" size="medium" round :loading="loggingIn" :disabled="loggingIn"
                @click="handleLogin">
                {{ activeType.submitLabel }}
              </n-button>
              <n-button text type="primary" size="medium" tag="a" :href="activeType.help.href">
                {{ activeType.help.label }}
              </n-button>
            </n-form-item>
          </n-form>
        </div>
      </Transition>

      <template #footer>
        <div class="form-footer">
          <span>{{ activeType.footer.text }}</span>
          <n-button text type="primary" size="small"
            @click="selectedType === 'traveler' ? (showTravelerSignup = true) : (showBusinessApply = true)">
            {{ activeType.footer.actionLabel }}
          </n-button>
        </div>
      </template>
    </n-card>

    <n-modal v-model:show="showTravelerSignup" preset="card" title="Create traveler account" :style="dialogCardStyle"
      :header-style="dialogHeaderStyle" :content-style="dialogContentStyle" :footer-style="dialogFooterStyle">
      <n-alert v-if="travelerError" type="error" closable strong @close="travelerError = ''">
        {{ travelerError }}
      </n-alert>
      <n-form ref="travelerFormRef" :model="travelerForm" :rules="travelerRules" size="large" label-placement="top"
        require-mark-placement="right-hanging">
        <n-form-item label="Full name" path="fullName">
          <n-input v-model:value="travelerForm.fullName" placeholder="Enter your full name" />
        </n-form-item>
        <n-form-item label="Email address" path="email">
          <n-input v-model:value="travelerForm.email" type="email" placeholder="traveler@email.com" />
        </n-form-item>
        <n-form-item label="Password" path="password">
          <n-input v-model:value="travelerForm.password" type="password" placeholder="Create password" />
        </n-form-item>
        <n-form-item label="Confirm password" path="confirmPassword">
          <n-input v-model:value="travelerForm.confirmPassword" type="password" placeholder="Re-enter password" />
        </n-form-item>
        <n-divider />
        <n-space justify="space-between" align="center">
          <n-button quaternary type="primary" @click="showTravelerSignup = false">Cancel</n-button>
          <n-button type="primary" round :loading="travelerSubmitting" @click="handleTravelerSignupSubmit">
            Create traveler account
          </n-button>
        </n-space>
      </n-form>
    </n-modal>

    <n-modal v-model:show="showBusinessApply" preset="card" title="Apply as a business partner" :style="dialogCardStyle"
      :header-style="dialogHeaderStyle" :content-style="dialogContentStyle" :footer-style="dialogFooterStyle">
      <n-alert v-if="operatorError" type="error" closable strong @close="operatorError = ''">
        {{ operatorError }}
      </n-alert>
      <n-form ref="operatorFormRef" :model="operatorForm" :rules="operatorRules" size="large" label-placement="top"
        require-mark-placement="right-hanging">
        <n-form-item label="Company name" path="companyName">
          <n-input v-model:value="operatorForm.companyName" placeholder="Enter registered company name" />
        </n-form-item>
        <n-form-item label="Contact person" path="contactPerson">
          <n-input v-model:value="operatorForm.contactPerson" placeholder="Full name of primary contact" />
        </n-form-item>
        <n-form-item label="Business email" path="email">
          <n-input v-model:value="operatorForm.email" type="email" placeholder="contact@yourcompany.my" />
        </n-form-item>
        <n-form-item label="Phone number" path="phone">
          <n-input v-model:value="operatorForm.phone" placeholder="+60 12-345 6789" />
        </n-form-item>
        <n-form-item label="Company Website" path="website">
          <n-input v-model:value="operatorForm.website" placeholder="https://example.com" />
        </n-form-item>
        <n-form-item label="Password" path="password">
          <n-input v-model:value="operatorForm.password" type="password" placeholder="Create password" />
        </n-form-item>
        <n-form-item label="Confirm password" path="confirmPassword">
          <n-input v-model:value="operatorForm.confirmPassword" type="password" placeholder="Re-enter password" />
        </n-form-item>
        <n-divider />
        <n-space justify="space-between" align="center">
          <n-button quaternary type="primary" @click="showBusinessApply = false">Cancel</n-button>
          <n-button type="primary" round :loading="operatorSubmitting" @click="handleOperatorApplySubmit">
            Submit application
          </n-button>
        </n-space>
      </n-form>
    </n-modal>
  </section>
</template>

<style scoped>
.login {
  display: flex;
  flex-direction: column;
  gap: 2.75rem;
  padding: 0 1.5rem 4.5rem;
}

.hero-card {
  background: linear-gradient(135deg, #def4e8 0%, #f3fbf6 55%, #ffffff 100%);
  border-radius: 36px;
  box-shadow: 0 26px 52px rgba(9, 54, 34, 0.12);
  padding: clamp(1.75rem, 4vw, 2.75rem);
}

.hero-title {
  display: block;
  font-size: clamp(2.6rem, 5vw, 3.7rem);
  font-weight: 700;
  letter-spacing: -0.01em;
  line-height: 1.05;
}

.hero-copy {
  font-size: 1.1rem;
  line-height: 1.75;
  max-width: 540px;
}

.login-card {
  max-width: 760px;
  margin-inline: 0;
  border-radius: 28px;
  box-shadow: 0 28px 54px rgba(9, 54, 34, 0.08);
  overflow: hidden;
}

.form-body {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.form-body :deep(.n-alert) {
  margin-bottom: 0.5rem;
}

.form-heading h2 {
  margin: 0;
  font-size: 2.1rem;
  color: #0b3b26;
}

.form-heading p {
  margin: 0;
  color: #456553;
  line-height: 1.6;
  max-width: 560px;
}

.form-actions {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  flex-wrap: wrap;
  padding-top: 0.5rem;
}

.form-footer {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: #476754;
  font-size: 0.98rem;
}

.fade-slide-enter-active,
.fade-slide-leave-active {
  transition: opacity 0.28s ease, transform 0.28s ease;
}

.fade-slide-enter-from,
.fade-slide-leave-to {
  opacity: 0;
  transform: translateY(14px);
}

@media (max-width: 720px) {
  .login {
    padding: 0 1rem 3.5rem;
  }

  .hero-card {
    text-align: center;
  }

  .form-actions {
    flex-direction: column;
    align-items: stretch;
  }

  .form-footer {
    flex-direction: column;
    align-items: flex-start;
  }

  .hero-copy {
    max-width: none;
  }
}
</style>
