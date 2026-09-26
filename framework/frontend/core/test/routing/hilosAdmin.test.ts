import { describe, expect, it } from 'vitest'
import { hilosDashboardLinks } from '../../src/routing/hilosAdmin.js'
import { type HilosDashboardSection } from '../../src/admin/identity/hilosPageIdentity.js'

describe('hilosDashboardLinks', () => {
  it('drops cards without addresses and empty sections, preserving catalog order', () => {
    const item = (page: string) => ({
      page,
      label: page,
      lead: 'Lead',
      icon: null,
    })
    const sections: HilosDashboardSection[] = [
      {
        title: 'First',
        description: 'One',
        items: [item('a'), item('hidden'), item('b')],
      },
      { title: 'Empty', description: 'Two', items: [item('hidden')] },
      { title: 'Last', description: 'Three', items: [item('c')] },
    ]
    expect(
      hilosDashboardLinks(sections, (page) =>
        page === 'hidden' ? undefined : `/${page}`,
      ),
    ).toEqual([
      {
        title: 'First',
        description: 'One',
        items: [
          { ...item('a'), to: '/a' },
          { ...item('b'), to: '/b' },
        ],
      },
      {
        title: 'Last',
        description: 'Three',
        items: [{ ...item('c'), to: '/c' }],
      },
    ])
    expect(sections).toHaveLength(3)
    expect(sections[0].items).toHaveLength(3)
  })
})
