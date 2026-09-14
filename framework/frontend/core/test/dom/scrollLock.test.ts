// @vitest-environment happy-dom

import { afterEach, describe, expect, it } from 'vitest'

import { lockBodyScroll, unlockBodyScroll } from '../../src/dom/scrollLock.js'

afterEach(() => {
  document.body.classList.remove('modal-open')
})

describe('scroll lock', () => {
  it('keeps one document locked until its last owner releases it', () => {
    const first = {}
    const second = {}
    lockBodyScroll(document, first)
    lockBodyScroll(document, second)

    unlockBodyScroll(first)
    expect(document.body.classList.contains('modal-open')).toBe(true)

    unlockBodyScroll(second)
    expect(document.body.classList.contains('modal-open')).toBe(false)
  })

  it('touches no class when an owner did not take a lock', () => {
    const owner = {}

    unlockBodyScroll(owner)
    expect(document.body.classList.contains('modal-open')).toBe(false)

    document.body.classList.add('modal-open')
    unlockBodyScroll(owner)
    expect(document.body.classList.contains('modal-open')).toBe(true)
  })

  it('keeps a repeated lock by one owner idempotent', () => {
    const owner = {}
    lockBodyScroll(document, owner)
    lockBodyScroll(document, owner)

    unlockBodyScroll(owner)

    expect(document.body.classList.contains('modal-open')).toBe(false)
  })

  it('tracks the owners of different documents separately', () => {
    const other = document.implementation.createHTMLDocument()
    const first = {}
    const second = {}
    lockBodyScroll(document, first)
    lockBodyScroll(other, second)

    unlockBodyScroll(first)
    expect(document.body.classList.contains('modal-open')).toBe(false)
    expect(other.body.classList.contains('modal-open')).toBe(true)

    unlockBodyScroll(second)
    expect(other.body.classList.contains('modal-open')).toBe(false)
  })
})
