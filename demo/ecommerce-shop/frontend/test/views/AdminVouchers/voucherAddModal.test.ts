// @vitest-environment happy-dom

import { act, createElement } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, expect, it, vi } from 'vitest'
import type { HilosConnection } from '@hilos/core/base'

import { VoucherAddModal } from '../../../src/views/AdminVouchers/VoucherAddModal.js'

const wire = vi.hoisted(() => {
  const listeners = new Map<string, (reply: unknown) => void>()
  const connection = {
    sendAction: vi.fn<HilosConnection['sendAction']>(() => true),
    on(event: string, listener: (reply: unknown) => void) {
      listeners.set(event, listener)
      return () => listeners.delete(event)
    },
  }
  return {
    connection,
    emit(event: string, reply: unknown) {
      listeners.get(event)?.(reply)
    },
  }
})

vi.mock('../../../src/bootstrap/connection.js', async () => {
  const { ActionLifecycle } = await import('@hilos/core/base')
  return {
    actions: new ActionLifecycle(wire.connection as unknown as HilosConnection),
  }
})

let root: Root | undefined

afterEach(async () => {
  await act(async () => {
    root?.unmount()
    // Settle pending requests even when the dispatch count assertion fails.
    for (const [action, , requestId] of wire.connection.sendAction.mock.calls) {
      wire.emit('actionSuccess', { action, requestId })
    }
  })
  document.body.replaceChildren()
  wire.connection.sendAction.mockClear()
  vi.unstubAllGlobals()
})

function type(field: HTMLInputElement, value: string): void {
  Object.getOwnPropertyDescriptor(
    HTMLInputElement.prototype,
    'value',
  )!.set!.call(field, value)
  field.dispatchEvent(new Event('input', { bubbles: true }))
}

it('sends one voucher creation for same-turn submits and retries after refusal', async () => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  const onClose = vi.fn()
  const container = document.body.appendChild(document.createElement('div'))
  root = createRoot(container)
  await act(async () =>
    root!.render(createElement(VoucherAddModal, { onClose })),
  )

  const code = document.querySelector<HTMLInputElement>(
    '#admin-vouchers-add-code',
  )!
  const discount = document.querySelector<HTMLInputElement>(
    '#admin-vouchers-add-discount-percent',
  )!
  const add = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-vouchers-add-submit"]',
  )!
  const form = code.form!
  await act(async () => {
    type(code, 'AUTUMN20')
    type(discount, '101')
  })
  expect(add.disabled).toBe(false)

  await act(async () => {
    form.requestSubmit()
    form.requestSubmit()
  })

  const sendAction = wire.connection.sendAction
  const action = 'voucher_create'
  expect(sendAction).toHaveBeenCalledExactlyOnceWith(
    action,
    { code: 'AUTUMN20', discountPercent: '101', active: true },
    expect.any(String),
  )
  const reason = 'Discount must be a whole percent from 1 to 100'
  await act(async () => {
    wire.emit('actionError', {
      action,
      requestId: sendAction.mock.calls[0][2],
      reason,
    })
  })
  expect(onClose).not.toHaveBeenCalled()
  expect(
    document.querySelector('[data-id="admin-vouchers-add-error"]')?.textContent,
  ).toContain(reason)

  await act(async () => type(discount, '20'))
  await act(async () => form.requestSubmit(add))
  expect(sendAction).toHaveBeenCalledTimes(2)
  expect(sendAction).toHaveBeenLastCalledWith(
    action,
    { code: 'AUTUMN20', discountPercent: '20', active: true },
    expect.any(String),
  )
  await act(async () => {
    wire.emit('actionSuccess', {
      action,
      requestId: sendAction.mock.calls[1][2],
    })
  })
  expect(onClose).toHaveBeenCalledOnce()
})
