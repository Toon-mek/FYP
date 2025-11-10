<script setup>
import { computed, h, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  NCard,
  NDataTable,
  NEmpty,
  NGrid,
  NGridItem,
  NSelect,
  NSpin,
  NStatistic,
  NSpace,
  NText,
  NButtonGroup,
  NButton,
} from 'naive-ui'
import { Chart, registerables } from 'chart.js'
import ChartDataLabels from 'chartjs-plugin-datalabels'
import jsPDF from 'jspdf'

Chart.register(...registerables, ChartDataLabels)

const API_BASE = import.meta.env.VITE_API_BASE || '/api'

const rangeOptions = [
  { label: 'Last 7 days', value: 7 },
  { label: 'Last 14 days', value: 14 },
  { label: 'Last 30 days', value: 30 },
  { label: 'Last 90 days', value: 90 },
]

const selectedRange = ref(30)
const loading = ref(false)
const errorMessage = ref('')
const data = ref(null)
const communityChartType = ref('table')
const exportingReport = ref(false)
const communityMetricConfig = [
  { key: 'posts', label: 'Posts', color: '#2080f0' },
  { key: 'comments', label: 'Comments', color: '#18a058' },
  { key: 'likes', label: 'Likes', color: '#d03050' },
]
const communityMetricKeys = communityMetricConfig.map(metric => metric.key)
const sumCommunityMetrics = (user) => {
  return communityMetricKeys.reduce((sum, key) => sum + Number(user?.[key] ?? 0), 0)
}
const communityUserPalette = ['#3b82f6', '#22c55e', '#f97316', '#a855f7', '#ec4899', '#0ea5e9', '#facc15', '#ef4444']

const communityBarCanvas = ref(null)
const communityDonutCanvas = ref(null)
let communityBarChart = null
let communityDonutChart = null

// Generate unique IDs for SVG gradients
const lineGradientId = `lineGrad-${Math.random().toString(36).slice(2, 9)}`
const donutGradientId = `donutGrad-${Math.random().toString(36).slice(2, 9)}`

// ==================== USAGE REPORTS ====================

const usageCards = computed(() => {
  if (!data.value) return []
  const usage = data.value.usageReports
  return [
    {
      label: 'Active Users',
      value: usage.activeUsers.total,
      detail: `${usage.activeUsers.travelers} travelers, ${usage.activeUsers.operators} operators`,
      icon: '👥',
      color: '#18a058'
    },
    {
      label: 'New Listings',
      value: usage.newListings,
      detail: 'Submitted in period',
      icon: '🏪',
      color: '#2080f0'
    },
    {
      label: 'Simulated Bookings',
      value: usage.simulatedBookings,
      detail: 'Listing inquiries',
      icon: '📅',
      color: '#f0a020'
    },
    {
      label: 'Chatbot Usage',
      value: usage.chatbotUsage,
      detail: 'Conversations',
      icon: '🤖',
      color: '#9333ea'
    }
  ]
})

// ==================== DAILY LOGINS CHART ====================

const loginChartData = computed(() => {
  if (!data.value?.analytics?.dailyLogins?.length) return null

  const logins = data.value.analytics.dailyLogins
  const width = 700
  const height = 250
  const padding = { top: 20, right: 30, bottom: 50, left: 50 }
  const chartWidth = width - padding.left - padding.right
  const chartHeight = height - padding.top - padding.bottom

  const values = logins.map(d => d.count)
  const maxValue = Math.max(...values, 1)
  const minValue = Math.min(...values, 0)
  const range = maxValue - minValue || 1

  const xStep = chartWidth / Math.max(logins.length - 1, 1)

  const points = logins.map((item, i) => {
    const x = padding.left + (i * xStep)
    const y = padding.top + chartHeight - ((item.count - minValue) / range * chartHeight)
    return { x, y, date: item.date, count: item.count }
  })

  const linePath = points.map((p, i) =>
    `${i === 0 ? 'M' : 'L'}${p.x.toFixed(2)},${p.y.toFixed(2)}`
  ).join(' ')

  const areaPath = `${linePath} L${points[points.length - 1].x},${padding.top + chartHeight} L${padding.left},${padding.top + chartHeight} Z`

  // Y-axis labels
  const yLabels = Array.from({ length: 5 }, (_, i) => {
    const value = minValue + (range * (4 - i) / 4)
    const y = padding.top + (chartHeight * i / 4)
    return { value: Math.round(value), y }
  })

  // X-axis labels (show every nth date)
  const xLabelStep = Math.ceil(logins.length / 8)
  const xLabels = logins.map((item, i) => ({
    label: i % xLabelStep === 0 ? formatDateShort(item.date) : '',
    x: padding.left + (i * xStep),
    y: padding.top + chartHeight + 30
  }))

  return {
    width,
    height,
    padding,
    chartWidth,
    chartHeight,
    linePath,
    areaPath,
    points,
    yLabels,
    xLabels,
    maxValue,
    minValue
  }
})

// ==================== COMMUNITY ACTIVENESS ====================

const communityColumns = [
  { title: 'User Name', key: 'userName', ellipsis: { tooltip: true }, width: 180 },
  { title: 'Posts', key: 'posts', align: 'center', width: 80 },
  { title: 'Comments', key: 'comments', align: 'center', width: 100 },
  { title: 'Likes', key: 'likes', align: 'center', width: 80 },
  { title: 'Engagement', key: 'engagement', align: 'center', width: 110 },
  { title: 'Total Activity', key: 'totalActivity', align: 'center', width: 120 },
]

const communityActiveness = computed(() => {
  const users = data.value?.analytics?.communityActiveness || []
  return users.map(user => ({
    ...user,
    totalActivity: sumCommunityMetrics(user),
  }))
})

const communitySummary = computed(() => {
  const users = communityActiveness.value
  if (!users.length) {
    return {
      total: 0,
      average: 0,
      topUser: '',
      topValue: 0,
    }
  }

  const totals = users.map(sumCommunityMetrics)
  const total = totals.reduce((sum, value) => sum + value, 0)
  const average = users.length ? total / users.length : 0
  const maxTotal = Math.max(...totals)
  const maxIndex = totals.findIndex(value => value === maxTotal)
  const topUser = maxIndex >= 0 ? users[maxIndex] : null

  return {
    total,
    average: Math.round(average),
    topUser: topUser?.userName ?? '',
    topValue: maxTotal || 0,
  }
})

const communityMetricTotals = computed(() => {
  const users = communityActiveness.value
  return communityMetricConfig
    .map(metric => ({
      ...metric,
      total: users.reduce((sum, user) => sum + Number(user[metric.key] ?? 0), 0),
    }))
    .filter(metric => metric.total > 0)
})

const communityCategoryDistribution = computed(() => {
  const categories = data.value?.analytics?.communityCategories || []
  const cleaned = categories
    .map((item, index) => ({
      label: item.category || 'Uncategorized',
      value: Number(item.count ?? 0),
    }))
    .filter(item => item.value > 0)
    .slice(0, 8)

  const total = cleaned.reduce((sum, item) => sum + item.value, 0)
  const segments = cleaned.map((item, index) => ({
    ...item,
    percentage: total ? (item.value / total) * 100 : 0,
    color: communityUserPalette[index % communityUserPalette.length],
  }))

  return {
    total,
    segments,
  }
})

const communityCategorySegments = computed(() => communityCategoryDistribution.value.segments ?? [])

const communityBarChartHeight = computed(() => {
  const rows = communityActiveness.value.length || 1
  return Math.min(Math.max(rows * 56 + 80, 220), 520)
})

watch([communityActiveness, communityCategoryDistribution, communityChartType], () => {
  nextTick(() => {
    if (communityChartType.value === 'bar') {
      renderCommunityBarChart()
    } else {
      destroyCommunityBarChart()
    }

    if (communityChartType.value === 'donut') {
      renderCommunityDonutChart()
    } else {
      destroyCommunityDonutChart()
    }
  })
}, { immediate: true })

onBeforeUnmount(() => {
  destroyCommunityBarChart()
  destroyCommunityDonutChart()
})

function destroyCommunityBarChart() {
  if (communityBarChart) {
    communityBarChart.destroy()
    communityBarChart = null
  }
}

function destroyCommunityDonutChart() {
  if (communityDonutChart) {
    communityDonutChart.destroy()
    communityDonutChart = null
  }
}

function renderCommunityBarChart() {
  const canvas = communityBarCanvas.value
  if (!canvas) return
  const users = communityActiveness.value
  if (!users.length) return

  const datasets = communityMetricConfig.map(metric => {
    const dataPoints = users.map(user => Number(user[metric.key] ?? 0))
    return {
      label: metric.label,
      data: dataPoints,
      backgroundColor: metric.color,
      borderRadius: 12,
      maxBarThickness: 28,
      barPercentage: 0.8,
    }
  }).filter(dataset => dataset.data.some(value => value > 0))

  if (!datasets.length) {
    destroyCommunityBarChart()
    return
  }

  destroyCommunityBarChart()
  communityBarChart = new Chart(canvas, {
    type: 'bar',
    data: {
      labels: users.map(user => user.userName || 'Unknown'),
      datasets,
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      indexAxis: 'y',
      interaction: { mode: 'nearest', intersect: false },
      scales: {
        x: {
          stacked: true,
          beginAtZero: true,
          grid: {
            color: 'rgba(148, 163, 184, 0.2)',
            borderDash: [4, 4],
          },
          ticks: {
            callback: value => formatNumber(value),
            font: { size: 11 },
            color: '#475569',
          },
        },
        y: {
          stacked: true,
          grid: { display: false },
          ticks: {
            font: { size: 13, weight: '600' },
            color: '#0f172a',
          },
        },
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#0f172a',
          callbacks: {
            label: context => {
              const label = context.dataset.label || ''
              const value = formatNumber(context.parsed.x)
              return `${label}: ${value}`
            },
          },
        },
        datalabels: {
          anchor: 'center',
          align: 'center',
          color: 'white',
          formatter: value => (value > 0 ? formatNumber(value) : ''),
          font: { weight: '600' },
          clamp: true,
        },
      },
    },
  })
}

function renderCommunityDonutChart() {
  const canvas = communityDonutCanvas.value
  if (!canvas) return
  const distribution = communityCategoryDistribution.value
  const segments = distribution.segments
  if (!segments.length) {
    destroyCommunityDonutChart()
    return
  }

  destroyCommunityDonutChart()
  communityDonutChart = new Chart(canvas, {
    type: 'doughnut',
    data: {
    labels: segments.map(item => item.label),
    datasets: [
      {
        data: segments.map(item => item.value),
        backgroundColor: segments.map(item => item.color),
        borderWidth: 0,
      },
    ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '70%',
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: context => {
              const total = context.dataset.data.reduce((sum, value) => sum + value, 0)
              const value = context.parsed
              const percent = total ? ((value / total) * 100).toFixed(1) : 0
              return `${context.label}: ${formatNumber(value)} (${percent}%)`
            },
          },
        },
        datalabels: {
          color: '#0f172a',
          formatter: (value, ctx) => {
            const total = ctx.chart.data.datasets[0].data.reduce((sum, val) => sum + val, 0)
            const pct = total ? (value / total) * 100 : 0
            return pct >= 8 ? `${pct.toFixed(0)}%` : ''
          },
          font: { weight: '600' },
        },
      },
    },
  })
}

// ==================== USER ACTIVITIES BARS ====================

const activityBars = computed(() => {
  if (!data.value?.analytics?.userActivities) return []

  const activities = data.value.analytics.userActivities
  const items = [
    { label: 'Posts', value: activities.posts, color: '#2080f0' },
    { label: 'Comments', value: activities.comments, color: '#18a058' },
    { label: 'Reviews', value: activities.reviews, color: '#f0a020' },
    { label: 'Saves', value: activities.saves, color: '#d03050' },
    { label: 'Messages', value: activities.messages, color: '#9333ea' },
  ]

  const maxValue = Math.max(...items.map(i => i.value), 1)

  return items.map(item => ({
    ...item,
    percentage: (item.value / maxValue) * 100
  }))
})

// ==================== OPERATOR RANKINGS ====================

const operatorRankings = computed(() => {
  return data.value?.analytics?.operatorRankings || []
})

const operatorColumns = [
  {
    title: 'Rank',
    key: 'rank',
    width: 70,
    render: (row, index) => {
      const rank = index + 1
      let badge = ''
      if (rank === 1) badge = '🥇 '
      else if (rank === 2) badge = '🥈 '
      else if (rank === 3) badge = '🥉 '
      return h('span', { style: { fontWeight: '600' } }, `${badge}#${rank}`)
    }
  },
  { title: 'Operator Name', key: 'name', ellipsis: { tooltip: true }, width: 180 },
  { title: 'Business Type', key: 'businessType', ellipsis: { tooltip: true }, width: 140 },
  {
    title: 'Listings',
    key: 'totalListings',
    width: 120,
    align: 'center',
    render: (row) => {
      const approved = row.approvedListings
      const total = row.totalListings
      const percentage = total > 0 ? (approved / total * 100).toFixed(0) : 0
      return h('span', [
        h('span', { style: { fontWeight: '600', color: '#18a058' } }, approved),
        h('span', { style: { color: '#666' } }, `/${total}`),
        h('span', { style: { fontSize: '0.85em', color: '#999', marginLeft: '4px' } }, `(${percentage}%)`)
      ])
    }
  },
  {
    title: 'Rating',
    key: 'avgRating',
    width: 100,
    align: 'center',
    render: (row) => {
      if (row.avgRating === 0) {
        return h('span', { style: { color: '#999' } }, 'N/A')
      }
      const stars = Math.round(row.avgRating)
      const starEmoji = '⭐'.repeat(stars)
      return h('span', { title: row.avgRating.toString() }, `${starEmoji} ${row.avgRating}`)
    }
  },
  {
    title: 'Reviews',
    key: 'totalReviews',
    width: 90,
    align: 'center',
    render: (row) => {
      if (row.totalReviews > 0) {
        return h('span', { style: { fontWeight: '600' } }, row.totalReviews)
      }
      return h('span', { style: { color: '#999' } }, '0')
    }
  },
  {
    title: 'Saves',
    key: 'totalSaves',
    width: 80,
    align: 'center',
    render: (row) => {
      if (row.totalSaves > 0) {
        return h('span', { style: { fontWeight: '600', color: '#f0a020' } }, row.totalSaves)
      }
      return h('span', { style: { color: '#999' } }, '0')
    }
  }
]

// ==================== CATEGORY DISTRIBUTION ====================

const categoryData = computed(() => {
  const categories = data.value?.analytics?.categoryDistribution || []
  if (!categories.length) return null

  const total = categories.reduce((sum, c) => sum + c.count, 0)
  if (total === 0) return null

  const colors = ['#2080f0', '#18a058', '#f0a020', '#d03050', '#9333ea', '#0e7490']

  return categories.map((cat, index) => ({
    category: cat.category,
    count: cat.count,
    percentage: (cat.count / total) * 100,
    color: colors[index % colors.length]
  }))
})

const categoryColumns = [
  {
    title: 'Category',
    key: 'category',
    width: 200,
    render: (row) => {
      return h('div', { style: { display: 'flex', alignItems: 'center', gap: '8px' } }, [
        h('div', {
          style: {
            width: '12px',
            height: '12px',
            borderRadius: '3px',
            background: row.color,
            flexShrink: '0'
          }
        }),
        h('span', { style: { fontWeight: '600' } }, row.category)
      ])
    }
  },
  {
    title: 'Listings',
    key: 'count',
    width: 100,
    align: 'center',
    render: (row) => h('span', { style: { fontWeight: '600', fontSize: '1.1em' } }, row.count)
  },
  {
    title: 'Percentage',
    key: 'percentage',
    width: 120,
    align: 'center',
    render: (row) => h('span', { style: { fontWeight: '600', color: row.color } }, `${row.percentage.toFixed(1)}%`)
  },
  {
    title: 'Distribution',
    key: 'bar',
    render: (row) => {
      return h('div', { style: { display: 'flex', alignItems: 'center', gap: '8px' } }, [
        h('div', {
          style: {
            flex: '1',
            height: '12px',
            background: 'rgba(0,0,0,0.05)',
            borderRadius: '6px',
            overflow: 'hidden'
          }
        }, [
          h('div', {
            style: {
              height: '100%',
              width: `${row.percentage}%`,
              background: row.color,
              borderRadius: '6px',
              transition: 'width 0.3s ease'
            }
          })
        ])
      ])
    }
  }
]

// ==================== TOTAL STATS ====================

const totalStats = computed(() => {
  if (!data.value?.totalStats) return []
  const stats = data.value.totalStats
  return [
    { label: 'Total Users', value: stats.totalUsers, icon: '👥' },
    { label: 'Total Listings', value: stats.totalListings, icon: '🏪' },
    { label: 'Total Reviews', value: stats.totalReviews, icon: '⭐' },
    { label: 'Total Messages', value: stats.totalMessages, icon: '💬' },
  ]
})

// ==================== API CALL ====================

async function loadAnalytics() {
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await fetch(
      `${API_BASE}/admin/analytics.php?days=${selectedRange.value}`,
      { credentials: 'include' }
    )
    if (!response.ok) {
      throw new Error('Unable to load analytics')
    }
    const result = await response.json()
    if (!result.ok) {
      throw new Error(result.error || 'Analytics returned an error')
    }
    data.value = result
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Unexpected error occurred.'
    data.value = null
  } finally {
    loading.value = false
  }
}

onMounted(loadAnalytics)
watch(selectedRange, loadAnalytics)

function buildAnalyticsReportPayload(raw) {
  const range = raw?.dateRange ?? {}
  return {
    generatedAt: new Date().toISOString(),
    range: {
      start: range.start ?? '',
      end: range.end ?? '',
      days: range.days ?? null,
    },
    usageReports: raw?.usageReports ?? {},
    analytics: raw?.analytics ?? {},
    totalStats: raw?.totalStats ?? {},
  }
}

function buildAnalyticsReportLines(report) {
  const lines = []
  lines.push('EcoTravel Platform - Analytics Report')
  lines.push(`Generated: ${new Date(report.generatedAt).toLocaleString()}`)
  if (report.range?.start && report.range?.end) {
    lines.push(`Range: ${report.range.start} -> ${report.range.end} (${report.range.days ?? '?'} days)`)
  }
  return lines
}

function renderChartImage(config, width = 640, height = 320) {
  return new Promise(resolve => {
    const canvas = document.createElement('canvas')
    canvas.width = width
    canvas.height = height
    const ctx = canvas.getContext('2d')
    if (!ctx) {
      resolve(null)
      return
    }
    const chart = new Chart(ctx, config)
    requestAnimationFrame(() => {
      const dataUrl = chart.toBase64Image()
      chart.destroy()
      resolve({ dataUrl, width, height })
    })
  })
}

async function buildPdfChartImages(analytics) {
  const result = {}
  const dailyLogins = Array.isArray(analytics?.dailyLogins) ? analytics.dailyLogins : []
  if (dailyLogins.length) {
    const labels = dailyLogins.map(item => item.date)
    const dataPoints = dailyLogins.map(item => Number(item.count ?? 0))
    if (dataPoints.some(value => value > 0)) {
      result.dailyLogins = await renderChartImage({
        type: 'line',
        data: {
          labels,
          datasets: [
            {
              label: 'Logins',
              data: dataPoints,
              borderColor: '#1d4ed8',
              backgroundColor: 'rgba(59, 130, 246, 0.2)',
              fill: true,
              tension: 0.35,
              borderWidth: 3,
              pointRadius: 3,
            },
          ],
        },
        options: {
          responsive: false,
          animation: false,
          maintainAspectRatio: false,
          scales: {
            x: { ticks: { color: '#475569' }, grid: { color: 'rgba(148, 163, 184, 0.3)' } },
            y: { ticks: { color: '#475569' }, grid: { color: 'rgba(148, 163, 184, 0.2)', borderDash: [4, 4] } },
          },
          plugins: { legend: { display: false } },
        },
      }, 720, 320)
    }
  }

  const community = Array.isArray(analytics?.communityActiveness) ? analytics.communityActiveness.slice(0, 5) : []
  if (community.length) {
    const datasets = communityMetricConfig.map(metric => ({
      label: metric.label,
      data: community.map(user => Number(user[metric.key] ?? 0)),
      backgroundColor: metric.color,
      borderWidth: 0,
      borderRadius: 6,
      barThickness: 30,
    })).filter(dataset => dataset.data.some(value => value > 0))

    if (datasets.length) {
      result.community = await renderChartImage({
        type: 'bar',
        data: { labels: community.map(user => user.userName ?? 'Unknown'), datasets },
        options: {
          indexAxis: 'y',
          responsive: false,
          animation: false,
          maintainAspectRatio: false,
          scales: {
            x: {
              stacked: true,
              ticks: { color: '#475569' },
              grid: { color: 'rgba(148, 163, 184, 0.2)', borderDash: [4, 4] },
            },
            y: {
              stacked: true,
              ticks: { color: '#0f172a', font: { weight: '600' } },
              grid: { display: false },
            },
          },
          plugins: { legend: { position: 'bottom' } },
        },
      }, 720, 320)
    }
  }

  const activities = analytics?.userActivities ?? null
  if (activities) {
    const items = [
      { label: 'Posts', value: Number(activities.posts ?? 0), color: '#3b82f6' },
      { label: 'Comments', value: Number(activities.comments ?? 0), color: '#10b981' },
      { label: 'Reviews', value: Number(activities.reviews ?? 0), color: '#f59e0b' },
      { label: 'Saves', value: Number(activities.saves ?? 0), color: '#ec4899' },
      { label: 'Messages', value: Number(activities.messages ?? 0), color: '#8b5cf6' },
    ]
    if (items.some(item => item.value > 0)) {
      result.activities = await renderChartImage({
        type: 'bar',
        data: {
          labels: items.map(item => item.label),
          datasets: [
            {
              label: 'Activity Count',
              data: items.map(item => item.value),
              backgroundColor: items.map(item => item.color),
              borderRadius: 6,
              borderWidth: 0,
            },
          ],
        },
        options: {
          responsive: false,
          animation: false,
          maintainAspectRatio: false,
          scales: {
            x: { ticks: { color: '#0f172a' }, grid: { display: false } },
            y: { ticks: { color: '#475569' }, grid: { color: 'rgba(148, 163, 184, 0.2)' } },
          },
          plugins: { legend: { display: false } },
        },
      }, 640, 300)
    }
  }

  return result
}

function createAnalyticsPdf(report, charts) {
  const doc = new jsPDF({ orientation: 'p', unit: 'pt', format: 'a4' })
  const margin = 48
  const lineHeight = 18
  const pageHeight = doc.internal.pageSize.getHeight()
  const pageWidth = doc.internal.pageSize.getWidth()
  let cursorY = margin

  doc.setFont('helvetica', 'normal')
  doc.setFontSize(12)

  const ensureSpace = (height) => {
    if (cursorY + height > pageHeight - margin) {
      doc.addPage()
      cursorY = margin
    }
  }

  const addLines = (lines) => {
    lines.forEach(line => {
      const text = typeof line === 'string' ? line : line.text
      ensureSpace(lineHeight)
      doc.text(text, margin, cursorY)
      cursorY += lineHeight
    })
  }

  const addSectionHeading = (text) => {
    doc.setFont('helvetica', 'bold')
    doc.setFontSize(13)
    ensureSpace(lineHeight * 1.5)
    doc.text(text, margin, cursorY)
    cursorY += lineHeight
    doc.setFont('helvetica', 'normal')
    doc.setFontSize(12)
  }

  const addTable = ({ headers, rows, columnWidths }) => {
    if (!rows.length) return
    const tableWidth = pageWidth - margin * 2
    const columnCount = headers.length || (rows[0]?.length ?? 0)
    if (!columnCount) return

    const widthFractions = Array.isArray(columnWidths) && columnWidths.length === columnCount
      ? columnWidths
      : null

    const totalFraction = widthFractions ? widthFractions.reduce((sum, val) => sum + val, 0) : 1
    const colWidth = (index) => {
      if (widthFractions) {
        return tableWidth * (widthFractions[index] / (totalFraction || 1))
      }
      if (columnCount === 1) return tableWidth
      if (columnCount === 2) return index === 0 ? tableWidth * 0.55 : tableWidth * 0.45
      const firstWidth = tableWidth * 0.45
      const remainingWidth = tableWidth - firstWidth
      return index === 0 ? firstWidth : remainingWidth / (columnCount - 1)
    }

    const rowHeight = 26
    const totalHeight = rowHeight * (rows.length + 1)
    ensureSpace(totalHeight + lineHeight)

    doc.setFont('helvetica', 'bold')
    doc.setFillColor(240, 243, 248)
    doc.setDrawColor(200, 205, 214)
    let currentX = margin
    headers.forEach((header, index) => {
      const width = colWidth(index)
      doc.rect(currentX, cursorY, width, rowHeight, 'FD')
      doc.text(String(header ?? ''), currentX + 10, cursorY + 17)
      currentX += width
    })
    cursorY += rowHeight
    doc.setFont('helvetica', 'normal')
    doc.setTextColor(28, 33, 44)

    rows.forEach((row, rowIndex) => {
      doc.setFillColor(255, 255, 255)
      doc.setDrawColor(222, 226, 235)
      let xPos = margin
      row.forEach((cell, index) => {
        const width = colWidth(index)
        doc.rect(xPos, cursorY, width, rowHeight)
        if (index === 0) {
          doc.text(String(cell ?? ''), xPos + 10, cursorY + 17)
        } else {
          doc.text(String(cell ?? ''), xPos + width - 10, cursorY + 17, { align: 'right' })
        }
        xPos += width
      })
      cursorY += rowHeight
    })
    cursorY += rowHeight / 2
  }

  const addChart = (chartImage) => {
    if (!chartImage?.dataUrl) return
    const availableWidth = pageWidth - margin * 2
    const ratio = availableWidth / chartImage.width
    const renderedHeight = chartImage.height * ratio
    ensureSpace(renderedHeight + lineHeight)
    doc.addImage(chartImage.dataUrl, 'PNG', margin, cursorY, availableWidth, renderedHeight)
    cursorY += renderedHeight + lineHeight
  }

  addLines(buildAnalyticsReportLines(report))

  addSectionHeading('Usage Reports')
  addTable({
    headers: ['Metric', 'Value'],
    rows: [
      ['Active Users', formatNumber(report.usageReports?.activeUsers?.total ?? 0)],
      ['New Listings', formatNumber(report.usageReports?.newListings ?? 0)],
      ['Simulated Bookings', formatNumber(report.usageReports?.simulatedBookings ?? 0)],
      ['Chatbot Usage', formatNumber(report.usageReports?.chatbotUsage ?? 0)],
    ],
  })

  addSectionHeading('Platform Totals')
  addTable({
    headers: ['Metric', 'Value'],
    rows: [
      ['Total Users', formatNumber(report.totalStats?.totalUsers ?? 0)],
      ['Approved Listings', formatNumber(report.totalStats?.totalListings ?? 0)],
      ['Reviews', formatNumber(report.totalStats?.totalReviews ?? 0)],
      ['Messages', formatNumber(report.totalStats?.totalMessages ?? 0)],
    ],
  })

  const analytics = report.analytics ?? {}
  const dailyLogins = Array.isArray(analytics.dailyLogins) ? analytics.dailyLogins : []
  if (dailyLogins.length) {
    addSectionHeading('Daily Logins')
    addLines(dailyLogins.slice(0, 6).map(entry => `- ${entry.date ?? 'Unknown'}: ${formatNumber(entry.count ?? 0)} logins`))
    addChart(charts.dailyLogins)
  }

  const community = Array.isArray(analytics.communityActiveness) ? analytics.communityActiveness : []
  if (community.length) {
    addSectionHeading('Community Activeness (Top Contributors)')
    addLines(community.slice(0, 5).map((user, index) => {
      const total = sumCommunityMetrics(user)
      const detail = communityMetricConfig
        .map(metric => `${metric.label}: ${formatNumber(user[metric.key] ?? 0)}`)
        .join(', ')
      return `${index + 1}. ${user.userName ?? 'Unknown'} - ${formatNumber(total)} actions (${detail})`
    }))
    addChart(charts.community)
  }

  if (analytics.userActivities) {
    addSectionHeading('Top User Activities')
    addLines(Object.entries(analytics.userActivities).map(([key, value]) => `- ${key}: ${formatNumber(value ?? 0)}`))
    addChart(charts.activities)
  }

  const operators = Array.isArray(analytics.operatorRankings) ? analytics.operatorRankings.slice(0, 5) : []
  if (operators.length) {
    addSectionHeading('Operator Performance (Top 5)')
    addTable({
      headers: ['#', 'Operator', 'Approved', 'Avg Rating', 'Saves'],
      columnWidths: [0.12, 0.46, 0.14, 0.14, 0.14],
      rows: operators.map((op, index) => [
        `#${index + 1}`,
        op.name ?? 'Unknown',
        formatNumber(op.approvedListings ?? 0),
        op.avgRating ?? 0,
        formatNumber(op.totalSaves ?? 0),
      ]),
    })
  }

  addLines(['--- End of report ---'])

  return doc
}

async function downloadAnalyticsReport() {
  if (!data.value || exportingReport.value) return
  exportingReport.value = true
  try {
    const payload = buildAnalyticsReportPayload(data.value)
    const charts = await buildPdfChartImages(payload.analytics ?? {})
    const doc = createAnalyticsPdf(payload, charts)
    const range = payload.range ?? {}
    const baseName = range.start && range.end
      ? `analytics-${range.start}-to-${range.end}`
      : 'analytics-report'
    const safeName = baseName.replace(/[^a-z0-9-]+/gi, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').toLowerCase()
    doc.save(`${safeName || 'analytics-report'}.pdf`)
  } catch (error) {
    console.error('Failed to export analytics report', error)
  } finally {
    exportingReport.value = false
  }
}

// ==================== UTILITIES ====================

function formatNumber(value) {
  return Number(value ?? 0).toLocaleString()
}

function formatDateShort(dateStr) {
  const date = new Date(dateStr)
  return `${date.getMonth() + 1}/${date.getDate()}`
}
</script>

<template>
  <n-space vertical size="large">
    <!-- Header -->
    <n-space justify="space-between" align="center">
      <div>
        <h2 style="margin: 0; font-size: 1.75rem;">📊 System Monitoring and Reports</h2>
        <n-text depth="3" style="font-size: 0.95rem;">
          {{ data?.dateRange ? `${data.dateRange.start} to ${data.dateRange.end}` : 'Select a date range' }}
        </n-text>
      </div>
      <n-space align="center" size="small">
        <n-select v-model:value="selectedRange" :options="rangeOptions" size="small" style="width: 150px;" />
        <n-button type="primary" tertiary size="small" :disabled="!data" :loading="exportingReport"
          @click="downloadAnalyticsReport">
          Download PDF
        </n-button>
      </n-space>
    </n-space>

    <n-spin :show="loading">
      <template v-if="data">
        <n-card title="Generate Usage Reports" :segmented="{ content: true }">
          <n-text depth="3" style="margin-bottom: 16px; display: block;">
            Periodic reports containing active users, new listings, simulated bookings, and chatbot usage trends.
          </n-text>
          <n-grid cols="2 m:4" :x-gap="16" :y-gap="16">
            <n-grid-item v-for="card in usageCards" :key="card.label">
              <n-card size="small" style="min-height: 140px;">
                <n-space vertical size="small">
                  <div style="font-size: 2rem;">{{ card.icon }}</div>
                  <div style="font-size: 2rem; font-weight: 700;" :style="{ color: card.color }">
                    {{ formatNumber(card.value) }}
                  </div>
                  <n-text depth="2" style="font-weight: 600;">{{ card.label }}</n-text>
                  <n-text depth="3" style="font-size: 0.85rem;">{{ card.detail }}</n-text>
                </n-space>
              </n-card>
            </n-grid-item>
          </n-grid>
        </n-card>

        <n-card title="View Analytics" :segmented="{ content: true }">
          <n-text depth="3" style="margin-bottom: 24px; display: block;">
            Dashboard containing real-time analytics such as daily logins, community activeness, top user activities,
            and operator performance.
          </n-text>

          <n-card title="📈 Daily Logins" size="small" style="margin-bottom: 16px;">
            <div v-if="loginChartData" class="chart-container">
              <svg :width="loginChartData.width" :height="loginChartData.height" xmlns="http://www.w3.org/2000/svg">
                <defs>
                  <linearGradient :id="lineGradientId" x1="0%" y1="0%" x2="0%" y2="100%">
                    <stop offset="0%" stop-color="rgba(32, 128, 240, 0.4)" />
                    <stop offset="100%" stop-color="rgba(32, 128, 240, 0)" />
                  </linearGradient>
                </defs>

                <g v-for="label in loginChartData.yLabels" :key="label.y">
                  <line :x1="loginChartData.padding.left" :y1="label.y"
                    :x2="loginChartData.padding.left + loginChartData.chartWidth" :y2="label.y"
                    stroke="rgba(128, 128, 128, 0.1)" stroke-width="1" />
                  <text :x="loginChartData.padding.left - 8" :y="label.y + 4" text-anchor="end"
                    fill="rgba(128, 128, 128, 0.7)" font-size="11">
                    {{ label.value }}
                  </text>
                </g>

                <g v-for="label in loginChartData.xLabels" :key="label.x">
                  <text v-if="label.label" :x="label.x" :y="label.y" text-anchor="middle"
                    fill="rgba(128, 128, 128, 0.7)" font-size="11">
                    {{ label.label }}
                  </text>
                </g>

                <path :d="loginChartData.areaPath" :fill="`url(#${lineGradientId})`" />

                <path :d="loginChartData.linePath" stroke="#2080f0" stroke-width="3" fill="none" stroke-linecap="round"
                  stroke-linejoin="round" />

                <circle v-for="(point, i) in loginChartData.points" :key="i" :cx="point.x" :cy="point.y" r="4"
                  fill="#2080f0" stroke="white" stroke-width="2" />
              </svg>
            </div>
            <n-empty v-else description="Not enough data to generate chart" />
          </n-card>

          <n-card title="💬 Community Activeness" size="small" style="margin-bottom: 16px;">
            <n-space justify="space-between" align="center" style="margin-bottom: 16px;">
              <n-text depth="3">Select chart type to visualize community engagement</n-text>
              <n-button-group>
                <n-button :type="communityChartType === 'table' ? 'primary' : 'default'" size="small"
                  @click="communityChartType = 'table'">
                  📊 Table
                </n-button>
                <n-button :type="communityChartType === 'bar' ? 'primary' : 'default'" size="small"
                  @click="communityChartType = 'bar'">
                  📈 Bar Chart
                </n-button>
                <n-button :type="communityChartType === 'donut' ? 'primary' : 'default'" size="small"
                  @click="communityChartType = 'donut'">
                  🍩 Pie Chart
                </n-button>
              </n-button-group>
            </n-space>

            <n-data-table v-if="communityChartType === 'table' && communityActiveness.length" size="small"
              :columns="communityColumns" :data="communityActiveness" :bordered="false" :single-line="false" striped />

            <div v-else-if="communityChartType === 'bar' && communityActiveness.length && communityMetricTotals.length"
              class="chart-container">
              <div class="community-chart-meta">
                <div class="insight-pill">
                  <span class="pill-label">Total interactions</span>
                  <span class="pill-value">{{ formatNumber(communitySummary.total) }}</span>
                </div>
                <div class="insight-pill">
                  <span class="pill-label">Average per user</span>
                  <span class="pill-value">{{ formatNumber(communitySummary.average) }}</span>
                </div>
                <div v-if="communitySummary.topUser" class="insight-pill highlight">
                  <span class="pill-label">Top contributor</span>
                  <span class="pill-value">{{ communitySummary.topUser }}</span>
                  <span class="pill-sub">{{ formatNumber(communitySummary.topValue) }} actions</span>
                </div>
              </div>

              <div v-if="communityMetricTotals.length" class="community-chart-legend">
                <div v-for="metric in communityMetricTotals" :key="metric.key" class="legend-item">
                  <span class="legend-swatch" :style="{ background: metric.color }"></span>
                  <span class="legend-label">{{ metric.label }}</span>
                  <span class="legend-value">{{ formatNumber(metric.total) }}</span>
                </div>
              </div>

              <div class="chartjs-wrapper bar" :style="{ height: `${communityBarChartHeight}px` }">
                <canvas ref="communityBarCanvas"></canvas>
              </div>
            </div>

            <div v-else-if="communityChartType === 'donut' && communityCategorySegments.length"
              class="chart-container donut-view">
              <div class="chartjs-wrapper donut">
                <canvas ref="communityDonutCanvas"></canvas>
                <div class="donut-center" v-if="communityCategoryDistribution.total">
                  <div class="donut-value">{{ formatNumber(communityCategoryDistribution.total) }}</div>
                  <div class="donut-label">Total Posts</div>
                </div>
              </div>

              <div class="donut-legend">
                <div v-for="segment in communityCategorySegments" :key="segment.label" class="donut-legend-row">
                  <span class="legend-swatch" :style="{ background: segment.color }"></span>
                  <span class="legend-label">{{ segment.label }}</span>
                  <span class="legend-value">{{ formatNumber(segment.value) }}</span>
                  <span class="legend-percentage">({{ segment.percentage.toFixed(1) }}%)</span>
                </div>
              </div>
            </div>

            <n-empty v-else-if="!communityActiveness.length" description="No community activity data available" />
            <n-empty v-else description="Not enough community activity to chart" />
          </n-card>

          <n-card title="🎯 Top User Activities" size="small" style="margin-bottom: 16px;">
            <n-space vertical size="large">
              <div v-for="item in activityBars" :key="item.label" class="activity-bar-item">
                <n-space justify="space-between" align="center" style="margin-bottom: 6px;">
                  <n-text depth="2">{{ item.label }}</n-text>
                  <n-text strong>{{ formatNumber(item.value) }}</n-text>
                </n-space>
                <div class="bar-track">
                  <div class="bar-fill" :style="{
                    width: `${item.percentage}%`,
                    background: item.color,
                  }"></div>
                </div>
              </div>
            </n-space>
          </n-card>

          <n-card title="🏆 Operator Performance Rankings" size="small" style="margin-bottom: 16px;">
            <n-text depth="3" style="margin-bottom: 16px; display: block;">
              Top operators ranked by approved listings, ratings, and saves. Shows active/inactive status based on last
              login.
            </n-text>
            <n-data-table v-if="operatorRankings.length" :columns="operatorColumns" :data="operatorRankings"
              :bordered="false" :single-line="false" :pagination="{ pageSize: 10 }" striped />
            <n-empty v-else description="No operator data available" />
          </n-card>

          <n-card title="📊 Listing Category Distribution" size="small">
            <n-text depth="3" style="margin-bottom: 16px; display: block;">
              Breakdown of approved listings by category with percentage distribution.
            </n-text>
            <n-data-table v-if="categoryData" :columns="categoryColumns" :data="categoryData" :bordered="false"
              :single-line="false" />
            <n-empty v-else description="No category data available" />
          </n-card>
        </n-card>

        <n-card title="📈 Platform Summary" :segmented="{ content: true }">
          <n-grid cols="2 m:4" :x-gap="16" :y-gap="16">
            <n-grid-item v-for="stat in totalStats" :key="stat.label">
              <n-statistic :label="stat.label" :value="formatNumber(stat.value)">
                <template #prefix>
                  <span style="font-size: 1.2rem;">{{ stat.icon }}</span>
                </template>
              </n-statistic>
            </n-grid-item>
          </n-grid>
        </n-card>
      </template>
      <n-empty v-else description="Select a date range to load analytics" />
    </n-spin>
  </n-space>
</template>

<style scoped>
.chart-container {
  width: 100%;
  overflow-x: auto;
  padding: 16px 0;
}

.chart-container svg {
  display: block;
  margin: 0 auto;
}

.chartjs-wrapper {
  position: relative;
  width: 100%;
}

.chartjs-wrapper canvas {
  width: 100% !important;
  height: 100% !important;
}

.chartjs-wrapper.bar {
  min-height: 220px;
}

.chartjs-wrapper.donut {
  max-width: 360px;
  height: 320px;
  margin: 0 auto;
}

.donut-view {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 24px;
}

.donut-center {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  pointer-events: none;
  color: #0f172a;
}

.donut-value {
  font-size: 2.4rem;
  font-weight: 700;
}

.donut-label {
  font-size: 0.85rem;
  color: rgba(15, 23, 42, 0.6);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.donut-legend {
  width: 100%;
  max-width: 420px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.donut-legend-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.9rem;
  color: #0f172a;
}

.donut-legend-row .legend-label {
  flex: 1;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.legend-percentage {
  color: rgba(15, 23, 42, 0.55);
  font-size: 0.85rem;
}

.community-chart-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 12px;
}

.insight-pill {
  flex: 1 1 160px;
  min-width: 150px;
  padding: 8px 14px;
  border-radius: 12px;
  border: 1px solid rgba(32, 128, 240, 0.18);
  background: rgba(32, 128, 240, 0.08);
}

.insight-pill.highlight {
  border-color: rgba(24, 160, 88, 0.35);
  background: rgba(24, 160, 88, 0.12);
}

.pill-label {
  display: block;
  font-size: 0.75rem;
  color: rgba(15, 23, 42, 0.6);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.pill-value {
  display: block;
  font-size: 1.1rem;
  font-weight: 600;
  color: #0f172a;
}

.pill-sub {
  display: block;
  margin-top: 2px;
  font-size: 0.8rem;
  color: rgba(15, 23, 42, 0.65);
}

.community-chart-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 14px;
}

.legend-item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 0.85rem;
  background: rgba(148, 163, 184, 0.18);
}

.legend-swatch {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  display: inline-block;
}

.legend-label {
  color: rgba(15, 23, 42, 0.75);
}

.legend-value {
  font-weight: 600;
  color: #0f172a;
}

.activity-bar-item {
  width: 100%;
}

.bar-track {
  width: 100%;
  height: 10px;
  background: rgba(128, 128, 128, 0.1);
  border-radius: 999px;
  overflow: hidden;
}

.bar-fill {
  height: 100%;
  border-radius: 999px;
  transition: width 0.5s ease;
}

:deep(.n-data-table) {
  font-size: 0.9rem;
}

:deep(.n-data-table th) {
  font-weight: 600;
}

:deep(.n-data-table td) {
  padding: 12px 8px;
}
</style>


