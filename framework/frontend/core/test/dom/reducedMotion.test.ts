// @vitest-environment happy-dom

import { afterEach, describe, expect, it, vi } from 'vitest'

import { bindReducedMotion } from '../../src/dom/reducedMotion.js'

let unbind: (() => void) | undefined

afterEach(() => {
  unbind?.()
  unbind = undefined
  vi.unstubAllGlobals()
})

describe('bindReducedMotion', () => {
  it.each([true, false])(
    'reports matches=%s synchronously using the reduced-motion preference',
    (matches) => {
      const media = Object.assign(new EventTarget(), { matches })
      const matchMedia = vi.fn(() => media)
      vi.stubGlobal('matchMedia', matchMedia)
      const onChange = vi.fn()

      unbind = bindReducedMotion(onChange)

      expect(matchMedia).toHaveBeenCalledExactlyOnceWith(
        '(prefers-reduced-motion: reduce)',
      )
      expect(onChange).toHaveBeenCalledExactlyOnceWith(matches)
    },
  )

  it('reports media changes until unbound', () => {
    const media = Object.assign(new EventTarget(), { matches: false })
    vi.stubGlobal('matchMedia', () => media)
    const onChange = vi.fn()
    unbind = bindReducedMotion(onChange)

    media.dispatchEvent(Object.assign(new Event('change'), { matches: true }))
    media.dispatchEvent(Object.assign(new Event('change'), { matches: false }))

    expect(onChange.mock.calls).toEqual([[false], [true], [false]])

    unbind()
    media.dispatchEvent(Object.assign(new Event('change'), { matches: true }))

    expect(onChange.mock.calls).toEqual([[false], [true], [false]])
  })

  it('reports false and releases safely without matchMedia', () => {
    vi.stubGlobal('matchMedia', undefined)
    const onChange = vi.fn()

    unbind = bindReducedMotion(onChange)

    expect(onChange).toHaveBeenCalledExactlyOnceWith(false)
    expect(() => unbind?.()).not.toThrow()
  })

  it('reports false and releases safely when matchMedia throws', () => {
    vi.stubGlobal('matchMedia', () => {
      throw new Error('Media queries are unavailable')
    })
    const onChange = vi.fn()

    unbind = bindReducedMotion(onChange)

    expect(onChange).toHaveBeenCalledExactlyOnceWith(false)
    expect(() => unbind?.()).not.toThrow()
  })
})
