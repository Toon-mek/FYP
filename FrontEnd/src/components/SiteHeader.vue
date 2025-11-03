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
  theme: {
    type: String,
    default: () => 'light',
  },
  themeToggleLabel: {
    type: String,
    default: () => 'Toggle theme',
  },
})

const emit = defineEmits(['cta-click', 'secondary-cta-click', 'brand-click', 'nav-click', 'locale-change', 'theme-toggle'])
</script>

<template>
  <header class="site-header">
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

    <nav v-if="props.navLinks.length" class="site-nav" aria-label="Primary navigation">
      <a v-for="link in props.navLinks" :key="link.label" :href="link.href"
        @click.prevent="emit('nav-click', link.href)">
        {{ link.label }}
      </a>
    </nav>

    <div class="header-actions">
      <button
        type="button"
        class="btn outline header-cta theme-toggle"
        @click="emit('theme-toggle')"
      >
        <span class="theme-toggle__label">{{ props.themeToggleLabel }}</span>
      </button>

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
        class="btn primary header-cta"
        :href="props.cta?.href"
        type="button"
        @click.prevent="props.cta?.href ? null : emit('cta-click')"
      >
        {{ props.cta.label }}
      </component>
    </div>
  </header>
</template>

<style scoped>
.site-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1.5rem;
  padding: 1.25rem 1.75rem;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.9);
  box-shadow: 0 16px 32px rgba(9, 54, 34, 0.08);
  backdrop-filter: blur(8px);
}

.brand {
  display: inline-flex;
  align-items: center;
  gap: 0.75rem;
  text-decoration: none;
  color: inherit;
}

.brand-logo {
  width: 48px;
  height: 48px;
  border-radius: 16px;
  object-fit: cover;
  box-shadow: 0 6px 14px rgba(15, 59, 39, 0.2);
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
  gap: 0.15rem;
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

.site-nav {
  display: flex;
  align-items: center;
  gap: 1.5rem;
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

.header-actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.header-cta {
  white-space: nowrap;
}

.theme-toggle {
  gap: 0.4rem;
  padding-inline: 1.25rem;
}

.theme-toggle__label {
  font-size: 0.9rem;
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
  box-shadow: 0 0 0 2px rgba(28, 111, 79, 0.2);
}

@media (max-width: 720px) {
  .site-header {
    flex-wrap: wrap;
    justify-content: center;
    padding: 1.25rem;
    text-align: center;
  }

  .site-nav {
    justify-content: center;
  }

  .header-actions {
    justify-content: center;
  }

  .language-selector {
    flex-wrap: wrap;
    justify-content: center;
  }
}
</style>
