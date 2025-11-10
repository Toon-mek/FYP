<script setup>
import { computed, h, onBeforeUnmount, onMounted, provide, ref } from 'vue'
import { NAvatar, NButton, NEmpty, NSpin, NSpace, NTag, NText, useMessage } from 'naive-ui'
import AdminBusinessListing from './admin/AdminBusinessListing.vue'
import AdminCommunityModeration from './admin/AdminCommunityModeration.vue'
import AdminListingVerification from './admin/AdminListingVerification.vue'
import AdminNotificationOutbox from './admin/AdminNotificationOutbox.vue'
import UserAndRole from './admin/UserAndRole.vue'
import { notificationFeedSymbol, useNotificationFeed } from '../composables/useNotificationFeed.js'
import { extractProfileImage } from '../utils/profileImage.js'

const avatarFallbackStyle = {
  background: 'var(--primary-color-hover)',
  color: 'white',
}
const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const ACTIVITY_FETCH_LIMIT = 8
const ACTIVITY_REFRESH_INTERVAL = 60_000

function deriveAvatarInfo(source) {
  return extractProfileImage(source)
}
const props = defineProps({
  currentAdminId: {
    type: Number,
    default: null,
  },
  admin: {
    type: Object,
    default: () => null,
  },
})
const defaultAdminProfile = {
  fullName: 'MS Admin',
  username: 'admin',
  email: '',
}
const adminProfile = computed(() => {
  const source = props.admin ?? {}
  const displayName = source.fullName || source.username || defaultAdminProfile.fullName
  const initials =
    source.avatarInitials ||
    displayName
      .split(/\s+/)
      .map((part) => part[0])
      .join('')
      .slice(0, 2)
      .toUpperCase()
  const { relative: derivedAvatarPath, url: derivedAvatarUrl } = deriveAvatarInfo(source)
  const avatarUrl = derivedAvatarUrl || source.avatarUrl || ''
  const avatarPath = derivedAvatarPath || source.avatarPath || ''
  return {
    ...defaultAdminProfile,
    ...source,
    displayName,
    initials,
    avatarUrl,
    avatarPath,
  }
})
const message = useMessage()

const adminRecipientId = computed(() => {
  if (typeof props.currentAdminId === 'number' && props.currentAdminId > 0) {
    return props.currentAdminId
  }
  const source = props.admin ?? {}
  const possibleKeys = ['adminId', 'adminID', 'id', 'userId', 'userID']
  for (const key of possibleKeys) {
    const value = Number(source[key])
    if (Number.isFinite(value) && value > 0) {
      return value
    }
  }
  return null
})

const adminNotificationFeed = useNotificationFeed({
  recipientType: computed(() => 'Admin'),
  recipientId: adminRecipientId,
  listLimit: 25,
  pollInterval: 60000,
  announce: true,
})
provide(notificationFeedSymbol, adminNotificationFeed)

const menuOptions = [
  { key: 'overview', label: 'Dashboard overview' },
  {
    type: 'group',
    label: 'People & access',
    children: [{ key: 'users', label: 'User & role management' }],
  },
  {
    type: 'group',
    label: 'Listings',
    children: [
      { key: 'verification', label: 'Listing verification' },
      { key: 'business', label: 'Business listings' },
    ],
  },
  {
    type: 'group',
    label: 'Community',
    children: [
      { key: 'community', label: 'Community moderation' },
      { key: 'notifications', label: 'Notifications & outreach' },
    ],
  },
  { key: 'reports', label: 'System reports' },
  { key: 'settings', label: 'Platform settings' },
]

const moduleMeta = {
  overview: {
    title: 'Admin control center',
    subtitle: 'Monitor community health and verify sustainable partners',
  },
  users: {
    title: 'User management',
    subtitle: 'Approve traveler and operator accounts, enforce access policies',
  },
  verification: {
    title: 'Listing verification',
    subtitle: 'Review sustainability credentials before listings go live',
  },
  business: {
    title: 'Business listing management',
    subtitle: 'Track submission status and coordinate with tourism operators',
  },
  community: {
    title: 'Community moderation',
    subtitle: 'Keep discussions respectful and surface potential issues quickly',
  },
  notifications: {
    title: 'Notifications & outreach',
    subtitle: 'Send broadcasts and manage platform-wide announcements',
  },
  reports: {
    title: 'System reports',
    subtitle: 'Generate analytics snapshots for stakeholders',
  },
  settings: {
    title: 'Platform settings',
    subtitle: 'Configure policies, integrations, and audit preferences',
  },
}

const summaryCards = [
  { key: 'travelers', label: 'Active travelers', value: '1,284', trendLabel: '+8.4% vs last month', trendType: 'success' },
  { key: 'operators', label: 'Verified operators', value: '312', trendLabel: '+12 this week', trendType: 'success' },
  { key: 'itineraries', label: 'Shared itineraries', value: '485', trendLabel: '23 awaiting review', trendType: 'warning' },
  { key: 'reports', label: 'Open reports', value: '9', trendLabel: '2 critical items', trendType: 'error' },
]

const verificationProgress = { completed: 42, total: 60 }

const pendingApprovals = ref([
  {
    key: 1,
    company: 'Green Trails Sdn Bhd',
    contact: 'Aisyah Rahman',
    email: 'aisyah@greentrails.my',
    submitted: '25 Oct 2025',
    status: 'Background check',
  },
  {
    key: 2,
    company: 'Borneo Eco Dive',
    contact: 'Daniel Lim',
    email: 'daniel@borneo.eco',
    submitted: '24 Oct 2025',
    status: 'Awaiting documents',
  },
  {
    key: 3,
    company: 'Penang Heritage Walks',
    contact: 'Mei Ling Tan',
    email: 'meiling@heritagewalks.my',
    submitted: '24 Oct 2025',
    status: 'Ready for call',
  },
])

const activityItems = ref([])
const activityLoading = ref(false)
const activityError = ref('')
const activityLastFetched = ref(null)
let activityRefreshHandle = null

const quickActions = [
  { key: 'create-report', label: 'Generate system report', type: 'primary' },
  { key: 'review-guidelines', label: 'Update operator checklist', type: 'default' },
  { key: 'broadcast', label: 'Send sustainability bulletin', type: 'default' },
]

function capitalize(value) {
  if (typeof value !== 'string' || value.length === 0) {
    return ''
  }
  return value.charAt(0).toUpperCase() + value.slice(1)
}

function truncate(value, length = 140) {
  if (typeof value !== 'string') return ''
  const trimmed = value.trim()
  if (trimmed.length <= length) return trimmed
  return `${trimmed.slice(0, length - 1)}…`
}

function formatRelativeTime(value) {
  if (!value) return ''
  const date = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  const now = Date.now()
  const diffMs = now - date.getTime()
  const future = diffMs < 0
  const abs = Math.abs(diffMs)
  const minute = 60 * 1000
  const hour = 60 * minute
  const day = 24 * hour
  const week = 7 * day
  if (abs < 45 * 1000) {
    return future ? 'in moments' : 'just now'
  }
  let count
  let unit
  if (abs < hour) {
    count = Math.round(abs / minute)
    unit = 'm'
  } else if (abs < day) {
    count = Math.round(abs / hour)
    unit = 'h'
  } else if (abs < week) {
    count = Math.round(abs / day)
    unit = 'd'
  } else {
    const dateLabel = date.toLocaleDateString()
    const timeLabel = date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
    return `${dateLabel} ${timeLabel}`
  }
  const label = `${count}${unit}`
  return future ? `in ${label}` : `${label} ago`
}

function safeDate(value) {
  if (!value) return null
  const date = value instanceof Date ? value : new Date(value)
  return Number.isNaN(date.getTime()) ? null : date
}

function mapCommunityStoryActivity(record) {
  const author = record?.author ?? {}
  const typeLabel = capitalize(author.type || 'Community')
  const name = author.name || author.username || (author.id ? `User #${author.id}` : 'Member')
  const actor = `${typeLabel}${name ? ` - ${name}` : ''}`
  const description = `Posted a new ${record?.mediaType === 'video' ? 'community video' : 'community story'}.`
  const timestampSeconds =
    record?.createdTimestamp ??
    record?.timeline?.createdTimestamp ??
    record?.updatedTimestamp ??
    record?.timeline?.updatedTimestamp ??
    null
  const timestamp =
    (typeof timestampSeconds === 'number' && Number.isFinite(timestampSeconds) ? new Date(timestampSeconds * 1000) : null) ||
    safeDate(record?.createdAt) ||
    safeDate(record?.updatedAt) ||
    safeDate(record?.timeline?.created) ||
    safeDate(record?.timeline?.updated) ||
    new Date()
  return {
    id: `story-${record?.id ?? Math.random()}`,
    actor,
    description,
    timestamp,
    category: 'Community story',
    rawTimestamp: timestamp,
  }
}

function mapBusinessListingActivity(listing) {
  const operatorInfo = listing?.operator ?? listing?.operatorInfo ?? {}
  const operatorName =
    operatorInfo.name ||
    operatorInfo.email ||
    listing?.operatorName ||
    listing?.operatorEmail ||
    `Operator #${operatorInfo.id ?? listing?.operatorID ?? 'N/A'}`
  const actor = `Operator - ${operatorName}`
  const status = String(listing?.status || 'Pending Review').toLowerCase()
  const displayName = listing?.businessName ? `"${listing.businessName}"` : 'a business listing'
  let description
  if (status.includes('approve') || status.includes('active')) {
    description = `Listing ${displayName} was approved.`
  } else if (status.includes('reject')) {
    description = `Listing ${displayName} was rejected.`
  } else {
    description = `Submitted listing ${displayName} for review.`
  }
  const timestampSeconds =
    listing?.submittedTimestamp ??
    listing?.latestVerification?.verifiedTimestamp ??
    null
  const timestamp =
    (typeof timestampSeconds === 'number' && Number.isFinite(timestampSeconds) ? new Date(timestampSeconds * 1000) : null) ||
    safeDate(listing?.submittedDate) ||
    safeDate(listing?.verifiedDate) ||
    new Date()
  return {
    id: `listing-${listing?.listingID ?? Math.random()}`,
    actor,
    description,
    timestamp,
    category: 'Business listing',
    rawTimestamp: timestamp,
  }
}

async function fetchCommunityActivity() {
  const response = await fetch(`${API_BASE}/admin/community_moderation.php?limit=${ACTIVITY_FETCH_LIMIT}`)
  if (!response.ok) {
    throw new Error('Unable to load community posts')
  }
  const body = await response.json().catch(() => null)
  const posts = Array.isArray(body?.posts) ? body.posts : []
  return posts.map(mapCommunityStoryActivity)
}

async function fetchBusinessActivity() {
  const response = await fetch(`${API_BASE}/admin/business_listings.php`)
  if (!response.ok) {
    throw new Error('Unable to load business listings')
  }
  const body = await response.json().catch(() => null)
  const listings = Array.isArray(body?.listings) ? body.listings : []
  return listings
    .map(mapBusinessListingActivity)
    .sort((a, b) => (b.timestamp ?? 0) - (a.timestamp ?? 0))
    .slice(0, ACTIVITY_FETCH_LIMIT)
}

async function loadActivityStream(options = {}) {
  const silent = Boolean(options?.silent)
  if (activityLoading.value && silent) {
    return
  }
  activityLoading.value = !silent
  if (!silent) {
    activityError.value = ''
  }
  try {
    const results = await Promise.allSettled([fetchCommunityActivity(), fetchBusinessActivity()])
    const aggregated = []
    const errors = []
    results.forEach((result) => {
      if (result.status === 'fulfilled') {
    aggregated.push(...result.value)
  } else if (result.reason) {
    errors.push(result.reason instanceof Error ? result.reason.message : String(result.reason))
  }
})
    if (errors.length) {
      activityError.value = errors.join(' ')
      if (!silent) {
        message.warning(activityError.value)
      }
    } else {
      activityError.value = ''
    }
    aggregated.sort((a, b) => (b.timestamp ?? 0) - (a.timestamp ?? 0))

    const firstWithTime = aggregated.find((entry) => entry.timestamp instanceof Date)
    if (firstWithTime && firstWithTime.timestamp) {
      const drift = firstWithTime.timestamp.getTime() - Date.now()
      if (drift > 60 * 60 * 1000) {
        aggregated.forEach((entry) => {
          if (entry.timestamp instanceof Date) {
            entry.timestamp = new Date(entry.timestamp.getTime() - drift)
          }
        })
      }
    }

    activityItems.value = aggregated.slice(0, ACTIVITY_FETCH_LIMIT).map((item) => {
      const displayTimestamp = item.timestamp
        ? item.timestamp.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })
        : ''
      return {
        ...item,
        displayTimestamp: displayTimestamp || formatRelativeTime(item.timestamp),
      }
    })
    activityLastFetched.value = new Date()
  } catch (error) {
    const messageText = error instanceof Error ? error.message : 'Unable to load community activity stream.'
    activityError.value = messageText
    activityItems.value = []
    if (!silent) {
      message.error(messageText)
    }
  } finally {
    activityLoading.value = false
  }
}

const activityLastUpdatedLabel = computed(() =>
  activityLastFetched.value ? formatRelativeTime(activityLastFetched.value) : '',
)

onMounted(() => {
  loadActivityStream()
  if (typeof window !== 'undefined') {
    activityRefreshHandle = window.setInterval(
      () => loadActivityStream({ silent: true }),
      ACTIVITY_REFRESH_INTERVAL,
    )
  }
})

onBeforeUnmount(() => {
  if (activityRefreshHandle) {
    window.clearInterval(activityRefreshHandle)
    activityRefreshHandle = null
  }
})

const approvalColumns = [
  { title: 'Company', key: 'company' },
  { title: 'Primary contact', key: 'contact' },
  { title: 'Email', key: 'email' },
  { title: 'Submitted', key: 'submitted' },
  {
    title: 'Status',
    key: 'status',
    render(row) {
      const type = row.status.includes('Ready') ? 'success' : 'warning'
      return h(NTag, { size: 'small', type, bordered: false }, { default: () => row.status })
    },
  },
  {
    title: 'Action',
    key: 'actions',
    render() {
      return h(NSpace, { size: 'small' }, () => [
        h(NButton, { size: 'small', tertiary: true, type: 'primary' }, { default: () => 'Review' }),
        h(NButton, { size: 'small', quaternary: true }, { default: () => 'Message' }),
      ])
    },
  },
]

const activeModule = ref('overview')
const activeModuleMeta = computed(() => moduleMeta[activeModule.value] ?? moduleMeta.overview)
const userModuleRef = ref(null)

function handleMenuSelect(key) {
  activeModule.value = key
}

const headerButtons = computed(() => {
  switch (activeModule.value) {
    case 'overview':
      return [
        { key: 'draft-announcement', label: 'Draft announcement', tertiary: true },
        { key: 'schedule-review', label: 'Schedule review', type: 'primary' },
      ]
    case 'users': {
      const refreshing = userModuleRef.value?.refreshingSessions?.value ?? false
      return [
        {
          key: 'add-user',
          label: 'Add user',
          type: 'primary',
          onClick: () => userModuleRef.value?.openUserModal?.(),
        },
        {
          key: 'refresh-sessions',
          label: 'Refresh sessions',
          tertiary: true,
          loading: refreshing,
          disabled: refreshing,
          onClick: () => userModuleRef.value?.refreshSessions?.(),
        },
      ]
    }
    default:
      return []
  }
})
</script>

<template>
  <n-layout id="admin-top" has-sider style="min-height: 100vh;">
    <n-layout-sider bordered collapse-mode="width" :collapsed-width="64" :width="240" show-trigger="bar">
      <n-space vertical size="small" style="padding: 18px 16px;">
        <n-space align="center" size="small">
          <n-avatar round size="large" :src="adminProfile.avatarUrl || undefined"
            :style="adminProfile.avatarUrl ? undefined : avatarFallbackStyle">
            <template v-if="!adminProfile.avatarUrl">{{ adminProfile.initials }}</template>
          </n-avatar>
          <div>
            <n-gradient-text type="success" style="font-size: 1.15rem; font-weight: 600;">
              {{ adminProfile.displayName }}
            </n-gradient-text>
            <n-text v-if="adminProfile.email" depth="3">{{ adminProfile.email }}</n-text>
          </div>
        </n-space>
        <n-text depth="3">Manage modules</n-text>
      </n-space>
      <div style="padding: 0 8px;">
        <n-menu :options="menuOptions" :value="activeModule" :indent="18" :collapsed-icon-size="20"
          @update:value="handleMenuSelect" />
      </div>
    </n-layout-sider>

    <n-layout>
      <n-layout-header bordered style="padding: 20px 28px;">
        <n-page-header :title="activeModuleMeta.title" :subtitle="activeModuleMeta.subtitle">
          <template #extra>
            <n-space>
              <n-button v-for="action in headerButtons" :key="action.key" :type="action.type ?? 'default'"
                :tertiary="action.tertiary" :loading="action.loading" :disabled="action.disabled"
                @click="action.onClick ? action.onClick() : null">
                {{ action.label }}
              </n-button>
            </n-space>
          </template>
        </n-page-header>
      </n-layout-header>

      <n-layout-content embedded style="padding: 24px;">
        <template v-if="activeModule === 'overview'">
          <n-space vertical size="large">
            <n-grid cols="1 m:2 l:4" :x-gap="16" :y-gap="16">
              <n-grid-item v-for="card in summaryCards" :key="card.key">
                <n-card size="medium" :segmented="{ content: true, footer: false }">
                  <n-space vertical size="small">
                    <n-text depth="3">{{ card.label }}</n-text>
                    <span style="font-size: 2rem; font-weight: 600;">{{ card.value }}</span>
                    <n-tag :type="card.trendType" size="small" bordered>{{ card.trendLabel }}</n-tag>
                  </n-space>
                </n-card>
              </n-grid-item>
            </n-grid>

            <n-grid cols="1 m:3" :x-gap="16" :y-gap="16">
              <n-grid-item span="1 m:2">
                <n-card title="Pending operator approvals" :segmented="{ content: true }">
                  <n-data-table size="small" :columns="approvalColumns" :data="pendingApprovals" :bordered="false" />
                </n-card>
              </n-grid-item>
              <n-grid-item>
                <n-space vertical size="large">
                  <n-card title="Verification throughput" :segmented="{ content: true }">
                    <n-space vertical size="small">
                      <span style="font-size: 2.2rem; font-weight: 600;">
                        {{ Math.round((verificationProgress.completed / verificationProgress.total) * 100) }}%
                      </span>
                      <n-text depth="3">
                        {{ verificationProgress.completed }} of {{ verificationProgress.total }} check-ins completed
                        this week
                      </n-text>
                      <n-progress type="line"
                        :percentage="Math.round((verificationProgress.completed / verificationProgress.total) * 100)"
                        indicator-placement="inside" processing />
                    </n-space>
                  </n-card>
                  <n-card title="Quick actions" :segmented="{ content: true }">
                    <n-space vertical>
                      <n-button v-for="action in quickActions" :key="action.key" :type="action.type" block>
                        {{ action.label }}
                      </n-button>
                    </n-space>
                  </n-card>
                </n-space>
              </n-grid-item>
            </n-grid>

            <n-card title="Community activity stream" :segmented="{ content: true }">
              <template #header-extra>
                <n-space align="center" size="small">
                  <n-text
                    v-if="activityLastUpdatedLabel"
                    depth="3"
                    style="font-size: 0.85rem;"
                  >
                    Updated {{ activityLastUpdatedLabel }}
                  </n-text>
                  <n-button
                    size="small"
                    quaternary
                    :loading="activityLoading"
                    @click="loadActivityStream()"
                  >
                    Refresh
                  </n-button>
                </n-space>
              </template>
              <n-spin :show="activityLoading">
                <n-alert
                  v-if="activityError"
                  type="error"
                  style="margin-bottom: 12px;"
                  closable
                  @close="activityError = ''"
                >
                  {{ activityError }}
                </n-alert>
                <n-empty
                  v-if="!activityLoading && !activityError && !activityItems.length"
                  description="No recent community activity recorded."
                />
                <template v-else-if="activityItems.length">
                  <n-list bordered :show-divider="false">
                    <n-list-item v-for="item in activityItems" :key="item.id">
                      <n-space justify="space-between" align="center" style="width: 100%;">
                        <n-space vertical size="small">
                          <span style="font-weight: 600;">
                            {{ item.actor }}
                            <n-tag v-if="item.category" size="tiny" type="info" style="margin-left: 8px;" :bordered="false">
                              {{ item.category }}
                            </n-tag>
                          </span>
                          <n-text depth="3">{{ item.description }}</n-text>
                        </n-space>
                        <n-text depth="3" style="font-size: 0.95rem;">
                          {{ item.displayTimestamp }}
                        </n-text>
                      </n-space>
                    </n-list-item>
                  </n-list>
                </template>
              </n-spin>
            </n-card>
          </n-space>
        </template>

        <template v-else-if="activeModule === 'verification'">
          <AdminListingVerification :admin-id="props.currentAdminId" />
        </template>

        <template v-else-if="activeModule === 'business'">
          <AdminBusinessListing :admin-id="props.currentAdminId" />
        </template>

        <template v-else-if="activeModule === 'community'">
          <AdminCommunityModeration :admin-id="props.currentAdminId" />
        </template>

        <template v-else-if="activeModule === 'notifications'">
          <AdminNotificationOutbox />
        </template>

        <template v-else-if="activeModule === 'users'">
          <UserAndRole ref="userModuleRef" :current-admin-id="props.currentAdminId" />
        </template>

        <template v-else>
          <n-card title="Module workspace" :segmented="{ content: true }">
            <n-space vertical size="large">
              <n-text depth="3">Select a module from the sidebar to begin.</n-text>
              <n-space>
                <n-button tertiary type="primary" @click="handleMenuSelect('overview')">Return to overview</n-button>
              </n-space>
            </n-space>
          </n-card>
        </template>
      </n-layout-content>
    </n-layout>
  </n-layout>
</template>
