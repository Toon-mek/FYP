<script setup>
import { computed, watch } from 'vue'
import { useAttrs } from 'vue'
import { NPagination } from 'naive-ui'

const props = defineProps({
  page: {
    type: Number,
    default: 1,
  },
  pageCount: {
    type: Number,
    default: 0,
  },
  itemCount: {
    type: Number,
    default: 0,
  },
  pageSize: {
    type: Number,
    default: 10,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  loading: {
    type: Boolean,
    default: false,
  },
  simple: {
    type: Boolean,
    default: true,
  },
  size: {
    type: String,
    default: 'small',
  },
})

const emit = defineEmits(['update:page', 'update:pageSize'])
const attrs = useAttrs()

const effectivePageCount = computed(() => {
  if (Number.isFinite(props.pageCount) && props.pageCount > 0) {
    return Math.max(1, Math.floor(props.pageCount))
  }
  if (Number.isFinite(props.itemCount) && props.itemCount > 0 && Number.isFinite(props.pageSize) && props.pageSize > 0) {
    return Math.max(1, Math.ceil(props.itemCount / props.pageSize))
  }
  return 1
})

function clampPage(value) {
  const totalPages = effectivePageCount.value
  if (!Number.isFinite(value) || value < 1) {
    return 1
  }
  if (value > totalPages) {
    return totalPages
  }
  return value
}

const currentPage = computed(() => clampPage(props.page))

watch(
  () => props.page,
  (value) => {
    const safeValue = clampPage(value)
    if (safeValue !== value) {
      emit('update:page', safeValue)
    }
  },
  { immediate: true },
)

watch(
  effectivePageCount,
  () => {
    const safeValue = clampPage(props.page)
    if (safeValue !== props.page) {
      emit('update:page', safeValue)
    }
  },
  { immediate: true },
)

function handlePageUpdate(value) {
  emit('update:page', clampPage(value))
}

function handlePageSizeUpdate(value) {
  emit('update:pageSize', value)
}
</script>

<template>
  <n-pagination v-bind="attrs" :simple="simple" :size="size" :page="currentPage" :page-count="effectivePageCount"
    :page-size="pageSize" :disabled="disabled || loading" @update:page="handlePageUpdate"
    @update:page-size="handlePageSizeUpdate" />
</template>
