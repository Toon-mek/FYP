<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { NMessageProvider, NNotificationProvider, createDiscreteApi } from 'naive-ui'
import AdminDashboard from './components/AdminDashboard.vue'
import AdminEditProfile from './components/AdminEditProfile.vue'
import BusinessOperatorDashboard from './components/BusinessOperatorDashboard.vue'
import BusinessOperatorEditProfile from './components/BusinessOperatorEditProfile.vue'
import HomePage from './components/HomePage.vue'
import LoginPage from './components/LoginPage.vue'
import SiteFooter from './components/SiteFooter.vue'
import SiteHeader from './components/SiteHeader.vue'
import TravelerDashboard from './components/TravelerDashboard.vue'
import TravelerEditProfile from './components/TravelerEditProfile.vue'
import { extractProfileImage } from './utils/profileImage.js'
import { setLocale, supportedLocaleCodes } from './plugins/i18n'
import { ensureLocaleMessages } from './utils/translationLoader.js'
import { setDomTranslationLocale } from './utils/domTranslator.js'
import brandLogo from './assets/brand-logo.png'

const { t, tm, locale } = useI18n()

const storageAvailable = typeof window !== 'undefined' && typeof window.localStorage !== 'undefined'

const localeOptions = computed(() =>
  supportedLocaleCodes.map((code) => ({
    value: code,
    label: t(`language.${code}`),
  })),
)

const languageLabel = computed(() => t('language.label'))

const homeNavLinks = computed(() => [
  { label: t('nav.about'), href: '#about' },
  { label: t('nav.destinations'), href: '#destinations' },
  { label: t('nav.tips'), href: '#tips' },
  { label: t('nav.newsletter'), href: '#newsletter' },
])

const travelerNavLinks = computed(() => [
  { label: t('nav.destinations'), href: '#destinations' },
  { label: t('nav.calendar'), href: '#calendar-panel' },
  { label: t('nav.resorts'), href: '#resorts-panel' },
  { label: t('nav.upcoming'), href: '#upcoming-panel' },
])

const adminNavLinks = []
const operatorNavLinks = computed(() => [
  { label: t('nav.uploadInfo'), href: '#upload-info' },
  { label: t('nav.mediaManager'), href: '#media-manager' },
  { label: t('nav.manageListings'), href: '#listings-panel' },
])
const profileComponentMap = {
  admin: AdminEditProfile,
  operator: BusinessOperatorEditProfile,
  traveler: TravelerEditProfile,
}

const headerBrandBase = computed(() => ({
  initials: 'MS',
  name: t('header.brandName'),
  tagline: t('header.tagline'),
  logo: brandLogo,
}))

const headerCtaHome = computed(() => ({
  key: 'cta-start',
  label: t('header.cta'),
}))

const headerTone = computed(() => {
  if (currentView.value === 'operator') return 'operator'
  if (currentView.value === 'traveler') return 'traveler'
  return 'default'
})

const footerBrand = computed(() => ({
  initials: 'MS',
  name: t('footer.brand.name'),
  title: t('footer.brand.title'),
  description: t('footer.brand.description'),
  logo: brandLogo,
}))

const footerColumns = computed(() => tm('footer.columns') ?? [])
const socialLinks = computed(() => tm('footer.socialLinks') ?? [])
const footerSocialLabel = computed(() => t('footer.socialLabel'))
const footerCopy = computed(() =>
  t('footer.copy', { year: copyrightYear, brand: footerBrand.value.name ?? 'Malaysia Sustainable Travel' }),
)

const copyrightYear = new Date().getFullYear()

const router = useRouter()
const route = useRoute()
const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const currentView = computed(() => route.meta.view ?? 'home')

const sessionKeys = {
  traveler: 'travelerSession',
  operator: 'operatorSession',
  admin: 'adminSession',
}

const sessionLogKeys = {
  traveler: 'travelerLoginLogId',
  operator: 'operatorLoginLogId',
  admin: 'adminLoginLogId',
}

const sessionLogStorageAvailable =
  typeof window !== 'undefined' && typeof window.sessionStorage !== 'undefined'

function readStoredLoginLog(type) {
  if (!sessionLogStorageAvailable || !type) return null
  const key = sessionLogKeys[type]
  if (!key) return null
  try {
    const value = window.sessionStorage.getItem(key)
    if (!value) return null
    const parsed = Number.parseInt(value, 10)
    return Number.isNaN(parsed) ? null : parsed
  } catch {
    return null
  }
}

function persistLoginLog(type, logId) {
  if (!sessionLogStorageAvailable || !type) return
  const key = sessionLogKeys[type]
  if (!key) return
  try {
    if (logId && Number.isInteger(logId)) {
      window.sessionStorage.setItem(key, String(logId))
    } else {
      window.sessionStorage.removeItem(key)
    }
  } catch {
    /* ignore session storage errors */
  }
}

function deriveInitialsFromRecord(record) {
  if (!record || typeof record !== 'object') {
    return ''
  }
  const candidates = [
    record.fullName,
    record.contactPerson,
    record.contact_person,
    record.username,
    record.email,
  ]
  const firstAvailable = candidates.find(
    (value) => typeof value === 'string' && value.trim().length > 0,
  )
  if (!firstAvailable) {
    return ''
  }
  return firstAvailable
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()
}

function normaliseAccountRecord(accountType, record) {
  if (!record || typeof record !== 'object') {
    return null
  }
  const normalised = { ...record }
  const { relative, url } = extractProfileImage(normalised)
  if (relative) {
    normalised.profileImagePath = relative
    if (!normalised.profileImage) {
      normalised.profileImage = relative
    }
  } else if (!normalised.profileImagePath) {
    normalised.profileImagePath = ''
  }
  normalised.profileImageUrl = url || ''
  if (!normalised.avatarUrl) {
    normalised.avatarUrl = normalised.profileImageUrl || ''
  }
  if (!normalised.avatarInitials) {
    const initials = deriveInitialsFromRecord(normalised)
    if (initials) {
      normalised.avatarInitials = initials
    }
  }
  if (accountType && !normalised.accountType) {
    normalised.accountType = accountType
  }
  return normalised
}

function resolveAccountId(accountType, record) {
  if (!record || typeof record !== 'object') {
    return null
  }
  if (accountType === 'operator') {
    return (
      record.id ?? record.operatorID ?? record.operatorId ?? record.operator_id ?? null
    )
  }
  if (accountType === 'traveler') {
    return record.id ?? record.travelerID ?? record.travelerId ?? record.traveler_id ?? null
  }
  if (accountType === 'admin') {
    return record.id ?? record.adminID ?? record.adminId ?? record.admin_id ?? null
  }
  return record.id ?? null
}

function readStoredUser(type) {
  if (!storageAvailable || !type) return null
  const key = sessionKeys[type]
  const raw = key ? window.localStorage.getItem(key) : null
  if (!raw) return null
  try {
    const parsed = JSON.parse(raw)
    return normaliseAccountRecord(type, parsed)
  } catch {
    return null
  }
}

const storedAccountType = storageAvailable ? window.localStorage.getItem('activeAccountType') : null
const sessionState = reactive({
  traveler: readStoredUser('traveler'),
  operator: readStoredUser('operator'),
  admin: readStoredUser('admin'),
})
const loginLogIds = reactive({
  traveler: readStoredLoginLog('traveler'),
  operator: readStoredLoginLog('operator'),
  admin: readStoredLoginLog('admin'),
})
const activeAccountType = ref(storedAccountType ?? null)
const loggedInUser = computed({
  get: () => (activeAccountType.value ? sessionState[activeAccountType.value] ?? null : null),
  set: (value) => {
    if (!activeAccountType.value) return
    const type = activeAccountType.value
    const normalisedValue = value ? normaliseAccountRecord(type, value) : null
    sessionState[type] = normalisedValue
    persistSession(type, normalisedValue)
  },
})
const editProfileVisible = ref(false)
const editProfileLoading = ref(false)
const { message } = createDiscreteApi(['message'])
const adminProfileFetchState = reactive({ loading: false, attempted: false })

function persistSession(accountType, user) {
  if (!storageAvailable || !accountType) return
  const key = sessionKeys[accountType]
  if (!key) return
  if (user) {
    const payload = { ...user }
    if ('loginLogId' in payload) {
      delete payload.loginLogId
    }
    window.localStorage.setItem(key, JSON.stringify(payload))
  } else {
    window.localStorage.removeItem(key)
  }
}

watch(
  () => loggedInUser.value,
  (user) => {
    if (!user) {
      editProfileVisible.value = false
      editProfileLoading.value = false
    }
  },
  { deep: true },
)

watch(activeAccountType, (type) => {
  if (!storageAvailable) return
  if (type) {
    window.localStorage.setItem('activeAccountType', type)
  } else {
    window.localStorage.removeItem('activeAccountType')
  }
})

watch(currentView, (view) => {
  editProfileVisible.value = false
  if (view === 'admin') {
    refreshAdminProfile()
  }
}, { immediate: true })

watch(
  () => route.fullPath,
  async () => {
    await nextTick()
    await setDomTranslationLocale(locale.value)
  },
)

onMounted(async () => {
  await ensureLocaleMessages(locale.value)
  await nextTick()
  await setDomTranslationLocale(locale.value)
})

const navLinks = computed(() => {
  if (currentView.value === 'traveler') return travelerNavLinks.value
  if (currentView.value === 'operator') return operatorNavLinks.value
  if (currentView.value === 'admin') return adminNavLinks
  return homeNavLinks.value
})

const operatorUser = computed(() => sessionState.operator ?? null)

const travelerUser = computed(() => sessionState.traveler ?? null)

const adminUser = computed(() => sessionState.admin ?? null)

const currentAccountUser = computed(() => {
  if (currentView.value === 'operator') return operatorUser.value
  if (currentView.value === 'traveler') return travelerUser.value
  if (currentView.value === 'admin') return adminUser.value
  return null
})

const headerBrand = computed(() => ({
  ...headerBrandBase.value,
  href:
    currentView.value === 'traveler'
      ? '#traveler-top'
      : currentView.value === 'operator'
        ? '#operator-top'
        : currentView.value === 'admin'
          ? '#admin-top'
          : '#hero',
}))

const headerCta = computed(() =>
  currentAccountUser.value ? { key: 'cta-logout', label: t('auth.logout') } : headerCtaHome.value,
)
const activeEditComponent = computed(() =>
  currentAccountUser.value ? profileComponentMap[currentView.value] ?? null : null,
)
const headerSecondaryCta = computed(() =>
  currentAccountUser.value && activeEditComponent.value
    ? { key: 'cta-edit-profile', label: t('profile.edit') }
    : null,
)

async function handleLocaleChange(nextLocale) {
  if (!nextLocale || nextLocale === locale.value) {
    return
  }
  await ensureLocaleMessages(nextLocale)
  setLocale(nextLocale)
  await nextTick()
  await setDomTranslationLocale(nextLocale)
}

function scrollToSection(targetId) {
  if (!targetId) {
    return
  }
  nextTick(() => {
    const section = document.querySelector(targetId)
    if (section) {
      section.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }
  })
}

function showHome(targetId) {
  if (currentView.value !== 'home') {
    router.push('/').then(() => {
      scrollToSection(targetId || '#hero')
    })
  } else {
    scrollToSection(targetId || '#hero')
  }
}

function goToLogin() {
  router.push('/login')
}

async function logout(targetType = activeAccountType.value) {
  if (!targetType) return

  const user = sessionState[targetType] ?? null
  const accountId = resolveAccountId(targetType, user)
  const logId = loginLogIds[targetType] ?? null

  if (accountId) {
    try {
      const response = await fetch(`${API_BASE}/auth/logout.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          accountType: targetType,
          accountId,
          logId,
        }),
      })
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }
    } catch (error) {
      message.warning('Unable to update login status on the server. You have been logged out locally.')
    }
  }

  loginLogIds[targetType] = null
  persistLoginLog(targetType, null)
  sessionState[targetType] = null
  persistSession(targetType, null)

  if (targetType === activeAccountType.value) {
    activeAccountType.value = null
    editProfileVisible.value = false
    editProfileLoading.value = false
    await router.push('/')
    scrollToSection('#hero')
    return
  }

  if (currentView.value === targetType) {
    editProfileVisible.value = false
    editProfileLoading.value = false
  }
}

async function handleHeaderCta() {
  if (currentAccountUser.value) {
    await logout(currentView.value)
    return
  }
  if (currentView.value !== 'login') {
    goToLogin()
    return
  }
  scrollToSection('#hero')
}

function handleBrandClick() {
  if (currentView.value === 'traveler') {
    scrollToSection('#traveler-top')
  } else if (currentView.value === 'operator') {
    scrollToSection('#operator-top')
  } else if (currentView.value === 'admin') {
    scrollToSection('#admin-top')
  } else {
    showHome('#hero')
  }
}

function handleNavClick(href) {
  if (!href || !href.startsWith('#')) {
    return
  }
  if (currentView.value === 'traveler') {
    scrollToSection(href)
  } else if (currentView.value === 'operator') {
    if (typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent('operator:navigate', { detail: href.replace('#', '') }))
    }
  } else {
    showHome(href)
  }
}

function handleEditProfileClick() {
  if (!currentAccountUser.value) {
    const view = currentView.value
    if (view === 'operator' || view === 'traveler' || view === 'admin') {
      message.warning(`Please log in as the ${view} before editing the profile.`)
      goToLogin()
    }
    return
  }
  editProfileVisible.value = true
}

async function refreshAdminProfile() {
  if (adminProfileFetchState.loading) return
  const admin = sessionState.admin
  if (!admin) {
    adminProfileFetchState.attempted = true
    return
  }
  if (admin.profileImageUrl && adminProfileFetchState.attempted) {
    return
  }
  const adminId = admin.id ?? admin.adminID ?? null
  if (!adminId) {
    adminProfileFetchState.attempted = true
    return
  }
  adminProfileFetchState.loading = true
  try {
    const response = await fetch(
      `${API_BASE}/admin/profile.php?adminId=${encodeURIComponent(adminId)}`,
    )
    const result = await response.json().catch(() => null)
    if (!response.ok || !result?.ok || !result.admin) {
      adminProfileFetchState.attempted = true
      return
    }
    const normalised = normaliseAccountRecord('admin', result.admin)
    sessionState.admin = normalised
    persistSession('admin', normalised)
    adminProfileFetchState.attempted = true
  } catch (error) {
    adminProfileFetchState.attempted = true
  } finally {
    adminProfileFetchState.loading = false
  }
}

async function handleProfileSave(changes) {
  const user = currentAccountUser.value
  if (!user || !changes || typeof changes !== 'object') {
    message.error('Unable to determine the active account. Please log in again.')
    return
  }
  const view = currentView.value
  const accountId =
    user.id ?? user.operatorID ?? user.operatorId ?? user.travelerID ?? user.adminID ?? null
  if (!accountId) {
    message.error('Unable to determine profile identifier. Please log in again.')
    return
  }

  let endpoint = ''
  const payload = { ...changes }
  let resultKey = ''

  if (view === 'operator') {
    endpoint = `${API_BASE}/operator/profile.php`
    payload.operatorId = accountId
    resultKey = 'operator'
  } else if (view === 'traveler') {
    endpoint = `${API_BASE}/traveler/profile.php`
    payload.travelerId = accountId
    resultKey = 'traveler'
  } else if (view === 'admin') {
    endpoint = `${API_BASE}/admin/profile.php`
    payload.adminId = accountId
    resultKey = 'admin'
  } else {
    message.error('Unsupported account type for profile editing.')
    return
  }

  editProfileLoading.value = true
  try {
    const response = await fetch(endpoint, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    const result = await response.json().catch(() => null)
    if (!response.ok || !result || result.ok !== true || !result[resultKey]) {
      throw new Error(result?.error || `Failed to update profile (HTTP ${response.status})`)
    }
    const updatedProfile = result[resultKey] ?? result.user ?? null
    const mergedProfile = { ...(sessionState[view] ?? {}), ...updatedProfile }
    const normalisedProfile = normaliseAccountRecord(view, mergedProfile)
    sessionState[view] = normalisedProfile
    persistSession(view, normalisedProfile)
    if (view === 'admin') {
      adminProfileFetchState.attempted = true
    }
    editProfileVisible.value = false
    message.success('Profile updated successfully.')
  } catch (error) {
    message.error(error instanceof Error ? error.message : 'Unable to update profile.')
  } finally {
    editProfileLoading.value = false
  }
}

function handleLoginSuccess(payload) {
  const { accountType, user } = payload
  if (accountType) {
    const normalisedUser = user ? normaliseAccountRecord(accountType, user) : null
    sessionState[accountType] = normalisedUser
    persistSession(accountType, normalisedUser)
    loginLogIds[accountType] = payload?.loginLogId ?? null
    persistLoginLog(accountType, loginLogIds[accountType])
  }
  activeAccountType.value = accountType ?? null

  if (accountType === 'traveler') {
    router.push('/traveler').then(() => scrollToSection('#traveler-top'))
  } else if (accountType === 'operator') {
    router.push('/operator').then(() => scrollToSection('#operator-top'))
  } else if (accountType === 'admin') {
    router.push('/admin').then(() => scrollToSection('#admin-top'))
  } else {
    router.push('/')
  }
}
</script>

<template>
  <n-notification-provider>
    <n-dialog-provider>
      <n-message-provider>
      <div class="page" :class="`page--${currentView}`">
      <SiteHeader
        :nav-links="navLinks"
        :brand="headerBrand"
        :cta="headerCta"
        :secondary-cta="headerSecondaryCta"
        :language-options="localeOptions"
        :language-label="languageLabel"
        :current-locale="locale"
        :tone="headerTone"
        @brand-click="handleBrandClick"
        @nav-click="handleNavClick"
        @cta-click="handleHeaderCta"
        @secondary-cta-click="handleEditProfileClick"
        @locale-change="handleLocaleChange"
      />

      <div class="content">
        <HomePage v-if="currentView === 'home'" />
        <LoginPage v-else-if="currentView === 'login'" @login-success="handleLoginSuccess" />
        <BusinessOperatorDashboard v-else-if="currentView === 'operator'" :operator="operatorUser" />
        <TravelerDashboard v-else-if="currentView === 'traveler'" :traveler="travelerUser" />
        <AdminDashboard v-else :current-admin-id="adminUser?.id ?? null" :admin="adminUser" />
      </div>

      <component
        v-if="activeEditComponent && currentAccountUser"
        :is="activeEditComponent"
        v-model:modelValue="editProfileVisible"
        :profile="currentAccountUser"
        :loading="editProfileLoading"
        @save="handleProfileSave"
      />

      <SiteFooter
        :brand="footerBrand"
        :columns="footerColumns"
        :social-links="socialLinks"
        :social-label="footerSocialLabel"
        :copyright-text="footerCopy"
      />
      </div>
      </n-message-provider>
    </n-dialog-provider>
  </n-notification-provider>
</template>

<style scoped>
.page {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 3.5rem;
}

.content {
  display: contents;
}

.page--login :deep(.site-nav a) {
  cursor: pointer;
}
</style>
