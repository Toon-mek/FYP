import { computed } from 'vue'

export function usePrimaryHeaderAction(actions) {
  const primary = computed(() => {
    const list = actions.value ?? []
    if (!list.length) return null
    const prioritized = list.find(
      (item) => item?.type === 'primary' && !item?.tertiary && !item?.quaternary,
    )
    return prioritized ?? list[0]
  })

  const secondary = computed(() => {
    const primaryAction = primary.value
    if (!primaryAction) return actions.value ?? []
    return (actions.value ?? []).filter((action) => action !== primaryAction)
  })

  return {
    primaryAction: primary,
    secondaryActions: secondary,
  }
}
