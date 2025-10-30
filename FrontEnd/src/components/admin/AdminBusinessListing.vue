<script setup>
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import {
  NAlert,
  NButton,
  NCard,
  NDataTable,
  NDrawer,
  NDrawerContent,
  NEmpty,
  NForm,
  NFormItem,
  NImage,
  NInput,
  NModal,
  NSelect,
  NSpace,
  NStatistic,
  NTag,
  NText,
  useMessage,
} from 'naive-ui'

const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const message = useMessage()

const props = defineProps({
  adminId: {
    type: Number,
    default: null,
  },
})

const filters = reactive({
  status: 'all',
  visibility: 'all',
  category: 'all',
  search: '',
})

const statusOptions = [
  { label: 'All Status', value: 'all' },
  { label: 'Pending', value: 'pending' },
  { label: 'Approved', value: 'approved' },
  { label: 'Rejected', value: 'rejected' },
  { label: 'Active', value: 'active' },
]

const visibilityOptions = [
  { label: 'All', value: 'all' },
  { label: 'Visible', value: 'visible' },
  { label: 'Hidden', value: 'hidden' },
]

const categoryOptions = ref([{ label: 'All categories', value: 'all' }])
const tableData = ref([])
const summary = ref({
  total: 0,
  status: { 'Pending Review': 0, Approved: 0, Rejected: 0, Active: 0 },
  visibility: { Visible: 0, Hidden: 0 },
})
const tableLoading = ref(false)
const fetchError = ref('')

const detailState = reactive({
  visible: false,
  loading: false,
  data: null,
  error: '',
})

const deleteState = reactive({
  show: false,
  loading: false,
  listing: null,
  reason: '',
})

onMounted(() => {
  fetchListings()
})

watch(
  () => [filters.status, filters.visibility, filters.category],
  () => {
    fetchListings()
  },
)

function statusTagType(status) {
  const value = (status || '').toLowerCase()
  if (value === 'approved' || value === 'active') return 'success'
  if (value === 'rejected') return 'error'
  if (value === 'pending review' || value === 'pending') return 'warning'
  return 'default'
}

function visibilityTagType(visibility) {
  return (visibility || '').toLowerCase() === 'visible' ? 'success' : 'default'
}

const columns = computed(() => [
  {
    title: 'Listing',
    key: 'businessName',
    minWidth: 230,
    render(row) {
      return h('div', { style: 'display:flex;flex-direction:column;gap:4px;' }, [
        h('div', { style: 'font-weight:600;' }, row.businessName),
        row.category || row.location
          ? h(
            'div',
            { style: 'font-size:12px;color:var(--n-text-color-3,#6b7280);' },
            [row.category ? `${row.category} • ` : '', row.location ?? 'Location not provided'].join(''),
          )
          : null,
      ])
    },
  },
  {
    title: 'Operator',
    key: 'operator',
    minWidth: 220,
    render(row) {
      const operator = row.operator || {}
      return h('div', { style: 'display:flex;flex-direction:column;gap:4px;' }, [
        h('span', { style: 'font-weight:500;' }, operator.name || 'Operator'),
        operator.email
          ? h('span', { style: 'font-size:12px;color:var(--n-text-color-3,#6b7280);' }, operator.email)
          : null,
        operator.phone
          ? h('span', { style: 'font-size:12px;color:var(--n-text-color-3,#6b7280);' }, operator.phone)
          : null,
      ])
    },
  },
  {
    title: 'Submitted',
    key: 'submittedDate',
    width: 120,
    render(row) {
      return row.submittedDate || '—'
    },
  },
  {
    title: 'Status',
    key: 'status',
    width: 120,
    render(row) {
      return h(
        NTag,
        { type: statusTagType(row.status), size: 'small', bordered: false },
        { default: () => row.status || 'Pending Review' },
      )
    },
  },
  {
    title: 'Visibility',
    key: 'visibility',
    width: 110,
    render(row) {
      return h(
        NTag,
        { type: visibilityTagType(row.visibility), size: 'small', bordered: true },
        { default: () => row.visibility || 'Hidden' },
      )
    },
  },
  {
    title: 'Media',
    key: 'imageCount',
    width: 90,
    render(row) {
      return h(NText, { depth: 3 }, { default: () => `${row.imageCount ?? 0} asset(s)` })
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
              { default: () => 'Delete' },
            ),
          ],
        },
      )
    },
  },
])

async function fetchListings() {
  tableLoading.value = true
  fetchError.value = ''

  const params = new URLSearchParams()
  if (filters.status !== 'all') params.set('status', filters.status)
  if (filters.visibility !== 'all') params.set('visibility', filters.visibility)
  if (filters.category !== 'all') params.set('category', filters.category)
  if (filters.search.trim() !== '') params.set('search', filters.search.trim())

  try {
    const response = await fetch(`${API_BASE}/admin/business_listings.php?${params.toString()}`)
    if (!response.ok) {
      throw new Error('Unable to load business listings.')
    }
    const body = await response.json()
    tableData.value = Array.isArray(body.listings) ? body.listings : []
    summary.value = body.summary ?? summary.value

    const categories = body.filters?.categories ?? []
    categoryOptions.value = [
      { label: 'All categories', value: 'all' },
      ...categories.map((category) => ({ label: category, value: category })),
    ]
  } catch (error) {
    console.error(error)
    fetchError.value = error instanceof Error ? error.message : 'Unexpected error while loading listings.'
    tableData.value = []
  } finally {
    tableLoading.value = false
  }
}

function applySearch() {
  fetchListings()
}

function resetFilters() {
  filters.status = 'all'
  filters.visibility = 'all'
  filters.category = 'all'
  filters.search = ''
  fetchListings()
}

async function openDetails(row) {
  detailState.visible = true
  detailState.loading = true
  detailState.error = ''
  detailState.data = null

  try {
    const response = await fetch(`${API_BASE}/admin/business_listings.php?listingId=${row.id}`)
    if (!response.ok) {
      throw new Error('Failed to load listing detail.')
    }
    const body = await response.json()
    detailState.data = body.listing ?? null
  } catch (error) {
    console.error(error)
    detailState.error = error instanceof Error ? error.message : 'Unable to load listing detail.'
  } finally {
    detailState.loading = false
  }
}

function openDelete(row) {
  deleteState.listing = row
  deleteState.reason = ''
  deleteState.show = true
  deleteState.loading = false
}

async function confirmDelete() {
  if (!deleteState.listing) {
    deleteState.show = false
    return
  }

  deleteState.loading = true

  try {
    const response = await fetch(`${API_BASE}/admin/business_listings.php`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        listingId: deleteState.listing.id,
        adminId: props.adminId ?? 0,
        reason: deleteState.reason.trim(),
      }),
    })

    const body = await response.json().catch(() => ({}))
    if (!response.ok || !body.ok) {
      throw new Error(body.error ?? 'Failed to remove listing.')
    }

    message.success(body.message ?? 'Listing removed.')
    deleteState.show = false
    await fetchListings()

    if (detailState.visible && detailState.data?.id === deleteState.listing.id) {
      detailState.visible = false
    }
  } catch (error) {
    console.error(error)
    message.error(error instanceof Error ? error.message : 'Unable to delete listing.')
  } finally {
    deleteState.loading = false
  }
}

const statusSummary = computed(() => summary.value?.status ?? {})
const visibilitySummary = computed(() => summary.value?.visibility ?? {})
const summaryColumns = [
  { title: 'Metric', key: 'label' },
  { title: 'Value', key: 'value' },
]
const summaryRows = computed(() => [
  { key: 'total', label: 'Total listings', value: summary.value?.total ?? 0 },
  { key: 'pending', label: 'Pending review', value: statusSummary.value['Pending Review'] ?? 0 },
  { key: 'approved', label: 'Approved', value: statusSummary.value.Approved ?? 0 },
  { key: 'rejected', label: 'Rejected', value: statusSummary.value.Rejected ?? 0 },
  { key: 'visible', label: 'Visible to travelers', value: visibilitySummary.value.Visible ?? 0 },
])
</script>

<template>
  <div class="abl">
    <n-space vertical size="large">
      <n-card size="small" :bordered="false" class="abl__toolbar">
        <div class="abl__header">
          <div>
            <h2 class="abl__title">Business listings</h2>
            <p class="abl__subtitle">
              Monitor every operator listing, inspect media and sustainability tags, and intervene if necessary.
            </p>
          </div>
          <n-button size="small" tertiary type="primary" :loading="tableLoading" @click="fetchListings">
            Refresh data
          </n-button>
        </div>

        <n-form inline label-placement="left" label-width="auto" class="abl__filters">
          <n-form-item label="Status">
            <n-select v-model:value="filters.status" :options="statusOptions" size="small" style="width: 170px" />
          </n-form-item>

          <n-form-item label="Visibility">
            <n-select v-model:value="filters.visibility" :options="visibilityOptions" size="small"
              style="width: 150px" />
          </n-form-item>

          <n-form-item label="Category">
            <n-select v-model:value="filters.category" :options="categoryOptions" size="small" style="width: 200px" />
          </n-form-item>

          <n-form-item label="Search">
            <n-input v-model:value="filters.search" size="small" clearable placeholder="Name, operator, location..."
              style="width: 240px" @keyup.enter="applySearch" />
          </n-form-item>

          <n-form-item>
            <n-button size="small" type="primary" :loading="tableLoading" @click="applySearch">
              Apply
            </n-button>
          </n-form-item>
          <n-form-item style="margin-left: auto;">
            <n-button size="small" quaternary :disabled="tableLoading" @click="resetFilters">
              Reset
            </n-button>
          </n-form-item>
        </n-form>
      </n-card>

      <n-card size="small" :bordered="false">
        <template v-if="fetchError">
          <n-alert type="error" show-icon closable @close="fetchError = ''">
            {{ fetchError }}
          </n-alert>
        </template>

        <template v-if="tableData.length">
          <n-data-table :columns="columns" :data="tableData" :loading="tableLoading" :bordered="false"
            :pagination="false" size="small" />
        </template>
        <template v-else>
          <n-empty description="No listings match the selected filters." :show-icon="!tableLoading" />
        </template>
      </n-card>

      <n-card v-if="!tableLoading" title="Listing summary" size="small" :bordered="false"
        :segmented="{ content: true }">
        <n-data-table size="small" :columns="summaryColumns" :data="summaryRows" :bordered="false" />
      </n-card>
    </n-space>

    <n-drawer v-model:show="detailState.visible" :width="560" placement="right">
      <n-drawer-content :title="detailState.data?.businessName ?? 'Listing details'">
        <n-space vertical size="large">
          <template v-if="detailState.loading">
            <n-text depth="3">Loading listing details…</n-text>
          </template>

          <template v-else-if="detailState.error">
            <n-alert type="error" show-icon>
              {{ detailState.error }}
            </n-alert>
          </template>

          <template v-else-if="detailState.data">
            <section class="detail-section">
              <h3>Business overview</h3>
              <div class="detail-grid">
                <div class="detail-label">Description</div>
                <div class="detail-value detail-value--multiline">
                  {{ detailState.data.description || 'No description provided.' }}
                </div>

                <div class="detail-label">Category</div>
                <div class="detail-value">
                  {{ detailState.data.category?.name || 'Not provided' }}
                </div>

                <div class="detail-label">Location</div>
                <div class="detail-value">
                  {{ detailState.data.location || 'Not provided' }}
                </div>

                <div class="detail-label">Price range</div>
                <div class="detail-value">
                  {{ detailState.data.priceRange || 'Not provided' }}
                </div>

                <div class="detail-label">Submitted</div>
                <div class="detail-value">
                  {{ detailState.data.submittedDate || 'Not available' }}
                </div>

                <div class="detail-label">Visibility</div>
                <div class="detail-value">
                  {{ detailState.data.visibility || 'Hidden' }}
                </div>
              </div>
            </section>

            <section class="detail-section">
              <h3>Operator contact</h3>
              <div class="detail-grid detail-grid--compact">
                <div class="detail-label">Name</div>
                <div class="detail-value">
                  {{ detailState.data.operator?.name || 'Not provided' }}
                </div>

                <div class="detail-label">Email</div>
                <div class="detail-value">
                  {{ detailState.data.operator?.email || 'Not provided' }}
                </div>

                <div class="detail-label">Phone</div>
                <div class="detail-value">
                  {{ detailState.data.operator?.phone || 'Not provided' }}
                </div>

                <div class="detail-label">Business type</div>
                <div class="detail-value">
                  {{ detailState.data.operator?.businessType || 'Not provided' }}
                </div>
              </div>
            </section>

            <section class="detail-section">
              <h3>Sustainability tags</h3>
              <template v-if="detailState.data.tags?.length">
                <n-space wrap>
                  <n-tag v-for="tag in detailState.data.tags" :key="tag.id" size="small" type="info" :bordered="false">
                    {{ tag.name }}
                  </n-tag>
                </n-space>
              </template>
              <template v-else>
                <n-text depth="3">No sustainability tags assigned.</n-text>
              </template>
            </section>

            <section class="detail-section">
              <h3>Image gallery</h3>
              <template v-if="detailState.data.images?.length">
                <n-space wrap size="small">
                  <n-image v-for="image in detailState.data.images" :key="image.id" :src="image.url"
                    :alt="image.caption || detailState.data.businessName" width="145" lazy />
                </n-space>
              </template>
              <template v-else>
                <n-empty description="No media uploaded for this listing." />
              </template>
            </section>

            <section class="detail-section">
              <h3>Verification history</h3>
              <template v-if="detailState.data.history?.length">
                <ul class="history-list">
                  <li v-for="entry in detailState.data.history" :key="entry.id">
                    <div class="history-list__header">
                      <n-tag :type="statusTagType(entry.status)" size="small" :bordered="false">
                        {{ entry.status }}
                      </n-tag>
                      <span>{{ entry.verifiedDate || '—' }}</span>
                    </div>
                    <div class="history-list__body">
                      <strong>{{ entry.adminName || 'Admin' }}</strong>
                      <p>{{ entry.remarks || 'No remarks provided.' }}</p>
                    </div>
                  </li>
                </ul>
              </template>
              <template v-else>
                <n-text depth="3">No verification activity recorded.</n-text>
              </template>
            </section>
          </template>

          <template v-else>
            <n-empty description="Select a listing to inspect its details." />
          </template>
        </n-space>
      </n-drawer-content>
    </n-drawer>

    <n-modal v-model:show="deleteState.show" preset="card" title="Remove listing"
      style="max-width: 420px; width: 100%;">
      <n-space vertical size="medium">
        <n-text depth="3">
          Removing <strong>{{ deleteState.listing?.businessName }}</strong> hides it from travelers and deletes its
          media. Optionally share a note for the operator.
        </n-text>
        <n-input v-model:value="deleteState.reason" type="textarea" placeholder="Reason for removal (optional)"
          :autosize="{ minRows: 3, maxRows: 5 }" />
      </n-space>
      <template #footer>
        <n-space justify="end">
          <n-button quaternary :disabled="deleteState.loading" @click="deleteState.show = false">
            Cancel
          </n-button>
          <n-button type="error" :loading="deleteState.loading" @click="confirmDelete">
            Remove listing
          </n-button>
        </n-space>
      </template>
    </n-modal>
  </div>
</template>

<style scoped>
.abl {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.abl__toolbar {
  background: #f9fafb;
}

.abl__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 16px;
}

.abl__title {
  margin: 0;
  font-size: 1.18rem;
  font-weight: 600;
}

.abl__subtitle {
  margin: 6px 0 0;
  color: var(--n-text-color-3, #6b7280);
  font-size: 0.92rem;
}

.abl__summary {
  margin-bottom: 16px;
}

.abl__filters {
  row-gap: 12px;
}

.detail-section {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.detail-section h3 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
}

.detail-grid {
  display: grid;
  grid-template-columns: 150px 1fr;
  column-gap: 1rem;
  row-gap: 0.45rem;
}

.detail-grid--compact {
  grid-template-columns: 130px 1fr;
}

.detail-label {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  color: #4b5563;
  letter-spacing: 0.08em;
}

.detail-value {
  font-size: 0.95rem;
  color: #111827;
}

.detail-value--multiline {
  white-space: pre-line;
}

.history-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.history-list__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.85rem;
  color: var(--n-text-color-3, #6b7280);
}

.history-list__body {
  font-size: 0.92rem;
  color: #1f2937;
}

.history-list__body p {
  margin: 4px 0 0;
  color: var(--n-text-color-3, #6b7280);
}
</style>
