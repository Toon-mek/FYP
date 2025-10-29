<script setup>
import { computed, h, reactive, ref, watch } from 'vue'
import {
  NAlert,
  NButton,
  NCard,
  NDataTable,
  NEmpty,
  NForm,
  NFormItem,
  NInput,
  NModal,
  NSelect,
  NSpace,
  NTag,
  NText,
} from 'naive-ui'

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
})

const emit = defineEmits(['update:listings', 'operator-updated'])

const listingItems = ref([])

watch(
  () => props.listings,
  (value) => {
    listingItems.value = Array.isArray(value) ? value.map((item) => ({ ...item })) : []
  },
  { immediate: true, deep: true },
)

const listingActionError = ref('')
const autoSaveMessage = ref('')
const isListingSaving = ref(false)
const previewModalVisible = ref(false)
const previewListing = ref(null)
const editModalVisible = ref(false)
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

const statusOptions = [
  { label: 'Active', value: 'Active' },
  { label: 'Pending Review', value: 'Pending Review' },
  { label: 'Hidden', value: 'Hidden' },
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
      const type = row.status === 'Active' ? 'success' : row.status === 'Hidden' ? 'warning' : 'info'
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
      const type = row.visibility === 'Visible' ? 'success' : 'default'
      return h(
        NTag,
        { type, bordered: true },
        { default: () => row.visibility },
      )
    },
  },
  {
    title: 'Last updated',
    key: 'lastUpdated',
    render(row) {
      const date = new Date(row.lastUpdated)
      return date.toLocaleString()
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
                onClick: () => toggleVisibility(row),
              },
              { default: () => (row.visibility === 'Visible' ? 'Hide' : 'Unhide') },
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
  previewListing.value = { ...listing }
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
    return
  }

  const listingIdNumeric = extractListingId(editForm)
  if (listingIdNumeric == null) {
    listingActionError.value = 'Unable to identify listing for update.'
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
      extractListingId(item) === result.listing.listingId ? result.listing : item,
    )
    emitListingsUpdate(listingItems.value)
    if (result.operator) {
      emit('operator-updated', result.operator)
    }
    autoSaveMessage.value =
      result.message ?? `${result.listing.name} updated successfully.`
    editModalVisible.value = false
  } catch (error) {
    listingActionError.value =
      error instanceof Error ? error.message : 'Unable to update listing.'
  } finally {
    isListingSaving.value = false
  }
}

async function toggleVisibility(listing) {
  if (!props.operatorId) {
    listingActionError.value = 'Operator account not loaded. Please refresh and try again.'
    return
  }

  const listingIdNumeric = extractListingId(listing)
  if (listingIdNumeric == null) {
    listingActionError.value = 'Unable to identify listing for update.'
    return
  }

  listingActionError.value = ''
  autoSaveMessage.value = ''

  const nextVisibility = listing.visibility === 'Visible' ? 'Hidden' : 'Visible'
  const nextStatus =
    nextVisibility === 'Visible'
      ? listing.status === 'Pending Review'
        ? 'Pending Review'
        : 'Active'
      : 'Hidden'

  try {
    const response = await fetch(`${props.apiBase}/operator/listings.php`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        operatorId: props.operatorId,
        listingId: listingIdNumeric,
        status: nextStatus,
        visibility: nextVisibility,
      }),
    })

    const result = await response.json().catch(() => null)
    if (!response.ok || !result || result.ok !== true || !result.listing) {
      throw new Error(result?.error || `Failed to update visibility (HTTP ${response.status})`)
    }

    listingItems.value = listingItems.value.map((item) =>
      extractListingId(item) === result.listing.listingId ? result.listing : item,
    )
    emitListingsUpdate(listingItems.value)
    if (result.operator) {
      emit('operator-updated', result.operator)
    }
    autoSaveMessage.value =
      result.message ?? `${result.listing.name} visibility updated successfully.`
  } catch (error) {
    listingActionError.value =
      error instanceof Error ? error.message : 'Unable to toggle listing visibility.'
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
    return
  }

  const listingIdNumeric = extractListingId(listing)
  if (listingIdNumeric == null) {
    listingActionError.value = 'Unable to delete listing.'
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
    if (result.operator) {
      emit('operator-updated', result.operator)
    }
  } catch (error) {
    listingActionError.value =
      error instanceof Error ? error.message : 'Unable to delete listing. Please try again.'
  }
}
</script>

<template>
  <n-space vertical size="large">
    <n-card id="listings-panel" title="Manage listings" :segmented="{ content: true }">
      <n-text depth="3">
        Control visibility, update contact details, or remove listings. Each action mirrors the Manage Business Listing use case from your documentation.
      </n-text>

      <n-alert
        v-if="listingActionError"
        type="error"
        show-icon
        style="margin-top: 16px;"
      >
        {{ listingActionError }}
      </n-alert>
      <n-alert v-if="autoSaveMessage" type="success" show-icon style="margin-top: 16px;">
        {{ autoSaveMessage }}
      </n-alert>

      <template v-if="listingItems.length">
        <n-data-table :columns="listingColumns" :data="listingItems" :single-line="false" style="margin-top: 16px;" />
      </template>
      <n-empty v-else description="No listings yet. Submit a business profile to get started." style="margin-top: 16px;" />
    </n-card>
  </n-space>

  <n-modal v-model:show="previewModalVisible" preset="card" :title="previewListing?.name || 'Preview listing'">
    <n-space vertical size="small" v-if="previewListing">
      <n-text depth="3">Category: {{ previewListing.category }}</n-text>
      <n-text depth="3">Status: {{ previewListing.status }} - Visibility: {{ previewListing.visibility }}</n-text>
      <n-text depth="3">Address: {{ previewListing.address }}</n-text>
      <n-text depth="3">Contact: {{ previewListing.contact?.phone }} - {{ previewListing.contact?.email }}</n-text>
      <n-text depth="3">Highlights: {{ previewListing.highlight }}</n-text>
      <n-text depth="3">Review notes: {{ previewListing.reviewNotes }}</n-text>
      <n-text depth="3">Last updated: {{ new Date(previewListing.lastUpdated).toLocaleString() }}</n-text>
    </n-space>
    <template #footer>
      <n-space justify="end">
        <n-button type="primary" @click="previewModalVisible = false">Close preview</n-button>
      </n-space>
    </template>
  </n-modal>

  <n-modal v-model:show="editModalVisible" preset="card" title="Edit listing">
    <n-space vertical size="large">
      <n-form label-placement="top" label-width="auto">
        <n-form-item label="Listing name" :feedback="editErrors.name" :validation-status="editErrors.name ? 'error' : undefined">
          <n-input v-model:value="editForm.name" />
        </n-form-item>
        <n-form-item label="Email" :feedback="editErrors.email" :validation-status="editErrors.email ? 'error' : undefined">
          <n-input v-model:value="editForm.email" />
        </n-form-item>
        <n-form-item label="Phone" :feedback="editErrors.phone" :validation-status="editErrors.phone ? 'error' : undefined">
          <n-input v-model:value="editForm.phone" />
        </n-form-item>
        <n-form-item label="Address" :feedback="editErrors.address" :validation-status="editErrors.address ? 'error' : undefined">
          <n-input v-model:value="editForm.address" />
        </n-form-item>
        <n-form-item label="Highlights">
          <n-input v-model:value="editForm.highlight" type="textarea" :rows="3" />
        </n-form-item>
        <n-form-item label="Status">
          <n-select v-model:value="editForm.status" :options="statusOptions" />
        </n-form-item>
        <n-form-item label="Visibility">
          <n-select v-model:value="editForm.visibility" :options="visibilityOptions" />
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
</style>
