// The return-address edit modal on the shared row-edit helper (HIL-1134): it
// holds the address row in focus, reloads a pristine modal when the address
// changes elsewhere and says so, shows the conflict chrome without Merge on a
// changed one and answers Keep mine / Take theirs, locks save as "Deleted" when
// the row goes, and asks before discarding a changed draft.
import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  createSignal,
} from '@hilos/core'
import type {
  HilosRouter,
  HilosSecurityOauthContext,
  PageRouteMatch,
} from '@hilos/core'

import HilosSecurityOauthPage from './HilosSecurityOauthPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const REDIRECT_TABLE = 'hilosSecurityOauthRedirect'
const ROW_KEY = 'oauth_redirect_uri'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.SECURITY_OAUTH,
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

/** The return-address row's slot, with the given value. */
function redirectSlot(value: string): Record<string, unknown> {
  return {
    value,
    source: value === '' ? 'default' : 'db',
    setState: value !== '',
  }
}

function seededContext(initial: string): {
  context: HilosSecurityOauthContext
  pushUpdate: (value: string) => void
  pushRemove: () => void
  sent: Array<{ action: string; payload: Record<string, unknown> }>
  focus: string[]
} {
  let rows = [redirectSlot(initial)]
  const focus: string[] = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.SECURITY_OAUTH)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (tableKey: string): void => {
    const served = tableKey === REDIRECT_TABLE ? rows : []
    const data = {
      page: HilosPages.SECURITY_OAUTH,
      tableKey,
      rows: served.map((redirect) => ({
        rowKey: ROW_KEY,
        slots: { redirect },
      })),
      totalCount: served.length,
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
          page: HilosPages.SECURITY_OAUTH,
          tableKey: REDIRECT_TABLE,
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
      connection:
        connection as unknown as HilosSecurityOauthContext['connection'],
      scopes,
      actions,
    },
    pushUpdate(value: string): void {
      rows = [redirectSlot(value)]
      pushDelta({
        kind: 'row_updated',
        rowKey: ROW_KEY,
        row: { rowKey: ROW_KEY, slots: { redirect: rows[0] } },
      })
    },
    pushRemove(): void {
      rows = []
      pushDelta({ kind: 'row_removed', rowKey: ROW_KEY, reason: 'deleted' })
    },
    sent,
    focus,
  }
}

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
  document.body.classList.remove('modal-open')
})

function modalEl(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function valueInput(): HTMLInputElement {
  return modalEl('hilos-oauth-redirect-input') as HTMLInputElement
}

function saveButton(): HTMLButtonElement {
  return modalEl('hilos-oauth-redirect-save') as HTMLButtonElement
}

function notice(): HTMLElement | null {
  return modalEl('hilos-oauth-redirect-edit-notice')
}

async function typeDraft(text: string): Promise<void> {
  const input = valueInput()
  input.value = text
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

/** Mount the page and open the modal on the seeded address. */
async function openModal(context: HilosSecurityOauthContext): Promise<void> {
  const wrapper = mount(HilosSecurityOauthPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
  mounted.push(wrapper)
  await nextTick()
  modalEl('hilos-oauth-redirect-edit')?.click()
  await nextTick()
}

/** Let a live frame reach the modal. */
async function settle(): Promise<void> {
  await nextTick()
  await nextTick()
}

describe('HilosSecurityOauthPage return-address modal', () => {
  it('opens on the live value with save locked and the message line empty', async () => {
    const { context } = seededContext('https://a.example/cb')
    await openModal(context)

    expect(valueInput().value).toBe('https://a.example/cb')
    expect(saveButton().disabled).toBe(true)
    expect(notice()).toBeNull()
    expect(modalEl('hilos-oauth-redirect-edit-notice-idle')).not.toBeNull()
  })

  it('sends nothing on Enter while a conflict stands', async () => {
    const { context, pushUpdate, sent } = seededContext('https://a.example/cb')
    await openModal(context)
    await typeDraft('https://mine.example/cb')
    pushUpdate('https://b.example/cb')
    await settle()

    valueInput().form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()

    expect(sent).toHaveLength(0)
    expect(modalEl('conflict-badge')).not.toBeNull()
  })

  it('reloads a pristine edit when the address changes elsewhere and says so', async () => {
    const { context, pushUpdate } = seededContext('https://a.example/cb')
    await openModal(context)

    pushUpdate('https://b.example/cb')
    await settle()

    expect(valueInput().value).toBe('https://b.example/cb')
    expect(modalEl('conflict-badge')).toBeNull()
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('surfaces a conflict on a changed edit, without Merge, and Keep mine sends mine', async () => {
    const { context, pushUpdate, sent } = seededContext('https://a.example/cb')
    await openModal(context)
    await typeDraft('https://mine.example/cb')

    pushUpdate('')
    await settle()

    expect(modalEl('conflict-badge')).not.toBeNull()
    expect(notice()?.textContent).toContain('Changed elsewhere to "—"')
    expect(modalEl('conflict-merge')).toBeNull()
    expect(saveButton().disabled).toBe(true)

    modalEl('conflict-accept-mine')?.click()
    await nextTick()
    expect(modalEl('conflict-badge')).toBeNull()
    expect(saveButton().disabled).toBe(false)

    saveButton().click()
    await nextTick()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('security_oauth_redirect_set')
    expect(sent[0]?.payload).toMatchObject({ value: 'https://mine.example/cb' })
  })

  it('Take theirs puts the live value in, says so, and locks save', async () => {
    const { context, pushUpdate } = seededContext('https://a.example/cb')
    await openModal(context)
    await typeDraft('https://mine.example/cb')
    pushUpdate('https://b.example/cb')
    await settle()

    modalEl('conflict-accept-theirs')?.click()
    await nextTick()

    expect(valueInput().value).toBe('https://b.example/cb')
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes under the modal', async () => {
    const { context, pushRemove } = seededContext('https://a.example/cb')
    await openModal(context)
    await typeDraft('https://mine.example/cb')
    pushRemove()
    await settle()

    expect(notice()?.textContent).toContain('Deleted elsewhere')
    expect(saveButton().disabled).toBe(true)
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(valueInput().value).toBe('https://mine.example/cb')
  })

  it('takes the row into focus on open, lets it go on close, and asks before discarding a changed draft', async () => {
    const { context, focus } = seededContext('https://a.example/cb')
    await openModal(context)
    expect(focus).toEqual([ROW_KEY])
    await typeDraft('https://mine.example/cb')

    modalEl('modal-close')?.click()
    await nextTick()
    expect(modalEl('modal-confirm-discard')).not.toBeNull()

    modalEl('modal-confirm-discard')?.click()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('closes a pristine edit on Save without a request', async () => {
    const { context, sent } = seededContext('https://a.example/cb')
    await openModal(context)

    valueInput().form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()

    expect(sent).toHaveLength(0)
    expect(modalEl('modal')).toBeNull()
  })
})
