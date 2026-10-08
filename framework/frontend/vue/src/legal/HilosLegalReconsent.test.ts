import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import {
  RECONSENT_NOW,
  reconsentContent,
  reconsentPreview,
} from '../../../core/test/legal/reconsentFixture.js'
import HilosLegalReconsent from './HilosLegalReconsent.vue'

const PERSON = { name: 'Bob', impersonated: false }

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
