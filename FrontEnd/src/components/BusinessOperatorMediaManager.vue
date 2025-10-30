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
                list-type="image-card"
                :file-list="pendingUploadFileInfos"
                :max="MAX_PENDING_FILES"
                :accept="SUPPORTED_MEDIA_TYPES.join(',')"
                :default-upload="false"
                :on-before-upload="handleMediaFileSelect"
                :on-remove="handlePendingUploadRemove"
                @preview="handlePendingPreview"
              >
                <div class="media-upload__trigger">
                  <span class="media-upload__trigger-plus">+</span>
                  <span class="media-upload__trigger-text">Click to Upload</span>
                </div>
              </n-upload>
              <n-divider dashed class="media-upload__divider" />
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
                  <n-tag
                    v-if="section.listingId != null"
                    size="tiny"
                    bordered="false"
                    type="default"
                  >
                    ID: {{ section.listingId }}
                  </n-tag>
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
                      <div v-if="IS_DEV" class="media-card__debug-url">
                        {{ resolveAssetUrl(entry.asset) }}
                      </div>
                      <img
                        v-if="entry.preview.type === 'image'"
                        :src="entry.preview.thumbnail || resolveAssetUrl(entry.asset)"
                        :alt="entry.asset.label"
                      />
                      <div v-else class="media-card__thumb-fallback">
                        <span v-if="entry.preview.type === 'pdf'">PDF</span>
                        <span v-else>{{ entry.asset.type || 'Media' }}</span>
                      </div>
                      <div
                        v-if="entry.asset.isPrimary"
                        class="media-card__badge"
                      >
                        Primary
                      </div>
                    </div>

                    <div class="media-card__body">
                      <div class="media-card__header">
                        <div class="media-card__title-block">
                          <span class="media-card__title">{{ entry.asset.label }}</span>
                          <span v-if="entry.asset.fileName" class="media-card__file">
                            {{ entry.asset.fileName }}
                          </span>
                        </div>
                        <span class="media-card__updated" v-if="entry.updatedLabel">
                          Updated {{ entry.updatedLabel }}
                        </span>
                      </div>
                      <div class="media-card__meta">
                        <span>{{ entry.asset.type || 'Unknown type' }}</span>
                        <span v-if="entry.asset.mimeType">• {{ entry.asset.mimeType }}</span>
                        <span v-if="entry.asset.fileSize">• {{ formatBytes(entry.asset.fileSize) }}</span>
                      </div>
                      <div class="media-card__status">
                        <n-tag
                          size="tiny"
                          :type="resolveStatusTagType(entry.asset.status)"
                          bordered="false"
                        >
                          {{ entry.asset.status || 'Pending' }}
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
            <n-pagination
              v-if="mediaTablePageCount > 1"
              v-model:page="mediaTablePage"
              :page-count="mediaTablePageCount"
              size="small"
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
      style="width: 720px"
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
                    <span v-else>{{ entry.asset.type || 'Media' }}</span>
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
                    <span>{{ entry.asset.type || 'Unknown type' }}</span>
                    <span v-if="entry.asset.mimeType">• {{ entry.asset.mimeType }}</span>
                    <span v-if="entry.asset.fileSize">• {{ formatBytes(entry.asset.fileSize) }}</span>
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
  NPagination,
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

  for (const asset of mediaLibrary.value ?? []) {
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
    mediaLibrary.value.map((asset) => asset.type).filter((value) => value),
  )
  return [...base, ...Array.from(uniqueTypes).map((type) => ({ label: type, value: type }))]
})

const mediaTableBaseData = computed(() =>
  (mediaLibrary.value ?? [])
    .slice()
    .sort((a, b) => {
      const nameA = (a.listingName || 'Unassigned media').toLowerCase()
      const nameB = (b.listingName || 'Unassigned media').toLowerCase()
      if (nameA === nameB) {
        return new Date(b.lastUpdated ?? 0).getTime() - new Date(a.lastUpdated ?? 0).getTime()
      }
      return nameA.localeCompare(nameB)
    })
    .map((asset, index) => ({
      key: asset.id ?? index,
      listingKey: asset.listingId ?? '__unassigned__',
      listingName: asset.listingName ?? 'Unassigned media',
      listingId: asset.listingId ?? null,
      label: asset.label,
      type: asset.type,
      status: asset.status,
      isPrimary: Boolean(asset.isPrimary),
      lastUpdated: asset.lastUpdated,
      fileName: asset.fileName,
      mimeType: asset.mimeType,
      fileSize: asset.fileSize,
      url: asset.url,
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
      preview: getAssetPreview(row.asset),
      updatedLabel: formatUpdatedDate(row.asset.lastUpdated),
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
  const source = file?.url || file?.thumbnailUrl
  const mimeType = file?.type || ''
  const isPdf = mimeType === 'application/pdf' || file?.extension?.toUpperCase() === 'PDF'

  previewImageUrl.value = ''
  previewPdfUrl.value = ''

  if (source) {
    previewSourceUrl.value = source
    if (isPdf) {
      previewContentType.value = 'pdf'
      previewPdfUrl.value = source
    } else {
      previewContentType.value = 'image'
      previewImageUrl.value = source
    }
    previewModalVisible.value = true
    return
  }

  previewModalVisible.value = false
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
    return false
  }

  if (rawFile.size > MAX_MEDIA_SIZE_BYTES) {
    mediaEditUploadError.value = 'File exceeds the 5MB limit.'
    return false
  }

  const mimeType = rawFile.type ?? file?.type ?? ''
  if (mimeType && !SUPPORTED_MEDIA_TYPES.includes(mimeType)) {
    mediaEditUploadError.value = MEDIA_VALIDATION_MESSAGE
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
  } catch (error) {
    mediaEditUploadError.value =
      error instanceof Error ? error.message : 'Failed to upload file.'
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
    return false
  }

  if (rawFile.size > MAX_MEDIA_SIZE_BYTES) {
    mediaErrors.file = 'File exceeds the 5MB limit.'
    return false
  }

  const mimeType = rawFile.type ?? file?.type ?? ''
  if (mimeType && !SUPPORTED_MEDIA_TYPES.includes(mimeType)) {
    mediaErrors.file = MEDIA_VALIDATION_MESSAGE
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
    return
  }

  if (!props.operatorId) {
    mediaErrors.upload = 'Operator account not loaded. Please refresh and try again.'
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
    if (uploadedCount > 1) {
      mediaConfirmation.value = `${uploadedCount} media files uploaded successfully.`
    } else if (uploadedCount === 1) {
      mediaConfirmation.value = `${uploadedLabels[0]} uploaded successfully.`
    }
    clearMediaSelection()
    newMedia.label = ''
    newMedia.type = null
    newMedia.isPrimary = false
  } catch (error) {
    mediaErrors.upload = error instanceof Error ? error.message : 'Failed to upload media.'
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

  if (removalCount > 0) {
    mediaConfirmation.value = `${mediaEditForm.label.trim()} updated. ${removalCount} file${removalCount > 1 ? 's' : ''} removed.`
  } else {
    mediaConfirmation.value = `${mediaEditForm.label.trim()} updated successfully.`
  }
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
}

function removeMediaAsset(target) {
  mediaLibrary.value = mediaLibrary.value.filter((asset) => asset.id !== target.id)
  emitLibraryUpdate()
  mediaConfirmation.value = `${target.label} removed from the gallery.`
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
  min-height: 240px;
}

.preview-image {
  max-width: 100%;
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

.media-card__debug-url {
  position: absolute;
  bottom: 4px;
  left: 4px;
  right: 4px;
  padding: 2px 4px;
  font-size: 10px;
  color: #0f172a;
  background: rgba(255, 255, 255, 0.6);
  border-radius: 4px;
  word-break: break-all;
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
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 14px;
  color: #0f172a;
  padding: 8px;
  text-align: center;
}

.media-card__thumb--pdf .media-card__thumb-fallback {
  color: #d03050;
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

