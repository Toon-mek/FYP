<script setup>
import { computed, h, reactive, ref, watch, nextTick } from 'vue'
import {
  NAlert,
  NButton,
  NCard,
  NDataTable,
  NEmpty,
  NForm,
  NFormItem,
  NInput,
  NList,
  NListItem,
  NModal,
  NScrollbar,
  NSelect,
  NSpace,
  NTag,
  NSwitch,
  NText,
} from 'naive-ui'
import { useMessage } from 'naive-ui'
import SimplePagination from './shared/SimplePagination.vue'

const props = defineProps({
  apiBase: {
    type: String,
    required: true,
  },
  operatorId: {
    type: [Number, String],
    default: null,
  },
  listings: {
    type: Array,
    default: () => [],
  },
  removalHistory: {
    type: Array,
    default: () => [],
  },
  removalHistoryLoading: {
    type: Boolean,
    default: false,
  },
  removalHistoryError: {
    type: String,
    default: null,
  },
})

const emit = defineEmits([
  'update:listings',
  'operator-updated',
  'request-removal-history',
  'clear-removal-history-error',
  'listing-touched',
])

const listingItems = ref([])
const pageSize = 5
const currentPage = ref(1)
const filteredListings = computed(() =>
  listingItems.value.filter((item) => (item.status || '').toLowerCase() !== 'rejected'),
)
const paginatedListings = computed(() => {
  const start = (currentPage.value - 1) * pageSize
  return filteredListings.value.slice(start, start + pageSize)
})
const removalHistoryEntries = computed(() =>
  Array.isArray(props.removalHistory) ? props.removalHistory : [],
)
const removalHistoryPageSize = 3
const removalHistoryPage = ref(1)
const paginatedRemovalHistory = computed(() => {
  const start = (removalHistoryPage.value - 1) * removalHistoryPageSize
  return removalHistoryEntries.value.slice(start, start + removalHistoryPageSize)
})
watch(
  () => removalHistoryEntries.value.length,
  () => {
    const maxPage = Math.max(1, Math.ceil(removalHistoryEntries.value.length / removalHistoryPageSize))
    if (removalHistoryPage.value > maxPage) {
      removalHistoryPage.value = maxPage
    }
  },
)
const removalHistoryError = computed(() => props.removalHistoryError)
const removalHistoryLoading = computed(() => props.removalHistoryLoading)
watch(
  () => filteredListings.value.length,
  () => {
    const maxPage = Math.max(1, Math.ceil(filteredListings.value.length / pageSize))
    if (currentPage.value > maxPage) currentPage.value = maxPage
  },
)

watch(
  () => props.listings,
  (value) => {
    listingItems.value = Array.isArray(value) ? value.map(normalizeListingItem) : []
  },
  { immediate: true, deep: true },
)

const listingActionError = ref('')
const autoSaveMessage = ref('')
const isListingSaving = ref(false)
const previewModalVisible = ref(false)
const previewListing = ref(null)
const editModalVisible = ref(false)
const historyModalVisible = ref(false)
const editForm = reactive({
  id: null,
  name: '',
  phone: '',
  email: '',
  address: '',
  highlight: '',
  status: '',
  visibility: '',
  category: '',
})
const editErrors = reactive({})
const message = useMessage()

const statusOptions = [
  { label: 'Pending Review', value: 'Pending Review' },
  { label: 'Approved', value: 'Approved' },
  { label: 'Rejected', value: 'Rejected' },
  { label: 'Active', value: 'Active' },
]

const visibilityOptions = [
  { label: 'Visible', value: 'Visible' },
  { label: 'Hidden', value: 'Hidden' },
]

const listingNameCellStyle = {
  display: 'flex',
  flexDirection: 'column',
  gap: '4px',
}

const listingNameStyle = {
  fontWeight: 600,
}

const listingMetaStyle = {
  fontSize: '0.85rem',
  color: 'rgba(0, 0, 0, 0.6)',
}

function formatVisibilityLabel(visibility) {
  return visibility === 'Visible' ? 'Visible' : 'Hidden'
}

function handleOpenRemovalHistory() {
  historyModalVisible.value = true
  emit('request-removal-history')
}

function handleClearRemovalHistoryError() {
  emit('clear-removal-history-error')
}

function formatHistoryDate(value) {
  if (!value) return '—'
  try {
    return new Date(value).toLocaleString()
  } catch {
    return value
  }
}

function emitListingsUpdate(list) {
  emit('update:listings', list.map((item) => ({ ...item })))
}

function extractListingId(listing) {
  if (!listing || typeof listing !== 'object') return null
  if (listing.listingId != null) {
    const numeric = Number(listing.listingId)
    return Number.isNaN(numeric) ? null : numeric
  }
  if (typeof listing.id === 'string' || typeof listing.id === 'number') {
    const numeric = Number(listing.id)
    if (!Number.isNaN(numeric) && numeric > 0) return numeric
    const match = String(listing.id).match(/(\d+)/)
    if (match && match[1]) {
      const parsed = Number(match[1])
      return Number.isNaN(parsed) ? null : parsed
    }
  }
  return null
}

function normalizeListingItem(raw) {
  if (!raw || typeof raw !== 'object') return raw
  const listingId = extractListingId(raw)
  const visibility = raw.visibility === 'Visible' ? 'Visible' : 'Hidden'
  const status =
    raw.status && String(raw.status).trim() !== ''
      ? raw.status
      : visibility === 'Visible'
        ? 'Active'
        : 'Pending Review'
  const contact = {
    ...(raw.contact ?? {}),
    phone: raw.contact?.phone ?? raw.phone ?? '',
    email: raw.contact?.email ?? raw.email ?? '',
  }
  const sourceLastUpdated =
    raw.lastUpdated && !Number.isNaN(new Date(raw.lastUpdated).getTime())
      ? raw.lastUpdated
      : null
  const lastUpdated = sourceLastUpdated ?? new Date().toISOString()

  return {
    ...raw,
    listingId: listingId ?? raw.listingId ?? null,
    visibility,
    status,
    name: raw.name ?? raw.businessName ?? 'Listing',
    category: raw.category ?? raw.type ?? 'Uncategorised',
    address: raw.address ?? raw.location ?? '',
    highlight: raw.highlight ?? raw.description ?? '',
    contact,
    lastUpdated,
    lastUpdatedDisplay: raw.lastUpdatedDisplay ?? new Date(lastUpdated).toLocaleString(),
  }
}

const listingColumns = computed(() => [
  {
    title: 'Listing',
    key: 'name',
    render(row) {
      return h('div', { style: listingNameCellStyle }, [
        h('div', { style: listingNameStyle }, row.name),
        h('div', { style: listingMetaStyle }, `${row.category} - ${row.address}`),
      ])
    },
  },
  {
    title: 'Status',
    key: 'status',
    render(row) {
      const statusLower = String(row.status ?? '').toLowerCase()
      let type = 'default'
      if (statusLower === 'approved' || statusLower === 'active') {
        type = 'success'
      } else if (statusLower === 'rejected') {
        type = 'error'
      } else if (statusLower === 'pending review') {
        type = 'warning'
      }
      return h(
        NTag,
        { type, bordered: false },
        { default: () => row.status },
      )
    },
  },
  {
    title: 'Visibility',
    key: 'visibility',
    render(row) {
      const isVisible = row.visibility === 'Visible'
      return h(
        NSwitch,
        {
          size: 'small',
          value: isVisible,
          round: true,
          onUpdateValue: (checked) => toggleVisibility(row, checked ? 'Visible' : 'Hidden'),
        },
        {
          checked: () => 'Visible',
          unchecked: () => 'Hidden',
        },
      )
    },
  },
  {
    title: 'Actions',
    key: 'actions',
    render(row) {
      return h(
        NSpace,
        { size: 'small' },
        {
          default: () => [
            h(
              NButton,
              {
                text: true,
                size: 'small',
                onClick: () => openEdit(row),
              },
              { default: () => 'Edit' },
            ),
            h(
              NButton,
              {
                text: true,
                size: 'small',
                onClick: () => openPreview(row),
              },
              { default: () => 'Preview' },
            ),
            h(
              NButton,
              {
                text: true,
                size: 'small',
                type: 'error',
                onClick: () => deleteListing(row),
              },
              { default: () => 'Delete' },
            ),
          ],
        },
      )
    },
  },
])

function openPreview(listing) {
  previewListing.value = { ...normalizeListingItem(listing) }
  previewModalVisible.value = true
}

function openEdit(listing) {
  editForm.id = listing.id ?? listing.listingId
  editForm.name = listing.name
  editForm.phone = listing.contact?.phone || ''
  editForm.email = listing.contact?.email || ''
  editForm.address = listing.address
  editForm.highlight = listing.highlight
  editForm.status = listing.status
  editForm.visibility = listing.visibility
  editForm.category = listing.category
  Object.keys(editErrors).forEach((key) => delete editErrors[key])
  editModalVisible.value = true
}

function validateEditForm() {
  const errors = {}
  if (!editForm.name?.trim()) errors.name = 'Name is required.'
  if (!editForm.email?.trim()) {
    errors.email = 'Email is required.'
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(editForm.email)) {
    errors.email = 'Provide a valid email.'
  }
  if (!editForm.phone?.trim()) errors.phone = 'Phone number is required.'
  if (!editForm.address?.trim()) errors.address = 'Address is required.'

  Object.keys(editErrors).forEach((key) => delete editErrors[key])
  Object.assign(editErrors, errors)

  return Object.keys(errors).length === 0
}

async function saveListingEdits() {
  if (!validateEditForm()) {
    return
  }

  if (!props.operatorId) {
    listingActionError.value = 'Operator account not loaded. Please refresh and try again.'
    message.error(listingActionError.value)
    return
  }

  const listingIdNumeric = extractListingId(editForm)
  if (listingIdNumeric == null) {
    listingActionError.value = 'Unable to identify listing for update.'
    message.error(listingActionError.value)
    return
  }

  isListingSaving.value = true
  listingActionError.value = ''
  autoSaveMessage.value = ''

  try {
    const response = await fetch(`${props.apiBase}/operator/listings.php`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        operatorId: props.operatorId,
        listingId: listingIdNumeric,
        name: editForm.name.trim(),
        phone: editForm.phone.trim(),
        email: editForm.email.trim(),
        address: editForm.address.trim(),
        description: editForm.highlight?.trim() || '',
        status: editForm.status,
        visibility: editForm.visibility,
        category: editForm.category,
      }),
    })

    const result = await response.json().catch(() => null)
    if (!response.ok || !result || result.ok !== true || !result.listing) {
      throw new Error(result?.error || `Failed to update listing (HTTP ${response.status})`)
    }

    listingItems.value = listingItems.value.map((item) =>
      extractListingId(item) === result.listing.listingId ? normalizeListingItem(result.listing) : item,
    )
    emitListingsUpdate(listingItems.value)
    if (result.operator) {
      emit('operator-updated', result.operator)
    }
    autoSaveMessage.value =
      result.message ?? `${result.listing.name} updated successfully.`
    message.success(autoSaveMessage.value)
    editModalVisible.value = false
  } catch (error) {
    listingActionError.value =
      error instanceof Error ? error.message : 'Unable to update listing.'
    message.error(listingActionError.value)
  } finally {
    isListingSaving.value = false
  }
}

async function toggleVisibility(listing, targetVisibility = null) {
  if (!props.operatorId) {
    listingActionError.value = 'Operator account not loaded. Please refresh and try again.'
    message.error(listingActionError.value)
    return
  }

  const listingIdNumeric = extractListingId(listing)
  if (listingIdNumeric == null) {
    listingActionError.value = 'Unable to identify listing for update.'
    message.error(listingActionError.value)
    return
  }

  listingActionError.value = ''
  autoSaveMessage.value = ''

  const previousVisibility = listing.visibility
  const nextVisibility =
    targetVisibility && (targetVisibility === 'Visible' || targetVisibility === 'Hidden')
      ? targetVisibility
      : previousVisibility === 'Visible'
        ? 'Hidden'
        : 'Visible'

  const nowIso = new Date().toISOString()
  const displayTimestamp = new Date(nowIso).toLocaleString()
  listing.visibility = nextVisibility
  listing.lastUpdated = nowIso
  listing.lastUpdatedDisplay = displayTimestamp

  const listingIndex = listingItems.value.findIndex(
    (item) => extractListingId(item) === listingIdNumeric,
  )
  const currentListing = listingIndex !== -1 ? listingItems.value[listingIndex] : listing
  const previousSnapshot = { ...currentListing }

  if (listingIndex !== -1) {
    const updatedListing = {
      ...currentListing,
      visibility: nextVisibility,
      lastUpdated: nowIso,
      lastUpdatedDisplay: displayTimestamp,
    }
    listingItems.value.splice(listingIndex, 1, updatedListing)
  }

  await nextTick()
  emitListingsUpdate(listingItems.value)

  try {
    const response = await fetch(`${props.apiBase}/operator/listings.php`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        operatorId: props.operatorId,
        listingId: listingIdNumeric,
        visibility: nextVisibility,
      }),
    })

    const result = await response.json().catch(() => null)
    if (!response.ok || !result || result.ok !== true || !result.listing) {
      throw new Error(result?.error || `Failed to update visibility (HTTP ${response.status})`)
    }

    const normalizedListing = normalizeListingItem(result.listing)
    normalizedListing.lastUpdated = nowIso
    normalizedListing.lastUpdatedDisplay = displayTimestamp
    listingItems.value = listingItems.value.map((item) =>
      extractListingId(item) === normalizedListing.listingId ? normalizedListing : item,
    )
    emitListingsUpdate(listingItems.value)
    if (result.operator) {
      emit('operator-updated', result.operator)
    }
    autoSaveMessage.value =
      result.message ??
      (nextVisibility === 'Visible'
        ? `${result.listing?.name ?? listing.name} is now visible to travelers.`
        : `${result.listing?.name ?? listing.name} has been hidden from travelers.`)
    message.success(autoSaveMessage.value)
  } catch (error) {
    listing.visibility = previousVisibility
    listing.lastUpdated = previousSnapshot.lastUpdated ?? listing.lastUpdated
    listing.lastUpdatedDisplay = previousSnapshot.lastUpdatedDisplay ?? listing.lastUpdatedDisplay
    listingActionError.value =
      error instanceof Error ? error.message : 'Unable to toggle listing visibility.'
    message.error(listingActionError.value)
  }
}

async function deleteListing(listing) {
  if (typeof window !== 'undefined') {
    const proceed = window.confirm(`Delete ${listing.name}? This action cannot be undone.`)
    if (!proceed) {
      return
    }
  }

  if (!props.operatorId) {
    listingActionError.value = 'Operator account not loaded. Please refresh and try again.'
    message.error(listingActionError.value)
    return
  }

  const listingIdNumeric = extractListingId(listing)
  if (listingIdNumeric == null) {
    listingActionError.value = 'Unable to delete listing.'
    message.error(listingActionError.value)
    return
  }

  listingActionError.value = ''
  autoSaveMessage.value = ''

  try {
    const response = await fetch(`${props.apiBase}/operator/listings.php`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        operatorId: props.operatorId,
        listingId: listingIdNumeric,
      }),
    })

    const result = await response.json().catch(() => null)
    if (!response.ok || !result || result.ok !== true) {
      throw new Error(result?.error || `Failed to delete listing (HTTP ${response.status})`)
    }

    listingItems.value = listingItems.value.filter(
      (item) => extractListingId(item) !== listingIdNumeric,
    )
    emitListingsUpdate(listingItems.value)
    autoSaveMessage.value = result.message ?? `${listing.name} removed from the platform.`
    message.success(autoSaveMessage.value)
    if (result.operator) {
      emit('operator-updated', result.operator)
    }
  } catch (error) {
    listingActionError.value =
      error instanceof Error ? error.message : 'Unable to delete listing. Please try again.'
    message.error(listingActionError.value)
  }
}
</script>

<template>
  <n-space vertical size="large">
    <n-card id="listings-panel" title="Manage listings" :segmented="{ content: true }">
      <n-text depth="3">
        Control visibility, update contact details, or remove listings. Each action mirrors the Manage Business Listing use case from your documentation.
      </n-text>

      <div class="removal-history-callout">
        <div>
          <div class="removal-history-callout__title">Removed listings history</div>
          <n-text depth="3">
            Review which listings administrators removed along with the date and reason. Entries are kept as read-only snapshots.
          </n-text>
        </div>
        <n-button size="small" type="primary" @click="handleOpenRemovalHistory">View history</n-button>
      </div>

      <n-alert
        v-if="listingActionError"
        type="error"
        show-icon
        style="margin-top: 16px;"
      >
        {{ listingActionError }}
      </n-alert>

      <template v-if="filteredListings.length">
        <n-data-table :columns="listingColumns" :data="paginatedListings" :single-line="false" style="margin-top: 16px;" />
        <n-space justify="end" style="margin-top: 12px;">
          <SimplePagination
            v-model:page="currentPage"
            :page-size="pageSize"
            :item-count="filteredListings.length"
          />
        </n-space>
     </template>
      <n-empty v-else description="No listings yet. Submit a business profile to get started." style="margin-top: 16px;" />
    </n-card>
  </n-space>

  <n-modal
    v-model:show="historyModalVisible"
    preset="card"
    title="Removed listings history"
    :style="{ maxWidth: '640px', width: '100%' }"
    :header-style="{ borderBottom: 'none' }"
    :content-style="{ paddingTop: '0' }"
  >
    <n-space vertical size="medium">
      <n-text depth="3">
        Listings removed by administrators stay archived here so you can revisit the details and the reason they were taken down.
      </n-text>
      <n-alert
        v-if="removalHistoryError"
        type="error"
        closable
        @close="handleClearRemovalHistoryError"
      >
        {{ removalHistoryError }}
      </n-alert>
      <n-alert
        v-else-if="removalHistoryLoading"
        type="info"
      >
        Fetching removal history…
      </n-alert>
      <n-empty
        v-else-if="!removalHistoryEntries.length"
        description="No listings have been removed yet."
      />
      <template v-else>
        <div class="removal-history-list">
          <n-list bordered :show-divider="false">
            <n-list-item
              v-for="entry in paginatedRemovalHistory"
              :key="entry.id ?? entry.listingId"
            >
              <div class="removal-history-card">
                <div class="removal-history-card__header">
                  <div>
                    <div class="removal-history-card__title">{{ entry.businessName }}</div>
                    <n-text depth="3" v-if="entry.location || entry.details?.address">
                      {{ entry.location || entry.details?.address }}
                    </n-text>
                  </div>
                  <div class="removal-history-card__badges">
                    <n-tag size="small" type="error" bordered>Removed</n-tag>
                    <n-tag size="small" type="info" v-if="entry.category" bordered>{{ entry.category }}</n-tag>
                  </div>
                </div>
                <n-text depth="3" class="removal-history-card__description">
                  {{ entry.details?.description || 'No description captured for this listing.' }}
                </n-text>
                <n-alert type="warning" size="small" class="removal-history-card__reason" v-if="entry.removalReason">
                  <strong>Reason:</strong> {{ entry.removalReason }}
                </n-alert>
                <n-text depth="3">
                  Removed {{ formatHistoryDate(entry.removedAt) }}
                  <template v-if="entry.removedBy"> by {{ entry.removedBy }}</template>
                </n-text>
              </div>
            </n-list-item>
          </n-list>
        </div>
        <n-space justify="end" style="margin-top: 12px;">
          <SimplePagination
            v-model:page="removalHistoryPage"
            :page-size="removalHistoryPageSize"
            :item-count="removalHistoryEntries.length"
          />
        </n-space>
      </template>
    </n-space>
    <template #footer>
      <n-space justify="end">
        <n-button type="primary" @click="historyModalVisible = false">Close</n-button>
      </n-space>
    </template>
  </n-modal>

  <n-modal
    v-model:show="previewModalVisible"
    preset="card"
    :title="previewListing?.name || 'Preview listing'"
    :style="{ maxWidth: '520px', width: '100%' }"
  >
    <n-space vertical size="small" v-if="previewListing">
      <div class="preview-row">
        <span class="preview-label">Category</span>
        <span>{{ previewListing.category }}</span>
      </div>
      <div class="preview-row">
        <span class="preview-label">Status</span>
        <span>{{ previewListing.status }}</span>
      </div>
      <div class="preview-row">
        <span class="preview-label">Address</span>
        <span>{{ previewListing.address }}</span>
      </div>
      <div class="preview-row">
        <span class="preview-label">Contact</span>
        <span>{{ previewListing.contact?.phone }} · {{ previewListing.contact?.email }}</span>
      </div>
      <div class="preview-row">
        <span class="preview-label">Highlights</span>
        <span>{{ previewListing.highlight }}</span>
      </div>
      <div class="preview-row">
        <span class="preview-label">Review notes</span>
        <span>{{ previewListing.reviewNotes }}</span>
      </div>
      <div class="preview-row">
        <span class="preview-label">Visibility</span>
        <span>{{ formatVisibilityLabel(previewListing.visibility) }}</span>
      </div>
      <div class="preview-row">
        <span class="preview-label">Last updated</span>
        <span>{{ new Date(previewListing.lastUpdated).toLocaleString() }}</span>
      </div>
    </n-space>
    <template #footer>
      <n-space justify="end">
        <n-button type="primary" @click="previewModalVisible = false">Close preview</n-button>
      </n-space>
    </template>
  </n-modal>

  <n-modal
    v-model:show="editModalVisible"
    preset="card"
    title="Edit listing"
    :style="{ maxWidth: '480px', width: '100%' }"
  >
    <n-space vertical size="large">
      <n-form label-placement="top" label-width="auto">
        <n-form-item label="Listing name" :feedback="editErrors.name" :validation-status="editErrors.name ? 'error' : undefined">
          <n-input v-model:value="editForm.name" />
        </n-form-item>
        <n-form-item label="Email">
          <n-input v-model:value="editForm.email" readonly disabled />
        </n-form-item>
        <n-form-item label="Phone">
          <n-input v-model:value="editForm.phone" readonly disabled />
        </n-form-item>
        <n-form-item label="Address" :feedback="editErrors.address" :validation-status="editErrors.address ? 'error' : undefined">
          <n-input v-model:value="editForm.address" />
        </n-form-item>
        <n-form-item label="Highlights">
          <n-input v-model:value="editForm.highlight" type="textarea" :rows="3" />
        </n-form-item>
        <n-form-item label="Status">
          <n-input v-model:value="editForm.status" readonly disabled />
        </n-form-item>
      </n-form>
      <n-space justify="end">
        <n-button tertiary type="primary" @click="editModalVisible = false">Cancel</n-button>
        <n-button type="primary" :loading="isListingSaving" :disabled="isListingSaving" @click="saveListingEdits">
          Save changes
        </n-button>
      </n-space>
    </n-space>
  </n-modal>
</template>

<style scoped>
.preview-row {
  display: flex;
  gap: 12px;
  font-size: 0.95rem;
  color: #475467;
}

.preview-label {
  min-width: 110px;
  font-weight: 600;
  color: #1f2937;
}

.preview-row span:last-child {
  flex: 1;
}

.removal-history-callout {
  margin-top: 16px;
  padding: 12px 16px;
  border-radius: 12px;
  border: 1px solid rgba(15, 23, 42, 0.08);
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: center;
  background: rgba(15, 23, 42, 0.02);
}

.removal-history-callout__title {
  font-weight: 600;
  margin-bottom: 4px;
}

.removal-history-list {
  display: flex;
  gap: 12px;
}

.removal-history-card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 14px;
  border: 1px solid rgba(15, 23, 42, 0.1);
  border-radius: 12px;
  background: rgba(15, 23, 42, 0.02);
}

.removal-history-card__header {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  align-items: flex-start;
}

.removal-history-card__title {
  font-weight: 700;
  font-size: 1.1rem;
}

.removal-history-card__badges {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.removal-history-card__description {
  white-space: pre-line;
}

.removal-history-card__reason :deep(.n-alert__body) {
  padding: 4px 0;
}
</style>
