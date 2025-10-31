<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { NMessageProvider, createDiscreteApi } from 'naive-ui'
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

const homeNavLinks = [
  { label: 'About', href: '#about' },
  { label: 'Destinations', href: '#destinations' },
  { label: 'Tips', href: '#tips' },
  { label: 'Newsletter', href: '#newsletter' },
]

const travelerNavLinks = [
  { label: 'Destinations', href: '#destinations' },
  { label: 'Calendar', href: '#calendar-panel' },
  { label: 'Resorts', href: '#resorts-panel' },
  { label: 'Upcoming', href: '#upcoming-panel' },
]

const adminNavLinks = []
const operatorNavLinks = [
  { label: 'Upload Info', href: '#upload-info' },
  { label: 'Media Manager', href: '#media-manager' },
  { label: 'Manage Listings', href: '#listings-panel' },
]
const profileComponentMap = {
  admin: AdminEditProfile,
  operator: BusinessOperatorEditProfile,
  traveler: TravelerEditProfile,
}

const headerBrandBase = {
  initials: 'MS',
  name: 'Malaysia Sustainable Travel',
  tagline: 'Explore with care',
}

const headerCtaHome = {
  label: 'Start planning',
}

const footerBrand = {
  initials: 'MS',
  title: 'Travel that uplifts Malaysia',
  description:
    'Join a community of explorers committed to protecting ecosystems and honouring local wisdom.',
}

const footerColumns = [
  {
    title: 'Plan your trip',
    links: [
      { label: 'Eco-friendly itineraries', href: '#' },
      { label: 'Responsible tour partners', href: '#' },
      { label: 'Community homestays', href: '#' },
    ],
  },
  {
    title: 'Learn & inspire',
    links: [
      { label: 'Sustainable travel guides', href: '#' },
      { label: 'Wildlife conservation stories', href: '#' },
      { label: 'Climate action toolkit', href: '#' },
    ],
  },
  {
    title: 'Support',
    links: [
      { label: 'About the initiative', href: '#' },
      { label: 'Volunteer programmes', href: '#' },
      { label: 'Contact our team', href: '#' },
    ],
  },
]

const socialLinks = [
  { label: 'Instagram', href: '#' },
  { label: 'Facebook', href: '#' },
  { label: 'YouTube', href: '#' },
]

const copyrightYear = new Date().getFullYear()

const router = useRouter()
const route = useRoute()
const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const currentView = computed(() => route.meta.view ?? 'home')

const storageAvailable = typeof window !== 'undefined' && typeof window.localStorage !== 'undefined'
const sessionKeys = {
  traveler: 'travelerSession',
  operator: 'operatorSession',
  admin: 'adminSession',
}

function readStoredUser(type) {
  if (!storageAvailable || !type) return null
  const key = sessionKeys[type]
  const raw = key ? window.localStorage.getItem(key) : null
  if (!raw) return null
  try {
    return JSON.parse(raw)
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
const activeAccountType = ref(storedAccountType ?? null)
const loggedInUser = computed({
  get: () => (activeAccountType.value ? sessionState[activeAccountType.value] ?? null : null),
  set: (value) => {
    if (!activeAccountType.value) return
    sessionState[activeAccountType.value] = value ?? null
    persistSession(activeAccountType.value, value ?? null)
  },
})
const editProfileVisible = ref(false)
const editProfileLoading = ref(false)
const { message } = createDiscreteApi(['message'])

function persistSession(accountType, user) {
  if (!storageAvailable || !accountType) return
  const key = sessionKeys[accountType]
  if (!key) return
  if (user) {
    window.localStorage.setItem(key, JSON.stringify(user))
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

watch(currentView, () => {
  editProfileVisible.value = false
})

const navLinks = computed(() => {
  if (currentView.value === 'traveler') return travelerNavLinks
  if (currentView.value === 'operator') return operatorNavLinks
  if (currentView.value === 'admin') return adminNavLinks
  return homeNavLinks
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
  ...headerBrandBase,
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
  currentAccountUser.value ? { label: 'Log out' } : headerCtaHome,
)
const activeEditComponent = computed(() =>
  currentAccountUser.value ? profileComponentMap[currentView.value] ?? null : null,
)
const headerSecondaryCta = computed(() =>
  currentAccountUser.value && activeEditComponent.value ? { label: 'Edit profile' } : null,
)

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

function logout(targetType = activeAccountType.value) {
  if (!targetType) return
  sessionState[targetType] = null
  persistSession(targetType, null)

  if (targetType === activeAccountType.value) {
    activeAccountType.value = null
    editProfileVisible.value = false
    editProfileLoading.value = false
    router.push('/').then(() => scrollToSection('#hero'))
    return
  }

  if (currentView.value === targetType) {
    editProfileVisible.value = false
    editProfileLoading.value = false
  }
}

function handleHeaderCta() {
  if (currentAccountUser.value) {
    logout(currentView.value)
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
    sessionState[view] = { ...(sessionState[view] ?? {}), ...updatedProfile }
    persistSession(view, sessionState[view])
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
    sessionState[accountType] = user || null
    persistSession(accountType, sessionState[accountType])
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
  <n-message-provider>
    <div class="page" :class="`page--${currentView}`">
      <SiteHeader
        :nav-links="navLinks"
        :brand="headerBrand"
        :cta="headerCta"
        :secondary-cta="headerSecondaryCta"
        @brand-click="handleBrandClick"
        @nav-click="handleNavClick"
        @cta-click="handleHeaderCta"
        @secondary-cta-click="handleEditProfileClick"
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

      <SiteFooter :brand="footerBrand" :columns="footerColumns" :social-links="socialLinks"
        :copyright-year="copyrightYear" />
    </div>
  </n-message-provider>
</template>

<style scoped>
.page {
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
