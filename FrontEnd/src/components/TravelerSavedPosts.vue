<template>
  <div class="saved-posts-feed">
    <n-spin :show="loading">
      <div class="saved-posts-switcher">
        <n-tabs v-model:value="activeFilter" type="segment" size="small">
          <n-tab-pane
            v-for="option in filterOptions"
            :key="option.value"
            :name="option.value"
            :tab="option.label"
          />
        </n-tabs>
      </div>
      <n-alert v-if="error" type="error" :closable="false" class="saved-posts-alert">
        {{ error }}
      </n-alert>
      <TravelerSocialFeed
        v-else-if="filteredPosts.length"
        :posts="filteredPosts"
        :current-user="currentUser"
        :categories="[]"
        hide-header
        disable-fetch
      />
      <n-empty v-else :description="emptyDescription" />
    </n-spin>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { NAlert, NEmpty, NSpin, NTabs, NTabPane } from 'naive-ui'
import TravelerSocialFeed from './TravelerSocialFeed.vue'

const props = defineProps({
  categories: {
    type: Array,
    default: () => [],
  },
  currentUser: {
    type: Object,
    default: () => null,
  },
})

const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const COMMUNITY_ENDPOINT = `${API_BASE}/community/posts.php`

const loading = ref(false)
const error = ref('')
const rawPosts = ref([])

const filterOptions = [
  { value: 'saved', label: 'Saved posts' },
  { value: 'liked', label: 'Liked posts' },
  { value: 'commented', label: 'Commented posts' },
]

const activeFilter = ref('saved')

const emptyMessages = {
  saved: "You haven't saved any community stories yet.",
  liked: "You haven't liked any community stories yet.",
  commented: "You haven't commented on any community stories yet.",
}

const emptyDescription = computed(() => emptyMessages[activeFilter.value] ?? emptyMessages.saved)
const activeFilterLabel = computed(
  () => filterOptions.find((option) => option.value === activeFilter.value)?.label ?? 'Saved posts'
)

let activeRequestToken = 0

const currentUserId = computed(() => {
  const source = props.currentUser ?? {}
  return (
    source.id ??
    source.userId ??
    source.userID ??
    source.travelerId ??
    source.travelerID ??
    null
  )
})

function normaliseFlag(value) {
  if (value === undefined || value === null) {
    return false
  }
  if (typeof value === 'boolean') {
    return value
  }
  if (typeof value === 'number') {
    return value !== 0
  }
  if (typeof value === 'string') {
    const normalised = value.trim().toLowerCase()
    if (!normalised || normalised === '0' || normalised === 'false' || normalised === 'no') {
      return false
    }
    return true
  }
  return Boolean(value)
}

function matchesSaved(post, viewerId) {
  if (!post) {
    return false
  }

  const flags = [
    post?.isSaved,
    post?.saved,
    post?.hasSaved,
    post?.viewerSaved,
    post?.viewerHasSaved,
    post?.viewer?.isSaved,
    post?.viewerState?.isSaved,
    post?.viewer_state?.isSaved,
    post?.viewer?.saved,
    post?.viewerState?.saved,
    post?.viewer_state?.saved,
    post?.isBookmarked,
    post?.bookmarked,
  ]

  if (flags.some((flag) => normaliseFlag(flag))) {
    return true
  }

  if (!viewerId) {
    return false
  }

  const savedByList = post.savedBy ?? post.savedByIds ?? post.bookmarkedBy ?? post.favouritedBy ?? []
  let matched = false

  if (Array.isArray(savedByList)) {
    matched = savedByList.some((entry) => {
      if (entry === null || entry === undefined) {
        return false
      }

      if (typeof entry === 'number' || typeof entry === 'string') {
        return String(entry) === String(viewerId)
      }

      if (typeof entry === 'object') {
        const entryId =
          entry.id ?? entry.travelerId ?? entry.travelerID ?? entry.userId ?? entry.userID ?? null
        const entryFlag =
          entry.saved ?? entry.isSaved ?? entry.bookmarked ?? entry.favourited ?? entry.favorite ?? true
        if (!entryId) {
          return false
        }
        return String(entryId) === String(viewerId) && normaliseFlag(entryFlag)
      }

      return false
    })
  }

  if (matched) {
    return true
  }

  const savedByMap = post.savedByMap ?? post.savedMap ?? post.bookmarkedByMap ?? post.favouritedByMap ?? null
  if (savedByMap && typeof savedByMap === 'object') {
    const entries = Array.isArray(savedByMap) ? savedByMap : Object.values(savedByMap)
    matched = entries.some((entry) => {
      if (entry === null || entry === undefined) {
        return false
      }
      if (typeof entry === 'boolean') {
        return entry === true
      }
      if (typeof entry === 'number' || typeof entry === 'string') {
        return String(entry) === String(viewerId)
      }
      if (typeof entry === 'object') {
        const entryId = entry.id ?? entry.travelerId ?? entry.travelerID ?? entry.userId ?? entry.userID ?? null
        const entryFlag = entry.saved ?? entry.isSaved ?? entry.bookmarked ?? entry.favourited ?? entry.favorite ?? true
        if (!entryId) {
          return false
        }
        return String(entryId) === String(viewerId) && normaliseFlag(entryFlag)
      }
      return false
    })
  }

  return matched
}

function matchesLiked(post, viewerId) {
  if (!post) {
    return false
  }

  const flags = [
    post?.isLiked,
    post?.liked,
    post?.hasLiked,
    post?.viewerLiked,
    post?.viewerHasLiked,
    post?.viewer?.liked,
    post?.viewer?.isLiked,
    post?.viewerState?.liked,
    post?.viewerState?.isLiked,
    post?.viewer_state?.liked,
    post?.viewer_state?.isLiked,
  ]

  if (flags.some((flag) => normaliseFlag(flag))) {
    return true
  }

  if (!viewerId) {
    return false
  }

  const likedByList = post.likedBy ?? post.reactedBy ?? post.likesBy ?? post.reactions ?? []
  let matched = false

  if (Array.isArray(likedByList)) {
    matched = likedByList.some((entry) => {
      if (entry === null || entry === undefined) {
        return false
      }
      if (typeof entry === 'number' || typeof entry === 'string') {
        return String(entry) === String(viewerId)
      }
      if (typeof entry === 'object') {
        const entryId =
          entry.id ?? entry.travelerId ?? entry.travelerID ?? entry.userId ?? entry.userID ?? entry.viewerId ?? null
        const entryFlag = entry.liked ?? entry.isLiked ?? entry.reacted ?? entry.reaction ?? true
        if (!entryId) {
          return false
        }
        return String(entryId) === String(viewerId) && normaliseFlag(entryFlag)
      }
      return false
    })
  }

  if (matched) {
    return true
  }

  const likedByMap = post.likedByMap ?? post.reactedByMap ?? post.likesMap ?? null
  if (likedByMap && typeof likedByMap === 'object') {
    const entries = Array.isArray(likedByMap) ? likedByMap : Object.values(likedByMap)
    matched = entries.some((entry) => {
      if (entry === null || entry === undefined) {
        return false
      }
      if (typeof entry === 'boolean') {
        return entry === true
      }
      if (typeof entry === 'number' || typeof entry === 'string') {
        return String(entry) === String(viewerId)
      }
      if (typeof entry === 'object') {
        const entryId = entry.id ?? entry.travelerId ?? entry.travelerID ?? entry.userId ?? entry.userID ?? null
        const entryFlag = entry.liked ?? entry.isLiked ?? entry.reacted ?? entry.reaction ?? true
        if (!entryId) {
          return false
        }
        return String(entryId) === String(viewerId) && normaliseFlag(entryFlag)
      }
      return false
    })
  }

  return matched
}

function matchesCommented(post, viewerId) {
  if (!post) {
    return false
  }

  const flags = [
    post?.viewerHasCommented,
    post?.hasCommented,
    post?.viewerCommented,
    post?.viewerHasComments,
    post?.viewer_state?.commented,
  ]

  if (flags.some((flag) => normaliseFlag(flag))) {
    return true
  }

  const commentCount =
    Number(post?.viewerCommentCount ?? post?.commentCountByViewer ?? post?.viewerCommentTotal ?? 0) || 0
  if (commentCount > 0) {
    return true
  }

  if (!viewerId) {
    return false
  }

  const commentersList = post.commenters ?? post.commentersIds ?? post.commentsBy ?? []
  let matched = false

  if (Array.isArray(commentersList)) {
    matched = commentersList.some((entry) => {
      if (entry === null || entry === undefined) {
        return false
      }
      if (typeof entry === 'number' || typeof entry === 'string') {
        return String(entry) === String(viewerId)
      }
      if (typeof entry === 'object') {
        const entryId =
          entry.id ?? entry.travelerId ?? entry.travelerID ?? entry.userId ?? entry.userID ?? entry.commenterId ?? null
        if (!entryId) {
          return false
        }
        const entryFlag =
          entry.commented ?? entry.hasCommented ?? entry.viewerHasCommented ?? entry.commentCount ?? true
        return String(entryId) === String(viewerId) && normaliseFlag(entryFlag)
      }
      return false
    })
  }

  if (matched) {
    return true
  }

  const commentersMap = post.commentersMap ?? post.commentsByMap ?? null
  if (commentersMap && typeof commentersMap === 'object') {
    const entries = Array.isArray(commentersMap) ? commentersMap : Object.values(commentersMap)
    matched = entries.some((entry) => {
      if (entry === null || entry === undefined) {
        return false
      }
      if (typeof entry === 'boolean') {
        return entry === true
      }
      if (typeof entry === 'number' || typeof entry === 'string') {
        return String(entry) === String(viewerId)
      }
      if (typeof entry === 'object') {
        const entryId = entry.id ?? entry.travelerId ?? entry.travelerID ?? entry.userId ?? entry.userID ?? null
        if (!entryId) {
          return false
        }
        const entryFlag =
          entry.commented ?? entry.hasCommented ?? entry.viewerHasCommented ?? entry.commentCount ?? true
        return String(entryId) === String(viewerId) && normaliseFlag(entryFlag)
      }
      return false
    })
  }

  return matched
}

const filteredPosts = computed(() => {
  const list = Array.isArray(rawPosts.value) ? rawPosts.value : []
  if (!list.length) {
    return []
  }

  const viewerId = currentUserId.value
  switch (activeFilter.value) {
    case 'liked':
      return list.filter((post) => matchesLiked(post, viewerId))
    case 'commented':
      return list.filter((post) => matchesCommented(post, viewerId))
    case 'saved':
    default:
      return list.filter((post) => matchesSaved(post, viewerId))
  }
})

async function loadPosts() {
  const viewerId = currentUserId.value
  if (!viewerId) {
    activeRequestToken += 1
    rawPosts.value = []
    error.value = ''
    loading.value = false
    return
  }

  const requestToken = ++activeRequestToken
  loading.value = true
  error.value = ''

  try {
    const params = new URLSearchParams()
    params.set('view', activeFilter.value)
    params.set('travelerId', String(viewerId))

    const response = await fetch(`${COMMUNITY_ENDPOINT}?${params.toString()}`)
    const payload = await response.json().catch(() => null)

    if (requestToken !== activeRequestToken) {
      return
    }

    if (!response.ok) {
      throw new Error(
        payload?.error ?? `Unable to load ${activeFilterLabel.value.toLowerCase()}.`
      )
    }

    const posts = Array.isArray(payload?.posts) ? payload.posts : []
    rawPosts.value = posts
  } catch (err) {
    console.error('Failed to load traveler engagement posts', err)
    if (requestToken !== activeRequestToken) {
      return
    }
    const fallbackLabel = activeFilterLabel.value.toLowerCase()
    error.value = err instanceof Error ? err.message : `Unable to load ${fallbackLabel}.`
    rawPosts.value = []
  } finally {
    if (requestToken !== activeRequestToken) {
      return
    }
    loading.value = false
  }
}

watch(
  [currentUserId, activeFilter],
  () => {
    loadPosts()
  },
  { immediate: true }
)
</script>

<style scoped>
.saved-posts-feed {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-height: 100%;
}

.saved-posts-alert {
  margin-bottom: 8px;
}

.saved-posts-switcher {
  margin-bottom: 12px;
}
</style>




