import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createSignal,
  HILOS_PAGE_ROUTES,
  HilosPages,
  type HilosRouter,
} from '@hilos/core/base'
import {
  RECONSENT_NOW,
  reconsentContent,
  reconsentPreview,
} from '../../../core/test/legal/reconsentFixture.js'
import { hilosRouterKey } from '../hilosRouterKey.js'
import HilosLegalReconsent from './HilosLegalReconsent.vue'

const PERSON = { name: 'Bob', impersonated: false }

afterEach(() => {
  vi.useRealTimers()
})

/**
 * Mount options providing a router that only records where a link went, so a
 * link that leaves is seen leaving and the document stays where it is.
 *
 * @param navigated Where the links went, in order.
 */
function recordingRouter(navigated: string[]) {
  const router = {
    currentPath: createSignal(''),
    navigate: (path: string) => navigated.push(path),
  } as unknown as HilosRouter

  return { provide: { [hilosRouterKey as symbol]: router } }
}

/**
 * Whether a disabled fieldset around the element turns it off. happy-dom
 * matches `:disabled` on an element's own attribute only, so the lock a
 * fieldset gives is read off the fieldset itself.
 *
 * @param element The control to read.
 */
function lockedByFieldset(element: Element): boolean {
  return element.closest('fieldset[disabled]') !== null
}

/**
 * Press an element the way a person does: a primary click that can be canceled.
 *
 * @param element The element pressed.
 * @returns The click, to read whether it was canceled.
 */
function press(element: Element): MouseEvent {
  const click = new MouseEvent('click', {
    bubbles: true,
    cancelable: true,
    button: 0,
  })
  element.dispatchEvent(click)

  return click
}

describe('the "the terms have changed" screen (HIL-500)', () => {
  it('draws a section per document with its plate, the accepted revision and the changes', () => {
    const view = mount(HilosLegalReconsent, {
      props: {
        variant: 'window',
        content: reconsentContent(),
        view: { kind: 'changes' },
        person: PERSON,
        now: RECONSENT_NOW,
      },
    })
    const sections = view.findAll('[data-id="legal-reconsent-document"]')
    expect(sections.map((item) => item.attributes('data-document'))).toEqual([
      'terms',
      'privacy',
    ])
    const plates = view.findAll('[data-id="legal-reconsent-badge"]')
    expect(plates[0]!.text()).toContain('9 days left')
    expect(plates[0]!.text()).toContain('until 10 November 2026')
    expect(plates[0]!.attributes('data-tone')).toBe('warning')
    expect(plates[1]!.text()).toContain('deadline passed 27 September 2026')
    expect(sections[0]!.text()).toContain(
      'You accepted the revision of 17 September 2026. Since then 2 clauses changed',
    )
    const changes = sections[0]!.findAll('[data-id="legal-reconsent-change"]')
    expect(changes).toHaveLength(2)
    expect(changes[0]!.text()).toContain('Wording changed')
    expect(changes[1]!.text()).toContain('Data may be wiped')
    expect(changes[1]!.text()).toContain('Before: No promise of uptime')
    expect(
      view
        .findAll('[data-id="legal-reconsent-change-kind"]')
        .map((item) => item.text()),
    ).toEqual(['Changed', 'Changed', 'Added'])
    expect(view.get('[data-id="legal-reconsent-person"]').text()).toContain(
      'Bob',
    )
    expect(view.find('[data-id="legal-reconsent-exits"]').exists()).toBe(false)
    view.unmount()
  })

  it('asks for the full text and the comparison and draws them with a way back', async () => {
    const view = mount(HilosLegalReconsent, {
      props: {
        variant: 'window',
        content: reconsentContent(),
        view: { kind: 'changes' },
        now: RECONSENT_NOW,
      },
    })
    await view
      .get('[data-id="legal-reconsent-full-text"][data-document="terms"]')
      .trigger('click')
    expect(view.emitted('show')?.[0]).toEqual([
      { kind: 'text', document: 'terms' },
    ])
    await view.setProps({ view: { kind: 'text', document: 'terms' } })
    expect(view.find('[data-id="legal-revision-text"]').exists()).toBe(true)
    expect(view.find('[data-id="legal-reconsent-document"]').exists()).toBe(
      false,
    )
    await view.setProps({ view: { kind: 'compare', document: 'terms' } })
    expect(view.find('[data-id="legal-changes"]').exists()).toBe(true)
    await view.get('[data-id="legal-reconsent-back"]').trigger('click')
    expect(view.emitted('show')?.at(-1)).toEqual([{ kind: 'changes' }])
    view.unmount()
  })

  it('opens the refusal step, words it by the setting, and closes like Later', async () => {
    const view = mount(HilosLegalReconsent, {
      props: {
        variant: 'window',
        content: reconsentContent('freeze'),
        view: { kind: 'changes' },
        now: RECONSENT_NOW,
      },
    })
    await view.get('[data-id="legal-reconsent-refuse"]').trigger('click')
    expect(view.emitted('show')?.[0]).toEqual([{ kind: 'refuse' }])
    await view.setProps({ view: { kind: 'refuse' } })
    const step = view.get('[data-id="legal-reconsent-refuse-step"]')
    expect(step.text()).toContain(
      'you will not be able to use the product until you accept',
    )
    expect(step.find('[data-id="legal-reconsent-data-link"]').exists()).toBe(
      true,
    )
    expect(step.find('[data-id="legal-reconsent-delete-link"]').exists()).toBe(
      true,
    )
    expect(view.find('[data-id="legal-reconsent-accept"]').exists()).toBe(false)
    await view.get('[data-id="legal-reconsent-close"]').trigger('click')
    expect(view.emitted('later')).toHaveLength(1)
    await view.setProps({ content: reconsentContent('remind') })
    expect(
      view.get('[data-id="legal-reconsent-refuse-step"]').text(),
    ).toContain('Nothing changes: this reminder will keep coming back.')
    view.unmount()
  })

  it('emits Later and Accept, and holds Accept while the content is read', async () => {
    const view = mount(HilosLegalReconsent, {
      props: {
        variant: 'window',
        content: null,
        view: { kind: 'changes' },
        loading: true,
      },
    })
    expect(view.find('[data-id="legal-reconsent-loading"]').exists()).toBe(true)
    expect(
      view.get('[data-id="legal-reconsent-accept"]').attributes('disabled'),
    ).toBeDefined()
    await view.setProps({ loading: false, content: reconsentContent() })
    await view.get('[data-id="legal-reconsent-later"]').trigger('click')
    await view.get('[data-id="legal-reconsent-accept"]').trigger('click')
    expect(view.emitted('later')).toHaveLength(1)
    expect(view.emitted('accept')).toHaveLength(1)
    view.unmount()
  })

  it('blocks Accept by its own acceptance at once and keeps its look until the spinner (HIL-1766)', async () => {
    vi.useFakeTimers()
    const view = mount(HilosLegalReconsent, {
      props: {
        variant: 'window',
        content: reconsentContent(),
        view: { kind: 'changes' },
        busy: true,
        now: RECONSENT_NOW,
      },
    })
    const accept = view.get('[data-id="legal-reconsent-accept"]')
    expect(accept.attributes('aria-busy')).toBe('true')
    expect(accept.attributes('aria-disabled')).toBe('true')
    expect(accept.attributes('disabled')).toBeUndefined()
    expect(accept.classes()).not.toContain('disabled')
    expect(accept.find('[data-id="loading-button-spinner"]').exists()).toBe(
      false,
    )
    await accept.trigger('click')
    expect(view.emitted('accept')).toBeUndefined()
    await vi.advanceTimersByTimeAsync(300)
    expect(accept.find('[data-id="loading-button-spinner"]').exists()).toBe(
      true,
    )
    expect(accept.classes()).toContain('disabled')
    await view.setProps({ busy: false })
    expect(accept.attributes('aria-disabled')).toBeUndefined()
    await accept.trigger('click')
    expect(view.emitted('accept')).toHaveLength(1)
    view.unmount()
  })

  it('holds the window in place while its acceptance is out, Accept outside the lock (HIL-1763)', async () => {
    const navigated: string[] = []
    const view = mount(HilosLegalReconsent, {
      props: {
        variant: 'window',
        content: reconsentContent(),
        view: { kind: 'changes' },
        busy: true,
        person: PERSON,
        now: RECONSENT_NOW,
      },
      global: recordingRouter(navigated),
    })
    const lock = view.get('fieldset')
    expect(lock.attributes('disabled')).toBeDefined()
    const accept = view.get('[data-id="legal-reconsent-accept"]')
    expect(lockedByFieldset(accept.element)).toBe(false)
    expect(accept.attributes('aria-busy')).toBe('true')
    expect(accept.attributes('disabled')).toBeUndefined()
    for (const id of [
      'legal-reconsent-not-you',
      'legal-reconsent-full-text',
      'legal-reconsent-compare',
    ]) {
      expect(lockedByFieldset(view.get(`[data-id="${id}"]`).element)).toBe(true)
    }
    for (const id of ['legal-reconsent-refuse', 'legal-reconsent-later']) {
      const button = view.get(`[data-id="${id}"]`)
      expect(lockedByFieldset(button.element)).toBe(false)
      expect(button.attributes('disabled')).toBeDefined()
      ;(button.element as HTMLButtonElement).click()
    }

    await view.setProps({ view: { kind: 'text', document: 'terms' } })
    expect(
      lockedByFieldset(view.get('[data-id="legal-reconsent-back"]').element),
    ).toBe(true)

    await view.setProps({ view: { kind: 'refuse' } })
    for (const id of ['legal-reconsent-back', 'legal-reconsent-close']) {
      const button = view.get(`[data-id="${id}"]`)
      expect(button.attributes('disabled')).toBeDefined()
      ;(button.element as HTMLButtonElement).click()
    }
    const links = [
      view.get('[data-id="legal-reconsent-data-link"]'),
      view.get('[data-id="legal-reconsent-delete-link"]'),
    ]
    for (const link of links) {
      expect(link.attributes('aria-disabled')).toBe('true')
      expect(link.attributes('tabindex')).toBe('-1')
      expect(press(link.element).defaultPrevented).toBe(true)
    }
    expect(view.emitted('later')).toBeUndefined()
    expect(view.emitted('show')).toBeUndefined()
    expect(navigated).toEqual([])
    expect(view.get('fieldset').element).toBe(lock.element)

    await view.setProps({ busy: false })
    expect(lock.attributes('disabled')).toBeUndefined()
    for (const link of links) {
      expect(link.attributes('aria-disabled')).toBeUndefined()
      expect(link.attributes('tabindex')).toBeUndefined()
    }
    press(links[0]!.element)
    expect(navigated).toEqual([HILOS_PAGE_ROUTES[HilosPages.PROFILE_DATA]])
    expect(view.emitted('later')).toHaveLength(1)
    await view.get('[data-id="legal-reconsent-close"]').trigger('click')
    expect(view.emitted('later')).toHaveLength(2)
    await view.get('[data-id="legal-reconsent-back"]').trigger('click')
    expect(view.emitted('show')).toEqual([[{ kind: 'changes' }]])
    view.unmount()
  })

  it('leaves the freeze its exits and the preview its own reasons while busy, as before (HIL-1763)', async () => {
    const navigated: string[] = []
    const frozen = mount(HilosLegalReconsent, {
      props: {
        variant: 'frozen',
        content: reconsentContent(),
        view: { kind: 'changes' },
        busy: true,
        person: PERSON,
        deletionScheduled: true,
        now: RECONSENT_NOW,
      },
      global: recordingRouter(navigated),
    })
    expect(frozen.get('fieldset').attributes('disabled')).toBeUndefined()
    expect(
      frozen.get('[data-id="legal-reconsent-accept"]').attributes('aria-busy'),
    ).toBe('true')
    for (const id of [
      'legal-reconsent-not-you',
      'legal-reconsent-keep-account',
      'legal-reconsent-sign-out',
    ]) {
      expect(
        frozen.get(`[data-id="${id}"]`).attributes('disabled'),
      ).toBeUndefined()
    }
    const data = frozen.get('[data-id="legal-reconsent-data-link"]')
    expect(data.attributes('aria-disabled')).toBeUndefined()
    press(data.element)
    expect(navigated).toEqual([HILOS_PAGE_ROUTES[HilosPages.PROFILE_DATA]])
    await frozen
      .get('[data-id="legal-reconsent-full-text"][data-document="terms"]')
      .trigger('click')
    expect(frozen.emitted('show')).toEqual([
      [{ kind: 'text', document: 'terms' }],
    ])
    frozen.unmount()

    const preview = mount(HilosLegalReconsent, {
      props: {
        variant: 'preview',
        content: reconsentPreview('window'),
        view: { kind: 'changes' },
        busy: true,
        now: RECONSENT_NOW,
      },
    })
    expect(preview.get('fieldset').attributes('disabled')).toBeUndefined()
    expect(
      preview.get('[data-id="legal-reconsent-refuse"]').attributes('disabled'),
    ).toBeDefined()
    expect(
      preview.get('[data-id="legal-reconsent-later"]').attributes('disabled'),
    ).toBeDefined()
    await preview.get('[data-id="legal-reconsent-full-text"]').trigger('click')
    expect(preview.emitted('show')).toEqual([
      [{ kind: 'text', document: 'terms' }],
    ])
    preview.unmount()
  })

  it('holds Accept when nothing is left to accept', () => {
    const view = mount(HilosLegalReconsent, {
      props: {
        variant: 'frozen',
        content: { ...reconsentContent(), documents: [] },
        view: { kind: 'changes' },
      },
    })
    expect(
      view.get('[data-id="legal-reconsent-accept"]').attributes('disabled'),
    ).toBeDefined()
    view.unmount()
  })

  it('shows a failed read with a retry', async () => {
    const view = mount(HilosLegalReconsent, {
      props: {
        variant: 'window',
        content: null,
        view: { kind: 'changes' },
        error: 'The terms could not be loaded.',
      },
    })
    expect(view.get('[data-id="legal-reconsent-error"]').text()).toContain(
      'The terms could not be loaded.',
    )
    await view.get('[data-id="legal-reconsent-retry"]').trigger('click')
    expect(view.emitted('retry')).toHaveLength(1)
    view.unmount()
  })

  it('keeps only Accept on the freeze and lays the exits out as actions', () => {
    const view = mount(HilosLegalReconsent, {
      props: {
        variant: 'frozen',
        content: reconsentContent(),
        view: { kind: 'changes' },
        person: PERSON,
        deletionScheduled: true,
        now: RECONSENT_NOW,
      },
    })
    expect(view.get('[data-id="legal-reconsent-heading"]').text()).toBe(
      'The terms were not accepted',
    )
    expect(view.find('[data-id="legal-reconsent-later"]').exists()).toBe(false)
    expect(view.find('[data-id="legal-reconsent-refuse"]').exists()).toBe(false)
    expect(view.find('[data-id="legal-reconsent-accept"]').exists()).toBe(true)
    const lapsed = view.findAll('[data-id="legal-reconsent-badge"]')[1]!
    expect(lapsed.text()).toContain('account frozen · deadline passed')
    expect(lapsed.attributes('data-tone')).toBe('info')
    const exits = view.get('[data-id="legal-reconsent-exits"]')
    expect(exits.find('[data-id="legal-reconsent-data-link"]').exists()).toBe(
      true,
    )
    expect(
      exits.find('[data-id="legal-reconsent-keep-account"]').exists(),
    ).toBe(true)
    expect(exits.find('[data-id="legal-reconsent-sign-out"]').exists()).toBe(
      true,
    )
    view.unmount()
  })

  it('offers no deletion to call off when none is scheduled', () => {
    const view = mount(HilosLegalReconsent, {
      props: {
        variant: 'frozen',
        content: reconsentContent(),
        view: { kind: 'changes' },
        person: PERSON,
      },
    })
    expect(view.find('[data-id="legal-reconsent-keep-account"]').exists()).toBe(
      false,
    )
    view.unmount()
  })

  it('takes the acceptance away under a takeover and says who may accept', () => {
    const view = mount(HilosLegalReconsent, {
      props: {
        variant: 'frozen',
        content: reconsentContent(),
        view: { kind: 'changes' },
        person: { name: 'Bob', impersonated: true },
        deletionScheduled: true,
      },
    })
    expect(view.find('[data-id="legal-reconsent-accept"]').exists()).toBe(false)
    expect(view.find('[data-id="legal-reconsent-keep-account"]').exists()).toBe(
      false,
    )
    expect(view.find('[data-id="legal-reconsent-not-you"]').exists()).toBe(
      false,
    )
    expect(view.get('[data-id="legal-reconsent-impersonated"]').text()).toBe(
      'Only Bob can accept the terms.',
    )
    view.unmount()
  })

  it('previews through the previous holder with every button inactive', () => {
    const plates = (['window', 'lapsed', 'editorial'] as const).map((kind) => {
      const view = mount(HilosLegalReconsent, {
        props: {
          variant: 'preview',
          content: reconsentPreview(kind),
          view: { kind: 'changes' },
          now: RECONSENT_NOW,
        },
      })
      for (const button of [
        'legal-reconsent-refuse',
        'legal-reconsent-later',
        'legal-reconsent-accept',
      ]) {
        expect(
          view.get(`[data-id="${button}"]`).attributes('disabled'),
        ).toBeDefined()
      }
      const text = view.get('[data-id="legal-reconsent-badge"]').text()
      view.unmount()
      return text
    })
    expect(plates).toEqual([
      '9 days left · until 10 November 2026',
      'No window · in force since 27 September 2026',
      'Editorial · nobody is asked to accept it',
    ])
  })

  it('draws a line instead of the screen for a first revision', () => {
    const view = mount(HilosLegalReconsent, {
      props: {
        variant: 'preview',
        content: reconsentPreview('first'),
        view: { kind: 'changes' },
      },
    })
    expect(view.get('[data-id="legal-reconsent-preview-first"]').text()).toBe(
      'This is the first revision: there is nothing to compare it with, and nobody sees this screen.',
    )
    expect(view.find('[data-id="legal-reconsent-accept"]').exists()).toBe(false)
    view.unmount()
  })

  it('draws the confirmed address under the name when present and omits it otherwise', () => {
    const withAddress = mount(HilosLegalReconsent, {
      props: {
        variant: 'window',
        content: reconsentContent('freeze', 'bob@example.com'),
        view: { kind: 'changes' },
        person: PERSON,
      },
    })
    expect(withAddress.get('[data-id="legal-reconsent-address"]').text()).toBe(
      'bob@example.com',
    )
    withAddress.unmount()

    const loading = mount(HilosLegalReconsent, {
      props: {
        variant: 'window',
        content: null,
        loading: true,
        view: { kind: 'changes' },
        person: PERSON,
      },
    })
    expect(loading.find('[data-id="legal-reconsent-address"]').exists()).toBe(
      false,
    )
    loading.unmount()

    const noAddress = mount(HilosLegalReconsent, {
      props: {
        variant: 'window',
        content: reconsentContent('freeze', null),
        view: { kind: 'changes' },
        person: PERSON,
      },
    })
    expect(noAddress.find('[data-id="legal-reconsent-address"]').exists()).toBe(
      false,
    )
    noAddress.unmount()

    const impersonated = mount(HilosLegalReconsent, {
      props: {
        variant: 'frozen',
        content: reconsentContent('freeze', null),
        view: { kind: 'changes' },
        person: { name: 'Bob', impersonated: true },
      },
    })
    expect(
      impersonated.find('[data-id="legal-reconsent-address"]').exists(),
    ).toBe(false)
    impersonated.unmount()

    const preview = mount(HilosLegalReconsent, {
      props: {
        variant: 'preview',
        content: reconsentPreview('window'),
        view: { kind: 'changes' },
        person: null,
      },
    })
    expect(preview.find('[data-id="legal-reconsent-person"]').exists()).toBe(
      false,
    )
    preview.unmount()
  })
})
