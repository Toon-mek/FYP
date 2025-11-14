<script setup>
import { computed } from 'vue'
const props = defineProps({
  brand: {
    type: Object,
    default: () => ({
      name: 'Malaysia Sustainable Travel',
      initials: 'MS',
      title: 'Travel that uplifts Malaysia',
      description:
        'Championing responsible journeys that protect ecosystems and honour local wisdom.',
      logo: null,
    }),
  },
  columns: {
    type: Array,
    default: () => [],
  },
  manifestoLines: {
    type: Array,
    default: () => [
      'Travel kindly, restore the places that welcome you.',
      'Uplift local keepers of culture and wild spaces.',
      'Collect memories, leave only gratitude behind.'
    ],
  },
  socialLinks: {
    type: Array,
    default: () => [
      { label: 'mst@company.com', href: 'mailto:mst@company.com' },
    ],
  },
  socialLabel: {
    type: String,
    default: () => 'Connect',
  },
  copyrightText: {
    type: String,
    default: () => '',
  },
})

const manifestoText = computed(() =>
  Array.isArray(props.manifestoLines) && props.manifestoLines.length
    ? props.manifestoLines.join(' • ')
    : ''
)
</script>

<template>
  <footer class="site-footer">
    <div class="footer-grid">
      <div class="footer-brand">
        <img
          v-if="props.brand.logo"
          class="brand-logo"
          :src="props.brand.logo"
          :alt="props.brand.name"
        />
        <span v-else class="brand-mark">{{ props.brand.initials }}</span>

        <div class="brand-text">
          <p class="brand-name">{{ props.brand.name }}</p>
          <p v-if="props.brand.title" class="brand-tagline">{{ props.brand.title }}</p>
        </div>
      </div>

      <p v-if="manifestoText" class="footer-manifesto single-line">
        {{ manifestoText }}
      </p>
    </div>

    <div class="footer-divider" role="presentation"></div>

    <div class="footer-meta">
      <div v-if="props.socialLinks.length" class="footer-social">
        <span class="social-label">{{ props.socialLabel }}</span>
        <div class="social-links">
          <a v-for="link in props.socialLinks" :key="link.label" :href="link.href">
            {{ link.label }}
          </a>
        </div>
      </div>
      <p class="footer-copy">{{ props.copyrightText }}</p>
    </div>
  </footer>
</template>

<style scoped>
.site-footer {
  width: 100%;
  padding: 1.75rem 2rem;
  border-radius: 18px;
  background: linear-gradient(120deg, #0b2f21, #145c3e);
  color: #f2f7f4;
}

.footer-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 1.5rem;
  justify-content: space-between;
  align-items: center;
}

.footer-brand {
  display: flex;
  gap: 1rem;
  align-items: center;
  min-width: 220px;
}

.brand-logo {
  width: 48px;
  height: 48px;
  border-radius: 14px;
  object-fit: cover;
}

.brand-mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.12);
  font-weight: 700;
  letter-spacing: 0.08em;
  color: #fbd146;
}

.brand-text {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.brand-name {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
  letter-spacing: 0.03em;
}

.brand-tagline {
  margin: 0;
  font-size: 0.95rem;
  color: rgba(242, 247, 244, 0.8);
}

.footer-manifesto {
  flex: 1;
  min-width: 260px;
  display: grid;
  gap: 0.5rem;
  padding-left: 0.25rem;
  margin: 0;
  margin-left: clamp(0.75rem, 4vw, 2.25rem);
  max-width: 920px;
}

.footer-manifesto.single-line {
  display: block;
  margin-left: auto;
  margin-right: auto;
  padding-left: 0;
  text-align: center;
  font-size: 1.08rem;
  font-weight: 700;
  letter-spacing: 0.01em;
  line-height: 1.5;
  color: #fbd146;
}

@supports (-webkit-background-clip: text) {
  .footer-manifesto.single-line {
    background: linear-gradient(90deg, #ffe8a8, #ffd75a);
    -webkit-background-clip: text;
    color: transparent;
  }
}

.footer-divider {
  margin: 1rem 0;
  height: 1px;
  width: 100%;
  background: rgba(255, 255, 255, 0.14);
}


.footer-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  align-items: center;
  justify-content: space-between;
}

.footer-social {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.social-label {
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: rgba(242, 247, 244, 0.8);
}

.social-links {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.social-links a {
  color: rgba(242, 247, 244, 0.9);
  text-decoration: none;
  font-weight: 600;
  font-size: 0.95rem;
  transition: color 0.2s ease;
}

.social-links a:hover {
  color: #fbd146;
}

.footer-copy {
  margin: 0;
  font-size: 0.88rem;
  color: rgba(242, 247, 244, 0.75);
}

@media (max-width: 640px) {
  .site-footer {
    padding: 1.25rem 1.5rem;
    border-radius: 14px;
  }

  .footer-grid {
    flex-direction: column;
    align-items: flex-start;
  }

  .footer-manifesto {
    width: 100%;
    margin-left: 0;
    text-align: left;
  }

  .footer-manifesto.single-line {
    text-align: left;
    font-size: 1rem;
  }

  .footer-meta {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
