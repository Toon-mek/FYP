<script setup>
import { computed, h, ref } from 'vue'
import { NButton, NSpace, NTag, NText } from 'naive-ui'

const menuOptions = [
  {
    key: 'overview',
    label: 'Dashboard overview',
  },
  {
    type: 'group',
    label: 'People & access',
    children: [
      { key: 'users', label: 'User management' },
      { key: 'roles', label: 'Role management' },
    ],
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
  roles: {
    title: 'Role management',
    subtitle: 'Define administrative scopes and align permissions with responsibilities',
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

const modulePlaceholders = {
  default: {
    title: 'Module workspace',
    description: 'Select a module from the sidebar to begin.',
    highlights: [],
    actions: [],
  },
  users: {
    title: 'User & role management workspace',
    description:
      'Segment travelers, operators, and administrators. Approve new sign-ups, lock risky accounts, and align each team to clear responsibilities.',
    highlights: [
      'Pending traveler approvals',
      'Operator verification queues',
      'Recent credential updates',
    ],
    actions: [
      { key: 'view-users', label: 'Open user directory', type: 'primary' },
      { key: 'invite-admin', label: 'Invite administrator', tertiary: true },
      { key: 'audit-log', label: 'View audit log', quaternary: true },
    ],
  },
  roles: {
    title: 'Role control center',
    description:
      'Define reusable role templates and share permission presets across regions or task forces.',
    highlights: [
      'Current role hierarchy',
      'Permission change requests',
      'Least-privilege recommendations',
    ],
    actions: [
      { key: 'create-role', label: 'Create role template', type: 'primary' },
      { key: 'export-policy', label: 'Export policy JSON', tertiary: true },
    ],
  },
  verification: {
    title: 'Listing verification queue',
    description:
      'Coordinate sustainability checks, document requests, and regional approvals before publishing.',
    highlights: [
      'Submissions awaiting document review',
      'Listings flagged during spot checks',
      'Completed verifications this week',
    ],
    actions: [
      { key: 'open-queue', label: 'Open verification board', type: 'primary' },
      { key: 'review-guidelines', label: 'Review checklist', tertiary: true },
    ],
  },
  business: {
    title: 'Business listings workspace',
    description:
      'Track listing health scores, pricing tiers, and operator engagement to keep content fresh.',
    highlights: [
      'Listings needing photo updates',
      'Partners approaching renewal',
      'High-performing eco stays',
    ],
    actions: [
      { key: 'view-catalog', label: 'View listing catalog', type: 'primary' },
      { key: 'sync-crm', label: 'Sync with CRM', tertiary: true },
    ],
  },
  community: {
    title: 'Community health dashboard',
    description:
      'Moderate posts, comments, and collaborative itineraries. Surface content for editorial amplification.',
    highlights: [
      'Queued moderation tickets',
      'Trending responsible travel stories',
      'Members needing follow-up',
    ],
    actions: [
      { key: 'open-inbox', label: 'Open moderation inbox', type: 'primary' },
      { key: 'schedule-spotlight', label: 'Schedule spotlight', tertiary: true },
    ],
  },
  notifications: {
    title: 'Communications hub',
    description:
      'Send targeted announcements to travelers and operators, and track engagement metrics in one place.',
    highlights: [
      'Draft announcements awaiting approval',
      'Audience segment performance',
      'Recent SMS/Email status',
    ],
    actions: [
      { key: 'compose-broadcast', label: 'Compose broadcast', type: 'primary' },
      { key: 'automation-center', label: 'Manage automations', tertiary: true },
    ],
  },
  reports: {
    title: 'Insights & reporting',
    description:
      'Generate sustainability impact dashboards and export summaries for leadership reviews.',
    highlights: [
      'Monthly activity snapshots',
      'Impact KPI trackers',
      'Data export schedules',
    ],
    actions: [
      { key: 'new-report', label: 'Generate new report', type: 'primary' },
      { key: 'share-dashboard', label: 'Share live dashboard', tertiary: true },
    ],
  },
  settings: {
    title: 'Platform configuration',
    description:
      'Adjust policy toggles, security settings, integrations, and maintenance windows.',
    highlights: [
      'API credential status',
      'Webhook delivery logs',
      'Security policy expirations',
    ],
    actions: [
      { key: 'open-settings', label: 'Open settings', type: 'primary' },
      { key: 'integration-center', label: 'Integration center', tertiary: true },
    ],
  },
}

const activeModule = ref('overview')

const activeModuleMeta = computed(
  () => moduleMeta[activeModule.value] ?? moduleMeta.overview,
)

const headerButtons = computed(() => {
  switch (activeModule.value) {
    case 'overview':
      return [
        { key: 'draft-announcement', label: 'Draft announcement', tertiary: true },
        { key: 'schedule-review', label: 'Schedule review', type: 'primary' },
      ]
    case 'users':
      return [
        { key: 'invite-user', label: 'Invite user', type: 'primary' },
        { key: 'view-rules', label: 'Access policies', tertiary: true },
      ]
    case 'roles':
      return [
        { key: 'role-blueprint', label: 'Role blueprint', type: 'primary' },
        { key: 'permission-diff', label: 'Permission diff', tertiary: true },
      ]
    case 'verification':
      return [
        { key: 'assign-review', label: 'Assign reviewers', type: 'primary' },
        { key: 'download-checklist', label: 'Download checklist', tertiary: true },
      ]
    case 'business':
      return [
        { key: 'new-listing', label: 'Register listing', type: 'primary' },
        { key: 'bulk-update', label: 'Bulk update', tertiary: true },
      ]
    case 'community':
      return [
        { key: 'open-moderation', label: 'Moderation queue', type: 'primary' },
        { key: 'escalation-rules', label: 'Escalation rules', tertiary: true },
      ]
    case 'notifications':
      return [
        { key: 'create-campaign', label: 'Create campaign', type: 'primary' },
        { key: 'message-history', label: 'Message history', tertiary: true },
      ]
    case 'reports':
      return [
        { key: 'export-pdf', label: 'Export PDF', type: 'primary' },
        { key: 'schedule-email', label: 'Schedule email', tertiary: true },
      ]
    case 'settings':
      return [
        { key: 'open-settings', label: 'Manage settings', type: 'primary' },
        { key: 'maintenance', label: 'Maintenance window', tertiary: true },
      ]
    default:
      return [{ key: 'configure', label: 'Configure module', type: 'primary' }]
  }
})

const summaryCards = [
  {
    key: 'travelers',
    label: 'Active travelers',
    value: '1,284',
    trendLabel: '+8.4% vs last month',
    trendType: 'success',
  },
  {
    key: 'operators',
    label: 'Verified operators',
    value: '312',
    trendLabel: '+12 this week',
    trendType: 'success',
  },
  {
    key: 'itineraries',
    label: 'Shared itineraries',
    value: '485',
    trendLabel: '23 awaiting review',
    trendType: 'warning',
  },
  {
    key: 'reports',
    label: 'Open reports',
    value: '9',
    trendLabel: '2 critical items',
    trendType: 'error',
  },
]

const verificationProgress = {
  completed: 42,
  total: 60,
}

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
  { id: 1, actor: 'Traveler • Yap Wei Hoong', description: 'reported inaccurate listing data', time: '4m ago' },
  { id: 2, actor: 'Operator • Green Trails', description: 'submitted sustainability audit', time: '32m ago' },
  { id: 3, actor: 'Traveler • Arif Hussain', description: 'published itinerary “Eco Sabah”', time: '1h ago' },
  { id: 4, actor: 'Operator • Penang Heritage', description: 'requested verification call', time: '2h ago' },
]

const quickActions = [
  { key: 'create-report', label: 'Generate system report', type: 'primary' },
  { key: 'review-guidelines', label: 'Update operator checklist', type: 'default' },
  { key: 'broadcast', label: 'Send sustainability bulletin', type: 'default' },
]

const approvalColumns = [
  {
    title: 'Company',
    key: 'company',
  },
  {
    title: 'Primary contact',
    key: 'contact',
  },
  {
    title: 'Email',
    key: 'email',
  },
  {
    title: 'Submitted',
    key: 'submitted',
  },
  {
    title: 'Status',
    key: 'status',
    render(row) {
      return h(
        NTag,
        {
          size: 'small',
          type: row.status.includes('Ready') ? 'success' : 'warning',
          bordered: false,
        },
        { default: () => row.status },
      )
    },
  },
  {
    title: 'Action',
    key: 'actions',
    render() {
      return h(
        NSpace,
        { size: 'small' },
        () => [
          h(
            NButton,
            { size: 'small', tertiary: true, type: 'primary' },
            { default: () => 'Review' },
          ),
          h(
            NButton,
            { size: 'small', quaternary: true },
            { default: () => 'Message' },
          ),
        ],
      )
    },
  },
]

const verificationPercent = computed(() =>
  Math.round((verificationProgress.completed / verificationProgress.total) * 100),
)

const currentPlaceholder = computed(
  () => modulePlaceholders[activeModule.value] ?? modulePlaceholders.default,
)

function handleMenuSelect(key) {
  activeModule.value = key
}
</script>

<template>
  <n-layout
    id="admin-top"
    has-sider
    style="min-height: 100vh; background: var(--body-color);"
  >
    <n-layout-sider
      bordered
      collapse-mode="width"
      :collapsed-width="64"
      :width="240"
      show-trigger="bar"
    >
      <n-space vertical size="small" style="padding: 18px 16px;">
        <n-gradient-text type="success" style="font-size: 1.15rem; font-weight: 600;">
          MS Admin
        </n-gradient-text>
        <n-text depth="3">Manage modules</n-text>
      </n-space>
      <div style="padding: 0 8px;">
        <n-menu
          :options="menuOptions"
          :value="activeModule"
          :indent="18"
          :collapsed-icon-size="20"
          @update:value="handleMenuSelect"
        />
      </div>
    </n-layout-sider>

    <n-layout>
      <n-layout-header bordered style="padding: 20px 28px;">
        <n-page-header
          :title="activeModuleMeta.title"
          :subtitle="activeModuleMeta.subtitle"
        >
          <template #extra>
            <n-space>
              <n-button
                v-for="action in headerButtons"
                :key="action.key"
                :type="action.type ?? 'default'"
                :tertiary="action.tertiary"
                :quaternary="action.quaternary"
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
                  <n-data-table
                    size="small"
                    :columns="approvalColumns"
                    :data="pendingApprovals"
                    :bordered="false"
                  />
                </n-card>
              </n-grid-item>

              <n-grid-item>
                <n-space vertical size="large">
                  <n-card title="Verification throughput" :segmented="{ content: true }">
                    <n-space vertical size="small">
                      <span style="font-size: 2.2rem; font-weight: 600;">{{ verificationPercent }}%</span>
                      <n-text depth="3">
                        {{ verificationProgress.completed }} of {{ verificationProgress.total }} check-ins completed this week
                      </n-text>
                      <n-progress
                        type="line"
                        :percentage="verificationPercent"
                        indicator-placement="inside"
                        processing
                      />
                    </n-space>
                  </n-card>

                  <n-card title="Quick actions" :segmented="{ content: true }">
                    <n-space vertical>
                      <n-button
                        v-for="action in quickActions"
                        :key="action.key"
                        :type="action.type"
                        block
                      >
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

        <template v-else>
          <n-space vertical size="large">
            <n-card :title="currentPlaceholder.title" :segmented="{ content: true }">
              <n-space vertical size="large">
                <n-text depth="3">{{ currentPlaceholder.description }}</n-text>
                <n-list
                  v-if="currentPlaceholder.highlights?.length"
                  :show-divider="false"
                  :bordered="false"
                >
                  <n-list-item v-for="item in currentPlaceholder.highlights" :key="item">
                    {{ item }}
                  </n-list-item>
                </n-list>
                <n-space v-if="currentPlaceholder.actions?.length" wrap>
                  <n-button
                    v-for="action in currentPlaceholder.actions"
                    :key="action.key"
                    :type="action.type ?? 'default'"
                    :tertiary="action.tertiary"
                    :quaternary="action.quaternary"
                  >
                    {{ action.label }}
                  </n-button>
                </n-space>
              </n-space>
            </n-card>
          </n-space>
        </template>
      </n-layout-content>
    </n-layout>
  </n-layout>
</template>
