import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import type { HilosUserMergeSection } from '@hilos/core/admin/users'

import HilosUserMergeSectionView from './HilosUserMergeSection.vue'

// The details panel is a HilosModal, which teleports to <body>.
const mounted: VueWrapper[] = []

afterEach(() => {
  for (const wrapper of mounted) {
    wrapper.unmount()
  }
  mounted.length = 0
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

/**
 * Mount the section. Focus tests attach it so the details button can hold
 * focus and receive it back.
 */
function mountSection(
  section: HilosUserMergeSection | null,
  attach = false,
): VueWrapper {
  const wrapper = mount(HilosUserMergeSectionView, {
    props: { section },
    attachTo: attach ? document.body : undefined,
  })
  mounted.push(wrapper)
  return wrapper
}

/** The normalized text of every node carrying this data-id. */
function lines(wrapper: VueWrapper, id: string): string[] {
  return wrapper
    .findAll(`[data-id="${id}"]`)
    .map((node) => (node.text() ?? '').replace(/\s+/g, ' ').trim())
}

/**
 * What a person sees: text outside hidden twins and outside the voice, which
 * repeats the status for announcement rather than as a second sentence.
 */
function visibleWords(wrapper: VueWrapper): string {
  const root = wrapper.get('[data-id="hilos-user-merge"]').element
  const parts: string[] = []
  const walk = (node: Node): void => {
    if (!(node instanceof Element)) {
      return
    }
    if (node.getAttribute('aria-hidden') === 'true') {
      return
    }
    if (node.getAttribute('role') === 'status') {
      return
    }
    if (node.childNodes.length === 0) {
      return
    }
    for (const child of node.childNodes) {
      if (child.nodeType === Node.TEXT_NODE) {
        parts.push(child.textContent ?? '')
      } else {
        walk(child)
      }
    }
  }
  walk(root)
  return parts.join(' ').replace(/\s+/g, ' ').trim()
}

describe('HilosUserMergeSection', () => {
  describe('current facts', () => {
    it('shows the status, the unfinished parts and the waits', () => {
      const wrapper = mountSection({
        status: 'Merge in progress',
        successful: false,
        pendingParts: [
          {
            key: '["give","survivor",74]',
            participant: 'Transfer account data',
            account: 74,
          },
        ],
        waits: [
          {
            key: '["receive",74]',
            participant: 'Accept account data',
            account: 74,
          },
        ],
        refusal: null,
      })

      expect(wrapper.get('h2').text()).toBe('Account merge')
      expect(wrapper.get('[data-id="hilos-user-merge-status"]').text()).toBe(
        'Merge in progress',
      )
      expect(lines(wrapper, 'hilos-user-merge-part')).toEqual([
        'Transfer account data · #74',
      ])
      expect(lines(wrapper, 'hilos-user-merge-wait')).toEqual([
        'Accept account data · #74',
      ])
      expect(wrapper.findAll('h3').map((heading) => heading.text())).toEqual([
        'Unfinished parts',
        'Waiting for participants',
      ])
    })
  })

  describe('an incomplete or hidden outcome is not a success', () => {
    it('keeps an unfinished merge as its status and its remaining part', () => {
      const wrapper = mountSection({
        status: 'Preparing merge',
        successful: false,
        pendingParts: [
          {
            key: '["prepare_survivor","survivor",null]',
            participant: 'Prepare receiving account',
            account: null,
          },
        ],
        waits: [],
        refusal: null,
      })

      expect(wrapper.get('[data-id="hilos-user-merge-status"]').text()).toBe(
        'Preparing merge',
      )
      expect(lines(wrapper, 'hilos-user-merge-part')).toEqual([
        'Prepare receiving account',
      ])
      expect(wrapper.find('[data-id="hilos-user-merge-wait"]').exists()).toBe(
        false,
      )
      expect(visibleWords(wrapper)).not.toContain('Merge completed')
    })

    it('does not turn an empty list or a hidden account into success or into nothing happening', () => {
      const wrapper = mountSection({
        status: 'Merge finished',
        successful: false,
        pendingParts: [],
        waits: [
          {
            key: '["sessions",null]',
            participant: 'Close source sessions',
            account: null,
          },
        ],
        refusal: null,
      })

      expect(wrapper.get('[data-id="hilos-user-merge-status"]').text()).toBe(
        'Merge finished',
      )
      expect(lines(wrapper, 'hilos-user-merge-wait')).toEqual([
        'Close source sessions',
      ])
      expect(wrapper.find('[data-id="hilos-user-merge-part"]').exists()).toBe(
        false,
      )
      expect(wrapper.findAll('h3').map((heading) => heading.text())).toEqual([
        'Waiting for participants',
      ])
      const words = visibleWords(wrapper)
      expect(words).not.toContain('Merge completed')
      expect(words.toLowerCase()).not.toContain('nothing')
    })

    it('draws the frame and no claim while the section is absent', () => {
      const wrapper = mountSection(null)

      expect(wrapper.get('h2').text()).toBe('Account merge')
      expect(wrapper.get('[data-id="hilos-user-merge-status"]').text()).toBe('')
      expect(
        wrapper.get('[data-id="hilos-user-merge-status-live"]').text(),
      ).toBe('')
      expect(wrapper.find('[data-id="hilos-user-merge-part"]').exists()).toBe(
        false,
      )
      expect(wrapper.find('[data-id="hilos-user-merge-wait"]').exists()).toBe(
        false,
      )
      const words = visibleWords(wrapper)
      expect(words).toBe('Account merge')
      expect(words.toLowerCase()).not.toContain('nothing')
    })
  })

  describe('refusal details stay current and close when cleared', () => {
    const first = 'The source account is already being merged.'
    const second = 'The receiving account closed before the merge could finish.'

    function refused(reason: string | null): HilosUserMergeSection {
      return {
        status: reason === null ? 'Preparing merge' : 'Merge refused',
        successful: false,
        pendingParts: [
          {
            key: '["give","survivor",8]',
            participant: 'Transfer account data',
            account: 8,
          },
        ],
        waits: [
          {
            key: '["receive",8]',
            participant: 'Accept account data',
            account: 8,
          },
        ],
        refusal: reason,
      }
    }

    it('opens the whole current reason and drops the panel when the refusal clears', async () => {
      const wrapper = mountSection(refused(first))

      expect(lines(wrapper, 'hilos-user-merge-part')).toEqual([
        'Transfer account data · #8',
      ])
      expect(lines(wrapper, 'hilos-user-merge-wait')).toEqual([
        'Accept account data · #8',
      ])
      const row = wrapper.get('[data-id="hilos-user-merge-refusal"]')
      expect(row.text()).toContain(first)
      expect(row.attributes('role')).toBeUndefined()
      expect(row.attributes('aria-live')).toBeUndefined()
      expect(
        wrapper.find('[data-id="hilos-user-merge-refusal-type"]').exists(),
      ).toBe(false)

      await wrapper
        .get('[data-id="hilos-user-merge-refusal-details"]')
        .trigger('click')
      await flushPromises()

      expect(document.querySelector('.modal-title')?.textContent).toBe(
        "Couldn't merge the accounts",
      )
      expect(
        document.querySelector('[data-id="hilos-user-merge-refusal-full"]')
          ?.textContent,
      ).toBe(first)
      expect(
        document.querySelector('[data-id="hilos-user-merge-refusal-detail"]'),
      ).toBeNull()

      await wrapper.setProps({ section: refused(second) })
      await flushPromises()

      expect(document.querySelector('[role="dialog"]')).not.toBeNull()
      expect(
        document.querySelector('[data-id="hilos-user-merge-refusal-full"]')
          ?.textContent,
      ).toBe(second)

      await wrapper.setProps({ section: refused(null) })
      await flushPromises()

      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(
        wrapper.find('[data-id="hilos-user-merge-refusal"]').exists(),
      ).toBe(false)
      expect(
        wrapper.find('[data-id="hilos-user-merge-refusal-idle"]').exists(),
      ).toBe(true)
      expect(document.body.textContent?.includes(second)).toBe(false)
    })
  })

  describe('viewer-safe data', () => {
    it('shows a participant as text and hides an account the section left unnamed', () => {
      const wrapper = mountSection({
        status: 'Merge in progress',
        successful: false,
        pendingParts: [
          {
            key: '["give","survivor",null]',
            participant: '<img src=x onerror=alert(1)>',
            account: null,
          },
        ],
        waits: [
          {
            key: '["sessions",null]',
            participant: 'Close source sessions',
            account: null,
          },
        ],
        refusal: null,
      })

      const part = wrapper.get('[data-id="hilos-user-merge-part"]')
      expect(part.text()).toBe('<img src=x onerror=alert(1)>')
      expect(part.find('img').exists()).toBe(false)
      expect(part.text()).not.toMatch(/#\d/)
      expect(wrapper.get('[data-id="hilos-user-merge-wait"]').text()).toBe(
        'Close source sessions',
      )
      expect(wrapper.find('a').exists()).toBe(false)
      expect(wrapper.find('button').exists()).toBe(false)
      expect(wrapper.find('input').exists()).toBe(false)
    })

    it('offers no start, cancel, fate or proof control beside the refusal', () => {
      const wrapper = mountSection({
        status: 'Merge refused',
        successful: false,
        pendingParts: [],
        waits: [],
        refusal: 'Refused by the server.',
      })

      const buttons = wrapper.findAll('button').map((button) => ({
        text: button.text(),
        name: button.attributes('aria-label') ?? '',
      }))
      expect(buttons).toEqual([{ text: '', name: 'Show error details' }])
    })
  })

  describe('permanent live regions, heading and keyboard details', () => {
    it('keeps the heading and both regions before there is anything to say', () => {
      const wrapper = mountSection(null)

      expect(wrapper.get('h2').text()).toBe('Account merge')
      const live = wrapper.get('[data-id="hilos-user-merge-status-live"]')
      expect(live.attributes('role')).toBe('status')
      expect(live.attributes('aria-live')).toBe('polite')
      expect(live.text()).toBe('')
      const slot = wrapper.get('[data-id="hilos-user-merge-refusal-slot"]')
      expect(slot.attributes('role')).toBe('alert')
      expect(slot.attributes('aria-live')).toBe('assertive')
      const status = wrapper.get('[data-id="hilos-user-merge-status"]')
      expect(status.attributes('role')).toBeUndefined()
      expect(status.attributes('aria-live')).toBeUndefined()
      expect(status.text()).toBe('')
      expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
      expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
      expect(
        wrapper
          .findAll('[aria-hidden="true"]')
          .some((node) => node.text() === 'Releasing merge reservations'),
      ).toBe(true)
    })

    it('keeps the same regions when the words arrive and leaves the visible line without a role', async () => {
      const wrapper = mountSection(null)
      const live = wrapper.get(
        '[data-id="hilos-user-merge-status-live"]',
      ).element
      const slot = wrapper.get(
        '[data-id="hilos-user-merge-refusal-slot"]',
      ).element

      await wrapper.setProps({
        section: {
          status: 'Releasing merge reservations',
          successful: false,
          pendingParts: [],
          waits: [],
          refusal: 'Could not release the reservation.',
        },
      })

      expect(
        wrapper.get('[data-id="hilos-user-merge-status-live"]').element,
      ).toBe(live)
      expect(
        wrapper.get('[data-id="hilos-user-merge-refusal-slot"]').element,
      ).toBe(slot)
      expect(live.textContent?.trim()).toBe('Releasing merge reservations')
      const status = wrapper.get('[data-id="hilos-user-merge-status"]')
      expect(status.text()).toBe('Releasing merge reservations')
      expect(status.attributes('role')).toBeUndefined()
      expect(status.attributes('aria-live')).toBeUndefined()
      const row = wrapper.get('[data-id="hilos-user-merge-refusal"]')
      expect(row.attributes('role')).toBeUndefined()
      expect(row.attributes('aria-live')).toBeUndefined()
      expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
      expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
    })

    it('closes the details from the keyboard and returns focus to the button', async () => {
      const wrapper = mountSection(
        {
          status: 'Merge refused',
          successful: false,
          pendingParts: [],
          waits: [],
          refusal: 'The source account is already being merged.',
        },
        true,
      )
      const button = wrapper.get('[data-id="hilos-user-merge-refusal-details"]')
      ;(button.element as HTMLButtonElement).focus()
      await button.trigger('click')
      await flushPromises()

      const dialog = document.querySelector<HTMLElement>('[role="dialog"]')
      expect(dialog).not.toBeNull()
      expect(
        document.querySelector('[data-id="hilos-user-merge-refusal-full"]')
          ?.textContent,
      ).toBe('The source account is already being merged.')

      dialog?.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
      await flushPromises()

      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(document.activeElement).toBe(button.element)
    })
  })

  describe('a cleared section leaves no previous operation and sends nothing', () => {
    it('drops the previous facts and the open refusal without emitting', async () => {
      const wrapper = mountSection(
        {
          status: 'Merge in progress',
          successful: false,
          pendingParts: [
            {
              key: '["give","survivor",74]',
              participant: 'Transfer account data',
              account: 74,
            },
          ],
          waits: [
            {
              key: '["receive",3]',
              participant: 'Accept account data',
              account: 3,
            },
          ],
          refusal: 'The merge was refused.',
        },
        true,
      )
      expect(wrapper.emitted()).toEqual({})
      await wrapper
        .get('[data-id="hilos-user-merge-refusal-details"]')
        .trigger('click')
      await flushPromises()
      expect(document.querySelector('[role="dialog"]')).not.toBeNull()

      const emittedBeforeClear = wrapper.emitted()
      await wrapper.setProps({ section: null })
      await flushPromises()

      expect(wrapper.get('h2').text()).toBe('Account merge')
      expect(wrapper.get('[data-id="hilos-user-merge-status"]').text()).toBe('')
      expect(
        wrapper.get('[data-id="hilos-user-merge-status-live"]').text(),
      ).toBe('')
      expect(wrapper.find('[data-id="hilos-user-merge-part"]').exists()).toBe(
        false,
      )
      expect(wrapper.find('[data-id="hilos-user-merge-wait"]').exists()).toBe(
        false,
      )
      expect(
        wrapper.find('[data-id="hilos-user-merge-refusal"]').exists(),
      ).toBe(false)
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(document.body.textContent).not.toContain('Transfer account data')
      expect(document.body.textContent).not.toContain('Accept account data')
      expect(document.body.textContent).not.toContain('The merge was refused.')
      expect(visibleWords(wrapper)).toBe('Account merge')
      expect(wrapper.emitted()).toEqual(emittedBeforeClear)
    })
  })
})
