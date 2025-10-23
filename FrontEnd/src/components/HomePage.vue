<script setup>
const highlights = [
  {
    title: 'Eco-certified stays',
    description:
      'Stay in accommodations that prioritize energy efficiency, water conservation, and positive community impact.',
  },
  {
    title: 'Low-impact adventures',
    description:
      'Choose guided experiences that protect fragile ecosystems while showcasing Malaysia\'s natural wonders.',
  },
  {
    title: 'Support local communities',
    description:
      'Spend with intention to preserve cultural heritage and uplift indigenous storytellers and artisans.',
  },
]

const destinations = [
  {
    name: 'Perhentian Islands',
    location: 'Terengganu',
    description:
      'Crystal-clear waters, thriving coral gardens, and marine conservation projects led by local NGOs.',
    tags: ['Marine sanctuary', 'Community tours', 'No-plastic pledge'],
  },
  {
    name: 'Belum Rainforest Reserve',
    location: 'Perak',
    description:
      'Ancient rainforest teeming with wildlife, eco-lodges, and guided treks with Orang Asli naturalists.',
    tags: ['Rainforest', 'Wildlife watching', 'Indigenous guides'],
  },
  {
    name: 'Kota Kinabalu Wetlands',
    location: 'Sabah',
    description:
      'Urban mangrove haven offering boardwalk trails and birdwatching while funding habitat restoration.',
    tags: ['Mangrove trails', 'Birding', 'Restoration'],
  },
]

const travelTips = [
  'Pack reef-safe sunscreen and reusable essentials for plastic-free adventures.',
  'Offset your carbon footprint by supporting Malaysian reforestation programmes.',
  'Respect cultural norms and learn simple Malay phrases to connect with hosts.',
  'Book with tour operators certified by Travelife or the Global Sustainable Tourism Council.',
]

</script>

<template>
  <main class="home">
    <section class="hero" id="hero">
      <div class="hero-surface">
        <p class="hero-tag">Discover Malaysia responsibly</p>
        <h1>Malaysia Sustainable Travel</h1>
        <p class="hero-subtitle">
          Craft eco-forward journeys that celebrate biodiversity, protect cultural heritage, and leave a gentle footprint.
        </p>
        <div class="hero-actions">
          <a class="btn primary" href="#destinations">Explore destinations</a>
          <a class="btn ghost" href="#tips">Learn sustainable tips</a>
        </div>
      </div>
    </section>

    <section class="panel highlights" id="about">
      <header class="panel-header">
        <h2>Why travel sustainably in Malaysia?</h2>
        <p>
          Sustainable choices protect lush rainforests, coral reefs, and vibrant cultures that make Malaysia extraordinary.
        </p>
      </header>
      <div class="grid">
        <article v-for="highlight in highlights" :key="highlight.title" class="card">
          <h3>{{ highlight.title }}</h3>
          <p>{{ highlight.description }}</p>
        </article>
      </div>
    </section>

    <section class="panel destinations" id="destinations">
      <header class="panel-header">
        <h2>Featured eco-destinations</h2>
        <p>Start with curated escapes that meet conservation goals and uplift local champions.</p>
      </header>
      <div class="grid">
        <article v-for="destination in destinations" :key="destination.name" class="destination-card">
          <div class="destination-header">
            <h3>{{ destination.name }}</h3>
            <span>{{ destination.location }}</span>
          </div>
          <p>{{ destination.description }}</p>
          <ul class="tag-list">
            <li v-for="tag in destination.tags" :key="tag">{{ tag }}</li>
          </ul>
        </article>
      </div>
    </section>

    <section class="panel tips" id="tips">
      <div class="tips-layout">
        <div>
          <p class="panel-kicker">Responsible travel checklist</p>
          <h2>Small changes, lasting impact</h2>
          <p>
            Empower your itinerary with mindful habits that protect wildlife, steward resources, and amplify local voices.
          </p>
        </div>
        <ul class="tips-list">
          <li v-for="tip in travelTips" :key="tip">{{ tip }}</li>
        </ul>
      </div>
    </section>

    <section class="panel cta" id="newsletter">
      <div class="cta-card">
        <h2>Plan your sustainable escape</h2>
        <p>
          Be the first to know about new eco-stays, volunteer projects, and slow travel itineraries across Malaysia.
        </p>
        <form class="cta-form" novalidate>
          <label class="sr-only" for="cta-email">Email address</label>
          <input id="cta-email" type="email" placeholder="Enter your email" />
          <button type="submit" class="btn primary">Notify me</button>
        </form>
        <p class="fine-print">We'll share only trip ideas and meaningful impact stories - no spam, ever.</p>
      </div>
    </section>
  </main>
</template>

<style scoped>
.home {
  display: flex;
  flex-direction: column;
  gap: 4rem;
  padding: 0 1.5rem 4rem;
}

.hero {
  position: relative;
  padding: 8rem 1.5rem;
  border-radius: 32px;
  background: radial-gradient(circle at top left, #1c6f4f, #0f3e2c);
  color: #f4f9f6;
  overflow: hidden;
}

.hero::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, rgba(9, 34, 22, 0.15), rgba(12, 91, 63, 0.6));
  mix-blend-mode: screen;
  pointer-events: none;
}

.hero-surface {
  position: relative;
  max-width: 60rem;
  z-index: 1;
}

.hero-tag {
  display: inline-block;
  padding: 0.35rem 0.75rem;
  border-radius: 999px;
  background: rgba(244, 249, 246, 0.12);
  font-size: 0.95rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  margin-bottom: 1rem;
}

.hero h1 {
  font-size: clamp(2.75rem, 4vw, 4.25rem);
  margin: 0 0 1rem;
  line-height: 1.05;
}

.hero-subtitle {
  font-size: 1.125rem;
  max-width: 40rem;
  margin: 0 0 2rem;
  color: rgba(244, 249, 246, 0.86);
}

.hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 2rem;
}

.panel-header {
  max-width: 44rem;
}

.panel-header h2 {
  margin: 0 0 0.75rem;
  font-size: clamp(2rem, 3vw, 3rem);
  color: #093622;
}

.panel-header p {
  margin: 0;
  color: #43604d;
  font-size: 1.05rem;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1.5rem;
}

.card {
  padding: 1.75rem;
  border-radius: 20px;
  background: #f5f9f7;
  border: 1px solid #e0eee6;
  box-shadow: 0 12px 24px rgba(12, 77, 47, 0.08);
}

.card h3 {
  margin: 0 0 0.75rem;
  font-size: 1.35rem;
  color: #0d5133;
}

.card p {
  margin: 0;
  color: #3f5b4a;
}

.destinations .destination-card {
  padding: 2rem;
  background: linear-gradient(135deg, #fefcf6, #f0f7f2);
  border-radius: 24px;
  border: 1px solid #e6efe9;
  box-shadow: 0 16px 32px rgba(9, 54, 34, 0.08);
}

.destination-header {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: baseline;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.destination-card h3 {
  margin: 0;
  font-size: 1.5rem;
  color: #0b3b26;
}

.destination-card span {
  font-size: 0.95rem;
  font-weight: 600;
  color: #5b7c67;
}

.destination-card p {
  margin: 0 0 1.25rem;
  color: #3a5747;
}

.tag-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  list-style: none;
  padding: 0;
  margin: 0;
}

.tag-list li {
  padding: 0.4rem 0.8rem;
  border-radius: 999px;
  background: rgba(11, 59, 38, 0.1);
  color: #0b3b26;
  font-size: 0.85rem;
  font-weight: 600;
}

.tips-layout {
  display: grid;
  gap: 2.5rem;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  padding: 2.5rem;
  border-radius: 28px;
  background: linear-gradient(135deg, #0f3927, #1c6f4f);
  color: #f3faf5;
}

.panel-kicker {
  text-transform: uppercase;
  letter-spacing: 0.16em;
  font-size: 0.9rem;
  margin: 0 0 1rem;
  color: rgba(243, 250, 245, 0.7);
}

.tips-layout h2 {
  margin: 0 0 1rem;
  font-size: 2.25rem;
}

.tips-layout p {
  margin: 0;
  color: rgba(243, 250, 245, 0.82);
}

.tips-list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 1rem;
}

.tips-list li {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
  padding: 1rem 1.25rem;
  border-radius: 16px;
  background: rgba(243, 250, 245, 0.08);
  color: rgba(243, 250, 245, 0.95);
  line-height: 1.5;
}

.tips-list li::before {
  content: '>';
  flex-shrink: 0;
  font-weight: 700;
  color: #fbd146;
  margin-top: 0.2rem;
}

.cta {
  padding-bottom: 2rem;
}

.cta-card {
  padding: 3rem 2.5rem;
  border-radius: 28px;
  background: #ffffff;
  border: 1px solid #e4ede8;
  box-shadow: 0 18px 36px rgba(9, 54, 34, 0.12);
  text-align: center;
}

.cta-card h2 {
  margin: 0 0 0.75rem;
  font-size: clamp(2rem, 3vw, 2.75rem);
  color: #0b3b26;
}

.cta-card p {
  margin: 0 0 1.5rem;
  color: #41604d;
  font-size: 1.05rem;
}

.cta-form {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.cta-form input {
  width: min(320px, 100%);
  padding: 0.85rem 1rem;
  border-radius: 999px;
  border: 1px solid #c9dcd1;
  font-size: 1rem;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.cta-form input:focus {
  outline: none;
  border-color: #1c6f4f;
  box-shadow: 0 0 0 3px rgba(28, 111, 79, 0.25);
}

.fine-print {
  margin: 0;
  font-size: 0.9rem;
  color: #607b6a;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  border: 0;
}

@media (max-width: 720px) {
  .hero {
    padding: 6rem 1.25rem;
  }

  .hero-actions {
    flex-direction: column;
    align-items: stretch;
  }

  .cta-card {
    padding: 2.25rem 1.75rem;
  }
}
</style>
