import { act, fireEvent, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import {
  HilosPages,
  hilosToasts,
  type TableViewportController,
} from '@hilos/core/base'
import {
  type HilosModerationKindRow,
  type HilosModerationRuleRow,
} from '@hilos/core/admin/moderation'

import { HilosModerationKindPage } from '../src/admin/moderation/HilosModerationKindPage.js'
import { HilosModerationKindRulesPage } from '../src/admin/moderation/HilosModerationKindRulesPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import { HilosView } from '../src/HilosView.js'
import {
  deliver,
  el,
  harness,
  initial,
  savedRule,
  TABLE,
} from './hilosModerationHarness.js'

const PAGE = HilosPages.MODERATION_KIND_RULES
const RULES = 'hilosModerationRules'
type Form = 'add' | 'edit' | 'delete'

/** Script only the socket; the production page owns both windows and all forms. */
function rulesHarness(
  options: { viewer?: boolean; strict?: boolean; cold?: boolean } = {},
) {
  const h = harness(PAGE, options)
  function answer(
    row: HilosModerationKindRow = { ...initial, ownRuleCount: 1 },
    rule = savedRule,
  ) {
    h.answer(
      [row],
      PAGE,
      row.ownRuleCount === 0 ? [] : [{ id: 1, kind: row.kind, rule }],
    )
  }
  if (!options.cold) answer()
  const view = h.mount(
    <HilosModerationKindRulesPage context={h.context} />,
    options.strict,
  )
  return {
    ...h,
    view,
    answer,
    rules: () =>
      h.registered.mock.calls.at(
        -1,
      )![1] as TableViewportController<HilosModerationRuleRow>,
    writes: () => h.sent.filter((frame) => frame.type === 'action'),
    open(form: Form) {
      const opener =
        form === 'add'
          ? view.getByRole('button', { name: 'Add rule' })
          : within(view.getByRole('table')).getByRole('button', {
              name: form === 'edit' ? 'Edit rule' : 'Delete rule',
            })
      opener.focus()
      fireEvent.click(opener)
      return opener
    },
    incoming(rule: string, kind = initial.kind) {
      h.receive('table_viewport_delta', {
        page: PAGE,
        tableKey: RULES,
        kind: 'row_updated',
        rowKey: '1',
        row: { rowKey: '1', slots: { rule: { id: 1, kind, rule } } },
      })
    },
    gone() {
      h.receive('table_viewport_delta', {
        page: PAGE,
        tableKey: RULES,
        kind: 'row_removed',
        rowKey: '1',
        reason: 'deleted',
      })
    },
    reply(requestId?: string, success = true) {
      const request = h.sent
        .filter(
          (frame) =>
            frame.type === 'action' &&
            (requestId === undefined || frame.requestId === requestId),
        )
        .at(-1)!
      h.receive(
        success ? 'action_success' : 'action_error',
        {
          action: request.action,
          ...(success
            ? { message: 'Rule written.' }
            : {
                reason: 'The saved rule no longer matches.',
                errorType: 'ValidationException',
              }),
        },
        { requestId: request.requestId, outcome: success ? 'success' : 'fail' },
      )
    },
  }
}

function field(form: 'add' | 'edit'): HTMLTextAreaElement {
  return el(`hilos-moderation-rule-${form}-rule`) as HTMLTextAreaElement
}

function input(form: 'add' | 'edit', value: string): void {
  fireEvent.change(field(form), { target: { value } })
}

function submit(form: Form): void {
  if (form === 'delete')
    fireEvent.click(el('hilos-moderation-rule-delete-confirm'))
  else fireEvent.submit(field(form).form!)
}

describe('HilosModerationKindRulesPage', () => {
  it('waits for canonical windows, then draws one live entity head, the current Rules tab and one named table without a fetch', () => {
    const h = rulesHarness({ cold: true })
    expect(
      h.view.getByRole('status', { name: 'Loading moderation rules' }),
    ).not.toBeNull()
    expect(el('hilos-page-head-skeleton')).not.toBeNull()
    expect(h.view.queryByRole('table')).toBeNull()
    expect(el('hilos-moderation-rules-built-in')).toBeNull()
    deliver(() => h.answer({ ...initial, model: { _hidden: true } }))
    expect(
      h.view
        .getAllByRole('heading', { level: 1 })
        .map((node) => node.textContent),
    ).toEqual(['Profile name'])
    const tabs = within(h.view.getByRole('navigation', { name: 'Kind pages' }))
    expect(
      tabs
        .getAllByRole('link')
        .map((node) => [
          node.textContent,
          node.getAttribute('href'),
          node.getAttribute('aria-current'),
        ]),
    ).toEqual([
      ['Overview', '/hilos/moderation/hilos_profile_name', null],
      ['Rules', '/hilos/moderation/hilos_profile_name/rules', 'page'],
    ])
    expect(h.view.queryByRole('tablist')).toBeNull()
    const crumbs = within(
      h.view.getByRole('navigation', { name: 'Breadcrumb' }),
    )
    expect(crumbs.getAllByRole('link').map((node) => node.textContent)).toEqual(
      ['Hilos', 'Moderation'],
    )
    expect(crumbs.getByText('Profile name').getAttribute('aria-current')).toBe(
      'page',
    )
    expect(el('hilos-moderation-kind-owner').textContent).toBe('Framework')
    expect(el('hilos-moderation-kind-key').textContent).toBe(initial.kind)
    expect(h.view.getAllByRole('table', { name: 'Profile name' })).toHaveLength(
      1,
    )
    const panel = el('hilos-moderation-rules-built-in')
    expect(within(panel).getByRole('heading', { level: 2 }).textContent).toBe(
      'Built-in rules',
    )
    expect(
      within(panel)
        .getAllByRole('listitem')
        .map((node) => node.textContent),
    ).toEqual(initial.defaultRules)
    expect(h.view.container.textContent).not.toContain('[object Object]')
    deliver(() =>
      h.update({ ...initial, title: 'Renamed kind', owner: 'project' }),
    )
    expect(h.view.getByRole('table', { name: 'Renamed kind' })).not.toBeNull()
    expect(el('hilos-moderation-kind-owner').textContent).toBe('Project')
    expect(h.navigation.currentTitle.get()).toBe('Renamed kind · Tasks')
    expect(
      h.sent.filter(
        (frame) => frame.type === 'table_viewport' || frame.type === 'action',
      ),
    ).toEqual([])
  })

  it('requires both current windows and rejects stale rows and policy after kind or scope replacement', () => {
    const h = rulesHarness()
    const oldScope = h.context.scopes.page()
    deliver(() =>
      h.navigation.navigate('/hilos/moderation/hilos_profile_photo/rules'),
    )
    expect(h.context.scopes.page()).not.toBe(oldScope)
    expect(el('hilos-moderation-rules-loading')).not.toBeNull()
    expect(h.view.queryByRole('table')).toBeNull()
    deliver(() => h.answer({ ...initial, ownRuleCount: 1 }))
    expect(h.view.queryByRole('table')).toBeNull()
    const photo = {
      ...initial,
      kind: 'hilos_profile_photo',
      title: 'Profile photo',
    }
    // The metadata window alone is not enough to expose the old rules window.
    deliver(() =>
      h.receive('table_window', {
        page: PAGE,
        tableKey: TABLE,
        rows: [{ rowKey: photo.kind, slots: { kind: photo } }],
        sort: [],
        limit: 1,
        totalCount: 1,
        totalExact: true,
        firstAnchor: null,
        lastAnchor: null,
      }),
    )
    expect(h.view.queryByRole('table')).toBeNull()
    expect(el('hilos-moderation-rules-built-in')).toBeNull()
    deliver(() => h.answer(photo))
    expect(h.view.getByRole('table', { name: 'Profile photo' })).not.toBeNull()
    deliver(() => h.context.scopes.openPage(PAGE))
    expect(h.view.queryByRole('table')).toBeNull()
    expect(el('hilos-moderation-rules-built-in')).toBeNull()
    deliver(() => h.answer(photo))
    expect(h.view.getByRole('table', { name: 'Profile photo' })).not.toBeNull()
  })

  it('uses the overall count for built-ins and plain text, while empty search keeps the stock Nothing found surface', () => {
    const h = rulesHarness()
    expect(el('hilos-moderation-rules-built-in')).toBeNull()
    fireEvent.change(h.view.getByRole('searchbox', { name: 'Search rules…' }), {
      target: { value: 'no match' },
    })
    const request = h.sent
      .filter((frame) => frame.type === 'table_viewport')
      .at(-1)!
    deliver(() =>
      h.receive(
        'table_window',
        {
          page: PAGE,
          tableKey: RULES,
          rows: [],
          sort: [{ field: 'id', direction: 'asc' }],
          limit: 25,
          totalCount: 0,
          totalExact: true,
          firstAnchor: null,
          lastAnchor: null,
        },
        { requestId: request.requestId },
      ),
    )
    expect(h.view.getAllByText('Nothing found').length).toBeGreaterThan(0)
    expect(h.view.queryByText('No rules of your own yet')).toBeNull()
    expect(el('hilos-moderation-rules-built-in')).toBeNull()
    deliver(() =>
      h.update({
        ...initial,
        defaultRules: ['<script>plain rule</script>', 'Second rule'],
      }),
    )
    const panel = el('hilos-moderation-rules-built-in')
    expect(
      within(panel)
        .getAllByRole('listitem')
        .map((node) => node.textContent),
    ).toEqual(['<script>plain rule</script>', 'Second rule'])
    expect(panel.querySelector('script')).toBeNull()
    deliver(() => h.update({ ...initial, ownRuleCount: 2 }))
    expect(el('hilos-moderation-rules-built-in')).toBeNull()
  })

  it.each([TABLE, RULES])(
    'presents %s refusal and recovers only from a new window',
    (tableKey) => {
      const h = rulesHarness()
      deliver(() => h.answer(initial))
      deliver(() =>
        h.receive('table_window_refused', {
          page: PAGE,
          tableKey,
          errorCode: 'unavailable',
        }),
      )
      expect(el('hilos-table-unavailable').getAttribute('role')).toBe('status')
      expect(el('hilos-moderation-rules-built-in')).toBeNull()
      expect(h.view.queryByText('No rules of your own yet')).toBeNull()
      deliver(() => h.answer(initial))
      expect(el('hilos-table-unavailable')).toBeNull()
      expect(el('hilos-moderation-rules-built-in')).not.toBeNull()
    },
  )

  it('connects Add to the table action, preserves raw input and refusal, and closes only on the tracked ack', async () => {
    const h = rulesHarness()
    const opener = h.open('add')
    expect(
      h.view.getByRole('dialog', { name: 'Add rule · Profile name' }),
    ).not.toBeNull()
    expect(field('add').value).toBe('')
    expect(document.activeElement).toBe(field('add'))
    const raw = '  New raw\nrule.\n'
    input('add', raw)
    submit('add')
    expect(h.writes()[0]).toEqual({
      type: 'action',
      action: 'hilos_moderation_rule_add',
      data: { kind: initial.kind, rule: raw },
      requestId: expect.any(String),
    })
    await act(async () => h.reply(undefined, false))
    expect(field('add').value).toBe(raw)
    expect(el('hilos-moderation-rule-add-error').textContent).toContain(
      'The saved rule no longer matches.',
    )
    expect(hilosToasts.toasts.get()).toEqual([])
    submit('add')
    deliver(() => h.incoming(raw))
    expect(h.view.queryByRole('dialog')).not.toBeNull()
    await act(async () => h.reply())
    expect(h.view.queryByRole('dialog')).toBeNull()
    expect(document.activeElement).toBe(opener)
    expect(
      hilosToasts.toasts
        .get()
        .map(({ severity, message, repeats }) => [severity, message, repeats]),
    ).toEqual([['success', 'Rule written.', 1]])
  })

  it('connects Edit and Delete to focus, live merge and exact expectedRule through real row and card actions', async () => {
    const h = rulesHarness()
    const opener = h.open('edit')
    expect(h.rules().focusedRow.get()?.id).toBe(1)
    input('edit', '  My rule\n')
    deliver(() => h.incoming('  Changed elsewhere\n'))
    expect(
      (el('hilos-moderation-rule-edit-submit') as HTMLButtonElement).disabled,
    ).toBe(true)
    fireEvent.click(el('conflict-accept-mine'))
    submit('edit')
    expect(h.writes()[0]!.data).toEqual({
      id: 1,
      kind: initial.kind,
      rule: '  My rule\n',
      expectedRule: '  Changed elsewhere\n',
    })
    await act(async () => h.reply())
    expect(h.rules().focusedRow.get()).toBeUndefined()
    expect(document.activeElement).toBe(opener)
    const cards = h.view.getByRole('list', { name: 'Profile name' })
    fireEvent.click(within(cards).getByRole('button', { name: 'Delete rule' }))
    expect(
      h.view.getByRole('dialog', { name: 'Delete rule' }).textContent,
    ).toContain('Changed elsewhere')
    expect(h.rules().focusedRow.get()?.id).toBe(1)
    submit('delete')
    expect(h.writes()[1]!.data).toEqual({
      id: 1,
      kind: initial.kind,
      expectedRule: '  Changed elsewhere\n',
    })
    await act(async () => h.reply())
    expect(h.view.queryByRole('dialog')).toBeNull()
    expect(h.rules().focusedRow.get()).toBeUndefined()
    expect(
      hilosToasts.toasts
        .get()
        .reduce((count, toast) => count + toast.repeats, 0),
    ).toBe(2)
  })

  it('keeps a departed row behind Apply while its open edit keeps the draft and blocks a forged submit', () => {
    const h = rulesHarness()
    h.open('edit')
    input('edit', 'Extractable draft')
    deliver(() => {
      h.gone()
      h.update(initial)
    })
    expect(field('edit').value).toBe('Extractable draft')
    expect(el('hilos-moderation-rule-edit-submit').textContent).toBe('Deleted')
    submit('edit')
    expect(h.writes()).toEqual([])
    expect(h.view.getByRole('table').textContent).toContain(savedRule)
    expect(h.rules().pendingCount.get()).toBe(1)
    expect(el('hilos-moderation-rules-built-in')).not.toBeNull()
    fireEvent.click(el('hilos-moderation-rule-edit-cancel'))
    fireEvent.click(el('modal-confirm-discard'))
    fireEvent.click(h.view.getByRole('button', { name: 'Apply' }))
    expect(h.view.getByRole('table').textContent).not.toContain(savedRule)
    expect(h.rules().pendingCount.get()).toBe(0)
  })

  it.each(['add', 'edit', 'delete'] as const)(
    'lets a viewer inspect %s but rejects forged submit and click',
    (form) => {
      const h = rulesHarness({ viewer: true })
      h.open(form)
      if (form !== 'delete') input(form, 'Local draft')
      const button = el(
        `hilos-moderation-rule-${form}-${form === 'delete' ? 'confirm' : 'submit'}`,
      ) as HTMLButtonElement
      expect(button.disabled).toBe(true)
      expect(button.getAttribute('aria-describedby')).toBeTruthy()
      if (form !== 'delete')
        expect(field(form).matches(':disabled')).toBe(false)
      submit(form)
      fireEvent.click(button)
      expect(h.writes()).toEqual([])
      expect(h.view.queryByRole('dialog')).not.toBeNull()
    },
  )

  it('replays StrictMode with fresh windows and working forms, then releases focus and controllers on unmount', async () => {
    const h = rulesHarness({ strict: true })
    expect(h.registered).toHaveBeenCalledTimes(4)
    expect(h.unregistered).toHaveBeenCalledTimes(2)
    expect(h.controller(0).rows.get()).toEqual([])
    expect(h.controller(1).rows.get()).toEqual([])
    expect(h.rules()).not.toBe(h.registered.mock.calls[1]![1])
    expect(
      h.view.getByRole('table', { name: 'Profile name' }).textContent,
    ).toContain(savedRule)
    h.open('edit')
    deliver(() => h.incoming('After replay'))
    expect(field('edit').value).toBe('After replay')
    input('edit', 'Saved after replay')
    submit('edit')
    await act(async () => h.reply())
    expect(h.writes()).toHaveLength(1)
    expect(hilosToasts.toasts.get().map(({ repeats }) => repeats)).toEqual([1])
    h.open('delete')
    const rules = h.rules()
    h.view.unmount()
    expect(h.unregistered).toHaveBeenCalledTimes(4)
    expect(rules.rows.get()).toEqual([])
    expect(rules.focusedRow.get()).toBeUndefined()
    expect(h.context.connection.tableWindowDescriptors()).toEqual({})
    expect(h.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe('Kind')
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })

  it.each(['add', 'edit', 'delete'] as const)(
    'isolates an in-flight %s on kind replacement from the new form and its tracked reply',
    async (form) => {
      const h = rulesHarness({ strict: true })
      h.open(form)
      if (form !== 'delete') input(form, 'Old draft')
      submit(form)
      const oldId = String(h.writes()[0]!.requestId)
      const next = {
        ...initial,
        kind: 'hilos_profile_photo',
        title: 'Profile photo',
        ownRuleCount: 1,
      }
      deliver(() =>
        h.navigation.navigate('/hilos/moderation/hilos_profile_photo/rules'),
      )
      expect(h.view.queryByRole('dialog')).toBeNull()
      expect(h.rules().focusedRow.get()).toBeUndefined()
      expect(h.view.queryByRole('table')).toBeNull()
      deliver(() => h.answer(next))
      h.open(form)
      if (form !== 'delete') input(form, 'New draft')
      submit(form)
      const dialog = h.view.getByRole('dialog')
      expect(h.writes()[1]!.data).toMatchObject({ kind: next.kind })
      await act(async () => h.reply(oldId))
      expect(h.view.getByRole('dialog')).toBe(dialog)
      if (form !== 'delete') expect(field(form).value).toBe('New draft')
      expect(hilosToasts.toasts.get()).toEqual([])
      expect(el('hilos-action-error')).toBeNull()
      await act(async () => h.reply())
      expect(h.view.queryByRole('dialog')).toBeNull()
      expect(hilosToasts.toasts.get().map(({ repeats }) => repeats)).toEqual([
        1,
      ])
      expect(
        h.view.getByRole('table', { name: 'Profile photo' }),
      ).not.toBeNull()
    },
  )

  it('disposes an in-flight edit on context replacement and ignores its late refusal', async () => {
    const old = rulesHarness()
    old.open('edit')
    input('edit', 'Old draft')
    submit('edit')
    const rules = old.rules()
    const next = harness(PAGE, { kind: 'hilos_profile_photo' })
    old.view.rerender(
      <HilosRouterContext.Provider value={next.navigation}>
        <HilosModerationKindRulesPage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    expect(old.unregistered).toHaveBeenCalledTimes(2)
    expect(rules.rows.get()).toEqual([])
    expect(rules.focusedRow.get()).toBeUndefined()
    expect(old.context.connection.tableWindowDescriptors()).toEqual({})
    expect(old.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe('Kind')
    deliver(() =>
      next.answer([
        { ...initial, kind: 'hilos_profile_photo', title: 'Profile photo' },
      ]),
    )
    await act(async () => old.reply(undefined, false))
    expect(old.view.queryByRole('dialog')).toBeNull()
    expect(
      old.view.getByRole('table', { name: 'Profile photo' }),
    ).not.toBeNull()
    expect(old.view.container.textContent).not.toContain('Old draft')
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('lets the real outlet leave Rules with an open form and show the ordinary unknown-kind refusal', () => {
    const h = harness(PAGE)
    h.answer([initial])
    const pages = {
      [PAGE]: () => <HilosModerationKindRulesPage context={h.context} />,
      [HilosPages.MODERATION_KIND]: () => (
        <HilosModerationKindPage context={h.context} />
      ),
    }
    const view = h.mount(<HilosView pages={pages} />, true)
    fireEvent.click(el('hilos-table-main-action'))
    input('add', 'Unsent draft')
    deliver(() => h.navigation.navigate('/hilos/moderation/hilos_profile_name'))
    expect(h.context.connection.tableWindowDescriptors()).toEqual({})
    deliver(() => h.answer([initial], HilosPages.MODERATION_KIND))
    expect(view.queryByRole('dialog')).toBeNull()
    expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
      'Profile name',
    )
    fireEvent.click(view.getByRole('link', { name: 'Rules' }))
    deliver(() => h.answer([initial]))
    deliver(() => h.navigation.navigate('/hilos/moderation/unknown/rules'))
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
    expect(el('hilos-moderation-rules-built-in')).toBeNull()
    expect(view.queryByRole('table')).toBeNull()
    expect(h.context.connection.tableWindowDescriptors()).toEqual({})
  })
})
