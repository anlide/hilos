import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import HilosI18nMarksLegend from './HilosI18nMarksLegend.vue'

describe('HilosI18nMarksLegend', () => {
  it('renders heading, rows with data-id, visible label and meaning, and aria-hidden mark badge', () => {
    const rows = [
      {
        key: 'default',
        mark: {
          icon: 'bi-star-fill',
          tone: 'text-bg-primary-subtle text-primary-emphasis',
          label: 'Default language',
        },
        meaning: 'the default language meaning',
      },
      {
        key: 'own',
        mark: {
          icon: 'bi-question-circle',
          tone: 'text-bg-warning-subtle text-warning-emphasis',
          label: 'Custom language',
        },
        meaning: 'custom language meaning',
      },
    ]

    const view = mount(HilosI18nMarksLegend, {
      props: {
        rows,
        dataId: 'test-legend',
      },
    })

    const section = view.get('section[data-id="test-legend"]')
    expect(section.get('h2').text()).toContain('What the marks mean')

    for (const row of rows) {
      const rowItem = section.get(`[data-id="test-legend-${row.key}"]`)
      const dt = rowItem.get('dt')
      expect(dt.text()).toContain(row.mark.label)

      const badge = dt.get('span.badge')
      expect(badge.attributes('aria-hidden')).toBe('true')
      expect(badge.attributes('title')).toBe(row.mark.label)
      for (const tone of row.mark.tone.split(' ')) {
        expect(badge.classes()).toContain(tone)
      }
      expect(badge.get('i.bi').classes()).toContain(row.mark.icon)

      const dd = rowItem.get('dd')
      expect(dd.text()).toBe(row.meaning)
    }
  })
})
