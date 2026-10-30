import { fireEvent, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { HilosPages } from '@hilos/core/base'

import { HilosModerationPage } from '../src/admin/moderation/HilosModerationPage.js'
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

const rows = [
  { ...initial, ownRuleCount: 2 },
  {
    ...initial,
    kind: 'hilos_profile_photo',
    title: 'Profile photo',
    input: 'image',
    model: { _hidden: true } as const,
  },
  {
    ...initial,
    kind: 'project_note',
    title: 'Project note',
    owner: 'project',
    input: 'text_with_files',
    ownRuleCount: 1,
  },
]

describe('HilosModerationPage', () => {
  it('renders the section head and the real named table with owner, models, counts and live navigation', () => {
    const h = harness(HilosPages.MODERATION, { viewer: true })
    h.answer(rows)
    const view = h.mount(<HilosModerationPage context={h.context} />)
    const heading = view.getByRole('heading', { level: 1, name: 'Moderation' })
    expect(view.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    const table = view.getByRole('table', { name: 'Moderation' })
    expect(table.getAttribute('aria-labelledby')).toBe(heading.id)
    expect(
      within(table)
        .getAllByRole('columnheader')
        .slice(0, 5)
        .map((cell) => cell.textContent),
    ).toEqual(['Kind', 'Declared by', 'Checked by', 'Rules', ''])
    const tableRows = within(table).getAllByRole('row').slice(1)
    expect(tableRows).toHaveLength(3)
    expect(tableRows[0]!.textContent).toContain('hilos_profile_name')
    expect(tableRows[0]!.textContent).toContain('Framework')
    expect(tableRows[0]!.textContent).toContain('Text modelqwen2.5:3b')
    expect(tableRows[0]!.textContent).toContain('2 rules')
    expect(tableRows[1]!.textContent).toContain('Vision modelHidden')
    expect(tableRows[1]!.textContent).toContain('Built-in')
    expect(tableRows[1]!.textContent).not.toContain('qwen2.5:3b')
    expect(tableRows[2]!.textContent).toContain('Project')
    expect(tableRows[2]!.textContent).toContain('1 rule')
    const open = within(table).getByRole('link', { name: 'Open Profile name' })
    expect(open.getAttribute('href')).toBe(
      '/hilos/moderation/hilos_profile_name',
    )
    expect(view.queryByRole('tablist')).toBeNull()
    expect(el('hilos-page-tabs')).toBeNull()
    expect(
      view.queryByRole('button', { name: /add|create|delete/i }),
    ).toBeNull()
    expect(
      h.sent.filter(
        (frame) => frame.type === 'table_viewport' || frame.type === 'action',
      ),
    ).toEqual([])
    fireEvent.click(open)
    expect(h.navigation.currentRoute.get().params.kind).toBe(
      'hilos_profile_name',
    )
    expect(h.navigation.currentRoute.get().page).toBe(
      HilosPages.MODERATION_KIND,
    )
  })

  it('keeps loading, refused and empty windows distinct and recovers from a fresh answer', () => {
    const h = harness(HilosPages.MODERATION)
    const view = h.mount(<HilosModerationPage context={h.context} />)
    expect(el('hilos-page-head-skeleton')).not.toBeNull()
    expect(view.queryByRole('heading', { level: 1 })).toBeNull()
    expect(view.container.textContent).not.toContain(
      'No moderation kinds declared.',
    )
    deliver(() =>
      h.receive('table_window_refused', {
        page: HilosPages.MODERATION,
        tableKey: TABLE,
        errorCode: 'unavailable',
      }),
    )
    expect(el('hilos-table-unavailable').getAttribute('role')).toBe('status')
    expect(view.container.textContent).not.toContain('Built-in')
    deliver(() => h.answer([]))
    expect(el('hilos-table-unavailable')).toBeNull()
    expect(el('hilos-table-empty-title').textContent).toBe(
      'No moderation kinds declared.',
    )
    deliver(() => h.answer(rows))
    expect(view.getByRole('table', { name: 'Moderation' })).not.toBeNull()
    expect(el('hilos-table-empty')).toBeNull()
  })

  it('survives StrictMode replay with cold-window data and releases every controller and registration', () => {
    const h = harness(HilosPages.MODERATION)
    h.answer(rows)
    const view = h.mount(<HilosModerationPage context={h.context} />, true)
    expect(h.registered).toHaveBeenCalledTimes(2)
    expect(h.unregistered).toHaveBeenCalledTimes(1)
    expect(h.controller(0).rows.get()).toEqual([])
    const controller = h.controller()
    expect(controller).not.toBe(h.controller(0))
    expect(
      view.getByRole('table', { name: 'Moderation' }).textContent,
    ).toContain('Profile name')
    deliver(() =>
      h.update({ ...rows[0]!, title: 'Live title', ownRuleCount: 3 }),
    )
    expect(view.getByRole('table').textContent).toContain('Live title')
    expect(view.getByRole('table').textContent).toContain('3 rules')
    expect(h.sent.filter((frame) => frame.type === 'table_viewport')).toEqual(
      [],
    )
    view.unmount()
    expect(h.unregistered).toHaveBeenCalledTimes(2)
    expect(controller.rows.get()).toEqual([])
    expect(h.context.connection.tableWindowDescriptors()).toEqual({})
    deliver(() => h.update({ ...initial, title: 'Late title' }))
    expect(controller.rows.get()).toEqual([])
  })

  it('replaces the root and Overview scopes through the real outlet under StrictMode', () => {
    const h = harness(HilosPages.MODERATION)
    h.answer(rows)
    const pages = {
      [HilosPages.MODERATION]: () => (
        <HilosModerationPage context={h.context} />
      ),
      [HilosPages.MODERATION_KIND]: () => (
        <HilosModerationKindPage context={h.context} />
      ),
    }
    const view = h.mount(<HilosView pages={pages} />, true)
    const rootScope = h.context.scopes.page()
    const rootController = h.controller()
    const table = view.getByRole('table', { name: 'Moderation' })
    fireEvent.click(
      within(table).getByRole('link', { name: 'Open Profile name' }),
    )
    expect(h.context.scopes.page()).not.toBe(rootScope)
    expect(rootController.rows.get()).toEqual([])
    deliver(() => h.answer([initial], HilosPages.MODERATION_KIND))
    expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
      'Profile name',
    )
    expect(view.queryByRole('table')).toBeNull()
    const kindController = h.controller()
    const kindScope = h.context.scopes.page()
    deliver(() => h.update({ ...initial, title: 'Late root title' }))
    expect(view.container.textContent).not.toContain('Late root title')
    fireEvent.click(
      within(view.getByRole('navigation', { name: 'Breadcrumb' })).getByRole(
        'link',
        { name: 'Moderation' },
      ),
    )
    expect(h.context.scopes.page()).not.toBe(kindScope)
    expect(kindController.rows.get()).toEqual([])
    deliver(() => h.answer(rows))
    expect(
      view.getByRole('table', { name: 'Moderation' }).textContent,
    ).toContain('Profile photo')
    expect(h.navigation.currentTitle.get()).toBe('Moderation · Tasks')
    view.unmount()
    expect(h.unregistered).toHaveBeenCalledTimes(h.registered.mock.calls.length)
    expect(h.context.connection.tableWindowDescriptors()).toEqual({})
  })

  it('replaces a context without retaining the old scope or accepting its later rows', () => {
    const old = harness(HilosPages.MODERATION)
    old.answer(rows)
    const view = old.mount(<HilosModerationPage context={old.context} />)
    const controller = old.controller()
    const next = harness(HilosPages.MODERATION)
    view.rerender(
      <HilosRouterContext.Provider value={next.navigation}>
        <HilosModerationPage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    expect(old.unregistered).toHaveBeenCalledTimes(1)
    expect(controller.rows.get()).toEqual([])
    deliver(() => old.update({ ...initial, title: 'Old scope' }))
    expect(view.container.textContent).not.toContain('Old scope')
    expect(view.container.textContent).not.toContain('Profile name')
    deliver(() => next.answer([{ ...initial, title: 'New scope' }]))
    expect(view.container.textContent).toContain('New scope')
  })
})
