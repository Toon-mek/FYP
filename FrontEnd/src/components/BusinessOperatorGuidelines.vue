<script setup>
import { computed, ref } from 'vue'
import {
  NCard,
  NList,
  NListItem,
  NSpace,
  NTabPane,
  NTabs,
  NText,
  NTimeline,
  NTimelineItem,
} from 'naive-ui'

const guidelineTab = ref('upload')

const guidelinesChecklist = [
  'Confirm business ownership or authority to represent the operator.',
  'Upload accurate contact details and verify them quarterly.',
  'Share sustainability highlights or certifications to support eco goals.',
  'Provide at least one high-resolution photo per listing.',
  'Keep pricing, packages, and seasonal availability up to date.',
]

const uploadFlow = [
  {
    title: 'Collect business profile',
    description:
      'Operators provide business name, category, contact info, and service descriptions using the registration form.',
  },
  {
    title: 'Submit for review',
    description:
      'Submission enters Pending Review for administrators to verify authenticity and completeness.',
  },
  {
    title: 'Approval & publication',
    description:
      'Approved listings become Active and appear to travelers; incomplete entries return with review notes.',
  },
]

const manageFlow = [
  {
    title: 'Edit listing details',
    description:
      'Update descriptions, pricing, and contact information when offerings change. Save drafts before publishing.',
  },
  {
    title: 'Toggle visibility',
    description:
      'Switch between Visible and Hidden to match seasonal availability or during maintenance windows.',
  },
  {
    title: 'Archive retired listings',
    description:
      'Remove listings that will no longer be offered to keep the traveler catalog accurate.',
  },
]

const guidelineResources = [
  {
    title: 'Listing quality checklist',
    description:
      'Ensure copywriting, photos, and contact information meet the minimum standard prior to submission.',
  },
  {
    title: 'Media preparation tips',
    description:
      'Use clear landscape images (>=1200px wide) and PDF menus under 5MB to speed up verification.',
  },
  {
    title: 'Communication etiquette',
    description:
      'Respond to traveler enquiries within 24 hours and ensure phone/email details remain updated.',
  },
]

const guidelineFlowMeta = computed(() =>
  guidelineTab.value === 'upload'
    ? {
        title: 'Upload Business Info flow',
        subtitle: 'Step-by-step journey from your documented use case.',
      }
    : {
        title: 'Manage Business Listing flow',
        subtitle: 'Operations after submission: edit, hide/unhide, delete.',
      },
)
</script>

<template>
  <n-space vertical size="large">
    <n-card title="Operator guidelines checklist" :segmented="{ content: true }">
      <n-space vertical size="small">
        <n-text depth="3">Follow these checkpoints before submitting or updating listings.</n-text>
        <n-list bordered :show-divider="false">
          <n-list-item v-for="item in guidelinesChecklist" :key="item">
            {{ item }}
          </n-list-item>
        </n-list>
      </n-space>
    </n-card>

    <n-card :title="guidelineFlowMeta.title" :segmented="{ content: true }">
      <n-text depth="3">{{ guidelineFlowMeta.subtitle }}</n-text>
      <n-tabs
        v-model:value="guidelineTab"
        type="segment"
        size="small"
        style="margin-top: 16px;"
      >
        <n-tab-pane name="upload" tab="Upload Business Info">
          <n-timeline size="large">
            <n-timeline-item v-for="step in uploadFlow" :key="step.title" :title="step.title">
              <n-text depth="3">{{ step.description }}</n-text>
            </n-timeline-item>
          </n-timeline>
        </n-tab-pane>
        <n-tab-pane name="manage" tab="Manage Business Listing">
          <n-timeline size="large">
            <n-timeline-item v-for="step in manageFlow" :key="step.title" :title="step.title">
              <n-text depth="3">{{ step.description }}</n-text>
            </n-timeline-item>
          </n-timeline>
        </n-tab-pane>
      </n-tabs>
    </n-card>

    <n-card title="Best-practice resources" :segmented="{ content: true }">
      <n-list bordered :show-divider="false">
        <n-list-item v-for="resource in guidelineResources" :key="resource.title">
          <n-space vertical size="small">
            <span style="font-weight: 600;">{{ resource.title }}</span>
            <n-text depth="3">{{ resource.description }}</n-text>
          </n-space>
        </n-list-item>
      </n-list>
    </n-card>
  </n-space>
</template>

<style scoped>
</style>
