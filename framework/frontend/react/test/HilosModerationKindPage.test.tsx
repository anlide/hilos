import { fireEvent, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { HilosPages } from '@hilos/core/base'

import { HilosModerationKindPage } from '../src/admin/moderation/HilosModerationKindPage.js'
import { HilosView } from '../src/HilosView.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  deliver,
  el,
  harness,
  initial,
  TABLE,
} from './hilosModerationHarness.js'

const PAGE = HilosPages.MODERATION_KIND

describe('HilosModerationKindPage', () => {
  it('draws one live entity head with ordinary breadcrumb navigation, the owner and kind code', () => {
    const h = harness(PAGE)
    h.answer()
    const view = h.mount(<HilosModerationKindPage context={h.context} />)
    expect(
      view
        .getAllByRole('heading', { level: 1 })
        .map((node) => node.textContent),
    ).toEqual(['Profile name'])
    const crumbs = el('hilos-breadcrumb')
    expect(
      within(crumbs)
        .getAllByRole('link')
        .map((node) => [node.textContent, node.getAttribute('href')]),
    ).toEqual([
      ['Hilos', '/hilos'],
      ['Moderation', '/hilos/moderation'],
    ])
    const last = crumbs.querySelector('[aria-current="page"]')!
    expect(within(last as HTMLElement).getByText('Profile name')).toBe(last)
    expect(last.querySelector('a')).toBeNull()
    expect(el('hilos-moderation-kind-visual').getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(el('hilos-moderation-kind-owner').textContent).toBe('Framework')
    expect(el('hilos-moderation-kind-key').textContent).toBe(initial.kind)
    expect(
      within(view.getByRole('navigation', { name: 'Kind pages' }))
        .getAllByRole('link')
        .map((node) => [node.textContent, node.getAttribute('aria-current')]),
    ).toEqual([
      ['Overview', 'page'],
      ['Rules', null],
    ])
    expect(
      view.container.querySelector(
        'button, input, select, textarea, table, [role="tablist"]',
      ),
    ).toBeNull()
    expect(h.navigation.currentTitle.get()).toBe('Profile name · Tasks')
    expect(
      h.sent.filter(
        (frame) => frame.type === 'table_viewport' || frame.type === 'action',
      ),
    ).toEqual([])
    deliver(() => h.update({ ...initial, title: 'Updated name' }))
    expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
      'Updated name',
    )
    expect(within(last as HTMLElement).getByText('Updated name')).toBe(last)
    expect(h.navigation.currentTitle.get()).toBe('Updated name · Tasks')
    view.unmount()
    expect(h.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe('Kind')
    expect(h.context.connection.tableWindowDescriptors()).toEqual({})
  })

  it.each([
    [
      'text',
      'framework',
      0,
      'Text model',
      'Framework',
      'Built-in until you add a rule of your own',
    ],
    [
      'image',
      'framework',
      1,
      'Vision model',
      'Framework',
      '1 rule of your own',
    ],
    [
      'text_with_files',
      'project',
      4,
      'Text model',
      'Project',
      '4 rules of your own',
    ],
  ])(
    'presents %s / %s / %s rules through semantic definitions',
    (input, owner, ownRuleCount, model, badge, rules) => {
      const h = harness(PAGE)
      h.answer([{ ...initial, input, owner, ownRuleCount }])
      h.mount(<HilosModerationKindPage context={h.context} />)
      const details = el('hilos-moderation-kind-overview')
      expect(
        within(details)
          .getAllByRole('term')
          .map((node) => node.textContent),
      ).toEqual(['Checked by', 'Can refuse for', 'Rules in force'])
      expect(within(details).getAllByRole('definition')).toHaveLength(3)
      expect(el('hilos-moderation-kind-model').textContent).toContain(
        `${model} · qwen2.5:3b`,
      )
      expect(el('hilos-moderation-kind-profile').textContent).toBe(
        initial.profile,
      )
      expect(el('hilos-moderation-kind-owner').textContent).toBe(badge)
      expect(el('hilos-moderation-kind-rules').textContent).toBe(rules)
      expect(
        [...el('hilos-moderation-kind-reasons').children].map(
          (node) => node.textContent,
        ),
      ).toEqual(initial.reasons)
    },
  )

  it('keeps a viewer model hidden and applies live model/count changes without another request', () => {
    const h = harness(PAGE, { viewer: true })
    h.answer([{ ...initial, model: { _hidden: true } }])
    const view = h.mount(<HilosModerationKindPage context={h.context} />)
    expect(el('hilos-moderation-kind-model').textContent).toContain('Hidden')
    expect(el('hilos-hidden')).not.toBeNull()
    expect(view.container.textContent).not.toContain('qwen2.5:3b')
    expect(view.container.textContent).not.toContain('[object Object]')
    deliver(() => h.update({ ...initial, model: 'new-model', ownRuleCount: 2 }))
    expect(el('hilos-moderation-kind-model').textContent).toContain('new-model')
    expect(el('hilos-hidden')).toBeNull()
    expect(el('hilos-moderation-kind-rules').textContent).toBe(
      '2 rules of your own',
    )
    expect(view.container.querySelector('button, form')).toBeNull()
    expect(
      h.sent.filter(
        (frame) => frame.type === 'table_viewport' || frame.type === 'action',
      ),
    ).toEqual([])
  })

  it('accepts long declared keys, profiles and reasons without changing their text', () => {
    const long = 'long_value_'.repeat(40)
    const h = harness(PAGE, { kind: long })
    h.answer([
      { ...initial, kind: long, profile: long, reasons: [long, 'spam'] },
    ])
    h.mount(<HilosModerationKindPage context={h.context} />)
    expect(el('hilos-moderation-kind-key').textContent).toBe(long)
    expect(el('hilos-moderation-kind-profile').textContent).toBe(long)
    expect(
      [...el('hilos-moderation-kind-reasons').children].map(
        (node) => node.textContent,
      ),
    ).toEqual([long, 'spam'])
  })

  it("waits for the canonical window, clears route/scope replacements and rejects another kind's late row", () => {
    const h = harness(PAGE)
    const view = h.mount(<HilosModerationKindPage context={h.context} />)
    expect(
      view.getByRole('status', { name: 'Loading moderation kind' }),
    ).not.toBeNull()
    expect(el('hilos-moderation-kind-overview')).toBeNull()
    expect(el('hilos-page-head-skeleton')).not.toBeNull()
    deliver(() => h.answer())
    expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
      'Profile name',
    )
    const previousScope = h.context.scopes.page()
    deliver(() => h.navigation.navigate('/hilos/moderation/project_note'))
    expect(h.context.scopes.page()).not.toBe(previousScope)
    expect(view.container.textContent).not.toContain('Profile name')
    expect(el('hilos-moderation-kind-overview')).toBeNull()
    deliver(() => h.update(initial))
    expect(el('hilos-moderation-kind-overview')).toBeNull()
    const next = {
      ...initial,
      kind: 'project_note',
      title: 'Project note',
      owner: 'project',
    }
    deliver(() => h.answer([next]))
    expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
      'Project note',
    )
    expect(h.navigation.currentTitle.get()).toBe('Project note · Tasks')
    deliver(() => h.context.scopes.openPage(PAGE))
    expect(view.container.textContent).not.toContain('Project note')
    expect(el('hilos-moderation-kind-overview')).toBeNull()
    deliver(() => h.answer([next]))
    expect(el('hilos-moderation-kind-overview')).not.toBeNull()
    deliver(() => h.navigation.navigate('/hilos/moderation'))
    expect(h.context.connection.tableWindowDescriptors()).toEqual({})
    expect(h.controller().rows.get()).toEqual([])
    deliver(() => h.update(initial))
    expect(el('hilos-moderation-kind-overview')).toBeNull()
  })

  it('shows a refused window through the stock accessible state and recovers on a new answer', () => {
    const h = harness(PAGE)
    h.answer()
    const view = h.mount(<HilosModerationKindPage context={h.context} />)
    deliver(() =>
      h.receive('table_window_refused', {
        page: PAGE,
        tableKey: TABLE,
        errorCode: 'unavailable',
      }),
    )
    expect(el('hilos-table-unavailable').getAttribute('role')).toBe('status')
    expect(el('hilos-moderation-kind-overview')).toBeNull()
    expect(view.container.textContent).not.toContain('Built-in')
    deliver(() => h.answer())
    expect(el('hilos-table-unavailable')).toBeNull()
    expect(el('hilos-moderation-kind-overview')).not.toBeNull()
  })

  it('uses the shared owner and supplied Rules link with ordinary page navigation', () => {
    const h = harness(PAGE, { rulesBuilt: true })
    h.answer()
    const view = h.mount(<HilosModerationKindPage context={h.context} />)
    const tabs = view.getByRole('navigation', { name: 'Kind pages' })
    const links = within(tabs).getAllByRole('link')
    expect(
      links.map((node) => [
        node.textContent,
        node.getAttribute('aria-current'),
      ]),
    ).toEqual([
      ['Overview', 'page'],
      ['Rules', null],
    ])
    expect(links[1]!.getAttribute('href')).toBe(
      '/hilos/moderation/hilos_profile_name/rules',
    )
    expect(view.queryByRole('tablist')).toBeNull()
    fireEvent.click(links[1]!)
    expect(h.navigation.currentRoute.get().page).toBe(
      HilosPages.MODERATION_KIND_RULES,
    )
    expect(h.context.connection.tableWindowDescriptors()).toEqual({})
  })

  it('lets the real outlet replace an unknown kind with the server 404', () => {
    const h = harness(PAGE)
    h.answer()
    const pages = {
      [PAGE]: () => <HilosModerationKindPage context={h.context} />,
    }
    const view = h.mount(<HilosView pages={pages} />)
    expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
      'Profile name',
    )
    deliver(() => h.navigation.navigate('/hilos/moderation/unknown'))
    deliver(() =>
      h.receive('subscription_page_error', {
        page: PAGE,
        httpCode: 404,
        errorCode: 'not_found',
        title: 'Kind not found',
        message: 'This kind does not exist.',
      }),
    )
    expect(view.getByText('Kind not found')).not.toBeNull()
    expect(view.getByText('This kind does not exist.')).not.toBeNull()
    expect(el('hilos-moderation-kind-overview')).toBeNull()
    expect(view.container.textContent).not.toContain('Built-in')
    expect(h.context.connection.tableWindowDescriptors()).toEqual({})
  })

  it('replays StrictMode setup/cleanup with a fresh controller and cold window, then follows live updates', () => {
    const h = harness(PAGE)
    h.answer()
    const view = h.mount(<HilosModerationKindPage context={h.context} />, true)
    expect(h.registered).toHaveBeenCalledTimes(2)
    expect(h.unregistered).toHaveBeenCalledTimes(1)
    expect(h.controller(0).rows.get()).toEqual([])
    expect(h.controller()).not.toBe(h.controller(0))
    expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
      'Profile name',
    )
    deliver(() =>
      h.update({ ...initial, title: 'After replay', ownRuleCount: 3 }),
    )
    expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
      'After replay',
    )
    expect(el('hilos-moderation-kind-rules').textContent).toBe(
      '3 rules of your own',
    )
    expect(h.navigation.currentTitle.get()).toBe('After replay · Tasks')
    expect(h.sent.filter((frame) => frame.type === 'table_viewport')).toEqual(
      [],
    )
    const controller = h.controller()
    view.unmount()
    expect(h.unregistered).toHaveBeenCalledTimes(2)
    expect(controller.rows.get()).toEqual([])
    expect(h.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe('Kind')
    expect(h.context.connection.tableWindowDescriptors()).toEqual({})
  })

  it('disposes the previous context/controller and name binding when its inputs are replaced', () => {
    const old = harness(PAGE)
    old.answer()
    const view = old.mount(<HilosModerationKindPage context={old.context} />)
    const controller = old.controller()
    const next = harness(PAGE, { kind: 'project_note' })
    view.rerender(
      <HilosRouterContext.Provider value={next.navigation}>
        <HilosModerationKindPage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    expect(old.unregistered).toHaveBeenCalledTimes(1)
    expect(controller.rows.get()).toEqual([])
    expect(old.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe('Kind')
    deliver(() => old.update({ ...initial, title: 'Old title' }))
    expect(view.container.textContent).not.toContain('Old title')
    expect(el('hilos-moderation-kind-overview')).toBeNull()
    deliver(() =>
      next.answer([{ ...initial, kind: 'project_note', title: 'New title' }]),
    )
    expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
      'New title',
    )
    expect(next.navigation.currentTitle.get()).toBe('New title · Tasks')
    view.unmount()
    expect(next.unregistered).toHaveBeenCalledTimes(1)
  })
})
