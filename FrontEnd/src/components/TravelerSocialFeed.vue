<template>
  <n-dialog-provider>
    <div class="social-feed">
      <n-page-header
        v-if="!hideHeader"
        class="feed-header"
        title="Community hub"
      subtitle="Discover how other eco-conscious travelers explore, save, and share.">
      <template #extra>
        <n-button type="primary" size="small" @click="startCreateStory">
          <template #icon>
            <n-icon>
              <i class="ri-add-line" />
            </n-icon>
          </template>
          Share a story
        </n-button>
      </template>
    </n-page-header>

      <div v-if="!hideHeader && categoryOptions.length" class="category-bar">
        <n-tabs v-model:value="activeCategory" type="segment" size="small" class="category-tabs">
          <n-tab-pane
            v-for="category in categoryOptions"
            :key="category.value"
            :name="category.value"
            :tab="category.label"
          />
        </n-tabs>
      </div>

    <n-alert v-if="postsError" type="error" closable class="feed-alert" @close="postsError = ''">
      {{ postsError }}
    </n-alert>

    <n-spin :show="postsLoading">
      <div v-if="filteredPosts.length" class="feed-grid">
        <article v-for="post in filteredPosts" :key="post.id" class="post-card">
          <n-card size="small" :segmented="{ content: true, footer: 'soft' }">
            <template #header>
              <div class="post-header">
                <n-avatar round size="large" :src="post.authorAvatar">
                  {{ post.authorInitials }}
                </n-avatar>
                <div class="post-author">
                  <strong>{{ post.authorName }}</strong>
                  <n-text depth="3">
                    {{ post.authorUsername ? '@' + post.authorUsername : 'Community traveler' }}
                    <span v-if="post.location"> - {{ post.location }}</span>
                  </n-text>
                </div>
              </div>
            </template>

            <template #cover>
              <div class="post-media" @click="openPostDetail(post)">
                <template v-if="getActiveMediaType(post) === 'video'">
                  <video :src="getActiveMediaUrl(post)" controls playsinline preload="metadata" />
                </template>
                <template v-else>
                  <img :src="getActiveMediaUrl(post)" :alt="post.caption" loading="lazy" />
                </template>
                <div v-if="hasMultipleMedia(post)" class="post-media-controls">
                  <button
                    class="post-media-nav post-media-nav--prev"
                    type="button"
                    aria-label="View previous media"
                    @click.stop="viewPrevMedia(post)"
                  >
                    <i class="ri-arrow-left-s-line" />
                  </button>
                  <button
                    class="post-media-nav post-media-nav--next"
                    type="button"
                    aria-label="View next media"
                    @click.stop="viewNextMedia(post)"
                  >
                    <i class="ri-arrow-right-s-line" />
                  </button>
                </div>
                <div v-if="hasMultipleMedia(post)" class="post-media-counter">
                  {{ getMediaPositionLabel(post) }}
                </div>
                <div class="post-media-badge" v-if="post.duration">
                  <n-tag size="tiny" type="info" round strong>
                    <template #icon>
                      <n-icon>
                        <i class="ri-time-line" />
                      </n-icon>
                    </template>
                    {{ post.duration }}
                  </n-tag>
                </div>
              </div>
            </template>

            <div class="post-body">
              <p class="post-caption">{{ post.caption }}</p>
              <n-space v-if="post.tags.length" size="small" wrap>
                <n-tag v-for="tag in post.tags" :key="tag" size="small" type="success" round>
                  #{{ tag }}
                </n-tag>
              </n-space>
            </div>

            <template #footer>
              <div class="post-footer">
                <div class="post-footer-actions">
                  <n-button text size="small" :type="resolveMetricFlag(post, 'isLiked') ? 'primary' : 'default'"
                    @click.stop="toggleReaction('like', post)">
                    <template #icon>
                      <n-icon>
                        <i :class="resolveMetricFlag(post, 'isLiked') ? 'ri-heart-3-fill' : 'ri-heart-3-line'" />
                      </n-icon>
                    </template>
                    {{ formatMetric(resolveMetricCount(post, 'likes')) }}
                  </n-button>

                  <n-button text size="small" @click.stop="openPostDetail(post)">
                    <template #icon>
                      <n-icon>
                        <i class="ri-chat-1-line" />
                      </n-icon>
                    </template>
                    {{ formatMetric(resolveMetricCount(post, 'comments')) }}
                  </n-button>

                  <n-button text size="small" :type="resolveMetricFlag(post, 'isSaved') ? 'primary' : 'default'"
                    @click.stop="toggleReaction('save', post)">
                    <template #icon>
                      <n-icon>
                        <i :class="resolveMetricFlag(post, 'isSaved') ? 'ri-bookmark-fill' : 'ri-bookmark-line'" />
                      </n-icon>
                    </template>
                    {{ formatMetric(resolveMetricCount(post, 'saves')) }}
                  </n-button>
                </div>

                <div class="post-footer-meta">
                  <n-tooltip v-if="!post.isOwn" placement="top">
                    <template #trigger>
                      <n-button text size="small" @click.stop="emitContact(post)">
                        <template #icon>
                          <n-icon>
                            <i class="ri-send-plane-line" />
                          </n-icon>
                        </template>
                        Contact
                      </n-button>
                    </template>
                    Reach out to collaborate or ask for tips.
                  </n-tooltip>
                  <div v-else class="post-owner-tools">
                    <TravelerPostActions :post="post" :disabled="isDeletingPost(post.id)"
                      @edit="handleEditPost" @delete="handleDeletePost" />
                  </div>
                </div>
              </div>
            </template>
          </n-card>
        </article>
      </div>
      <n-empty v-else description="No posts yet. Encourage travelers to share their journeys." />
    </n-spin>

    <n-modal v-model:show="postModalVisible" class="post-detail-modal" preset="card" :segmented="false"
      :style="{ maxWidth: '960px' }" @after-leave="closePostDetail">
      <template #header>
        <div class="detail-header" v-if="expandedPost">
          <n-avatar round size="large" :src="expandedPost.authorAvatar">
            {{ expandedPost.authorInitials }}
          </n-avatar>
          <div class="detail-meta">
            <strong>{{ expandedPost.authorName }}</strong>
            <n-text depth="3">
              {{ expandedPost.authorUsername ? '@' + expandedPost.authorUsername : 'Community traveler' }}
              <span v-if="expandedPost.location"> - {{ expandedPost.location }}</span>
            </n-text>
          </div>
          <div class="detail-header-actions">
            <TravelerPostActions v-if="expandedPost.isOwn" :post="expandedPost"
              :disabled="isDeletingPost(expandedPost.id)" @edit="handleEditPostFromDetail"
              @delete="handleDeletePost" />
          </div>
        </div>
      </template>
      <div v-if="expandedPost" class="detail-content">
        <div class="detail-media">
          <video
            v-if="getActiveMediaType(expandedPost) === 'video'"
            :src="getActiveMediaUrl(expandedPost)"
            controls
            playsinline
            preload="metadata"
            :autoplay="expandedPostAutoplay"
            :muted="expandedPostMuted"
            @loadedmetadata="handleDetailVideoReady"
            @volumechange="handleDetailVolumeChange"
          />
          <img
            v-else
            :src="getActiveMediaUrl(expandedPost)"
            :alt="expandedPost.caption"
          />
          <div v-if="hasMultipleMedia(expandedPost)" class="detail-media-controls">
            <button
              class="detail-media-nav detail-media-nav--prev"
              type="button"
              aria-label="View previous media"
              @click.stop="viewPrevMedia(expandedPost)"
            >
              <i class="ri-arrow-left-s-line" />
            </button>
            <button
              class="detail-media-nav detail-media-nav--next"
              type="button"
              aria-label="View next media"
              @click.stop="viewNextMedia(expandedPost)"
            >
              <i class="ri-arrow-right-s-line" />
            </button>
          </div>
          <div v-if="hasMultipleMedia(expandedPost)" class="detail-media-counter">
            {{ getMediaPositionLabel(expandedPost) }}
          </div>
        </div>
        <div class="detail-body">
          <p class="detail-caption">{{ expandedPost.caption }}</p>
          <n-space v-if="expandedPost.tags.length" size="small" wrap>
            <n-tag v-for="tag in expandedPost.tags" :key="tag" size="small" type="success" round>
              #{{ tag }}
            </n-tag>
          </n-space>
          <div class="detail-actions">
            <n-button text size="small" @click.stop="toggleReaction('like', expandedPost)">
              <template #icon>
                <n-icon>
                  <i :class="resolveMetricFlag(expandedPost, 'isLiked') ? 'ri-heart-3-fill' : 'ri-heart-3-line'" />
                </n-icon>
              </template>
              {{ formatMetric(resolveMetricCount(expandedPost, 'likes')) }} likes
            </n-button>
            <n-button text size="small" @click.stop="focusCommentComposer()">
              <template #icon>
                <n-icon>
                  <i class="ri-chat-1-line" />
                </n-icon>
              </template>
              {{ formatMetric(resolveMetricCount(expandedPost, 'comments')) }} comments
            </n-button>
            <n-button text size="small" @click.stop="toggleReaction('save', expandedPost)">
              <template #icon>
                <n-icon>
                  <i :class="resolveMetricFlag(expandedPost, 'isSaved') ? 'ri-bookmark-fill' : 'ri-bookmark-line'" />
                </n-icon>
              </template>
              {{ formatMetric(resolveMetricCount(expandedPost, 'saves')) }} saves
            </n-button>
            <n-tooltip placement="top" v-if="!expandedPost.isOwn">
              <template #trigger>
                <n-button text size="small" @click.stop="emitContact(expandedPost); postModalVisible = false">
                  <template #icon>
                    <n-icon>
                      <i class="ri-send-plane-line" />
                    </n-icon>
                  </template>
                  Contact
                </n-button>
              </template>
              Reach out to collaborate or ask for tips.
            </n-tooltip>
          </div>

          <n-divider />

          <div class="comment-compose" v-if="currentUserInfo.id">
            <n-input ref="commentInputRef" type="textarea" v-model:value="commentsState.newContent" placeholder="Share your thoughts"
              :autosize="{ minRows: 2, maxRows: 4 }" />
            <div class="comment-compose-actions">
              <n-rate v-model:value="commentsState.newRating" size="small" clearable />
              <n-space>
                <n-button size="small" quaternary @click="resetCommentComposer">Cancel</n-button>
                <n-button size="small" type="primary" :loading="commentsState.submitting"
                  @click="submitComment">
                  Post comment
                </n-button>
              </n-space>
            </div>
          </div>
          <n-alert v-else type="info" :bordered="false" class="comment-signin-hint">
            Sign in as a traveler to join the discussion.
          </n-alert>

          <n-spin :show="commentsState.loading">
            <n-empty v-if="!commentsState.items.length && !commentsState.loading"
              description="No comments yet. Be the first to share your thoughts." />
            <div v-else class="comment-list scrollable-comments">
              <div v-for="comment in commentsState.items" :key="comment.id" class="comment-item">
                <div class="comment-header">
                  <div class="comment-author">
                    <strong>{{ comment.authorName }}</strong>
                    <n-text depth="3">
                      {{ comment.authorUsername ? '@' + comment.authorUsername : 'Traveler' }}
                      <span> - {{ comment.createdAtLabel }}</span>
                    </n-text>
                  </div>
                  <n-space size="small">
                    <n-rate v-if="comment.rating" :value="comment.rating" size="tiny" readonly />
                    <n-button v-if="comment.travelerId === currentUserInfo.id" text size="tiny"
                      type="error" @click="deleteComment(comment)">
                      Delete
                    </n-button>
                  </n-space>
                </div>
                <p class="comment-content">{{ comment.content }}</p>
              </div>
            </div>
          </n-spin>
        </div>
      </div>
    </n-modal>

    <n-modal
      v-model:show="storyModalVisible"
      class="story-modal"
      preset="card"
      :title="storyModalTitle"
      :mask-closable="false"
      :style="{ maxWidth: '880px', marginTop: '40px', marginBottom: '40px' }"
    >
      <div class="story-card">
        <section class="story-card__header">
          <div class="story-card__badge">
            <n-icon size="16"><i class="ri-seedling-line" /></n-icon>
            <span>Sustainable spotlight</span>
          </div>
          <div class="story-card__heading">
            <h3>Inspire travelers with eco-positive moments</h3>
            <p>
              Add captions, locations, and tags to surface your sustainable experiences.
              Thoughtful context paired with rich media helps your story reach eco-focused explorers.
            </p>
          </div>
          <ul class="story-card__tips">
            <li>
              <n-icon size="16"><i class="ri-check-line" /></n-icon>
              2-3 sentences describing the positive impact
            </li>
            <li>
              <n-icon size="16"><i class="ri-check-line" /></n-icon>
              Add locations, tags, and categories for discoverability
            </li>
            <li>
              <n-icon size="16"><i class="ri-check-line" /></n-icon>
              Use clear, well-lit photos or short video clips
            </li>
          </ul>
        </section>

        <n-form
          ref="storyFormRef"
          :model="storyForm"
          :rules="storyRules"
          label-placement="top"
          size="large"
          class="story-card__form"
        >
          <n-form-item label="Caption" path="caption">
            <n-input
              v-model:value="storyForm.caption"
              type="textarea"
              maxlength="1000"
              show-count
              placeholder="Tell the community about your sustainable travel moment."
              :autosize="{ minRows: 3, maxRows: 5 }"
            />
          </n-form-item>

          <n-form-item label="Media files" path="mediaItems">
            <div class="story-upload-card">
              <n-upload
                multiple
                directory-dnd
                :disabled="isEditingStory"
                :max="STORY_MAX_MEDIA"
                accept="image/*,video/*"
                :default-upload="false"
                :show-file-list="false"
                :file-list="storyUploadFileList"
                :on-before-upload="handleStoryFileSelect"
              >
                <n-upload-dragger class="story-upload-card__dragger">
                  <n-space vertical align="center" size="small">
                    <div class="story-upload-card__icon">
                      <n-icon size="26">
                        <i class="ri-upload-cloud-2-line" />
                      </n-icon>
                    </div>
                    <n-text depth="3">
                      Drag up to {{ STORY_MAX_MEDIA }} images or videos here
                    </n-text>
                    <n-text depth="3">or browse from your device</n-text>
                    <n-button size="tiny" type="primary" quaternary :disabled="isEditingStory">
                      Select files
                    </n-button>
                    <n-text depth="3" class="story-upload-card__hint">
                      Each file must be under 25MB. First media appears as the cover.
                    </n-text>
                  </n-space>
                </n-upload-dragger>
              </n-upload>

              <div
                v-if="isEditingStory && storyForm.existingMedia.length"
                class="story-media-gallery story-media-gallery--existing"
              >
                <div
                  v-for="item in storyForm.existingMedia"
                  :key="`existing-${item.id}`"
                  class="story-media-card story-media-card--existing"
                >
                  <div
                    class="story-media-card__thumb"
                    role="button"
                    tabindex="0"
                    @click="openStoryMediaPreview(item)"
                    @keydown.enter.prevent="openStoryMediaPreview(item)"
                    @keydown.space.prevent="openStoryMediaPreview(item)"
                  >
                    <img v-if="item.type === 'image'" :src="item.url" alt="Existing story media" />
                    <video
                      v-else
                      :src="item.url"
                      preload="metadata"
                      muted
                      playsinline
                      loop
                    />
                  </div>
                  <div class="story-media-card__name">{{ item.name || 'Existing media' }}</div>
                </div>
              </div>

              <div v-if="storyForm.mediaItems.length" class="story-media-gallery">
                <div
                  v-for="item in storyForm.mediaItems"
                  :key="item.id"
                  class="story-media-card"
                >
                  <div
                    class="story-media-card__thumb"
                    role="button"
                    tabindex="0"
                    @click="openStoryMediaPreview(item)"
                    @keydown.enter.prevent="openStoryMediaPreview(item)"
                    @keydown.space.prevent="openStoryMediaPreview(item)"
                  >
                    <img
                      v-if="item.type === 'image'"
                      :src="item.previewUrl"
                      :alt="item.name || 'Story media'"
                    />
                    <video
                      v-else
                      :src="item.previewUrl"
                      preload="metadata"
                      muted
                      playsinline
                      loop
                    />
                    <n-button
                      class="story-media-card__remove"
                      circle
                      size="tiny"
                      type="error"
                      quaternary
                      @click.stop="removeStoryMedia(item.id)"
                    >
                      <template #icon>
                        <n-icon><i class="ri-close-line" /></n-icon>
                      </template>
                    </n-button>
                  </div>
                  <div class="story-media-card__name" :title="item.name || ''">
                    {{ item.name || (item.type === 'video' ? 'Video file' : 'Image file') }}
                  </div>
                </div>
              </div>
            </div>
          </n-form-item>

          <n-grid cols="1 m:2" :x-gap="12">
            <n-grid-item>
              <n-form-item label="Location" path="location">
                <n-input
                  v-model:value="storyForm.location"
                  placeholder="e.g. Perhentian Islands, Malaysia"
                  clearable
                />
              </n-form-item>
            </n-grid-item>
            <n-grid-item>
              <n-form-item label="Tags" path="tags">
                <n-input
                  v-model:value="storyForm.tags"
                  placeholder="Separate with commas e.g. reef, cleanup"
                  clearable
                />
              </n-form-item>
            </n-grid-item>
          </n-grid>

          <n-form-item label="Categories" path="categories">
            <n-select
              v-model:value="storyForm.categories"
              :options="categorySelectOptions"
              multiple
              tag
              placeholder="Pick or create categories"
            />
          </n-form-item>
        </n-form>

        <n-modal
          v-model:show="storyMediaPreviewState.visible"
          preset="card"
          :segmented="false"
          class="story-media-preview-modal"
          :style="{ maxWidth: '720px' }"
          @after-leave="closeStoryMediaPreview"
        >
          <template #header>
            {{ storyMediaPreviewState.name || 'Media preview' }}
          </template>
          <div class="story-media-preview">
            <video
              v-if="storyMediaPreviewState.type === 'video'"
              :src="storyMediaPreviewState.url"
              controls
              autoplay
              playsinline
              preload="metadata"
            />
            <img
              v-else
              :src="storyMediaPreviewState.url"
              :alt="storyMediaPreviewState.name || 'Story media preview'"
            />
          </div>
        </n-modal>
      </div>

      <template #action>
        <n-space justify="end" :size="12">
          <n-button tertiary @click="handleStoryCancel" :disabled="storySubmitting">Cancel</n-button>
          <n-button type="primary" @click="handleStorySubmit" :loading="storySubmitting">
            {{ isEditingStory ? 'Update story' : 'Post story' }}
          </n-button>
        </n-space>
      </template>
    </n-modal>
    </div>
  </n-dialog-provider>
</template>

<script setup>
import { computed, reactive, ref, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import {
  NAlert,
  NAvatar,
  NButton,
  NCard,
  NDialogProvider,
  NEmpty,
  NForm,
  NFormItem,
  NGrid,
  NGridItem,
  NIcon,
  NInput,
  NDivider,
  NModal,
  NPageHeader,
  NRate,
  NSelect,
  NSpace,
  NSpin,
  NTabPane,
  NTabs,
  NTag,
  NText,
  NTooltip,
  NUpload,
  NUploadDragger,
  useMessage,
} from 'naive-ui'
import TravelerPostActions from './TravelerPostActions.vue'

const emit = defineEmits(['contact', 'post-updated', 'post-removed'])

const props = defineProps({
  posts: {
    type: Array,
    default: () => [],
  },
  hideHeader: {
    type: Boolean,
    default: false,
  },
  categories: {
    type: Array,
    default: () => [],
  },
  currentUser: {
    type: Object,
    default: () => null,
  },
  disableFetch: {
    type: Boolean,
    default: false,
  },
})

const message = useMessage()
const hideHeader = computed(() => !!props.hideHeader)
const shouldAutoFetch = computed(() => !props.disableFetch)

const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const COMMUNITY_ENDPOINT = `${API_BASE}/community/posts.php`
const COMMUNITY_MEDIA_ENDPOINT = `${API_BASE}/community/media.php`
const PUBLIC_ASSETS_BASE = normaliseBaseUrl(import.meta.env.VITE_PUBLIC_ASSETS_BASE || '')
const PLACEHOLDER_IMAGE = `data:image/svg+xml;utf8,${encodeURIComponent(
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480">
    <defs>
      <linearGradient id="placeholderGradient" x1="0%" x2="100%" y1="0%" y2="100%">
        <stop offset="0%" stop-color="#e6f4f1" />
        <stop offset="100%" stop-color="#cde4de" />
      </linearGradient>
    </defs>
    <rect width="640" height="480" fill="url(#placeholderGradient)" />
    <g fill="none" stroke="#8fb7ad" stroke-width="8">
      <rect x="40" y="40" width="560" height="360" rx="28" ry="28" />
      <path d="M140 320 L240 220 L320 280 L420 180 L500 260" stroke-linecap="round" stroke-linejoin="round"/>
      <circle cx="220" cy="160" r="36" />
    </g>
    <text x="50%" y="440" font-family="Poppins, Arial, sans-serif" font-size="36" fill="#6c8f87" text-anchor="middle">
      Eco story placeholder
    </text>
  </svg>`
)}`

const storyModalVisible = ref(false)
const storySubmitting = ref(false)
const editingStoryId = ref(null)
const storyFormRef = ref(null)
const activePost = ref(null)
const serverPosts = ref([])
const postsLoading = ref(false)
const postsError = ref('')
const deletingPostIds = ref(new Set())
const expandedPostMuted = ref(true)
const expandedPostAutoplay = ref(true)

const STORY_MAX_MEDIA = 10
const STORY_MAX_MEDIA_SIZE_BYTES = 25 * 1024 * 1024

function createStoryFormState() {
  return {
    caption: '',
    mediaItems: [],
    existingMedia: [],
    location: '',
    tags: '',
    categories: [],
  }
}

const storyForm = reactive(createStoryFormState())

const storyRules = {
  caption: [
    {
      required: true,
      message: 'Share a short caption for your story.',
      trigger: ['blur', 'input'],
    },
  ],
  mediaItems: [
    {
      validator: validateMediaFile,
      trigger: ['change', 'blur'],
    },
  ],
}

const isEditingStory = computed(() => editingStoryId.value !== null)
const storyModalTitle = computed(() =>
  isEditingStory.value ? 'Edit your story' : 'Share a new story'
)

const storyUploadFileList = computed(() =>
  storyForm.mediaItems
    .filter((item) => item.source === 'new')
    .map((item) => ({
      id: item.id,
      name: item.name ?? 'media',
      status: 'finished',
      percentage: 100,
      url: item.previewUrl ?? '',
      thumbnailUrl: item.previewUrl ?? '',
    }))
)

const storyMediaPreviewState = reactive({
  visible: false,
  url: '',
  type: 'image',
  name: '',
})

const commentsState = reactive({
  storyId: null,
  items: [],
  loading: false,
  submitting: false,
  error: '',
  hasLoaded: false,
  newContent: '',
  newRating: null,
})

const liveMetrics = reactive({})
const mediaStates = reactive({})

const commentInputRef = ref(null)

const currentUserInfo = computed(() => {
  const source = props.currentUser ?? {}
  const id =
    source.id ??
    source.userId ??
    source.travelerID ??
    source.travelerId ??
    null
  const name = source.displayName ?? source.fullName ?? source.username ?? 'Traveler'
  const username = source.username ?? source.handle ?? ''
  const initials = source.initials ?? computeInitials(name || username || 'Traveler')
  const location = source.location ?? ''
  const avatar =
    source.avatar ??
    source.avatarUrl ??
    source.profileImage ??
    ''

  return {
    id,
    name,
    username,
    initials,
    location,
    avatar,
  }
})


const fallbackCategories = [
  { value: 'all', label: 'All' },
  { value: 'adventure', label: 'Adventure' },
  { value: 'community', label: 'Community' },
  { value: 'food', label: 'Food' },
  { value: 'lodging', label: 'Lodging' },
  { value: 'volunteering', label: 'Volunteering' },
]


const basePosts = computed(() => {
  if (shouldAutoFetch.value && serverPosts.value.length) {
    return serverPosts.value
  }
  if (props.posts.length) {
    return props.posts
  }
  return []
})

const storyDateFormatter = new Intl.DateTimeFormat(undefined, {
  year: 'numeric',
  month: 'short',
  day: 'numeric',
  hour: 'numeric',
  minute: '2-digit',
})

function formatAbsoluteDateLabel(value) {
  if (!value) return ''
  const normalized = String(value).trim()
  const isoCandidate = normalized.includes('T') ? normalized : normalized.replace(' ', 'T')
  const date = new Date(isoCandidate)
  if (Number.isNaN(date.getTime())) {
    return ''
  }
  return storyDateFormatter.format(date)
}

function deriveTimelineMeta(post) {
  const createdRaw = post.createdAt ?? post.postedAt ?? ''
  const updatedRaw = post.updatedAt ?? ''

  const createdLabel = post.createdAtLabel || formatAbsoluteDateLabel(createdRaw)
  const updatedLabel = post.updatedAtLabel || formatAbsoluteDateLabel(updatedRaw)

  const typeFromApi = post.timelineType || post.timelineStatus || ''
  const hasUpdate =
    (typeFromApi && typeFromApi === 'updated') ||
    (updatedRaw && updatedRaw !== createdRaw && updatedLabel)

  const type = hasUpdate ? 'updated' : 'created'
  const baseLabel = type === 'updated' ? updatedLabel : createdLabel
  const label = baseLabel || ''

  return {
    type,
    label,
    createdLabel,
    updatedLabel,
  }
}

function ensureStringArray(value) {
  if (!value) return []
  if (Array.isArray(value)) {
    return value
      .map((item) => (typeof item === 'string' ? item : String(item)))
      .map((item) => item.trim())
      .filter(Boolean)
  }
  return String(value)
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
}

function normalisePost(post, index = 0) {
  const id = post.id ?? `community-post-${index}`
  const rawMedia = Array.isArray(post.media) ? post.media : []
  const mediaItems = rawMedia.length
    ? rawMedia
        .map((item, mediaIndex) => {
          const originalPath = item.url ?? item.mediaUrl ?? item.mediaPath ?? ''
          const resolvedUrl = resolveAssetUrl(originalPath)
          if (!resolvedUrl) {
            return null
          }
          const type = item.type ?? item.mediaType ?? (item.mimeType?.startsWith?.('video/') ? 'video' : 'image')

          return {
            id: item.id ?? `${id}-media-${mediaIndex}`,
            type: type === 'video' ? 'video' : 'image',
            url: resolvedUrl,
            position: item.position ?? mediaIndex,
            name:
              item.name ??
              item.title ??
              item.label ??
              item.fileName ??
              item.filename ??
              resolveMediaNameFromSource(resolvedUrl),
          }
        })
        .filter((item) => item !== null)
    : []

  if (!mediaItems.length) {
    const fallbackType = post.mediaType === 'video' ? 'video' : 'image'
    const fallbackUrl = post.mediaUrl || PLACEHOLDER_IMAGE
    mediaItems.push({
      id: `${id}-media-0`,
      type: fallbackType,
      url: resolveAssetUrl(fallbackUrl),
      position: 0,
      name: resolveMediaNameFromSource(fallbackUrl),
    })
  }

  mediaItems.sort((a, b) => a.position - b.position)
  const cover = mediaItems[0]

  const authorId =
    post.authorId ??
    post.authorID ??
    post.travelerId ??
    post.travelerID ??
    null

  const authorTypeRaw =
    post.authorType ??
    post.author_type ??
    post.authorRole ??
    (post.operatorID ? 'operator' : 'traveler')
  const authorType =
    typeof authorTypeRaw === 'string' && authorTypeRaw.trim()
      ? authorTypeRaw.trim().toLowerCase()
      : 'traveler'
  const isOwn =
    typeof post.isOwn === 'boolean'
      ? post.isOwn
      : Boolean(
          authorId &&
            currentUserInfo.value.id &&
            String(authorId) === String(currentUserInfo.value.id)
        )

  const viewerCommentCountRaw =
    post.viewerCommentCount ?? post.viewerComments ?? post.viewerCommentedCount ?? post.viewerCommentTotal ?? 0
  const viewerCommentCount = Number.isFinite(Number(viewerCommentCountRaw))
    ? Number(viewerCommentCountRaw)
    : 0
  const hasCommented =
    typeof post.viewerHasCommented === 'boolean'
      ? post.viewerHasCommented
      : typeof post.hasCommented === 'boolean'
      ? post.hasCommented
      : viewerCommentCount > 0

  const createdAt = post.createdAt || post.postedAt || ''
  const updatedAt = post.updatedAt || ''
  const timeline = deriveTimelineMeta(post)
  const normalised = {
    ...post,
    id,
    media: mediaItems,
    mediaCount: mediaItems.length,
    mediaType: cover.type,
    mediaUrl: cover.url,
    caption:
      post.caption ||
      'Share your sustainable travel highlight to inspire the community.',
    authorName: post.authorName || 'Traveler',
    authorUsername: post.authorUsername || post.username || '',
    authorAvatar: resolveAssetUrl(post.authorAvatar || post.profileImage || ''),
    authorType,
    authorInitials: computeInitials(post.authorName || post.authorUsername || 'Traveler'),
    postedAtLabel:
      timeline.label ||
      post.timelineLabel ||
      post.postedAtLabel ||
      createdAt ||
      '',
    postedAtRelativeLabel: post.postedAtLabel || '',
    timelineType: timeline.type,
    timelineLabel: timeline.label,
    createdAt,
    createdAtLabel: timeline.createdLabel || createdAt,
    updatedAt,
    updatedAtLabel: timeline.updatedLabel || updatedAt,
    location: post.location || '',
    authorId,
    isOwn,
    likes: Number.isFinite(Number(post.likes)) ? Number(post.likes) : 0,
    comments: Number.isFinite(Number(post.comments)) ? Number(post.comments) : 0,
    saves: Number.isFinite(Number(post.saves)) ? Number(post.saves) : 0,
    isLiked: Boolean(post.isLiked),
    isSaved: Boolean(post.isSaved),
    hasCommented,
    viewerCommentCount,
    tags: ensureStringArray(post.tags),
    categories: ensureStringArray(post.categories),
    duration: post.duration || '',
    __normalized: true,
  }
  ensureMediaState(normalised)
  return normalised
}

const normalisedBasePosts = computed(() =>
  basePosts.value.map((post, index) => {
    const normalised = post && post.__normalized ? post : normalisePost(post, index)
    ensureMetrics(resolveStoryId(normalised), normalised)
    ensureMediaState(normalised)
    return normalised
  })
)

const allPosts = computed(() => normalisedBasePosts.value)

const categoryOptions = computed(() => {
  const provided = props.categories.length
    ? props.categories.map((item) => ({
      value: item.value ?? item.key ?? item.id ?? item.slug ?? String(item),
      label: item.label ?? item.name ?? item.title ?? formatLabel(item.value ?? item.key ?? item),
    }))
    : fallbackCategories

  const categorySet = new Map(provided.map((item) => [item.value, item.label]))

  allPosts.value.forEach((post) => {
    post.categories.forEach((category) => {
      const key = category || 'uncategorised'
      if (!categorySet.has(key)) {
        categorySet.set(key, formatLabel(key))
      }
    })
  })

  if (!categorySet.has('all')) {
    categorySet.set('all', 'All')
  }

  const orderedEntries = Array.from(categorySet.entries()).sort((a, b) => {
    if (a[0] === 'all') return -1
    if (b[0] === 'all') return 1
    return 0
  })

  return orderedEntries.map(([value, label]) => ({
    value,
    label,
  }))
})

const categorySelectOptions = computed(() =>
  categoryOptions.value
    .filter((option) => option.value !== 'all')
    .map((option) => ({
      label: option.label,
      value: option.value,
    }))
)

const activeCategory = ref('all')

watch(
  categoryOptions,
  (options) => {
    if (!options.some((option) => option.value === activeCategory.value)) {
      activeCategory.value = options[0]?.value ?? 'all'
    }
  },
  { immediate: true }
)

const filteredPosts = computed(() => {
  if (activeCategory.value === 'all') {
    return allPosts.value
  }
  return allPosts.value.filter((post) => post.categories.includes(activeCategory.value))
})

const postModalVisible = ref(false)
const expandedPost = computed(() => activePost.value)

onMounted(() => {
  if (shouldAutoFetch.value) {
    fetchCommunityPosts()
  }
})

watch(
  shouldAutoFetch,
  (enabled) => {
    if (enabled && !serverPosts.value.length) {
      fetchCommunityPosts()
    }
  }
)

async function fetchCommunityPosts() {
  if (!shouldAutoFetch.value) {
    return
  }
  postsLoading.value = true
  postsError.value = ''
  try {
    const params = new URLSearchParams()
    if (currentUserInfo.value.id) {
      params.set('travelerId', String(currentUserInfo.value.id))
    }
    const url = params.toString() ? `${COMMUNITY_ENDPOINT}?${params.toString()}` : COMMUNITY_ENDPOINT
    const response = await fetch(url)
    if (!response.ok) {
      const errorPayload = await safeJson(response)
      throw new Error(errorPayload?.error ?? 'Failed to load community posts.')
    }

    const payload = await response.json()
    const posts = Array.isArray(payload?.posts) ? payload.posts : []
    serverPosts.value = posts.map((post, index) => normalisePost(post, index))
  } catch (error) {
    console.error('Failed to load community posts', error)
    postsError.value = error instanceof Error ? error.message : 'Failed to load community posts.'
  } finally {
    postsLoading.value = false
  }
}

function resolveStoryId(post) {
  if (!post) return null
  return post.id ?? post.ID ?? post.storyId ?? null
}

function normaliseMetricValue(value) {
  const number = Number(value)
  if (!Number.isFinite(number)) {
    return 0
  }
  return number < 0 ? 0 : Math.round(number)
}

function resolveMetricCount(post, key) {
  const storyId = resolveStoryId(post)
  const metrics = ensureMetrics(storyId, post)
  const value = metrics ? metrics[key] : post?.[key]
  return normaliseMetricValue(value)
}

function resolveMetricFlag(post, key) {
  const storyId = resolveStoryId(post)
  const metrics = ensureMetrics(storyId, post)
  return metrics ? Boolean(metrics[key]) : Boolean(post?.[key])
}

function ensureMediaState(post) {
  if (!post) {
    return null
  }
  const storyId = resolveStoryId(post)
  if (!storyId) {
    return null
  }
  const key = String(storyId)
  if (!mediaStates[key]) {
    mediaStates[key] = reactive({ index: 0 })
  }
  const list = Array.isArray(post.media) ? post.media : []
  if (!list.length) {
    mediaStates[key].index = 0
  } else if (mediaStates[key].index < 0 || mediaStates[key].index >= list.length) {
    mediaStates[key].index = 0
  }
  return mediaStates[key]
}

function getMediaList(post) {
  return Array.isArray(post?.media) ? post.media : []
}

function getActiveMedia(post) {
  const list = getMediaList(post)
  if (!list.length) {
    return null
  }
  const state = ensureMediaState(post)
  if (!state) {
    return list[0]
  }
  return list[state.index] ?? list[0]
}

function getActiveMediaType(post) {
  return getActiveMedia(post)?.type ?? (post?.mediaType ?? 'image')
}

function getActiveMediaUrl(post) {
  return getActiveMedia(post)?.url ?? post?.mediaUrl ?? PLACEHOLDER_IMAGE
}

function hasMultipleMedia(post) {
  return getMediaList(post).length > 1
}

function getMediaPosition(post) {
  const list = getMediaList(post)
  const total = list.length
  if (!total) {
    return { current: 0, total: 0 }
  }
  const state = ensureMediaState(post)
  const index = state ? state.index : 0
  return { current: index + 1, total }
}

function getMediaPositionLabel(post) {
  const position = getMediaPosition(post)
  if (!position.total) {
    return ''
  }
  return `${position.current}/${position.total}`
}

function shiftMedia(post, step) {
  const list = getMediaList(post)
  if (list.length <= 1) {
    return
  }
  const state = ensureMediaState(post)
  if (!state) {
    return
  }
  const nextIndex = (state.index + step + list.length) % list.length
  state.index = nextIndex
}

function viewNextMedia(post) {
  shiftMedia(post, 1)
  expandedPostAutoplay.value = true
}

function viewPrevMedia(post) {
  shiftMedia(post, -1)
  expandedPostAutoplay.value = true
}

function setMetricValues(post, patch = {}) {
  const storyId = resolveStoryId(post)
  const metrics = ensureMetrics(storyId, post)
  if (!metrics) {
    return
  }
  if (Object.prototype.hasOwnProperty.call(patch, 'likes')) {
    metrics.likes = normaliseMetricValue(patch.likes)
  }
  if (Object.prototype.hasOwnProperty.call(patch, 'saves')) {
    metrics.saves = normaliseMetricValue(patch.saves)
  }
  if (Object.prototype.hasOwnProperty.call(patch, 'comments')) {
    metrics.comments = normaliseMetricValue(patch.comments)
  }
  if (Object.prototype.hasOwnProperty.call(patch, 'isLiked')) {
    metrics.isLiked = Boolean(patch.isLiked)
  }
  if (Object.prototype.hasOwnProperty.call(patch, 'isSaved')) {
    metrics.isSaved = Boolean(patch.isSaved)
  }
}

function adjustMetricCount(current, delta) {
  const base = Number.isFinite(Number(current)) ? Number(current) : 0
  const next = base + delta
  return next < 0 ? 0 : next
}

function ensureMetrics(storyId, fallback = null) {
  const key = storyId ? String(storyId) : ''
  if (!key) {
    return null
  }
  if (!liveMetrics[key]) {
    liveMetrics[key] = reactive({
      likes: normaliseMetricValue(fallback?.likes ?? 0),
      saves: normaliseMetricValue(fallback?.saves ?? 0),
      comments: normaliseMetricValue(fallback?.comments ?? 0),
      isLiked: Boolean(fallback?.isLiked),
      isSaved: Boolean(fallback?.isSaved),
    })
  }
  return liveMetrics[key]
}

function updateMetrics(storyId, patch = {}, fallback = null) {
  const metrics = ensureMetrics(storyId, fallback)
  if (!metrics) {
    return
  }
  if (Object.prototype.hasOwnProperty.call(patch, 'likes')) {
    metrics.likes = normaliseMetricValue(patch.likes)
  }
  if (Object.prototype.hasOwnProperty.call(patch, 'saves')) {
    metrics.saves = normaliseMetricValue(patch.saves)
  }
  if (Object.prototype.hasOwnProperty.call(patch, 'comments')) {
    metrics.comments = normaliseMetricValue(patch.comments)
  }
  if (Object.prototype.hasOwnProperty.call(patch, 'isLiked')) {
    metrics.isLiked = Boolean(patch.isLiked)
  }
  if (Object.prototype.hasOwnProperty.call(patch, 'isSaved')) {
    metrics.isSaved = Boolean(patch.isSaved)
  }
}

function updatePostState(storyId, patch = {}) {
  if (!storyId) {
    return
  }

  const key = String(storyId)
  const normalised = { ...patch }

  if (Object.prototype.hasOwnProperty.call(normalised, 'likes')) {
    normalised.likes = Number(normalised.likes) || 0
  }
  if (Object.prototype.hasOwnProperty.call(normalised, 'saves')) {
    normalised.saves = Number(normalised.saves) || 0
  }
  if (Object.prototype.hasOwnProperty.call(normalised, 'comments')) {
    normalised.comments = Number(normalised.comments) || 0
  }
  if (Object.prototype.hasOwnProperty.call(normalised, 'isLiked')) {
    normalised.isLiked = Boolean(normalised.isLiked)
  }
  if (Object.prototype.hasOwnProperty.call(normalised, 'isSaved')) {
    normalised.isSaved = Boolean(normalised.isSaved)
  }

  if (activePost.value && String(activePost.value.id) === key) {
    Object.assign(activePost.value, normalised)
    ensureMediaState(activePost.value)
  }

  updateMetrics(storyId, normalised, activePost.value)

  if (Array.isArray(serverPosts.value) && serverPosts.value.length) {
    serverPosts.value = serverPosts.value.map((item) => {
      if (String(item.id) === key) {
        const merged = { ...item, ...normalised }
        updateMetrics(storyId, merged, merged)
        ensureMediaState(merged)
        return merged
      }
      return item
    })
  }
}

function normaliseComment(raw) {
  if (!raw) {
    return null
  }

  const authorName = raw.authorName ?? raw.fullName ?? raw.username ?? 'Traveler'
  const ratingValue =
    raw.rating === null || raw.rating === undefined || raw.rating === ''
      ? null
      : Number(raw.rating)

  return {
    id: raw.id !== undefined ? Number(raw.id) : raw.commentId !== undefined ? Number(raw.commentId) : null,
    storyId:
      raw.storyId !== undefined
        ? Number(raw.storyId)
        : raw.story_id !== undefined
        ? Number(raw.story_id)
        : commentsState.storyId,
    travelerId:
      raw.travelerId !== undefined
        ? Number(raw.travelerId)
        : raw.traveler_id !== undefined
        ? Number(raw.traveler_id)
        : raw.authorId !== undefined
        ? Number(raw.authorId)
        : null,
    authorName,
    authorUsername: raw.authorUsername ?? raw.username ?? '',
    authorInitials: raw.authorInitials ?? computeInitials(authorName),
    content: String(raw.content ?? ''),
    rating: ratingValue,
    createdAt: raw.createdAt ?? raw.created_at ?? '',
    createdAtLabel: raw.createdAtLabel ?? raw.created_at_label ?? raw.createdAt ?? raw.created_at ?? '',
    updatedAt: raw.updatedAt ?? raw.updated_at ?? '',
  }
}

async function toggleReaction(kind, post) {
  const storyId = resolveStoryId(post)
  if (!storyId) {
    message.error('Unable to identify this story.')
    return
  }

  if (!currentUserInfo.value.id) {
    message.error('Please sign in to interact with stories.')
    return
  }

  const action = kind === 'save' ? 'toggle-save' : 'toggle-like'
  const isSave = action === 'toggle-save'
  const stateKey = isSave ? 'isSaved' : 'isLiked'
  const countKey = isSave ? 'saves' : 'likes'

  const previousState = {
    likes: resolveMetricCount(post, 'likes'),
    saves: resolveMetricCount(post, 'saves'),
    isLiked: resolveMetricFlag(post, 'isLiked'),
    isSaved: resolveMetricFlag(post, 'isSaved'),
  }

  const optimisticPatch = isSave
    ? {
        isSaved: !previousState.isSaved,
        saves: adjustMetricCount(previousState.saves, previousState.isSaved ? -1 : 1),
      }
    : {
        isLiked: !previousState.isLiked,
        likes: adjustMetricCount(previousState.likes, previousState.isLiked ? -1 : 1),
      }

  setMetricValues(post, optimisticPatch)
  Object.assign(post, optimisticPatch)

  try {
    const response = await fetch(COMMUNITY_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action,
        storyId,
        travelerId: currentUserInfo.value.id,
      }),
    })

    const payload = await safeJson(response)
    if (!response.ok || !payload?.ok) {
      throw new Error(payload?.message ?? 'Failed to update story.')
    }

    const responsePatch = isSave
      ? {
          isSaved: typeof payload.saved === 'boolean' ? payload.saved : optimisticPatch.isSaved,
          saves: normaliseMetricValue(
            payload.saves !== undefined ? payload.saves : optimisticPatch.saves
          ),
        }
      : {
          isLiked: typeof payload.liked === 'boolean' ? payload.liked : optimisticPatch.isLiked,
          likes: normaliseMetricValue(
            payload.likes !== undefined ? payload.likes : optimisticPatch.likes
          ),
        }

    setMetricValues(post, responsePatch)
    Object.assign(post, responsePatch)
    updatePostState(storyId, responsePatch)

    const currentFlags = {
      isSaved: resolveMetricFlag(post, 'isSaved'),
      isLiked: resolveMetricFlag(post, 'isLiked'),
    }
    emit('post-updated', {
      storyId,
      post,
      patch: responsePatch,
      flags: currentFlags,
    })

    if (isSave && !currentFlags.isSaved) {
      emit('post-removed', { storyId, post, reason: 'unsaved' })
      if (activePost.value && String(activePost.value.id) === String(storyId)) {
        closePostDetail()
      }
    }
    if (!isSave && !currentFlags.isLiked) {
      emit('post-removed', { storyId, post, reason: 'unliked' })
      if (activePost.value && String(activePost.value.id) === String(storyId)) {
        closePostDetail()
      }
    }
  } catch (error) {
    console.error('Failed to toggle reaction', error)
    const revertPatch = {
      [stateKey]: previousState[stateKey],
      [countKey]: previousState[countKey],
    }
    setMetricValues(post, revertPatch)
    Object.assign(post, revertPatch)
    updatePostState(storyId, revertPatch)
    emit('post-updated', {
      storyId,
      post,
      patch: revertPatch,
      flags: {
        isSaved: previousState.isSaved,
        isLiked: previousState.isLiked,
      },
    })
    message.error(error instanceof Error ? error.message : 'Failed to update story.')
  }
}

function focusCommentComposer() {
  nextTick(() => {
    const input = commentInputRef.value
    if (input?.focus) {
      input.focus()
    } else if (input?.$refs?.textarea && typeof input.$refs.textarea.focus === 'function') {
      input.$refs.textarea.focus()
    }
  })
}

function resetCommentComposer() {
  commentsState.newContent = ''
  commentsState.newRating = null
}

async function submitComment() {
  if (commentsState.submitting) {
    return
  }

  if (!currentUserInfo.value.id) {
    message.error('Please sign in to post a comment.')
    return
  }

  const storyId = commentsState.storyId ?? resolveStoryId(activePost.value)
  if (!storyId) {
    message.error('Select a story before adding a comment.')
    return
  }

  const content = (commentsState.newContent || '').trim()
  if (!content) {
    message.error('Share your thoughts before posting.')
    focusCommentComposer()
    return
  }

  commentsState.submitting = true

  try {
    const requestBody = {
      action: 'add-comment',
      storyId,
      travelerId: currentUserInfo.value.id,
      content,
    }

    if (commentsState.newRating && Number.isFinite(Number(commentsState.newRating))) {
      requestBody.rating = Number(commentsState.newRating)
    }

    const response = await fetch(COMMUNITY_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(requestBody),
    })

    const payload = await safeJson(response)
    if (!response.ok || !payload?.ok) {
      throw new Error(payload?.message ?? 'Failed to add comment.')
    }

    const created = payload.comment ? normaliseComment(payload.comment) : null
    if (created) {
      commentsState.items = [created, ...commentsState.items]
    }

    const total = payload.commentCount ?? commentsState.items.length
    updatePostState(storyId, { comments: total })
    commentsState.hasLoaded = true
    resetCommentComposer()
    message.success('Comment posted.')
  } catch (error) {
    console.error('Failed to submit comment', error)
    message.error(error instanceof Error ? error.message : 'Failed to add comment.')
  } finally {
    commentsState.submitting = false
  }
}

async function loadCommentsForPost(storyId) {
  if (!storyId) {
    return
  }

  commentsState.loading = true
  commentsState.error = ''
  commentsState.storyId = storyId

  try {
    const params = new URLSearchParams({
      view: 'comments',
      storyId: String(storyId),
    })

    const response = await fetch(`${COMMUNITY_ENDPOINT}?${params.toString()}`)
    const payload = await safeJson(response)

    if (!response.ok || !payload?.ok) {
      throw new Error(payload?.message ?? 'Failed to load comments.')
    }

    const rows = Array.isArray(payload.comments) ? payload.comments : []
    commentsState.items = rows
      .map((item) => normaliseComment(item))
      .filter((item) => item !== null)

    const total = payload.total ?? commentsState.items.length
    updatePostState(storyId, { comments: total })
    commentsState.hasLoaded = true
  } catch (error) {
    console.error('Failed to load comments', error)
    commentsState.error = error instanceof Error ? error.message : 'Failed to load comments.'
  } finally {
    commentsState.loading = false
  }
}

async function deleteComment(comment) {
  if (!comment) {
    return
  }

  if (!currentUserInfo.value.id) {
    message.error('Please sign in to manage your comments.')
    return
  }

  const commentId = comment.id ?? comment.commentId ?? null
  if (!commentId) {
    message.error('Unable to identify this comment.')
    return
  }

  if (Number(comment.travelerId) !== Number(currentUserInfo.value.id)) {
    message.error('You can only delete your own comments.')
    return
  }

  const storyId = comment.storyId ?? commentsState.storyId ?? resolveStoryId(activePost.value)
  if (!storyId) {
    message.error('Unable to determine the related story.')
    return
  }

  try {
    const params = new URLSearchParams({
      action: 'comment',
      commentId: String(commentId),
      travelerId: String(currentUserInfo.value.id),
    })

    const response = await fetch(`${COMMUNITY_ENDPOINT}?${params.toString()}`, {
      method: 'DELETE',
    })

    const payload = await safeJson(response)
    if (!response.ok || !payload?.ok) {
      throw new Error(payload?.message ?? 'Failed to delete comment.')
    }

    commentsState.items = commentsState.items.filter((item) => String(item.id) !== String(commentId))

    const total = payload.commentCount ?? commentsState.items.length
    updatePostState(storyId, { comments: total })
    message.success('Comment removed.')
  } catch (error) {
    console.error('Failed to delete comment', error)
    message.error(error instanceof Error ? error.message : 'Failed to delete comment.')
  }
}

function openPostDetail(post) {
  ensureMediaState(post)
  activePost.value = post
  expandedPostMuted.value = true
  expandedPostAutoplay.value = true
  postModalVisible.value = true
  resetCommentComposer()
  commentsState.items = []
  commentsState.error = ''
  commentsState.loading = false
  commentsState.hasLoaded = false
  commentsState.storyId = resolveStoryId(post)
  commentsState.submitting = false

  if (commentsState.storyId) {
    loadCommentsForPost(commentsState.storyId)
  }
}

function closePostDetail() {
  postModalVisible.value = false
  activePost.value = null
  expandedPostMuted.value = true
  expandedPostAutoplay.value = false
  resetCommentComposer()
  commentsState.storyId = null
  commentsState.items = []
  commentsState.error = ''
  commentsState.loading = false
  commentsState.hasLoaded = false
  commentsState.submitting = false
}

function emitContact(post) {
  emit('contact', post)
}

function isDeletingPost(id) {
  const key = String(id)
  return deletingPostIds.value.has(key)
}

function startCreateStory() {
  editingStoryId.value = null
  resetStoryForm()
  storyModalVisible.value = true
}

function startEditPost(post) {
  if (!post) {
    return
  }

  storyForm.mediaItems.forEach(releaseStoryMediaPreview)
  editingStoryId.value = post.id ?? null
  storyModalVisible.value = true
  const normalizedCategories = Array.isArray(post.categories)
    ? [...post.categories]
    : ensureStringArray(post.categories)

  const existingMediaItems = Array.isArray(post.media)
    ? post.media
        .map((item, index) => ({
          id: item.id ?? `${post.id ?? 'story'}-media-${index}`,
          type: item.type === 'video' ? 'video' : 'image',
          url: item.url,
          name: item.name ?? item.fileName ?? resolveMediaNameFromSource(item.url),
          source: 'existing',
        }))
        .filter((item) => Boolean(item.url))
    : []

  Object.assign(storyForm, {
    caption: post.caption ?? '',
    mediaItems: [],
    existingMedia: existingMediaItems,
    location: post.location ?? '',
    tags: ensureStringArray(post.tags).join(', '),
    categories: normalizedCategories,
  })

  const mediaState = ensureMediaState(post)
  if (mediaState) {
    mediaState.index = 0
  }
  closeStoryMediaPreview()
  restoreMediaValidation()
}

function handleEditPost(post) {
  if (!post) return
  startEditPost(post)
}

function handleEditPostFromDetail(post) {
  if (!post) return
  startEditPost(post)
  postModalVisible.value = false
}

function handleStoryCancel() {
  storyModalVisible.value = false
  resetStoryForm()
  editingStoryId.value = null
}

async function handleStorySubmit() {
  if (!storyFormRef.value) {
    return
  }

  try {
    await storyFormRef.value.validate()
  } catch {
    return
  }

  if (!currentUserInfo.value.id) {
    message.error('You need to be signed in to share a story.')
    return
  }

  storySubmitting.value = true
  try {
    const rawTags = ensureStringArray(storyForm.tags)
    const rawCategories = storyForm.categories.length ? storyForm.categories : ['community']

    if (isEditingStory.value) {
      const updatePayload = {
        id: editingStoryId.value,
        travelerId: currentUserInfo.value.id,
        caption: storyForm.caption.trim(),
        location: storyForm.location.trim(),
        tags: rawTags,
        categories: rawCategories,
      }

      const response = await fetch(COMMUNITY_ENDPOINT, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(updatePayload),
      })

      if (!response.ok) {
        const errorPayload = await safeJson(response)
        throw new Error(errorPayload?.error ?? 'Failed to update story.')
      }

      const payload = await response.json()
      const updatedRaw = payload?.post ?? null
      if (!updatedRaw) {
        throw new Error('Unexpected response from server.')
      }

      const updatedPost = normalisePost({ ...updatedRaw }, 0)
      serverPosts.value = serverPosts.value.map((post) =>
        String(post.id) === String(updatedPost.id) ? updatedPost : post
      )

      if (activePost.value && String(activePost.value.id) === String(updatedPost.id)) {
        activePost.value = updatedPost
      }

      message.success('Story updated successfully.')
    } else {
      const uploadItems = storyForm.mediaItems.filter((item) => item.file instanceof File)
      if (!uploadItems.length) {
        message.error('Please attach at least one image or video for your story.')
        return
      }
      const formData = new FormData()
      formData.append('travelerId', String(currentUserInfo.value.id))
      formData.append('caption', storyForm.caption.trim())
      formData.append('location', storyForm.location.trim())
      formData.append('tags', JSON.stringify(rawTags))
      formData.append('categories', JSON.stringify(rawCategories))
      uploadItems.forEach((item, index) => {
        const filename = item.name || `media-${index + 1}`
        formData.append('media[]', item.file, filename)
      })

      const response = await fetch(COMMUNITY_ENDPOINT, {
        method: 'POST',
        body: formData,
      })

      if (!response.ok) {
        const errorPayload = await safeJson(response)
        throw new Error(errorPayload?.error ?? 'Failed to publish story.')
      }

      const payload = await response.json()
      const newPostRaw = payload?.post ?? null
      if (!newPostRaw) {
        throw new Error('Unexpected response from server.')
      }

      const newPost = normalisePost({ ...newPostRaw }, 0)
      serverPosts.value = [newPost, ...serverPosts.value]

      if (activeCategory.value !== 'all' && !newPost.categories.includes(activeCategory.value)) {
        activeCategory.value = 'all'
      }

      message.success('Story shared with the community.')
    }

    storyModalVisible.value = false
    resetStoryForm()
    editingStoryId.value = null
  } catch (error) {
    console.error('Failed to submit story', error)
    message.error(error instanceof Error ? error.message : 'Failed to publish story.')
  } finally {
    storySubmitting.value = false
  }
}

async function handleDeletePost(post) {
  if (!post) {
    return
  }

  if (!currentUserInfo.value.id) {
    message.error('You need to be signed in to manage your story.')
    return
  }

  const postId = post.id ?? post.ID ?? null
  if (!postId) {
    message.error('Unable to identify this story.')
    return
  }

  const key = String(postId)
  if (deletingPostIds.value.has(key)) {
    return
  }

  const next = new Set(deletingPostIds.value)
  next.add(key)
  deletingPostIds.value = next

  try {
    const response = await fetch(COMMUNITY_ENDPOINT, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: postId,
        travelerId: currentUserInfo.value.id,
      }),
    })

    if (!response.ok) {
      const errorPayload = await safeJson(response)
      throw new Error(errorPayload?.error ?? 'Failed to delete story.')
    }

    serverPosts.value = serverPosts.value.filter((item) => String(item.id) !== key)
    if (activePost.value && String(activePost.value.id) === key) {
      postModalVisible.value = false
      activePost.value = null
    }

    message.success('Story deleted.')
  } catch (error) {
    console.error('Failed to delete story', error)
    message.error(error instanceof Error ? error.message : 'Failed to delete story.')
  } finally {
    const nextSet = new Set(deletingPostIds.value)
    nextSet.delete(key)
    deletingPostIds.value = nextSet
  }
}

function createStoryMediaId() {
  return crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2, 10)}`
}

function releaseStoryMediaPreview(item) {
  if (item?.source === 'new' && item.previewUrl) {
    URL.revokeObjectURL(item.previewUrl)
  }
}

function resetStoryForm() {
  storyForm.mediaItems.forEach(releaseStoryMediaPreview)
  closeStoryMediaPreview()
  Object.assign(storyForm, createStoryFormState())
  restoreMediaValidation()
}

function handleStoryFileSelect({ file }) {
  const rawFile = file?.file ?? file
  if (!rawFile) {
    return false
  }

  if (storyForm.mediaItems.length >= STORY_MAX_MEDIA) {
    message.error(`You can upload up to ${STORY_MAX_MEDIA} files per story.`)
    return false
  }

  if (rawFile.size > STORY_MAX_MEDIA_SIZE_BYTES) {
    message.error('Each file must be under 25MB.')
    return false
  }

  const mime = rawFile.type || ''
  const isImage = mime.startsWith('image/')
  const isVideo = mime.startsWith('video/')

  if (!isImage && !isVideo) {
    message.error('Please select an image or video file.')
    return false
  }

  const previewUrl = URL.createObjectURL(rawFile)

  storyForm.mediaItems.push({
    id: createStoryMediaId(),
    file: rawFile,
    name: rawFile.name ?? 'media',
    type: isVideo ? 'video' : 'image',
    previewUrl,
    url: previewUrl,
    size: rawFile.size ?? 0,
    source: 'new',
  })

  restoreMediaValidation()
  return false
}

function removeStoryMedia(id) {
  const index = storyForm.mediaItems.findIndex((item) => item.id === id)
  if (index === -1) {
    return
  }

  const [removed] = storyForm.mediaItems.splice(index, 1)
  releaseStoryMediaPreview(removed)
  if (
    storyMediaPreviewState.visible &&
    removed &&
    storyMediaPreviewState.url &&
    storyMediaPreviewState.url === (removed.url || removed.previewUrl)
  ) {
    closeStoryMediaPreview()
  }
  restoreMediaValidation()
}

function openStoryMediaPreview(item) {
  const source = item?.source === 'new' ? item.previewUrl : item?.url
  if (!source) {
    return
  }
  storyMediaPreviewState.url = source
  storyMediaPreviewState.type = item?.type === 'video' ? 'video' : 'image'
  storyMediaPreviewState.name = item?.name || resolveMediaNameFromSource(source)
  storyMediaPreviewState.visible = true
}

function closeStoryMediaPreview() {
  storyMediaPreviewState.visible = false
  storyMediaPreviewState.url = ''
  storyMediaPreviewState.type = 'image'
  storyMediaPreviewState.name = ''
}

function handleDetailVideoReady(event) {
  const video = event?.currentTarget
  if (!video) {
    return
  }
  expandedPostMuted.value = video.muted
  if (expandedPostAutoplay.value && video.paused) {
    const playPromise = video.play()
    if (playPromise instanceof Promise) {
      playPromise.catch(() => {
        expandedPostAutoplay.value = false
      })
    }
  }
}

function handleDetailVolumeChange(event) {
  const video = event?.currentTarget
  if (!video) {
    return
  }
  expandedPostMuted.value = video.muted
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

function resolveAssetUrl(path) {
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
function computeInitials(text) {
  return text
    .split(/\s+/)
    .map((word) => word[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()
}

function resolveMediaNameFromSource(source = '') {
  if (!source) {
    return ''
  }
  const base = typeof window !== 'undefined' ? window.location.origin : 'http://localhost'
  try {
    const parsed = new URL(source, base)
    const pathname = parsed.pathname.split('/').filter(Boolean)
    const candidate = pathname[pathname.length - 1] || source
    return candidate.split('?')[0].split('#')[0]
  } catch {
    const segments = source.split(/[\\/]/)
    return segments[segments.length - 1] || source
  }
}

function formatLabel(slug) {
  return String(slug)
    .replace(/[-_]/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase())
}

function formatMetric(value) {
  const number = Number(value) || 0
  if (number < 1000) {
    return number
  }
  const scaled = (number / 1000).toFixed(number >= 10000 ? 0 : 1)
  return `${Number(scaled)}k`
}

function validateMediaFile() {
  if (isEditingStory.value) {
    return true
  }

  if (!storyForm.mediaItems.length) {
    return new Error('Please upload at least one image or video.')
  }

  return true
}



function restoreMediaValidation() {
  const form = storyFormRef.value
  if (form?.restoreValidation) {
    form.restoreValidation(['mediaItems'])
  }
}

async function safeJson(response) {
  try {
    return await response.json()
  } catch (error) {
    return null
  }
}

onBeforeUnmount(() => {
  storyForm.mediaItems.forEach(releaseStoryMediaPreview)
  closeStoryMediaPreview()
})
</script>

<style scoped>
.social-feed {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.category-bar {
  padding: 0 4px;
  width: 100%;
  display: flex;
  justify-content: center;
}

.category-tabs {
  width: 100%;
  max-width: 960px;
  min-width: 240px;
  margin: 0 auto;
  display: block;
}

:deep(.category-tabs .n-tabs-nav) {
  width: 100%;
}

:deep(.category-tabs .n-tabs-nav-scroll) {
  width: 100%;
}

:deep(.category-tabs .n-tabs-nav-scroll-content) {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: 8px;
  align-items: center;
  width: 100%;
}

:deep(.category-tabs .n-tabs-tab) {
  width: 100%;
  border-radius: 999px;
  padding: 6px 14px;
  font-weight: 500;
  justify-content: center;
}

:deep(.category-tabs .n-tabs-tab__label) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.feed-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  grid-auto-rows: 1fr;
  gap: 18px;
  align-items: stretch;
}

@media (min-width: 1200px) {
  .feed-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

.post-card {
  width: 100%;
  display: flex;
}

.post-card :deep(.n-card) {
  height: 100%;
  display: flex;
  flex-direction: column;
}

.post-media {
  position: relative;
  padding-top: 72%;
  overflow: hidden;
  border-radius: 16px;
  background: #f3f4f6;
  cursor: pointer;
}

.post-media img,
.post-media video {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.post-media-controls {
  position: absolute;
  top: 50%;
  left: 0;
  right: 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0 6px;
  transform: translateY(-50%);
  pointer-events: none;
  z-index: 2;
}

.post-media-nav {
  pointer-events: auto;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: none;
  background: rgba(12, 30, 21, 0.38);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.2s ease;
}

.post-media-nav:hover {
  background: rgba(12, 30, 21, 0.6);
}

.post-media-counter {
  position: absolute;
  bottom: 10px;
  right: 12px;
  background: rgba(12, 30, 21, 0.35);
  color: #fff;
  font-size: 0.72rem;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 999px;
  backdrop-filter: blur(6px);
  z-index: 2;
  pointer-events: none;
}

.post-body {
  display: flex;
  flex-direction: column;
  gap: 10px;
  flex: 1;
}

.post-footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.post-footer-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.story-modal :deep(.n-card) {
  border-radius: 18px;
  box-shadow: 0 28px 46px rgba(15, 23, 42, 0.16);
  border: 1px solid rgba(15, 59, 39, 0.08);
  overflow: visible;
}

.story-modal :deep(.n-card-header) {
  padding-bottom: 4px;
}

.story-modal :deep(.n-card-header__title) {
  font-weight: 600;
  font-size: 1.1rem;
}

.story-modal :deep(.n-card__content) {
  padding: 10px 0 0;
}

.story-modal :deep(.n-card__action) {
  padding: 12px 18px 18px;
}

.story-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

@media (min-width: 900px) {
  .story-card {
    flex-direction: row;
    align-items: stretch;
    gap: 32px;
  }
}

.story-card__header {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 12px 16px;
  border-radius: 14px;
  border: 1px solid rgba(28, 111, 79, 0.14);
  background: linear-gradient(145deg, rgba(28, 111, 79, 0.09), rgba(28, 111, 79, 0.03));
  width: 100%;
}

@media (min-width: 720px) {
  .story-card__header {
    padding: 14px 18px;
  }
}

@media (min-width: 900px) {
  .story-card__header {
    flex: 0 0 220px;
    position: sticky;
    top: 18px;
  }
}

.story-card__badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border-radius: 999px;
  background: rgba(28, 111, 79, 0.12);
  color: #1d5239;
  font-weight: 600;
  font-size: 0.78rem;
  padding: 5px 12px;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

.story-card__heading {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.story-card__heading h3 {
  margin: 0;
  font-size: 0.95rem;
  color: #153f2d;
}

.story-card__heading p {
  margin: 0;
  font-size: 0.84rem;
  color: #3e5d4d;
  line-height: 1.5;
}

.story-card__tips {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

@media (min-width: 720px) {
  .story-card__tips {
    flex-direction: row;
    flex-wrap: wrap;
    gap: 10px 16px;
  }
}

.story-card__tips li {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 0.82rem;
  color: #2a4f3c;
}

.story-card__tips li :deep(.n-icon) {
  color: #1c6f4f;
}

.story-card__form {
  background: #ffffff;
  border-radius: 16px;
  border: 1px solid rgba(15, 59, 39, 0.08);
  box-shadow: 0 14px 28px rgba(15, 59, 39, 0.08);
  padding: 16px 18px;
  display: flex;
  flex-direction: column;
  gap: 14px;
  width: 100%;
}

@media (max-width: 719px) {
  .story-card__form {
    padding: 16px;
  }
}

@media (min-width: 900px) {
  .story-card__form {
    flex: 1;
    padding: 20px 28px;
  }
}

.story-card__form :deep(.n-form-item) {
  margin-bottom: 0;
}

.story-card__form :deep(.n-form-item + .n-form-item) {
  margin-top: 6px;
}

.story-card__form :deep(.n-form-item .n-form-item-label) {
  font-weight: 600;
  color: #1b4a34;
}

.story-card__form :deep(.n-input),
.story-card__form :deep(.n-select),
.story-card__form :deep(.n-upload) {
  border-radius: 12px;
  background: #f9fcf9;
}

.story-card__form :deep(.n-input.n-input--textarea .n-input__textarea-el) {
  padding: 10px 14px;
  line-height: 1.5;
  text-align: left;
  text-indent: 0;
  font-size: 0.92rem;
}

.story-card__form :deep(.n-upload-dragger) {
  border-radius: 12px;
  border: 1px dashed rgba(28, 111, 79, 0.22);
  background: #fbfdfb;
  padding: 16px;
}

.story-card__form :deep(.n-upload-dragger:hover) {
  border-color: rgba(28, 111, 79, 0.4);
  background: #f4faf6;
}

.story-card__form :deep(.n-input.n-input--textarea .n-input__textarea-el::placeholder) {
  color: #6a8577;
  opacity: 0.8;
}

.story-upload-card {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.story-upload-card__dragger {
  border-radius: 16px;
  border: 1px dashed rgba(28, 111, 79, 0.35);
  background: rgba(28, 111, 79, 0.05);
  transition: border-color 0.2s ease, background 0.2s ease;
}

.story-upload-card__dragger:hover {
  border-color: rgba(28, 111, 79, 0.55);
  background: rgba(28, 111, 79, 0.08);
}

.story-upload-card__icon {
  width: 34px;
  height: 34px;
  border-radius: 12px;
  background: rgba(28, 111, 79, 0.14);
  color: #1c6f4f;
  display: flex;
  align-items: center;
  justify-content: center;
}

.story-upload-card__hint {
  font-size: 0.78rem;
  color: #4e6a5a;
}

.story-media-gallery {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 12px;
}

.story-media-card {
  display: flex;
  flex-direction: column;
  gap: 8px;
  border-radius: 14px;
  border: 1px solid rgba(28, 111, 79, 0.12);
  background: #f8fbfa;
  padding: 10px;
  position: relative;
}

.story-media-card--existing {
  background: #f3f5f4;
  border-color: rgba(28, 111, 79, 0.08);
}

.story-media-card__thumb {
  position: relative;
  border-radius: 10px;
  overflow: hidden;
  background: rgba(28, 111, 79, 0.08);
  aspect-ratio: 1 / 1;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  border: none;
  padding: 0;
}

.story-media-card__thumb img,
.story-media-card__thumb video {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.story-media-card__thumb:focus-visible {
  outline: 2px solid rgba(28, 111, 79, 0.6);
  outline-offset: 2px;
}

.story-media-card__remove {
  position: absolute;
  top: 6px;
  right: 6px;
  background: rgba(220, 53, 69, 0.12);
  backdrop-filter: blur(6px);
}

.story-media-card__name {
  font-size: 0.8rem;
  font-weight: 600;
  color: #2d3a34;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.story-media-preview {
  display: flex;
  justify-content: center;
  align-items: center;
  max-height: 70vh;
  overflow: hidden;
}

.story-media-preview img,
.story-media-preview video {
  max-width: 100%;
  max-height: 70vh;
  border-radius: 12px;
  object-fit: contain;
}
.story-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.comment-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.comment-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 8px 0;
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
}

.comment-item:last-child {
  border-bottom: none;
}

.scrollable-comments {
  max-height: 260px;
  overflow-y: auto;
  padding-right: 6px;
}

.detail-content {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

@media (min-width: 720px) {
  .detail-content {
    flex-direction: row;
  }
}

.detail-media {
  flex: 1 1 55%;
  max-height: 520px;
  max-width: 560px;
  border-radius: 18px;
  overflow: hidden;
  background: #000;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
}

.detail-media img,
.detail-media video {
  display: block;
  max-width: 100%;
  max-height: 100%;
  width: 100%;
  height: auto;
  object-fit: contain;
}

.detail-media-controls {
  position: absolute;
  top: 50%;
  left: 0;
  right: 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0 10px;
  transform: translateY(-50%);
  pointer-events: none;
  z-index: 2;
}

.detail-media-nav {
  pointer-events: auto;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  border: none;
  background: rgba(0, 0, 0, 0.4);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  cursor: pointer;
  transition: background 0.2s ease;
}

.detail-media-nav:hover {
  background: rgba(0, 0, 0, 0.6);
}

.detail-media-counter {
  position: absolute;
  bottom: 14px;
  right: 18px;
  background: rgba(0, 0, 0, 0.45);
  color: #fff;
  font-weight: 600;
  padding: 6px 14px;
  border-radius: 999px;
  font-size: 0.82rem;
  backdrop-filter: blur(6px);
  z-index: 2;
  pointer-events: none;
}

.detail-body {
  flex: 1 1 45%;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.detail-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.detail-header {
  display: flex;
  align-items: center;
  gap: 12px;
}

.detail-meta {
  flex: 1;
  display: flex;
  flex-direction: column;
}
</style>

