<script setup>
const props = defineProps({
  navLinks: {
    type: Array,
    default: () => [
      { label: 'About', href: '#about' },
      { label: 'Destinations', href: '#destinations' },
      { label: 'Tips', href: '#tips' },
      { label: 'Newsletter', href: '#newsletter' },
    ],
  },
  brand: {
    type: Object,
    default: () => ({
      initials: 'MS',
      name: 'Malaysia Sustainable Travel',
      tagline: 'Explore with care',
      href: '#hero',
      logo: null,
    }),
  },
  cta: {
    type: Object,
    default: () => ({
      label: 'Start planning',
      href: '#destinations',
    }),
  },
  secondaryCta: {
    type: Object,
    default: () => null,
  },
  moduleShortcuts: {
    type: Array,
    default: () => [],
  },
  languageOptions: {
    type: Array,
    default: () => [],
  },
  languageLabel: {
    type: String,
    default: () => 'Language',
  },
  currentLocale: {
    type: String,
    default: () => 'en',
  },
  tone: {
    type: String,
    default: () => 'default',
  },
})

const emit = defineEmits([
  'cta-click',
  'secondary-cta-click',
  'brand-click',
  'nav-click',
  'locale-change',
  'module-shortcut-click',
])
</script>

<template>
  <header :class="['site-header', props.tone ? `site-header--${props.tone}` : '']">
    <div class="site-header__container">
      <a class="brand" :href="props.brand.href" @click.prevent="emit('brand-click')">
        <img
          v-if="props.brand.logo"
          class="brand-logo"
          :src="props.brand.logo"
          :alt="props.brand.name"
        />
        <span v-else class="brand-mark">{{ props.brand.initials }}</span>
        <span class="brand-text">
          <span class="brand-name">{{ props.brand.name }}</span>
          <span class="brand-tagline">{{ props.brand.tagline }}</span>
        </span>
      </a>

      <div class="site-header__cluster">
        <nav v-if="props.navLinks.length" class="site-nav" aria-label="Primary navigation">
          <a v-for="link in props.navLinks" :key="link.label" :href="link.href"
            @click.prevent="emit('nav-click', link.href)">
            {{ link.label }}
          </a>
        </nav>

        <div v-if="props.moduleShortcuts.length" class="header-module-breadcrumb">
          <button
            v-for="(shortcut, index) in props.moduleShortcuts"
            :key="shortcut.key || shortcut.label"
            type="button"
            class="header-module-breadcrumb__btn"
            :class="{ 'is-last': index === props.moduleShortcuts.length - 1 }"
            @click="emit('module-shortcut-click', shortcut.key ?? shortcut.label)"
          >
            {{ shortcut.label }}
          </button>
        </div>

        <div class="header-actions">
          <div v-if="props.languageOptions.length" class="language-selector">
            <label class="language-label" for="site-language-picker">{{ props.languageLabel }}</label>
            <select
              id="site-language-picker"
              class="language-select"
              :value="props.currentLocale"
              @change="emit('locale-change', $event.target.value)"
            >
              <option v-for="option in props.languageOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </div>

        <component
          :is="props.secondaryCta?.href ? 'a' : 'button'"
            v-if="props.secondaryCta?.label"
            :key="props.secondaryCta?.key || props.secondaryCta?.label"
            class="btn outline header-cta"
            :href="props.secondaryCta?.href"
            type="button"
            @click.prevent="props.secondaryCta?.href ? null : emit('secondary-cta-click')"
          >
            {{ props.secondaryCta.label }}
          </component>

          <component
            :is="props.cta?.href ? 'a' : 'button'"
            v-if="props.cta?.label"
            :key="props.cta?.key || props.cta?.label"
            class="btn primary header-cta"
            :href="props.cta?.href"
            type="button"
            @click.prevent="props.cta?.href ? null : emit('cta-click')"
          >
            {{ props.cta.label }}
          </component>
        </div>
      </div>
    </div>
  </header>
</template>

<style scoped>
.site-header {
  width: 100%;
  z-index: 10;
  display: flex;
  justify-content: center;
  padding: 1.15rem;
  border-radius: 36px;
  background: linear-gradient(120deg, #dceee4, #d1e7df);
  border: 1px solid rgba(17, 94, 64, 0.08);
  box-shadow: 0 20px 44px rgba(17, 82, 56, 0.12);
}

.site-header--traveler {
  background: linear-gradient(120deg, #d4eafb, #d4f4ea);
  border-color: rgba(42, 108, 142, 0.12);
  box-shadow: 0 24px 52px rgba(24, 94, 122, 0.14);
}

.site-header--operator {
  background: linear-gradient(120deg, #d9f1e6, #f5ead3);
  border-color: rgba(40, 117, 87, 0.12);
  box-shadow: 0 24px 54px rgba(31, 90, 66, 0.16);
}

.site-header--home {
  background: linear-gradient(120deg, #f4edff, #e5f6ff);
  border-color: rgba(91, 112, 189, 0.12);
  box-shadow: 0 24px 52px rgba(72, 94, 149, 0.14);
}

.site-header--login {
  background: radial-gradient(circle at 10% 20%, #fff1e5, transparent 55%),
    radial-gradient(circle at 90% 10%, #e0f2ff, transparent 50%),
    linear-gradient(120deg, #ffe8f3, #e7f5ff);
  border: 1px solid rgba(137, 94, 60, 0.12);
  box-shadow: 0 26px 48px rgba(120, 104, 136, 0.18);
}

.site-header__container {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1.25rem;
}

.brand {
  display: inline-flex;
  align-items: center;
  gap: 0.85rem;
  padding: 0.35rem 0.35rem 0.35rem 0;
  text-decoration: none;
  color: inherit;
  border-radius: 999px;
  box-shadow: none;
  border: none;
  background: transparent;
}

.site-header--traveler .brand,
.site-header--operator .brand {
  box-shadow: none;
  border: none;
}

.brand-logo {
  width: 52px;
  height: 52px;
  border-radius: 18px;
  object-fit: cover;
  box-shadow: 0 10px 18px rgba(15, 59, 39, 0.25);
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.3);
}

.brand-mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: 14px;
  background: linear-gradient(135deg, #0f3927, #1c6f4f);
  color: #f5f9f7;
  font-weight: 700;
  letter-spacing: 0.08em;
}

.brand-text {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.brand-name {
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  font-size: 0.9rem;
  color: #0b3b26;
}

.brand-tagline {
  font-size: 0.85rem;
  color: #5b7c67;
}

.site-header__cluster {
  display: flex;
  align-items: center;
  gap: 1.1rem;
  padding: 0.7rem 1.25rem;
  background: rgba(255, 255, 255, 0.94);
  border-radius: 999px;
  box-shadow: 0 16px 32px rgba(15, 59, 39, 0.1);
  border: 1px solid rgba(15, 59, 39, 0.08);
}

.site-header--traveler .site-header__cluster {
  border-color: rgba(34, 112, 149, 0.12);
  box-shadow: 0 18px 36px rgba(26, 91, 125, 0.14);
}

.site-header--operator .site-header__cluster {
  border-color: rgba(33, 109, 79, 0.12);
  box-shadow: 0 18px 36px rgba(24, 85, 61, 0.15);
}

.site-header--home .site-header__cluster {
  border-color: rgba(86, 106, 176, 0.14);
  box-shadow: 0 18px 36px rgba(67, 88, 148, 0.16);
}

.site-header--login .site-header__cluster {
  border-color: rgba(143, 122, 178, 0.18);
  background: rgba(255, 255, 255, 0.95);
  box-shadow: 0 20px 36px rgba(130, 112, 152, 0.18);
}

.site-nav {
  display: inline-flex;
  align-items: center;
  gap: 1.1rem;
  flex-wrap: wrap;
}

.site-nav a {
  font-weight: 600;
  font-size: 0.95rem;
  color: #1b4c34;
  text-decoration: none;
  transition: color 0.2s ease;
}

.site-nav a:hover {
  color: #0f3927;
}

.site-header--traveler .site-nav a {
  color: #0f4b73;
}

.site-header--traveler .site-nav a:hover {
  color: #093552;
}

.site-header--operator .site-nav a {
  color: #134f37;
}

.site-header--operator .site-nav a:hover {
  color: #0c3725;
}

.site-header--home .site-nav {
  padding: 0 0.9rem;
  gap: 0;
  align-items: center;
  flex-wrap: nowrap;
}

.site-header--home .site-nav a {
  color: #0f4c33;
  position: relative;
  display: inline-flex;
  align-items: center;
  padding: 0.15rem 1rem;
  margin-right: 0;
  font-family: Arial, sans-serif;
  font-size: 0.9rem;
  font-weight: 700;
  line-height: 1.3;
  text-transform: none;
  text-decoration: none;
}

.site-header--home .site-nav a:last-child {
  padding-right: 0;
}

.site-header--home .site-nav a::before {
  content: '';
  position: absolute;
  left: 0;
  bottom: -4px;
  height: 2px;
  width: 100%;
  background: #0f4c33;
  border-radius: 999px;
  transform: scaleX(0);
  transform-origin: left;
  opacity: 0;
  transition: transform 0.2s ease, opacity 0.2s ease;
}

.site-header--home .site-nav a:hover {
  color: #0c3423;
}

.site-header--home .site-nav a:hover::before {
  opacity: 1;
  transform: scaleX(1);
}

.site-header--home .site-nav a:not(:last-child)::after {
  content: '';
  position: absolute;
  right: 0;
  top: 50%;
  transform: translateY(-50%);
  width: 1px;
  height: 1.35rem;
  background: rgba(15, 59, 39, 0.35);
  pointer-events: none;
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding-left: 0.9rem;
  border-left: 1px solid rgba(15, 59, 39, 0.08);
}

.site-header__cluster > .header-actions:first-child {
  border-left: none;
  padding-left: 0;
}

.header-cta {
  white-space: nowrap;
  border-radius: 99px;
  padding: 0.35rem 1.1rem;
  font-weight: 550;
  box-shadow: 0 8px 16px rgba(0, 0, 0, 0.13);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.header-cta.btn.outline {
  background: linear-gradient(120deg, rgba(255, 255, 255, 0.9), rgba(230, 233, 235, 0.9));
  border: none;
  color: #111c24;
}

.header-cta.btn.outline:hover {
  transform: translateY(-1px);
  box-shadow: 0 10px 18px rgba(0, 0, 0, 0.12);
}

.site-header--login .header-cta.btn.primary {
  background: linear-gradient(120deg, #ffd977, #ffb347);
  color: #3b2a0a;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4), 0 12px 20px rgba(255, 183, 71, 0.35);
}

.site-header--login .header-cta.btn.primary:hover {
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4), 0 14px 22px rgba(255, 183, 71, 0.45);
}

.language-selector {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.language-label {
  font-size: 0.85rem;
  color: #5b7c67;
}

.site-header--traveler .language-label {
  color: #0f4d74;
}

.site-header--operator .language-label {
  color: #1a5139;
}

.site-header--home .language-label {
  color: #314263;
}

.site-header--login .language-label {
  color: #5b577b;
}

.language-select {
  border-radius: 999px;
  border: 1px solid #cfd9d3;
  background: rgba(255, 255, 255, 0.9);
  padding: 0.35rem 0.75rem;
  font-size: 0.85rem;
  color: #0b3b26;
  cursor: pointer;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.language-select:focus {
  outline: none;
  border-color: #1c6f4f;
  box-shadow: 0 0 0 2px rgba(28, 111, 79, 0.18);
}

.header-module-breadcrumb {
  display: inline-flex;
  align-items: center;
  gap: 1rem;
  padding: 0 0.6rem;
  font-size: 1rem;
}

.header-module-breadcrumb__btn {
  border: none;
  background: transparent;
  padding: 0;
  font-size: 0.9rem;
  font-weight: 600;
  color: #0f4c33;
  cursor: pointer;
  position: relative;
}

.header-module-breadcrumb__btn:not(:last-child)::after {
  content: '|';
  color: rgba(15, 59, 39, 0.4);
  position: absolute;
  right: -0.5rem;
  top: 50%;
  transform: translateY(-50%);
  font-weight: 400;
  pointer-events: none;
}

.header-module-breadcrumb__btn::before {
  content: '';
  position: absolute;
  bottom: -6px;
  left: 0;
  height: 2px;
  width: 100%;
  background: #0c5235;
  transform: scaleX(0);
  transform-origin: left;
  transition: transform 0.3s ease;
}

.header-module-breadcrumb__btn:hover::before {
  transform: scaleX(1);
}

.site-header--traveler .language-select:focus {
  border-color: #1b6a9e;
  box-shadow: 0 0 0 2px rgba(27, 106, 158, 0.18);
}

.site-header--operator .language-select:focus {
  border-color: #1d6f4f;
  box-shadow: 0 0 0 2px rgba(29, 111, 79, 0.18);
}

.site-header--home .language-select:focus {
  border-color: #4b64a5;
  box-shadow: 0 0 0 2px rgba(75, 100, 165, 0.18);
}

.site-header--login .language-select:focus {
  border-color: #a96237;
  box-shadow: 0 0 0 2px rgba(169, 98, 55, 0.2);
}

@media (max-width: 720px) {
  .site-header {
    flex-wrap: wrap;
    justify-content: center;
  }

  .site-header__container {
    flex-direction: column;
    gap: 1rem;
  }

  .site-header__cluster {
    flex-direction: column;
    width: 100%;
    gap: 0.9rem;
  }

  .site-nav {
    justify-content: center;
  }

  .header-actions {
    justify-content: center;
    border-left: none;
    border-top: 1px solid rgba(15, 59, 39, 0.08);
    padding: 0.85rem 0 0 0;
  }

  .language-selector {
    flex-wrap: wrap;
    justify-content: center;
  }
}
</style>
