<script setup>
import { computed, reactive, ref, watch } from 'vue'
import {
  emailRule,
  minLengthRule,
  phoneRule,
  required,
} from '../utils/validators'

const emit = defineEmits(['login-success'])
const accountTypes = [
  {
    id: 'traveler',
    label: 'Traveler',
    apiAccountType: 'traveler',
    title: 'Traveler account',
    description: 'Plan low-impact journeys, save eco stays, and access personalized guidance.',
    emailLabel: 'Email address',
    emailPlaceholder: 'traveler@email.com',
    passwordLabel: 'Password',
    submitLabel: 'Sign in',
    help: { label: 'Forgot password?' },
    footer: { text: 'New traveler?', actionLabel: 'Create account' },
  },
  {
    id: 'business',
    label: 'Business partner',
    apiAccountType: 'operator',
    title: 'Business partner account',
    description: 'Manage listings, track impact metrics, and connect with responsible travelers.',
    emailLabel: 'Business email',
    emailPlaceholder: 'contact@yourcompany.my',
    passwordLabel: 'Password',
    submitLabel: 'Partner login',
    help: { label: 'Forgot password?' },
    footer: { text: 'Interested in collaborating?', actionLabel: 'Apply now' },
  },
  {
    id: 'admin',
    label: 'Administrator',
    apiAccountType: 'admin',
    title: 'Administrator account',
    description: 'Review submissions, manage listings, and support operator onboarding.',
    emailLabel: 'Admin email',
    emailPlaceholder: 'admin@example.com',
    passwordLabel: 'Password',
    submitLabel: 'Admin login',
    help: { label: 'Forgot password?' },
    footer: null,
  },
]

function normaliseAccountType(type) {
  const value = String(type || '').toLowerCase()
  if (value === 'operator' || value === 'business') return 'operator'
  if (value === 'admin' || value === 'administrator') return 'admin'
  return 'traveler'
}

function resolveLoginFormKey(type) {
  const value = normaliseAccountType(type)
  if (value === 'operator') return 'business'
  if (value === 'admin') return 'admin'
  return 'traveler'
}

function resolveForgotKey(type) {
  return normaliseAccountType(type)
}

const selectedType = ref(accountTypes[0].id)
const activeType = computed(
  () => accountTypes.find((type) => type.id === selectedType.value) ?? accountTypes[0],
)

const loginFormRef = ref(null)
const loginForms = reactive({
  traveler: { email: '', password: '' },
  business: { email: '', password: '' },
  admin: { email: '', password: '' },
})
const currentLoginForm = computed(
  () => loginForms[resolveLoginFormKey(selectedType.value)] ?? loginForms.traveler,
)
const selectedAccountType = computed(
  () => activeType.value.apiAccountType ?? normaliseAccountType(selectedType.value),
)

const loggingIn = ref(false)
const loginError = ref('')
const loginSuccess = ref('')
const API_BASE = import.meta.env.VITE_API_BASE || '/api'

const loginRules = {
  email: [required('Email is required'), emailRule('Enter a valid email address')],
  password: [required('Password is required'), minLengthRule(6, 'Password must be at least 6 characters')],
}

const showTravelerSignup = ref(false)
const travelerFormRef = ref(null)
const travelerUploadRef = ref(null)
const travelerSubmitting = ref(false)
const travelerError = ref('')
const travelerForm = reactive({
  fullName: '',
  email: '',
  password: '',
  confirmPassword: '',
  profileImageData: '',
  profileImagePreview: '',
  profileImageName: '',
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
  profileImageData: [
    {
      validator: () =>
        travelerForm.profileImageData ? true : new Error('Upload a profile photo'),
      trigger: ['change', 'blur'],
    },
  ],
}

const showBusinessApply = ref(false)
const operatorFormRef = ref(null)
const operatorUploadRef = ref(null)
const operatorSubmitting = ref(false)
const operatorError = ref('')
const operatorForm = reactive({
  companyName: '',
  contactPerson: '',
  email: '',
  phone: '',
  password: '',
  confirmPassword: '',
  profileImageData: '',
  profileImagePreview: '',
  profileImageName: '',
})
const operatorRules = {
  companyName: [required('Company name is required')],
  contactPerson: [required('Contact person is required')],
  email: loginRules.email,
  phone: [phoneRule()],
  password: loginRules.password,
  confirmPassword: [
    required('Confirm the password'),
    {
      validator: (_, value) =>
        value === operatorForm.password ? true : new Error('Passwords do not match'),
      trigger: ['blur'],
    },
  ],
  profileImageData: [
    {
      validator: () =>
        operatorForm.profileImageData ? true : new Error('Upload a profile photo'),
      trigger: ['change', 'blur'],
    },
  ],
}

const imageAccept = 'image/png, image/jpeg, image/webp'

const forgotPasswordModal = reactive({
  visible: false,
  accountType: 'traveler',
  loading: false,
  error: '',
  success: '',
})
const forgotPasswordForms = reactive({
  traveler: { email: '', passwordLastDigit: '', password: '', confirmPassword: '' },
  operator: { email: '', passwordLastDigit: '', password: '', confirmPassword: '' },
  admin: { email: '', passwordLastDigit: '', password: '', confirmPassword: '' },
})
const forgotPasswordRules = {
  traveler: {
    email: loginRules.email,
    passwordLastDigit: [
      required('Provide the last digit of your previous password'),
      {
        validator: (_, value) =>
          value && /^\d$/.test(value.trim())
            ? true
            : new Error('Enter a single digit (0-9)'),
        trigger: ['blur'],
      },
    ],
    password: loginRules.password,
    confirmPassword: [
      required('Confirm your password'),
      {
        validator: (_, value) =>
          value === forgotPasswordForms.traveler.password
            ? true
            : new Error('Passwords do not match'),
        trigger: ['blur'],
      },
    ],
  },
  operator: {
    email: loginRules.email,
    passwordLastDigit: [
      required('Provide the last digit of your previous password'),
      {
        validator: (_, value) =>
          value && /^\d$/.test(value.trim())
            ? true
            : new Error('Enter a single digit (0-9)'),
        trigger: ['blur'],
      },
    ],
    password: loginRules.password,
    confirmPassword: [
      required('Confirm your password'),
      {
        validator: (_, value) =>
          value === forgotPasswordForms.operator.password
            ? true
            : new Error('Passwords do not match'),
        trigger: ['blur'],
      },
    ],
  },
  admin: {
    email: loginRules.email,
    passwordLastDigit: [
      required('Provide the last digit of your previous password'),
      {
        validator: (_, value) =>
          value && /^\d$/.test(value.trim())
            ? true
            : new Error('Enter a single digit (0-9)'),
        trigger: ['blur'],
      },
    ],
    password: loginRules.password,
    confirmPassword: [
      required('Confirm your password'),
      {
        validator: (_, value) =>
          value === forgotPasswordForms.admin.password
            ? true
            : new Error('Passwords do not match'),
        trigger: ['blur'],
      },
    ],
  },
}
const forgotPasswordFormRefs = {
  traveler: ref(null),
  operator: ref(null),
  admin: ref(null),
}
const currentForgotKey = computed(() => resolveForgotKey(forgotPasswordModal.accountType))
const currentForgotForm = computed(() => forgotPasswordForms[currentForgotKey.value])
const currentForgotRules = computed(() => forgotPasswordRules[currentForgotKey.value])
const forgotPasswordTitle = computed(() => {
  switch (currentForgotKey.value) {
    case 'operator':
      return 'Reset business partner password'
    case 'admin':
      return 'Reset administrator password'
    default:
      return 'Reset traveler password'
  }
})
function setCurrentForgotFormRef(instance) {
  forgotPasswordFormRefs[currentForgotKey.value].value = instance ?? null
}
watch(selectedType, () => {
  loginError.value = ''
  loginSuccess.value = ''
  loginFormRef.value?.restoreValidation?.()
})

watch(showTravelerSignup, (open) => {
  if (!open) {
    resetTravelerForm()
    travelerFormRef.value?.restoreValidation?.()
  }
})

watch(showBusinessApply, (open) => {
  if (!open) {
    resetOperatorForm()
    operatorFormRef.value?.restoreValidation?.()
  }
})

watch(
  () => forgotPasswordModal.visible,
  (visible) => {
    if (!visible) {
      const key = resolveForgotKey(forgotPasswordModal.accountType)
      resetForgotPasswordForm(key)
      forgotPasswordModal.error = ''
      forgotPasswordModal.success = ''
      forgotPasswordModal.loading = false
    }
  },
)

async function fileToDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(String(reader.result))
    reader.onerror = () => reject(new Error('Unable to read the selected file.'))
    reader.readAsDataURL(file)
  })
}

function resetTravelerForm() {
  travelerForm.fullName = ''
  travelerForm.email = ''
  travelerForm.password = ''
  travelerForm.confirmPassword = ''
  travelerForm.profileImageData = ''
  travelerForm.profileImagePreview = ''
  travelerForm.profileImageName = ''
  travelerUploadRef.value?.clear?.()
  travelerError.value = ''
}

function resetOperatorForm() {
  operatorForm.companyName = ''
  operatorForm.contactPerson = ''
  operatorForm.email = ''
  operatorForm.phone = ''
  operatorForm.password = ''
  operatorForm.confirmPassword = ''
  operatorForm.profileImageData = ''
  operatorForm.profileImagePreview = ''
  operatorForm.profileImageName = ''
  operatorUploadRef.value?.clear?.()
  operatorError.value = ''
}

function resetForgotPasswordForm(key = currentForgotKey.value, options = {}) {
  const form = forgotPasswordForms[key]
  if (!form) return
  const keepEmail = options.keepEmail ?? false
  if (!keepEmail) {
    form.email = ''
  }
  if ('passwordLastDigit' in form) {
    form.passwordLastDigit = ''
  }
  form.password = ''
  form.confirmPassword = ''
  forgotPasswordFormRefs[key].value?.restoreValidation?.()
}

async function handleTravelerUploadChange({ file }) {
  if (!file?.file) {
    return
  }
  travelerError.value = ''
  try {
    if (file.file.size > 4 * 1024 * 1024) {
      throw new Error('Profile image must be 4MB or smaller.')
    }
    const dataUrl = await fileToDataUrl(file.file)
    travelerForm.profileImageData = dataUrl
    travelerForm.profileImagePreview = dataUrl
    travelerForm.profileImageName = file.name || file.file.name || 'profile-image'
    file.status = 'finished'
    file.url = dataUrl
    travelerFormRef.value?.validate?.(['profileImageData'])
  } catch (error) {
    travelerError.value =
      error instanceof Error ? error.message : 'Unable to process the selected image.'
    travelerForm.profileImageData = ''
    travelerForm.profileImagePreview = ''
    travelerForm.profileImageName = ''
    travelerUploadRef.value?.clear?.()
  }
}

function handleTravelerUploadRemove() {
  travelerForm.profileImageData = ''
  travelerForm.profileImagePreview = ''
  travelerForm.profileImageName = ''
  travelerFormRef.value?.validate?.(['profileImageData'])
}

async function handleOperatorUploadChange({ file }) {
  if (!file?.file) {
    return
  }
  operatorError.value = ''
  try {
    if (file.file.size > 4 * 1024 * 1024) {
      throw new Error('Profile image must be 4MB or smaller.')
    }
    const dataUrl = await fileToDataUrl(file.file)
    operatorForm.profileImageData = dataUrl
    operatorForm.profileImagePreview = dataUrl
    operatorForm.profileImageName = file.name || file.file.name || 'profile-image'
    file.status = 'finished'
    file.url = dataUrl
    operatorFormRef.value?.validate?.(['profileImageData'])
  } catch (error) {
    operatorError.value =
      error instanceof Error ? error.message : 'Unable to process the selected image.'
    operatorForm.profileImageData = ''
    operatorForm.profileImagePreview = ''
    operatorForm.profileImageName = ''
    operatorUploadRef.value?.clear?.()
  }
}

function handleOperatorUploadRemove() {
  operatorForm.profileImageData = ''
  operatorForm.profileImagePreview = ''
  operatorForm.profileImageName = ''
  operatorFormRef.value?.validate?.(['profileImageData'])
}

function clearLoginFormAfterSuccess(accountType) {
  const key = resolveLoginFormKey(accountType)
  const form = loginForms[key]
  if (form) {
    form.password = ''
  }
  loginFormRef.value?.restoreValidation?.()
}

async function handleLogin(event) {
  if (event) event.preventDefault()
  loginError.value = ''
  loginSuccess.value = ''

  const valid = await loginFormRef.value
    ?.validate()
    .then(() => true)
    .catch(() => false)

  if (!valid) return

  loggingIn.value = true

  const payloadBody = {
    accountType: selectedAccountType.value,
    email: currentLoginForm.value.email.trim(),
    password: currentLoginForm.value.password,
  }

  try {
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
      } catch {
        throw new Error('Unexpected response from server')
      }
    }

    if (!response.ok) {
      throw new Error(payload?.error || 'Invalid credentials')
    }

    if (!payload?.ok || !payload?.user) {
      throw new Error(payload?.error || 'Invalid credentials')
    }

    emit('login-success', payload)
    clearLoginFormAfterSuccess(payload.accountType ?? payloadBody.accountType)
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
        profileImageData: travelerForm.profileImageData,
      }),
    })

    const data = await response.json().catch(() => null)

    if (!response.ok || !data?.ok) {
      throw new Error(data?.error || 'Unable to create traveler account')
    }

    showTravelerSignup.value = false

    const key = resolveLoginFormKey('traveler')
    loginForms[key].email = travelerForm.email.trim()
    loginForms[key].password = ''
    resetTravelerForm()
    loginError.value = ''
    loginSuccess.value = 'Traveler account created. Please sign in.'
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
        password: operatorForm.password,
        profileImageData: operatorForm.profileImageData,
      }),
    })

    const data = await response.json().catch(() => null)

    if (!response.ok || !data?.ok) {
      const detail = data?.details ? ` (${data.details})` : ''
      const message = data?.error || 'Unable to submit application'
      throw new Error(`${message}${detail}`)
    }

    showBusinessApply.value = false

    const key = resolveLoginFormKey('operator')
    loginForms[key].email = operatorForm.email.trim()
    loginForms[key].password = ''
    resetOperatorForm()
    loginError.value = ''
    loginSuccess.value = 'Operator application submitted. Our team will review it shortly.'
  } catch (error) {
    console.error('Operator application failed', error)
    operatorError.value = error instanceof Error ? error.message : 'Unexpected error'
  } finally {
    operatorSubmitting.value = false
  }
}

function openForgotPasswordModal(accountType) {
  const target = normaliseAccountType(accountType)
  const key = resolveForgotKey(target)
  resetForgotPasswordForm(key)
  const loginKey = resolveLoginFormKey(target)
  const presetEmail = loginForms[loginKey]?.email?.trim?.() ?? ''
  if (presetEmail) {
    forgotPasswordForms[key].email = presetEmail
  }
  forgotPasswordModal.accountType = target
  forgotPasswordModal.error = ''
  forgotPasswordModal.success = ''
  forgotPasswordModal.loading = false
  forgotPasswordModal.visible = true
}

async function handleForgotPasswordSubmit(event) {
  if (event) event.preventDefault()
  forgotPasswordModal.error = ''
  forgotPasswordModal.success = ''

  const key = currentForgotKey.value
  const formRef = forgotPasswordFormRefs[key].value
  const valid = await formRef
    ?.validate()
    .then(() => true)
    .catch(() => false)

  if (!valid) return

  const form = forgotPasswordForms[key]
const payload = {
  accountType: key === 'operator' ? 'operator' : key,
  email: form.email.trim(),
  newPassword: form.password,
  passwordLastDigit: form.passwordLastDigit.trim(),
}

  forgotPasswordModal.loading = true

  try {
    const response = await fetch(`${API_BASE}/auth/forgot_password.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    const data = await response.json().catch(() => null)
    if (!response.ok || !data?.ok) {
      throw new Error(data?.error || 'Unable to reset password')
    }
    forgotPasswordModal.success =
      data.message || 'Password updated. You can now sign in with your new password.'
    resetForgotPasswordForm(key, { keepEmail: true })
  } catch (error) {
    forgotPasswordModal.error = error instanceof Error ? error.message : 'Unexpected error'
  } finally {
    forgotPasswordModal.loading = false
  }
}

const dialogCardStyle = { width: 'min(520px, calc(100vw - 2.5rem))', margin: '0 auto' }
const smallDialogCardStyle = { width: 'min(460px, calc(100vw - 2.5rem))', margin: '0 auto' }
const businessDialogCardStyle = {
  width: 'min(460px, calc(100vw - 2.25rem))',
  margin: '0 auto',
  borderRadius: '18px'
}
const dialogHeaderStyle = { padding: '1.25rem clamp(1.25rem, 3vw, 1.75rem) 0' }
const dialogContentStyle = { padding: '1.5rem clamp(1.25rem, 3vw, 1.75rem)' }
const compactDialogContentStyle = { padding: '1.15rem clamp(1rem, 2.5vw, 1.35rem)' }
const businessDialogContentStyle = {
  padding: '1.25rem clamp(1rem, 2.25vw, 1.5rem) 1.15rem'
}
const dialogFooterStyle = { padding: '0 clamp(1.25rem, 3vw, 1.75rem) 1.25rem' }
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
          <n-alert v-if="loginSuccess" type="success" closable strong @close="loginSuccess = ''">
            {{ loginSuccess }}
          </n-alert>

          <n-form
            ref="loginFormRef"
            :model="currentLoginForm"
            :rules="loginRules"
            size="large"
            label-placement="top"
            label-width="auto"
            require-mark-placement="right-hanging"
          >
            <n-form-item :label="activeType.emailLabel" path="email">
              <n-input
                v-model:value="currentLoginForm.email"
                type="text"
                :placeholder="activeType.emailPlaceholder"
                clearable
                size="large"
              />
            </n-form-item>

            <n-form-item :label="activeType.passwordLabel" path="password">
              <n-input
                v-model:value="currentLoginForm.password"
                type="password"
                show-password-on="mousedown"
                placeholder="Enter password"
                size="large"
              />
            </n-form-item>

            <n-form-item class="form-actions">
              <n-button
                type="primary"
                size="medium"
                round
                :loading="loggingIn"
                :disabled="loggingIn"
                @click="handleLogin"
              >
                {{ activeType.submitLabel }}
              </n-button>
              <n-button
                v-if="activeType.help?.label"
                text
                type="primary"
                size="medium"
                @click.prevent="openForgotPasswordModal(selectedAccountType)"
              >
                {{ activeType.help.label }}
              </n-button>
            </n-form-item>
          </n-form>
        </div>
      </Transition>

      <template #footer>
        <div v-if="activeType.footer" class="form-footer">
          <span v-if="activeType.footer.text">{{ activeType.footer.text }}</span>
          <n-button
            v-if="activeType.footer.actionLabel"
            text
            type="primary"
            size="small"
            @click="selectedType === 'traveler' ? (showTravelerSignup = true) : (showBusinessApply = true)"
          >
            {{ activeType.footer.actionLabel }}
          </n-button>
        </div>
      </template>
    </n-card>

    <n-modal
      v-model:show="showTravelerSignup"
      preset="card"
      title="Create traveler account"
      :style="smallDialogCardStyle"
      :header-style="dialogHeaderStyle"
      :content-style="dialogContentStyle"
      :footer-style="dialogFooterStyle"
    >
      <n-alert v-if="travelerError" type="error" closable strong @close="travelerError = ''">
        {{ travelerError }}
      </n-alert>
      <n-form
        ref="travelerFormRef"
        :model="travelerForm"
        :rules="travelerRules"
        label-placement="top"
        label-width="auto"
        size="medium"
      >
        <n-form-item label="Full name" path="fullName">
          <n-input v-model:value="travelerForm.fullName" type="text" placeholder="Your name" />
        </n-form-item>
        <n-form-item label="Email address" path="email">
          <n-input v-model:value="travelerForm.email" type="text" placeholder="traveler@email.com" />
        </n-form-item>
        <n-form-item label="Password" path="password">
          <n-input v-model:value="travelerForm.password" type="password" placeholder="Create password" />
        </n-form-item>
        <n-form-item label="Confirm password" path="confirmPassword">
          <n-input v-model:value="travelerForm.confirmPassword" type="password" placeholder="Re-enter password" />
        </n-form-item>
        <n-form-item label="Profile photo" path="profileImageData" class="signup-upload">
          <n-upload
            ref="travelerUploadRef"
            list-type="image-card"
            :default-upload="false"
            :accept="imageAccept"
            :max="1"
            @change="handleTravelerUploadChange"
            @remove="handleTravelerUploadRemove"
          >
            Upload
          </n-upload>
          <n-text depth="3" class="upload-tip">
            Accepted formats: JPG, PNG, or WebP (max 4&nbsp;MB).
          </n-text>
        </n-form-item>
        <n-divider />
        <n-space justify="space-between" align="center">
          <n-button quaternary type="primary" @click="showTravelerSignup = false">Cancel</n-button>
          <n-button type="primary" round :loading="travelerSubmitting" @click="handleTravelerSignupSubmit">
            Create account
          </n-button>
        </n-space>
      </n-form>
    </n-modal>

    <n-modal
      v-model:show="showBusinessApply"
      preset="card"
      title="Apply as a business partner"
      :style="businessDialogCardStyle"
      :header-style="dialogHeaderStyle"
      :content-style="businessDialogContentStyle"
      :footer-style="dialogFooterStyle"
    >
      <n-alert v-if="operatorError" type="error" closable strong @close="operatorError = ''">
        {{ operatorError }}
      </n-alert>
      <n-form
        ref="operatorFormRef"
        :model="operatorForm"
        :rules="operatorRules"
        label-placement="top"
        label-width="auto"
        size="medium"
        class="business-signup-form"
      >
        <n-form-item label="Business name" path="companyName">
          <n-input v-model:value="operatorForm.companyName" type="text" placeholder="Company or organisation" />
        </n-form-item>
        <n-form-item label="Contact person" path="contactPerson">
          <n-input v-model:value="operatorForm.contactPerson" type="text" placeholder="Your name" />
        </n-form-item>
        <n-form-item label="Business email" path="email">
          <n-input v-model:value="operatorForm.email" type="text" placeholder="contact@yourcompany.my" />
        </n-form-item>
        <n-form-item label="Phone number" path="phone">
          <n-input v-model:value="operatorForm.phone" type="text" placeholder="+60 12-345 6789" />
        </n-form-item>
        <n-form-item label="Password" path="password">
          <n-input v-model:value="operatorForm.password" type="password" placeholder="Create password" />
        </n-form-item>
        <n-form-item label="Confirm password" path="confirmPassword">
          <n-input v-model:value="operatorForm.confirmPassword" type="password" placeholder="Re-enter password" />
        </n-form-item>
        <n-form-item label="Profile photo" path="profileImageData" class="signup-upload">
          <n-upload
            ref="operatorUploadRef"
            list-type="image-card"
            :default-upload="false"
            :accept="imageAccept"
            :max="1"
            @change="handleOperatorUploadChange"
            @remove="handleOperatorUploadRemove"
          >
            Upload
          </n-upload>
          <n-text depth="3" class="upload-tip">
            Accepted formats: JPG, PNG, or WebP (max 4&nbsp;MB).
          </n-text>
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

  <n-modal
    v-model:show="forgotPasswordModal.visible"
    preset="card"
    :title="forgotPasswordTitle"
    :style="dialogCardStyle"
    :header-style="dialogHeaderStyle"
    :content-style="dialogContentStyle"
    :footer-style="dialogFooterStyle"
  >
    <n-alert
      v-if="forgotPasswordModal.error"
      type="error"
      closable
      strong
      @close="forgotPasswordModal.error = ''"
    >
      {{ forgotPasswordModal.error }}
    </n-alert>
    <n-alert
      v-if="forgotPasswordModal.success"
      type="success"
      closable
      strong
      @close="forgotPasswordModal.success = ''"
    >
      {{ forgotPasswordModal.success }}
    </n-alert>
    <n-form
      :model="currentForgotForm"
      :rules="currentForgotRules"
      :show-require-mark="false"
      size="large"
      label-placement="top"
      :ref="setCurrentForgotFormRef"
    >
      <n-form-item label="Email" path="email">
        <n-input
          v-model:value="currentForgotForm.email"
          type="text"
          placeholder="account@email.com"
        />
      </n-form-item>
      <n-form-item
        label="Last digit of previous password"
        path="passwordLastDigit"
      >
        <n-input
          v-model:value="currentForgotForm.passwordLastDigit"
          type="text"
          maxlength="1"
          placeholder="0-9"
        />
      </n-form-item>
      <n-form-item label="New password" path="password">
        <n-input v-model:value="currentForgotForm.password" type="password" />
      </n-form-item>
      <n-form-item label="Confirm password" path="confirmPassword">
        <n-input v-model:value="currentForgotForm.confirmPassword" type="password" />
      </n-form-item>
    </n-form>
    <n-space justify="end">
      <n-button quaternary type="primary" @click="forgotPasswordModal.visible = false">
        Cancel
      </n-button>
      <n-button
        type="primary"
        :loading="forgotPasswordModal.loading"
        @click="handleForgotPasswordSubmit"
      >
        Reset password
      </n-button>
    </n-space>
  </n-modal>
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

.signup-upload :deep(.n-upload) {
  width: 100%;
}

.signup-upload :deep(.n-upload-trigger) {
  width: 116px;
  height: 116px;
  border-radius: 22px;
}

.upload-tip {
  display: block;
  margin-top: 0.5rem;
  font-size: 0.85rem;
  color: #5b6f63;
}

.business-signup-form {
  max-width: 400px;
  margin: 0 auto;
}

.business-signup-form :deep(.n-form-item) {
  margin-bottom: 14px;
}

.business-signup-form :deep(.n-form-item-label) {
  font-size: 0.9rem;
  font-weight: 600;
  color: #2f4b3c;
}

.business-signup-form :deep(.n-input) {
  font-size: 1rem;
}

.business-signup-form :deep(.n-input__placeholder) {
  font-size: 0.95rem;
}

.business-signup-form .signup-upload :deep(.n-upload-trigger) {
  width: 104px;
  height: 104px;
  border-radius: 18px;
}

.qr-caption {
  text-align: center;
  color: #4b6657;
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
