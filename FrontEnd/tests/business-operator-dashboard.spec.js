import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import naive from '../src/plugins/naive'
import BusinessOperatorDashboard from '../src/components/BusinessOperatorDashboard.vue'

const createWrapper = () =>
  mount(BusinessOperatorDashboard, {
    global: {
      plugins: [naive],
    },
  })

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const sampleListing = {
  id: 'LST-4321',
  listingId: 4321,
  name: 'Rainforest Homestay',
  category: 'Homestay',
  type: 'Small Business',
  status: 'Pending Review',
  visibility: 'Hidden',
  lastUpdated: '2025-10-24T00:00:00.000Z',
  contact: { phone: '+60 12-345 6789', email: 'hello@example.com' },
  address: 'Kampung Example, Perak',
  highlight: 'Eco-friendly stay.',
  reviewNotes: 'Awaiting administrator verification.',
}

describe('BusinessOperatorDashboard', () => {
  beforeEach(() => {
    window.location.hash = ''
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input, init = {}) => {
        const url = typeof input === 'string' ? input : input?.url ?? ''
        const method = (init.method ?? 'GET').toUpperCase()

        let body = {}
        if (init.body) {
          try {
            body = JSON.parse(init.body)
          } catch {
            body = {}
          }
        }

        const okResponse = (payload) =>
          Promise.resolve({
            ok: true,
            status: 200,
            json: async () => payload,
          })

        if (url.includes('/operator/listings.php')) {
          if (method === 'POST') {
            return okResponse({
              ok: true,
              message: 'Listing created.',
              listing: {
                ...sampleListing,
                name: body.name ?? sampleListing.name,
                category: body.category ?? sampleListing.category,
                contact: {
                  phone: body.phone ?? sampleListing.contact.phone,
                  email: body.email ?? sampleListing.contact.email,
                },
                address: body.address ?? sampleListing.address,
                highlight: body.description ?? sampleListing.highlight,
              },
              operator: {
                id: 1,
                email: body.email ?? sampleListing.contact.email,
                contactNumber: body.phone ?? sampleListing.contact.phone,
              },
            })
          }

          if (method === 'PUT') {
            return okResponse({
              ok: true,
              message: 'Listing updated.',
              listing: {
                ...sampleListing,
                id: `LST-${body.listingId ?? sampleListing.listingId}`,
                listingId: body.listingId ?? sampleListing.listingId,
                name: body.name ?? sampleListing.name,
                status: body.status ?? sampleListing.status,
                visibility: body.visibility ?? sampleListing.visibility,
                contact: {
                  phone: body.phone ?? sampleListing.contact.phone,
                  email: body.email ?? sampleListing.contact.email,
                },
                address: body.address ?? sampleListing.address,
                highlight: body.description ?? sampleListing.highlight,
                lastUpdated: '2025-10-25T00:00:00.000Z',
              },
              operator: {
                id: 1,
                email: body.email ?? sampleListing.contact.email,
                contactNumber: body.phone ?? sampleListing.contact.phone,
              },
            })
          }

          if (method === 'DELETE') {
            return okResponse({
              ok: true,
              deleted: true,
              message: 'Listing removed.',
            })
          }
        }

        return Promise.resolve({
          ok: false,
          status: 404,
          json: async () => ({ ok: false, error: 'Not found' }),
        })
      }),
    )
  })

  afterEach(() => {
    if (typeof vi.unstubAllGlobals === 'function') {
      vi.unstubAllGlobals()
    }
    vi.clearAllMocks()
  })

  it('renders overview header and primary action by default', () => {
    const wrapper = createWrapper()

    const title = wrapper.find('.operator-header__meta .section-title').text()
    const primaryAction = wrapper.find('[data-test="header-primary-action"]').text()

    expect(title).toBe('Tourism Operator Control Center')
    expect(primaryAction).toBe('Start registration flow')

    wrapper.unmount()
  })

  it('updates hero when navigating to manage listings', async () => {
    const wrapper = createWrapper()

    window.dispatchEvent(new CustomEvent('operator:navigate', { detail: 'manage-listings' }))
    await nextTick()
    await flush()

    const title = wrapper.find('.operator-header__meta .section-title').text()
    const subtitle = wrapper.find('.operator-header__meta .n-text').text()
    const primaryAction = wrapper.find('[data-test="header-primary-action"]').text()
    const secondaryRow = wrapper.find('.operator-header__actions')
    expect(secondaryRow.exists()).toBe(true)

    expect(title).toBe('Listing Management')
    expect(subtitle).toContain('Edit details, toggle visibility, and maintain up-to-date information.')
    expect(primaryAction).toBe('Bulk update listings')
    expect(secondaryRow.text()).toContain('Download CSV')

    wrapper.unmount()
  })

  it('updates hero when navigating to guidelines', async () => {
    const wrapper = createWrapper()

    window.dispatchEvent(new CustomEvent('operator:navigate', { detail: 'guidelines' }))
    await nextTick()
    await flush()

    const title = wrapper.find('.operator-header__meta .section-title').text()
    const primaryAction = wrapper.find('[data-test="header-primary-action"]').text()
    const secondaryRow = wrapper.find('.operator-header__actions')
    expect(secondaryRow.exists()).toBe(true)
    expect(title).toBe('Operator Guidelines')
    expect(primaryAction).toBe('Download handbook')
    expect(secondaryRow.text()).toContain('Back to overview')

    wrapper.unmount()
  })

  it('validates business registration form inputs', async () => {
    const wrapper = createWrapper()
    const vm = wrapper.vm

    vm.formState.name = ''
    vm.formState.category = null
    vm.formState.phone = ''
    vm.formState.email = ''
    vm.formState.address = ''
    vm.formState.description = ''

    await vm.submitListing()
    await flush()
    expect(vm.formErrors.name).toBeDefined()
    expect(vm.formErrors.category).toBeDefined()
    expect(vm.formErrors.phone).toBeDefined()
    expect(vm.formErrors.email).toBeDefined()
    expect(vm.formErrors.address).toBeDefined()
    expect(vm.formErrors.description).toBeDefined()

    vm.formState.name = 'Rainforest Homestay'
    vm.formState.category = 'Homestay'
    vm.formState.phone = '+60 12-345 6789'
    vm.formState.email = 'invalid-email'
    vm.formState.address = 'Kampung Example, Perak'
    vm.formState.description = 'Eco-friendly stay.'

    await vm.submitListing()
    await flush()
    expect(vm.formErrors.email).toBeDefined()

    vm.formState.email = 'hello@example.com'
    vm.formState.website = 'invalid-url'
    await vm.submitListing()
    await flush()
    expect(vm.formErrors.website).toBeDefined()

    vm.remoteOperator = {
      id: 1,
      email: 'hello@example.com',
      contactNumber: '+60 12-345 6789',
    }
    vm.formState.website = 'https://example.com'
    await vm.submitListing()
    await flush()
    expect(Object.keys(vm.formErrors).length).toBe(0)
    expect(vm.submissionError).toBeNull()

    wrapper.unmount()
  })

  it('rejects unsupported or oversized media files', async () => {
    const wrapper = createWrapper()
    const vm = wrapper.vm

    const invalidType = new File(['data'], 'malware.exe', {
      type: 'application/x-msdownload',
    })
    vm.handleMediaFileSelect({ file: invalidType })
    expect(vm.mediaErrors.file).toContain('Files must')

    const bigPayload = new Uint8Array(5 * 1024 * 1024 + 10)
    const oversized = new File([bigPayload], 'clip.mp4', { type: 'video/mp4' })
    vm.handleMediaFileSelect({ file: oversized })
    expect(vm.mediaErrors.file).toContain('Files must')

    const valid = new File([new Uint8Array(1024)], 'photo.jpg', { type: 'image/jpeg' })
    vm.handleMediaFileSelect({ file: valid })
    expect(vm.mediaErrors.file).toBeUndefined()
    expect(vm.mediaSelectionMessage).not.toBe('')

    wrapper.unmount()
  })
})
