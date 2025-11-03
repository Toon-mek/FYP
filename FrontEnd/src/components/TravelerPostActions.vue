<template>
  <n-dropdown trigger="click" placement="bottom-end" :options="options" :disabled="isDisabled" @select="handleSelect">
    <n-button quaternary circle size="small" :disabled="isDisabled" :loading="props.editing">
      <template #icon>
        <n-icon>
          <i class="ri-more-2-fill" />
        </n-icon>
      </template>
    </n-button>
  </n-dropdown>
</template>

<script setup>
import { computed, h } from 'vue'
import { NDropdown, NButton, NIcon, useDialog } from 'naive-ui'

const emit = defineEmits(['edit', 'delete'])

const props = defineProps({
  post: {
    type: Object,
    required: true,
  },
  editing: {
    type: Boolean,
    default: false,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
})

const dialog = useDialog()
const isDisabled = computed(() => props.disabled || props.editing)

const options = [
  {
    key: 'edit',
    label: 'Edit post',
    icon: () => renderIcon('ri-edit-line'),
  },
  {
    key: 'delete',
    label: 'Delete post',
    icon: () => renderIcon('ri-delete-bin-line'),
  },
]

function handleSelect(key) {
  if (key === 'edit') {
    emit('edit', props.post)
    return
  }

  if (key === 'delete') {
    dialog.warning({
      title: 'Delete post',
      content: 'Are you sure you want to delete this story? This action cannot be undone.',
      positiveText: 'Delete',
      negativeText: 'Cancel',
      onPositiveClick: () => emit('delete', props.post),
    })
  }
}

function renderIcon(iconName) {
  return h(NIcon, null, {
    default: () => h('i', { class: iconName }),
  })
}
</script>
