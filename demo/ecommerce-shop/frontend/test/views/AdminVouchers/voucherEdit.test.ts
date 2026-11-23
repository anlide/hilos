// @vitest-environment happy-dom

import { act, createElement } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  SIGNAL_TYPE_PAGE_RESPONSE,
  createSignal,
  hilosToasts,
  type ActionHandle,
  type ActionResult,
} from '@hilos/core/base'

import { scopes } from '../../../src/bootstrap/session.js'
import { PAGE_ADMIN_VOUCHERS } from '../../../src/pages/keys.js'
import {
  VOUCHERS_TABLE,
  VOUCHER_SLOT,
} from '../../../src/views/AdminVouchers/adminVouchers.js'
import { VOUCHER_UPDATE } from '../../../src/views/AdminVouchers/adminVouchersActions.js'
import {
  createVouchersTable,
  type VouchersTable,
} from '../../../src/views/AdminVouchers/adminVouchersPage.js'
import { type VoucherRow } from '../../../src/views/AdminVouchers/types/tables/VoucherRow.js'
import { VoucherEditModal } from '../../../src/views/AdminVouchers/VoucherEditModal.js'
import {
  createVoucherEdit,
  type VoucherEdit,
} from '../../../src/views/AdminVouchers/voucherEdit.js'

const openingRow: VoucherRow = {
  id: 4,
  code: 'SUMMER15',
  discountPercent: 15,
  active: true,
}
const rowKey = String(openingRow.id)

const wire = vi.hoisted(() => {
  const listeners = new Map<string, Set<(signal: unknown) => void>>()
  return {
    listeners,
    connection: {
      on: vi.fn((name: string, listener: (signal: unknown) => void) => {
        const current = listeners.get(name) ?? new Set()
        current.add(listener)
        listeners.set(name, current)
        return () => current.delete(listener)
      }),
      registerTableWindow: vi.fn(),
      unregisterTableWindow: vi.fn(),
      sendTableViewport: vi.fn(),
      sendTableRendered: vi.fn(),
      sendTableRowFocus: vi.fn(),
      sendTableFacets: vi.fn(),
    },
    actions: { dispatch: vi.fn() },
  }
})

vi.mock('../../../src/bootstrap/connection.js', () => ({
  connection: wire.connection,
  actions: wire.actions,
}))
vi.mock('../../../src/bootstrap/session.js', async () => {
  const { ScopeManager } = await import('@hilos/core/base')
  return { scopes: new ScopeManager() }
})

const handle: ActionHandle = {
  requestId: 'voucher-update-1',
  loading: createSignal(false),
  done: Promise.resolve({}),
}

const mounted: Array<{ table: VouchersTable; edit: VoucherEdit }> = []

beforeEach(() => {
  vi.clearAllMocks()
  wire.actions.dispatch.mockReturnValue(handle)
  scopes.openPage(PAGE_ADMIN_VOUCHERS)
})

afterEach(() => {
  for (const { table, edit } of mounted.splice(0)) {
    edit.dispose()
    table.dispose()
  }
  scopes.dropPage()
  wire.listeners.clear()
})

function emit(name: string, signal: unknown): void {
  for (const listener of wire.listeners.get(name) ?? []) listener(signal)
}

function openEdit(): VoucherEdit {
  const table = createVouchersTable(vi.fn())
  const edit = createVoucherEdit(table.controller)
  mounted.push({ table, edit })
  table.start()
  edit.start()
  emit('projectSignal', {
    type: SIGNAL_TYPE_PAGE_RESPONSE,
    data: {
      page: PAGE_ADMIN_VOUCHERS,
      payload: {
        windows: {
          [VOUCHERS_TABLE]: {
            rows: [
              { rowKey: openingRow.id, slots: { [VOUCHER_SLOT]: openingRow } },
            ],
            totalCount: 1,
            totalExact: true,
            firstAnchor: { code: openingRow.code, id: openingRow.id },
            lastAnchor: { code: openingRow.code, id: openingRow.id },
            limit: 25,
            sort: [{ field: 'code', direction: 'asc' }],
            filter: {},
          },
        },
      },
    },
  })
  expect(edit.open(rowKey)).toBe(true)
  return edit
}

function changedElsewhere(patch: Partial<VoucherRow>): void {
  emit('tableViewportDelta', {
    data: {
      page: PAGE_ADMIN_VOUCHERS,
      tableKey: VOUCHERS_TABLE,
      kind: 'row_updated',
      rowKey,
      row: {
        rowKey: openingRow.id,
        slots: { [VOUCHER_SLOT]: { ...openingRow, ...patch } },
      },
    },
  })
}

describe('voucher edit', () => {
  it('opens with the focused row values and disables unchanged Save', () => {
    const edit = openEdit()

    expect(edit.form.get()).toEqual({
      code: 'SUMMER15',
      discountPercent: '15',
      active: true,
    })
    expect(wire.connection.sendTableRowFocus).toHaveBeenCalledWith(
      PAGE_ADMIN_VOUCHERS,
      VOUCHERS_TABLE,
      rowKey,
    )
    expect(edit.canSave.get()).toBe(false)
  })

  it('updates untouched fields while keeping the typed code', () => {
    const edit = openEdit()
    edit.patchForm({ code: 'MYCODE' })

    changedElsewhere({ discountPercent: 25, active: false })

    expect(edit.form.get()).toEqual({
      code: 'MYCODE',
      discountPercent: '25',
      active: false,
    })
    expect(edit.state.get().notice?.kind).toBe('updated')
    expect(edit.canSave.get()).toBe(true)
  })

  it('converges when the live row matches the draft as the server would save it', () => {
    const edit = openEdit()
    const draft = { code: ' spring25 ', discountPercent: '025' }
    edit.patchForm(draft)
    expect(edit.canSave.get()).toBe(true)

    changedElsewhere({ code: 'SPRING25', discountPercent: 25 })

    expect(edit.form.get()).toMatchObject(draft)
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.state.get().dirty).toBe(false)
    expect(edit.canSave.get()).toBe(false)
    expect(wire.actions.dispatch).not.toHaveBeenCalled()
  })

  it('keeps conflicts when the server would refuse the raw draft fields', () => {
    const edit = openEdit()
    const draft = { code: '\u00a0SPRING25', discountPercent: '25e0' }
    edit.patchForm(draft)

    changedElsewhere({ code: 'SPRING25', discountPercent: 25 })

    expect(edit.form.get()).toMatchObject(draft)
    expect(edit.state.get().notice?.fields).toEqual(['code', 'discountPercent'])
    expect(edit.canSave.get()).toBe(false)
  })

  it('blocks conflicting codes until Keep mine or Take theirs resolves them', () => {
    const edit = openEdit()
    edit.patchForm({ code: 'MYCODE' })

    changedElsewhere({ code: 'OTHER25' })

    expect(edit.state.get().conflict).toBe(true)
    expect(edit.form.get().code).toBe('MYCODE')
    expect(edit.noticeText.get()).toBe('Code changed elsewhere to "OTHER25".')
    expect(edit.canSave.get()).toBe(false)

    edit.keepMine()
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.form.get().code).toBe('MYCODE')
    expect(edit.canSave.get()).toBe(true)

    changedElsewhere({ code: 'OTHER30' })
    expect(edit.canSave.get()).toBe(false)
    edit.takeTheirs()

    expect(edit.state.get().conflict).toBe(false)
    expect(edit.form.get().code).toBe('OTHER30')
    expect(edit.canSave.get()).toBe(false)
  })

  it('holds conflict choices until the tracked save is refused', async () => {
    const edit = openEdit()
    const draft = { code: ' myCode ', discountPercent: '025' }
    edit.patchForm(draft)
    let refuse!: (error: ActionError) => void
    const done = new Promise<ActionResult>((_resolve, reject) => {
      refuse = reject
    })
    wire.actions.dispatch.mockReturnValue({ ...handle, done })

    vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
    const container = document.body.appendChild(document.createElement('div'))
    const root = createRoot(container)
    const button = (id: string) =>
      document.querySelector<HTMLButtonElement>(`button[data-id="${id}"]`)
    const keepMine = 'conflict-accept-mine'
    const takeTheirs = 'conflict-accept-theirs'
    try {
      await act(async () =>
        root.render(createElement(VoucherEditModal, { edit })),
      )
      await act(async () => button('admin-vouchers-edit-submit')!.click())
      expect(edit.saving.get()).toBe(true)

      await act(async () => changedElsewhere({ code: 'OTHER25' }))

      expect(
        document.querySelector('[data-id="admin-vouchers-edit-notice"]')
          ?.textContent,
      ).toContain('Code changed elsewhere to "OTHER25".')
      expect(button(keepMine)).toBeNull()
      expect(button(takeTheirs)).toBeNull()
      expect(button('admin-vouchers-edit-cancel')?.disabled).toBe(true)
      expect(button('admin-vouchers-edit-submit')?.disabled).toBe(true)
      await act(async () => {
        edit.keepMine()
        edit.takeTheirs()
      })
      expect(edit.state.get().conflict).toBe(true)
      expect(edit.form.get()).toMatchObject(draft)

      const refusal =
        'The voucher changed elsewhere. Review the current values and save again.'
      await act(async () =>
        refuse(new ActionError(VOUCHER_UPDATE, 'fail', refusal)),
      )

      expect(edit.saving.get()).toBe(false)
      expect(edit.opened.get()).toBe(true)
      expect(
        document.querySelector('[data-id="admin-vouchers-edit-error"]')
          ?.textContent,
      ).toContain(refusal)
      expect(edit.form.get()).toMatchObject(draft)
      expect(button(keepMine)?.disabled).toBe(false)
      expect(button(takeTheirs)?.disabled).toBe(false)
      await act(async () => button(takeTheirs)!.click())
      expect(edit.form.get()).toMatchObject({
        code: 'OTHER25',
        discountPercent: '025',
      })
      expect(edit.canSave.get()).toBe(true)
    } finally {
      await act(async () => root.unmount())
      container.remove()
      hilosToasts.clear()
      vi.unstubAllGlobals()
    }
  })

  it('sends raw draft fields and typed expected values from the latest focus', async () => {
    const edit = openEdit()
    const run = vi.fn().mockResolvedValue(true)
    changedElsewhere({ code: 'OTHER25', discountPercent: 25, active: false })
    edit.patchForm({ code: ' miXeD42 ', discountPercent: '042' })

    await edit.save(run)

    expect(wire.actions.dispatch).toHaveBeenCalledExactlyOnceWith(
      VOUCHER_UPDATE,
      {
        id: 4,
        code: ' miXeD42 ',
        discountPercent: '042',
        active: false,
        expectedCode: 'OTHER25',
        expectedDiscountPercent: 25,
        expectedActive: false,
      },
    )
    expect(run).toHaveBeenCalledExactlyOnceWith(handle)
    expect(edit.opened.get()).toBe(false)
    expect(wire.connection.sendTableRowFocus).toHaveBeenLastCalledWith(
      PAGE_ADMIN_VOUCHERS,
      VOUCHERS_TABLE,
      '',
    )
  })

  it('blocks a save while either required input is empty', () => {
    const edit = openEdit()

    edit.patchForm({ code: '' })
    expect(edit.canSave.get()).toBe(false)

    edit.patchForm({ code: 'MYCODE', discountPercent: '' })
    expect(edit.canSave.get()).toBe(false)
  })

  it('keeps the draft and refuses to send once the voucher disappears', async () => {
    const edit = openEdit()
    edit.patchForm({ code: 'MYCODE' })
    emit('tableViewportDelta', {
      data: {
        page: PAGE_ADMIN_VOUCHERS,
        tableKey: VOUCHERS_TABLE,
        kind: 'row_removed',
        rowKey,
        reason: 'deleted',
      },
    })

    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().code).toBe('MYCODE')
    expect(edit.saveLabel.get()).toBe('Deleted')
    expect(edit.canSave.get()).toBe(false)
    await edit.save(vi.fn())
    expect(wire.actions.dispatch).not.toHaveBeenCalled()
  })
})
