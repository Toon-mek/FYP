<script setup>
import { computed, h, ref, watch } from 'vue'
import { NIcon } from 'naive-ui'
import TravelerWeatherWidget from './TravelerWeatherWidget.vue'

const props = defineProps({
  traveler: {
    type: Object,
    default: () => null,
  },
  metrics: {
    type: Object,
    default: () => ({}),
  },
  destinationGroups: {
    type: Array,
    default: () => [],
  },
  upcomingTrips: {
    type: Array,
    default: () => [],
  },
  stays: {
    type: Array,
    default: () => [],
  },
  transport: {
    type: Array,
    default: () => [],
  },
  experiences: {
    type: Array,
    default: () => [],
  },
  companions: {
    type: Array,
    default: () => [],
  },
  integrations: {
    type: Array,
    default: () => [],
  },
  insights: {
    type: Array,
    default: () => [],
  },
})

const defaultTraveler = {
  fullName: 'Traveler',
  username: 'traveler01',
}

const traveler = computed(() => {
  const incoming = props.traveler ?? {}
  const displayName = incoming.fullName || incoming.username || defaultTraveler.fullName
  const initials =
    incoming.avatarInitials ||
    displayName
      .split(/\s+/)
      .map((part) => part[0])
      .join('')
      .slice(0, 2)
      .toUpperCase()

  return {
    ...defaultTraveler,
    ...incoming,
    displayName,
    initials,
  }
})

const metrics = computed(() => ({
  tripsPlanned: Number(props.metrics.tripsPlanned ?? 0),
  ecoPoints: Number(props.metrics.ecoPoints ?? 0),
  savedSpots: Number(props.metrics.savedSpots ?? 0),
  impactBadges: Number(props.metrics.impactBadges ?? 0),
  nextTrip: props.metrics.nextTrip ?? 'Not scheduled yet',
  carbonSaved: Number(props.metrics.carbonSaved ?? 0),
  pledges: Number(props.metrics.pledges ?? 0),
  sharedGuides: Number(props.metrics.sharedGuides ?? 0),
  impactScore: Number(props.metrics.impactScore ?? 0),
}))

const renderIcon = (name) => () =>
  h(NIcon, null, { default: () => h('i', { class: name }) })

const destinationGroups = computed(() => props.destinationGroups ?? [])
const experiences = computed(() => props.experiences ?? [])
const upcomingTrips = computed(() => props.upcomingTrips ?? [])
const stays = computed(() => props.stays ?? [])
const transport = computed(() => props.transport ?? [])
const companions = computed(() => props.companions ?? [])
const integrations = computed(() => props.integrations ?? [])
const insights = computed(() => props.insights ?? [])

const sidebarOptions = [
  { key: 'dashboard', label: 'Dashboard overview', icon: renderIcon('ri-compass-3-line') },
  { key: 'messages', label: 'Messages', disabled: true, icon: renderIcon('ri-chat-3-line') },
  { key: 'notifications', label: 'Notifications', disabled: true, icon: renderIcon('ri-notification-3-line') },
  { key: 'trips', label: 'Trip planner', disabled: true, icon: renderIcon('ri-calendar-event-line') },
  { key: 'saved', label: 'Saved places', disabled: true, icon: renderIcon('ri-heart-3-line') },
  { key: 'settings', label: 'Account settings', disabled: true, icon: renderIcon('ri-settings-4-line') },
]

const selectedMenu = ref('dashboard')

const destinationTabs = computed(() => destinationGroups.value.map((group) => group.label))
const activeDestinationTab = ref(destinationTabs.value[0] ?? null)

watch(destinationTabs, (tabs) => {
  if (!tabs.includes(activeDestinationTab.value)) {
    activeDestinationTab.value = tabs[0] ?? null
  }
})

const activeDestinations = computed(() => {
  const group = destinationGroups.value.find((item) => item.label === activeDestinationTab.value)
  return group?.items ?? []
})

const summaryCards = computed(() => [
  {
    key: 'trips',
    label: 'Trips planned',
    value: metrics.value.tripsPlanned,
    type: 'number',
    accent: 'linear-gradient(135deg, #70c3ff, #6c63ff)',
  },
  {
    key: 'eco-points',
    label: 'Eco points collected',
    value: metrics.value.ecoPoints,
    type: 'number',
    accent: 'linear-gradient(135deg, #42b883, #8fd3f4)',
  },
  {
    key: 'saved',
    label: 'Saved eco stays',
    value: metrics.value.savedSpots,
    type: 'number',
    accent: 'linear-gradient(135deg, #ff9a9e, #fad0c4)',
  },
  {
    key: 'impact',
    label: 'Impact badges',
    value: metrics.value.impactBadges,
    type: 'number',
    accent: 'linear-gradient(135deg, #a18cd1, #fbc2eb)',
  },
  {
    key: 'upcoming',
    label: 'Next activity',
    value: metrics.value.nextTrip,
    type: 'text',
    accent: 'linear-gradient(135deg, #f6d365, #fda085)',
  },
])

const heroStats = computed(() => [
  {
    key: 'carbon',
    label: 'Carbon saved',
    value: metrics.value.carbonSaved,
    suffix: ' tCO₂e',
    icon: 'ri-planet-line',
  },
  {
    key: 'pledges',
    label: 'Community pledges',
    value: metrics.value.pledges,
    icon: 'ri-hand-heart-line',
  },
  {
    key: 'guides',
    label: 'Shared guides',
    value: metrics.value.sharedGuides,
    icon: 'ri-map-pin-user-line',
  },
  {
    key: 'impactScore',
    label: 'Impact score',
    value: metrics.value.impactScore,
    suffix: '/100',
    icon: 'ri-star-sparkle-line',
  },
])

const hasDestinations = computed(() => destinationGroups.value.length > 0)
const hasExperiences = computed(() => experiences.value.length > 0)
const hasTrips = computed(() => upcomingTrips.value.length > 0)
const hasStays = computed(() => stays.value.length > 0)
const hasTransport = computed(() => transport.value.length > 0)
const hasCompanions = computed(() => companions.value.length > 0)
const hasIntegrations = computed(() => integrations.value.length > 0)
const hasInsights = computed(() => insights.value.length > 0)
</script>

<template>
  <n-layout
    has-sider
    style="min-height: 100vh; background: var(--body-color);"
  >
    <n-layout-sider
      bordered
      collapse-mode="width"
      :collapsed-width="64"
      :width="220"
      show-trigger="bar"
    >
      <n-space vertical size="small" style="padding: 18px 16px;">
        <n-gradient-text type="info" style="font-size: 1.1rem; font-weight: 600;">
          Traveler hub
        </n-gradient-text>
        <n-text depth="3">Navigate modules</n-text>
      </n-space>
      <div style="padding: 0 8px 16px;">
        <n-menu
          :options="sidebarOptions"
          :value="selectedMenu"
          :indent="16"
          :collapsed-icon-size="20"
          @update:value="(val) => (selectedMenu.value = val)"
        />
      </div>
    </n-layout-sider>

    <n-layout>
      <n-layout-header
        bordered
        style="padding: 20px 32px; background: transparent;"
      >
        <n-space justify="space-between" align="center" wrap>
          <n-space align="center" size="large">
            <n-avatar round size="large" style="background: var(--primary-color-hover); color: white;">
              {{ traveler.initials }}
            </n-avatar>
            <div>
              <n-text depth="3">Hello, traveler</n-text>
              <div style="font-size: 1.35rem; font-weight: 600;">
                {{ traveler.displayName }}
              </div>
            </div>
          </n-space>
          <n-space>
            <n-input
              round
              clearable
              placeholder="Search eco stays, guides, or itineraries"
              style="min-width: 280px;"
            >
              <template #suffix>
                <n-icon size="18">
                  <i class="ri-search-2-line" />
                </n-icon>
              </template>
            </n-input>
            <n-button type="primary" round>
              Start new plan
            </n-button>
          </n-space>
        </n-space>
      </n-layout-header>

      <n-layout-content embedded style="padding: 24px 32px;">
        <n-space vertical size="large">
          <n-card
            :segmented="{ content: true }"
            :style="{
              background: 'linear-gradient(135deg, rgba(66, 184, 131, 0.12), rgba(108, 99, 255, 0.12))',
              border: '1px solid rgba(66, 184, 131, 0.24)',
            }"
          >
            <n-grid cols="1 m:2" :x-gap="18" :y-gap="18" align="center">
              <n-grid-item>
                <n-space vertical size="small">
                  <n-tag type="success" size="small" bordered>Traveler spotlight</n-tag>
                  <div style="font-size: 1.8rem; font-weight: 700;">
                    Craft journeys that protect Malaysia’s wild places
                  </div>
                  <n-text depth="3">
                    Plan flexible itineraries, track your eco impact, and stay in touch with responsible guides.
                  </n-text>
                  <n-space>
                    <n-button type="primary" round>
                      Continue last itinerary
                    </n-button>
                    <n-button tertiary type="primary" round>
                      Explore eco pledges
                    </n-button>
                  </n-space>
                </n-space>
              </n-grid-item>
              <n-grid-item>
                <n-grid cols="2" :x-gap="16" :y-gap="16">
                  <n-grid-item v-for="stat in heroStats" :key="stat.key">
                    <n-statistic :label="stat.label" :value="stat.value" :suffix="stat.suffix">
                      <template #prefix>
                        <n-icon size="20">
                          <i :class="stat.icon" />
                        </n-icon>
                      </template>
                    </n-statistic>
                  </n-grid-item>
                </n-grid>
              </n-grid-item>
            </n-grid>
          </n-card>

          <n-grid cols="1 m:2 l:5" :x-gap="16" :y-gap="16">
            <n-grid-item v-for="card in summaryCards" :key="card.key">
              <n-card
                size="medium"
                :segmented="{ content: true, footer: false }"
                :style="{
                  background: card.accent,
                  color: '#fff',
                }"
              >
                <n-space vertical size="small">
                  <n-text depth="3" style="color: rgba(255, 255, 255, 0.85);">
                    {{ card.label }}
                  </n-text>
                  <div v-if="card.type === 'number'" style="display: flex; align-items: baseline; gap: 6px;">
                    <n-number-animation
                      :from="0"
                      :to="card.value"
                      :duration="1200"
                      show-separator
                    />
                  </div>
                  <div v-else style="font-size: 1.1rem; font-weight: 600;">
                    {{ card.value }}
                  </div>
                </n-space>
              </n-card>
            </n-grid-item>
          </n-grid>

          <TravelerWeatherWidget />

          <n-card title="Featured experiences" :segmented="{ content: true }">
            <template v-if="hasExperiences">
              <n-carousel autoplay dot-type="line" draggable>
                <n-carousel-item
                  v-for="experience in experiences"
                  :key="experience.key ?? experience.title"
                >
                  <div
                    :style="{
                      height: '280px',
                      borderRadius: '20px',
                      backgroundImage: `linear-gradient(135deg, rgba(9, 54, 34, 0.55), rgba(9, 54, 34, 0.15)), url(${experience.image})`,
                      backgroundSize: 'cover',
                      backgroundPosition: 'center',
                      display: 'flex',
                      flexDirection: 'column',
                      justifyContent: 'flex-end',
                      padding: '28px',
                      color: '#fff',
                    }"
                  >
                    <div style="font-size: 1.65rem; font-weight: 700;">{{ experience.title }}</div>
                    <div style="max-width: 520px; margin-top: 6px;">
                      {{ experience.description }}
                    </div>
                    <n-space style="margin-top: 16px;">
                      <n-button round type="primary">See itinerary</n-button>
                      <n-button round tertiary type="primary">Save experience</n-button>
                    </n-space>
                  </div>
                </n-carousel-item>
              </n-carousel>
            </template>
            <template v-else>
              <n-empty description="No featured experiences yet.">
                <template #extra>
                  <n-button size="small" type="primary" tertiary>Import from API</n-button>
                </template>
              </n-empty>
            </template>
          </n-card>

          <n-card
            :segmented="{ content: true }"
            title="Destination inspiration"
          >
            <template v-if="hasDestinations">
              <n-tabs
                v-model:value="activeDestinationTab"
                type="segment"
              >
                <n-tab-pane
                  v-for="tab in destinationTabs"
                  :key="tab"
                  :name="tab"
                  :tab="tab"
                >
                  <n-grid cols="1 m:2 l:3" :x-gap="18" :y-gap="18">
                    <n-grid-item
                      v-for="destination in activeDestinations"
                      :key="destination.key ?? destination.name"
                    >
                      <n-card
                        size="medium"
                        :segmented="{ content: true }"
                        style="overflow: hidden;"
                      >
                        <template #cover>
                          <img
                            v-if="destination.image"
                            :src="destination.image"
                            :alt="destination.name"
                            style="width: 100%; height: 180px; object-fit: cover;"
                          />
                        </template>
                        <n-space vertical size="small">
                          <div style="font-size: 1.1rem; font-weight: 600;">
                            {{ destination.name }}
                          </div>
                          <n-text depth="3">
                            {{ destination.location }} · {{ destination.duration }}
                          </n-text>
                          <n-tag v-if="destination.tag" type="success" size="small" bordered>
                            {{ destination.tag }}
                          </n-tag>
                          <n-button tertiary type="primary">
                            View itinerary
                          </n-button>
                        </n-space>
                      </n-card>
                    </n-grid-item>
                  </n-grid>
                </n-tab-pane>
              </n-tabs>
            </template>
            <template v-else>
              <n-empty description="Add destination groups to inspire your traveler." />
            </template>
          </n-card>

          <n-grid cols="1 m:3" :x-gap="16" :y-gap="16">
            <n-grid-item span="1 m:2">
              <n-card title="Upcoming journeys" :segmented="{ content: true }">
                <template v-if="hasTrips">
                  <n-timeline size="large">
                    <n-timeline-item
                      v-for="trip in upcomingTrips"
                      :key="trip.id ?? trip.title"
                      :title="trip.title"
                      :time="`${trip.location} · ${trip.duration}`"
                    >
                      <n-text depth="3">{{ trip.focus }}</n-text>
                      <template #footer>
                        <n-button text type="primary">Open trip board</n-button>
                      </template>
                    </n-timeline-item>
                  </n-timeline>
                </template>
                <template v-else>
                  <n-empty description="No upcoming trips scheduled." />
                </template>
              </n-card>
            </n-grid-item>

            <n-grid-item>
              <n-space vertical size="large">
                <n-card title="Sustainable stays" :segmented="{ content: true }">
                  <template v-if="hasStays">
                    <n-list bordered :show-divider="false">
                      <n-list-item v-for="stay in stays" :key="stay.id ?? stay.name">
                        <n-space justify="space-between" align="center" style="width: 100%;">
                          <div>
                            <div style="font-weight: 600;">{{ stay.name }}</div>
                            <n-text depth="3">{{ stay.location }}</n-text>
                          </div>
                          <n-tag size="small" type="success" bordered>{{ stay.price }}</n-tag>
                        </n-space>
                      </n-list-item>
                    </n-list>
                  </template>
                  <template v-else>
                    <n-empty description="Connect your sustainable stays feed to populate this list." />
                  </template>
                  <template #footer>
                    <n-button block tertiary type="primary">Browse eco stays</n-button>
                  </template>
                </n-card>

                <n-card title="Preferred transport" :segmented="{ content: true }">
                  <template v-if="hasTransport">
                    <n-space wrap>
                      <n-button
                        v-for="mode in transport"
                        :key="mode.key ?? mode.label"
                        round
                        quaternary
                      >
                        <n-icon size="18" v-if="mode.icon">
                          <i :class="mode.icon" />
                        </n-icon>
                        <span style="margin-left: 6px;">{{ mode.label }}</span>
                      </n-button>
                    </n-space>
                  </template>
                  <template v-else>
                    <n-empty description="Transport providers will appear once connected." />
                  </template>
                </n-card>
              </n-space>
            </n-grid-item>
          </n-grid>

          <n-grid cols="1 m:2" :x-gap="16" :y-gap="16">
            <n-grid-item>
              <n-card title="Travel companions" :segmented="{ content: true }">
                <template v-if="hasCompanions">
                  <n-avatar-group size="medium">
                    <n-avatar v-for="url in companions" :key="url" :src="url" />
                  </n-avatar-group>
                </template>
                <template v-else>
                  <n-empty description="Invite friends to plan together." />
                </template>
                <template #footer>
                  <n-space justify="space-between" align="center">
                    <n-text depth="3">Collaborate on itineraries and share travel notes.</n-text>
                    <n-button text type="primary">Manage invitations</n-button>
                  </n-space>
                </template>
              </n-card>
            </n-grid-item>

            <n-grid-item>
              <n-card title="Connected apps" :segmented="{ content: true }">
                <template v-if="hasIntegrations">
                  <n-space wrap>
                    <n-tag
                      v-for="app in integrations"
                      :key="app.key ?? app"
                      type="info"
                      size="large"
                      bordered
                    >
                      {{ app.label ?? app }}
                    </n-tag>
                  </n-space>
                </template>
                <template v-else>
                  <n-empty description="Link trip planning apps to sync data here." />
                </template>
                <template #footer>
                  <n-button text type="primary">Manage integrations</n-button>
                </template>
              </n-card>
            </n-grid-item>
          </n-grid>

          <n-card title="Eco travel playbook" :segmented="{ content: true }">
            <template v-if="hasInsights">
              <n-collapse>
                <n-collapse-item
                  v-for="item in insights"
                  :key="item.key ?? item.title"
                  :title="item.title"
                >
                  <n-text depth="3">{{ item.description }}</n-text>
                </n-collapse-item>
              </n-collapse>
            </template>
            <template v-else>
              <n-empty description="Add best-practice tips to guide mindful travel." />
            </template>
          </n-card>
        </n-space>
      </n-layout-content>
    </n-layout>
  </n-layout>
</template>

<style scoped>
:global(body) {
  background: var(--body-color);
}
</style>
