<script setup>
import { computed, h, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { NAvatar, NButton, NSpace, NTag, NText, NIcon, NEmpty, useMessage } from 'naive-ui'
import AdminBusinessListing from './admin/AdminBusinessListing.vue'
import AdminCommunityModeration from './admin/AdminCommunityModeration.vue'
import AdminListingVerification from './admin/AdminListingVerification.vue'
import { extractProfileImage } from '../utils/profileImage.js'
import { RefreshOutline } from '@vicons/ionicons5'

const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const avatarFallbackStyle = {
  background: 'var(--primary-color-hover)',
  color: 'white',
}

function deriveAvatarInfo(source) {
  return extractProfileImage(source)
}


const message = useMessage()
const SESSION_HISTORY_LIMIT = 5

function formatDateTime(value) {
  if (!value) return null
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return null
  }
  try {
    return new Intl.DateTimeFormat(undefined, {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(date)
  } catch {
    return date.toLocaleString()
  }
}

function formatDuration(milliseconds) {
  if (!Number.isFinite(milliseconds) || milliseconds < 0) {
    return null
  }
  const totalSeconds = Math.floor(milliseconds / 1000)
  const days = Math.floor(totalSeconds / 86400)
  const hours = Math.floor((totalSeconds % 86400) / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)
  const seconds = totalSeconds % 60
  const pad = (value) => String(value).padStart(2, '0')
  if (days > 0) {
    return `${days}d ${pad(hours)}:${pad(minutes)}:${pad(seconds)}`
  }
  if (hours > 0) {
    return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`
  }
  return `${pad(minutes)}:${pad(seconds)}`
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

const recentActivities = [
  { id: 1, actor: 'Traveler - Yap Wei Hoong', description: 'reported inaccurate listing data', time: '4m ago' },
  { id: 2, actor: 'Operator - Green Trails', description: 'submitted sustainability audit', time: '32m ago' },
  { id: 3, actor: 'Traveler - Arif Hussain', description: 'published itinerary "Eco Sabah"', time: '1h ago' },
  { id: 4, actor: 'Operator - Penang Heritage', description: 'requested verification call', time: '2h ago' },
]

const quickActions = [
  { key: 'create-report', label: 'Generate system report', type: 'primary' },
  { key: 'review-guidelines', label: 'Update operator checklist', type: 'default' },
  { key: 'broadcast', label: 'Send sustainability bulletin', type: 'default' },
]

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

const sessionHistoryColumns = [
  {
    title: 'User',
    key: 'user',
    minWidth: 220,
    render(row) {
      const lines = [
        h(NText, { strong: true }, { default: () => row.name }),
      ]
      if (row.email) {
        lines.push(
          h(
            NText,
            { depth: 3, style: 'font-size: 0.85rem;' },
            { default: () => row.email },
          ),
        )
      }
      lines.push(
        h(
          NText,
          { depth: 3, style: 'font-size: 0.75rem; color: #6b7280;' },
          { default: () => row.role },
        ),
      )
      return h('div', { style: 'display:flex;flex-direction:column;gap:0.25rem;' }, lines)
    },
  },
  {
    title: 'Login time',
    key: 'loginDisplay',
    minWidth: 160,
    render(row) {
      return h(NText, { depth: 3 }, { default: () => row.loginDisplay || '--' })
    },
  },
  {
    title: 'Logout time',
    key: 'logoutDisplay',
    minWidth: 160,
    render(row) {
      return h(NText, { depth: 3 }, { default: () => row.logoutDisplay || '--' })
    },
  },
  {
    title: 'Duration',
    key: 'durationDisplay',
    minWidth: 120,
    render(row) {
      return h(
        NText,
        { depth: 3, style: 'font-variant-numeric: tabular-nums;' },
        { default: () => row.durationDisplay || '--' },
      )
    },
  },
]

const activeModule = ref('overview')
const activeModuleMeta = computed(() => moduleMeta[activeModule.value] ?? moduleMeta.overview)

function handleMenuSelect(key) {
  activeModule.value = key
}

const users = ref([])
const roles = ref([])
const loadingUsers = ref(false)
const loadingRoles = ref(false)
const savingUser = ref(false)
const userSearchTerm = ref('')
const userTypeFilter = ref('all')
const statusFilter = ref('all')
const page = ref(1)
const pageSize = 10

const sessionTicker = ref(Date.now())
let sessionTickerHandle = null

onMounted(() => {
  if (typeof window !== 'undefined') {
    sessionTickerHandle = window.setInterval(() => {
      sessionTicker.value = Date.now()
    }, 1000)
  }
})

onBeforeUnmount(() => {
  if (sessionTickerHandle) {
    window.clearInterval(sessionTickerHandle)
    sessionTickerHandle = null
  }
})

const userTypeFilterOptions = [
  { label: 'All types', value: 'all' },
  { label: 'Traveler', value: 'Traveler' },
  { label: 'Operator', value: 'Operator' },
  { label: 'Admin', value: 'Admin' },
]

const statusFilterOptions = [
  { label: 'All statuses', value: 'all' },
  { label: 'Pending', value: 'Pending' },
  { label: 'Active', value: 'Active' },
  { label: 'Suspended', value: 'Suspended' },
  { label: 'Inactive', value: 'Inactive' },
]

const filteredUsers = computed(() => {
  const term = userSearchTerm.value.trim().toLowerCase()
  return users.value.filter((user) => {
    const matchesName = term === '' || (user.name || '').toLowerCase().includes(term)
    const matchesType = userTypeFilter.value === 'all' || user.type === userTypeFilter.value
    const matchesStatus = statusFilter.value === 'all' || user.status === statusFilter.value
    return matchesName && matchesType && matchesStatus
  })
})

const pageCount = computed(() => Math.max(1, Math.ceil(filteredUsers.value.length / pageSize)))
const paginatedUsers = computed(() => {
  const start = (page.value - 1) * pageSize
  return filteredUsers.value.slice(start, start + pageSize)
})

const refreshingSessions = ref(false)

async function refreshSessions() {
  if (refreshingSessions.value) return
  refreshingSessions.value = true
  try {
    await fetchUsers()
  } finally {
    refreshingSessions.value = false
  }
}

const loginActivityRows = computed(() => {
  const now = sessionTicker.value
  return (users.value ?? [])
    .map((user) => {
      const loginAt = user.lastLoginAt ? new Date(user.lastLoginAt) : null
      const hasLoginRecord = !!loginAt && !Number.isNaN(loginAt.getTime())
      const isActive = Boolean(user.activeSession === true && hasLoginRecord)
      if (!isActive) {
        return null
      }
      const elapsedMs = hasLoginRecord ? Math.max(0, now - loginAt.getTime()) : 0
      const durationSeconds = Math.floor(elapsedMs / 1000)
      const durationLabel = formatDuration(durationSeconds * 1000) ?? '00:00'
      return {
        key: `${user.type ?? 'user'}-${user.id ?? 'unknown'}-${user.lastLoginAt ?? 'none'}`,
        name: user.name ?? 'Unknown user',
        email: user.email ?? '',
        role: user.role ?? user.type ?? 'User',
        loginDisplay: hasLoginRecord ? formatDateTime(loginAt) ?? '--' : '--',
        statusLabel: 'Active now',
        statusType: 'success',
        durationLabel,
        durationSeconds,
        ipAddress: user.lastIpAddress ?? null,
        deviceInfo: user.lastDeviceInfo ?? null,
        loginTimestampValue: hasLoginRecord ? loginAt.getTime() : 0,
      }
    })
    .filter((row) => row !== null)
    .sort((a, b) => (b.loginTimestampValue ?? 0) - (a.loginTimestampValue ?? 0))
})

const loginActivitySummary = computed(() => ({
  active: loginActivityRows.value.length,
}))

const sessionHistoryRows = computed(() => {
  const rows = []
  const usersList = users.value ?? []
  usersList.forEach((user) => {
    const history = Array.isArray(user.sessionHistory) ? user.sessionHistory : []
    history.forEach((entry) => {
      const loginAt = entry?.loginTimestamp ? new Date(entry.loginTimestamp) : null
      const logoutAt = entry?.logoutTimestamp ? new Date(entry.logoutTimestamp) : null
      const durationSeconds =
        entry?.durationSeconds !== null && entry?.durationSeconds !== undefined
          ? Number(entry.durationSeconds)
          : loginAt && logoutAt
            ? Math.max(0, Math.floor((logoutAt.getTime() - loginAt.getTime()) / 1000))
            : null
      rows.push({
        key: `${user.type ?? 'user'}-${user.id ?? 'unknown'}-history-${entry?.logId ?? entry?.logID ?? entry?.loginTimestamp ?? Math.random()}`,
        name: user.name ?? 'Unknown user',
        email: user.email ?? '',
        role: user.role ?? user.type ?? 'User',
        loginDisplay: loginAt ? formatDateTime(loginAt) ?? '--' : '--',
        logoutDisplay: logoutAt ? formatDateTime(logoutAt) ?? '--' : '--',
        durationDisplay:
          durationSeconds !== null ? formatDuration(durationSeconds * 1000) ?? '--' : '--',
        durationSeconds,
        loginTimestampValue: loginAt ? loginAt.getTime() : 0,
      })
    })
  })
  return rows.sort((a, b) => (b.loginTimestampValue ?? 0) - (a.loginTimestampValue ?? 0))
})
const statusOptions = computed(() => {
  if (userForm.type === 'Traveler' || userForm.type === 'Operator') {
    const options = [
      { label: 'Active', value: 'Active' },
      { label: 'Suspended', value: 'Suspended' },
    ]
    if (userForm.status === 'Pending') {
      options.unshift({ label: 'Pending', value: 'Pending', disabled: true })
    }
    return options
  }
  return [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
  ]
})

const createUserTypeOptions = [
  { label: 'Traveler', value: 'Traveler' },
  { label: 'Operator', value: 'Operator' },
]

const roleOptions = computed(() => roles.value.map((role) => ({ label: role.name, value: role.name })))

const headerButtons = computed(() => {
  switch (activeModule.value) {
    case 'overview':
      return [
        { key: 'draft-announcement', label: 'Draft announcement', tertiary: true },
        { key: 'schedule-review', label: 'Schedule review', type: 'primary' },
      ]
    case 'users':
      return [
        { key: 'add-user', label: 'Add user', type: 'primary', onClick: () => openUserModal() },
        {
          key: 'refresh-sessions',
          label: 'Refresh sessions',
          tertiary: true,
          loading: refreshingSessions.value,
          disabled: refreshingSessions.value,
          onClick: () => refreshSessions(),
        },
      ]
    default:
      return [{ key: 'configure', label: 'Configure module', type: 'primary' }]
  }
})

const userColumns = [
  { title: 'Name', key: 'name' },
  { title: 'Email', key: 'email' },
  { title: 'Type / Role', key: 'role' },
  {
    title: 'Status',
    key: 'status',
    render(row) {
      const type = row.status === 'Active' ? 'success' : row.status === 'Pending' ? 'warning' : row.status === 'Suspended' ? 'error' : 'default'
      return h(NTag, { size: 'small', type, bordered: false }, { default: () => row.status })
    },
  },
  {
    title: 'Actions',
    key: 'actions',
    render(row) {
      const buttons = [
        h(
          NButton,
          {
            size: 'small',
            tertiary: true,
            type: 'primary',
            onClick: () => openUserModal(row),
          },
          { default: () => 'Edit' },
        ),
      ]
      buttons.push(
        h(
          NButton,
          {
            size: 'small',
            quaternary: true,
            type: 'error',
            onClick: () => removeUser(row),
          },
          { default: () => 'Remove' },
        ),
      )
      return h(NSpace, { size: 'small' }, () => buttons)
    },
  },
]

const roleColumns = [
  { title: 'Role name', key: 'name' },
  {
    title: 'Members',
    key: 'members',
    render(row) {
      return h(NText, { strong: true }, { default: () => row.members })
    },
  },
  {
    title: 'Description',
    key: 'description',
    render(row) {
      const summary = row.description && row.description.trim().length > 0 ? row.description : 'No custom permissions'
      return h(
        NText,
        { depth: 3 },
        { default: () => summary },
      )
    },
  },
]

const loginActivityColumns = [
  {
    title: 'User',
    key: 'user',
    minWidth: 220,
    render(row) {
      const lines = [
        h(NText, { strong: true }, { default: () => row.name }),
      ]
      if (row.email) {
        lines.push(
          h(
            NText,
            { depth: 3, style: 'font-size: 0.85rem;' },
            { default: () => row.email },
          ),
        )
      }
      lines.push(
        h(
          NText,
          { depth: 3, style: 'font-size: 0.75rem; color: #6b7280;' },
          { default: () => row.role },
        ),
      )
      return h('div', { style: 'display:flex;flex-direction:column;gap:0.25rem;' }, lines)
    },
  },
  {
    title: 'Login time',
    key: 'loginDisplay',
    minWidth: 160,
    render(row) {
      return h(NText, { depth: 3 }, { default: () => row.loginDisplay || '--' })
    },
  },
  {
    title: 'Session',
    key: 'session',
    minWidth: 200,
    render(row) {
      const nodes = [
        h(NTag, { size: 'small', type: row.statusType, bordered: false }, { default: () => row.statusLabel }),
      ]
      nodes.push(
        h('span', { key: row.durationSeconds, class: 'duration-ticker' }, [
          h(
            NText,
            { depth: 3, style: 'font-size: 0.85rem;' },
            { default: () => `Active for ${row.durationLabel}` },
          ),
        ]),
      )
      return h('div', { style: 'display:flex;flex-direction:column;gap:0.25rem;' }, nodes)
    },
  },
  {
    title: 'Device & IP',
    key: 'device',
    minWidth: 200,
    render(row) {
      const items = []
      if (row.deviceInfo) {
        items.push(
          h(NText, { depth: 3 }, { default: () => row.deviceInfo }),
        )
      }
      if (row.ipAddress) {
        items.push(
          h(
            NText,
            { depth: 3, style: 'font-size: 0.8rem; color: #6b7280;' },
            { default: () => row.ipAddress },
          ),
        )
      }
      if (!items.length) {
        items.push(h(NText, { depth: 3 }, { default: () => '--' }))
      }
      return h('div', { style: 'display:flex;flex-direction:column;gap:0.25rem;' }, items)
    },
  },
]
const showUserModal = ref(false)
const editingUserId = ref(null)
const userForm = reactive({
  name: '',
  email: '',
  role: 'Traveler',
  status: 'Pending',
  type: 'Traveler',
  phone: '',
  businessType: '',
  password: '',
  confirmPassword: '',
})

function resetUserForm() {
  userForm.name = ''
  userForm.email = ''
  userForm.role = 'Traveler'
  userForm.status = 'Pending'
  userForm.type = 'Traveler'
  userForm.phone = ''
  userForm.businessType = ''
  userForm.password = ''
  userForm.confirmPassword = ''
  editingUserId.value = null
}

function openUserModal(row) {
  if (row) {
    editingUserId.value = row.id
    userForm.name = row.name
    userForm.email = row.email
    userForm.role = row.role
    userForm.status = row.status
    userForm.type = row.type
    userForm.phone = row.phone || ''
    userForm.businessType = row.businessType || ''
    userForm.password = ''
    userForm.confirmPassword = ''
  } else {
    resetUserForm()
  }
  showUserModal.value = true
}

async function fetchRoles() {
  loadingRoles.value = true
  try {
    const response = await fetch(`${API_BASE}/admin/roles.php`)
    const data = await response.json().catch(() => null)
    if (!response.ok || !data?.roles) {
      throw new Error(data?.error || 'Unable to load roles')
    }
    roles.value = data.roles
  } catch (error) {
    console.error(error)
    message.error(error instanceof Error ? error.message : 'Unable to load roles')
  } finally {
    loadingRoles.value = false
  }
}

async function fetchUsers() {
  loadingUsers.value = true
  try {
    const response = await fetch(`${API_BASE}/admin/users.php`)
    const data = await response.json().catch(() => null)
    if (!response.ok || !data?.users) {
      throw new Error(data?.error || 'Unable to load users')
    }
    users.value = data.users
  } catch (error) {
    console.error(error)
    message.error(error instanceof Error ? error.message : 'Unable to load users')
  } finally {
    loadingUsers.value = false
  }
}

async function saveUser() {
  if (!userForm.name.trim() || !userForm.email.trim()) {
    return
  }

  const passwordValue = userForm.password.trim()
  const confirmValue = userForm.confirmPassword.trim()
  const passwordProvided = passwordValue !== ''

  if (!editingUserId.value || passwordProvided) {
    if (passwordValue.length < 6) {
      message.error('Password must be at least 6 characters')
      return
    }
    if (passwordValue !== confirmValue) {
      message.error('Passwords do not match')
      return
    }
  }

  savingUser.value = true
  try {
    const payload = {
      id: editingUserId.value,
      type: userForm.type,
      name: userForm.name.trim(),
      email: userForm.email.trim(),
      status: userForm.status,
      role: userForm.type === 'Admin' ? userForm.role : undefined,
      phone: userForm.type !== 'Admin' ? userForm.phone.trim() : undefined,
      businessType: userForm.type === 'Operator' ? userForm.businessType.trim() : undefined,
      password: passwordProvided ? passwordValue : undefined,
    }

    const response = await fetch(`${API_BASE}/admin/users.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    const data = await response.json().catch(() => null)
    if (!response.ok || !data?.ok) {
      throw new Error(data?.error || 'Unable to save user')
    }
    showUserModal.value = false
    await fetchUsers()
    await fetchRoles()
    message.success('User saved')
    if (!editingUserId.value && data.temporaryPassword) {
      message.info(`Temporary password: ${data.temporaryPassword}`)
    }
    resetUserForm()
  } catch (error) {
    console.error(error)
    message.error(error instanceof Error ? error.message : 'Unable to save user')
  } finally {
    savingUser.value = false
  }
}

async function removeUser(row) {
  if (row.type === 'Admin' && props.currentAdminId && row.id === props.currentAdminId) {
    message.warning('You cannot remove your own administrator account')
    return
  }
  if (!confirm(`Remove this ${row.type.toLowerCase()} account? This action cannot be undone.`)) {
    return
  }
  try {
    const response = await fetch(`${API_BASE}/admin/users.php`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: row.id, type: row.type }),
    })
    const data = await response.json().catch(() => null)
    if (!response.ok || !data?.ok) {
      throw new Error(data?.error || 'Unable to remove user')
    }
    await fetchUsers()
    await fetchRoles()
    message.success('User removed')
  } catch (error) {
    console.error(error)
    message.error(error instanceof Error ? error.message : 'Unable to remove user')
  }
}

onMounted(async () => {
  await fetchRoles()
  await fetchUsers()
})

watch(roleOptions, (options) => {
  if (editingUserId.value) return
  if (userForm.type === 'Admin') {
    userForm.role = options[0]?.value || ''
  } else {
    userForm.role = userForm.type
  }
})

watch(
  () => userForm.type,
  (type) => {
    if (!editingUserId.value) {
      userForm.status = type === 'Traveler' || type === 'Operator' ? 'Pending' : 'Active'
      if (type !== 'Admin') {
        userForm.role = type
      } else {
        userForm.role = roleOptions.value[0]?.value || ''
      }
    } else if (type !== 'Admin') {
      userForm.role = type
    }
  },
)

watch([userSearchTerm, userTypeFilter, statusFilter], () => {
  page.value = 1
})

watch(filteredUsers, () => {
  if (page.value > pageCount.value) {
    page.value = pageCount.value
  }
})

watch(showUserModal, (visible) => {
  if (!visible) {
    resetUserForm()
  }
})

</script>

<template>
  <n-layout id="admin-top" has-sider style="min-height: 100vh;">
    <n-layout-sider bordered collapse-mode="width" :collapsed-width="64" :width="240" show-trigger="bar">
      <n-space vertical size="small" style="padding: 18px 16px;">
        <n-space align="center" size="small">
          <n-avatar
            round
            size="large"
            :src="adminProfile.avatarUrl || undefined"
            :style="adminProfile.avatarUrl ? undefined : avatarFallbackStyle"
          >
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
              <n-button
                v-for="action in headerButtons"
                :key="action.key"
                :type="action.type ?? 'default'"
                :tertiary="action.tertiary"
                :loading="action.loading"
                :disabled="action.disabled"
                @click="action.onClick ? action.onClick() : null"
              >
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
              <n-list bordered :show-divider="false">
                <n-list-item v-for="item in recentActivities" :key="item.id">
                  <n-space justify="space-between" align="center" style="width: 100%;">
                    <n-space vertical size="small">
                      <span style="font-weight: 600;">{{ item.actor }}</span>
                      <n-text depth="3">{{ item.description }}</n-text>
                    </n-space>
                    <n-text depth="3" style="font-size: 0.95rem;">{{ item.time }}</n-text>
                  </n-space>
                </n-list-item>
              </n-list>
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

        <template v-else-if="activeModule === 'users'">
          <n-space vertical size="large">
            <n-card title="Account directory" :segmented="{ content: true }">
              <template #header-extra>
                <n-space size="small">
                  <n-select v-model:value="userTypeFilter" size="small" :options="userTypeFilterOptions"
                    style="width: 150px;" />
                  <n-select v-model:value="statusFilter" size="small" :options="statusFilterOptions"
                    style="width: 150px;" />
                  <n-input v-model:value="userSearchTerm" size="small" clearable placeholder="Search name"
                    style="width: 220px;" />
                </n-space>
              </template>
              <n-space vertical size="medium">
                <n-data-table size="small" :columns="userColumns" :data="paginatedUsers" :bordered="false"
                  :loading="loadingUsers" />
                <n-space justify="space-between" align="center">
                  <n-text depth="3" style="font-size: 0.85rem;">
                    Showing
                    {{ filteredUsers.length === 0 ? 0 : (page - 1) * pageSize + 1 }}
                    -
                    {{ Math.min(page * pageSize, filteredUsers.length) }}
                    of {{ filteredUsers.length }}
                    users
                  </n-text>
                  <n-pagination v-model:page="page" :page-count="10" simple />
                </n-space>
              </n-space>
            </n-card>

            <n-card title="Role templates" :segmented="{ content: true }">

              <n-data-table size="small" :columns="roleColumns" :data="roles" :bordered="false"
                :loading="loadingRoles" />
            </n-card>

            <n-card title="Live login sessions" :segmented="{ content: true }">
              <template #header-extra>
                <n-space align="center" size="small">
                  <n-text depth="3" style="font-size: 0.85rem;">
                    {{ loginActivitySummary.active }} active {{ loginActivitySummary.active === 1 ? 'user' : 'users' }}
                  </n-text>
                  <n-button quaternary circle size="small" :loading="refreshingSessions" :disabled="refreshingSessions"
                    @click="refreshSessions" title="Refresh live sessions">
                    <n-icon :size="16">
                      <RefreshOutline />
                    </n-icon>
                  </n-button>
                </n-space>
              </template>

              <n-data-table
                size="small"
                :columns="loginActivityColumns"
                :data="loginActivityRows"
                :bordered="false"
                :loading="loadingUsers"
                :row-key="(row) => row.key"
              />

              <template #footer>
                <n-text depth="3" style="font-size: 0.75rem;">
                  Session counters refresh every second while this dashboard is open.
                </n-text>
              </template>
            </n-card>

            <n-card title="Recent session durations" :segmented="{ content: true }">
              <n-data-table
                size="small"
                :columns="sessionHistoryColumns"
                :data="sessionHistoryRows"
                :bordered="false"
                :loading="loadingUsers"
                :row-key="(row) => row.key"
              />
              <template #footer>
                <n-text depth="3" style="font-size: 0.75rem;">
                  Showing up to {{ SESSION_HISTORY_LIMIT }} recent completed sessions per user.
                </n-text>
              </template>
            </n-card>
          </n-space>

          <n-modal v-model:show="showUserModal" preset="card" :title="editingUserId ? 'Edit user' : 'Add user'"
            style="max-width: 480px; width: 100%;">
            <n-form label-placement="top">
              <n-form-item label="Account type">
                <template v-if="editingUserId">
                  <n-tag type="info" size="small">{{ userForm.type }}</n-tag>
                </template>
                <template v-else>
                  <n-select v-model:value="userForm.type" :options="createUserTypeOptions" />
                </template>
              </n-form-item>
              <n-form-item label="Full name">
                <n-input v-model:value="userForm.name" />
              </n-form-item>
              <n-form-item label="Email address">
                <n-input v-model:value="userForm.email" placeholder="***@example.com" />
              </n-form-item>
              <n-form-item v-if="userForm.type !== 'Admin'" label="Contact number (optional)">
                <n-input v-model:value="userForm.phone" placeholder="012-345 6789" />
              </n-form-item>
              <n-form-item v-if="userForm.type === 'Operator'" label="Business type">
                <n-input v-model:value="userForm.businessType" placeholder="Eco travel agency" />
              </n-form-item>
              <n-form-item :label="editingUserId ? 'New password (optional)' : 'Password'">
                <n-input v-model:value="userForm.password" type="password" placeholder="At least 6 characters" />
              </n-form-item>
              <n-form-item v-if="!editingUserId || userForm.password" label="Confirm password">
                <n-input v-model:value="userForm.confirmPassword" type="password" placeholder="Re-enter password" />
              </n-form-item>
              <n-form-item v-if="userForm.type === 'Admin'" label="Role">
                <n-select v-model:value="userForm.role" :options="roleOptions" placeholder="Select role" />
              </n-form-item>
              <n-form-item label="Status">
                <n-select v-model:value="userForm.status" :options="statusOptions" />
              </n-form-item>
            </n-form>
            <template #footer>
              <n-space justify="end">
                <n-button quaternary @click="showUserModal = false">Cancel</n-button>
                <n-button type="primary" :loading="savingUser" @click="saveUser">Save</n-button>
              </n-space>
            </template>
          </n-modal>

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

<style scoped>
.duration-ticker {
  display: inline-block;
  animation: ticker-pulse 0.9s ease-in-out;
}

@keyframes ticker-pulse {
  0% {
    opacity: 0.35;
    transform: translateY(4px);
  }
  50% {
    opacity: 1;
    transform: translateY(0);
  }
  100% {
    opacity: 0.9;
    transform: translateY(0);
  }
}
</style>





