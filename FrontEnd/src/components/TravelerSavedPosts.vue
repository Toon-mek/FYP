<template>
  <div class="saved-posts-feed">
    <n-spin :show="loading">
      <div class="saved-posts-switcher">
        <n-tabs v-model:value="activeFilter" type="segment" size="small">
          <n-tab-pane
            v-for="option in filterOptions"
            :key="option.value"
            :name="option.value"
            :tab="formatTabLabel(option)"
          />
        </n-tabs>
      </div>
      <n-alert v-if="error" type="error" :closable="false" class="saved-posts-alert">
        {{ error }}
      </n-alert>
      <TravelerSocialFeed
        v-else-if="filteredPosts.length"
        :key="activeFilter"
        :posts="filteredPosts"
        :current-user="currentUser"
        :categories="[]"
        hide-header
        disable-fetch
        @post-updated="handleFeedPostUpdated"
        @post-removed="handleFeedPostRemoved"
      />
      <n-empty v-else :description="emptyDescription" />
    </n-spin>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
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

const viewStates = reactive({
  saved: {
    loading: false,
    error: '',
    posts: [],
    loaded: false,
    requestToken: 0,
  },
  liked: {
    loading: false,
    error: '',
    posts: [],
    loaded: false,
    requestToken: 0,
  },
  commented: {
    loading: false,
    error: '',
    posts: [],
    loaded: false,
    requestToken: 0,
  },
})

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

const activeState = computed(() => viewStates[activeFilter.value])
const loading = computed(() => activeState.value?.loading ?? false)
const error = computed(() => activeState.value?.error ?? '')
const emptyDescription = computed(() => emptyMessages[activeFilter.value] ?? emptyMessages.saved)

const viewCounts = computed(() => ({
  saved: viewStates.saved.posts.length,
  liked: viewStates.liked.posts.length,
  commented: viewStates.commented.posts.length,
}))

function formatTabLabel(option) {
  const count = viewCounts.value[option.value]
  return count ? `${option.label} (${count})` : option.label
}

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
  const state = activeState.value
  const list = state && Array.isArray(state.posts) ? state.posts : []
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

function resetViewStates() {
  Object.keys(viewStates).forEach((key) => {
    const state = viewStates[key]
    state.loading = false
    state.loaded = false
    state.error = ''
    state.posts = []
    state.requestToken = 0
  })
}

function updatePostAcrossViews(storyId, updater) {
  const storyIdStr = String(storyId)
  Object.keys(viewStates).forEach((view) => {
    const state = viewStates[view]
    if (!state || !Array.isArray(state.posts) || !state.posts.length) {
      return
    }
    let changed = false
    const updated = state.posts.map((post) => {
      if (String(post.id) !== storyIdStr) {
        return post
      }
      const next = { ...post }
      updater(next, post, view)
      changed = true
      return next
    })
    if (changed) {
      state.posts = updated
    }
  })
}

async function loadPosts(view) {
  if (!viewStates[view]) {
    return
  }
  const viewerId = currentUserId.value
  if (!viewerId) {
    resetViewStates()
    return
  }

  const state = viewStates[view]
  const requestToken = ++state.requestToken
  state.loading = true
  state.error = ''

  try {
    const params = new URLSearchParams()
    params.set('view', view)
    params.set('travelerId', String(viewerId))

    const response = await fetch(`${COMMUNITY_ENDPOINT}?${params.toString()}`)
    const payload = await response.json().catch(() => null)

    if (requestToken !== state.requestToken) {
      return
    }

    if (!response.ok) {
      throw new Error(
        payload?.error ??
          `Unable to load ${formatTabLabel(
            filterOptions.find((option) => option.value === view) ?? { value: view, label: view }
          ).toLowerCase()}.`
      )
    }

    const posts = Array.isArray(payload?.posts) ? payload.posts : []
    state.posts = posts
    state.loaded = true
  } catch (err) {
    console.error('Failed to load traveler engagement posts', err)
    if (requestToken !== state.requestToken) {
      return
    }
    const fallbackLabel = formatTabLabel(
      filterOptions.find((option) => option.value === view) ?? { value: view, label: view }
    ).toLowerCase()
    state.error = err instanceof Error ? err.message : `Unable to load ${fallbackLabel}.`
    state.posts = []
  } finally {
    if (requestToken !== state.requestToken) {
      return
    }
    state.loading = false
  }
}

function handleFeedPostUpdated(payload = {}) {
  const storyId = payload.storyId ?? payload.id
  if (!storyId) {
    return
  }
  const patch = payload.patch ?? {}
  const flags = payload.flags ?? {}
  updatePostAcrossViews(storyId, (target) => {
    Object.assign(target, patch)
    if (flags.isSaved !== undefined) {
      target.isSaved = flags.isSaved
    }
    if (flags.isLiked !== undefined) {
      target.isLiked = flags.isLiked
    }
    if (flags.viewerHasCommented !== undefined) {
      target.viewerHasCommented = flags.viewerHasCommented
    }
    if (flags.viewerCommentCount !== undefined) {
      target.viewerCommentCount = flags.viewerCommentCount
    }
  })
}

function handleFeedPostRemoved(payload = {}) {
  const storyId = payload.storyId ?? payload.id
  if (!storyId) {
    return
  }
  const reason = payload.reason ?? ''
  updatePostAcrossViews(storyId, (target) => {
    if (reason === 'unsaved') {
      target.isSaved = false
    }
    if (reason === 'unliked') {
      target.isLiked = false
    }
  })

  const storyIdStr = String(storyId)
  if (reason === 'unsaved') {
    const state = viewStates.saved
    if (state?.posts?.length) {
      state.posts = state.posts.filter((post) => String(post.id) !== storyIdStr)
    }
  }
  if (reason === 'unliked') {
    const state = viewStates.liked
    if (state?.posts?.length) {
      state.posts = state.posts.filter((post) => String(post.id) !== storyIdStr)
    }
  }
}

function ensureViewLoaded(view) {
  const state = viewStates[view]
  if (!state) {
    return
  }
  if (state.loaded || state.loading) {
    return
  }
  loadPosts(view)
}

watch(
  currentUserId,
  (id) => {
    resetViewStates()
    if (id) {
      ensureViewLoaded(activeFilter.value)
    }
  },
  { immediate: true }
)

watch(
  activeFilter,
  (view) => {
    ensureViewLoaded(view)
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




