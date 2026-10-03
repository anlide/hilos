// The Angular peer of vue/src/admin/security/HilosSecurity2faPage.test.ts:
// the two-step verification setting's edit modal on the shared row-edit helper
// (HIL-1134): it holds the setting row in focus, reloads a pristine edit when
// the value changes elsewhere and says so, shows the conflict chrome without
// Merge on a changed one (the live value in words) and answers Keep mine / Take
// theirs, and locks save as "Deleted" when the row goes. In the admin view
// mode a viewer's modal shows the hidden mark in place of the input with Save
// disabled, and an admin on the node keeps Save.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  HilosPages,
  HilosSecondFactorSettingKey,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosRouter,
  HilosTwoFactorContext,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'

import { afterEach, describe, expect, it } from 'vitest'

import { HilosSecurity2faPage } from '../src/admin/security/HilosSecurity2faPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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
    onLeave: () => () => {},
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

/** A setting row's slot as a viewer of the admin view mode is sent it. */
function hiddenSlot(rowKey: string): Record<string, unknown> {
  return { rowKey, value: { _hidden: true }, defaultValue: { _hidden: true } }
}

function seededContext(hiddenValues = false): {
  context: HilosTwoFactorContext
  pushUpdate: (rowKey: string, value: string) => void
  pushRemove: (rowKey: string) => void
  sent: Array<{ action: string; payload: Record<string, unknown> }>
  focus: string[]
} {
  let settings = new Map<string, Record<string, unknown>>([
    [
      REQUIRED,
      hiddenValues ? hiddenSlot(REQUIRED) : settingSlot(REQUIRED, 'admins'),
    ],
    [
      TRUST_DAYS,
      hiddenValues ? hiddenSlot(TRUST_DAYS) : settingSlot(TRUST_DAYS, '30'),
    ],
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
  const actions = new ActionLifecycle({
    sendAction: (action: string, payload: Record<string, unknown>) => {
      sent.push({ action, payload })

      return true
    },
    on: () => () => {},
  })

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

function el(
  fixture: ComponentFixture<unknown>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

function valueInput(
  fixture: ComponentFixture<unknown>,
): HTMLInputElement | HTMLSelectElement {
  return el(fixture, 'hilos-2fa-input') as HTMLInputElement | HTMLSelectElement
}

function saveButton(fixture: ComponentFixture<unknown>): HTMLButtonElement {
  return el(fixture, 'hilos-2fa-save') as HTMLButtonElement
}

function notice(fixture: ComponentFixture<unknown>): HTMLElement | null {
  return el(fixture, 'hilos-2fa-edit-notice')
}

function typeDraft(fixture: ComponentFixture<unknown>, text: string): void {
  const input = valueInput(fixture)
  input.value = text
  input.dispatchEvent(
    new Event(input instanceof HTMLSelectElement ? 'change' : 'input', {
      bubbles: true,
    }),
  )
  fixture.detectChanges()
}

/**
 * Mount the page and open the modal of one setting. The row's controls stand
 * in the document twice — the table and the narrow-screen card — so the button
 * is looked up through the table.
 */
function openModal(
  context: HilosTwoFactorContext,
  rowKey: string,
): ComponentFixture<HilosSecurity2faPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosSecurity2faPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()
  ;(fixture.nativeElement as HTMLElement)
    .querySelector<HTMLElement>(`table [data-id="hilos-2fa-edit-${rowKey}"]`)
    ?.click()
  fixture.detectChanges()

  return fixture
}

describe('HilosSecurity2faPage setting modal', () => {
  it('opens on the live value with save locked, the message line empty, and the row in focus', () => {
    const { context, focus } = seededContext()
    const fixture = openModal(context, TRUST_DAYS)

    expect(valueInput(fixture).value).toBe('30')
    expect(saveButton(fixture).disabled).toBe(true)
    expect(notice(fixture)).toBeNull()
    expect(focus).toEqual([TRUST_DAYS])
  })

  it('sends nothing on Enter while a conflict stands', () => {
    const { context, pushUpdate, sent } = seededContext()
    const fixture = openModal(context, REQUIRED)
    typeDraft(fixture, 'everyone')
    pushUpdate(REQUIRED, 'none')
    fixture.detectChanges()

    valueInput(fixture).form?.dispatchEvent(
      new Event('submit', { cancelable: true }),
    )
    fixture.detectChanges()

    expect(sent).toHaveLength(0)
    expect(el(fixture, 'conflict-badge')).not.toBeNull()
  })

  it('reloads a pristine edit when the value changes elsewhere and says so', () => {
    const { context, pushUpdate } = seededContext()
    const fixture = openModal(context, TRUST_DAYS)

    pushUpdate(TRUST_DAYS, '14')
    fixture.detectChanges()

    expect(valueInput(fixture).value).toBe('14')
    expect(notice(fixture)?.textContent).toContain('Updated just now')
    expect(saveButton(fixture).disabled).toBe(true)
  })

  it('surfaces a conflict in words, without Merge, and Keep mine sends mine', () => {
    const { context, pushUpdate, sent } = seededContext()
    const fixture = openModal(context, REQUIRED)
    typeDraft(fixture, 'everyone')
    expect(saveButton(fixture).disabled).toBe(false)

    pushUpdate(REQUIRED, 'none')
    fixture.detectChanges()

    expect(el(fixture, 'conflict-badge')).not.toBeNull()
    expect(notice(fixture)?.textContent).toContain(
      'Changed elsewhere to "Nobody"',
    )
    expect(el(fixture, 'conflict-merge')).toBeNull()
    expect(saveButton(fixture).disabled).toBe(true)

    el(fixture, 'conflict-accept-mine')?.click()
    fixture.detectChanges()
    saveButton(fixture).click()
    fixture.detectChanges()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('security_2fa_setting_set')
    expect(sent[0]?.payload).toMatchObject({ key: REQUIRED, value: 'everyone' })
  })

  it('Take theirs puts the live value in, says so, and locks save', () => {
    const { context, pushUpdate } = seededContext()
    const fixture = openModal(context, REQUIRED)
    typeDraft(fixture, 'everyone')
    pushUpdate(REQUIRED, 'none')
    fixture.detectChanges()

    el(fixture, 'conflict-accept-theirs')?.click()
    fixture.detectChanges()

    expect(valueInput(fixture).value).toBe('none')
    expect(notice(fixture)?.textContent).toContain('Updated just now')
    expect(saveButton(fixture).disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes, and lets the row go on close', () => {
    const { context, pushRemove, focus } = seededContext()
    const fixture = openModal(context, REQUIRED)
    typeDraft(fixture, 'everyone')
    pushRemove(REQUIRED)
    fixture.detectChanges()

    expect(notice(fixture)?.textContent).toContain('Deleted elsewhere')
    expect(saveButton(fixture).textContent?.trim()).toBe('Deleted')
    expect(saveButton(fixture).disabled).toBe(true)
    expect(valueInput(fixture).value).toBe('everyone')

    el(fixture, 'modal-close')?.click()
    fixture.detectChanges()
    expect(focus).toEqual([REQUIRED, ''])
  })

  it('asks before discarding a changed draft, and closes a pristine one on Save without a request', () => {
    const { context, sent } = seededContext()
    const fixture = openModal(context, TRUST_DAYS)
    typeDraft(fixture, '7')

    el(fixture, 'modal-close')?.click()
    fixture.detectChanges()
    expect(el(fixture, 'modal-confirm-discard')).not.toBeNull()
    el(fixture, 'modal-confirm-discard')?.click()
    fixture.detectChanges()
    expect(el(fixture, 'modal')).toBeNull()
    ;(fixture.nativeElement as HTMLElement)
      .querySelector<HTMLElement>(
        `table [data-id="hilos-2fa-edit-${TRUST_DAYS}"]`,
      )
      ?.click()
    fixture.detectChanges()
    valueInput(fixture).form?.dispatchEvent(
      new Event('submit', { cancelable: true }),
    )
    fixture.detectChanges()
    expect(sent).toHaveLength(0)
    expect(el(fixture, 'modal')).toBeNull()
  })
})

describe('HilosSecurity2faPage', () => {
  it('no longer carries the operations that ask for confirmation (HIL-1204)', () => {
    const { context } = seededContext()
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosSecurity2faPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()

    expect(el(fixture, 'hilos-step-up-table')).toBeNull()
  })
})

describe('HilosSecurity2faPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  function bindSession(scopes: ScopeManager) {
    const listeners: ((signal: ProjectSignal) => void)[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void): () => void {
        if (event === 'projectSignal') {
          listeners.push(listener as (signal: ProjectSignal) => void)
        }

        return () => {}
      },
    } as unknown as HilosConnection
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

  it('a viewer opens a setting edit, sees the hidden mark instead of an input, and closes via Cancel', () => {
    const { context, sent } = seededContext(true)
    bindSession(context.scopes).handshake(null, true)
    const fixture = openModal(context, REQUIRED)

    expect(el(fixture, 'hilos-2fa-input')).toBeNull()
    expect(
      el(fixture, 'modal')?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(saveButton(fixture).disabled).toBe(true)
    saveButton(fixture).click()
    fixture.detectChanges()
    expect(sent).toEqual([])

    const cancel = Array.from(
      (
        fixture.nativeElement as HTMLElement
      ).querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    expect(cancel?.disabled).toBe(false)
    cancel?.click()
    fixture.detectChanges()

    expect(el(fixture, 'modal-confirm-discard')).toBeNull()
    expect(el(fixture, 'modal')).toBeNull()
  })

  it('an admin on a node in the mode has Save active after typing a draft', () => {
    const { context } = seededContext()
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    const fixture = openModal(context, REQUIRED)

    typeDraft(fixture, 'everyone')
    expect(saveButton(fixture).disabled).toBe(false)
    expect(saveButton(fixture).getAttribute('aria-describedby')).toBeNull()
  })
})
