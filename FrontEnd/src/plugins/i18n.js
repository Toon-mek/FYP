import { createI18n } from 'vue-i18n'
import en from '../locales/en.json'
import zh from '../locales/zh.json'
import ms from '../locales/ms.json'
import ta from '../locales/ta.json'

export const LOCALE_STORAGE_KEY = 'mst-preferred-locale'
export const supportedLocaleCodes = ['en', 'zh', 'ms', 'ta']

const messages = {
  en,
  zh,
  ms,
  ta,
}

function detectBrowserLocale() {
  if (typeof navigator === 'undefined' || !navigator.language) {
    return null
  }
  const language = navigator.language.toLowerCase()
  const directMatch = supportedLocaleCodes.find((code) => language === code)
  if (directMatch) {
    return directMatch
  }
  const base = language.split('-')[0]
  return supportedLocaleCodes.find((code) => code === base) ?? null
}

function resolveStartingLocale() {
  if (typeof window !== 'undefined' && window.localStorage) {
    const stored = window.localStorage.getItem(LOCALE_STORAGE_KEY)
    if (stored && supportedLocaleCodes.includes(stored)) {
      return stored
    }
  }
  return detectBrowserLocale() ?? 'en'
}

export const i18n = createI18n({
  legacy: false,
  locale: resolveStartingLocale(),
  fallbackLocale: 'en',
  messages,
})

export function persistLocale(localeCode) {
  if (typeof window !== 'undefined' && window.localStorage) {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, localeCode)
  }
}

export function setLocale(localeCode) {
  if (!supportedLocaleCodes.includes(localeCode)) {
    return
  }
  i18n.global.locale.value = localeCode
  persistLocale(localeCode)
}
