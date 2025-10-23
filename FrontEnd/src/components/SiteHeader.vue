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
    }),
  },
  cta: {
    type: Object,
    default: () => ({
      label: 'Start planning',
      href: '#destinations',
    }),
  },
})
</script>

<template>
  <header class="site-header">
    <a class="brand" :href="props.brand.href">
      <span class="brand-mark">{{ props.brand.initials }}</span>
      <span class="brand-text">
        <span class="brand-name">{{ props.brand.name }}</span>
        <span class="brand-tagline">{{ props.brand.tagline }}</span>
      </span>
    </a>

    <nav v-if="props.navLinks.length" class="site-nav" aria-label="Primary navigation">
      <a v-for="link in props.navLinks" :key="link.label" :href="link.href">
        {{ link.label }}
      </a>
    </nav>

    <a v-if="props.cta?.label" class="btn primary header-cta" :href="props.cta.href">
      {{ props.cta.label }}
    </a>
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

.header-cta {
  white-space: nowrap;
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
}
</style>
