// @vitest-environment happy-dom

import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import {
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  SIGNAL_TYPE_PAGE_RESPONSE,
  USER_ENTITY_TYPE,
  bindAdminAccess,
  bindPageScope,
  createHilosRouter,
  ingest,
  type HilosConnection,
} from '@hilos/core/base'
import { HilosRouterContext } from '@hilos/react/base'

import { connection } from '../../../src/bootstrap/connection.js'
import { scopes } from '../../../src/bootstrap/session.js'
import { PAGE_ADMIN_VOUCHERS } from '../../../src/pages/keys.js'
import { router as routes } from '../../../src/pages/routes.js'
import AdminVouchers from '../../../src/views/AdminVouchers/AdminVouchers.js'
import {
  VOUCHERS_TABLE,
  VOUCHER_CODE_FIELD,
  VOUCHER_SLOT,
} from '../../../src/views/AdminVouchers/adminVouchers.js'
import type { VoucherRow } from '../../../src/views/AdminVouchers/types/tables/VoucherRow.js'

const wire = vi.hoisted(() => {
  const listeners = new Map<string, Set<(signal: unknown) => void>>()
  return {
    connection: {
      state: 'connected',
      send: () => true,
      sendAction: vi.fn<HilosConnection['sendAction']>(() => true),
      forgetPageFrames: () => {},
      tableWindowDescriptors: () => ({}),
      registerTableWindow: () => {},
      unregisterTableWindow: () => {},
      sendTableViewport: () => {},
      sendTableRendered: () => {},
      sendTableRowFocus: () => {},
      sendTableFacets: () => {},
      on(event: string, listener: (signal: unknown) => void) {
        const members = listeners.get(event) ?? new Set()
        members.add(listener)
        listeners.set(event, members)
        return () => members.delete(listener)
      },
    },
    emit(event: string, signal: unknown) {
      for (const listener of listeners.get(event) ?? []) listener(signal)
    },
  }
})

vi.mock('../../../src/bootstrap/connection.js', async () => {
  const { ActionLifecycle } = await import('@hilos/core/base')
  return {
    connection: wire.connection,
    actions: new ActionLifecycle(wire.connection as unknown as HilosConnection),
  }
})

vi.mock('../../../src/bootstrap/session.js', async () => {
  const { ScopeManager } = await import('@hilos/core/base')
  return { scopes: new ScopeManager() }
})

const voucher: VoucherRow = {
  id: 4,
  code: 'SUMMER15',
  discountPercent: 15,
  active: true,
}
const otherVoucher: VoucherRow = { ...voucher, id: 5, code: 'WINTER15' }
const switchSelector = '[role="switch"][data-id="admin-vouchers-active-toggle"]'
const sendAction = wire.connection.sendAction
let root: Root | undefined
const release: Array<() => void> = []

beforeEach(() => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  sendAction.mockClear()
})

afterEach(async () => {
  await act(async () => {
    root?.unmount()
    for (const [action, , requestId] of sendAction.mock.calls) {
      wire.emit('actionSuccess', { action, requestId })
    }
  })
  root = undefined
  for (const stop of release.splice(0).reverse()) stop()
  scopes.dropPage()
  document.body.replaceChildren()
  vi.unstubAllGlobals()
})

async function mount(admin: boolean) {
  ingest(
    scopes.session,
    {
      data: { adminViewMode: true },
      entities: { currentUser: { id: 1, admin } },
    },
    { entityTypes: { currentUser: USER_ENTITY_TYPE } },
  )
  release.push(bindAdminAccess(scopes))

  const pages = bindPageScope(connection, scopes)
  const router = createHilosRouter(routes, pages, {
    pathname: () => routes.path(PAGE_ADMIN_VOUCHERS)!,
    pushState: () => {},
    replaceState: () => {},
    onPopState: () => () => {},
  })
  router.start()
  release.push(() => router.stop())
  pages.releaseOnSession()
  const mounted = createRoot(
    document.body.appendChild(document.createElement('div')),
  )
  root = mounted
  await act(async () => {
    mounted.render(
      <HilosRouterContext.Provider value={router}>
        <AdminVouchers />
      </HilosRouterContext.Provider>,
    )
  })
  const anchor = { code: voucher.code, id: voucher.id }
  await act(async () => {
    wire.emit('projectSignal', {
      type: SIGNAL_TYPE_PAGE_RESPONSE,
      data: {
        page: PAGE_ADMIN_VOUCHERS,
        payload: {
          windows: {
            [VOUCHERS_TABLE]: {
              rows: [voucher, otherVoucher].map((row) => ({
                rowKey: row.id,
                slots: { [VOUCHER_SLOT]: row },
              })),
              totalCount: 2,
              totalExact: true,
              firstAnchor: anchor,
              lastAnchor: { code: otherVoucher.code, id: otherVoucher.id },
              limit: 25,
              sort: [{ field: VOUCHER_CODE_FIELD, direction: 'asc' }],
              filter: {},
            },
          },
        },
      },
    })
  })
  const voucherSwitch = `${switchSelector}[aria-label="Active · ${voucher.code}"]`
  return {
    cell: document.querySelector<HTMLInputElement>(`tbody ${voucherSwitch}`)!,
    card: document.querySelector<HTMLInputElement>(
      `[data-id="hilos-table-cards"] ${voucherSwitch}`,
    )!,
    otherCell: document.querySelector<HTMLInputElement>(
      `tbody ${switchSelector}[aria-label="Active · ${otherVoucher.code}"]`,
    )!,
  }
}

it('shares one pending toggle between the two views and follows the live row', async () => {
  const { cell, card, otherCell } = await mount(true)
  for (const control of [cell, card]) {
    expect(control.getAttribute('aria-label')).toBe('Active · SUMMER15')
    expect(control.checked).toBe(true)
  }
  expect(card.closest('dd')?.previousElementSibling?.textContent).toBe('Active')

  // Exercise the other projection while dispatch is still on the stack.
  sendAction.mockImplementationOnce(() => {
    card.click()
    return true
  })
  await act(async () => {
    cell.focus()
    cell.click()
  })
  expect(sendAction).toHaveBeenCalledExactlyOnceWith(
    'voucher_toggle_active',
    { id: voucher.id },
    expect.any(String),
  )
  for (const control of [cell, card]) {
    expect(control.checked).toBe(true)
    expect(control.disabled).toBe(false)
    expect(control.getAttribute('aria-busy')).toBe('true')
    expect(control.getAttribute('aria-disabled')).toBe('true')
  }
  expect(otherCell.disabled).toBe(true)
  expect(otherCell.hasAttribute('aria-busy')).toBe(false)
  await act(async () => {
    cell.click()
    card.click()
    otherCell.click()
  })
  expect(sendAction).toHaveBeenCalledTimes(1)
  expect(document.activeElement).toBe(cell)

  const [action, , requestId] = sendAction.mock.calls[0]
  await act(async () => wire.emit('actionSuccess', { action, requestId }))
  for (const control of [cell, card]) {
    expect(control.checked).toBe(true)
    expect(control.disabled).toBe(false)
    expect(control.hasAttribute('aria-busy')).toBe(false)
    expect(control.hasAttribute('aria-disabled')).toBe(false)
  }
  expect(otherCell.disabled).toBe(false)
  expect(document.activeElement).toBe(cell)

  await act(async () => {
    wire.emit('tableViewportDelta', {
      data: {
        page: PAGE_ADMIN_VOUCHERS,
        tableKey: VOUCHERS_TABLE,
        kind: 'row_updated',
        rowKey: String(voucher.id),
        row: {
          rowKey: voucher.id,
          slots: { [VOUCHER_SLOT]: { ...voucher, active: false } },
        },
      },
    })
  })
  expect(cell.checked).toBe(false)
  expect(card.checked).toBe(false)
  expect(document.activeElement).toBe(cell)

  await act(async () => card.click())
  expect(sendAction).toHaveBeenCalledTimes(2)
  expect(sendAction).toHaveBeenLastCalledWith(
    'voucher_toggle_active',
    { id: voucher.id },
    expect.any(String),
  )
  expect(cell.checked).toBe(false)
  expect(card.checked).toBe(false)
})

it('shows the delivered position in both views without sending a toggle in view mode', async () => {
  const { cell, card } = await mount(false)
  for (const control of [cell, card]) {
    expect(control.checked).toBe(true)
    expect(control.disabled).toBe(true)
    expect(control.getAttribute('aria-describedby')).toBe(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
  }

  await act(async () => {
    cell.click()
    card.click()
  })
  expect(sendAction).not.toHaveBeenCalled()
})
