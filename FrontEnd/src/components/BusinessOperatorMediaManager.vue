<template>
  <div class="media-manager">
    <n-space vertical size="large">
      <n-card class="media-card">
        <template #header>
          Upload Media
        </template>

        <n-form label-placement="top" class="media-form" :disabled="isMediaSaving">
          <div class="media-form__grid">
            <n-form-item
              label="Title"
              :feedback="mediaErrors.label"
              :validation-status="mediaErrors.label ? 'error' : undefined"
            >
              <n-input v-model:value="newMedia.label" placeholder="Enter media title" />
            </n-form-item>
            <n-form-item
              label="Media Type"
              :feedback="mediaErrors.type"
              :validation-status="mediaErrors.type ? 'error' : undefined"
            >
              <n-select
                v-model:value="newMedia.type"
                :options="mediaTypes"
                placeholder="Select media type"
              />
            </n-form-item>
            <n-form-item
              label="Listing"
              :feedback="mediaErrors.listingId"
              :validation-status="mediaErrors.listingId ? 'error' : undefined"
            >
              <n-select
                v-model:value="newMedia.listingId"
                :options="mediaListingOptions"
                placeholder="Select listing"
                filterable
                clearable
              />
            </n-form-item>
            <n-form-item label="Mark as primary visual">
              <n-select
                v-model:value="newMedia.isPrimary"
                :options="primaryOptions"
                placeholder="Primary visual"
              />
            </n-form-item>
          </div>

          <n-form-item
            label="Media files"
            :feedback="mediaErrors.file"
            :validation-status="mediaErrors.file ? 'error' : undefined"
            class="media-upload__field"
          >
            <div class="media-upload__card">
              <n-upload
                multiple
                directory-dnd
                :file-list="pendingUploadFileInfos"
                :max="MAX_PENDING_FILES"
                :accept="SUPPORTED_MEDIA_TYPES.join(',')"
                :default-upload="false"
                :on-before-upload="handleMediaFileSelect"
                :on-remove="handlePendingUploadRemove"
                :show-file-list="false"
                @preview="handlePendingPreview"
              >
                <n-upload-dragger class="media-upload__dragger">
                  <div style="margin-bottom: 12px">
                    <n-icon size="48" :depth="3">
                      <ArchiveOutline />
                    </n-icon>
                  </div>
                  <n-text style="font-size: 16px">
                    Click or drag a file to this area to upload
                  </n-text>
                  <n-p depth="3" style="margin: 8px 0 0 0">
                    Strictly prohibit from uploading sensitive information. For example,
                    your bank card PIN or your credit card expiry date.
                  </n-p>
                </n-upload-dragger>
              </n-upload>
              <ul v-if="pendingUploadFileInfos.length" class="media-upload__file-list">
                <li
                  v-for="file in pendingUploadFileInfos"
                  :key="file.id"
                  class="media-upload__file-item"
                >
                  <button
                    type="button"
                    class="media-upload__file-link"
                    @click="handlePendingPreview(file)"
                  >
                    {{ file.name }}
                  </button>
                  <n-text v-if="file.sizeLabel" depth="3" class="media-upload__file-size">
                    {{ file.sizeLabel }}
                  </n-text>
                  <n-button
                    text
                    type="error"
                    size="tiny"
                    @click="removePendingMediaFile(file.id)"
                  >
                    Remove
                  </n-button>
                </li>
              </ul>
              <n-text depth="3" class="media-hint">
                {{ MEDIA_VALIDATION_MESSAGE }}
              </n-text>
            </div>
          </n-form-item>

          <div class="media-upload__messages">
            <n-alert v-if="mediaSelectionMessage" type="info" class="media-alert" :show-icon="false">
              {{ mediaSelectionMessage }}
            </n-alert>
            <n-alert v-if="mediaErrors.upload" type="error" class="media-alert">
              {{ mediaErrors.upload }}
            </n-alert>
            <n-alert v-if="mediaConfirmation" type="success" class="media-alert">
              {{ mediaConfirmation }}
            </n-alert>
          </div>

          <div class="media-upload__actions">
            <n-button type="primary" :loading="isMediaSaving" @click="addMediaAsset">
              Upload media
            </n-button>
            <n-button quaternary @click="clearMediaSelection" :disabled="!newMedia.files.length">
              Clear selection
            </n-button>
          </div>
        </n-form>
      </n-card>

      <n-card class="media-card">
        <template #header>
          Media Library
        </template>

        <n-space vertical size="large">
          <div class="media-filter-bar">
            <n-select
              v-model:value="mediaListingFilter"
              :options="mediaListingFilterOptions"
              placeholder="Filter by listing"
            />
            <n-select
              v-model:value="mediaTypeFilter"
              :options="mediaTypeFilterOptions"
              placeholder="Filter by type"
            />
            <n-input
              v-model:value="mediaSearchTerm"
              placeholder="Search title, file name, or listing"
              clearable
            />
          </div>

          <div class="media-library" v-if="mediaLibrarySections.length">
            <div
              v-for="section in mediaLibrarySections"
              :key="section.key"
              class="media-library__section"
            >
              <div class="media-library__section-header">
                <div class="media-library__section-title">
                  <span class="media-library__section-name">{{ section.listingName }}</span>
                </div>
                <n-tag size="tiny" bordered="false" type="default">
                  {{ section.assets.length }} {{ section.assets.length === 1 ? 'item' : 'items' }}
                </n-tag>
              </div>

              <n-space vertical size="medium">
                <n-card
                  v-for="entry in section.assets"
                  :key="entry.asset.id"
                  size="small"
                  class="media-card__item"
                  :bordered="false"
                >
                  <div class="media-card__row">
                    <div
                      class="media-card__thumb"
                      :class="{
                        'media-card__thumb--pdf': entry.preview.type === 'pdf',
                        'media-card__thumb--generic': entry.preview.type === 'generic',
                        'media-card__thumb--clickable': Boolean(entry.preview.url)
                      }"
                      @click="(entry.preview.url || resolveAssetUrl(entry.asset)) && openExistingAssetPreview(entry.asset)"
                    >
                      <img
                        v-if="entry.preview.type === 'image'"
                        :src="entry.preview.thumbnail || resolveAssetUrl(entry.asset)"
                        :alt="entry.asset.label"
                      />
                      <div v-else class="media-card__thumb-fallback">
                        <svg v-if="entry.preview.type === 'pdf'" class="media-card__thumb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                          <path fill="currentColor" d="M6 2h7l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V8h4.5L13 3.5z" />
                        </svg>
                        <span v-else>{{ getAssetDisplayLabel(entry.asset, entry.preview) }}</span>
                      </div>
                      <div
                        v-if="entry.isPrimary"
                        class="media-card__badge"
                      >
                        Primary
                      </div>
                    </div>
                    <div class="media-card__body">
                      <div class="media-card__header">
                        <div class="media-card__title-block">
                          <span class="media-card__title">{{ entry.label }}</span>
                          <span v-if="entry.fileName" class="media-card__file">
                            {{ entry.fileName }}
                          </span>
                        </div>
                        <span class="media-card__updated" v-if="entry.updatedLabel">
                          Updated {{ entry.updatedLabel }}
                        </span>
                      </div>
                      <div class="media-card__meta">
                        <span>{{ getAssetDisplayLabel(entry.asset, entry.preview) }}</span>
                        <span v-if="entry.mimeType">&bull; {{ entry.mimeType }}</span>
                        <span v-if="entry.fileSize">&bull; {{ formatBytes(entry.fileSize) }}</span>
                      </div>
                      <div class="media-card__status">
                        <n-tag
                          size="tiny"
                          :type="resolveStatusTagType(entry.status)"
                          bordered="false"
                        >
                          {{ entry.status || 'Pending' }}
                        </n-tag>
                      </div>
                      <div class="media-card__actions">
                        <n-button
                          size="tiny"
                          tertiary
                          @click="openExistingAssetPreview(entry.asset)"
                          :disabled="!(entry.preview.url || resolveAssetUrl(entry.asset))"
                        >
                          Preview
                        </n-button>
                        <n-button
                          size="tiny"
                          tertiary
                          type="primary"
                          @click="openMediaEdit(entry.asset)"
                        >
                          Edit
                        </n-button>
                        <n-button
                          size="tiny"
                          tertiary
                          type="primary"
                          @click="markPrimaryMedia(entry.asset)"
                        >
                          {{ entry.asset.isPrimary ? 'Primary' : 'Make primary' }}
                        </n-button>
                        <n-button
                          size="tiny"
                          tertiary
                          type="error"
                          @click="removeMediaAsset(entry.asset)"
                        >
                          Remove
                        </n-button>
                      </div>
                    </div>
                  </div>
                </n-card>
              </n-space>
            </div>
          </div>

          <n-empty v-else description="No media files match your filters." class="media-library__empty" />

          <div class="media-table__footer">
            <n-text depth="3">{{ mediaTableSummaryText }}</n-text>
            <SimplePagination
              v-if="mediaTablePageCount > 1"
              v-model:page="mediaTablePage"
              :page-count="mediaTablePageCount"
            />
          </div>
        </n-space>
      </n-card>
    </n-space>

    <n-modal
      v-model:show="previewModalVisible"
      preset="card"
      class="media-preview-modal"
      :title="previewContentType === 'pdf' ? 'Preview PDF' : 'Preview Image'"
      style="width: 420px"
    >
      <div v-if="previewContentType === 'image'" class="preview-image-wrapper">
        <img v-if="previewImageUrl" :src="previewImageUrl" alt="Selected media preview" class="preview-image" />
      </div>
      <div v-else class="preview-pdf-wrapper">
        <iframe
          v-if="previewPdfUrl"
          :src="previewPdfUrl"
          title="Media preview"
        ></iframe>
      </div>
      <template #footer>
        <n-space justify="space-between" align="center" style="width: 100%">
          <n-text v-if="IS_DEV && previewSourceUrl" depth="3" class="preview-debug-url">
            {{ previewSourceUrl }}
          </n-text>
          <n-button v-if="previewSourceUrl" tag="a" :href="previewSourceUrl" target="_blank">
            Open original
          </n-button>
          <n-button type="primary" @click="previewModalVisible = false">
            Close
          </n-button>
        </n-space>
      </template>
    </n-modal>

    <n-modal
      v-model:show="mediaEditModalVisible"
      preset="card"
      title="Edit media asset"
      style="max-width: 720px"
    >
      <n-form label-placement="top">
        <n-form-item
          label="Title"
          :feedback="mediaEditErrors.label"
          :validation-status="mediaEditErrors.label ? 'error' : undefined"
        >
          <n-input v-model:value="mediaEditForm.label" placeholder="Update media title" />
        </n-form-item>

        <n-form-item
          label="Media Type"
          :feedback="mediaEditErrors.type"
          :validation-status="mediaEditErrors.type ? 'error' : undefined"
        >
          <n-select
            v-model:value="mediaEditForm.type"
            :options="mediaTypes"
            placeholder="Select media type"
          />
        </n-form-item>

        <n-form-item label="Mark as primary visual">
          <n-select
            v-model:value="mediaEditForm.isPrimary"
            :options="primaryOptions"
          />
        </n-form-item>

        <div class="media-edit__status">
          <n-text depth="3">
            Listing ID: {{ mediaEditForm.listingId ?? 'Unassigned' }}
          </n-text>
        </div>

        <n-upload
          list-type="text"
          :accept="SUPPORTED_MEDIA_TYPES.join(',')"
          :default-upload="false"
          :show-file-list="false"
          :on-before-upload="handleEditFileUpload"
        >
          <n-button :loading="isMediaEditUploading">
            Add another file to this listing
          </n-button>
        </n-upload>

        <div class="media-edit__messages">
          <n-alert v-if="mediaEditUploadError" type="error">
            {{ mediaEditUploadError }}
          </n-alert>
          <n-alert v-if="mediaEditUploadMessage" type="success">
            {{ mediaEditUploadMessage }}
          </n-alert>
        </div>

        <div v-if="mediaEditTiles.length" class="media-edit__related">
          <n-divider>Listing media</n-divider>
          <n-grid cols="1 s:2" x-gap="12" y-gap="12">
            <n-grid-item
              v-for="entry in mediaEditTiles"
              :key="entry.asset.id"
            >
              <div class="media-edit__asset-tile">
                <div
                  class="media-edit__asset-thumb"
                  :class="{
                    'media-edit__asset-thumb--clickable': Boolean(entry.preview.url || resolveAssetUrl(entry.asset)),
                    'media-edit__asset-thumb--pdf': entry.preview.type === 'pdf',
                    'media-edit__asset-thumb--generic': entry.preview.type === 'generic'
                  }"
                  @click="(entry.preview.url || resolveAssetUrl(entry.asset)) && openExistingAssetPreview(entry.asset)"
                >
                  <img
                    v-if="entry.preview.type === 'image'"
                    :src="entry.preview.thumbnail || resolveAssetUrl(entry.asset)"
                    :alt="entry.asset.label"
                  />
                  <div v-else class="media-edit__asset-fallback">
                    <span v-if="entry.preview.type === 'pdf'">PDF</span>
                    <span v-else>{{ getAssetDisplayLabel(entry.asset, entry.preview) }}</span>
                  </div>
                  <div class="media-edit__asset-badges">
                    <span v-if="entry.asset.isPrimary" class="media-edit__asset-pill">Primary</span>
                    <span
                      v-if="isAttachmentMarkedForRemoval(entry.asset.id)"
                      class="media-edit__asset-pill media-edit__asset-pill--remove"
                    >
                      Pending removal
                    </span>
                  </div>
                </div>
                <div class="media-edit__asset-body">
                  <div class="media-edit__asset-heading">
                    <span class="media-edit__asset-title">{{ entry.asset.label }}</span>
                    <n-tag
                      v-if="isAttachmentMarkedForRemoval(entry.asset.id)"
                      size="tiny"
                      type="error"
                      bordered="false"
                    >
                      Removal pending
                    </n-tag>
                  </div>

                  <div v-if="entry.asset.fileName" class="media-edit__asset-file">
                    {{ entry.asset.fileName }}
                  </div>

                  <div class="media-edit__asset-meta">
                    <span>{{ getAssetDisplayLabel(entry.asset, entry.preview) }}</span>
                    <span v-if="entry.asset.mimeType">&bull; {{ entry.asset.mimeType }}</span>
                    <span v-if="entry.asset.fileSize">&bull; {{ formatBytes(entry.asset.fileSize) }}</span>
                  </div>

                  <div class="media-edit__asset-actions">
                    <n-button
                      size="tiny"
                      tertiary
                      :disabled="!(entry.preview.url || resolveAssetUrl(entry.asset))"
                      @click="openExistingAssetPreview(entry.asset)"
                    >
                      Preview
                    </n-button>
                    <n-button
                      size="tiny"
                      tertiary
                      type="error"
                      @click="toggleAttachmentRemoval(entry.asset)"
                    >
                      {{ isAttachmentMarkedForRemoval(entry.asset.id) ? 'Undo removal' : 'Remove' }}
                    </n-button>
                  </div>
                </div>
              </div>
            </n-grid-item>
          </n-grid>
        </div>
      </n-form>

      <template #footer>
        <n-space justify="end">
          <n-button quaternary @click="mediaEditModalVisible = false">Cancel</n-button>
          <n-button type="primary" @click="saveMediaEdits">Save changes</n-button>
        </n-space>
      </template>
    </n-modal>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import {
  NAlert,
  NButton,
  NCard,
  NDivider,
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
  NIcon,
  NUpload,
  NUploadDragger,
  NP,
  useMessage,
} from 'naive-ui'
import { ArchiveOutline } from '@vicons/ionicons5'
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
const message = useMessage()

const IS_DEV = typeof import.meta !== 'undefined' && Boolean(import.meta.env?.DEV)

function normalizeListingId(raw) {
  if (raw == null) return null
  if (typeof raw === 'number') {
    return Number.isNaN(raw) ? null : raw
  }
  if (typeof raw === 'string') {
    const direct = Number(raw)
    if (!Number.isNaN(direct) && direct > 0) return direct
    const match = raw.match(/(\d+)/)
    if (match && match[1]) {
      const parsed = Number(match[1])
      if (!Number.isNaN(parsed) && parsed > 0) {
        return parsed
      }
    }
  }
  return null
}

const MAX_MEDIA_SIZE_BYTES = 5 * 1024 * 1024
const MAX_PENDING_FILES = 10
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

const PDF_PLACEHOLDER_THUMBNAIL =
  'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMjAiIGhlaWdodD0iMTIwIiB2aWV3Qm94PSIwIDAgMTIwIDEyMCI+PHJlY3Qgd2lkdGg9IjEyMCIgaGVpZ2h0PSIxMjAiIHJ4PSIxMiIgZmlsbD0iI2Y0ZjVmNyIvPjxwYXRoIGQ9Ik0zNCAyNGg1MmE2IDYgMCAwIDEgNiA2djYwYTYgNiAwIDAgMS02IDZIMzRhNiA2IDAgMCAxLTYtNlYzMGE2IDYgMCAwIDEgNi02eiIgZmlsbD0iI2ZmZiIgc3Ryb2tlPSIjZDlkOWQ5IiBzdHJva2Utd2lkdGg9IjIiLz48dGV4dCB4PSI2MCIgeT0iNzQiIGZvbnQtc2l6ZT0iMzIiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGZpbGw9IiNkMDMwNTAiIGZvbnQtZmFtaWx5PSJBcmlhbCwgSGVsdmV0aWNhLCBzYW5zLXNlcmlmIj5QREY8L3RleHQ+PC9zdmc+'

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

const assetBaseUrl = computed(() => {
  if (typeof window === 'undefined') return ''
  const rawBase = String(props.apiBase ?? '').trim()
  if (!rawBase) return ''

  const isAbsolute = /^https?:\/\//i.test(rawBase)

  if (!isAbsolute && IS_DEV) {
    try {
      const devPath = `${rawBase.replace(/\/$/, '')}/public_assets/`
      return new URL(devPath, window.location.origin).toString()
    } catch {
      // fall through to standard handling
    }
  }

  try {
    const base = new URL(rawBase, window.location.origin)
    base.search = ''
    base.hash = ''
    const normalisedPath = base.pathname.replace(/\/api\/?$/, '/public_assets/')
    base.pathname = normalisedPath.endsWith('/') ? normalisedPath : `${normalisedPath}/`
    return base.toString()
  } catch {
    return ''
  }
})

function resolveAssetUrl(asset) {
  if (asset?.absoluteUrl) {
    return asset.absoluteUrl
  }
  if (!asset?.url) return null
  if (/^https?:\/\//i.test(asset.url)) {
    return asset.url
  }
  const base = assetBaseUrl.value
  if (base) {
    return `${base}${asset.url.replace(/^\/?/, '')}`
  }

  const rawBase = String(props.apiBase ?? '').trim()
  if (rawBase) {
    try {
      const joined = rawBase.endsWith('/')
        ? `${rawBase}${asset.url.replace(/^\/?/, '')}`
        : `${rawBase}/${asset.url.replace(/^\/?/, '')}`
      return new URL(joined, window.location.origin).toString()
    } catch {
      // ignore
    }
  }

  try {
    return new URL(asset.url, window.location.origin).toString()
  } catch {
    return asset.url
  }
}

function isImageAsset(asset) {
  const mime = (asset?.mimeType ?? '').toLowerCase()
  if (mime.startsWith('image/')) return true
  if (!mime && asset?.fileName) {
    const extension = asset.fileName.split('.').pop()?.toLowerCase()
    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'bmp'].includes(extension ?? '')
  }
  return false
}

const mediaListingOptions = computed(() =>
  (props.listings ?? []).map((listing) => ({
    label: listing.name ?? 'Listing',
    value: normalizeListingId(listing.listingId ?? listing.id),
  })).filter((option) => option.value),
)

const mediaListingFilter = ref('all')
const mediaTypeFilter = ref('all')
const mediaSearchTerm = ref('')
const mediaTablePage = ref(1)
const MEDIA_TABLE_PAGE_SIZE = 8
const mediaTablePageCount = computed(() =>
  Math.max(1, Math.ceil((mediaTableFilteredData.value.length || 1) / MEDIA_TABLE_PAGE_SIZE)),
)

const isMediaSaving = ref(false)
const mediaSelectionMessage = ref('')
const mediaConfirmation = ref('')
const mediaErrors = reactive({})
const previewModalVisible = ref(false)
const previewContentType = ref('image')
const previewImageUrl = ref('')
const previewPdfUrl = ref('')
const previewSourceUrl = ref('')
const pendingUploadFileInfos = computed(() =>
  newMedia.files.map((pending) => {
    const mime = (pending.mimeType || '').toLowerCase()
    const extension = pending.extension?.toLowerCase() || ''
    const isPdf = mime === 'application/pdf' || extension === 'pdf'
    const previewUrl = pending.previewUrl || null

    return {
      id: pending.id,
      name: pending.fileName,
      status: 'finished',
      percentage: 100,
      thumbnailUrl: isPdf ? PDF_PLACEHOLDER_THUMBNAIL : previewUrl,
      url: previewUrl,
      type: pending.mimeType || '',
      size: pending.fileSize || 0,
      sizeLabel: pending.fileSize ? formatBytes(pending.fileSize) : '',
      extension: pending.extension || '',
    }
  }),
)

const listingStatusMap = computed(() => {
  const map = new Map()
  const listingArray = Array.isArray(props.listings) ? props.listings : []
  listingArray.forEach((listing) => {
    const id = normalizeListingId(listing?.listingId ?? listing?.id ?? null)
    if (id != null) {
      const status =
        typeof listing?.status === 'string' ? listing.status.trim() : listing?.status ?? ''
      map.set(id, status)
    }
  })
  return map
})

const PENDING_LISTING_STATES = new Set([
  'pending',
  'pending review',
  'awaiting review',
  'submitted',
  'processing',
  'draft',
])
const APPROVED_LISTING_STATES = new Set([
  'approved',
  'active',
  'published',
  'visible',
  'live',
])

function deriveMediaStatusFromListing(asset) {
  const listingId = normalizeListingId(asset?.listingId ?? asset?.listingID ?? null)
  if (listingId != null && listingStatusMap.value.has(listingId)) {
    const listingStatus = listingStatusMap.value.get(listingId) ?? ''
    const normalized = String(listingStatus).toLowerCase()
    if (PENDING_LISTING_STATES.has(normalized)) {
      return 'Pending'
    }
    if (APPROVED_LISTING_STATES.has(normalized)) {
      return 'Published'
    }
  }
  return asset?.status ?? 'Pending'
}

const mediaLibraryDisplayEntries = computed(() =>
  mediaLibrary.value.map((asset) => {
    const derivedStatus = deriveMediaStatusFromListing(asset)
    if (derivedStatus && derivedStatus !== asset.status) {
      return { asset, display: { ...asset, status: derivedStatus } }
    }
    return { asset, display: asset }
  }),
)

const newMedia = reactive({
  label: '',
  type: null,
  isPrimary: false,
  listingId: null,
  files: [],
})

const mediaEditModalVisible = ref(false)
const mediaEditForm = reactive({
  id: null,
  listingId: null,
  label: '',
  type: '',
  isPrimary: false,
  fileName: '',
  mimeType: '',
})
const mediaEditErrors = reactive({})
const mediaEditUploadError = ref('')
const mediaEditUploadMessage = ref('')
const isMediaEditUploading = ref(false)
const mediaEditPendingRemovals = ref(new Set())

const mediaEditRelatedAssets = computed(() => {
  const targetListingId = mediaEditForm.listingId
  return mediaLibrary.value
    .filter((asset) =>
      targetListingId == null ? asset.listingId == null : asset.listingId === targetListingId,
    )
    .slice()
    .sort(
      (a, b) => new Date(b?.lastUpdated ?? 0).getTime() - new Date(a?.lastUpdated ?? 0).getTime(),
    )
})

const mediaEditTiles = computed(() =>
  mediaEditRelatedAssets.value.map((asset) => ({
    asset,
    preview: getAssetPreview(asset),
  })),
)

const mediaListingFilterOptions = computed(() => {
  const options = new Map()
  options.set('all', { label: 'All listings', value: 'all' })

  for (const listing of props.listings ?? []) {
    const id = normalizeListingId(listing.listingId ?? listing.id)
    if (id != null) {
      const key = String(id)
      if (!options.has(key)) {
        options.set(key, { label: listing.name ?? 'Listing', value: key })
      }
    }
  }

  for (const entry of mediaLibraryDisplayEntries.value ?? []) {
    const asset = entry.display
    if (asset.listingId == null) {
      options.set('__unassigned__', { label: 'Unassigned media', value: '__unassigned__' })
    } else {
      const key = String(asset.listingId)
      if (!options.has(key)) {
        options.set(key, {
          label: asset.listingName ?? `Listing ${asset.listingId}`,
          value: key,
        })
      }
    }
  }

  return Array.from(options.values())
})

const mediaTypeFilterOptions = computed(() => {
  const base = [{ label: 'All media types', value: 'all' }]
  const uniqueTypes = new Set(
    mediaLibraryDisplayEntries.value
      .map((entry) => entry.display.type)
      .filter((value) => value),
  )
  return [...base, ...Array.from(uniqueTypes).map((type) => ({ label: type, value: type }))]
})

const mediaTableBaseData = computed(() =>
  (mediaLibraryDisplayEntries.value ?? [])
    .slice()
    .sort((a, b) => {
      const nameA = (a.display.listingName || 'Unassigned media').toLowerCase()
      const nameB = (b.display.listingName || 'Unassigned media').toLowerCase()
      if (nameA === nameB) {
        return (
          new Date(b.display.lastUpdated ?? 0).getTime() -
          new Date(a.display.lastUpdated ?? 0).getTime()
        )
      }
      return nameA.localeCompare(nameB)
    })
    .map(({ asset, display }, index) => ({
      key: display.id ?? index,
      listingKey: display.listingId ?? '__unassigned__',
      listingName: display.listingName ?? 'Unassigned media',
      listingId: display.listingId ?? null,
      label: display.label,
      type: display.type,
      status: display.status,
      isPrimary: Boolean(display.isPrimary),
      lastUpdated: display.lastUpdated,
      fileName: display.fileName,
      mimeType: display.mimeType,
      fileSize: display.fileSize,
      url: display.url,
      absoluteUrl: display.absoluteUrl,
      asset,
    })),
)

const mediaTableFilteredData = computed(() => {
  const term = mediaSearchTerm.value.trim().toLowerCase()
  const listingFilter = mediaListingFilter.value
  const typeFilter = mediaTypeFilter.value

  return mediaTableBaseData.value.filter((row) => {
    const matchesListing =
      listingFilter === 'all'
        ? true
        : listingFilter === '__unassigned__'
          ? row.listingId == null
          : String(row.listingId ?? '') === listingFilter
    const matchesType = typeFilter === 'all' ? true : row.type === typeFilter
    const matchesSearch =
      !term ||
      [row.label, row.fileName, row.mimeType, row.listingName]
        .filter(Boolean)
        .some((field) => field.toLowerCase().includes(term))
    return matchesListing && matchesType && matchesSearch
  })
})

watch([mediaListingFilter, mediaTypeFilter, mediaSearchTerm], () => {
  mediaTablePage.value = 1
})

watch(mediaTablePageCount, (count) => {
  if (mediaTablePage.value > count) {
    mediaTablePage.value = count
  }
})

watch(previewModalVisible, (visible) => {
  if (!visible) {
    previewContentType.value = 'image'
    previewImageUrl.value = ''
    previewPdfUrl.value = ''
    previewSourceUrl.value = ''
  }
})

watch(
  () => mediaLibrary.value.length,
  () => {
    if (mediaTablePage.value > mediaTablePageCount.value) {
      mediaTablePage.value = mediaTablePageCount.value
    }
  },
)

const mediaTablePagedData = computed(() =>
  mediaTableFilteredData.value
    .slice((mediaTablePage.value - 1) * MEDIA_TABLE_PAGE_SIZE, mediaTablePage.value * MEDIA_TABLE_PAGE_SIZE)
    .map((row) => ({ ...row })),
)

const mediaTableSummaryText = computed(() => {
  const total = mediaTableFilteredData.value.length
  if (total === 0) return 'Showing 0 media files'
  const start = (mediaTablePage.value - 1) * MEDIA_TABLE_PAGE_SIZE + 1
  const end = Math.min(mediaTablePage.value * MEDIA_TABLE_PAGE_SIZE, total)
  return `Showing ${start} to ${end} of ${total} media files`
})

const mediaLibrarySections = computed(() => {
  const groups = new Map()

  for (const row of mediaTablePagedData.value) {
    const key = row.listingKey ?? '__unassigned__'
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        listingName: row.listingName,
        listingId: row.listingId,
        assets: [],
      })
    }
    groups.get(key).assets.push({
      asset: row.asset,
      label: row.label,
      fileName: row.fileName,
      mimeType: row.mimeType,
      fileSize: row.fileSize,
      status: row.status,
      isPrimary: row.isPrimary,
      preview: getAssetPreview(row.asset),
      updatedLabel: formatUpdatedDate(row.lastUpdated),
    })
  }

  return Array.from(groups.values())
})

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

function getAssetPreview(asset) {
  if (!asset || typeof asset !== 'object') {
    return { type: 'generic', url: null, thumbnail: null }
  }
  const resolvedUrl = resolveAssetUrl(asset) || asset.url || null
  const fileName = asset.fileName ?? ''
  const mime = (asset?.mimeType ?? '').toLowerCase()
  const extension = fileName.split('.').pop()?.toLowerCase() || ''

  const isPdf =
    mime === 'application/pdf' ||
    extension === 'pdf' ||
    (resolvedUrl ? resolvedUrl.toLowerCase().endsWith('.pdf') : false)

  if (isPdf) {
    return { type: 'pdf', url: resolvedUrl, thumbnail: PDF_PLACEHOLDER_THUMBNAIL }
  }

  const looksLikeImage =
    mime.startsWith('image/') ||
    (mime === '' && extension && ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'bmp'].includes(extension)) ||
    (resolvedUrl ? resolvedUrl.startsWith('data:image/') || /\.(jpe?g|png|gif|webp|heic|bmp)$/i.test(resolvedUrl) : false)

  if (looksLikeImage && resolvedUrl) {
    return { type: 'image', url: resolvedUrl, thumbnail: resolvedUrl }
  }

  return { type: 'generic', url: resolvedUrl, thumbnail: null }
}

function getAssetDisplayLabel(asset, preview) {
  const previewType = preview?.type ?? null
  const mime = (asset?.mimeType ?? '').toLowerCase()
  if (previewType === 'pdf' || mime === 'application/pdf') {
    return 'PDF'
  }
  if (previewType === 'image' || isImageAsset(asset)) {
    return 'Image'
  }
  const providedType = typeof asset?.type === 'string' && asset.type.trim() ? asset.type : null
  if (providedType) {
    return providedType
  }
  if (mime) {
    const fragment = mime.split('/').pop() || mime
    return fragment.toUpperCase()
  }
  return 'Media'
}

function resolveStatusTagType(status) {
  const normalized = String(status ?? '').toLowerCase()
  if (['published', 'active', 'approved', 'live'].includes(normalized)) return 'success'
  if (['pending', 'processing', 'uploading', 'draft'].includes(normalized)) return 'warning'
  if (['rejected', 'failed', 'error', 'archived'].includes(normalized)) return 'error'
  return 'default'
}

function formatUpdatedDate(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return date.toLocaleString()
}

function createPreviewUrl(file) {
  if (typeof window === 'undefined' || typeof URL === 'undefined') return null
  if (file instanceof File || file instanceof Blob) {
    return URL.createObjectURL(file)
  }
  return null
}

function revokePreviewUrl(url) {
  if (typeof window === 'undefined' || typeof URL === 'undefined') return
  if (url && url.startsWith('blob:')) {
    try {
      URL.revokeObjectURL(url)
    } catch (error) {
      // no-op if revoke fails
    }
  }
}

function updateSelectionMessage() {
  const count = newMedia.files.length
  if (!count) {
    mediaSelectionMessage.value = ''
    return
  }
  const plural = count > 1 ? 'files' : 'file'
  mediaSelectionMessage.value = `Selected ${count} ${plural} (max ${MAX_PENDING_FILES}).`
}

function clearMediaSelection() {
  newMedia.files.forEach((item) => {
    if (item.previewUrl) {
      revokePreviewUrl(item.previewUrl)
    }
  })
  newMedia.files.splice(0, newMedia.files.length)
  updateSelectionMessage()
  if (mediaErrors.file) delete mediaErrors.file
}

function removePendingMediaFile(targetId) {
  const index = newMedia.files.findIndex((item) => item.id === targetId)
  if (index !== -1) {
    const [removed] = newMedia.files.splice(index, 1)
    if (removed?.previewUrl) {
      revokePreviewUrl(removed.previewUrl)
    }
  }
  updateSelectionMessage()
}

function handlePendingUploadRemove(option) {
  const target = option?.file ?? option
  if (target?.id) {
    removePendingMediaFile(target.id)
  }
  return false
}

function handlePendingPreview(file) {
  const pendingMatch =
    file?.id != null ? newMedia.files.find((item) => item.id === file.id) ?? null : null
  let resolvedUrl = file?.url || file?.thumbnailUrl || pendingMatch?.previewUrl || ''
  if (!resolvedUrl && pendingMatch?.file) {
    const generatedUrl = createPreviewUrl(pendingMatch.file)
    if (generatedUrl) {
      pendingMatch.previewUrl = generatedUrl
      resolvedUrl = generatedUrl
    }
  }
  const mimeType = (file?.type || pendingMatch?.mimeType || '').toLowerCase()
  const extension = (file?.extension || pendingMatch?.extension || '').toUpperCase()
  const isPdf = mimeType === 'application/pdf' || extension === 'PDF'

  previewImageUrl.value = ''
  previewPdfUrl.value = ''
  previewSourceUrl.value = ''

  if (resolvedUrl) {
    previewSourceUrl.value = resolvedUrl
    if (isPdf) {
      previewContentType.value = 'pdf'
      previewPdfUrl.value = resolvedUrl
    } else {
      previewContentType.value = 'image'
      previewImageUrl.value = resolvedUrl
    }
    previewModalVisible.value = true
  } else {
    previewModalVisible.value = false
  }

  return false
}

function openExistingAssetPreview(asset) {
  const preview = getAssetPreview(asset)
  const fallbackUrl = resolveAssetUrl(asset) || asset.url || ''
  const source = preview.url || fallbackUrl
  if (!source) return

  previewImageUrl.value = ''
  previewPdfUrl.value = ''
  previewSourceUrl.value = source

  const mimeType = (asset?.mimeType ?? '').toLowerCase()
  const isPdf =
    preview.type === 'pdf' ||
    mimeType === 'application/pdf' ||
    source.toLowerCase().endsWith('.pdf')

  if (isPdf) {
    previewContentType.value = 'pdf'
    previewPdfUrl.value = source
  } else {
    previewContentType.value = 'image'
    previewImageUrl.value = source || fallbackUrl
  }

  previewModalVisible.value = true
}

async function handleEditFileUpload({ file }) {
  const rawFile = file?.file ?? file
  if (!rawFile) return false

  mediaEditUploadError.value = ''
  mediaEditUploadMessage.value = ''

  if (!mediaEditForm.listingId) {
    mediaEditUploadError.value = 'Listing information missing; please close and reopen the editor.'
    message.error(mediaEditUploadError.value)
    return false
  }

  if (rawFile.size > MAX_MEDIA_SIZE_BYTES) {
    mediaEditUploadError.value = 'File exceeds the 5MB limit.'
    message.error(mediaEditUploadError.value)
    return false
  }

  const mimeType = rawFile.type ?? file?.type ?? ''
  if (mimeType && !SUPPORTED_MEDIA_TYPES.includes(mimeType)) {
    mediaEditUploadError.value = MEDIA_VALIDATION_MESSAGE
    message.error(mediaEditUploadError.value)
    return false
  }

  try {
    isMediaEditUploading.value = true
    const asset = await uploadMediaFile({
      file: rawFile,
      label: mediaEditForm.label.trim() || rawFile.name || 'Media asset',
      type: mediaEditForm.type || 'Accommodation photo',
      listingId: mediaEditForm.listingId,
      isPrimary: false,
    })

    mediaLibrary.value = [asset, ...mediaLibrary.value]
    emitLibraryUpdate()
    mediaEditUploadMessage.value = `${asset.label} added to this listing.`
    message.success(mediaEditUploadMessage.value)
  } catch (error) {
    mediaEditUploadError.value =
      error instanceof Error ? error.message : 'Failed to upload file.'
    message.error(mediaEditUploadError.value)
  } finally {
    isMediaEditUploading.value = false
  }

  return false
}

function isAttachmentMarkedForRemoval(id) {
  return mediaEditPendingRemovals.value.has(id)
}

function toggleAttachmentRemoval(attachment) {
  const next = new Set(mediaEditPendingRemovals.value)
  if (next.has(attachment.id)) {
    next.delete(attachment.id)
    mediaEditUploadMessage.value = ''
  } else {
    next.add(attachment.id)
    mediaEditUploadMessage.value = `${attachment.label} marked for removal. Save changes to confirm.`
    message.info(mediaEditUploadMessage.value)
  }
  mediaEditPendingRemovals.value = next
}

function clearAttachmentRemovalMarks() {
  mediaEditPendingRemovals.value = new Set()
  mediaEditUploadMessage.value = ''
}

function handleMediaFileSelect({ file }) {
  const rawFile = file?.file ?? file
  if (!rawFile) return false

  if (newMedia.files.length >= MAX_PENDING_FILES) {
    mediaErrors.file = `You can queue up to ${MAX_PENDING_FILES} files at a time.`
    message.error(mediaErrors.file)
    return false
  }

  if (rawFile.size > MAX_MEDIA_SIZE_BYTES) {
    mediaErrors.file = 'File exceeds the 5MB limit.'
    message.error(mediaErrors.file)
    return false
  }

  const mimeType = rawFile.type ?? file?.type ?? ''
  if (mimeType && !SUPPORTED_MEDIA_TYPES.includes(mimeType)) {
    mediaErrors.file = MEDIA_VALIDATION_MESSAGE
    message.error(mediaErrors.file)
    return false
  }

  const previewUrl = createPreviewUrl(rawFile)
  const extension =
    rawFile.name?.split('.').pop()?.toUpperCase() ??
    file?.name?.split('.').pop()?.toUpperCase() ??
    ''
  const pendingEntry = {
    id: crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2, 8)}`,
    file: rawFile,
    fileName: rawFile.name ?? file?.name ?? 'media',
    mimeType,
    fileSize: rawFile.size ?? 0,
    previewUrl,
    extension,
  }

  newMedia.files.push(pendingEntry)

  updateSelectionMessage()
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
  const fileName = asset.fileName ?? asset.file_name ?? null
  const rawUrl = asset.url ?? asset.imageURL ?? asset.imageUrl ?? asset.path ?? null
  const baseUrl =
    rawUrl ??
    (fileName
      ? `operator_media/${fileName}`
      : null)
  const normalised = {
    ...asset,
    listingId,
    fileName,
    url: baseUrl,
    id: asset.id ?? asset.mediaId ?? asset.assetId ?? crypto.randomUUID?.() ?? Date.now(),
    lastUpdated: asset.lastUpdated ?? asset.updatedAt ?? new Date().toISOString(),
  }
  return normalised
}

async function uploadMediaFile({ file, label, type, listingId, isPrimary }) {
  if (!props.operatorId) {
    throw new Error('Operator account not loaded. Please refresh and try again.')
  }
  if (!listingId) {
    throw new Error('Select the listing to associate with this media.')
  }
  const formData = new FormData()
  formData.append('operatorId', props.operatorId)
  formData.append('listingId', listingId)
  formData.append('title', label)
  formData.append('type', type)
  formData.append('isPrimary', isPrimary ? 'true' : 'false')
  formData.append('file', file, file.name ?? 'upload.bin')

  const response = await fetch(`${props.apiBase}/operator/upload_media.php`, {
    method: 'POST',
    body: formData,
  })

  const payload = await response.json().catch(() => null)
  if (!response.ok || !payload || payload.ok !== true || !payload.asset) {
    throw new Error(payload?.error || `Failed to upload media (HTTP ${response.status})`)
  }

  return normalizeMediaAsset(payload.asset)
}

async function addMediaAsset() {
  const errors = {}
  if (!newMedia.label.trim()) errors.label = 'Provide a title for the photo or menu.'
  if (!newMedia.files.length) errors.file = 'Select at least one media file to attach.'
  if (!newMedia.type) errors.type = 'Select the media type.'
  if (!newMedia.listingId) errors.listingId = 'Choose the listing this media belongs to.'

  Object.keys(mediaErrors).forEach((key) => delete mediaErrors[key])
  Object.assign(mediaErrors, errors)

  if (Object.keys(errors).length > 0) {
    mediaConfirmation.value = ''
    message.warning('Please complete the highlighted media fields before uploading.')
    return
  }

  if (!props.operatorId) {
    mediaErrors.upload = 'Operator account not loaded. Please refresh and try again.'
    message.error(mediaErrors.upload)
    return
  }

  isMediaSaving.value = true
  mediaConfirmation.value = ''
  if (mediaErrors.upload) delete mediaErrors.upload

  try {
    const uploadedLabels = []
    let primaryApplied = false

    for (const pending of newMedia.files) {
      const normalizedLabel = newMedia.label.trim() || pending.fileName || 'Media asset'
      const resolvedType = newMedia.type || 'Accommodation photo'
      const shouldSetPrimary = Boolean(newMedia.isPrimary) && !primaryApplied

      const asset = await uploadMediaFile({
        file: pending.file,
        label: normalizedLabel,
        type: resolvedType,
        listingId: newMedia.listingId,
        isPrimary: shouldSetPrimary,
      })

      if (shouldSetPrimary) {
        primaryApplied = true
      }

      if (asset.isPrimary && asset.listingId != null) {
        mediaLibrary.value = mediaLibrary.value.map((item) =>
          item.listingId === asset.listingId ? { ...item, isPrimary: false } : item,
        )
      }
      mediaLibrary.value = [asset, ...mediaLibrary.value]
      uploadedLabels.push(asset.label)
    }

    emitLibraryUpdate()
    const uploadedCount = uploadedLabels.length
    let successMessage = ''
    if (uploadedCount > 1) {
      successMessage = `${uploadedCount} media files uploaded successfully.`
    } else if (uploadedCount === 1) {
      successMessage = `${uploadedLabels[0]} uploaded successfully.`
    }
    if (successMessage) {
      mediaConfirmation.value = successMessage
      message.success(successMessage)
    }
    clearMediaSelection()
    newMedia.label = ''
    newMedia.type = null
    newMedia.isPrimary = false
  } catch (error) {
    mediaErrors.upload = error instanceof Error ? error.message : 'Failed to upload media.'
    message.error(mediaErrors.upload)
  } finally {
    isMediaSaving.value = false
  }
}

function openMediaEdit(asset) {
  mediaEditForm.id = asset.id
  mediaEditForm.listingId = asset.listingId ?? null
  mediaEditForm.label = asset.label
  mediaEditForm.type = asset.type
  mediaEditForm.isPrimary = asset.isPrimary
  mediaEditForm.fileName = asset.fileName
  mediaEditForm.mimeType = asset.mimeType ?? ''
  Object.keys(mediaEditErrors).forEach((key) => delete mediaEditErrors[key])
  mediaEditUploadError.value = ''
  mediaEditUploadMessage.value = ''
  isMediaEditUploading.value = false
  clearAttachmentRemovalMarks()
  mediaEditModalVisible.value = true
}

function saveMediaEdits() {
  const errors = {}
  if (!mediaEditForm.label?.trim()) errors.label = 'Provide a title for the media asset.'
  if (!mediaEditForm.type) errors.type = 'Select a media type.'

  Object.keys(mediaEditErrors).forEach((key) => delete mediaEditErrors[key])
  Object.assign(mediaEditErrors, errors)

  if (Object.keys(errors).length > 0) {
    message.warning('Please resolve the highlighted fields before saving media details.')
    return
  }

  const shouldSetPrimary = Boolean(mediaEditForm.isPrimary)
  mediaLibrary.value = mediaLibrary.value.map((asset) => {
    if (asset.id === mediaEditForm.id) {
      return {
        ...asset,
        label: mediaEditForm.label.trim(),
        type: mediaEditForm.type,
        mimeType: mediaEditForm.mimeType?.trim() || '',
        isPrimary: shouldSetPrimary,
        lastUpdated: new Date().toISOString(),
      }
    }
    return shouldSetPrimary ? { ...asset, isPrimary: false } : asset
  })

  let removalCount = 0
  if (mediaEditPendingRemovals.value.size > 0) {
    removalCount = mediaEditPendingRemovals.value.size
    mediaLibrary.value = mediaLibrary.value.filter(
      (asset) => !mediaEditPendingRemovals.value.has(asset.id),
    )
    clearAttachmentRemovalMarks()
  }

  const confirmationMessage =
    removalCount > 0
      ? `${mediaEditForm.label.trim()} updated. ${removalCount} file${removalCount > 1 ? 's' : ''} removed.`
      : `${mediaEditForm.label.trim()} updated successfully.`
  mediaConfirmation.value = confirmationMessage
  message.success(confirmationMessage)
  emitLibraryUpdate()
  mediaEditModalVisible.value = false
}

function markPrimaryMedia(target) {
  mediaLibrary.value = mediaLibrary.value.map((asset) => ({
    ...asset,
    isPrimary: asset.id === target.id,
  }))
  emitLibraryUpdate()
  mediaConfirmation.value = `${target.label} is now marked as the primary visual.`
  message.success(mediaConfirmation.value)
}

function removeMediaAsset(target) {
  mediaLibrary.value = mediaLibrary.value.filter((asset) => asset.id !== target.id)
  emitLibraryUpdate()
  mediaConfirmation.value = `${target.label} removed from the gallery.`
  message.success(mediaConfirmation.value)
  if (mediaEditModalVisible.value && mediaEditForm.id === target.id) {
    mediaEditModalVisible.value = false
  }
}
</script>

<style scoped>
.media-manager {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.media-card {
  width: 100%;
}

.media-form__grid {
  display: grid;
  gap: 16px;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}

.media-upload__field {
  flex-direction: column;
  align-items: flex-start;
}

.media-upload__card {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 12px;
  width: 100%;
}

.media-upload__dragger {
  border: 1px dashed rgba(15, 23, 42, 0.18);
  border-radius: 12px;
  padding: 36px 24px;
  background: rgba(24, 160, 88, 0.04);
  transition: border-color 0.2s ease, background 0.2s ease;
}

.media-upload__dragger :deep(.n-upload-dragger-icon) {
  color: rgba(15, 23, 42, 0.45);
}

.media-upload__dragger:hover {
  border-color: rgba(24, 160, 88, 0.4);
  background: rgba(24, 160, 88, 0.08);
}

.media-upload__file-list {
  list-style: none;
  margin: 16px 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
  width: 100%;
}

.media-upload__file-item {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 14px;
  color: #1f2d3d;
}

.media-upload__file-link {
  border: none;
  background: none;
  padding: 0;
  font-size: 14px;
  font-weight: 500;
  color: #18a058;
  cursor: pointer;
  text-align: left;
}

.media-upload__file-link:hover,
.media-upload__file-link:focus {
  text-decoration: underline;
  outline: none;
}

.media-upload__file-size {
  font-size: 12px;
}

.media-upload__card :deep(.n-upload-trigger.n-upload-trigger--image-card) {
  width: 108px;
  height: 108px;
}

.media-upload__trigger {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  gap: 6px;
  font-weight: 600;
  color: #18a058;
}

.media-upload__trigger-plus {
  font-size: 28px;
  line-height: 1;
}

.media-upload__trigger-text {
  font-size: 12px;
}

.media-upload__divider {
  width: 100%;
  margin: 4px 0 0;
}

.media-hint {
  margin-top: 8px;
  font-size: 12px;
}

.media-upload__messages {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 8px;
}

.media-upload__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 16px;
}

.media-filter-bar {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
}

.media-table__footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.media-preview-modal .preview-image-wrapper {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 180px;
  padding: 12px;
}

.preview-image {
  max-width: 320px;
  max-height: 320px;
  width: auto;
  height: auto;
  border-radius: 6px;
  box-shadow: 0 6px 24px rgba(0, 0, 0, 0.12);
}

.media-preview-modal .preview-pdf-wrapper {
  height: 480px;
}

.media-preview-modal iframe {
  width: 100%;
  height: 100%;
  border: none;
  border-radius: 6px;
  box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.05);
}

.preview-debug-url {
  font-size: 11px;
  word-break: break-all;
  max-width: 60%;
}

.media-edit__status {
  margin: 12px 0;
}

.media-edit__messages {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 16px 0 0;
}

.media-edit__related {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.media-edit__asset-tile {
  display: grid;
  grid-template-columns: 96px 1fr;
  gap: 16px;
  padding: 14px 16px;
  border: 1px solid #e6ebf1;
  border-radius: 12px;
  background: #fff;
  box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
  align-items: center;
}

.media-edit__asset-thumb {
  position: relative;
  width: 96px;
  height: 76px;
  border-radius: 10px;
  overflow: hidden;
  background: linear-gradient(135deg, rgba(24, 160, 88, 0.18), rgba(24, 160, 88, 0.05));
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: inset 0 0 0 1px rgba(24, 160, 88, 0.18);
}

.media-edit__asset-thumb--clickable {
  cursor: pointer;
}

.media-edit__asset-thumb--pdf {
  background: linear-gradient(135deg, rgba(208, 48, 80, 0.18), rgba(208, 48, 80, 0.05));
  box-shadow: inset 0 0 0 1px rgba(208, 48, 80, 0.18);
}

.media-edit__asset-thumb--generic {
  background: linear-gradient(135deg, rgba(15, 23, 42, 0.12), rgba(15, 23, 42, 0.03));
  box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.12);
}

.media-edit__asset-thumb img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.media-edit__asset-fallback {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  font-weight: 600;
  font-size: 13px;
  color: #0f172a;
  padding: 8px;
}

.media-edit__asset-thumb--pdf .media-edit__asset-fallback {
  color: #d03050;
}

.media-edit__asset-badges {
  position: absolute;
  top: 8px;
  left: 8px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.media-edit__asset-pill {
  font-size: 11px;
  font-weight: 600;
  color: #fff;
  background: #18a058;
  padding: 2px 6px;
  border-radius: 999px;
}

.media-edit__asset-pill--remove {
  background: #d03050;
}

.media-edit__asset-body {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.media-edit__asset-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}

.media-edit__asset-title {
  font-weight: 600;
  color: #1f2933;
}

.media-edit__asset-file {
  font-size: 12px;
  color: #61728a;
}

.media-edit__asset-meta {
  font-size: 12px;
  color: #7b8794;
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.media-edit__asset-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}

.media-library {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.media-library__section {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.media-library__section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.media-library__section-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.media-library__section-name {
  font-weight: 600;
  font-size: 16px;
}

.media-card__item {
  background: #fff;
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
  border-radius: 12px;
}

.media-card__row {
  display: flex;
  gap: 16px;
  align-items: flex-start;
}

.media-card__thumb {
  position: relative;
  width: 124px;
  aspect-ratio: 4 / 3;
  border-radius: 10px;
  overflow: hidden;
  background: linear-gradient(135deg, rgba(24, 160, 88, 0.18), rgba(24, 160, 88, 0.05));
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: inset 0 0 0 1px rgba(24, 160, 88, 0.18);
  transition: transform 0.16s ease, box-shadow 0.16s ease;
}

.media-card__thumb--clickable {
  cursor: pointer;
}

.media-card__thumb--clickable:hover {
  transform: translateY(-1px);
  box-shadow: inset 0 0 0 1px rgba(24, 160, 88, 0.24), 0 8px 20px rgba(15, 23, 42, 0.08);
}

.media-card__thumb--pdf {
  background: linear-gradient(135deg, rgba(208, 48, 80, 0.18), rgba(208, 48, 80, 0.05));
  box-shadow: inset 0 0 0 1px rgba(208, 48, 80, 0.18);
}

.media-card__thumb--generic {
  background: linear-gradient(135deg, rgba(15, 23, 42, 0.12), rgba(15, 23, 42, 0.03));
  box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.12);
}

.media-card__thumb img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.media-card__thumb-fallback {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-weight: 600;
  font-size: 14px;
  color: #0f172a;
  padding: 8px;
  text-align: center;
}

.media-card__thumb--pdf .media-card__thumb-fallback {
  color: #d03050;
}

.media-card__thumb-icon {
  color: #9aa5b1;
}

.media-card__badge {
  position: absolute;
  top: 8px;
  left: 8px;
  font-size: 11px;
  font-weight: 600;
  color: #fff;
  background: rgba(24, 160, 88, 0.92);
  padding: 2px 6px;
  border-radius: 999px;
}

.media-card__body {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.media-card__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.media-card__title-block {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.media-card__title {
  font-weight: 600;
  font-size: 15px;
  color: #1f2933;
}

.media-card__file {
  font-size: 12px;
  color: #7b8794;
}

.media-card__meta {
  font-size: 12px;
  color: #61728a;
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.media-card__status {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: #4c566a;
}

.media-card__updated {
  color: #7b8794;
  font-size: 12px;
}

.media-card__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 4px;
}

.media-library__empty {
  width: 100%;
  padding: 32px 0;
  background: #fff;
  border-radius: 12px;
  box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.05);
}
</style>









