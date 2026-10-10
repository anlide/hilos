import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import HilosI18nCatalogNote from './HilosI18nCatalogNote.vue'

describe('HilosI18nCatalogNote', () => {
  it('renders heading counts, slot text, data-id, aria-hidden icon, and avoids role="alert"', () => {
    const view = mount(HilosI18nCatalogNote, {
      props: {
        catalog: { languageCount: 12, countryCount: 34 },
        dataId: 'test-catalog-note',
      },
      slots: {
        default: 'Explaining what the catalog provides.',
      },
    })

    const section = view.get('section[data-id="test-catalog-note"]')
    expect(section.attributes('role')).toBeUndefined()
    expect(view.find('[role="alert"]').exists()).toBe(false)

    const heading = section.get('h2')
    expect(heading.text()).toBe(
      'The system knows 12 languages and 34 countries on its own',
    )

    const paragraph = section.get('p')
    expect(paragraph.text()).toBe('Explaining what the catalog provides.')

    const icon = section.get('i.bi-database-check')
    expect(icon.attributes('aria-hidden')).toBe('true')
  })
})
