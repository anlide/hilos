import { describe, expect, it } from 'vitest'

import { HilosPages } from '../../src/routing/hilosPages.js'
import { resolvePageTitle } from '../../src/routing/pageTitle.js'

describe('resolvePageTitle', () => {
  it('titles public Status before any handshake or page answer, preserving project priority', () => {
    expect(
      resolvePageTitle(HilosPages.PUBLIC_STATUS, {}, '', undefined, false),
    ).toBe('Status')
    expect(
      resolvePageTitle(HilosPages.PUBLIC_STATUS, {}, 'Demo', undefined, false),
    ).toBe('Status · Demo')
    expect(
      resolvePageTitle(
        HilosPages.PUBLIC_STATUS,
        { [HilosPages.PUBLIC_STATUS]: 'Service health' },
        'Demo',
        'Old catalog',
        false,
        'Old owner',
      ),
    ).toBe('Service health · Demo')
    expect(resolvePageTitle(HilosPages.STATUS, {}, '', undefined, false)).toBe(
      '',
    )
  })

  it('titles an admin page from the heading its answer carried', () => {
    expect(
      resolvePageTitle(
        HilosPages.SETTINGS,
        {},
        'Hilos Tasks',
        'Settings',
        true,
      ),
    ).toBe('Settings · Hilos Tasks')
  })

  it('titles a public footer page from its frontend label', () => {
    expect(
      resolvePageTitle(HilosPages.ABOUT, {}, 'Hilos Tasks', undefined, true),
    ).toBe('About · Hilos Tasks')
  })

  it('titles a project page from the supplied titles', () => {
    expect(
      resolvePageTitle(
        'main',
        { main: 'Conversations' },
        'Chat',
        undefined,
        true,
      ),
    ).toBe('Conversations · Chat')
  })

  it('lets a project title win over the catalog heading', () => {
    expect(
      resolvePageTitle(
        HilosPages.SETTINGS,
        { [HilosPages.SETTINGS]: 'Config' },
        'App',
        'Settings',
        true,
      ),
    ).toBe('Config · App')
  })

  it('titles a project page from its own map before the page answers', () => {
    // A title the frontend already holds needs nothing from the wire, so the tab
    // is named the instant the navigation happens.
    expect(
      resolvePageTitle(
        'main',
        { main: 'Conversations' },
        'Chat',
        undefined,
        false,
      ),
    ).toBe('Conversations · Chat')
  })

  it('titles nothing at all while an admin page is still on the wire', () => {
    // The empty string is load-bearing: the shell sets document.title only on a
    // non-empty value and announces the same value in a live region, so an
    // interim application name would announce every navigation twice.
    expect(
      resolvePageTitle(HilosPages.SETTINGS, {}, 'Chat', undefined, false),
    ).toBe('')
  })

  it('falls back to the application name once a page answers with no heading', () => {
    expect(resolvePageTitle('nope', {}, 'Chat', undefined, true)).toBe('Chat')
  })

  it('names a route miss immediately, ignoring the catalog and the wait', () => {
    expect(
      resolvePageTitle(null, { main: 'Home' }, 'Demo', 'Settings', false),
    ).toBe('Page Not Found · Demo')
    expect(resolvePageTitle(null, {}, '', 'Settings', false)).toBe(
      'Page Not Found',
    )
  })

  it('yields the bare label when no application name is given', () => {
    expect(
      resolvePageTitle('main', { main: 'Conversations' }, '', undefined, true),
    ).toBe('Conversations')
  })

  it.each([HilosPages.I18N_COUNTRY, HilosPages.I18N_COUNTRY_NAMES])(
    'uses the owner label on %s ahead of the subscribing tab heading',
    (page) => {
      expect(resolvePageTitle(page, {}, 'Demo', 'Names', true, 'Ireland')).toBe(
        'Ireland · Demo',
      )
      expect(resolvePageTitle(page, {}, '', 'Names', true, 'Ireland')).toBe(
        'Ireland',
      )
      expect(resolvePageTitle(page, {}, '', 'Names', true, '')).toBe('')
    },
  )

  it('keeps project and footer labels ahead of an owner label', () => {
    expect(
      resolvePageTitle(
        HilosPages.I18N_COUNTRY,
        { [HilosPages.I18N_COUNTRY]: 'Custom title' },
        'Demo',
        'Country',
        true,
        'Ireland',
      ),
    ).toBe('Custom title · Demo')
    expect(
      resolvePageTitle(HilosPages.ABOUT, {}, 'Demo', 'Catalog', true, 'Owner'),
    ).toBe('About · Demo')
  })

  it('ignores an owner on a route miss and retains loading and error titles', () => {
    expect(resolvePageTitle(null, {}, 'Demo', 'Names', false, 'Ireland')).toBe(
      'Page Not Found · Demo',
    )
    expect(
      resolvePageTitle(HilosPages.I18N_COUNTRY, {}, 'Demo', undefined, false),
    ).toBe('')
    expect(
      resolvePageTitle(HilosPages.I18N_COUNTRY, {}, 'Demo', undefined, true),
    ).toBe('Demo')
  })
})
