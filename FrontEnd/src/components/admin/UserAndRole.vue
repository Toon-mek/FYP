<script setup>
import { computed, h, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { NButton, NRadioGroup, NRadioButton, NSpace, NTag, NText, useMessage } from 'naive-ui'
import PaginatedTable from '../shared/PaginatedTable.vue'
import { RefreshOutline } from '@vicons/ionicons5'
import { resolveRoleTagType } from './RoleTagTypes.js'

const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const SESSION_HISTORY_PAGE_SIZE = 5

const props = defineProps({
  currentAdminId: {
    type: Number,
    default: null,
  },
})

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

const message = useMessage()

function isActiveSessionFlag(source) {
  if (source === true) return true
  if (typeof source === 'number') {
    return Number.isFinite(source) ? source > 0 : false
  }
  if (typeof source === 'string') {
    const normalised = source.trim().toLowerCase()
    if (normalised === '') return false
    return ['1', 'true', 'yes', 'active', 'active now', 'online', 'present'].includes(normalised)
  }
  if (Array.isArray(source)) {
    return source.some((entry) => isActiveSessionFlag(entry))
  }
  if (source && typeof source === 'object') {
    if ('logoutTimestamp' in source && source.logoutTimestamp !== undefined) {
      return source.logoutTimestamp === null || source.logoutTimestamp === undefined || source.logoutTimestamp === ''
    }
    if ('endTimestamp' in source && source.endTimestamp !== undefined) {
      return source.endTimestamp === null || source.endTimestamp === undefined || source.endTimestamp === ''
    }
    if ('active' in source) return isActiveSessionFlag(source.active)
    if ('isActive' in source) return isActiveSessionFlag(source.isActive)
    if ('count' in source) return isActiveSessionFlag(source.count)
    if ('length' in source) return isActiveSessionFlag(source.length)
    if ('current' in source) return isActiveSessionFlag(source.current)
  }
  return false
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
  fetchRoles()
  fetchUsers()
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

function isUserActive(user, now = Date.now()) {
  if (!user) return false
  const loginAt = user.lastLoginAt ? new Date(user.lastLoginAt) : null
  const hasLoginRecord = !!loginAt && !Number.isNaN(loginAt.getTime())
  if (!hasLoginRecord) {
    return false
  }
  const hasOpenHistorySession =
    Array.isArray(user.sessionHistory) &&
    user.sessionHistory.some(
      (entry) =>
        entry &&
        (entry.logoutTimestamp === null ||
          entry.logoutTimestamp === undefined ||
          entry.logoutTimestamp === ''),
    )
  const statusCandidates = [
    user.activeSession,
    user.sessionActive,
    user.sessionStatus,
    user.currentSession,
    user.activeSessions,
    user.sessionState,
  ]
  return statusCandidates.some((value) => isActiveSessionFlag(value)) || hasOpenHistorySession || (now - loginAt.getTime() < 5 * 60 * 1000 && isActiveSessionFlag(user.status))
}

const loginActivityRows = computed(() => {
  const now = sessionTicker.value
  const rows = []
  const usersList = users.value ?? []
  usersList.forEach((user) => {
    if (!isUserActive(user, now)) {
      return
    }
    const loginAt = user.lastLoginAt ? new Date(user.lastLoginAt) : null
    const loginDisplay = loginAt && !Number.isNaN(loginAt.getTime()) ? formatDateTime(loginAt) ?? '--' : '--'
    const elapsedMs = loginAt ? Math.max(0, now - loginAt.getTime()) : 0
    const durationSeconds = Math.floor(elapsedMs / 1000)
    rows.push({
      key: `${user.type ?? 'user'}-${user.id ?? 'unknown'}-${user.lastLoginAt ?? 'none'}`,
      name: user.name ?? 'Unknown user',
      email: user.email ?? '',
      role: user.role ?? user.type ?? 'User',
      loginDisplay,
      statusLabel: 'Active now',
      statusType: 'success',
      durationLabel: formatDuration(durationSeconds * 1000) ?? '00:00',
      durationSeconds,
      ipAddress: user.lastIpAddress ?? null,
      deviceInfo: user.lastDeviceInfo ?? null,
      loginTimestampValue: loginAt ? loginAt.getTime() : 0,
    })
  })
  return rows.sort((a, b) => (b.loginTimestampValue ?? 0) - (a.loginTimestampValue ?? 0))
})

const activeUsersCount = computed(() => {
  const now = sessionTicker.value
  return (users.value ?? []).reduce((count, user) => (isUserActive(user, now) ? count + 1 : count), 0)
})

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

const statusRadioOptions = computed(() => {
  if (userForm.type === 'Traveler' || userForm.type === 'Operator') {
    const currentStatus = userFormStatus.value
    return [
      {
        label: 'Pending',
        value: 'Pending',
        disabled: currentStatus !== 'Pending', // Disable if not currently Pending
      },
      {
        label: 'Active',
        value: 'Active',
        disabled: false, // Always enabled
      },
      {
        label: 'Suspended',
        value: 'Suspended',
        disabled: currentStatus === 'Pending', // Disable if currently Pending
      },
    ]
  }
  return [
    { label: 'Active', value: 'Active', disabled: false },
    { label: 'Inactive', value: 'Inactive', disabled: false },
  ]
})

const createUserTypeOptions = [
  { label: 'Traveler', value: 'Traveler' },
  { label: 'Operator', value: 'Operator' },
]

const roleOptions = computed(() => roles.value.map((role) => ({ label: role.name, value: role.name })))

const userColumns = [
  { title: 'Name', key: 'name' },
  { title: 'Email', key: 'email' },
  {
    title: 'Type / Role',
    key: 'role',
    render(row) {
      const value = row.role || row.type || 'User'
      const tagType = resolveRoleTagType(value)
      return h(
        NTag,
        {
          size: 'small',
          bordered: false,
          type: tagType,
          style: 'text-transform: capitalize; font-weight: 600;',
        },
        { default: () => value },
      )
    },
  },
  {
    title: 'Status',
    key: 'status',
    render(row) {
      const statusValue = row.status || 'Unknown'
      const type = statusValue === 'Active' ? 'success' : statusValue === 'Pending' ? 'warning' : statusValue === 'Suspended' ? 'error' : 'default'
      return h(
        NTag,
        {
          key: `status-${row.id}-${statusValue}`,
          size: 'small',
          type,
          bordered: false,
          style: 'cursor: pointer;',
          onClick: () => openUserModal(row),
        },
        { default: () => statusValue },
      )
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

const showUserModal = ref(false)
const editingUserId = ref(null)
const userFormStatus = ref('Active')
const userForm = reactive({
  name: '',
  email: '',
  role: 'Traveler',
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
  userFormStatus.value = 'Active'
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
    userForm.name = row.name || ''
    userForm.email = row.email || ''
    userForm.role = row.role || row.type || 'Traveler'
    userFormStatus.value = row.status || null
    userForm.type = row.type || 'Traveler'
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
      status: userFormStatus.value,
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
      userFormStatus.value = 'Active'
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

watch(showUserModal, (visible) => {
  if (!visible) {
    resetUserForm()
  }
})

defineExpose({
  openUserModal,
  refreshSessions,
  refreshingSessions,
})
</script>

<template>
  <n-space vertical size="large">
    <n-card title="Account directory" :segmented="{ content: true }">
      <template #header-extra>
        <n-space size="small">
          <n-select v-model:value="userTypeFilter" size="small" :options="userTypeFilterOptions"
            style="width: 150px;" />
          <n-select v-model:value="statusFilter" size="small" :options="statusFilterOptions" style="width: 150px;" />
          <n-input v-model:value="userSearchTerm" size="small" clearable placeholder="Search name"
            style="width: 220px;" />
        </n-space>
      </template>
      <PaginatedTable v-model:page="page" :columns="userColumns" :rows="filteredUsers" :loading="loadingUsers"
        :page-size="pageSize" :row-key="(row) => `${row.id || 'no-id'}-${row.email || 'no-email'}`"
        :table-props="{ bordered: false, size: 'small' }">
        <template #range="{ start, end, total }">
          <n-text depth="3" style="font-size: 0.85rem;">
            Showing {{ total === 0 ? 0 : start }}
            -
            {{ total === 0 ? 0 : end }}
            of {{ total }}
            users
          </n-text>
        </template>
      </PaginatedTable>
    </n-card>

    <n-card title="Role templates" :segmented="{ content: true }">
      <n-data-table size="small" :columns="roleColumns" :data="roles" :bordered="false" :loading="loadingRoles" />
    </n-card>

    <n-card title="Live login sessions" :segmented="{ content: true }">
      <template #header-extra>
        <n-space align="center" size="small">
          <n-text depth="3" style="font-size: 0.85rem;">
            {{ activeUsersCount }} active {{ activeUsersCount === 1 ? 'user' : 'users' }}
          </n-text>
          <n-button size="small" :loading="refreshingSessions" :disabled="refreshingSessions" @click="refreshSessions"
            type="success" ghost>
            <template #icon>
              <n-icon :size="16">
                <RefreshOutline />
              </n-icon>
            </template>
            Refresh
          </n-button>
        </n-space>
      </template>

      <n-data-table size="small" :columns="loginActivityColumns" :data="loginActivityRows" :bordered="false"
        :loading="loadingUsers" :row-key="(row) => row.key" />

      <template #footer>
        <n-text depth="3" style="font-size: 0.75rem;">
          Session counters refresh every second while this dashboard is open.
        </n-text>
      </template>
    </n-card>

    <n-card title="Recent session durations" :segmented="{ content: true }">
      <PaginatedTable :columns="sessionHistoryColumns" :rows="sessionHistoryRows" :page-size="SESSION_HISTORY_PAGE_SIZE"
        :loading="loadingUsers" :row-key="(row) => row.key" :bordered="false" :pagination-props="{ simple: true }"
        :empty-message="'No session activity recorded yet.'">
        <template #range="{ start, end, total }">
          <n-text depth="3" style="font-size: 0.75rem;">
            Showing {{ total === 0 ? 0 : start }} - {{ total === 0 ? 0 : end }} of {{ total }} session logs.
          </n-text>
        </template>
      </PaginatedTable>
    </n-card>

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
        <n-form-item v-if="editingUserId" label="Status">
          <n-radio-group v-model:value="userFormStatus" button-style="solid">
            <n-radio-button v-for="option in statusRadioOptions" :key="option.value" :value="option.value"
              :disabled="option.disabled">
              {{ option.label }}
            </n-radio-button>
          </n-radio-group>
        </n-form-item>
      </n-form>
      <template #footer>
        <n-space justify="end">
          <n-button quaternary @click="showUserModal = false">Cancel</n-button>
          <n-button type="primary" :loading="savingUser" @click="saveUser">Save</n-button>
        </n-space>
      </template>
    </n-modal>
  </n-space>
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
