import { StrictMode, type ReactNode } from 'react'
import { act, cleanup, render } from '@testing-library/react'
import { afterEach, vi } from 'vitest'
import {
  ActionLifecycle,
  bindAdminAccess,
  bindPageScope,
  computedSignal,
  createAppPageRouter,
  createHilosRouter,
  HilosConnection,
  HilosPages,
  hilosToasts,
  hilosUnbuiltPages,
  PAGE_SIGNAL_SCHEMAS,
  resolvePageTitle,
  ScopeManager,
  type TableViewportController,
  type WebSocketLike,
} from '@hilos/core/base'
import {
  createHilosModerationKind,
  createHilosModerationRuleAdd,
  createHilosModerationRuleDelete,
  createHilosModerationRuleEdit,
  createHilosModerationRules,
  type HilosModerationKindRow,
} from '@hilos/core/admin/moderation'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosViewportTable } from '../src/HilosViewportTable.js'
import { HilosModerationRuleAdd } from '../src/admin/moderation/HilosModerationRuleAdd.js'
import { HilosModerationRuleDelete } from '../src/admin/moderation/HilosModerationRuleDelete.js'
import { HilosModerationRuleEdit } from '../src/admin/moderation/HilosModerationRuleEdit.js'
import { HilosTakeoverViewOnlyContext } from '../src/hilosLookOnly.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

export const TABLE = 'hilosModerationKinds'
export const initial: HilosModerationKindRow = {
  kind: 'hilos_profile_name',
  title: 'Profile name',
  owner: 'framework',
  input: 'text',
  model: 'qwen2.5:3b',
  ownRuleCount: 0,
  profile: 'hilos.moderation.text',
  reasons: ['spam', 'threat', 'impersonation'],
  defaultRules: ['Allow polite names.', 'Block threats.'],
}

const releases: Array<() => void> = []
afterEach(() => {
  cleanup()
  for (const release of releases.splice(0)) release()
  hilosToasts.clear()
  vi.useRealTimers()
  vi.restoreAllMocks()
})

/** Only the socket is scripted: parsing, navigation, scope replay and tables are real. */
export function harness(
  page:
    | typeof HilosPages.MODERATION
    | typeof HilosPages.MODERATION_KIND
    | typeof HilosPages.MODERATION_KIND_RULES,
  options: { kind?: string; viewer?: boolean; rulesBuilt?: boolean } = {},
) {
  const listeners = new Map<string, (event: { data?: unknown }) => void>()
  const sent: Array<Record<string, unknown>> = []
  const socket: WebSocketLike = {
    send: (text) => sent.push(JSON.parse(String(text))),
    close: () => {},
    addEventListener: (event, listener) => listeners.set(event, listener),
  }
  const connection = new HilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: () => socket,
    projectSchemas: PAGE_SIGNAL_SCHEMAS,
  })
  const actions = new ActionLifecycle(connection)
  const scopes = new ScopeManager()
  scopes.session.data.set('adminViewMode', options.viewer ?? false)
  const releaseAccess = bindAdminAccess(scopes)
  const pages = bindPageScope(connection, scopes)
  const path =
    page === HilosPages.MODERATION
      ? '/hilos/moderation'
      : `/hilos/moderation/${options.kind ?? initial.kind}${page === HilosPages.MODERATION_KIND_RULES ? '/rules' : ''}`
  const navigation = createHilosRouter(
    createAppPageRouter({}),
    pages,
    {
      pathname: () => path,
      pushState: () => {},
      replaceState: () => {},
      onPopState: () => () => {},
    },
    (key) => {
      const identity = pages.pageIdentity.get()
      return resolvePageTitle(
        key,
        {},
        'Tasks',
        identity?.label,
        !pages.pageLoading.get(),
        identity?.pageHeadOwner?.label,
      )
    },
    options.rulesBuilt ? new Set() : hilosUnbuiltPages('react'),
  )
  connection.connect()
  listeners.get('open')?.({})
  pages.releaseOnSession()
  navigation.start()
  releases.push(() => {
    navigation.stop()
    connection.close()
    releaseAccess()
  })
  const registered = vi.spyOn(connection, 'registerTableWindow')
  const unregistered = vi.spyOn(connection, 'unregisterTableWindow')

  function receive(
    type: string,
    data: unknown,
    envelope: Record<string, unknown> = {},
  ): void {
    listeners.get('message')?.({
      data: JSON.stringify({ type, data, ...envelope }),
    })
  }

  function answer(
    rows: readonly HilosModerationKindRow[] = [initial],
    answeredPage: typeof page = page,
    rules: readonly { id: number; kind: string; rule: string }[] = [],
  ): void {
    const root = answeredPage === HilosPages.MODERATION
    const label = root ? 'Moderation' : 'Kind'
    const lead = root
      ? 'What is checked before it is shown, and by which rules.'
      : 'One declared check and the rules in force.'
    const owner = root ? answeredPage : HilosPages.MODERATION_KIND
    receive('page_response', {
      page: answeredPage,
      payload: {
        data: {
          pageLabel: label,
          pageLead: lead,
          pageHeadOwner: {
            page: owner,
            label,
            lead,
            icon: 'bi-shield-check',
          },
          pageBreadcrumb: [
            { page: HilosPages.DASHBOARD, label: 'Hilos' },
            { page: HilosPages.MODERATION, label: 'Moderation' },
            ...(root ? [] : [{ page: owner, label }]),
          ],
          pageTabs: root
            ? []
            : [
                { page: HilosPages.MODERATION_KIND, label: 'Overview' },
                { page: HilosPages.MODERATION_KIND_RULES, label: 'Rules' },
              ],
          pageChildren: [],
        },
        windows: {
          [TABLE]: {
            rows: rows.map((row) => ({
              rowKey: row.kind,
              slots: { kind: row },
            })),
            sort: [],
            limit: root ? 10 : 1,
            totalCount: rows.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
          },
          ...(answeredPage === HilosPages.MODERATION_KIND_RULES
            ? {
                hilosModerationRules: {
                  rows: rules.map((rule) => ({
                    rowKey: String(rule.id),
                    slots: { rule },
                  })),
                  sort: [{ field: 'id', direction: 'asc' }],
                  limit: 25,
                  totalCount: rules.length,
                  totalExact: true,
                  firstAnchor: null,
                  lastAnchor: null,
                },
              }
            : {}),
        },
      },
    })
  }

  function update(row: HilosModerationKindRow): void {
    receive('table_viewport_delta', {
      page,
      tableKey: TABLE,
      kind: 'row_updated',
      rowKey: row.kind,
      row: { rowKey: row.kind, slots: { kind: row } },
    })
  }

  return {
    context: { connection, scopes, actions },
    navigation,
    pages,
    sent,
    registered,
    unregistered,
    receive,
    answer,
    update,
    releaseAfterTest(release: () => void) {
      releases.push(release)
    },
    controller(index = registered.mock.calls.length - 1) {
      return registered.mock.calls[
        index
      ]![1] as TableViewportController<HilosModerationKindRow>
    },
    mount(content: ReactNode, strict = false) {
      const page = (
        <HilosRouterContext.Provider value={navigation}>
          {content}
        </HilosRouterContext.Provider>
      )
      return render(strict ? <StrictMode>{page}</StrictMode> : page)
    },
  }
}

/** Read one owned semantic surface without matching the table's card projection. */
export function el(id: string): HTMLElement {
  return document.querySelector<HTMLElement>(`[data-id="${id}"]`)!
}

/** Deliver one protocol event and flush its React signal mirrors. */
export function deliver(action: () => void): void {
  act(action)
}

export const savedRule = '  Saved rule.\n'

/** A test owner of real Rules scopes/controllers; no production Rules page is needed. */
export function formHarness(
  form: 'add' | 'edit' | 'delete',
  options: { viewer?: boolean; takeover?: boolean; strict?: boolean } = {},
) {
  const page = HilosPages.MODERATION_KIND_RULES
  const h = harness(page, { ...options, rulesBuilt: true })
  const kind = computedSignal(
    () => h.navigation.currentRoute.get().params.kind ?? '',
  )
  const detail = createHilosModerationKind(h.context, kind, page)
  const rules = createHilosModerationRules(h.context, kind)
  const add = createHilosModerationRuleAdd(h.context, detail.row, rules.current)
  const edit = createHilosModerationRuleEdit(h.context, detail.row, rules)
  const remove = createHilosModerationRuleDelete(h.context, detail.row, rules)
  detail.start()
  rules.start()
  add.start()
  edit.start()
  remove.start()
  h.releaseAfterTest(() => {
    add.dispose()
    edit.dispose()
    remove.dispose()
    rules.dispose()
    detail.dispose()
  })

  function answer(rule = savedRule): void {
    const key = kind.get()
    h.answer(
      [
        {
          ...initial,
          kind: key,
          title: key === initial.kind ? initial.title : 'Profile photo',
          ownRuleCount: 1,
        },
      ],
      page,
      [{ id: 1, kind: key, rule }],
    )
  }
  answer()

  const view = h.mount(
    <HilosTakeoverViewOnlyContext.Provider value={options.takeover ?? false}>
      <HilosAdminPage page={page}>
        <button type="button" onClick={() => add.open()} data-id="open-add">
          Add rule
        </button>
        <HilosViewportTable
          controller={rules.controller}
          cells={{
            actions: (_row, rowKey) => (
              <>
                <button type="button" onClick={() => edit.open(rowKey)}>
                  Edit rule
                </button>
                <button type="button" onClick={() => remove.open(rowKey)}>
                  Delete rule
                </button>
              </>
            ),
          }}
        />
        {form === 'add' ? (
          <HilosModerationRuleAdd add={add} />
        ) : form === 'edit' ? (
          <HilosModerationRuleEdit edit={edit} />
        ) : (
          <HilosModerationRuleDelete remove={remove} />
        )}
      </HilosAdminPage>
    </HilosTakeoverViewOnlyContext.Provider>,
    options.strict ?? true,
  )
  const action = `hilos_moderation_rule_${form === 'edit' ? 'update' : form}`
  return {
    ...h,
    view,
    add,
    edit,
    remove,
    rules,
    answer,
    writes: () => h.sent.filter((frame) => frame.type === 'action'),
    open() {
      const opener =
        form === 'add'
          ? el('open-add')
          : Array.from(view.getByRole('table').querySelectorAll('button')).find(
              (button) =>
                button.textContent ===
                (form === 'edit' ? 'Edit rule' : 'Delete rule'),
            )!
      opener.focus()
      deliver(() => opener.click())
      return opener
    },
    incoming(rule: string) {
      h.receive('table_viewport_delta', {
        page,
        tableKey: 'hilosModerationRules',
        kind: 'row_updated',
        rowKey: '1',
        row: {
          rowKey: '1',
          slots: { rule: { id: 1, kind: kind.get(), rule } },
        },
      })
    },
    gone() {
      h.receive('table_viewport_delta', {
        page,
        tableKey: 'hilosModerationRules',
        kind: 'row_removed',
        rowKey: '1',
        reason: 'deleted',
      })
    },
    replace(nextKind = kind.get()) {
      const sameKind = nextKind === kind.get()
      h.navigation.navigate(`/hilos/moderation/${nextKind}/rules`)
      // Navigating to the same address is a no-op; replace its actual page scope.
      if (sameKind) h.context.scopes.openPage(page)
      answer()
    },
    reply(
      requestId = String(
        h.sent.filter((frame) => frame.type === 'action').at(-1)?.requestId,
      ),
      success = true,
      message: string | null = 'Rule written.',
    ) {
      h.receive(
        success ? 'action_success' : 'action_error',
        {
          action,
          ...(success
            ? message === null
              ? {}
              : { message }
            : {
                reason: 'The saved rule no longer matches.',
                errorType: 'ValidationException',
                errorDetail: 'The writer refused this rule.',
              }),
        },
        { requestId, outcome: success ? 'success' : 'fail' },
      )
    },
  }
}
