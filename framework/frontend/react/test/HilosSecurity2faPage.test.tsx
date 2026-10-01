// The React peer of vue/src/admin/security/HilosSecurity2faPage.test.ts:
// the two-step verification setting's edit modal on the shared row-edit helper
// (HIL-1134): it holds the setting row in focus, reloads a pristine edit when
// the value changes elsewhere and says so, shows the conflict chrome without
// Merge on a changed one (the live value in words) and answers Keep mine / Take
// theirs, and locks save as "Deleted" when the row goes.
import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionLifecycle,
  HilosPages,
  HilosSecondFactorSettingKey,
  ScopeManager,
  createSignal,
} from '@hilos/core'
import type {
  HilosRouter,
  HilosTwoFactorContext,
  PageRouteMatch,
} from '@hilos/core'

import { HilosSecurity2faPage } from '../src/admin/security/HilosSecurity2faPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const SETTINGS_TABLE = 'hilosSecurityTwoFactor'
const REQUIRED = HilosSecondFactorSettingKey.required
const TRUST_DAYS = HilosSecondFactorSettingKey.trustDays

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.SECURITY_2FA,
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
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/** A setting row's slot, with the given value. */
function settingSlot(rowKey: string, value: string): Record<string, unknown> {
  return { rowKey, value, defaultValue: rowKey === REQUIRED ? 'none' : '30' }
}

function seededContext(): {
  context: HilosTwoFactorContext
  pushUpdate: (rowKey: string, value: string) => void
  pushRemove: (rowKey: string) => void
  sent: Array<{ action: string; payload: Record<string, unknown> }>
  focus: string[]
} {
  let settings = new Map<string, Record<string, unknown>>([
    [REQUIRED, settingSlot(REQUIRED, 'admins')],
    [TRUST_DAYS, settingSlot(TRUST_DAYS, '30')],
  ])
  const focus: string[] = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.SECURITY_2FA)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (tableKey: string): void => {
    const rows =
      tableKey === SETTINGS_TABLE
        ? [...settings].map(([rowKey, setting]) => ({
            rowKey,
            slots: { setting },
          }))
        : []
    const data = {
      page: HilosPages.SECURITY_2FA,
      tableKey,
      rows,
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
  const pushDelta = (data: Record<string, unknown>): void => {
    for (const listener of deltaListeners) {
      listener({
        data: {
          page: HilosPages.SECURITY_2FA,
          tableKey: SETTINGS_TABLE,
          ...data,
        },
      })
    }
  }

  const connection = {
    registerTableWindow(tableKey: string): void {
      serveWindow(tableKey)
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(_page: string, tableKey: string): boolean {
      serveWindow(tableKey)

      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    sendTableRowFocus(
      _page: string,
      _tableKey: string,
      rowKey: string,
    ): boolean {
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
  const sent: Array<{ action: string; payload: Record<string, unknown> }> = []
  const source = {
    sendAction: (action: string, payload: Record<string, unknown>) => {
      sent.push({ action, payload })

      return true
    },
    on: (event: string, listener: (state: string) => void) => {
      if (event === 'state') {
        drops.push(() => listener('disconnected'))
      }

      return () => {}
    },
  }
  const actions = new ActionLifecycle(
    source as unknown as ConstructorParameters<typeof ActionLifecycle>[0],
  )

  return {
    context: {
      connection: connection as unknown as HilosTwoFactorContext['connection'],
      scopes,
      actions,
    },
    pushUpdate(rowKey: string, value: string): void {
      const setting = settingSlot(rowKey, value)
      settings = new Map(settings).set(rowKey, setting)
      pushDelta({
        kind: 'row_updated',
        rowKey,
        row: { rowKey, slots: { setting } },
      })
    },
    pushRemove(rowKey: string): void {
      settings = new Map(settings)
      settings.delete(rowKey)
      pushDelta({ kind: 'row_removed', rowKey, reason: 'deleted' })
    },
    sent,
    focus,
  }
}

// Drops every connection a case left an action in flight on, so the lifecycle
// fails the action and stops its deferred-loading timer before the page goes.
const drops: Array<() => void> = []

afterEach(() => {
  act(() => {
    for (const drop of drops.splice(0)) {
      drop()
    }
  })
  cleanup()
  document.body.classList.remove('modal-open')
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function valueInput(): HTMLInputElement | HTMLSelectElement {
  return byId('hilos-2fa-input') as HTMLInputElement | HTMLSelectElement
}

function saveButton(): HTMLButtonElement {
  return byId('hilos-2fa-save') as HTMLButtonElement
}

function notice(): HTMLElement | null {
  return byId('hilos-2fa-edit-notice')
}

function typeDraft(text: string): void {
  fireEvent.change(valueInput(), { target: { value: text } })
}

/**
 * Mount the page and open the modal of one setting. The row's controls stand
 * in the document twice — the table and the narrow-screen card — so the button
 * is looked up through the table.
 */
function openModal(context: HilosTwoFactorContext, rowKey: string): void {
  render(
    <HilosRouterContext.Provider value={router()}>
      <HilosSecurity2faPage context={context} />
    </HilosRouterContext.Provider>,
  )
  fireEvent.click(
    document.querySelector(
      `table [data-id="hilos-2fa-edit-${rowKey}"]`,
    ) as Element,
  )
}

describe('HilosSecurity2faPage setting modal', () => {
  it('opens on the live value with save locked, the message line empty, and the row in focus', () => {
    const { context, focus } = seededContext()
    openModal(context, TRUST_DAYS)

    expect(valueInput().value).toBe('30')
    expect(saveButton().disabled).toBe(true)
    expect(notice()).toBeNull()
    expect(focus).toEqual([TRUST_DAYS])
  })

  it('sends nothing on Enter while a conflict stands', () => {
    const { context, pushUpdate, sent } = seededContext()
    openModal(context, REQUIRED)
    typeDraft('everyone')
    act(() => {
      pushUpdate(REQUIRED, 'none')
    })

    fireEvent.submit(valueInput().form as HTMLFormElement)

    expect(sent).toHaveLength(0)
    expect(byId('conflict-badge')).not.toBeNull()
  })

  it('reloads a pristine edit when the value changes elsewhere and says so', () => {
    const { context, pushUpdate } = seededContext()
    openModal(context, TRUST_DAYS)

    act(() => {
      pushUpdate(TRUST_DAYS, '14')
    })

    expect(valueInput().value).toBe('14')
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('surfaces a conflict in words, without Merge, and Keep mine sends mine', () => {
    const { context, pushUpdate, sent } = seededContext()
    openModal(context, REQUIRED)
    typeDraft('everyone')
    expect(saveButton().disabled).toBe(false)

    act(() => {
      pushUpdate(REQUIRED, 'none')
    })

    expect(byId('conflict-badge')).not.toBeNull()
    expect(notice()?.textContent).toContain('Changed elsewhere to "Nobody"')
    expect(byId('conflict-merge')).toBeNull()
    expect(saveButton().disabled).toBe(true)

    fireEvent.click(byId('conflict-accept-mine') as Element)
    fireEvent.click(saveButton())
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('security_2fa_setting_set')
    expect(sent[0]?.payload).toMatchObject({ key: REQUIRED, value: 'everyone' })
  })

  it('Take theirs puts the live value in, says so, and locks save', () => {
    const { context, pushUpdate } = seededContext()
    openModal(context, REQUIRED)
    typeDraft('everyone')
    act(() => {
      pushUpdate(REQUIRED, 'none')
    })

    fireEvent.click(byId('conflict-accept-theirs') as Element)

    expect(valueInput().value).toBe('none')
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes, and lets the row go on close', () => {
    const { context, pushRemove, focus } = seededContext()
    openModal(context, REQUIRED)
    typeDraft('everyone')
    act(() => {
      pushRemove(REQUIRED)
    })

    expect(notice()?.textContent).toContain('Deleted elsewhere')
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(saveButton().disabled).toBe(true)
    expect(valueInput().value).toBe('everyone')

    fireEvent.click(byId('modal-close') as Element)
    expect(focus).toEqual([REQUIRED, ''])
  })

  it('asks before discarding a changed draft, and closes a pristine one on Save without a request', () => {
    const { context, sent } = seededContext()
    openModal(context, TRUST_DAYS)
    typeDraft('7')

    fireEvent.click(byId('modal-close') as Element)
    expect(byId('modal-confirm-discard')).not.toBeNull()
    fireEvent.click(byId('modal-confirm-discard') as Element)
    expect(byId('modal')).toBeNull()

    fireEvent.click(
      document.querySelector(
        `table [data-id="hilos-2fa-edit-${TRUST_DAYS}"]`,
      ) as Element,
    )
    fireEvent.submit(valueInput().form as HTMLFormElement)
    expect(sent).toHaveLength(0)
    expect(byId('modal')).toBeNull()
  })
})

describe('HilosSecurity2faPage', () => {
  it('no longer carries the operations that ask for confirmation (HIL-1204)', () => {
    const { context } = seededContext()
    render(
      <HilosRouterContext.Provider value={router()}>
        <HilosSecurity2faPage context={context} />
      </HilosRouterContext.Provider>,
    )

    expect(byId('hilos-step-up-table')).toBeNull()
  })
})
