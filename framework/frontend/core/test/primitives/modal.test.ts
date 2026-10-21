import { describe, expect, it } from 'vitest'

import {
  createModalController,
  type ModalController,
} from '../../src/primitives/modal.js'

/** Close-guard flags; the getters read them live, so a test may flip them. */
interface Flags {
  confirmOnClose?: boolean
  closeOnEsc?: boolean
  closeOnBackdrop?: boolean
  busy?: boolean
}

function setup(flags: Flags = {}) {
  let closed = 0
  const modal = createModalController({
    confirmOnClose: () => flags.confirmOnClose ?? false,
    closeOnEsc: () => flags.closeOnEsc ?? true,
    closeOnBackdrop: () => flags.closeOnBackdrop ?? true,
    busy: () => flags.busy ?? false,
    onClose: () => {
      closed += 1
    },
  })

  return { modal, closed: () => closed }
}

/** Every user close gesture the busy guard suppresses. */
const closeGestures: ReadonlyArray<
  readonly [string, (modal: ModalController) => void]
> = [
  ['requestClose', (modal) => modal.requestClose()],
  ['onEsc', (modal) => modal.onEsc()],
  ['onBackdrop', (modal) => modal.onBackdrop()],
  ['discard', (modal) => modal.discard()],
]

/** The gestures that raise the confirm step over a dirty draft. */
const confirmGestures = closeGestures.filter(([name]) => name !== 'discard')

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

  it('is idle when no busy getter is given', () => {
    let closed = 0
    const modal = createModalController({
      onClose: () => {
        closed += 1
      },
    })
    modal.requestClose()
    expect(closed).toBe(1)
  })

  describe.each(closeGestures)('%s and busy', (_name, gesture) => {
    it('does not close while busy and closes again once idle', () => {
      const flags: Flags = { closeOnEsc: true, closeOnBackdrop: true }
      const { modal, closed } = setup(flags)
      flags.busy = true
      gesture(modal)
      expect(closed()).toBe(0)
      expect(modal.confirmVisible.get()).toBe(false)
      flags.busy = false
      expect(closed()).toBe(0)
      gesture(modal)
      expect(closed()).toBe(1)
    })

    it('keeps an open confirm step while busy', () => {
      const flags: Flags = { confirmOnClose: true }
      const { modal, closed } = setup(flags)
      modal.requestClose()
      flags.busy = true
      gesture(modal)
      expect(modal.confirmVisible.get()).toBe(true)
      expect(closed()).toBe(0)
    })
  })

  describe.each(confirmGestures)('%s, dirty and busy', (_name, gesture) => {
    it('raises no confirm step while busy and raises it once idle', () => {
      const flags: Flags = { confirmOnClose: true, busy: true }
      const { modal, closed } = setup(flags)
      gesture(modal)
      expect(modal.confirmVisible.get()).toBe(false)
      flags.busy = false
      expect(modal.confirmVisible.get()).toBe(false)
      gesture(modal)
      expect(modal.confirmVisible.get()).toBe(true)
      expect(closed()).toBe(0)
    })
  })

  it('discards once idle after a confirm step kept through busy', () => {
    const flags: Flags = { confirmOnClose: true }
    const { modal, closed } = setup(flags)
    modal.requestClose()
    flags.busy = true
    modal.discard()
    flags.busy = false
    modal.discard()
    expect(modal.confirmVisible.get()).toBe(false)
    expect(closed()).toBe(1)
  })

  it('keeps editing and resets while busy', () => {
    const flags: Flags = { confirmOnClose: true }
    const { modal, closed } = setup(flags)
    modal.requestClose()
    flags.busy = true
    modal.keepEditing()
    expect(modal.confirmVisible.get()).toBe(false)
    flags.busy = false
    modal.requestClose()
    flags.busy = true
    modal.reset()
    expect(modal.confirmVisible.get()).toBe(false)
    expect(closed()).toBe(0)
  })

  it('keeps closeOnEsc false in force after busy clears', () => {
    const flags: Flags = { closeOnEsc: false, busy: true }
    const { modal, closed } = setup(flags)
    modal.onEsc()
    flags.busy = false
    modal.onEsc()
    expect(closed()).toBe(0)
  })

  it('keeps closeOnBackdrop false in force after busy clears', () => {
    const flags: Flags = { closeOnBackdrop: false, busy: true }
    const { modal, closed } = setup(flags)
    modal.onBackdrop()
    flags.busy = false
    modal.onBackdrop()
    expect(closed()).toBe(0)
  })
})
