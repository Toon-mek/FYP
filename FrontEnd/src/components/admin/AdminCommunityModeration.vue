<script setup>
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import {
  NAlert,
  NAvatar,
  NBadge,
  NButton,
  NCard,
  NDataTable,
  NDescriptions,
  NDescriptionsItem,
  NDrawer,
  NDrawerContent,
  NEmpty,
  NForm,
  NFormItem,
  NGrid,
  NGridItem,
  NInput,
  NModal,
  NSelect,
  NSpin,
  NSpace,
  NStatistic,
  NTag,
  NText,
  useMessage,
} from 'naive-ui'
import SimplePagination from '../shared/SimplePagination.vue'

const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const COMMUNITY_MEDIA_ENDPOINT = `${API_BASE}/community/media.php`
const PUBLIC_ASSETS_BASE = normaliseBaseUrl(import.meta.env.VITE_PUBLIC_ASSETS_BASE || '')

const props = defineProps({
  adminId: {
    type: Number,
    default: null,
  },
})

const message = useMessage()
const numberFormatter = new Intl.NumberFormat()

const filters = reactive({
  mediaType: 'all',
  category: 'all',
  authorType: 'all',
  search: '',
})

const mediaOptions = [
  { label: 'All media', value: 'all' },
  { label: 'Images only', value: 'image' },
  { label: 'Videos only', value: 'video' },
]

const authorOptions = [
  { label: 'All authors', value: 'all' },
  { label: 'Travelers', value: 'traveler' },
  { label: 'Operators', value: 'operator' },
]

const categoryOptions = ref([{ label: 'All categories', value: 'all' }])

const tableData = ref([])
const totalPosts = ref(0)
const page = ref(1)
const pageSize = 10
const tableLoading = ref(false)
const fetchError = ref('')
const summary = ref(createEmptySummary())

const panelContentStyle = undefined

const detailState = reactive({
  visible: false,
  loading: false,
  data: null,
  error: '',
})

const deleteState = reactive({
  show: false,
  loading: false,
  post: null,
  reason: '',
})

const columns = computed(() => [
  {
    title: 'Post',
    key: 'caption',
    minWidth: 240,
    render(row) {
      const tags = (row.categories ?? []).slice(0, 2)
      return h(
        'div',
        { class: 'acm__column-post' },
        [
          h('div', { class: 'acm__post-caption' }, row.caption || 'Untitled story'),
          row.location
            ? h(NText, { depth: 3, style: 'font-size: 12px;' }, { default: () => row.location })
            : null,
          tags.length
            ? h(
              NSpace,
              { size: 4 },
              {
                default: () =>
                  tags.map((tag) =>
                    h(
                      NTag,
                      { size: 'tiny', type: 'success', round: true, bordered: false },
                      { default: () => tag },
                    ),
                  ),
              },
            )
            : null,
        ].filter(Boolean),
      )
    },
  },
  {
    title: 'Author',
    key: 'author',
    minWidth: 220,
    render(row) {
      const author = row.author || {}
      const initials = author.initials || (author.name ? computeInitials(author.name) : 'TR')
      return h(
        'div',
        { class: 'acm__author-cell' },
        [
          h(NAvatar, { round: true, size: 36, src: author.avatar || undefined }, { default: () => initials }),
          h('div', { class: 'acm__author-meta' }, [
            h('strong', null, author.name || 'Community member'),
            author.username
              ? h(NText, { depth: 3, style: 'font-size: 12px;' }, { default: () => `@${author.username}` })
              : null,
            author.email
              ? h(NText, { depth: 3, style: 'font-size: 12px;' }, { default: () => author.email })
              : null,
          ]),
          h(
            NTag,
            {
              type: author.type === 'operator' ? 'warning' : 'info',
              size: 'tiny',
              bordered: false,
              round: true,
            },
            { default: () => (author.type === 'operator' ? 'Operator' : 'Traveler') },
          ),
        ],
      )
    },
  },
  {
    title: 'Timeline',
    key: 'timeline',
    width: 160,
    render(row) {
      const timeline = row.timeline || {}
      return timeline.label
        ? h(NText, { depth: 3, style: 'font-size: 12px;' }, { default: () => timeline.label })
        : null
    },
  },
  {
    title: 'Engagement',
    key: 'metrics',
    width: 170,
    render(row) {
      const metrics = row.metrics || {}
      return h(
        NSpace,
        { size: 8 },
        {
          default: () => [
            h(NBadge, { value: metrics.likes ?? 0, type: 'error', showZero: true }, { default: () => '❤' }),
            h(NBadge, { value: metrics.comments ?? 0, type: 'info', showZero: true }, { default: () => '💬' }),
            h(NBadge, { value: metrics.saves ?? 0, type: 'success', showZero: true }, { default: () => '🔖' }),
          ],
        },
      )
    },
  },
  {
    title: 'Media',
    key: 'media',
    width: 120,
    render(row) {
      const mediaCount = row.mediaCount ?? 0
      const type = row.mediaType === 'video' ? 'Video' : 'Image'
      return h(
        NSpace,
        { vertical: true, size: 4 },
        {
          default: () => [
            h(NTag, { size: 'tiny', type: row.mediaType === 'video' ? 'warning' : 'info', bordered: false }, { default: () => type }),
            h(NText, { depth: 3, style: 'font-size: 12px;' }, { default: () => `${mediaCount} asset(s)` }),
          ],
        },
      )
    },
  },
  {
    title: 'Action',
    key: 'actions',
    width: 160,
    render(row) {
      return h(
        NSpace,
        { size: 'small' },
        {
          default: () => [
            h(
              NButton,
              {
                size: 'small',
                tertiary: true,
                type: 'primary',
                onClick: () => openDetails(row),
              },
              { default: () => 'Details' },
            ),
            h(
              NButton,
              {
                size: 'small',
                tertiary: true,
                type: 'error',
                onClick: () => openDelete(row),
              },
              { default: () => 'Remove' },
            ),
          ],
        },
      )
    },
  },
])

const summaryCards = computed(() => {
  const data = summary.value || createEmptySummary()

  return [
    {
      key: 'posts',
      label: 'Total posts',
      value: numberFormatter.format(data.totalPosts ?? 0),
      description: 'Stories currently available',
    },
    {
      key: 'removals',
      label: 'Removals (30d)',
      value: numberFormatter.format(data.recentRemovals30d ?? 0),
      description: 'Actions recorded in the last 30 days',
    },
  ]
})

const pageSummary = computed(() => {
  if (!totalPosts.value) {
    return 'Showing 0 posts'
  }
  const start = (page.value - 1) * pageSize + 1
  const end = Math.min(totalPosts.value, page.value * pageSize)
  return `Showing ${numberFormatter.format(start)}-${numberFormatter.format(end)} of ${numberFormatter.format(totalPosts.value)} posts`
})

let suppressFilterWatch = false

watch(
  () => [filters.mediaType, filters.category, filters.authorType],
  () => {
    if (suppressFilterWatch) {
      return
    }
    if (page.value !== 1) {
      page.value = 1
    } else {
      fetchPosts()
    }
  },
)

watch(page, () => {
  fetchPosts()
})

onMounted(() => {
  fetchPosts()
})

function createEmptySummary() {
  return {
    totalPosts: 0,
    recentRemovals30d: 0,
  }
}

async function fetchPosts() {
  tableLoading.value = true
  fetchError.value = ''

  try {
    const params = new URLSearchParams()
    params.set('limit', String(pageSize))
    params.set('offset', String(Math.max(0, (page.value - 1) * pageSize)))
    if (filters.mediaType !== 'all') params.set('mediaType', filters.mediaType)
    if (filters.category !== 'all' && filters.category !== '') params.set('category', filters.category)
    if (filters.authorType !== 'all') params.set('authorType', filters.authorType)
    if (filters.search.trim()) params.set('search', filters.search.trim())

    const url = `${API_BASE}/admin/community_moderation.php?${params.toString()}`
    const response = await fetch(url)
    const payload = await response.json().catch(() => ({}))

    if (!response.ok) {
      throw new Error(payload.error ?? 'Failed to load community posts.')
    }

    tableData.value = Array.isArray(payload.posts) ? payload.posts : []
    const total = Number(payload.total)
    totalPosts.value = Number.isFinite(total) ? total : 0
    summary.value = payload.summary ? { ...createEmptySummary(), ...payload.summary } : createEmptySummary()
    categoryOptions.value = normaliseCategoryOptions(payload.categories)
  } catch (error) {
    console.error('Failed to load community posts', error)
    fetchError.value = error instanceof Error ? error.message : 'Unable to load community posts.'
    message.error(fetchError.value)
    tableData.value = []
  } finally {
    tableLoading.value = false
  }
}

function normaliseCategoryOptions(raw) {
  const base = [{ label: 'All categories', value: 'all' }]
  if (!Array.isArray(raw)) {
    return base
  }

  const items = raw
    .map((item) => ({
      value: item.value ?? item.category ?? item.label ?? '',
      label: item.label ?? item.value ?? item.category ?? '',
      count: item.count ?? null,
    }))
    .filter((item) => item.value && item.label && item.value !== 'all')

  const deduped = []
  const seen = new Set()
  items.forEach((item) => {
    if (seen.has(item.value)) return
    seen.add(item.value)
    deduped.push(item)
  })

  return base.concat(
    deduped.map((item) => ({
      value: item.value,
      label: item.count ? `${item.label} (${item.count})` : item.label,
    })),
  )
}

function applySearch() {
  if (page.value !== 1) {
    page.value = 1
  } else {
    fetchPosts()
  }
}

function resetFilters() {
  suppressFilterWatch = true
  filters.mediaType = 'all'
  filters.category = 'all'
  filters.authorType = 'all'
  filters.search = ''
  suppressFilterWatch = false
  if (page.value !== 1) {
    page.value = 1
  } else {
    fetchPosts()
  }
}

async function openDetails(row) {
  detailState.visible = true
  detailState.loading = true
  detailState.error = ''
  detailState.data = null

  try {
    const response = await fetch(`${API_BASE}/admin/community_moderation.php?postId=${row.id}`)
    const payload = await response.json().catch(() => ({}))
    if (!response.ok) {
      throw new Error(payload.error ?? 'Failed to load post details.')
    }
    detailState.data = payload.post ?? null
  } catch (error) {
    console.error('Failed to load post detail', error)
    detailState.error = error instanceof Error ? error.message : 'Unable to load post detail.'
  } finally {
    detailState.loading = false
  }
}

function openDelete(row) {
  deleteState.post = row
  deleteState.reason = ''
  deleteState.show = true
  deleteState.loading = false
}

async function confirmDelete() {
  if (!deleteState.post) {
    deleteState.show = false
    return
  }

  deleteState.loading = true

  try {
    const response = await fetch(`${API_BASE}/admin/community_moderation.php`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        postId: deleteState.post.id,
        adminId: props.adminId ?? undefined,
        reason: deleteState.reason.trim(),
      }),
    })

    const payload = await response.json().catch(() => ({}))
    if (!response.ok || !payload.ok) {
      throw new Error(payload.error ?? 'Failed to remove post.')
    }

    message.success(payload.message ?? 'Post removed.')
    deleteState.show = false
    await fetchPosts()

    if (detailState.visible && detailState.data?.id === deleteState.post.id) {
      detailState.visible = false
    }
  } catch (error) {
    console.error('Failed to delete post', error)
    message.error(error instanceof Error ? error.message : 'Unable to delete post.')
  } finally {
    deleteState.loading = false
  }
}

function formatDateTime(value) {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return value
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

function resolveMediaUrl(path) {
  if (!path) {
    return ''
  }

  if (/^https?:\/\//i.test(path) || path.startsWith('data:')) {
    return path
  }

  let cleaned = path.replace(/^\/+/, '')
  if (cleaned.toLowerCase().startsWith('public_assets/')) {
    cleaned = cleaned.slice('public_assets/'.length)
  }

  if (cleaned.toLowerCase().startsWith('community_media/')) {
    return `${COMMUNITY_MEDIA_ENDPOINT}?path=${encodeURIComponent(cleaned)}`
  }

  return `${PUBLIC_ASSETS_BASE}${cleaned}`
}

function normaliseBaseUrl(input) {
  if (!input) {
    const pathMatch = window.location.pathname.match(/^(.*?\/public_assets\/)/i)
    if (pathMatch && pathMatch[1]) {
      return `${window.location.origin}${pathMatch[1]}`
    }
    return `${window.location.origin.replace(/\/$/, '')}/public_assets/`
  }

  const trimmed = input.trim()
  if (/^https?:\/\//i.test(trimmed)) {
    return trimmed.replace(/\/?$/, '/')
  }

  if (trimmed.startsWith('/')) {
    return `${window.location.origin}${trimmed.replace(/\/?$/, '/')}`
  }

  const baseDirMatch = trimmed.match(/^\.{0,2}\/(.+)/)
  if (baseDirMatch) {
    const resolved = new URL(baseDirMatch[1], `${window.location.origin}${window.location.pathname}`).href
    return resolved.replace(/\/?$/, '/')
  }

  return `${window.location.origin}/${trimmed.replace(/\/?$/, '/')}`
}

function computeInitials(text) {
  if (!text) {
    return 'TR'
  }
  return text
    .split(/\s+/)
    .map((word) => word[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()
}
</script>

<template>
  <div class="acm">
    <n-space vertical size="large" class="acm__stack" align="stretch">
      <n-card size="small" :bordered="false" class="abl__panel acm__toolbar">
        <div class="acm__header">
          <div>
            <h2 class="acm__title">Community moderation</h2>
            <p class="acm__subtitle">
              Review traveler stories, monitor engagement trends, and remove content that violates guidelines.
            </p>
          </div>
          <n-button size="small" tertiary type="primary" :loading="tableLoading" @click="fetchPosts">
            Refresh data
          </n-button>
        </div>

        <n-grid cols="1 s:2 l:4" :x-gap="16" :y-gap="16" class="acm__summary-grid">
          <n-grid-item v-for="card in summaryCards" :key="card.key">
            <n-card size="small" :segmented="{ content: true }">
              <n-space vertical size="small">
                <n-text depth="3">{{ card.label }}</n-text>
                <span class="acm__summary-value">{{ card.value }}</span>
                <n-text depth="3" class="acm__summary-desc">{{ card.description }}</n-text>
              </n-space>
            </n-card>
          </n-grid-item>
        </n-grid>

        <n-form inline label-placement="left" label-width="auto" class="acm__filters">
          <n-form-item label="Media">
            <n-select v-model:value="filters.mediaType" :options="mediaOptions" size="small" style="width: 160px" />
          </n-form-item>
          <n-form-item label="Category">
            <n-select v-model:value="filters.category" :options="categoryOptions" size="small" filterable clearable
              style="width: 200px" @update:value="(value) => (filters.category = value || 'all')" />
          </n-form-item>
          <n-form-item label="Author">
            <n-select v-model:value="filters.authorType" :options="authorOptions" size="small" style="width: 170px" />
          </n-form-item>
          <n-form-item label="Search">
            <n-input v-model:value="filters.search" size="small" clearable placeholder="Caption, author, location"
              style="width: 220px" @keyup.enter="applySearch" />
          </n-form-item>
          <n-form-item>
            <n-space size="small">
              <n-button size="small" type="primary" @click="applySearch">
                Apply
              </n-button>
              <n-button size="small" quaternary @click="resetFilters">
                Reset
              </n-button>
            </n-space>
          </n-form-item>
        </n-form>
      </n-card>

      <n-card size="medium" :bordered="false" class="abl__panel acm__table-card" :content-style="panelContentStyle">
        <template #header>
          <span class="acm__table-title">Community posts</span>
        </template>

        <n-alert v-if="fetchError" type="error" closable class="acm__alert" @close="fetchError = ''">
          {{ fetchError }}
        </n-alert>

        <n-data-table :columns="columns" :data="tableData" :loading="tableLoading" :bordered="false" :pagination="false"
          :row-key="(row) => row.id" size="small" class="acm__table" />

        <n-empty v-if="!tableLoading && !tableData.length && !fetchError"
          description="No posts match your current filters." class="acm__empty" />

        <n-space v-if="totalPosts > 0" justify="space-between" align="center" class="acm__pagination">
          <n-text depth="3">{{ pageSummary }}</n-text>
          <SimplePagination v-model:page="page" :item-count="totalPosts" :page-size="pageSize" />
        </n-space>
      </n-card>
    </n-space>

    <n-drawer v-model:show="detailState.visible" width="780" :mask-closable="true" :close-on-esc="true">
      <n-drawer-content title="Post details">
        <template #header-extra>
          <n-button text size="small" @click="detailState.visible = false">
            Close
          </n-button>
        </template>
        <n-spin :show="detailState.loading">
          <n-alert v-if="detailState.error" type="error" class="acm__alert">
            {{ detailState.error }}
          </n-alert>

          <template v-else-if="detailState.data">
            <n-space vertical size="large">
              <div class="acm__detail-header">
                <div class="acm__detail-author">
                  <n-avatar round size="large" :src="detailState.data.author?.avatar || undefined">
                    {{ detailState.data.author?.initials || computeInitials(detailState.data.author?.name || 'TR') }}
                  </n-avatar>
                  <div>
                    <strong>{{ detailState.data.author?.name }}</strong>
                    <n-text depth="3" v-if="detailState.data.author?.email">
                      {{ detailState.data.author?.email }}
                    </n-text>
                    <n-text depth="3" v-if="detailState.data.author?.username">
                      @{{ detailState.data.author.username }}
                    </n-text>
                  </div>
                </div>
              </div>

              <div class="acm__detail-media">
                <template v-for="asset in detailState.data.media" :key="asset.id">
                  <video v-if="asset.type === 'video'" :src="resolveMediaUrl(asset.url)" controls playsinline
                    preload="metadata" />
                  <img v-else :src="resolveMediaUrl(asset.url)" :alt="detailState.data.caption" loading="lazy" />
                </template>
              </div>

              <n-card size="small" class="acm__detail-card" :bordered="false">
                <n-space vertical size="small">
                  <strong>{{ detailState.data.caption || 'Untitled story' }}</strong>
                  <n-text depth="3" v-if="detailState.data.location">{{ detailState.data.location }}</n-text>
                  <n-space size="small" wrap v-if="detailState.data.categories?.length">
                    <n-tag v-for="category in detailState.data.categories" :key="category" size="small" type="success"
                      round>
                      #{{ category }}
                    </n-tag>
                  </n-space>
                  <n-space size="small" wrap v-if="detailState.data.tags?.length">
                    <n-tag v-for="tag in detailState.data.tags" :key="tag" size="tiny" type="info" round>
                      {{ tag }}
                    </n-tag>
                  </n-space>
                </n-space>
              </n-card>

              <n-descriptions size="small" :column="1" bordered>
                <n-descriptions-item label="Created">
                  {{ formatDateTime(detailState.data.createdAt) }}
                </n-descriptions-item>
                <n-descriptions-item label="Updated">
                  {{ formatDateTime(detailState.data.updatedAt) }}
                </n-descriptions-item>
                <n-descriptions-item label="Engagement">
                  ❤ {{ numberFormatter.format(detailState.data.metrics?.likes ?? 0) }} ·
                  💬 {{ numberFormatter.format(detailState.data.metrics?.comments ?? 0) }} ·
                  🔖 {{ numberFormatter.format(detailState.data.metrics?.saves ?? 0) }}
                </n-descriptions-item>
                <n-descriptions-item label="Author contact">
                  <template v-if="detailState.data.author?.contact">
                    {{ detailState.data.author.contact }}
                  </template>
                  <template v-else>
                    <n-text depth="3">Not provided</n-text>
                  </template>
                </n-descriptions-item>
              </n-descriptions>

            </n-space>
          </template>

          <n-empty v-else description="No post selected." />
        </n-spin>
      </n-drawer-content>
    </n-drawer>

    <n-modal v-model:show="deleteState.show" preset="card" title="Remove post" style="max-width: 420px; width: 100%;">
      <n-space vertical size="medium">
        <n-text depth="3">
          Removing <strong>{{ deleteState.post?.caption || 'this post' }}</strong> hides it from the community and
          deletes its media. Share a note so the creator understands what happened.
        </n-text>
        <n-input v-model:value="deleteState.reason" type="textarea" placeholder="Reason for removal (required)"
          :autosize="{ minRows: 3, maxRows: 5 }" />
      </n-space>
      <template #footer>
        <n-space justify="end">
          <n-button :disabled="deleteState.loading" @click="deleteState.show = false">
            Cancel
          </n-button>
          <n-button type="error" :loading="deleteState.loading" :disabled="!deleteState.reason.trim()"
            @click="confirmDelete">
            Remove post
          </n-button>
        </n-space>
      </template>
    </n-modal>
  </div>
</template>

<style scoped>
.acm {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.acm__stack {
  width: 100%;
}

.abl__panel {
  background: #f9fafb;
}

.acm__toolbar {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.acm__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.acm__title {
  margin: 0;
  font-size: 1.5rem;
  font-weight: 600;
}

.acm__subtitle {
  margin: 6px 0 0;
  color: var(--n-text-color-3, #6b7280);
  font-size: 0.95rem;
}

.acm__summary-grid {
  margin-top: 8px;
}

.acm__summary-value {
  font-size: 1.6rem;
  font-weight: 600;
}

.acm__summary-desc {
  font-size: 0.85rem;
}

.acm__filters {
  margin-top: 8px;
  flex-wrap: wrap;
  gap: 8px 16px;
}

.acm__table-card {
  flex: 1;
  width: 100%;
  overflow: hidden;
}

.acm__table {
  width: 100%;
}

.acm__table-title {
  font-weight: 600;
  font-size: 1.1rem;
}

.acm__column-post {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.acm__post-caption {
  font-weight: 600;
}

.acm__author-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.acm__author-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.acm__timeline-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.acm__alert {
  margin-bottom: 12px;
}

.acm__empty {
  margin-top: 24px;
}

.acm__pagination {
  margin-top: 20px;
}

.acm__detail-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.acm__detail-author {
  display: flex;
  align-items: center;
  gap: 12px;
}

.acm__detail-media {
  display: grid;
  gap: 12px;
}

.acm__detail-media img,
.acm__detail-media video {
  width: 100%;
  border-radius: 8px;
  background: #f1f5f9;
}

.acm__detail-card {
  background: #f8fafc;
}
</style>
