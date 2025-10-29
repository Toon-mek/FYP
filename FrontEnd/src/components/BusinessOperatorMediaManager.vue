<script setup>
import { computed, reactive, ref, watch } from 'vue'
import {
  NAlert,
  NButton,
  NCard,
  NEmpty,
  NForm,
  NFormItem,
  NGrid,
  NGridItem,
  NInput,
  NModal,
  NSelect,
  NSpace,
  NTag,
  NText,
  NUpload,
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
  mediaAssets: {
    type: Array,
    default: () => [],
  },
  listings: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['update:mediaAssets'])

const MAX_MEDIA_SIZE_BYTES = 5 * 1024 * 1024
const SUPPORTED_MEDIA_TYPES = [
  'image/jpeg',
  'image/png',
  'image/gif',
  'image/webp',
  'application/pdf',
  'video/mp4',
  'video/quicktime',
  'image/heic',
]
const MEDIA_VALIDATION_MESSAGE =
  'Files must be JPG, PNG, GIF, WEBP, HEIC, PDF, MP4, or MOV and smaller than 5MB.'

const mediaTypes = [
  { label: 'Accommodation photo', value: 'Accommodation photo' },
  { label: 'Food menu (PDF)', value: 'Food menu' },
  { label: 'Tour highlight', value: 'Tour highlight' },
  { label: 'Promotional brochure', value: 'Promotional brochure' },
]

const primaryOptions = [
  { label: 'No', value: false },
  { label: 'Yes, mark as primary visual', value: true },
]

const mediaLibrary = ref([])

watch(
  () => props.mediaAssets,
  (value) => {
    mediaLibrary.value = Array.isArray(value) ? value.map((item) => ({ ...item })) : []
  },
  { immediate: true, deep: true },
)

const mediaListingOptions = computed(() =>
  (props.listings ?? []).map((listing) => ({
    label: listing.name ?? `Listing ${listing.id ?? listing.listingId ?? ''}`,
    value: listing.id ?? listing.listingId,
  })),
)

const isMediaSaving = ref(false)
const mediaSelectionMessage = ref('')
const mediaConfirmation = ref('')
const mediaErrors = reactive({})

const newMedia = reactive({
  label: '',
  type: 'Accommodation photo',
  isPrimary: false,
  listingId: null,
  file: null,
  fileName: '',
  mimeType: '',
  fileSize: 0,
  autoType: null,
})

const mediaEditModalVisible = ref(false)
const mediaEditForm = reactive({
  id: null,
  label: '',
  type: '',
  isPrimary: false,
  fileName: '',
  mimeType: '',
})
const mediaEditErrors = reactive({})

function emitLibraryUpdate() {
  emit('update:mediaAssets', mediaLibrary.value.map((item) => ({ ...item })))
}

function formatBytes(bytes) {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  const value = bytes / 1024 ** exponent
  return `${value.toFixed(value >= 10 || exponent === 0 ? 0 : 1)} ${units[exponent]}`
}

function detectMediaType(file) {
  const mime = file.type ?? ''
  const name = file.name ?? ''
  if (mime.startsWith('image/') || /\.(png|jpe?g|gif|webp|heic)$/i.test(name)) {
    return 'Accommodation photo'
  }
  if (mime === 'application/pdf' || /\.pdf$/i.test(name)) {
    return 'Food menu'
  }
  if (mime.startsWith('video/') || /\.(mp4|mov|qt)$/i.test(name)) {
    return 'Tour highlight'
  }
  return 'Promotional brochure'
}

function clearMediaSelection() {
  newMedia.file = null
  newMedia.fileName = ''
  newMedia.mimeType = ''
  newMedia.fileSize = 0
  newMedia.autoType = null
  mediaSelectionMessage.value = ''
  if (mediaErrors.file) delete mediaErrors.file
}

function handleMediaFileSelect({ file }) {
  if (!file) return false

  if (file.file?.size > MAX_MEDIA_SIZE_BYTES) {
    mediaErrors.file = 'File exceeds the 5MB limit.'
    return false
  }

  const mimeType = file.file?.type ?? ''
  if (mimeType && !SUPPORTED_MEDIA_TYPES.includes(mimeType)) {
    mediaErrors.file = MEDIA_VALIDATION_MESSAGE
    return false
  }

  newMedia.file = file.file ?? null
  newMedia.fileName = file.name ?? file.file?.name ?? ''
  newMedia.mimeType = mimeType
  newMedia.fileSize = file.file?.size ?? 0
  newMedia.autoType = detectMediaType(file.file ?? file)
  if (!newMedia.label) {
    newMedia.label = newMedia.autoType === 'Food menu' ? 'Menu upload' : newMedia.autoType
  }
  mediaSelectionMessage.value = 'File ready for upload.'
  if (newMedia.autoType) {
    newMedia.type = newMedia.autoType
  }
  if (mediaErrors.file) delete mediaErrors.file
  return false
}

function normalizeMediaAsset(asset) {
  if (!asset || typeof asset !== 'object') return asset
  const listingId =
    asset.listingId != null
      ? Number(asset.listingId)
      : asset.listing_id != null
        ? Number(asset.listing_id)
        : null
  return {
    ...asset,
    listingId,
    id: asset.id ?? asset.mediaId ?? asset.assetId ?? crypto.randomUUID?.() ?? Date.now(),
    lastUpdated: asset.lastUpdated ?? asset.updatedAt ?? new Date().toISOString(),
  }
}

async function addMediaAsset() {
  const errors = {}
  if (!newMedia.label.trim()) errors.label = 'Provide a title for the photo or menu.'
  if (!newMedia.file) errors.file = 'Select a media file to attach.'
  if (!newMedia.listingId) errors.listingId = 'Choose the listing this media belongs to.'

  Object.keys(mediaErrors).forEach((key) => delete mediaErrors[key])
  Object.assign(mediaErrors, errors)

  if (Object.keys(errors).length > 0) {
    mediaConfirmation.value = ''
    return
  }

  if (!props.operatorId) {
    mediaErrors.upload = 'Operator account not loaded. Please refresh and try again.'
    return
  }

  const formData = new FormData()
  formData.append('operatorId', props.operatorId)
  formData.append('listingId', newMedia.listingId)
  formData.append('title', newMedia.label.trim())
  formData.append('type', newMedia.type)
  formData.append('isPrimary', newMedia.isPrimary ? 'true' : 'false')
  formData.append('file', newMedia.file, newMedia.file.name ?? 'upload.bin')

  isMediaSaving.value = true
  mediaConfirmation.value = ''
  if (mediaErrors.upload) delete mediaErrors.upload

  try {
    const response = await fetch(`${props.apiBase}/operator/upload_media.php`, {
      method: 'POST',
      body: formData,
    })

    const payload = await response.json().catch(() => null)
    if (!response.ok || !payload || payload.ok !== true || !payload.asset) {
      throw new Error(payload?.error || `Failed to upload media (HTTP ${response.status})`)
    }

    const asset = normalizeMediaAsset(payload.asset)
    if (asset.isPrimary && asset.listingId != null) {
      mediaLibrary.value = mediaLibrary.value.map((item) =>
        item.listingId === asset.listingId ? { ...item, isPrimary: false } : item,
      )
    }
    mediaLibrary.value = [asset, ...mediaLibrary.value]

    emitLibraryUpdate()
    mediaConfirmation.value = `${asset.label} uploaded successfully.`
    mediaSelectionMessage.value = ''
    newMedia.label = ''
    newMedia.type = 'Accommodation photo'
    newMedia.isPrimary = false
    clearMediaSelection()
  } catch (error) {
    mediaErrors.upload = error instanceof Error ? error.message : 'Failed to upload media.'
  } finally {
    isMediaSaving.value = false
  }
}

function openMediaEdit(asset) {
  mediaEditForm.id = asset.id
  mediaEditForm.label = asset.label
  mediaEditForm.type = asset.type
  mediaEditForm.isPrimary = asset.isPrimary
  mediaEditForm.fileName = asset.fileName
  mediaEditForm.mimeType = asset.mimeType
  Object.keys(mediaEditErrors).forEach((key) => delete mediaEditErrors[key])
  mediaEditModalVisible.value = true
}

function saveMediaEdits() {
  const errors = {}
  if (!mediaEditForm.label?.trim()) errors.label = 'Provide a title for the media asset.'
  if (!mediaEditForm.type) errors.type = 'Select a media type.'

  Object.keys(mediaEditErrors).forEach((key) => delete mediaEditErrors[key])
  Object.assign(mediaEditErrors, errors)

  if (Object.keys(errors).length > 0) {
    return
  }

  const shouldSetPrimary = Boolean(mediaEditForm.isPrimary)
  mediaLibrary.value = mediaLibrary.value.map((asset) => {
    if (asset.id === mediaEditForm.id) {
      return {
        ...asset,
        label: mediaEditForm.label.trim(),
        type: mediaEditForm.type,
        isPrimary: shouldSetPrimary,
        lastUpdated: new Date().toISOString(),
      }
    }
    return shouldSetPrimary ? { ...asset, isPrimary: false } : asset
  })

  emitLibraryUpdate()
  mediaConfirmation.value = `${mediaEditForm.label.trim()} updated successfully.`
  mediaEditModalVisible.value = false
}

function markPrimaryMedia(target) {
  mediaLibrary.value = mediaLibrary.value.map((asset) => ({
    ...asset,
    isPrimary: asset.id === target.id,
  }))
  emitLibraryUpdate()
  mediaConfirmation.value = `${target.label} is now marked as the primary visual.`
}

function removeMediaAsset(target) {
  mediaLibrary.value = mediaLibrary.value.filter((asset) => asset.id !== target.id)
  emitLibraryUpdate()
  mediaConfirmation.value = `${target.label} removed from the gallery.`
}
</script>

<template>
  <n-space vertical size="large">
    <n-card id="media-manager" title="Upload photos and menus" :segmented="{ content: true }">
      <n-text depth="3">
        Add visuals that reinforce your business story. Use clear names so administrators can verify assets during review.
      </n-text>

      <n-form label-placement="top" label-width="auto" style="margin-top: 16px;">
        <n-grid cols="1 m:3" :x-gap="16" :y-gap="16">
          <n-grid-item>
            <n-form-item label="Media title" :feedback="mediaErrors.label" :validation-status="mediaErrors.label ? 'error' : undefined">
              <n-input id="operator-media-title" v-model:value="newMedia.label" placeholder="e.g. Sunset boardwalk photo" />
            </n-form-item>
          </n-grid-item>
          <n-grid-item>
            <n-form-item label="Type">
              <n-space vertical size="small" style="width: 100%;">
                <n-select v-model:value="newMedia.type" :options="mediaTypes" />
                <n-alert v-if="newMedia.autoType" type="info" size="small" bordered>
                  Detected: {{ newMedia.autoType }} (adjust if needed)
                </n-alert>
              </n-space>
            </n-form-item>
          </n-grid-item>
          <n-grid-item>
            <n-form-item
              label="Linked listing"
              :feedback="mediaErrors.listingId"
              :validation-status="mediaErrors.listingId ? 'error' : undefined"
            >
              <n-select
                v-model:value="newMedia.listingId"
                :options="mediaListingOptions"
                :disabled="!mediaListingOptions.length"
                placeholder="Select linked listing"
              />
            </n-form-item>
          </n-grid-item>
          <n-grid-item>
            <n-form-item label="Primary asset">
              <n-select v-model:value="newMedia.isPrimary" :options="primaryOptions" />
            </n-form-item>
          </n-grid-item>
          <n-grid-item span="1 m:3">
            <n-form-item label="Attachment" :feedback="mediaErrors.file" :validation-status="mediaErrors.file ? 'error' : undefined">
              <n-upload
                :max="1"
                :show-file-list="false"
                accept=".jpg,.jpeg,.png,.gif,.webp,.heic,.pdf,.mp4,.mov"
                @before-upload="handleMediaFileSelect"
              >
                <n-button tertiary type="primary">Select file</n-button>
              </n-upload>
              <template v-if="newMedia.fileName">
                <div class="media-file-preview">
                  <div class="media-file-preview__header">
                    <n-text depth="3">
                      {{ newMedia.fileName }}<span v-if="newMedia.fileSize"> ({{ formatBytes(newMedia.fileSize) }})</span>
                    </n-text>
                    <n-button text type="error" size="tiny" @click="clearMediaSelection">
                      Remove file
                    </n-button>
                  </div>
                  <n-text v-if="newMedia.mimeType" depth="3">MIME: {{ newMedia.mimeType }}</n-text>
                  <n-tag type="info" size="small" bordered>
                    Detected type: {{ newMedia.type }}
                  </n-tag>
                </div>
              </template>
            </n-form-item>
          </n-grid-item>
        </n-grid>

        <n-alert
          v-if="!mediaListingOptions.length"
          type="warning"
          size="small"
          show-icon
          style="margin-top: 12px;"
        >
          Submit a business listing before uploading media so the asset can be linked.
        </n-alert>

        <n-space justify="end" style="margin-top: 12px;">
          <n-button
            type="primary"
            :loading="isMediaSaving"
            :disabled="isMediaSaving || !mediaListingOptions.length"
            @click.prevent="addMediaAsset"
          >
            Add media
          </n-button>
        </n-space>
      </n-form>

      <n-alert
        v-if="mediaSelectionMessage"
        type="info"
        show-icon
        style="margin-top: 12px;"
      >
        {{ mediaSelectionMessage }}
      </n-alert>
      <n-alert
        v-else-if="mediaConfirmation"
        type="success"
        show-icon
        style="margin-top: 12px;"
      >
        {{ mediaConfirmation }}
      </n-alert>
      <n-alert
        v-else-if="mediaErrors.upload"
        type="error"
        show-icon
        style="margin-top: 12px;"
      >
        {{ mediaErrors.upload }}
      </n-alert>
    </n-card>

    <n-card title="Media library" :segmented="{ content: true }">
      <template v-if="mediaLibrary.length">
        <n-grid cols="1 m:2" :x-gap="18" :y-gap="18">
          <n-grid-item v-for="asset in mediaLibrary" :key="asset.id">
            <n-card size="small" :segmented="{ content: true, footer: true }" style="height: 100%;">
              <n-space vertical size="small">
                <div style="font-weight: 600;">{{ asset.label }}</div>
                <n-tag type="info" size="small" bordered>{{ asset.type }}</n-tag>
                <n-text depth="3">Status: {{ asset.status }}</n-text>
                <n-text depth="3">Last updated: {{ new Date(asset.lastUpdated).toLocaleString() }}</n-text>
                <n-text depth="3">
                  File: {{ asset.fileName || '-' }}<span v-if="asset.fileSize"> ({{ formatBytes(asset.fileSize) }})</span>
                </n-text>
                <n-text depth="3">
                  Linked listing: {{ asset.listingName || '-' }}
                </n-text>
                <n-text v-if="asset.mimeType" depth="3">MIME: {{ asset.mimeType }}</n-text>
                <n-tag v-if="asset.isPrimary" type="success" size="small" bordered>Primary visual</n-tag>
              </n-space>
              <template #footer>
                <n-space justify="space-between" align="center">
                  <n-space size="small">
                    <n-button text type="primary" @click="openMediaEdit(asset)">
                      Edit
                    </n-button>
                    <n-button text type="primary" @click="markPrimaryMedia(asset)">
                      {{ asset.isPrimary ? 'Primary' : 'Make primary' }}
                    </n-button>
                  </n-space>
                  <n-button text type="error" @click="removeMediaAsset(asset)">
                    Remove
                  </n-button>
                </n-space>
              </template>
            </n-card>
          </n-grid-item>
        </n-grid>
      </template>
      <template v-else>
        <n-empty description="No media uploaded yet." />
      </template>
    </n-card>
  </n-space>

  <n-modal v-model:show="mediaEditModalVisible" preset="card" title="Edit media asset">
    <n-space vertical size="large">
      <n-form label-placement="top" label-width="auto">
        <n-form-item label="Title" :feedback="mediaEditErrors.label" :validation-status="mediaEditErrors.label ? 'error' : undefined">
          <n-input v-model:value="mediaEditForm.label" />
        </n-form-item>
        <n-form-item label="Type" :feedback="mediaEditErrors.type" :validation-status="mediaEditErrors.type ? 'error' : undefined">
          <n-select v-model:value="mediaEditForm.type" :options="mediaTypes" />
        </n-form-item>
        <n-form-item label="Primary asset">
          <n-select v-model:value="mediaEditForm.isPrimary" :options="primaryOptions" />
        </n-form-item>
        <n-form-item label="File name">
          <n-input v-model:value="mediaEditForm.fileName" disabled />
        </n-form-item>
        <n-form-item label="MIME type">
          <n-input v-model:value="mediaEditForm.mimeType" disabled />
        </n-form-item>
      </n-form>
      <n-space justify="end">
        <n-button tertiary type="primary" @click="mediaEditModalVisible = false">Cancel</n-button>
        <n-button type="primary" @click="saveMediaEdits">Save changes</n-button>
      </n-space>
    </n-space>
  </n-modal>
</template>

<style scoped>
.media-file-preview {
  margin-top: 8px;
  padding: 10px 12px;
  border: 1px solid #e5e8eb;
  border-radius: 10px;
  background: #f8fafc;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.media-file-preview__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
</style>
