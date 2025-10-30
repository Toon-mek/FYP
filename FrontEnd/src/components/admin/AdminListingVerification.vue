<script setup>
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import { NButton, NTag, useMessage } from 'naive-ui'

const props = defineProps({
  adminId: {
    type: Number,
    default: null,
  },
})

const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const message = useMessage()

const filters = reactive({
  status: 'pending',
  category: 'all',
  search: '',
})

const statusOptions = [
  { label: 'Pending review', value: 'pending' },
  { label: 'Approved', value: 'approved' },
  { label: 'Rejected', value: 'rejected' },
  { label: 'All statuses', value: 'all' },
]

const listings = ref([])
const loadingListings = ref(false)
const loadError = ref('')
const lastLoadedAt = ref('')

const selectedListingId = ref(null)
const selectedListing = ref(null)
const detailLoading = ref(false)
const showDetailDrawer = ref(false)
const remarksDraft = ref('')
const decisionLoading = ref(false)

const columns = computed(() => [
  {
    title: 'Listing',
    key: 'businessName',
    minWidth: 220,
    render(row) {
      return h('div', { style: 'display:flex; flex-direction:column; gap:6px;' }, [
        h('div', { style: 'font-weight:600; font-size:15px;' }, row.businessName),
        h(
          'div',
          { style: 'display:flex; flex-wrap:wrap; gap:6px; align-items:center;' },
          [
            row.category
              ? h(
                NTag,
                { size: 'small', type: 'info', bordered: false },
                { default: () => row.category }
              )
              : null,
            row.location
              ? h(
                'span',
                { style: 'font-size:12px; color: var(--n-text-color-3, #6b7280);' },
                row.location
              )
              : null,
          ].filter(Boolean)
        ),
      ])
    },
  },
  {
    title: 'Operator',
    key: 'operator',
    minWidth: 200,
    render(row) {
      const operator = row.operator || {}
      return h('div', { style: 'display:flex; flex-direction:column; gap:4px;' }, [
        h('span', { style: 'font-weight:500;' }, operator.name || operator.username || 'Operator'),
        operator.email
          ? h('span', { style: 'font-size:12px; color: var(--n-text-color-3, #6b7280);' }, operator.email)
          : null,
        operator.phone
          ? h('span', { style: 'font-size:12px; color: var(--n-text-color-3, #6b7280);' }, operator.phone)
          : null,
      ].filter(Boolean))
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
      const status = row.status || 'Pending Review'
      const type = statusBadgeType(status)
      return h(
        NTag,
        { type, size: 'small', bordered: false },
        { default: () => status }
      )
    },
  },
  {
    title: 'Actions',
    key: 'actions',
    width: 120,
    render(row) {
      return h(
        NButton,
        {
          size: 'small',
          type: 'primary',
          quaternary: true,
          onClick: () => openListing(row.id),
        },
        { default: () => 'Review' }
      )
    },
  },
])

const categoryOptions = computed(() => {
  const names = new Set()
  listings.value.forEach((item) => {
    if (item.category) {
      names.add(item.category)
    }
  })
  return [{ label: 'All categories', value: 'all' }, ...Array.from(names).map((name) => ({ label: name, value: name }))]
})

const filteredListings = computed(() => {
  const searchTerm = filters.search.trim().toLowerCase()
  return listings.value.filter((item) => {
    const byCategory = filters.category === 'all' || item.category === filters.category
    const inSearch =
      searchTerm === '' ||
      item.businessName?.toLowerCase().includes(searchTerm) ||
      item.operator?.name?.toLowerCase().includes(searchTerm) ||
      item.operator?.username?.toLowerCase().includes(searchTerm) ||
      item.location?.toLowerCase().includes(searchTerm) ||
      item.status?.toLowerCase().includes(searchTerm)

    return byCategory && inSearch
  })
})

const emptyStateDescription = computed(() => {
  if (filters.status === 'pending') {
    return 'All caught up. Pending submissions will appear here for review.'
  }
  if (filters.status === 'approved') {
    return 'No approved listings match your filters.'
  }
  if (filters.status === 'rejected') {
    return 'No rejected listings match your filters.'
  }
  return 'No listings found for the selected filters.'
})

const lastLoadedLabel = computed(() => (lastLoadedAt.value ? `Updated ${lastLoadedAt.value}` : ''))
const moderatorId = computed(() => (typeof props.adminId === 'number' && props.adminId > 0 ? props.adminId : 1))

watch(
  () => filters.status,
  () => {
    loadListings()
  }
)

onMounted(() => {
  loadListings()
})

async function loadListings() {
  loadingListings.value = true
  loadError.value = ''

  const query = new URLSearchParams()
  if (filters.status) {
    query.set('status', filters.status)
  }

  try {
    const response = await fetch(`${API_BASE}/admin/listing_verification.php?${query.toString()}`, {
      method: 'GET',
    })

    if (!response.ok) {
      throw new Error('Unable to load listings')
    }

    const body = await response.json()
    listings.value = Array.isArray(body.listings) ? body.listings : []
    lastLoadedAt.value = new Date().toLocaleString()
  } catch (error) {
    console.error(error)
    loadError.value = error instanceof Error ? error.message : 'Something went wrong while loading listings.'
    listings.value = []
  } finally {
    loadingListings.value = false
  }
}

async function openListing(id) {
  selectedListingId.value = id
  showDetailDrawer.value = true
  detailLoading.value = true
  selectedListing.value = null
  remarksDraft.value = ''

  try {
    const response = await fetch(`${API_BASE}/admin/listing_verification.php?listingId=${id}`, {
      method: 'GET',
    })

    if (!response.ok) {
      throw new Error('Unable to load listing details')
    }

    const body = await response.json()
    selectedListing.value = body.listing ?? null
  } catch (error) {
    console.error(error)
    message.error(error instanceof Error ? error.message : 'Failed to fetch listing details.')
    showDetailDrawer.value = false
  } finally {
    detailLoading.value = false
  }
}

async function handleDecision(decision) {
  if (!selectedListingId.value) {
    return
  }

  const remarks = remarksDraft.value.trim()
  if (remarks === '') {
    message.warning('Please share your decision notes with the operator.')
    return
  }

  decisionLoading.value = true

  try {
    const response = await fetch(`${API_BASE}/admin/listing_verification.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        listingId: selectedListingId.value,
        adminId: moderatorId.value,
        decision,
        remarks,
      }),
    })

    if (!response.ok) {
      const errorBody = await response.json().catch(() => ({}))
      const errorMessage = errorBody.error ?? 'Failed to submit your decision.'
      throw new Error(errorMessage)
    }

    const body = await response.json()
    message.success(body.status === 'Approved' ? 'Listing approved successfully.' : 'Listing rejected.')
    showDetailDrawer.value = false
    await loadListings()
  } catch (error) {
    console.error(error)
    message.error(error instanceof Error ? error.message : 'Failed to submit decision.')
  } finally {
    decisionLoading.value = false
  }
}

function statusBadgeType(status) {
  const normalised = (status || '').toLowerCase()
  if (normalised.includes('approve')) return 'success'
  if (normalised.includes('reject')) return 'error'
  if (normalised.includes('pending')) return 'warning'
  return 'default'
}
</script>

<template>
  <div class="listing-verification">
    <n-space vertical size="large">
      <n-card class="listing-verification__filters" size="small" :bordered="false">
        <template #header>
          <div class="listing-verification__header">
            <div>
              <h2 class="listing-verification__title">Listing verification queue</h2>
              <p class="listing-verification__subtitle">
                Review pending submissions from operators and share your decision.
              </p>
            </div>
            <n-button tertiary type="primary" size="small" :loading="loadingListings" @click="loadListings">
              Refresh
            </n-button>
          </div>
        </template>

        <n-space wrap size="small">
          <n-select v-model:value="filters.status" :options="statusOptions" size="small" style="width: 180px" />
          <n-select v-model:value="filters.category" :options="categoryOptions" size="small" style="width: 180px" />
          <n-input v-model:value="filters.search" size="small" clearable placeholder="Search listing or operator"
            style="width: 240px" />
        </n-space>

        <n-alert v-if="loadError" type="error" style="margin-top: 16px;">
          {{ loadError }}
        </n-alert>

        <div class="listing-verification__meta">
          <span>Showing {{ filteredListings.length }} listing(s)</span>
          <span v-if="lastLoadedLabel">{{ lastLoadedLabel }}</span>
        </div>
      </n-card>

      <n-card size="small" :bordered="false">
        <n-data-table :columns="columns" :data="filteredListings" :loading="loadingListings" :bordered="false"
          :pagination="false" size="small" striped />
        <n-empty v-if="!loadingListings && filteredListings.length === 0" style="margin-top: 24px;">
          <template #description>
            {{ emptyStateDescription }}
          </template>
        </n-empty>
      </n-card>
    </n-space>

    <n-drawer v-model:show="showDetailDrawer" :width="520" placement="right">
      <n-drawer-content :title="selectedListing?.businessName ?? 'Listing details'">
        <template #header-extra>
          <n-tag v-if="selectedListing?.status" :type="statusBadgeType(selectedListing.status)" size="small"
            :bordered="false">
            {{ selectedListing.status }}
          </n-tag>
        </template>

        <template #footer>
          <n-space justify="space-between" style="width: 100%;">
            <n-button quaternary @click="showDetailDrawer = false">Close</n-button>
            <n-space>
              <n-button size="small" type="success" :disabled="!selectedListing || decisionLoading"
                :loading="decisionLoading" @click="handleDecision('approve')">
                Approve listing
              </n-button>
              <n-button size="small" type="error" ghost :disabled="!selectedListing || decisionLoading"
                :loading="decisionLoading" @click="handleDecision('reject')">
                Reject listing
              </n-button>
            </n-space>
          </n-space>
        </template>

        <n-spin :show="detailLoading" size="large">
          <template #description>Loading listing details…</template>
          <n-space v-if="selectedListing" vertical size="large">
            <section class="listing-verification__section">
              <h3>Business overview</h3>
              <div class="detail-grid">
                <div class="detail-label">Description</div>
                <div class="detail-value detail-value--multiline">
                  {{ selectedListing.description || 'No description provided by the operator.' }}
                </div>

                <div class="detail-label">Category</div>
                <div class="detail-value">
                  <n-tag v-if="selectedListing.category?.name" type="info" :bordered="false" size="small">
                    {{ selectedListing.category.name }}
                  </n-tag>
                  <span v-else>Not provided</span>
                </div>

                <div class="detail-label">Location</div>
                <div class="detail-value">
                  {{ selectedListing.location || 'Not provided' }}
                </div>

                <div class="detail-label">Price range</div>
                <div class="detail-value">
                  {{ selectedListing.priceRange || 'Not provided' }}
                </div>

                <div class="detail-label">Submitted</div>
                <div class="detail-value">
                  {{ selectedListing.submittedDate || 'Not available' }}
                </div>

                <div class="detail-label">Visibility</div>
                <div class="detail-value">
                  {{ selectedListing.visibility || 'Hidden' }}
                </div>
              </div>
            </section>

            <section class="listing-verification__section">
              <h3>Operator contact</h3>
              <div class="detail-grid detail-grid--compact">
                <div class="detail-label">Name</div>
                <div class="detail-value">
                  {{ selectedListing.operator?.name || selectedListing.operator?.username || 'Not provided' }}
                </div>

                <div class="detail-label">Email</div>
                <div class="detail-value">
                  {{ selectedListing.operator?.email || 'Not provided' }}
                </div>

                <div class="detail-label">Phone</div>
                <div class="detail-value">
                  {{ selectedListing.operator?.phone || 'Not provided' }}
                </div>

                <div class="detail-label">Business type</div>
                <div class="detail-value">
                  {{ selectedListing.operator?.businessType || 'Not provided' }}
                </div>
              </div>
            </section>

            <section class="listing-verification__section">
              <h3>Image gallery</h3>
              <template v-if="selectedListing.images?.length">
                <n-space wrap size="small" class="image-grid">
                  <n-image v-for="image in selectedListing.images" :key="image.id" :src="image.url"
                    :alt="image.caption || selectedListing.businessName" width="140" lazy />
                </n-space>
              </template>
              <n-empty v-else description="No images uploaded for this listing." />
            </section>

            <section class="listing-verification__section">
              <h3>Decision notes</h3>
              <n-input v-model:value="remarksDraft" type="textarea" placeholder="Share your remarks with the operator…"
                :autosize="{ minRows: 3, maxRows: 5 }" :maxlength="400" show-count />
              <p class="listing-verification__hint">
                Your notes will be sent to the operator with every decision. Please share clear context before approving
                or rejecting a listing.
              </p>
            </section>
          </n-space>

          <n-empty v-else description="Listing details unavailable." />
        </n-spin>
      </n-drawer-content>
    </n-drawer>
  </div>
</template>

<style scoped>
.listing-verification {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.listing-verification__filters {
  background: #f9fafb;
}

.listing-verification__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.listing-verification__title {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 600;
}

.listing-verification__subtitle {
  margin: 4px 0 0;
  color: var(--n-text-color-3, #6b7280);
  font-size: 0.9rem;
}

.listing-verification__meta {
  margin-top: 16px;
  display: flex;
  justify-content: space-between;
  font-size: 0.85rem;
  color: var(--n-text-color-3, #6b7280);
}

.listing-verification__section {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.listing-verification__section h3 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
}

.listing-verification__description {
  margin: 0;
  line-height: 1.5;
  color: var(--n-text-color, #111827);
  white-space: pre-line;
}

.listing-verification__list {
  margin: 0;
  padding-left: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.95rem;
}

.listing-verification__list strong {
  font-weight: 600;
}

.listing-verification__hint {
  margin: 6px 0 0;
  font-size: 0.8rem;
  color: var(--n-text-color-3, #6b7280);
}

.detail-grid {
  display: grid;
  grid-template-columns: 150px 1fr;
  row-gap: 0.65rem;
  column-gap: 1rem;
  align-items: start;
}

.detail-grid--compact {
  grid-template-columns: 140px 1fr;
}

.detail-label {
  font-size: 0.78rem;
  font-weight: 600;
  color: #4b5563;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.detail-value {
  font-size: 0.95rem;
  color: #111827;
}

.detail-value--multiline {
  white-space: pre-line;
}

.detail-value :deep(a) {
  color: #111827;
}

.image-grid :deep(.n-image) {
  border-radius: 8px;
  overflow: hidden;
}
</style>
