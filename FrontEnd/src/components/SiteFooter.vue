<script setup>
const props = defineProps({
  brand: {
    type: Object,
    default: () => ({
      initials: 'MS',
      title: 'Travel that uplifts Malaysia',
      description:
        'Join a community of explorers committed to protecting ecosystems and honouring local wisdom.',
    }),
  },
  columns: {
    type: Array,
    default: () => [
      {
        title: 'Plan your trip',
        links: [
          { label: 'Eco-friendly itineraries', href: '#' },
          { label: 'Responsible tour partners', href: '#' },
          { label: 'Community homestays', href: '#' },
        ],
      },
      {
        title: 'Learn & inspire',
        links: [
          { label: 'Sustainable travel guides', href: '#' },
          { label: 'Wildlife conservation stories', href: '#' },
          { label: 'Climate action toolkit', href: '#' },
        ],
      },
      {
        title: 'Support',
        links: [
          { label: 'About the initiative', href: '#' },
          { label: 'Volunteer programmes', href: '#' },
          { label: 'Contact our team', href: '#' },
        ],
      },
    ],
  },
  socialLinks: {
    type: Array,
    default: () => [
      { label: 'Instagram', href: '#' },
      { label: 'Facebook', href: '#' },
      { label: 'YouTube', href: '#' },
    ],
  },
  copyrightYear: {
    type: Number,
    default: () => new Date().getFullYear(),
  },
})
</script>

<template>
  <footer class="site-footer">
    <div class="footer-top">
      <div class="footer-brand">
        <span class="brand-mark">{{ props.brand.initials }}</span>
        <div>
          <p class="footer-title">{{ props.brand.title }}</p>
          <p class="footer-subtitle">{{ props.brand.description }}</p>
        </div>
      </div>

      <div v-if="props.columns.length" class="footer-links">
        <div v-for="column in props.columns" :key="column.title" class="footer-column">
          <h3>{{ column.title }}</h3>
          <ul>
            <li v-for="link in column.links" :key="link.label">
              <a :href="link.href">{{ link.label }}</a>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <div class="footer-bottom">
      <div v-if="props.socialLinks.length" class="footer-social">
        <span>Connect with us:</span>
        <a v-for="link in props.socialLinks" :key="link.label" :href="link.href">
          {{ link.label }}
        </a>
      </div>
      <p class="footer-copy">© {{ props.copyrightYear }} Malaysia Sustainable Travel. All rights reserved.</p>
    </div>
  </footer>
</template>

<style scoped>
.site-footer {
  display: flex;
  flex-direction: column;
  gap: 2.5rem;
  padding: 3rem 2.5rem;
  border-radius: 32px;
  background: linear-gradient(180deg, #0f3927, #1c6f4f);
  color: #f5f9f7;
  box-shadow: 0 20px 40px rgba(9, 54, 34, 0.2);
}

.footer-top {
  display: flex;
  flex-wrap: wrap;
  gap: 2.5rem;
  justify-content: space-between;
}

.footer-brand {
  display: flex;
  gap: 1rem;
  max-width: 320px;
}

.brand-mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: 14px;
  background: rgba(245, 249, 247, 0.12);
  font-weight: 700;
  letter-spacing: 0.08em;
  color: #fbd146;
}

.footer-title {
  margin: 0 0 0.5rem;
  font-size: 1.4rem;
  font-weight: 600;
}

.footer-subtitle {
  margin: 0;
  color: rgba(245, 249, 247, 0.78);
  line-height: 1.6;
}

.footer-links {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 2rem;
  flex: 1;
}

.footer-column h3 {
  margin: 0 0 1rem;
  font-size: 1.05rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.footer-column ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.75rem;
}

.footer-column a {
  color: rgba(245, 249, 247, 0.85);
  text-decoration: none;
  transition: color 0.2s ease;
}

.footer-column a:hover {
  color: #fbd146;
}

.footer-bottom {
  display: flex;
  flex-wrap: wrap;
  gap: 1.5rem;
  justify-content: space-between;
  align-items: center;
  border-top: 1px solid rgba(245, 249, 247, 0.15);
  padding-top: 1.5rem;
}

.footer-social {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
  align-items: center;
}

.footer-social span {
  font-weight: 600;
}

.footer-social a {
  color: rgba(245, 249, 247, 0.85);
  text-decoration: none;
  font-weight: 500;
  transition: color 0.2s ease;
}

.footer-social a:hover {
  color: #fbd146;
}

.footer-copy {
  margin: 0;
  color: rgba(245, 249, 247, 0.7);
  font-size: 0.9rem;
}

@media (max-width: 720px) {
  .site-footer {
    padding: 2.5rem 2rem;
  }
}
</style>
