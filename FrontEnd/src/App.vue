<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { NMessageProvider } from 'naive-ui'
import AdminDashboard from './components/AdminDashboard.vue'
import HomePage from './components/HomePage.vue'
import LoginPage from './components/LoginPage.vue'
import TravelerDashboard from './components/TravelerDashboard.vue'
import BusinessOperatorDashboard from './components/BusinessOperatorDashboard.vue'
import SiteHeader from './components/SiteHeader.vue'
import SiteFooter from './components/SiteFooter.vue'

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
const activeAccountType = ref(storedAccountType ?? null)
const loggedInUser = ref(readStoredUser(activeAccountType.value))

function persistSession(accountType, user) {
  if (!storageAvailable) return
  if (accountType && user) {
    Object.entries(sessionKeys).forEach(([type, key]) => {
      if (type === accountType) {
        window.localStorage.setItem(key, JSON.stringify(user))
      } else {
        window.localStorage.removeItem(key)
      }
    })
    window.localStorage.setItem('activeAccountType', accountType)
  } else {
    Object.values(sessionKeys).forEach((key) => window.localStorage.removeItem(key))
    window.localStorage.removeItem('activeAccountType')
  }
}

watch(
  loggedInUser,
  (user) => {
    persistSession(activeAccountType.value, user)
  },
  { deep: true },
)

watch(activeAccountType, (type) => {
  if (!type) {
    loggedInUser.value = null
    persistSession(null, null)
    return
  }
  const storedUser = readStoredUser(type)
  if (storedUser) {
    loggedInUser.value = storedUser
  }
})

const currentView = computed(() => route.meta.view ?? 'home')

const navLinks = computed(() => {
  if (currentView.value === 'traveler') return travelerNavLinks
  if (currentView.value === 'operator') return operatorNavLinks
  if (currentView.value === 'admin') return adminNavLinks
  return homeNavLinks
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
  currentView.value === 'traveler' || currentView.value === 'operator' || currentView.value === 'admin'
    ? { label: 'Log out' }
    : headerCtaHome,
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

function logout() {
  activeAccountType.value = null
  loggedInUser.value = null
  persistSession(null, null)
  router.push('/').then(() => scrollToSection('#hero'))
}

function handleHeaderCta() {
  if (
    currentView.value === 'traveler' ||
    currentView.value === 'operator' ||
    currentView.value === 'admin'
  ) {
    logout()
  } else {
    goToLogin()
  }
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

function handleLoginSuccess(payload) {
  const { accountType, user } = payload
  persistSession(accountType ?? null, user || null)
  activeAccountType.value = accountType ?? null
  loggedInUser.value = user || null

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
      <SiteHeader :nav-links="navLinks" :brand="headerBrand" :cta="headerCta" @brand-click="handleBrandClick"
        @nav-click="handleNavClick" @cta-click="handleHeaderCta" />

      <div class="content">
        <HomePage v-if="currentView === 'home'" />
        <LoginPage v-else-if="currentView === 'login'" @login-success="handleLoginSuccess" />
        <BusinessOperatorDashboard v-else-if="currentView === 'operator'" :operator="loggedInUser" />
        <TravelerDashboard v-else-if="currentView === 'traveler'" :traveler="loggedInUser" />
        <AdminDashboard v-else :current-admin-id="loggedInUser?.id ?? null" />
      </div>

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
