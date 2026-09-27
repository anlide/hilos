import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { change } from '../../../core/test/legal/fixtures.js'
import HilosLegalChanges from './HilosLegalChanges.vue'

describe('HilosLegalChanges', () => {
  it('keeps both responsive layouts mounted and both sides with each clause', () => {
    const view = mount(HilosLegalChanges, {
      props: { changes: [change], fromLabel: 'old', toLabel: 'new' },
    })
    for (const layout of ['wide', 'narrow']) {
      const block = view.get(`[data-id="legal-changes-${layout}"]`)
      expect(block.findAll('[data-id="legal-change-row"]')).toHaveLength(1)
      expect(block.text()).toContain('Old wording')
      expect(block.text()).toContain('First paragraph.')
      expect(block.text()).toContain('Project deviation')
    }
    expect(view.get('[data-id="legal-changes-wide"]').classes()).toContain(
      'd-md-block',
    )
    expect(view.get('[data-id="legal-changes-narrow"]').classes()).toContain(
      'd-md-none',
    )
  })
  it('names the missing side of additions and removals, and an empty comparison', () => {
    const view = mount(HilosLegalChanges, {
      props: {
        changes: [
          { ...change, kind: 'added', before: null },
          { ...change, clauseKey: 'removed', kind: 'removed', after: null },
        ],
        fromLabel: 'old',
        toLabel: 'new',
      },
    })
    expect(view.text()).toContain('Not present')
    const empty = mount(HilosLegalChanges, {
      props: { changes: [], fromLabel: 'old', toLabel: 'new' },
    })
    expect(empty.get('[data-id="legal-changes-empty"]').text()).toBe(
      'No clause changed',
    )
  })
})
