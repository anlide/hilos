import { describe, expect, it } from 'vitest'

import { createModalController } from './modal.js'

function setup(
  flags: {
    confirmOnClose?: boolean
    closeOnEsc?: boolean
    closeOnBackdrop?: boolean
  } = {},
) {
  let closed = 0
  const modal = createModalController({
    confirmOnClose: () => flags.confirmOnClose ?? false,
    closeOnEsc: () => flags.closeOnEsc ?? true,
    closeOnBackdrop: () => flags.closeOnBackdrop ?? true,
    onClose: () => {
      closed += 1
    },
  })

  return { modal, closed: () => closed }
}

describe('createModalController', () => {
  it('closes immediately when not guarding', () => {
    const { modal, closed } = setup()
    modal.requestClose()
    expect(modal.confirmVisible.get()).toBe(false)
    expect(closed()).toBe(1)
  })

  it('raises the confirm step instead of closing when guarding', () => {
    const { modal, closed } = setup({ confirmOnClose: true })
    modal.requestClose()
    expect(modal.confirmVisible.get()).toBe(true)
    expect(closed()).toBe(0)
  })

  it('discards: leaves the confirm step and closes', () => {
    const { modal, closed } = setup({ confirmOnClose: true })
    modal.requestClose()
    modal.discard()
    expect(modal.confirmVisible.get()).toBe(false)
    expect(closed()).toBe(1)
  })

  it('keeps editing: leaves the confirm step without closing', () => {
    const { modal, closed } = setup({ confirmOnClose: true })
    modal.requestClose()
    modal.keepEditing()
    expect(modal.confirmVisible.get()).toBe(false)
    expect(closed()).toBe(0)
  })

  it('Escape backs out of the confirm step before it closes', () => {
    const { modal, closed } = setup({ confirmOnClose: true })
    modal.requestClose()
    modal.onEsc()
    expect(modal.confirmVisible.get()).toBe(false)
    expect(closed()).toBe(0)
  })

  it('Escape closes when allowed and not guarding', () => {
    const { modal, closed } = setup()
    modal.onEsc()
    expect(closed()).toBe(1)
  })

  it('Escape does nothing when closeOnEsc is false', () => {
    const { modal, closed } = setup({ closeOnEsc: false })
    modal.onEsc()
    expect(closed()).toBe(0)
  })

  it('a backdrop click closes when allowed', () => {
    const { modal, closed } = setup()
    modal.onBackdrop()
    expect(closed()).toBe(1)
  })

  it('a backdrop click is ignored when closeOnBackdrop is false', () => {
    const { modal, closed } = setup({ closeOnBackdrop: false })
    modal.onBackdrop()
    expect(closed()).toBe(0)
  })

  it('a backdrop click is ignored while the confirm step is open', () => {
    const { modal, closed } = setup({ confirmOnClose: true })
    modal.requestClose()
    modal.onBackdrop()
    expect(modal.confirmVisible.get()).toBe(true)
    expect(closed()).toBe(0)
  })

  it('reset clears the confirm step', () => {
    const { modal } = setup({ confirmOnClose: true })
    modal.requestClose()
    modal.reset()
    expect(modal.confirmVisible.get()).toBe(false)
  })
})
