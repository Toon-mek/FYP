const API_BASE = import.meta.env.VITE_API_BASE || '/api'
const TRANSLATE_ENDPOINT = `${API_BASE.replace(/\/$/, '')}/external/translate.php`
const STORAGE_PREFIX = 'mst-dom-translation-cache'
const SKIP_PARENT_TAGS = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEXTAREA', 'CODE', 'PRE'])
const MAX_CACHE_ENTRIES = 2000
const MAX_CHUNK_SIZE = 80
const MAX_CHUNK_CHARS = 3500

const nodeRecords = new WeakMap()
const localeCaches = new Map()
const pendingPersists = new Set()

let currentLocale = 'en'
let observer = null
let translationQueue = Promise.resolve()
let observerScheduled = false

function getLocaleCache(locale) {
  if (localeCaches.has(locale)) {
    return localeCaches.get(locale)
  }
  const map = new Map()
  if (typeof window !== 'undefined' && window.localStorage) {
    const raw = window.localStorage.getItem(`${STORAGE_PREFIX}:${locale}`)
    if (raw) {
      try {
        const parsed = JSON.parse(raw)
        if (parsed && typeof parsed === 'object') {
          for (const [key, value] of Object.entries(parsed)) {
            if (typeof key === 'string' && typeof value === 'string') {
              map.set(key, value)
            }
          }
        }
      } catch (error) {
        console.warn('Unable to parse cached translations for locale', locale, error)
      }
    }
  }
  localeCaches.set(locale, map)
  return map
}

function schedulePersist(locale) {
  if (typeof window === 'undefined' || !window.localStorage) {
    return
  }
  if (pendingPersists.has(locale)) {
    return
  }
  pendingPersists.add(locale)
  window.setTimeout(() => {
    pendingPersists.delete(locale)
    const cache = getLocaleCache(locale)
    const payload = {}
    let count = 0
    for (const [key, value] of cache) {
      payload[key] = value
      count += 1
      if (count >= MAX_CACHE_ENTRIES) {
        break
      }
    }
    try {
      window.localStorage.setItem(`${STORAGE_PREFIX}:${locale}`, JSON.stringify(payload))
    } catch (error) {
      console.warn('Unable to persist translation cache for locale', locale, error)
    }
  }, 250)
}

function collectTextNodes(root) {
  if (!root) {
    return []
  }
  const nodes = []
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
  let current = walker.nextNode()
  while (current) {
    const parent = current.parentElement
    if (parent && !SKIP_PARENT_TAGS.has(parent.tagName)) {
      const textContent = current.textContent
      if (textContent && /\S/.test(textContent)) {
        nodes.push(current)
      }
    }
    current = walker.nextNode()
  }
  return nodes
}

function ensureNodeRecord(node, locale) {
  let record = nodeRecords.get(node)
  if (!record) {
    record = {
      text: node.textContent ?? '',
      baseLocale: locale,
    }
    nodeRecords.set(node, record)
  }
  return record
}

function shouldTranslate(original) {
  const trimmed = original.trim()
  if (trimmed === '') {
    return false
  }
  if (trimmed.length <= 1) {
    return false
  }
  if (/^[\d\s.,:;!?()\-_/\\]+$/.test(trimmed)) {
    return false
  }
  return true
}

function applySpacing(original, translated) {
  const leadingMatch = original.match(/^\s*/)
  const trailingMatch = original.match(/\s*$/)
  const leading = leadingMatch ? leadingMatch[0] : ''
  const trailing = trailingMatch ? trailingMatch[0] : ''
  return `${leading}${translated}${trailing}`
}

async function requestTranslations(locale, texts) {
  if (texts.length === 0) {
    return []
  }
  const response = await fetch(TRANSLATE_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      source: 'en',
      target: locale,
      texts,
      format: 'text',
    }),
  })
  const payload = await safeParseJson(response)
  if (!response.ok || !payload || payload.ok !== true || !Array.isArray(payload.translations)) {
    throw new Error(
      `Translation request failed for locale ${locale}: ${payload?.error ?? response.statusText}`,
    )
  }
  return payload.translations.map((entry, index) => {
    if (entry && typeof entry === 'object' && typeof entry.translatedText === 'string') {
      return entry.translatedText
    }
    return texts[index] ?? ''
  })
}

async function safeParseJson(response) {
  try {
    return await response.json()
  } catch (error) {
    return null
  }
}

async function translateDocument(locale) {
  if (typeof window === 'undefined' || typeof document === 'undefined') {
    return
  }
  const root = document.body
  const textNodes = collectTextNodes(root)
  if (textNodes.length === 0) {
    return
  }

  if (locale === 'en') {
    textNodes.forEach((node) => {
      const record = ensureNodeRecord(node, locale)
      const current = node.textContent ?? ''
      if (record.baseLocale === 'en') {
        if (current !== record.text) {
          node.textContent = record.text
        }
      } else {
        if (current !== record.text) {
          // Assume current text reflects canonical English copy
          record.text = current
        }
        record.baseLocale = 'en'
      }
    })
    return
  }

  const cache = getLocaleCache(locale)
  const missingSet = new Set()

  textNodes.forEach((node) => {
    const record = ensureNodeRecord(node, locale)
    const base = record.text
    if (!shouldTranslate(base)) {
      return
    }
    if (!cache.has(base)) {
      missingSet.add(base)
    }
  })

  if (missingSet.size > 0) {
    const missing = Array.from(missingSet)
    let index = 0
    let cacheUpdated = false

    while (index < missing.length) {
      const batch = []
      let batchChars = 0

      while (index < missing.length && batch.length < MAX_CHUNK_SIZE) {
        const candidate = missing[index]
        const candidateLength = candidate.length
        if (batchChars + candidateLength > MAX_CHUNK_CHARS && batch.length > 0) {
          break
        }
        batch.push(candidate)
        batchChars += candidateLength
        index += 1
      }

      try {
        const translations = await requestTranslations(locale, batch)
        translations.forEach((translation, offset) => {
      const source = batch[offset]
      if (typeof translation === 'string') {
        cache.set(source, translation)
        cacheUpdated = true
          }
        })
      } catch (error) {
        console.error(error)
        break
      }
    }

    if (cacheUpdated) {
      schedulePersist(locale)
    }
  }

  textNodes.forEach((node) => {
    const record = ensureNodeRecord(node, locale)
    const base = record.text
    if (!shouldTranslate(base)) {
      return
    }
    const translated = cache.get(base)
    if (typeof translated === 'string' && translated !== '') {
      const adjusted = applySpacing(base, translated)
      if (node.textContent !== adjusted) {
        node.textContent = adjusted
      }
    }
  })
}

function scheduleObserver() {
  if (observerScheduled) {
    return
  }
  observerScheduled = true
  requestAnimationFrame(() => {
    observerScheduled = false
    refreshDomTranslations()
  })
}

function attachObserver(locale) {
  if (typeof MutationObserver === 'undefined' || typeof document === 'undefined') {
    return
  }
  if (observer) {
    observer.disconnect()
  }
  observer = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      if (mutation.type === 'childList' || mutation.type === 'characterData') {
        scheduleObserver()
        break
      }
    }
  })
  observer.observe(document.body, {
    childList: true,
    subtree: true,
    characterData: true,
  })
}

async function processLocaleChange(nextLocale) {
  currentLocale = nextLocale
  attachObserver(nextLocale)
  await translateDocument(nextLocale)
}

export function setDomTranslationLocale(locale) {
  translationQueue = translationQueue.then(() => processLocaleChange(locale))
  return translationQueue
}

export function refreshDomTranslations() {
  translationQueue = translationQueue.then(() => translateDocument(currentLocale))
  return translationQueue
}
