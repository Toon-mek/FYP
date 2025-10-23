<script setup>
import HomePage from './components/HomePage.vue'
import SiteHeader from './components/SiteHeader.vue'
import SiteFooter from './components/SiteFooter.vue'

const navLinks = [
  { label: 'About', href: '#about' },
  { label: 'Destinations', href: '#destinations' },
  { label: 'Tips', href: '#tips' },
  { label: 'Newsletter', href: '#newsletter' },
]

const headerBrand = {
  initials: 'MS',
  name: 'Malaysia Sustainable Travel',
  tagline: 'Explore with care',
  href: '#hero',
}

const headerCta = {
  label: 'Start planning',
  href: '#destinations',
}

const footerBrand = {
  initials: 'MS',
  title: 'Travel that uplifts Malaysia',
  description:
    'Join a community of explorers committed to protecting ecosystems and honouring local wisdom.',
}

const footerColumns = [
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
]

const socialLinks = [
  { label: 'Instagram', href: '#' },
  { label: 'Facebook', href: '#' },
  { label: 'YouTube', href: '#' },
]

const copyrightYear = new Date().getFullYear()
</script>

<template>
  <div class="page">
    <SiteHeader :nav-links="navLinks" :brand="headerBrand" :cta="headerCta" />
    <HomePage />
    <SiteFooter
      :brand="footerBrand"
      :columns="footerColumns"
      :social-links="socialLinks"
      :copyright-year="copyrightYear"
    />
  </div>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  gap: 3.5rem;
}
</style>
