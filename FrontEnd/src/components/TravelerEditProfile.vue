<script setup>
import { computed, reactive, ref, watch } from 'vue'
import {
  NAlert,
  NButton,
  NForm,
  NFormItem,
  NInput,
  NModal,
  NSpace,
  NUpload,
} from 'naive-ui'
import { extractProfileImage } from '../utils/profileImage.js'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false,
  },
  profile: {
    type: Object,
    default: () => ({}),
  },
  loading: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue', 'save'])

const show = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
})

const base = reactive({
  fullName: '',
  email: '',
  username: '',
  phone: '',
  profileImagePath: '',
  profileImageUrl: '',
})

const form = reactive({
  fullName: '',
  email: '',
  username: '',
  phone: '',
  currentPassword: '',
  passwordLastDigit: '',
  newPassword: '',
  confirmPassword: '',
  profileImagePreview: '',
  profileImageData: '',
  profileImageName: '',
  removeProfileImage: false,
})

const passwordState = reactive({
  verified: false,
  verifying: false,
  attempts: 0,
  method: 'current-password',
  error: '',
})

const showFallbackPrompt = computed(
  () => !passwordState.verified && passwordState.attempts >= 3,
)

const canEditPassword = computed(() => passwordState.verified)

const currentPasswordFeedback = computed(() => {
  if (formErrors.currentPassword) {
    return formErrors.currentPassword
  }
  if (passwordState.error) {
    return passwordState.error
  }
  if (passwordState.verified && passwordState.method === 'current-password') {
    return 'Current password verified.'
  }
  return ''
})

const currentPasswordStatus = computed(() => {
  if (formErrors.currentPassword || passwordState.error) {
    return 'error'
  }
  if (passwordState.verified && passwordState.method === 'current-password') {
    return 'success'
  }
  return undefined
})

const fallbackFeedback = computed(() => {
  if (formErrors.passwordLastDigit) {
    return formErrors.passwordLastDigit
  }
  if (passwordState.method === 'last-digit' && passwordState.verified) {
    return 'Last digit accepted.'
  }
  if (showFallbackPrompt.value) {
    return 'Unable to verify your current password? Enter the last digit you remember.'
  }
  return ''
})

const fallbackStatus = computed(() => {
  if (formErrors.passwordLastDigit) {
    return 'error'
  }
  if (passwordState.method === 'last-digit' && passwordState.verified) {
    return 'success'
  }
  return undefined
})

const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const IMAGE_ACCEPT = 'image/png,image/jpeg,image/webp'
const MAX_IMAGE_SIZE = 4 * 1024 * 1024
function resolveProfileImageFromSource(source) {
  return extractProfileImage(source)
}

const accountId = computed(() => {
  const profile = props.profile ?? {}
  return profile.id ?? profile.travelerID ?? null
})

const profileUploadRef = ref(null)

const formErrors = reactive({})
const submissionError = reactive({ message: '' })

function resetErrors() {
  Object.keys(formErrors).forEach((key) => delete formErrors[key])
  submissionError.message = ''
  passwordState.error = ''
}

function fileToDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(String(reader.result))
    reader.onerror = () => reject(new Error('Unable to read the selected image.'))
    reader.readAsDataURL(file)
  })
}

function resetPasswordFlow() {
  passwordState.verified = false
  passwordState.verifying = false
  passwordState.attempts = 0
  passwordState.method = 'current-password'
  passwordState.error = ''
  form.currentPassword = ''
  form.passwordLastDigit = ''
}

function syncForm(profile) {
  const source = profile && typeof profile === 'object' ? profile : {}
  const { relative, url } = resolveProfileImageFromSource(source)
  base.fullName = source.fullName ?? ''
  base.email = source.email ?? ''
  base.username = source.username ?? ''
  base.phone = source.contactNumber ?? source.phone ?? ''
  base.profileImagePath = relative
  base.profileImageUrl = url

  form.fullName = base.fullName
  form.email = base.email
  form.username = base.username
  form.phone = base.phone
  form.profileImagePreview = base.profileImageUrl
  form.profileImageData = ''
  form.profileImageName = ''
  form.removeProfileImage = false
  resetPasswordFlow()
  form.newPassword = ''
  form.confirmPassword = ''
  resetErrors()
}

watch(
  () => props.profile,
  (value) => {
    syncForm(value ?? {})
  },
  { immediate: true },
)

watch(show, (visible) => {
  if (!visible) {
    form.newPassword = ''
    form.confirmPassword = ''
    form.profileImagePreview = base.profileImageUrl
    form.profileImageData = ''
    form.profileImageName = ''
    form.removeProfileImage = false
    resetPasswordFlow()
    resetErrors()
  }
})

const profileHasPreview = computed(() => Boolean(form.profileImagePreview))
const hasBaseProfileImage = computed(() => Boolean(base.profileImageUrl))
const hasNewProfileImage = computed(() => Boolean(form.profileImageData))
const profileInitials = computed(() => {
  const source = (form.fullName || base.fullName || form.username || '').trim()
  return source ? source[0]?.toUpperCase() : ''
})

async function handleProfileImageChange({ file }) {
  const rawFile = file?.file ?? file
  if (!rawFile) {
    return
  }
  if (rawFile.size > MAX_IMAGE_SIZE) {
    submissionError.message = 'Profile image must be 4MB or smaller.'
    return
  }
  try {
    const dataUrl = await fileToDataUrl(rawFile)
    form.profileImageData = dataUrl
    form.profileImagePreview = dataUrl
    form.profileImageName = rawFile.name ?? 'profile-photo'
    form.removeProfileImage = false
    submissionError.message = ''
  } catch (error) {
    submissionError.message =
      error instanceof Error ? error.message : 'Unable to read the selected image.'
  }
}

function clearProfileImageSelection() {
  form.profileImageData = ''
  form.profileImageName = ''
  form.profileImagePreview = base.profileImageUrl
  form.removeProfileImage = false
}

function handleRemoveProfileImage() {
  form.profileImageData = ''
  form.profileImageName = ''
  form.profileImagePreview = ''
  form.removeProfileImage = true
}

function undoRemoveProfileImage() {
  form.profileImageData = ''
  form.profileImageName = ''
  form.profileImagePreview = base.profileImageUrl
  form.removeProfileImage = false
}

async function verifyCurrentPassword() {
  if (passwordState.verifying || passwordState.method === 'last-digit') {
    return
  }
  passwordState.error = ''
  delete formErrors.currentPassword

  if (!form.currentPassword || !form.currentPassword.trim()) {
    passwordState.error = 'Enter your current password.'
    formErrors.currentPassword = 'Enter your current password.'
    return
  }

  const id = accountId.value
  if (!id) {
    passwordState.error = 'Unable to verify without an account identifier.'
    return
  }

  passwordState.verifying = true
  try {
    const response = await fetch(`${API_BASE}/auth/verify_password.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        accountType: 'traveler',
        accountId: id,
        password: form.currentPassword,
      }),
    })
    const result = await response.json().catch(() => null)
    if (!response.ok || !result?.ok) {
      throw new Error(result?.error || 'Current password did not match.')
    }
    passwordState.verified = true
    passwordState.method = 'current-password'
    passwordState.error = ''
    passwordState.attempts = 0
    form.passwordLastDigit = ''
  } catch (error) {
    passwordState.attempts += 1
    passwordState.error =
      error instanceof Error
        ? error.message || 'Current password did not match.'
        : 'Current password did not match.'
  } finally {
    passwordState.verifying = false
  }
}

async function verifyLastDigit() {
  if (passwordState.verifying) {
    return
  }
  passwordState.error = ''
  delete formErrors.passwordLastDigit

  const value = (form.passwordLastDigit || '').trim()
  if (!/^\d$/.test(value)) {
    const message = 'Enter a single digit (0-9) to continue.'
    formErrors.passwordLastDigit = message
    passwordState.error = message
    return
  }

  const id = accountId.value
  if (!id) {
    const message = 'Unable to verify without an account identifier.'
    formErrors.passwordLastDigit = message
    passwordState.error = message
    return
  }

  passwordState.verifying = true
  try {
    const response = await fetch(`${API_BASE}/auth/verify_password_digit.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        accountType: 'traveler',
        accountId: id,
        digit: value,
      }),
    })
    const result = await response.json().catch(() => null)
    if (!response.ok || !result?.ok) {
      throw new Error(result?.error || 'Last digit did not match our records.')
    }
    passwordState.verified = true
    passwordState.method = 'last-digit'
    passwordState.error = ''
    passwordState.attempts = 0
  } catch (error) {
    const message =
      error instanceof Error
        ? error.message || 'Last digit did not match our records.'
        : 'Last digit did not match our records.'
    formErrors.passwordLastDigit = message
    passwordState.error = message
  } finally {
    passwordState.verifying = false
  }
}

function switchToCurrentPassword() {
  passwordState.verified = false
  passwordState.method = 'current-password'
  form.passwordLastDigit = ''
  passwordState.error = ''
  delete formErrors.passwordLastDigit
  delete formErrors.currentPassword
}

function validate() {
  resetErrors()
  const errors = {}
  if (!form.fullName || !form.fullName.trim()) {
    errors.fullName = 'Name is required.'
  }
  if (!form.email || !form.email.trim()) {
    errors.email = 'Email is required.'
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) {
    errors.email = 'Enter a valid email.'
  }
  if (!form.username || !form.username.trim()) {
    errors.username = 'Username is required.'
  }
  if (form.newPassword || form.confirmPassword) {
    if (!passwordState.verified) {
      errors.currentPassword =
        'Verify your current password before setting a new one.'
    } else if (passwordState.method === 'current-password') {
      if (!form.currentPassword || !form.currentPassword.trim()) {
        errors.currentPassword = 'Current password is required.'
      }
    } else if (passwordState.method === 'last-digit') {
      if (!form.passwordLastDigit || !form.passwordLastDigit.trim()) {
        errors.passwordLastDigit =
          'Enter the last digit of your password to continue.'
      }
    }
    if (form.newPassword.length < 6) {
      errors.newPassword = 'Password must be at least 6 characters.'
    }
    if (form.newPassword !== form.confirmPassword) {
      errors.confirmPassword = 'Passwords do not match.'
    }
  }

  Object.assign(formErrors, errors)
  return Object.keys(errors).length === 0
}

function collectPayload() {
  const payload = {}
  if (form.fullName.trim() !== base.fullName) {
    payload.fullName = form.fullName.trim()
  }
  if (form.email.trim() !== base.email) {
    payload.email = form.email.trim()
  }
  if (form.username.trim() !== base.username) {
    payload.username = form.username.trim()
  }
  if ((form.phone || '') !== (base.phone || '')) {
    payload.contactNumber = form.phone.trim()
  }
  if (form.newPassword) {
    payload.password = form.newPassword
    payload.passwordVerificationMethod = passwordState.method
    if (passwordState.method === 'current-password') {
      payload.currentPassword = form.currentPassword
    } else if (passwordState.method === 'last-digit') {
      payload.passwordLastDigit = form.passwordLastDigit.trim()
    }
  }
  if (form.profileImageData) {
    payload.profileImageData = form.profileImageData
  } else if (form.removeProfileImage) {
    payload.removeProfileImage = true
  }
  return payload
}

function handleSubmit() {
  if (!validate()) {
    return
  }
  const payload = collectPayload()
  if (Object.keys(payload).length === 0) {
    submissionError.message = 'No changes detected.'
    return
  }
  emit('save', payload)
}
</script>

<template>
  <n-modal
    v-model:show="show"
    preset="card"
    title="Edit traveler profile"
    style="max-width: 560px"
    card-style="border-radius: 18px; overflow: hidden; border: 1px solid rgba(0,0,0,0.08);"
    :mask-closable="false"
  >
    <n-space class="edit-profile-body" vertical size="small">
      <n-alert
        v-if="submissionError.message"
        type="error"
        closable
        @close="submissionError.message = ''"
      >
        {{ submissionError.message }}
      </n-alert>

      <n-form
        class="edit-profile-form"
        label-placement="left"
        :label-width="180"
        size="small"
        :model="form"
      >
        <n-form-item label="Full name" :feedback="formErrors.fullName" :validation-status="formErrors.fullName ? 'error' : undefined">
          <n-input v-model:value="form.fullName" placeholder="Enter full name" />
        </n-form-item>

        <n-form-item label="Email address" :feedback="formErrors.email" :validation-status="formErrors.email ? 'error' : undefined">
          <n-input v-model:value="form.email" placeholder="traveler@email.com" />
        </n-form-item>

        <n-form-item label="Username" :feedback="formErrors.username" :validation-status="formErrors.username ? 'error' : undefined">
          <n-input v-model:value="form.username" placeholder="traveler username" />
        </n-form-item>

        <n-form-item label="Phone (optional)">
          <n-input v-model:value="form.phone" placeholder="+60 12-345 6789" />
        </n-form-item>

        <n-form-item label="Profile photo">
          <div class="profile-photo-field">
            <div
              class="profile-photo-preview"
              :class="{ 'profile-photo-preview--empty': !profileHasPreview && !form.removeProfileImage }"
            >
              <img
                v-if="profileHasPreview && !form.removeProfileImage"
                :src="form.profileImagePreview"
                alt="Profile photo preview"
              />
              <div v-else class="profile-photo-placeholder">
                <span v-if="form.removeProfileImage">Photo will be removed</span>
                <span v-else-if="profileInitials" class="profile-photo-initial">{{ profileInitials }}</span>
                <span v-else>No photo uploaded</span>
              </div>
            </div>
            <div class="profile-photo-actions">
              <n-upload
                ref="profileUploadRef"
                :show-file-list="false"
                :default-upload="false"
                :accept="IMAGE_ACCEPT"
                :max="1"
                @change="handleProfileImageChange"
              >
                <n-button size="small" tertiary type="primary">Change photo</n-button>
              </n-upload>
              <n-button
                v-if="hasNewProfileImage"
                size="small"
                quaternary
                @click="clearProfileImageSelection"
              >
                Clear selection
              </n-button>
              <n-button
                v-else-if="(profileHasPreview || hasBaseProfileImage) && !form.removeProfileImage"
                size="small"
                quaternary
                type="error"
                @click="handleRemoveProfileImage"
              >
                Remove photo
              </n-button>
              <n-button
                v-if="form.removeProfileImage && hasBaseProfileImage"
                size="small"
                quaternary
                type="primary"
                @click="undoRemoveProfileImage"
              >
                Keep existing photo
              </n-button>
            </div>
          </div>
        </n-form-item>

        <n-form-item
          label="Current password"
          :feedback="currentPasswordFeedback"
          :validation-status="currentPasswordStatus"
        >
          <n-space vertical size="small" class="password-field" style="width: 100%;">
            <n-input
              v-model:value="form.currentPassword"
              type="password"
              placeholder="Enter your current password"
              :disabled="passwordState.method === 'last-digit'"
            />
            <n-space justify="space-between" class="password-actions" style="width: 100%;">
              <n-button
                size="small"
                type="primary"
                tertiary
                :loading="passwordState.verifying"
                :disabled="passwordState.verified && passwordState.method === 'current-password'"
                @click="verifyCurrentPassword"
              >
                Verify current password
              </n-button>
              <n-button
                v-if="passwordState.method === 'last-digit'"
                size="small"
                quaternary
                type="primary"
                @click="switchToCurrentPassword"
              >
                Use current password instead
              </n-button>
            </n-space>
          </n-space>
        </n-form-item>

        <n-form-item
          v-if="showFallbackPrompt || passwordState.method === 'last-digit'"
          label="Password recovery"
          :feedback="fallbackFeedback"
          :validation-status="fallbackStatus"
        >
          <n-space vertical size="small" class="password-field" style="width: 100%;">
            <n-input
              v-model:value="form.passwordLastDigit"
              type="text"
              placeholder="Enter the last digit of your password" :maxlength="1"
              :disabled="passwordState.method === 'last-digit' && passwordState.verified"
            />
            <n-space justify="space-between" class="password-actions" style="width: 100%;">
              <n-button
                size="small"
                tertiary
                type="warning"
                :disabled="passwordState.method === 'last-digit' && passwordState.verified"
                @click="verifyLastDigit"
              >
                Verify last digit
              </n-button>
              <span>
                {{ passwordState.method === 'last-digit' && passwordState.verified ? 'Last digit accepted.' : 'Available after three incorrect attempts.' }}
              </span>
            </n-space>
          </n-space>
        </n-form-item>

        <n-form-item label="New password" :feedback="formErrors.newPassword" :validation-status="formErrors.newPassword ? 'error' : undefined">
          <n-input v-model:value="form.newPassword" type="password" placeholder="Leave blank to keep current password" :disabled="!canEditPassword" />
        </n-form-item>

        <n-form-item label="Confirm password" :feedback="formErrors.confirmPassword" :validation-status="formErrors.confirmPassword ? 'error' : undefined">
          <n-input v-model:value="form.confirmPassword" type="password" placeholder="Re-enter new password" :disabled="!canEditPassword" />
        </n-form-item>
      </n-form>

      <n-space class="edit-profile-actions" justify="end">
        <n-button quaternary type="primary" @click="show = false">Cancel</n-button>
        <n-button type="primary" :loading="loading" :disabled="loading" @click="handleSubmit">
          Save changes
        </n-button>
      </n-space>
    </n-space>
  </n-modal>
</template>

<style scoped>
.edit-profile-body {
  gap: 12px;
}

.edit-profile-form :deep(.n-form-item) {
  margin-bottom: 8px;
}

.profile-photo-field {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}

.profile-photo-preview {
  width: 98px;
  height: 98px;
  border-radius: 18px;
  overflow: hidden;
  background: #f1f5f9;
  border: 1px dashed rgba(15, 23, 42, 0.12);
  display: flex;
  align-items: center;
  justify-content: center;
}

.profile-photo-preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.profile-photo-preview--empty {
  background: #f8fafc;
}

.profile-photo-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  font-size: 12px;
  color: #64748b;
  text-align: center;
  padding: 0 6px;
}

.profile-photo-initial {
  font-size: 32px;
  font-weight: 700;
  color: #1f2937;
}

.profile-photo-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.edit-profile-form :deep(.n-form-item .n-form-item-label) {
  font-weight: 600;
  padding-right: 12px;
}

.edit-profile-form :deep(.n-form-item .n-form-item-blank) {
  flex: 1;
}

.edit-profile-form :deep(.n-input),
.edit-profile-form :deep(.n-input .n-input__textarea-el),
.edit-profile-form :deep(.n-input .n-input__input-el) {
  width: 100%;
  max-width: 100%;
}

.edit-profile-form :deep(.password-field) {
  width: 100%;
}

.edit-profile-form :deep(.password-actions) {
  width: 100%;
}

.edit-profile-form :deep(.password-actions > span) {
  flex: 1;
  text-align: right;
}

.edit-profile-actions {
  margin-top: 2px;
}
</style>
