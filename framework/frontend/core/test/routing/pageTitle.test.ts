import { describe, expect, it } from 'vitest'

import { HilosPages } from '../../src/routing/hilosPages.js'
import { resolvePageTitle } from '../../src/routing/pageTitle.js'

describe('resolvePageTitle', () => {
  it('titles a framework admin page from its catalog label', () => {
    expect(resolvePageTitle(HilosPages.SETTINGS, {}, 'Hilos Todo')).toBe(
      'Settings · Hilos Todo',
    )
  })

  it('titles a public footer page from its catalog label', () => {
    expect(resolvePageTitle(HilosPages.ABOUT, {}, 'Hilos Todo')).toBe(
      'About · Hilos Todo',
    )
  })

  it('titles a project page from the supplied titles', () => {
    expect(resolvePageTitle('main', { main: 'Conversations' }, 'Chat')).toBe(
      'Conversations · Chat',
    )
  })

  it('lets a project title win over the framework label', () => {
    expect(
      resolvePageTitle(
        HilosPages.SETTINGS,
        { [HilosPages.SETTINGS]: 'Config' },
        'App',
      ),
    ).toBe('Config · App')
  })

  it('falls back to the application name for an unknown page', () => {
    expect(resolvePageTitle('nope', {}, 'Chat')).toBe('Chat')
  })

  it('yields the bare label when no application name is given', () => {
    expect(resolvePageTitle('main', { main: 'Conversations' }, '')).toBe(
      'Conversations',
    )
  })
})
