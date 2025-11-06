export const roleTagTypeMap = {
  Traveler: 'success',
  Operator: 'warning',
  Admin: 'info',
}

export function resolveRoleTagType(role) {
  if (!role) return 'default'
  const normalised = String(role).trim()
  return roleTagTypeMap[normalised] ?? 'default'
}
