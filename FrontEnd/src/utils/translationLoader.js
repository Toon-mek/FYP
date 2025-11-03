import { i18n } from '../plugins/i18n'
import englishMessages from '../locales/en.json'

const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const TRANSLATE_ENDPOINT = `${API_BASE.replace(/\/$/, '')}/external/translate.php`
const CACHE_PREFIX = 'mst-translation-cache'
const CACHE_VERSION = '1'
const TRANSLATABLE_SECTIONS = ['home', 'footer']

function extractSections(messages) {
  const subset = {}
  for (const section of TRANSLATABLE_SECTIONS) {
    if (Object.prototype.hasOwnProperty.call(messages, section)) {
      subset[section] = messages[section]
    }
  }
  return subset
}

function collectStrings(node, collector, path = []) {
  if (typeof node === 'string') {
    if (!shouldSkipValue(node, path)) {
      collector.add(node)
    }
    return
  }

  if (node === null || typeof node !== 'object') {
    return
  }

  if (Array.isArray(node)) {
    node.forEach((item, index) => collectStrings(item, collector, path.concat(index)))
    return
  }

  for (const [key, value] of Object.entries(node)) {
    const nextPath = path.concat(key)
    if (typeof value === 'string') {
      if (!shouldSkipKey(key) && !shouldSkipValue(value, nextPath)) {
        collector.add(value)
      }
      continue
    }
    collectStrings(value, collector, nextPath)
  }
}

function shouldSkipKey(key) {
  return key === 'href' || key === 'initials'
}

function shouldSkipValue(value, path) {
  if (typeof value !== 'string') {
    return true
  }
  const trimmed = value.trim()
  if (trimmed === '') {
    return true
  }
  if (trimmed.startsWith('#') || trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
    return true
  }
  if (path.includes('language')) {
    return true
  }
  return false
}

function translateStructure(node, translations, path = []) {
  if (typeof node === 'string') {
    return translations.get(node) ?? node
  }

  if (node === null || typeof node !== 'object') {
    return node
  }

  if (Array.isArray(node)) {
    return node.map((item, index) => translateStructure(item, translations, path.concat(index)))
  }

  const result = {}
  for (const [key, value] of Object.entries(node)) {
    if (typeof value === 'string') {
      if (shouldSkipKey(key) || shouldSkipValue(value, path.concat(key))) {
        result[key] = value
      } else {
        result[key] = translations.get(value) ?? value
      }
      continue
    }
    result[key] = translateStructure(value, translations, path.concat(key))
  }
  return result
}

function deepMerge(base, override) {
  if (Array.isArray(base) && Array.isArray(override)) {
    return override.map((item) => cloneValue(item))
  }

  if (Array.isArray(base)) {
    return base.map((item) => cloneValue(item))
  }

  if (Array.isArray(override)) {
    return override.map((item) => cloneValue(item))
  }

  const result = {}
  if (base && typeof base === 'object') {
    for (const [key, value] of Object.entries(base)) {
      result[key] = cloneValue(value)
    }
  }

  if (override && typeof override === 'object') {
    for (const [key, value] of Object.entries(override)) {
      if (value && typeof value === 'object' && !Array.isArray(value) && result[key] && typeof result[key] === 'object' && !Array.isArray(result[key])) {
        result[key] = deepMerge(result[key], value)
      } else {
        result[key] = cloneValue(value)
      }
    }
  }

  return result
}

function cloneValue(value) {
  if (Array.isArray(value)) {
    return value.map((item) => cloneValue(item))
  }
  if (value && typeof value === 'object') {
    const clone = {}
    for (const [key, val] of Object.entries(value)) {
      clone[key] = cloneValue(val)
    }
    return clone
  }
  return value
}

function cacheKey(locale) {
  return `${CACHE_PREFIX}:${CACHE_VERSION}:${locale}`
}

function saveToCache(locale, messages) {
  if (typeof window === 'undefined' || !window.localStorage) {
    return
  }
  try {
    const payload = JSON.stringify(messages)
    window.localStorage.setItem(cacheKey(locale), payload)
  } catch (error) {
    console.warn('Unable to cache translations', error)
  }
}

function readFromCache(locale) {
  if (typeof window === 'undefined' || !window.localStorage) {
    return null
  }
  const raw = window.localStorage.getItem(cacheKey(locale))
  if (!raw) {
    return null
  }
  try {
    const parsed = JSON.parse(raw)
    if (parsed && typeof parsed === 'object') {
      return parsed
    }
  } catch (error) {
    console.warn('Unable to parse cached translations', error)
  }
  return null
}

export async function ensureLocaleMessages(locale) {
  if (!locale || locale === 'en') {
    return
  }

  const existing = i18n.global.getLocaleMessage(locale)
  const hasAllSections =
    existing &&
    TRANSLATABLE_SECTIONS.every((section) => Object.prototype.hasOwnProperty.call(existing, section))

  if (hasAllSections) {
    return
  }

  const cached = readFromCache(locale)
  if (cached) {
    const merged = deepMerge(cached, existing ?? {})
    i18n.global.setLocaleMessage(locale, merged)
    return
  }

  const subset = extractSections(englishMessages)
  const collector = new Set()
  collectStrings(subset, collector)

  if (collector.size === 0) {
    return
  }

  let response
  try {
    response = await fetch(TRANSLATE_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        source: 'en',
        target: locale,
        texts: Array.from(collector),
        format: 'text',
      }),
    })
  } catch (error) {
    console.error('Failed to request translations', error)
    return
  }

  if (!response || !response.ok) {
    console.error('Translation endpoint returned an error', response && (await safeParseJson(response)))
    return
  }

  const payload = await safeParseJson(response)
  if (!payload || payload.ok !== true || !Array.isArray(payload.translations)) {
    console.error('Unexpected translation payload', payload)
    return
  }

  const translationMap = new Map()
  for (const entry of payload.translations) {
    if (!entry || typeof entry !== 'object') {
      continue
    }
    const input = typeof entry.input === 'string' ? entry.input : null
    const translated = typeof entry.translatedText === 'string' ? entry.translatedText : null
    if (input && translated) {
      translationMap.set(input, translated)
    }
  }

  const translatedSubset = translateStructure(subset, translationMap)
  const merged = deepMerge(translatedSubset, existing ?? {})
  i18n.global.setLocaleMessage(locale, merged)
  saveToCache(locale, translatedSubset)
}

async function safeParseJson(response) {
  try {
    return await response.json()
  } catch (error) {
    return null
  }
}

