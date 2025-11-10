<template>
  <div class="marketplace-feed">
    <n-page-header
      class="feed-header"
      title="Marketplace"
      subtitle="Discover sustainable businesses and eco-friendly services."
    >
      <template #extra>
        <n-input
          v-model:value="searchQuery"
          round
          clearable
          placeholder="Search businesses..."
          style="min-width: 280px;"
          @keyup.enter="handleSearch"
        >
          <template #suffix>
            <n-icon size="18">
              <i class="ri-search-2-line" />
            </n-icon>
          </template>
        </n-input>
      </template>
    </n-page-header>

    <div v-if="categoryOptions.length" class="category-bar">
      <n-tabs v-model:value="activeCategory" type="segment" size="small" class="category-tabs">
        <n-tab-pane
          v-for="category in categoryOptions"
          :key="category.value"
          :name="category.value"
          :tab="category.label"
        />
      </n-tabs>
    </div>

    <n-alert v-if="listingsError" type="error" closable class="feed-alert" @close="listingsError = ''">
      {{ listingsError }}
    </n-alert>

    <n-spin :show="listingsLoading">
      <div v-if="filteredListings.length" class="feed-grid">
        <article v-for="listing in filteredListings" :key="listing.id" class="listing-card">
          <n-card size="small" :segmented="{ content: true, footer: 'soft' }">
            <template #cover>
              <div class="listing-media" @click="openListingDetail(listing)">
                <div v-if="listing.images && listing.images.length > 0" class="media-carousel-container">
                  <n-carousel
                    :show-dots="listing.images.length > 1"
                    :show-arrow="listing.images.length > 1"
                    draggable
                    @click.stop
                  >
                    <img
                      v-for="img in listing.images"
                      :key="img.id"
                      :src="img.url"
                      :alt="listing.businessName"
                      class="carousel-image"
                      loading="lazy"
                      @click.stop="openListingDetail(listing)"
                    />
                  </n-carousel>
                  <div v-if="listing.images.length > 1" class="image-count-badge">
                    <n-icon size="14">
                      <i class="ri-image-line" />
                    </n-icon>
                    {{ listing.images.length }}
                  </div>
                </div>
                <div v-else-if="listing.coverImage" @click="openListingDetail(listing)">
                  <img
                    :src="listing.coverImage"
                    :alt="listing.businessName"
                    class="carousel-image"
                    loading="lazy"
                  />
                </div>
                <div v-else class="listing-placeholder">
                  <n-icon size="48">
                    <i class="ri-store-3-line" />
                  </n-icon>
                </div>
              </div>
            </template>

            <div class="listing-body">
              <div class="listing-header">
                <div style="font-size: 1.1rem; font-weight: 600;">
                  {{ listing.businessName }}
                </div>
                <n-button
                  text
                  size="small"
                  :type="listing.isSaved ? 'primary' : 'default'"
                  @click.stop="toggleSave(listing)"
                >
                  <template #icon>
                    <n-icon>
                      <i :class="listing.isSaved ? 'ri-bookmark-fill' : 'ri-bookmark-line'" />
                    </n-icon>
                  </template>
                </n-button>
              </div>
              <n-tag v-if="listing.category" type="success" size="small" bordered style="margin-top: 8px;">
                {{ listing.category }}
              </n-tag>
              <n-text depth="3" style="display: block; margin-top: 8px;">
                {{ listing.location }}
              </n-text>
              <n-text v-if="listing.description" depth="3" style="display: block; margin-top: 8px; line-height: 1.5;">
                {{ truncateText(listing.description, 120) }}
              </n-text>
              <div v-if="listing.priceRange" style="margin-top: 8px;">
                <n-text depth="3">Price: </n-text>
                <n-text strong>{{ listing.priceRange }}</n-text>
              </div>
              <div v-if="listing.reviewSummary && listing.reviewSummary.totalReviews > 0" style="margin-top: 8px;">
                <n-space align="center" size="small">
                  <n-rate :value="listing.reviewSummary.averageRating" size="small" readonly />
                  <n-text depth="3" style="font-size: 0.85rem;">
                    {{ listing.reviewSummary.averageRating }} ({{ listing.reviewSummary.totalReviews }})
                  </n-text>
                </n-space>
              </div>
            </div>

            <template #footer>
              <div class="listing-footer">
                <n-button text size="small" @click="openListingDetail(listing)">
                  View details
                </n-button>
                <n-button text size="small" type="warning" @click="openReviewDrawer(listing)">
                  <template #icon>
                    <n-icon><i class="ri-edit-line" /></n-icon>
                  </template>
                  Write Review
                </n-button>
                <n-button text size="small" type="primary" @click="handleContact(listing)">
                  Contact
                </n-button>
              </div>
            </template>
          </n-card>
        </article>
      </div>
      <n-empty v-else description="No businesses found. Try adjusting your search or filters." />
    </n-spin>

    <n-modal
      v-model:show="detailModalVisible"
      preset="card"
      :segmented="false"
      :style="{ maxWidth: '960px' }"
      @after-leave="closeListingDetail"
    >
      <template #header>
        <div v-if="selectedListing" class="detail-header">
          <div style="flex: 1;">
            <div style="font-size: 1.3rem; font-weight: 600;">
              {{ selectedListing.businessName }}
            </div>
            <n-text depth="3">{{ selectedListing.location }}</n-text>
          </div>
          <n-button
            text
            size="medium"
            :type="selectedListing.isSaved ? 'primary' : 'default'"
            @click="toggleSave(selectedListing)"
          >
            <template #icon>
              <n-icon>
                <i :class="selectedListing.isSaved ? 'ri-bookmark-fill' : 'ri-bookmark-line'" />
              </n-icon>
            </template>
          </n-button>
        </div>
      </template>

      <div v-if="selectedListing" class="detail-content">
        <div class="detail-media-section">
          <div v-if="selectedListing.images && selectedListing.images.length > 0" class="detail-carousel-wrapper">
            <n-carousel
              :show-dots="true"
              show-arrow
              draggable
              :autoplay="false"
            >
              <div
                v-for="(img, index) in selectedListing.images"
                :key="img.id"
                class="detail-carousel-slide"
                @click="openImageModal(selectedListing.images, index)"
              >
                <img :src="img.url" :alt="`${selectedListing.businessName} - Image ${index + 1}`" />
                <div class="zoom-hint">
                  <n-icon size="20">
                    <i class="ri-zoom-in-line" />
                  </n-icon>
                  <span>Click to zoom</span>
                </div>
              </div>
            </n-carousel>
            <div class="carousel-counter">
              {{ selectedListing.images.length }} photo{{ selectedListing.images.length > 1 ? 's' : '' }}
            </div>
          </div>
          <div v-else class="listing-placeholder">
            <n-icon size="64">
              <i class="ri-store-3-line" />
            </n-icon>
          </div>
        </div>
        <div class="detail-body">
          <n-space vertical size="medium">
            <div>
              <n-tag v-if="selectedListing.category" type="success" size="medium" bordered>
                {{ selectedListing.category }}
              </n-tag>
              <n-tag v-if="selectedListing.priceRange" size="medium" style="margin-left: 8px;">
                {{ selectedListing.priceRange }}
              </n-tag>
            </div>
            <div>
              <n-text strong>Description</n-text>
              <p style="margin-top: 8px; line-height: 1.6;">
                {{ selectedListing.description || 'No description available.' }}
              </p>
            </div>
            <div v-if="selectedListing.operator">
              <n-text strong>Operator</n-text>
              <p style="margin-top: 8px;">
                {{ selectedListing.operator.name }}
              </p>
            </div>
            <div v-if="selectedListing.reviewSummary && selectedListing.reviewSummary.totalReviews > 0">
              <n-text strong>Reviews</n-text>
              <n-space align="center" size="medium" style="margin-top: 8px;">
                <n-rate :value="selectedListing.reviewSummary.averageRating" size="medium" readonly />
                <n-text>
                  {{ selectedListing.reviewSummary.averageRating }} ({{ selectedListing.reviewSummary.totalReviews }} review{{ selectedListing.reviewSummary.totalReviews > 1 ? 's' : '' }})
                </n-text>
              </n-space>
            </div>
            <n-button type="warning" secondary block @click="openReviewDrawer(selectedListing)">
              <template #icon>
                <n-icon><i class="ri-chat-1-line" /></n-icon>
              </template>
              {{ selectedListing.reviewSummary && selectedListing.reviewSummary.totalReviews > 0 ? 'View & Write Reviews' : 'Be the first to review!' }}
            </n-button>
            <n-button type="primary" block @click="handleContact(selectedListing)">
              Contact business
            </n-button>
          </n-space>
        </div>
      </div>
    </n-modal>

    <n-modal
      v-model:show="imageModalVisible"
      preset="card"
      :segmented="false"
      :style="{ maxWidth: '50vw', maxHeight: '90vh' }"
      @after-leave="closeImageModal"
    >
      <template #header>
        <div class="image-modal-header">
          <span>Photo {{ currentImageIndex + 1 }} of {{ galleryImages.length }}</span>
          <n-button-group>
            <n-button size="small" :disabled="imageZoom <= 0.5" @click="zoomOut">
              <template #icon>
                <n-icon><i class="ri-zoom-out-line" /></n-icon>
              </template>
            </n-button>
            <n-button size="small" @click="resetZoom">
              {{ Math.round(imageZoom * 100) }}%
            </n-button>
            <n-button size="small" :disabled="imageZoom >= 3" @click="zoomIn">
              <template #icon>
                <n-icon><i class="ri-zoom-in-line" /></n-icon>
              </template>
            </n-button>
          </n-button-group>
        </div>
      </template>
      <div class="image-modal-content">
        <div class="image-container">
          <img
            v-if="currentImage"
            :src="currentImage.url"
            :alt="currentImage.caption || 'Business photo'"
            class="enlarged-image"
            :style="{ transform: `scale(${imageZoom})`, transformOrigin: 'center center' }"
          />
        </div>
        <div v-if="galleryImages.length > 1" class="image-nav">
          <n-button
            circle
            :disabled="currentImageIndex === 0"
            @click="previousImage"
          >
            <template #icon>
              <n-icon>
                <i class="ri-arrow-left-s-line" />
              </n-icon>
            </template>
          </n-button>
          <n-button
            circle
            :disabled="currentImageIndex === galleryImages.length - 1"
            @click="nextImage"
          >
            <template #icon>
              <n-icon>
                <i class="ri-arrow-right-s-line" />
              </n-icon>
            </template>
          </n-button>
        </div>
      </div>
    </n-modal>

    <n-drawer v-model:show="reviewDrawerVisible" :width="480" placement="right">
      <n-drawer-content :title="reviewingListing ? `Reviews - ${reviewingListing.businessName}` : 'Reviews'" closable>
        <n-space vertical size="large">
          <!-- Review Summary -->
          <div v-if="reviewingListing && reviewingListing.reviewSummary && reviewingListing.reviewSummary.totalReviews > 0">
            <n-space vertical size="small">
              <div style="display: flex; align-items: center; gap: 12px;">
                <div style="font-size: 2.5rem; font-weight: 700;">
                  {{ reviewingListing.reviewSummary.averageRating }}
                </div>
                <div style="flex: 1;">
                  <n-rate :value="reviewingListing.reviewSummary.averageRating" size="medium" readonly />
                  <n-text depth="3" style="display: block; margin-top: 4px;">
                    {{ reviewingListing.reviewSummary.totalReviews }} review{{ reviewingListing.reviewSummary.totalReviews > 1 ? 's' : '' }}
                  </n-text>
                </div>
              </div>
              
              <n-space vertical size="small" style="margin-top: 12px;">
                <div v-for="star in [5, 4, 3, 2, 1]" :key="star" style="display: flex; align-items: center; gap: 8px;">
                  <n-text depth="3" style="min-width: 20px;">{{ star }}</n-text>
                  <n-progress
                    type="line"
                    :percentage="reviewingListing.reviewSummary.distribution && reviewingListing.reviewSummary.distribution[star] !== undefined ? (reviewingListing.reviewSummary.distribution[star] / reviewingListing.reviewSummary.totalReviews) * 100 : 0"
                    :show-indicator="false"
                    style="flex: 1;"
                    :height="8"
                  />
                  <n-text depth="3" style="min-width: 30px; text-align: right;">
                    {{ reviewingListing.reviewSummary.distribution ? reviewingListing.reviewSummary.distribution[star] || 0 : 0 }}
                  </n-text>
                </div>
              </n-space>
            </n-space>
            <n-divider />
          </div>

          <!-- Write Review Form -->
          <div>
            <n-text strong style="font-size: 1.1rem; display: block; margin-bottom: 12px;">
              Write a review
            </n-text>
            <n-space vertical size="medium">
              <div>
                <n-text depth="3" style="display: block; margin-bottom: 8px;">Rating</n-text>
                <n-rate v-model:value="newReview.rating" size="large" clearable allow-half />
                <n-text v-if="newReview.rating" depth="3" style="font-size: 0.9rem; margin-top: 4px; display: block;">
                  {{ newReview.rating }} star{{ newReview.rating > 1 ? 's' : '' }}
                </n-text>
              </div>
              <div>
                <n-input
                  v-model:value="newReview.content"
                  type="textarea"
                  placeholder="Share your experience..."
                  :autosize="{ minRows: 4, maxRows: 8 }"
                  maxlength="1000"
                  show-count
                />
              </div>
              <n-button
                type="primary"
                :loading="submittingReview"
                :disabled="!newReview.content.trim()"
                @click="submitReview"
                block
              >
                Submit review
              </n-button>
            </n-space>
          </div>

          <n-divider />

          <!-- Reviews List -->
          <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
              <n-text strong style="font-size: 1.1rem;">
                Reviews
              </n-text>
              <n-button-group size="small">
                <n-button
                  :type="reviewSort === 'newest' ? 'primary' : 'default'"
                  @click="reviewSort = 'newest'"
                >
                  Newest
                </n-button>
                <n-button
                  :type="reviewSort === 'highest' ? 'primary' : 'default'"
                  @click="reviewSort = 'highest'"
                >
                  Highest
                </n-button>
                <n-button
                  :type="reviewSort === 'lowest' ? 'primary' : 'default'"
                  @click="reviewSort = 'lowest'"
                >
                  Lowest
                </n-button>
              </n-button-group>
            </div>
            <n-spin :show="reviewsLoading">
              <n-space v-if="sortedReviews.length" vertical size="large">
                <div v-for="review in sortedReviews" :key="review.id" class="review-item">
                  <n-space align="start" size="medium">
                    <n-avatar round size="medium" style="flex-shrink: 0;">
                      {{ review.authorInitials }}
                    </n-avatar>
                    <div style="flex: 1; min-width: 0;">
                      <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 4px;">
                        <div>
                          <n-text strong>{{ review.authorName }}</n-text>
                          <n-text depth="3" style="display: block; font-size: 0.85rem;">
                            {{ review.createdAtLabel }}
                          </n-text>
                        </div>
                        <n-rate v-if="review.rating" :value="review.rating" size="small" readonly />
                      </div>
                      <n-text depth="2" style="display: block; line-height: 1.5;">
                        {{ review.content }}
                      </n-text>
                    </div>
                  </n-space>
                </div>
              </n-space>
              <n-empty v-else description="No reviews yet. Be the first to review!" size="small" />
            </n-spin>
          </div>
        </n-space>
      </n-drawer-content>
    </n-drawer>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import {
  NAlert,
  NAvatar,
  NButton,
  NButtonGroup,
  NCard,
  NCarousel,
  NDivider,
  NDrawer,
  NDrawerContent,
  NEmpty,
  NIcon,
  NInput,
  NModal,
  NPageHeader,
  NProgress,
  NSpace,
  NSpin,
  NTabPane,
  NTabs,
  NTag,
  NText,
  NRate,
  useMessage,
} from 'naive-ui'

const props = defineProps({
  currentUser: {
    type: Object,
    default: () => null,
  },
})

const emit = defineEmits(['contact'])

const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const MARKETPLACE_ENDPOINT = `${API_BASE}/marketplace/marketplace.php`
const REVIEWS_ENDPOINT = `${API_BASE}/marketplace/marketplacereview.php`

const message = useMessage()
const listings = ref([])
const listingsLoading = ref(false)
const listingsError = ref('')
const searchQuery = ref('')
const activeCategory = ref('all')
const selectedListing = ref(null)
const detailModalVisible = ref(false)
const reviews = ref([])
const reviewsLoading = ref(false)
const submittingReview = ref(false)
const newReview = ref({ content: '', rating: null })
const reviewSort = ref('newest')
const imageModalVisible = ref(false)
const galleryImages = ref([])
const currentImageIndex = ref(0)
const imageZoom = ref(1)
const reviewDrawerVisible = ref(false)
const reviewingListing = ref(null)
const pendingListingId = ref(null)

const currentTravelerId = computed(() => {
  const source = props.currentUser ?? {}
  return source.id ?? source.travelerID ?? source.travelerId ?? source.userId ?? null
})

const currentImage = computed(() => galleryImages.value[currentImageIndex.value] ?? null)

const sortedReviews = computed(() => {
  const reviewsCopy = [...reviews.value]
  
  switch (reviewSort.value) {
    case 'highest':
      return reviewsCopy.sort((a, b) => {
        const ratingA = a.rating ?? 0
        const ratingB = b.rating ?? 0
        return ratingB - ratingA
      })
    case 'lowest':
      return reviewsCopy.sort((a, b) => {
        const ratingA = a.rating ?? 0
        const ratingB = b.rating ?? 0
        return ratingA - ratingB
      })
    case 'newest':
    default:
      return reviewsCopy.sort((a, b) => {
        const dateA = new Date(a.createdAt)
        const dateB = new Date(b.createdAt)
        return dateB - dateA
      })
  }
})

const categoryOptions = [
  { value: 'all', label: 'All' },
  { value: 'homestay', label: 'Homestay', original: 'Homestay' },
  { value: 'food-beverage', label: 'Food & Beverage', original: 'Food & Beverage' },
  { value: 'wellness', label: 'Wellness', original: 'Wellness' },
  { value: 'entertainment', label: 'Entertainment', original: 'Entertainment' },
  { value: 'dessert', label: 'Dessert', original: 'Dessert' },
  { value: 'others', label: 'Others', original: 'Others' },
]


watch(selectedListing, (listing) => {
  if (listing) {
    loadReviews(listing.id)
    newReview.value = { content: '', rating: null }
  } else {
    reviews.value = []
  }
})

const filteredListings = computed(() => {
  let result = listings.value

  if (activeCategory.value !== 'all') {
    const categoryOption = categoryOptions.find((opt) => opt.value === activeCategory.value)
    if (categoryOption && categoryOption.original) {
      result = result.filter((listing) => listing.category === categoryOption.original)
    }
  }

  if (searchQuery.value.trim()) {
    const query = searchQuery.value.trim().toLowerCase()
    result = result.filter(
      (listing) =>
        listing.businessName?.toLowerCase().includes(query) ||
        listing.description?.toLowerCase().includes(query) ||
        listing.location?.toLowerCase().includes(query)
    )
  }

  return result
})

onMounted(() => {
  activeCategory.value = 'all'
  fetchListings()
})

async function fetchListings() {
  listingsLoading.value = true
  listingsError.value = ''

  try {
    const params = new URLSearchParams()
    if (currentTravelerId.value) {
      params.set('travelerId', String(currentTravelerId.value))
    }
    const url = params.toString() ? `${MARKETPLACE_ENDPOINT}?${params.toString()}` : MARKETPLACE_ENDPOINT
    const response = await fetch(url)
    if (!response.ok) {
      throw new Error('Failed to load business listings.')
    }
    const payload = await response.json()
    listings.value = Array.isArray(payload.listings) ? payload.listings : []
  } catch (error) {
    console.error('Failed to load listings', error)
    listingsError.value = error instanceof Error ? error.message : 'Failed to load business listings.'
    listings.value = []
  } finally {
    listingsLoading.value = false
    activeCategory.value = 'all'
  }
}

async function loadReviews(listingId) {
  if (!listingId) return
  reviewsLoading.value = true
  try {
    const response = await fetch(`${REVIEWS_ENDPOINT}?listingId=${listingId}&limit=20`)
    if (!response.ok) {
      throw new Error('Failed to load reviews.')
    }
    let payload
    try {
      payload = await response.json()
    } catch (parseError) {
      console.error('Failed to parse reviews response', parseError)
      reviews.value = []
      return
    }
    if (payload.ok === false) {
      throw new Error(payload.error || 'Failed to load reviews.')
    }
    reviews.value = Array.isArray(payload.reviews) ? payload.reviews : []
  } catch (error) {
    console.error('Failed to load reviews', error)
    reviews.value = []
  } finally {
    reviewsLoading.value = false
  }
}

function openReviewDrawer(listing) {
  if (!currentTravelerId.value) {
    message.warning('Please sign in to write reviews.')
    return
  }
  reviewingListing.value = listing
  reviewDrawerVisible.value = true
  loadReviews(listing.id)
}

function closeReviewDrawer() {
  reviewDrawerVisible.value = false
  reviewingListing.value = null
  newReview.value = { content: '', rating: null }
  reviews.value = []
  reviewSort.value = 'newest'
}

async function toggleSave(listing) {
  if (!currentTravelerId.value) {
    message.warning('Please sign in to save listings.')
    return
  }

  const previousState = listing.isSaved
  listing.isSaved = !listing.isSaved

  try {
    const response = await fetch(MARKETPLACE_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'toggle-save',
        listingId: listing.id,
        travelerId: currentTravelerId.value,
      }),
    })

    const data = await response.json()
    if (!response.ok || !data.ok) {
      throw new Error(data.error || 'Failed to update save status')
    }

    listing.isSaved = data.saved
    if (selectedListing.value && selectedListing.value.id === listing.id) {
      selectedListing.value.isSaved = data.saved
    }
  } catch (error) {
    listing.isSaved = previousState
    if (selectedListing.value && selectedListing.value.id === listing.id) {
      selectedListing.value.isSaved = previousState
    }
    message.error(error instanceof Error ? error.message : 'Failed to update save status')
  }
}

async function submitReview() {
  if (!currentTravelerId.value) {
    message.warning('Please sign in to write reviews.')
    return
  }

  const targetListing = reviewingListing.value || selectedListing.value
  if (!targetListing) {
    return
  }

  const content = newReview.value.content.trim()
  if (!content) {
    message.warning('Please write a review before submitting.')
    return
  }

  submittingReview.value = true
  try {
    const response = await fetch(MARKETPLACE_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'add-review',
        listingId: targetListing.id,
        travelerId: currentTravelerId.value,
        content,
        rating: newReview.value.rating,
      }),
    })

    let data
    try {
      data = await response.json()
    } catch (parseError) {
      throw new Error('Server returned invalid response. Please try again.')
    }

    if (!response.ok || !data.ok) {
      const errorMsg = data.errorDetails || data.error || 'Failed to submit review'
      console.error('Review submission error:', data)
      throw new Error(errorMsg)
    }

    if (data.review) {
      reviews.value = [data.review, ...reviews.value]
    }
    newReview.value = { content: '', rating: null }
    message.success('Review submitted successfully!')
    await fetchListings()
    if (selectedListing.value && selectedListing.value.id === targetListing.id) {
      const updated = listings.value.find((l) => l.id === selectedListing.value.id)
      if (updated) {
        selectedListing.value = updated
      }
    }
    if (reviewingListing.value && reviewingListing.value.id === targetListing.id) {
      const updated = listings.value.find((l) => l.id === reviewingListing.value.id)
      if (updated) {
        reviewingListing.value = updated
      }
    }
  } catch (error) {
    message.error(error instanceof Error ? error.message : 'Failed to submit review')
  } finally {
    submittingReview.value = false
  }
}

function handleSearch() {
  // Search is handled by computed property
}

function truncateText(text, maxLength) {
  if (!text || text.length <= maxLength) return text
  return text.slice(0, maxLength) + '...'
}

function openListingDetail(listing) {
  selectedListing.value = listing
  detailModalVisible.value = true
}

function openListingById(listingId) {
  const numeric = Number(listingId)
  if (!Number.isFinite(numeric) || numeric <= 0) {
    return
  }
  const target = listings.value.find((item) => Number(item.id) === numeric)
  if (target) {
    openListingDetail(target)
    pendingListingId.value = null
  } else {
    pendingListingId.value = numeric
  }
}

watch(
  () => [pendingListingId.value, listings.value],
  () => {
    if (!pendingListingId.value) {
      return
    }
    const target = listings.value.find((item) => Number(item.id) === pendingListingId.value)
    if (target) {
      openListingDetail(target)
      pendingListingId.value = null
    }
  },
)

function closeListingDetail() {
  detailModalVisible.value = false
  selectedListing.value = null
  reviews.value = []
  newReview.value = { content: '', rating: null }
}

function handleContact(listing) {
  emit('contact', listing)
  closeListingDetail()
}

function openImageModal(images, startIndex = 0) {
  galleryImages.value = images
  currentImageIndex.value = startIndex
  imageZoom.value = 1
  imageModalVisible.value = true
}

function closeImageModal() {
  imageModalVisible.value = false
  galleryImages.value = []
  currentImageIndex.value = 0
  imageZoom.value = 1
}

function zoomIn() {
  if (imageZoom.value < 3) {
    imageZoom.value = Math.min(imageZoom.value + 0.25, 3)
  }
}

function zoomOut() {
  if (imageZoom.value > 0.5) {
    imageZoom.value = Math.max(imageZoom.value - 0.25, 0.5)
  }
}

function resetZoom() {
  imageZoom.value = 1
}

function nextImage() {
  if (currentImageIndex.value < galleryImages.value.length - 1) {
    currentImageIndex.value++
    imageZoom.value = 1
  }
}

function previousImage() {
  if (currentImageIndex.value > 0) {
    currentImageIndex.value--
    imageZoom.value = 1
  }
}

defineExpose({
  openListingById,
})
</script>

<style scoped>
.marketplace-feed {
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
}

.feed-alert {
  margin-bottom: 16px;
}

.feed-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 18px;
  align-items: stretch;
}

@media (min-width: 1024px) {
  .feed-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (min-width: 1440px) {
  .feed-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (min-width: 1920px) {
  .feed-grid {
    grid-template-columns: repeat(5, minmax(0, 1fr));
  }
}

.listing-card {
  width: 100%;
  display: flex;
}

.listing-card :deep(.n-card) {
  height: 100%;
  display: flex;
  flex-direction: column;
}

.listing-media {
  position: relative;
  padding-top: 72%;
  overflow: hidden;
  border-radius: 16px;
  background: #f3f4f6;
  cursor: pointer;
}

.listing-media img {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.media-carousel-container {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
}

.media-carousel-container :deep(.n-carousel) {
  height: 100%;
}

.media-carousel-container :deep(.n-carousel__slides) {
  height: 100%;
}

.media-carousel-container :deep(.n-carousel__slide) {
  height: 100%;
}

.carousel-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  cursor: pointer;
}

.media-carousel-container :deep(.n-carousel__arrow) {
  background: rgba(0, 0, 0, 0.5);
  color: white;
  border-radius: 50%;
}

.media-carousel-container :deep(.n-carousel__dots) {
  bottom: 12px;
}

.media-carousel-container :deep(.n-carousel__dot) {
  background: rgba(255, 255, 255, 0.6);
}

.media-carousel-container :deep(.n-carousel__dot--active) {
  background: white;
}

.image-count-badge {
  position: absolute;
  top: 8px;
  right: 8px;
  background: rgba(0, 0, 0, 0.6);
  color: white;
  padding: 4px 8px;
  border-radius: 12px;
  font-size: 0.75rem;
  display: flex;
  align-items: center;
  gap: 4px;
}

.listing-placeholder {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, rgba(66, 184, 131, 0.1), rgba(108, 99, 255, 0.1));
  color: rgba(15, 23, 42, 0.4);
}

.listing-body {
  display: flex;
  flex-direction: column;
  gap: 8px;
  flex: 1;
}

.listing-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 8px;
}

.listing-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}

.detail-header {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
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

.detail-media-section {
  flex: 1 1 55%;
  max-width: 560px;
}

.detail-carousel-wrapper {
  position: relative;
  width: 100%;
  border-radius: 12px;
  overflow: hidden;
  background: #f3f4f6;
}

.detail-carousel-wrapper :deep(.n-carousel) {
  aspect-ratio: 4 / 3;
}

.detail-carousel-slide {
  position: relative;
  width: 100%;
  height: 100%;
  cursor: pointer;
}

.detail-carousel-slide img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.zoom-hint {
  position: absolute;
  bottom: 12px;
  right: 12px;
  background: rgba(0, 0, 0, 0.6);
  color: white;
  padding: 6px 12px;
  border-radius: 20px;
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.85rem;
  opacity: 0;
  transition: opacity 0.2s;
}

.detail-carousel-slide:hover .zoom-hint {
  opacity: 1;
}

.carousel-counter {
  position: absolute;
  top: 12px;
  left: 12px;
  background: rgba(0, 0, 0, 0.6);
  color: white;
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 0.85rem;
  z-index: 1;
}

.detail-carousel-wrapper :deep(.n-carousel__arrow) {
  background: rgba(0, 0, 0, 0.5);
  color: white;
}

.image-gallery {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.main-image {
  position: relative;
  width: 100%;
  padding-top: 75%;
  border-radius: 12px;
  overflow: hidden;
  background: #f3f4f6;
  cursor: pointer;
}

.main-image img {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.image-overlay {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.7), transparent);
  color: white;
  padding: 12px;
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.9rem;
}

.thumbnail-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
}

.thumbnail {
  position: relative;
  padding-top: 75%;
  border-radius: 8px;
  overflow: hidden;
  background: #f3f4f6;
  cursor: pointer;
}

.thumbnail img {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.more-thumbnail {
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.5);
  color: white;
}

.more-count {
  position: absolute;
  font-size: 1.2rem;
  font-weight: 600;
}

.detail-body {
  flex: 1 1 45%;
  display: flex;
  flex-direction: column;
}

.review-summary-section {
  margin-top: 8px;
}

.review-summary {
  margin-top: 12px;
}

.review-rating {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}

.rating-number {
  font-size: 2rem;
  font-weight: 700;
}

.rating-count {
  font-size: 0.9rem;
  color: rgba(0, 0, 0, 0.6);
}

.rating-distribution {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.rating-bar-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.85rem;
}

.rating-bar-item :deep(.n-progress) {
  flex: 1;
}

.reviews-section {
  margin-top: 8px;
}

.reviews-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-top: 12px;
  max-height: 400px;
  overflow-y: auto;
}

.review-item {
  padding-bottom: 16px;
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
}

.review-item:last-child {
  border-bottom: none;
}

.review-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.review-author {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.review-content {
  margin: 0;
  line-height: 1.6;
  color: rgba(0, 0, 0, 0.8);
}

.review-compose {
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid rgba(0, 0, 0, 0.06);
}

.image-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
}

.image-modal-content {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 600px;
}

.image-container {
  width: 100%;
  height: 100%;
  overflow: auto;
  display: flex;
  align-items: center;
  justify-content: center;
}

.enlarged-image {
  max-width: 100%;
  max-height: 80vh;
  object-fit: contain;
  border-radius: 8px;
  transition: transform 0.3s ease;
  cursor: move;
}

.image-nav {
  position: absolute;
  top: 50%;
  left: 0;
  right: 0;
  display: flex;
  justify-content: space-between;
  padding: 0 16px;
  transform: translateY(-50%);
  pointer-events: none;
}

.image-nav :deep(.n-button) {
  pointer-events: auto;
  background: rgba(0, 0, 0, 0.5);
  color: white;
}
</style>
