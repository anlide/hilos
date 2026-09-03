import { describe, expect, it } from 'vitest'

import { HilosPages } from '../../src/routing/hilosPages.js'
import { resolvePageTitle } from '../../src/routing/pageTitle.js'

describe('resolvePageTitle', () => {
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

  it('yields the bare label when no application name is given', () => {
    expect(
      resolvePageTitle('main', { main: 'Conversations' }, '', undefined, true),
    ).toBe('Conversations')
  })
})
