<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
  traveler: {
    type: Object,
    default: () => null,
  },
})

const sidebarItems = [
  { label: 'Destinations', icon: 'ri-compass-3-line', active: true },
  { label: 'Messages', icon: 'ri-chat-3-line', disabled: true },
  { label: 'Notifications', icon: 'ri-notification-3-line', disabled: true },
  { label: 'Saved places', icon: 'ri-heart-line', disabled: true },
  { label: 'Account', icon: 'ri-user-3-line', disabled: true },
  { label: 'Settings', icon: 'ri-settings-5-line', disabled: true },
]

const defaultTraveler = {
  name: 'Traveler Name',
  destinations: 127,
  avatarInitials: 'TN',
}

const traveler = computed(() => {
  const override = props.traveler ?? {}
  const displayName = override.fullName || override.username || defaultTraveler.name
  const initials =
    override.avatarInitials ||
    displayName
      .split(/\s+/)
      .map((part) => part[0])
      .join('')
      .slice(0, 2)
      .toUpperCase()

  return {
    ...defaultTraveler,
    ...override,
    name: displayName,
    avatarInitials: initials || defaultTraveler.avatarInitials,
  }
})

const destinationTabs = ['Most Popular', 'Best Price', 'Near Me']
const activeDestinationTab = ref(destinationTabs[0])

const destinationCards = [
  {
    title: 'Perhentian Islands',
    location: 'Terengganu',
    days: '3-5 days',
    rating: 4.8,
    image: 'https://images.unsplash.com/photo-1526481280695-3c46973cffa3?auto=format&fit=crop&w=600&q=80',
  },
  {
    title: 'Belum Rainforest',
    location: 'Perak',
    days: '5-7 days',
    rating: 4.7,
    image: 'https://images.unsplash.com/photo-1501785888041-af3ef285b470?auto=format&fit=crop&w=600&q=80',
  },
  {
    title: 'Kota Kinabalu',
    location: 'Sabah',
    days: '2-4 days',
    rating: 4.6,
    image: 'https://images.unsplash.com/photo-1522906456132-bac22adad33f?auto=format&fit=crop&w=600&q=80',
  },
]

const calendarWeeks = [
  ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
  ['1', '2', '3', '4', '5', '6', '7'],
  ['8', '9', '10', '11', '12', '13', '14'],
  ['15', '16', '17', '18', '19', '20', '21'],
  ['22', '23', '24', '25', '26', '27', '28'],
  ['29', '30', '31', '', '', '', ''],
]

const upcoming = [
  { date: '06', label: 'Mangrove kayak tour', location: 'Langkawi', days: '1 day' },
  { date: '14', label: 'Wildlife spotting', location: 'Taman Negara', days: '2 days' },
  { date: '22', label: 'Reef volunteering', location: 'Sipadan', days: '4 days' },
]

const bestResorts = [
  { name: 'Heritage Rainforest Lodge', location: 'Pahang', price: 'RM420 / night' },
  { name: 'Eco Reef Villas', location: 'Sabah', price: 'RM380 / night' },
  { name: 'Highland Farmstay', location: 'Cameron Highlands', price: 'RM290 / night' },
]

const transports = [
  { icon: 'ri-plane-line', label: 'Flights' },
  { icon: 'ri-train-line', label: 'Rail' },
  { icon: 'ri-bus-2-line', label: 'Bus' },
  { icon: 'ri-sailboat-line', label: 'Ferry' },
]

const friends = [
  'https://randomuser.me/api/portraits/women/1.jpg',
  'https://randomuser.me/api/portraits/men/11.jpg',
  'https://randomuser.me/api/portraits/women/12.jpg',
  'https://randomuser.me/api/portraits/men/15.jpg',
]

const integrations = ['Slack', 'Asana', 'Dropbox']
</script>

<template>
  <div class="dashboard-shell">
    <div class="dashboard">
      <aside class="sidebar">
        <div class="brand">
          <div class="brand-icon">✈️</div>
          <n-text strong class="brand-name">Tourvista</n-text>
        </div>
        <n-space vertical size="small" class="menu">
          <n-button v-for="item in sidebarItems" :key="item.label" block quaternary class="menu-button"
            :class="{ active: item.active }" :disabled="item.disabled">
            <n-icon size="20">
              <i :class="item.icon" />
            </n-icon>
            <span>{{ item.label }}</span>
          </n-button>
        </n-space>
      </aside>

      <main class="main">
        <section class="greeting" id="traveler-top">
          <div class="hello-wrap">
            <n-text class="hello">Hello, {{ traveler.name }}</n-text>
            <n-text depth="3">Welcome back!</n-text>
          </div>
          <div class="greeting-actions">
            <n-input round placeholder="Search destinations, guides..." size="large" class="search">
              <template #prefix>
                <n-icon size="18"><i class="ri-search-line" /></n-icon>
              </template>
            </n-input>
            <n-button circle quaternary><n-icon size="18"><i class="ri-notification-2-line" /></n-icon></n-button>
            <n-button circle quaternary><n-icon size="18"><i class="ri-settings-3-line" /></n-icon></n-button>
          </div>
        </section>

        <section class="destinations" id="destinations">
          <div class="section-header">
            <n-text strong>Hotels</n-text>
            <div class="tab-group">
              <n-button v-for="tab in destinationTabs" :key="tab" size="small" round quaternary
                :class="{ 'tab-active': activeDestinationTab === tab }" @click="activeDestinationTab = tab">
                {{ tab }}
              </n-button>
            </div>
            <n-button text type="primary" size="small">See all</n-button>
          </div>
          <div class="destination-grid">
            <n-card v-for="card in destinationCards" :key="card.title" class="destination-card" size="small" hoverable>
              <img :src="card.image" :alt="card.title" />
              <n-space vertical size="small">
                <n-text strong>{{ card.title }}</n-text>
                <n-text depth="3">{{ card.location }}</n-text>
                <n-space align="center" size="small" justify="space-between">
                  <n-tag round type="info" size="small">{{ card.days }}</n-tag>
                  <n-tag round type="success" size="small">{{ card.rating }} ★</n-tag>
                </n-space>
              </n-space>
            </n-card>
          </div>
        </section>

        <section class="middle-panels">
          <n-card class="calendar" id="calendar-panel" size="small">
            <div class="section-header section-header--sub">
              <n-text strong>Available dates</n-text>
              <n-tag round type="info">January 2025</n-tag>
            </div>
            <div class="calendar-grid">
              <div v-for="(week, weekIndex) in calendarWeeks" :key="weekIndex" class="calendar-row">
                <span v-for="day in week" :key="day + weekIndex"
                  :class="['calendar-cell', { 'calendar-cell--muted': !day, 'calendar-cell--active': day === '06' || day === '19' }]">
                  {{ day }}
                </span>
              </div>
            </div>
          </n-card>

          <n-card class="resorts" id="resorts-panel" size="small">
            <div class="section-header section-header--sub">
              <n-text strong>Best resorts</n-text>
            </div>
            <n-list>
              <n-list-item v-for="resort in bestResorts" :key="resort.name">
                <n-space justify="space-between" align="center" style="width: 100%">
                  <div>
                    <n-text strong>{{ resort.name }}</n-text>
                    <n-text depth="3">{{ resort.location }}</n-text>
                  </div>
                  <n-text type="success">{{ resort.price }}</n-text>
                </n-space>
              </n-list-item>
            </n-list>
          </n-card>

          <n-card class="upcoming" id="upcoming-panel" size="small">
            <div class="section-header section-header--sub">
              <n-text strong>Upcoming trips</n-text>
            </div>
            <n-list>
              <n-list-item v-for="item in upcoming" :key="item.label">
                <n-space align="center" justify="space-between" style="width: 100%">
                  <n-space align="center" size="medium">
                    <div class="pill-date">{{ item.date }}</div>
                    <div>
                      <n-text strong>{{ item.label }}</n-text>
                      <n-text depth="3">{{ item.location }} · {{ item.days }}</n-text>
                    </div>
                  </n-space>
                  <n-button text type="primary" size="small">Details</n-button>
                </n-space>
              </n-list-item>
            </n-list>
          </n-card>
        </section>
      </main>

      <aside class="rightbar">
        <n-card class="profile-card" size="small" :bordered="false">
          <n-space vertical align="center" size="small">
            <n-avatar round size="large">{{ traveler.avatarInitials }}</n-avatar>
            <n-text strong>{{ traveler.name }}</n-text>
            <n-text depth="3">{{ traveler.destinations }} destinations</n-text>
            <n-button type="primary" ghost round size="small">Edit profile</n-button>
          </n-space>
        </n-card>

        <n-card class="friends-card" size="small">
          <n-text strong>Friends</n-text>
          <n-space size="small" align="center">
            <n-avatar-group size="large">
              <n-avatar v-for="friend in friends" :key="friend" round :src="friend" />
            </n-avatar-group>
            <n-button circle quaternary><n-icon size="18"><i class="ri-add-line" /></n-icon></n-button>
          </n-space>
        </n-card>

        <n-card class="integrations-card" size="small">
          <n-text strong>Integrations</n-text>
          <n-space vertical size="small">
            <n-tag v-for="integration in integrations" :key="integration" type="success" round strong>
              {{ integration }}
            </n-tag>
          </n-space>
        </n-card>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.dashboard-shell {
  background: radial-gradient(circle at 20% 20%, #f3ecff, transparent 55%), radial-gradient(circle at 80% -10%, #e4f4ed, transparent 60%);
  padding: 2.5rem 0 4rem;
}

.dashboard {
  max-width: 1280px;
  margin: 0 auto;
  display: grid;
  grid-template-columns: 240px minmax(0, 1fr) 260px;
  gap: 2rem;
  padding: 0 1.5rem;
}

.sidebar,
.rightbar {
  display: flex;
  flex-direction: column;
  gap: 1.75rem;
}

.sidebar {
  border-radius: 28px;
  padding: 2rem 1.5rem;
  background: linear-gradient(200deg, #f2f0ff 0%, #f6fbf8 45%, #ffffff 100%);
  box-shadow: 0 24px 48px rgba(79, 70, 255, 0.12);
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  font-size: 1.15rem;
  margin-bottom: 1.75rem;
}

.brand-icon {
  width: 48px;
  height: 48px;
  border-radius: 16px;
  display: grid;
  place-items: center;
  background: linear-gradient(135deg, #6c63ff, #42b883);
  color: #fff;
  font-size: 20px;
}

.brand-name {
  font-size: 1.05rem;
  letter-spacing: 0.03em;
}

.menu-button {
  justify-content: flex-start;
  gap: 0.85rem;
  border-radius: 16px;
  padding: 0.85rem 1rem;
}

.menu-button.active {
  background: rgba(108, 99, 255, 0.18);
  color: #4f46ff;
  font-weight: 600;
}

.sidebar-upgrade {
  margin-top: auto;
  border-radius: 24px;
  padding: 1.75rem;
  background: rgba(108, 99, 255, 0.12);
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  text-align: left;
}

.main {
  display: flex;
  flex-direction: column;
  gap: 2rem;
}

.greeting {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1.5rem;
}

.hello-wrap {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.search {
  width: clamp(260px, 36vw, 420px);
}

.search :deep(input) {
  padding-inline: 1.1rem;
}

.greeting-actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.destinations .section-header,
.section-header--sub {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
}

.destinations .section-header {
  margin-bottom: 1.5rem;
}

.tab-group {
  display: inline-flex;
  gap: 0.5rem;
  background: rgba(108, 99, 255, 0.08);
  padding: 0.25rem;
  border-radius: 999px;
}

.tab-active {
  background: linear-gradient(135deg, #6c63ff, #42b883) !important;
  color: #fff !important;
  font-weight: 600;
}

.destination-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1.6rem;
}

.destination-card {
  border-radius: 22px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.85) 0%, #ffffff 65%);
  box-shadow: 0 18px 40px rgba(16, 24, 40, 0.08);
}

.destination-card img {
  width: 100%;
  height: 160px;
  object-fit: cover;
  border-radius: 18px;
}

.middle-panels {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 1.5rem;
}

.calendar-grid {
  display: grid;
  gap: 0.6rem;
}

.calendar-row {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 0.4rem;
  text-align: center;
}

.calendar-cell {
  padding: 0.65rem 0;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.5);
  font-weight: 600;
}

.calendar-cell--muted {
  opacity: 0.2;
}

.calendar-cell--active {
  background: linear-gradient(135deg, #6c63ff, #42b883);
  color: #fff;
}

.pill-date {
  width: 46px;
  height: 46px;
  display: grid;
  place-items: center;
  border-radius: 16px;
  background: rgba(108, 99, 255, 0.15);
  color: #4f46ff;
  font-weight: 600;
  font-size: 1rem;
}

.rightbar-card,
.profile-card,
.friends-card,
.integrations-card {
  border-radius: 26px;
  box-shadow: 0 18px 48px rgba(16, 24, 40, 0.08);
}

.integrations-card :deep(.n-tag) {
  justify-content: center;
  padding: 0.6rem 1rem;
}

@media (max-width: 1200px) {
  .dashboard {
    grid-template-columns: 210px minmax(0, 1fr);
  }

  .rightbar {
    display: none;
  }

  .greeting {
    grid-template-columns: minmax(0, 1fr);
  }

  .search {
    width: 100%;
  }
}

@media (max-width: 900px) {
  .dashboard {
    grid-template-columns: 1fr;
    padding: 0 1rem;
  }

  .sidebar {
    position: sticky;
    top: 80px;
    z-index: 1;
  }
}
</style>
