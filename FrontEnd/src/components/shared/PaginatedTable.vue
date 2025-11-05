<script setup>
import { computed, ref, watch } from 'vue'
import { NDataTable, NEmpty, NPagination, NSpace, NText } from 'naive-ui'

const props = defineProps({
  columns: {
    type: Array,
    required: true,
  },
  rows: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
  pageSize: {
    type: Number,
    default: 10,
  },
  rowKey: {
    type: [Function, String],
    default: 'key',
  },
  bordered: {
    type: Boolean,
    default: false,
  },
  emptyMessage: {
    type: String,
    default: 'No records found.',
  },
  showRange: {
    type: Boolean,
    default: true,
  },
  hidePaginationWhenSingle: {
    type: Boolean,
    default: true,
  },
  tableProps: {
    type: Object,
    default: () => ({}),
  },
  paginationProps: {
    type: Object,
    default: () => ({}),
  },
  page: {
    type: Number,
    default: undefined,
  },
})

const emit = defineEmits(['update:page'])

const internalPage = ref(props.page ?? 1)
const isControlled = computed(() => typeof props.page === 'number')

watch(
  () => props.page,
  (value) => {
    if (isControlled.value && typeof value === 'number') {
      internalPage.value = value
    }
  },
)

const total = computed(() => props.rows.length)
const pageCount = computed(() => {
  if (props.pageSize <= 0) {
    return 1
  }
  return Math.max(1, Math.ceil(total.value / props.pageSize))
})

const currentPage = computed({
  get() {
    return isControlled.value ? props.page ?? 1 : internalPage.value
  },
  set(value) {
    const safeValue = clampPage(value)
    if (isControlled.value) {
      emit('update:page', safeValue)
    } else {
      internalPage.value = safeValue
    }
  },
})

function clampPage(value) {
  const count = pageCount.value
  if (!Number.isFinite(value) || value < 1) {
    return 1
  }
  if (value > count) {
    return count
  }
  return value
}

watch(
  () => [total.value, props.pageSize],
  () => {
    currentPage.value = clampPage(currentPage.value)
  },
)

const paginatedRows = computed(() => {
  if (props.pageSize <= 0) {
    return props.rows
  }
  const start = (currentPage.value - 1) * props.pageSize
  return props.rows.slice(start, start + props.pageSize)
})

const rangeMeta = computed(() => {
  if (total.value === 0) {
    return { start: 0, end: 0, total: 0 }
  }
  const start = (currentPage.value - 1) * props.pageSize + 1
  const end = Math.min(currentPage.value * props.pageSize, total.value)
  return { start, end, total: total.value }
})

const showPagination = computed(
  () => !props.hidePaginationWhenSingle || pageCount.value > 1,
)

const tableAttrs = computed(() => ({
  pagination: false,
  bordered: props.bordered,
  ...props.tableProps,
}))

function resolveRowKey(row, index) {
  if (typeof props.rowKey === 'function') {
    return props.rowKey(row, index)
  }
  if (props.rowKey && row && row[props.rowKey] !== undefined) {
    return row[props.rowKey]
  }
  if (row && row.key !== undefined) {
    return row.key
  }
  if (row && row.id !== undefined) {
    return row.id
  }
  return index
}
</script>

<template>
  <n-space vertical size="small">
    <slot name="before-table" />

    <template v-if="paginatedRows.length || loading">
      <n-data-table
        v-bind="tableAttrs"
        :columns="columns"
        :data="paginatedRows"
        :loading="loading"
        :row-key="resolveRowKey"
      />
    </template>
    <n-empty v-else :description="emptyMessage" />

    <slot name="after-table" />

    <n-space
      v-if="showPagination"
      justify="space-between"
      align="center"
      style="margin-top: 8px;"
    >
      <slot name="range" :start="rangeMeta.start" :end="rangeMeta.end" :total="rangeMeta.total">
        <n-text v-if="showRange" depth="3" style="font-size: 0.75rem;">
          Showing
          {{ rangeMeta.total === 0 ? 0 : rangeMeta.start }}
          -
          {{ rangeMeta.total === 0 ? 0 : rangeMeta.end }}
          of
          {{ rangeMeta.total }}
          items.
        </n-text>
      </slot>
      <n-pagination
        v-model:page="currentPage"
        :page-size="pageSize"
        :page-count="pageCount"
        size="small"
        :disabled="loading"
        v-bind="paginationProps"
      />
    </n-space>

    <slot name="footer" />
  </n-space>
</template>
