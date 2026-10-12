import { describe, expect, it } from 'vitest'
import {
  hilosChildLinks,
  hilosCrumbLinks,
  hilosDashboardLinks,
  resolveHilosPath,
} from '../../src/routing/hilosAdmin.js'
import { type HilosDashboardSection } from '../../src/admin/identity/hilosPageIdentity.js'
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

describe('admin route links', () => {
  it.each(['node-1', 'node /?#%', '%2F', 'узел🌍'])(
    'roundtrips %s through framework, child and breadcrumb resolvers',
    (nodeId) => {
      const router = createAppPageRouter(
        { main: { path: '/', admin: false } },
        { fallback: 'main' },
      )
      const params = { nodeId }
      const child = {
        page: HilosPages.DAEMON_WORKERS,
        label: 'Workers',
        lead: '',
        icon: null,
      }
      const crumb = { page: HilosPages.DAEMON_WORKERS, label: 'Workers' }
      const expected = `/hilos/daemon/${encodeURIComponent(nodeId)}/workers`
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
