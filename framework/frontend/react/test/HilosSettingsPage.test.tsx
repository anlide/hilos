import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionLifecycle,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosRouter,
  HilosSettingsContext,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'

import { HilosSettingsPage } from '../src/admin/settings/HilosSettingsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.SETTINGS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

interface SettingSlot {
  key: string
  type: string
  value: unknown
  overrideValue: unknown
  defaultValue: unknown
  defaultReferenceKey: string | null
  valueSource: string
}

// Build one inline settings slot, defaulting the catalog fields a test does not
// care about so each case states only what it asserts on.
function slot(
  over: Partial<SettingSlot> & { key: string; valueSource: string },
): SettingSlot {
  return {
    type: 'string',
    value: 'v',
    overrideValue: null,
    defaultValue: 'd',
    defaultReferenceKey: null,
    ...over,
  }
}

function seededContext(initial: SettingSlot[]): {
  context: HilosSettingsContext
  pushUpdate: (next: SettingSlot) => void
  pushRemove: (key: string) => void
  pushLeave: (next: SettingSlot) => void
  answer: (outcome: 'success' | 'fail') => void
  sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }>
  focus: string[]
} {
  let rows = initial.slice()
  const focus: string[] = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.SETTINGS)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (
    page: string = HilosPages.SETTINGS,
    tableKey: string = 'settings',
  ): void => {
    const data = {
      page,
      tableKey,
      rows: rows.map((settings) => ({
        rowKey: settings.key,
        slots: { settings },
      })),
      totalCount: rows.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      offset: 0,
      limit: 10,
    }
    for (const listener of windowListeners) {
      listener({ data })
    }
  }

  const connection = {
    registerTableWindow(): void {
      serveWindow()
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(page: string, tableKey: string): boolean {
      serveWindow(page, tableKey)

      return true
    },
    sendTableRowFocus(page: string, tableKey: string, rowKey: string): boolean {
      focus.push(rowKey)

      return true
    },
    on(
      event: string,
      listener: (signal: { data: unknown }) => void,
    ): () => void {
      if (event === 'tableWindow') {
        windowListeners.add(listener)

        return () => windowListeners.delete(listener)
      }
      if (event === 'tableViewportDelta') {
        deltaListeners.add(listener)

        return () => deltaListeners.delete(listener)
      }

      return () => {}
    },
  }
  const sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }> = []
  const replyListeners = new Map<
    string,
    Set<(signal: Record<string, unknown>) => void>
  >()
  const actions = new ActionLifecycle({
    sendAction: (
      action: string,
      payload: Record<string, unknown>,
      requestId?: string,
    ) => {
      sent.push({ action, payload, requestId })

      return true
    },
    on: (
      event: string,
      listener: (signal: Record<string, unknown>) => void,
    ) => {
      const listeners = replyListeners.get(event) ?? new Set()
      listeners.add(listener)
      replyListeners.set(event, listeners)

      return () => listeners.delete(listener)
    },
  } as unknown as ConstructorParameters<typeof ActionLifecycle>[0])

  return {
    context: {
      connection: connection as unknown as HilosSettingsContext['connection'],
      scopes,
      actions,
    },
    pushUpdate(next: SettingSlot): void {
      rows = rows.map((row) => (row.key === next.key ? next : row))
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.SETTINGS,
            tableKey: 'settings',
            kind: 'row_updated',
            rowKey: next.key,
            row: { rowKey: next.key, slots: { settings: next } },
          },
        })
      }
    },
    pushRemove(key: string): void {
      rows = rows.filter((row) => row.key !== key)
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.SETTINGS,
            tableKey: 'settings',
            kind: 'row_removed',
            rowKey: key,
            reason: 'deleted',
          },
        })
      }
    },
    // The row left this tab's window alive — out of its search, past its edge — and the frame
    // that takes it off the screen carries the row for the dialog holding it in focus.
    pushLeave(next: SettingSlot): void {
      rows = rows.filter((row) => row.key !== next.key)
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.SETTINGS,
            tableKey: 'settings',
            kind: 'row_removed',
            rowKey: next.key,
            reason: 'left_set',
            row: { rowKey: next.key, slots: { settings: next } },
          },
        })
      }
    },
    // Answer the last action sent, the way the server replies to it.
    answer(outcome: 'success' | 'fail'): void {
      const last = sent[sent.length - 1]
      const event = outcome === 'success' ? 'actionSuccess' : 'actionError'
      for (const listener of replyListeners.get(event) ?? []) {
        listener({
          kind: event,
          action: last?.action,
          requestId: last?.requestId,
          reason: 'The setting refused the reset.',
        })
      }
    },
    sent,
    focus,
  }
}

function renderPage(context: HilosSettingsContext) {
  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosSettingsPage context={context} />
    </HilosRouterContext.Provider>,
  )
}

describe('HilosSettingsPage', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
  })

  it('renders a row per setting with its key and source badge', () => {
    const { container } = renderPage(
      seededContext([
        slot({
          key: 'site_name',
          valueSource: 'override',
          value: 'Hilos',
          overrideValue: 'Hilos',
        }),
        slot({
          key: 'max_items',
          valueSource: 'default',
          type: 'integer',
          value: '10',
          defaultValue: '10',
        }),
      ]).context,
    )
    expect(
      container.querySelectorAll('[data-id^="hilos-table-row-"]').length,
    ).toBe(2)
    expect(container.textContent).toContain('site_name')
    expect(container.textContent).toContain('custom')
    expect(container.textContent).toContain('default')
  })

  it('offers delete only for an orphan key', () => {
    const { container } = renderPage(
      seededContext([
        slot({
          key: 'site_name',
          valueSource: 'override',
          overrideValue: 'Hilos',
        }),
        slot({
          key: 'legacy',
          valueSource: 'orphan',
          overrideValue: 'x',
          defaultValue: null,
        }),
      ]).context,
    )
    expect(
      container.querySelector('[data-id="hilos-settings-delete-legacy"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-settings-delete-site_name"]'),
    ).toBeNull()
  })

  it('offers "set custom value" for a cataloged key on its default', () => {
    // The row a reset leaves behind: on its catalog default, no value of its own.
    // The screen asks the override, not whether a row is stored, so the button is
    // the plus that offers a custom value — not the pencil that edits one.
    const { container } = renderPage(
      seededContext([
        slot({ key: 'site_name', valueSource: 'default', value: 'd' }),
      ]).context,
    )
    const button = container.querySelector(
      '[data-id="hilos-settings-edit-site_name"]',
    ) as Element

    expect(button.getAttribute('aria-label')).toBe('Set custom value')
    expect(button.querySelector('.bi-plus-lg')).not.toBeNull()
  })

  it('opens a key on its default with the custom-value switch off', () => {
    const { container } = renderPage(
      seededContext([
        slot({ key: 'site_name', valueSource: 'default', value: 'd' }),
      ]).context,
    )
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )

    const custom = document.querySelector(
      '[data-id="hilos-settings-edit-custom"]',
    ) as HTMLInputElement

    expect(custom.checked).toBe(false)
    expect(
      document.querySelector('[data-id="hilos-settings-edit-value"]'),
    ).toBeNull()
  })

  it('opens the edit dialog from a row action', () => {
    const { container } = renderPage(
      seededContext([
        slot({
          key: 'site_name',
          valueSource: 'override',
          overrideValue: 'Hilos',
        }),
      ]).context,
    )
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    // Nothing happened elsewhere yet: the message line stands empty, its room
    // held by the twin, so a message later moves nothing.
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]'),
    ).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice-idle"]'),
    ).not.toBeNull()
  })

  it('reloads a pristine edit when the live row changes elsewhere', () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    const input = document.querySelector(
      '[data-id="hilos-settings-edit-value"]',
    ) as HTMLInputElement
    expect(input.value).toBe('Hilos')

    act(() => {
      pushUpdate(
        slot({
          key: 'site_name',
          valueSource: 'override',
          value: 'Elsewhere',
          overrideValue: 'Elsewhere',
        }),
      )
    })

    expect(input.value).toBe('Elsewhere')
    expect(document.querySelector('[data-id="conflict-badge"]')).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]')
        ?.textContent,
    ).toContain('Updated just now')
    expect(
      (
        document.querySelector(
          '[data-id="hilos-settings-edit-save"]',
        ) as HTMLButtonElement
      ).disabled,
    ).toBe(true)

    // The person types over the taken value: the note about it goes out.
    fireEvent.change(input, { target: { value: 'Mine' } })
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]'),
    ).toBeNull()
  })

  it('leaves the effective value under the switch after a reset elsewhere', () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
        defaultValue: 'Default',
      }),
    ])
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )

    // The other side reset the key: the switch goes off and the dialog says so.
    act(() => {
      pushUpdate(
        slot({
          key: 'site_name',
          valueSource: 'default',
          value: 'Default',
          overrideValue: null,
          defaultValue: 'Default',
        }),
      )
    })
    const custom = document.querySelector(
      '[data-id="hilos-settings-edit-custom"]',
    ) as HTMLInputElement
    expect(custom.checked).toBe(false)
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]')
        ?.textContent,
    ).toContain('Updated just now')

    // Turning the switch back on starts from the value now in effect, not from
    // the override the other side just removed.
    fireEvent.click(custom)
    expect(
      (
        document.querySelector(
          '[data-id="hilos-settings-edit-value"]',
        ) as HTMLInputElement
      ).value,
    ).toBe('Default')
  })

  it('sends nothing on Enter while a conflict stands', () => {
    const { context, pushUpdate, sent } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    const input = document.querySelector(
      '[data-id="hilos-settings-edit-value"]',
    ) as HTMLInputElement
    fireEvent.change(input, { target: { value: 'Mine' } })
    act(() => {
      pushUpdate(
        slot({
          key: 'site_name',
          valueSource: 'override',
          value: 'Theirs',
          overrideValue: 'Theirs',
        }),
      )
    })

    fireEvent.submit(input.form as HTMLFormElement)

    expect(sent).toHaveLength(0)
    expect(document.querySelector('[data-id="conflict-badge"]')).not.toBeNull()
  })

  it('surfaces a conflict on a dirty edit and hides merge', () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    fireEvent.change(
      document.querySelector(
        '[data-id="hilos-settings-edit-value"]',
      ) as HTMLInputElement,
      { target: { value: 'Mine' } },
    )
    act(() => {
      pushUpdate(
        slot({
          key: 'site_name',
          valueSource: 'override',
          value: 'Theirs',
          overrideValue: 'Theirs',
        }),
      )
    })

    expect(document.querySelector('[data-id="conflict-badge"]')).not.toBeNull()
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]')
        ?.textContent,
    ).toContain('Changed elsewhere to "Theirs"')
    expect(document.querySelector('[data-id="conflict-merge"]')).toBeNull()
    expect(
      (
        document.querySelector(
          '[data-id="hilos-settings-edit-save"]',
        ) as HTMLButtonElement
      ).disabled,
    ).toBe(true)
  })

  it('Take theirs sets the field to the live value and locks save', () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    const input = document.querySelector(
      '[data-id="hilos-settings-edit-value"]',
    ) as HTMLInputElement
    fireEvent.change(input, { target: { value: 'Mine' } })
    act(() => {
      pushUpdate(
        slot({
          key: 'site_name',
          valueSource: 'override',
          value: 'Theirs',
          overrideValue: 'Theirs',
        }),
      )
    })
    fireEvent.click(
      document.querySelector('[data-id="conflict-accept-theirs"]') as Element,
    )

    expect(input.value).toBe('Theirs')
    expect(document.querySelector('[data-id="conflict-badge"]')).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]')
        ?.textContent,
    ).toContain('Updated just now')
    expect(
      (
        document.querySelector(
          '[data-id="hilos-settings-edit-save"]',
        ) as HTMLButtonElement
      ).disabled,
    ).toBe(true)
  })

  it('locks save and names the gone row when it is removed under the modal', () => {
    const { context, pushRemove } = seededContext([
      slot({
        key: 'legacy',
        valueSource: 'orphan',
        value: 'x',
        overrideValue: 'x',
        defaultValue: null,
      }),
    ])
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-legacy"]',
      ) as Element,
    )
    act(() => {
      pushRemove('legacy')
    })

    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]')
        ?.textContent,
    ).toContain('Deleted elsewhere')
    const save = document.querySelector(
      '[data-id="hilos-settings-edit-save"]',
    ) as HTMLButtonElement
    expect(save.disabled).toBe(true)
    expect(save.textContent?.trim()).toBe('Deleted')
  })

  it('follows a row that left the window under the modal: Updated just now, not Deleted', () => {
    const { context, pushLeave } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    // The other tab's save took the row out of this tab's search: the screen gates the
    // departure, the dialog holding the row in focus reads the body off the same frame.
    act(() => {
      pushLeave(
        slot({
          key: 'site_name',
          valueSource: 'override',
          value: 'Theirs',
          overrideValue: 'Theirs',
        }),
      )
    })

    expect(
      (
        document.querySelector(
          '[data-id="hilos-settings-edit-value"]',
        ) as HTMLInputElement
      ).value,
    ).toBe('Theirs')
    expect(
      document.querySelector('[data-id="hilos-settings-edit-notice"]')
        ?.textContent,
    ).toContain('Updated just now')
    expect(
      (
        document.querySelector(
          '[data-id="hilos-settings-edit-save"]',
        ) as HTMLButtonElement
      ).textContent?.trim(),
    ).toBe('Save')
  })

  it('takes the row into focus on open and lets it go on close', () => {
    const { context, focus } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const { container } = renderPage(context)
    fireEvent.click(
      container.querySelector(
        '[data-id="hilos-settings-edit-site_name"]',
      ) as Element,
    )
    expect(focus).toEqual(['site_name'])

    fireEvent.click(
      document.querySelector('[data-id="modal-close"]') as Element,
    )
    expect(focus).toEqual(['site_name', ''])
  })
})

describe('HilosSettingsPage reset dialog', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
  })

  const CUSTOM = slot({
    key: 'site_name',
    valueSource: 'override',
    value: 'Mine',
    overrideValue: 'Mine',
    defaultValue: 'Hilos',
  })

  function byId(id: string): HTMLElement | null {
    return document.querySelector(`[data-id="${id}"]`)
  }

  function resetButton(key: string): HTMLButtonElement | null {
    return document.querySelector(
      `table [data-id="hilos-settings-reset-${key}"]`,
    )
  }

  function confirmButton(): HTMLButtonElement {
    return byId('hilos-settings-reset-confirm') as HTMLButtonElement
  }

  function cancelButton(): HTMLButtonElement {
    return Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find(
      (button) => button.textContent?.trim() === 'Cancel',
    ) as HTMLButtonElement
  }

  function openReset(context: HilosSettingsContext): void {
    renderPage(context)
    fireEvent.click(resetButton('site_name') as Element)
  }

  async function reply(
    answer: (outcome: 'success' | 'fail') => void,
    outcome: 'success' | 'fail',
  ): Promise<void> {
    await act(async () => {
      answer(outcome)
      await Promise.resolve()
    })
  }

  it('offers ↺ on a catalog row, locked on its default, and none on an orphan', () => {
    renderPage(
      seededContext([
        CUSTOM,
        slot({ key: 'on_default', valueSource: 'default' }),
        slot({
          key: 'orphan',
          valueSource: 'orphan',
          overrideValue: 'x',
          defaultValue: null,
        }),
      ]).context,
    )

    expect(resetButton('site_name')?.disabled).toBe(false)
    expect(resetButton('on_default')?.disabled).toBe(true)
    expect(resetButton('orphan')).toBeNull()
    expect(
      document.querySelector('table [data-id="hilos-settings-delete-orphan"]'),
    ).not.toBeNull()
  })

  it('opens on ↺ with the row in focus and sends nothing', () => {
    const { context, sent, focus } = seededContext([CUSTOM])
    openReset(context)

    expect(byId('modal')?.textContent).toContain('Reset · site_name')
    expect(focus).toEqual(['site_name'])
    expect(sent).toEqual([])
  })

  it('shows the own value now and the catalog default it goes back to', () => {
    const { context } = seededContext([CUSTOM])
    openReset(context)

    expect(byId('hilos-settings-reset-now')?.textContent).toContain('Mine')
    const back = byId('hilos-settings-reset-default')
    expect(back?.textContent).toContain('Hilos')
    expect(back?.textContent).not.toContain('custom')
  })

  it('names the referenced key when the default is a reference', () => {
    const { context } = seededContext([
      { ...CUSTOM, defaultReferenceKey: 'app.title' },
    ])
    openReset(context)

    expect(byId('hilos-settings-reset-default')?.textContent).toContain(
      'app.title',
    )
  })

  it('Reset sends the reset and closes on success, letting the row go', async () => {
    const { context, sent, focus, answer } = seededContext([CUSTOM])
    openReset(context)

    fireEvent.click(confirmButton())
    expect(sent.map(({ action, payload }) => ({ action, payload }))).toEqual([
      { action: 'setting_reset', payload: { key: 'site_name' } },
    ])

    await reply(answer, 'success')
    expect(byId('modal')).toBeNull()
    expect(focus).toEqual(['site_name', ''])
  })

  it('Cancel sends nothing and lets the row go', () => {
    const { context, sent, focus } = seededContext([CUSTOM])
    openReset(context)

    fireEvent.click(cancelButton())

    expect(byId('modal')).toBeNull()
    expect(sent).toEqual([])
    expect(focus).toEqual(['site_name', ''])
  })

  it('keeps the dialog open with the refusal on a failed reset', async () => {
    const { context, answer } = seededContext([CUSTOM])
    openReset(context)

    fireEvent.click(confirmButton())
    await reply(answer, 'fail')

    expect(byId('modal')).not.toBeNull()
    expect(byId('hilos-action-error')?.textContent).toContain(
      'The setting refused the reset.',
    )
  })

  it('says a reset elsewhere and locks Reset', () => {
    const { context, pushUpdate } = seededContext([CUSTOM])
    openReset(context)
    expect(byId('hilos-settings-reset-gone')).toBeNull()

    act(() => {
      pushUpdate({
        ...CUSTOM,
        valueSource: 'default',
        value: 'Hilos',
        overrideValue: null,
      })
    })

    expect(byId('hilos-settings-reset-gone')?.textContent?.trim()).toBe(
      'Already reset elsewhere.',
    )
    expect(confirmButton().disabled).toBe(true)
  })
})

describe('HilosSettingsPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
    for (const release of releases.splice(0)) release()
  })

  function bindSession() {
    const listeners: ((signal: ProjectSignal) => void)[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void): () => void {
        if (event === 'projectSignal') {
          listeners.push(listener as (signal: ProjectSignal) => void)
        }

        return () => {}
      },
    } as unknown as HilosConnection
    const scopes = new ScopeManager()
    bindSessionScope(connection, scopes)
    releases.push(bindAdminAccess(scopes))

    return {
      handshake(
        user: { id: number; admin: boolean } | null,
        viewMode: boolean,
      ): void {
        const signal = {
          kind: 'project',
          type: 'handshake_response',
          data: {
            entities: {
              currentUser: user === null ? null : { ...user, name: 'Olena' },
            },
            data: { adminViewMode: viewMode },
          },
          envelope: {},
        } as unknown as ProjectSignal
        for (const listener of listeners) {
          listener(signal)
        }
      },
    }
  }

  function byId(id: string): HTMLElement | null {
    return document.querySelector(`[data-id="${id}"]`)
  }

  it('a viewer opens a setting, sees the hidden mark instead of the input, and finds Save disabled', () => {
    bindSession().handshake(null, true)
    const { context, sent } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: { _hidden: true },
        overrideValue: { _hidden: true },
      }),
    ])
    const { container } = renderPage(context)

    const edit = container.querySelector(
      '[data-id="hilos-settings-edit-site_name"]',
    ) as HTMLButtonElement
    expect(edit.disabled).toBe(false)
    fireEvent.click(edit)

    expect(byId('hilos-settings-edit-value')).toBeNull()
    expect(byId('hilos-hidden')).not.toBeNull()

    const save = byId('hilos-settings-edit-save') as HTMLButtonElement
    expect(save.disabled).toBe(true)

    fireEvent.click(save)
    expect(sent).toEqual([])

    const cancel = byId('hilos-settings-edit-cancel') as HTMLButtonElement
    expect(cancel.disabled).toBe(false)
    fireEvent.click(cancel)
    expect(byId('modal')).toBeNull()
  })

  it('a viewer opens the reset of a setting and has nothing to reset it with', () => {
    bindSession().handshake(null, true)
    const { context, sent } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: { _hidden: true },
        overrideValue: { _hidden: true },
        defaultValue: { _hidden: true },
      }),
    ])
    renderPage(context)

    const reset = document.querySelector<HTMLButtonElement>(
      'table [data-id="hilos-settings-reset-site_name"]',
    )
    expect(reset?.disabled).toBe(false)
    fireEvent.click(reset as Element)

    expect(
      byId('hilos-settings-reset-now')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect(
      byId('hilos-settings-reset-default')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()

    const confirm = byId('hilos-settings-reset-confirm') as HTMLButtonElement
    expect(confirm.disabled).toBe(true)
    expect(confirm.getAttribute('aria-describedby')).toBe(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    fireEvent.click(confirm)
    expect(sent).toEqual([])

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    fireEvent.click(cancel as Element)

    expect(byId('modal')).toBeNull()
  })

  it('a viewer opens the deletion of an orphan and has nothing to delete it with', () => {
    bindSession().handshake(null, true)
    const { context, sent } = seededContext([
      slot({
        key: 'orphan',
        valueSource: 'orphan',
        overrideValue: { _hidden: true },
        defaultValue: null,
      }),
    ])
    renderPage(context)

    const del = document.querySelector<HTMLButtonElement>(
      'table [data-id="hilos-settings-delete-orphan"]',
    )
    expect(del?.disabled).toBe(false)
    fireEvent.click(del as Element)

    const confirm = byId('hilos-settings-delete-confirm') as HTMLButtonElement
    expect(confirm.disabled).toBe(true)
    expect(confirm.getAttribute('aria-describedby')).toBe(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    fireEvent.click(confirm)
    expect(sent).toEqual([])

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    fireEvent.click(cancel as Element)

    expect(byId('modal')).toBeNull()
  })

  it('an admin on a node in the mode edits a setting as today', () => {
    bindSession().handshake({ id: 1, admin: true }, true)
    const { context } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const { container } = renderPage(context)

    const edit = container.querySelector(
      '[data-id="hilos-settings-edit-site_name"]',
    ) as HTMLButtonElement
    expect(edit.disabled).toBe(false)
    fireEvent.click(edit)

    const input = byId('hilos-settings-edit-value') as HTMLInputElement
    expect(input.disabled).toBe(false)
    fireEvent.change(input, { target: { value: 'Other' } })

    const save = byId('hilos-settings-edit-save') as HTMLButtonElement
    expect(save.disabled).toBe(false)
    expect(save.getAttribute('aria-describedby')).toBeNull()
  })
})
