<template>
  <div class="saved-posts-feed">
    <n-spin :show="loading">
      <n-alert v-if="error" type="error" :closable="false" class="saved-posts-alert">
        {{ error }}
      </n-alert>
      <TravelerSocialFeed
        v-else-if="savedPosts.length"
        :posts="savedPosts"
        :categories="categories"
        :current-user="currentUser"
        disable-fetch
      />
      <n-empty v-else description="You haven't saved any community stories yet." />
    </n-spin>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { NAlert, NEmpty, NSpin } from 'naive-ui'
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

const savedPosts = computed(() => {
  const list = Array.isArray(rawPosts.value) ? rawPosts.value : []
  if (!list.length) {
    return []
  }

  return list.filter((post) => {
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

    const viewerId = currentUserId.value
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
  })
})

async function loadSavedPosts() {
  const viewerId = currentUserId.value
  if (!viewerId) {
    rawPosts.value = []
    return
  }

  loading.value = true
  error.value = ''

  try {
    const params = new URLSearchParams()
    params.set('travelerId', String(viewerId))

    const response = await fetch(`${COMMUNITY_ENDPOINT}?${params.toString()}`)
    if (!response.ok) {
      const payload = await response.json().catch(() => null)
      throw new Error(payload?.error ?? 'Unable to load saved posts.')
    }

    const payload = await response.json()
    const posts = Array.isArray(payload?.posts) ? payload.posts : []
    rawPosts.value = posts
  } catch (err) {
    console.error('Failed to load saved posts', err)
    error.value = err instanceof Error ? err.message : 'Unable to load saved posts.'
    rawPosts.value = []
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadSavedPosts()
})

watch(currentUserId, () => {
  loadSavedPosts()
})
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
</style>




