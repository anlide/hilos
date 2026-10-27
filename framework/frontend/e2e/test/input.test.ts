// @vitest-environment happy-dom
import type { Expect, Locator } from '@playwright/test'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { clickSubmit, typeInto } from '../input.js'

afterEach(() => {
  document.body.onclick = null
  document.body.replaceChildren()
  vi.restoreAllMocks()
})

function submitButton(replaceDuring?: 'scroll' | 'focus') {
  document.body.innerHTML = '<button data-id="submit">Submit</button>'
  const current = () => document.querySelector<HTMLButtonElement>('button')!
  const original = current()
  const events: string[] = []
  let preparing = false
  let replaced = false
  const prepare = (step: 'scroll' | 'focus') => {
    events.push(step)
    if (step === replaceDuring && !replaced) {
      replaced = true
      original.replaceWith(original.cloneNode(true))
      throw new Error('Element was detached during preparation')
    }
    if (step === 'focus') current().focus()
  }
  const click = vi.fn(async () => {
    events.push('click')
    expect(preparing).toBe(false)
    expect(document.activeElement).toBe(current())
    current().click()
  })
  const locator = {
    scrollIntoViewIfNeeded: vi.fn(async () => prepare('scroll')),
    focus: vi.fn(async () => prepare('focus')),
    click,
  } as unknown as Locator
  const attempts = vi.fn(async (callback: () => Promise<void>) => {
    for (let attempt = 0; ; attempt++) {
      preparing = true
      try {
        await callback()
        return
      } catch (error) {
        if (attempt === 1) throw error
      } finally {
        preparing = false
      }
    }
  })
  // Playwright owns the retry policy. This adapter supplies two deterministic
  // attempts and checks which operations the helper puts inside that boundary.
  const check = ((value: Locator | (() => Promise<void>)) => {
    if (typeof value === 'function') {
      return { toPass: () => attempts(value) }
    }
    expect(value).toBe(locator)
    return {
      toBeVisible: async () => {
        events.push('visible')
        expect(current().isConnected).toBe(true)
      },
      toBeEnabled: async () => {
        events.push('enabled')
        expect(current().disabled).toBe(false)
      },
    }
  }) as unknown as Expect
  const submitted = vi.fn()
  document.body.onclick = submitted
  return {
    locator,
    check,
    click,
    attempts,
    events,
    original,
    current,
    submitted,
  }
}

describe('typeInto', () => {
  it('clears the old value before typing every key with the shared delay', async () => {
    const input = document.createElement('input')
    input.value = 'previous draft'
    document.body.append(input)
    const fill = vi.fn(async (value: string) => {
      input.value = value
    })
    const pressSequentially = vi.fn(
      async (value: string, options: { delay: number }) => {
        expect(input.value).toBe('')
        expect(options).toEqual({ delay: 10 })
        input.value += value
      },
    )

    await typeInto(
      { fill, pressSequentially } as unknown as Locator,
      'new draft',
    )

    expect(fill).toHaveBeenCalledExactlyOnceWith('')
    expect(pressSequentially).toHaveBeenCalledExactlyOnceWith('new draft', {
      delay: 10,
    })
    expect(input.value).toBe('new draft')
  })
})

describe('clickSubmit', () => {
  it.each(['scroll', 'focus'] as const)(
    'repeats preparation after replacement during %s, then submits once',
    async (step) => {
      const f = submitButton(step)

      await clickSubmit(f.check, f.locator)

      expect(f.original.isConnected).toBe(false)
      expect(f.current()).not.toBe(f.original)
      expect(f.events).toEqual([
        ...(step === 'scroll'
          ? ['scroll']
          : ['scroll', 'visible', 'enabled', 'focus']),
        'scroll',
        'visible',
        'enabled',
        'focus',
        'click',
      ])
      expect(f.attempts).toHaveBeenCalledTimes(1)
      expect(f.click).toHaveBeenCalledTimes(1)
      expect(f.submitted).toHaveBeenCalledTimes(1)
    },
  )

  it('does not retry a failed click after preparation has succeeded', async () => {
    const f = submitButton()
    f.click.mockRejectedValue(new Error('Click failed after dispatch'))

    await expect(clickSubmit(f.check, f.locator)).rejects.toThrow(
      'Click failed after dispatch',
    )

    expect(f.events).toEqual(['scroll', 'visible', 'enabled', 'focus'])
    expect(f.click).toHaveBeenCalledTimes(1)
    expect(f.attempts).toHaveBeenCalledTimes(1)
  })

  it('does not click if preparation never succeeds', async () => {
    const f = submitButton()
    vi.mocked(f.locator.focus).mockRejectedValue(new Error('Still detached'))

    await expect(clickSubmit(f.check, f.locator)).rejects.toThrow(
      'Still detached',
    )

    expect(f.locator.scrollIntoViewIfNeeded).toHaveBeenCalledTimes(2)
    expect(f.click).not.toHaveBeenCalled()
  })
})
