import { describe, expect, it } from 'vitest'
import {
  hilosChildLinks,
  hilosCrumbLinks,
  hilosDashboardLinks,
  hilosTabLinks,
  resolveHilosPath,
} from '../../src/routing/hilosAdmin.js'
import { type HilosDashboardSection } from '../../src/routing/hilosPageIdentity.js'
import { createAppPageRouter } from '../../src/routing/appPageRouter.js'
import { HilosPages } from '../../src/routing/hilosPages.js'

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

describe('hilosTabLinks', () => {
  it('keeps the row order and drops a tab not built in this view layer', () => {
    const tabs = [
      { page: 'owner', label: 'Overview' },
      { page: 'unbuilt', label: 'Unbuilt' },
      { page: 'names', label: 'Names' },
    ]
    expect(
      hilosTabLinks(tabs, {}, (page) =>
        page === 'unbuilt' ? undefined : `/${page}`,
      ),
    ).toStrictEqual([
      { page: 'owner', label: 'Overview', to: '/owner' },
      { page: 'names', label: 'Names', to: '/names' },
    ])
    expect(tabs).toHaveLength(3)
  })

  it('drops a tab whose route needs a param the current route does not carry', () => {
    const router = createAppPageRouter({ main: { path: '/', admin: false } })
    const tabs = [
      { page: HilosPages.I18N_COUNTRY, label: 'Overview' },
      { page: HilosPages.I18N_COUNTRY_NAMES, label: 'Names' },
    ]
    expect(hilosTabLinks(tabs, {}, router.path)).toStrictEqual([])
    expect(hilosTabLinks(tabs, { nodeId: 'node-1' }, router.path)).toEqual([])
    expect(hilosTabLinks(tabs, { countryId: '102' }, router.path)).toEqual([
      {
        page: HilosPages.I18N_COUNTRY,
        label: 'Overview',
        to: '/hilos/i18n/country/102',
      },
      {
        page: HilosPages.I18N_COUNTRY_NAMES,
        label: 'Names',
        to: '/hilos/i18n/country/102/names',
      },
    ])
  })
})

describe('admin route links', () => {
  it('keeps an addressless intermediate crumb as text in its place', () => {
    expect(
      hilosCrumbLinks(
        [
          { page: 'root', label: 'Root' },
          { page: 'nowhere', label: 'Nowhere' },
          { page: 'owner', label: 'Owner' },
        ],
        {},
        (page) => (page === 'nowhere' ? undefined : `/${page}`),
      ),
    ).toStrictEqual([
      { page: 'root', label: 'Root', to: '/root' },
      { page: 'nowhere', label: 'Nowhere' },
      { page: 'owner', label: 'Owner', to: '/owner' },
    ])
  })

  it('keeps country tab addresses on primary ids when names are equal or renamed', () => {
    const router = createAppPageRouter({ main: { path: '/', admin: false } })
    const tabs = (countryId: string, overview: string) =>
      hilosTabLinks(
        [
          { page: HilosPages.I18N_COUNTRY, label: overview },
          { page: HilosPages.I18N_COUNTRY_NAMES, label: 'Names' },
        ],
        { countryId },
        router.path,
      ).map((link) => link.to)

    expect(tabs('102', 'Overview')).toEqual([
      '/hilos/i18n/country/102',
      '/hilos/i18n/country/102/names',
    ])
    expect(tabs('140', 'Overview')).toEqual([
      '/hilos/i18n/country/140',
      '/hilos/i18n/country/140/names',
    ])
    expect(tabs('102', 'Renamed / 🌍')).toEqual(tabs('102', 'Overview'))
    expect(tabs('140', '')).toEqual(tabs('140', 'Overview'))
  })

  it('addresses equally named countries by their primary ids and keeps URLs on rename', () => {
    const router = createAppPageRouter({ main: { path: '/', admin: false } })
    const links = (countryId: string, label: string) =>
      hilosCrumbLinks(
        [
          { page: HilosPages.I18N_COUNTRY, label },
          { page: HilosPages.I18N_COUNTRY_NAMES, label: 'Names' },
        ],
        { countryId },
        router.path,
      )

    const first = links('102', 'Unnamed country')
    const second = links('140', 'Unnamed country')
    expect(first.map((link) => link.to)).toEqual([
      '/hilos/i18n/country/102',
      '/hilos/i18n/country/102/names',
    ])
    expect(second.map((link) => link.to)).toEqual([
      '/hilos/i18n/country/140',
      '/hilos/i18n/country/140/names',
    ])
    expect(links('102', 'Renamed / 🌍').map((link) => link.to)).toEqual(
      first.map((link) => link.to),
    )
    expect(links('140', '').map((link) => link.to)).toEqual(
      second.map((link) => link.to),
    )
  })

  it.each(['node-1', 'node /?#%', '%2F', 'узел🌍'])(
    'roundtrips %s through framework, child and breadcrumb resolvers',
    (nodeId) => {
      const router = createAppPageRouter({ main: { path: '/', admin: false } })
      const params = { nodeId }
      const child = {
        page: HilosPages.DAEMON_WORKERS,
        label: 'Workers',
        lead: '',
        icon: null,
      }
      const crumb = { page: HilosPages.DAEMON_WORKERS, label: 'Workers' }
      const expected = `/hilos/daemon/node/${encodeURIComponent(nodeId)}/workers`
      const paths = [
        resolveHilosPath(child.page, params),
        hilosChildLinks([child], params, router.path)[0].to,
        hilosCrumbLinks([crumb], params, router.path)[0].to,
      ]
      for (const path of paths) {
        expect(path).toBe(expected)
        expect(router.match(path!)).toEqual({
          page: child.page,
          params,
          admin: true,
        })
      }
      const logs = resolveHilosPath(HilosPages.LOGS_VIEW, {
        nodeId,
        source: 'live',
      })
      expect(router.match(logs).params).toEqual({ nodeId, source: 'live' })
    },
  )
})
