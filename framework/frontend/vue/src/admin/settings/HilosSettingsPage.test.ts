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
  HilosSettingsContext,
  PageRouteMatch,
} from '@hilos/core'

import HilosSettingsPage from './HilosSettingsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

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
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

interface SettingSlot {
  key: string
  type: string
  value: string | null
  overrideValue: string | null
  defaultValue: string | null
  defaultReferenceKey: string | null
  valueSource: string
}

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
} {
  let rows = initial.slice()
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
  const actions = new ActionLifecycle({
    sendAction: () => false,
    on: () => () => {},
  })

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
  }
}

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
  document.body.classList.remove('modal-open')
})

async function mountPage(context: HilosSettingsContext) {
  const wrapper = mount(HilosSettingsPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
  mounted.push(wrapper)
  await nextTick()

  return wrapper
}

function editButton(key: string): HTMLElement {
  return document.querySelector(
    `[data-id="hilos-settings-edit-${key}"]`,
  ) as HTMLElement
}

function modalEl(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

describe('HilosSettingsPage', () => {
  it('renders a row per setting with its key', async () => {
    const { context } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    const wrapper = await mountPage(context)

    expect(wrapper.findAll('[data-id^="hilos-table-row-"]').length).toBe(1)
    expect(wrapper.text()).toContain('site_name')
  })

  it('opens the edit dialog from a row action', async () => {
    const { context } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    expect(modalEl('modal')).toBeNull()
    editButton('site_name').click()
    await nextTick()
    expect(modalEl('modal')).not.toBeNull()
  })

  it('reloads a pristine edit when the live row changes elsewhere', async () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await nextTick()
    const input = modalEl('hilos-settings-edit-value') as HTMLInputElement
    expect(input.value).toBe('Hilos')

    pushUpdate(
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Elsewhere',
        overrideValue: 'Elsewhere',
      }),
    )
    await nextTick()
    await nextTick()

    expect(input.value).toBe('Elsewhere')
    expect(modalEl('conflict-badge')).toBeNull()
    expect(
      (modalEl('hilos-settings-edit-save') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('surfaces a conflict on a dirty edit and hides merge', async () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await nextTick()
    const input = modalEl('hilos-settings-edit-value') as HTMLInputElement
    input.value = 'Mine'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()

    pushUpdate(
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Theirs',
        overrideValue: 'Theirs',
      }),
    )
    await nextTick()
    await nextTick()

    expect(modalEl('conflict-badge')).not.toBeNull()
    expect(modalEl('hilos-settings-edit-conflict')?.textContent).toContain(
      'The value changed elsewhere to "Theirs"',
    )
    expect(modalEl('conflict-merge')).toBeNull()
    expect(
      (modalEl('hilos-settings-edit-save') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('Keep mine unlocks save on a dirty conflict', async () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await nextTick()
    const input = modalEl('hilos-settings-edit-value') as HTMLInputElement
    input.value = 'Mine'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    pushUpdate(
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Theirs',
        overrideValue: 'Theirs',
      }),
    )
    await nextTick()
    await nextTick()

    modalEl('conflict-accept-mine')?.click()
    await nextTick()
    expect(modalEl('conflict-badge')).toBeNull()
    expect(
      (modalEl('hilos-settings-edit-save') as HTMLButtonElement).disabled,
    ).toBe(false)
  })

  it('Take theirs sets the field to the live value and locks save', async () => {
    const { context, pushUpdate } = seededContext([
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Hilos',
        overrideValue: 'Hilos',
      }),
    ])
    await mountPage(context)
    editButton('site_name').click()
    await nextTick()
    const input = modalEl('hilos-settings-edit-value') as HTMLInputElement
    input.value = 'Mine'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()
    pushUpdate(
      slot({
        key: 'site_name',
        valueSource: 'override',
        value: 'Theirs',
        overrideValue: 'Theirs',
      }),
    )
    await nextTick()
    await nextTick()

    modalEl('conflict-accept-theirs')?.click()
    await nextTick()
    expect(input.value).toBe('Theirs')
    expect(modalEl('conflict-badge')).toBeNull()
    expect(
      (modalEl('hilos-settings-edit-save') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('locks save and names the gone row when it is removed under the modal', async () => {
    const { context, pushRemove } = seededContext([
      slot({
        key: 'legacy',
        valueSource: 'orphan',
        value: 'x',
        overrideValue: 'x',
        defaultValue: null,
      }),
    ])
    await mountPage(context)
    editButton('legacy').click()
    await nextTick()
    pushRemove('legacy')
    await nextTick()
    await nextTick()

    expect(modalEl('hilos-settings-edit-gone')).not.toBeNull()
    const save = modalEl('hilos-settings-edit-save') as HTMLButtonElement
    expect(save.disabled).toBe(true)
    expect(save.textContent?.trim()).toBe('Deleted')
  })
})
