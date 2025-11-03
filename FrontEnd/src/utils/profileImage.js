const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const explicitPublicBase = typeof import.meta.env.VITE_PUBLIC_ASSET_BASE === 'string'
  ? import.meta.env.VITE_PUBLIC_ASSET_BASE.trim()
  : ''

function computePublicAssetBase() {
  if (explicitPublicBase) {
    return explicitPublicBase.endsWith('/') ? explicitPublicBase : `${explicitPublicBase}/`
  }

  if (/^https?:\/\//i.test(API_BASE)) {
    try {
      const apiUrl = new URL(API_BASE)
      apiUrl.pathname = apiUrl.pathname.replace(/\/api\/?$/, '')
      const basePath = apiUrl.pathname.replace(/\/$/, '')
      return `${apiUrl.origin}${basePath ? `${basePath}/` : '/'}public_assets/`
    } catch {
      // fall back to relative handling below
    }
  }

  const trimmed = API_BASE.replace(/\/api\/?$/, '')
  if (!trimmed || trimmed === '/') {
    return '/public_assets/'
  }
  return `${trimmed.replace(/\/$/, '')}/public_assets/`
}

export const PUBLIC_ASSET_BASE = computePublicAssetBase()

export function resolveProfileImageUrl(path) {
  if (!path) return ''
  if (/^https?:\/\/|^data:/i.test(path)) {
    return path
  }
  const normalised = String(path).replace(/^\/+/, '')
  return `${PUBLIC_ASSET_BASE}${normalised}`
}

export function extractProfileImage(source) {
  if (!source || typeof source !== 'object') {
    return { relative: '', url: '' }
  }

  const explicit =
    source.profileImageUrl ??
    source.profileImageURL ??
    source.avatarUrl ??
    source.avatarURL ??
    source.profile_image_url ??
    ''

  const relative =
    source.profileImagePath ??
    source.profile_image_path ??
    source.profileImage ??
    source.profile_image ??
    source.avatarPath ??
    source.avatar_path ??
    ''

  const url = explicit || (relative ? resolveProfileImageUrl(relative) : '')

  return { relative, url }
}

export function withProfileImage(source) {
  if (!source || typeof source !== 'object') {
    return null
  }
  const cloned = { ...source }
  const { relative, url } = extractProfileImage(cloned)
  if (!cloned.profileImagePath && relative) {
    cloned.profileImagePath = relative
  }
  cloned.profileImageUrl = url
  if (!cloned.avatarUrl) {
    cloned.avatarUrl = url
  }
  return cloned
}
