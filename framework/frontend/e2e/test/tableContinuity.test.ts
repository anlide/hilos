// @vitest-environment happy-dom
import type { Expect, Locator } from '@playwright/test'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { watchTableContinuity } from '../tableContinuity.js'
import {
  TABLE_SEARCH_HOOK,
  type TableSearchApplication,
} from '../tableFocus.js'

const cleanups: (() => Promise<void>)[] = []
afterEach(async () => {
  for (const cleanup of cleanups.splice(0)) await cleanup()
  document.body.replaceChildren()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

async function browser() {
  // happy-dom has no layout engine or native checkVisibility implementation.
  Object.defineProperty(Element.prototype, 'checkVisibility', {
    configurable: true,
    value: () => true,
  })
  cleanups.push(async () => {
    Reflect.deleteProperty(Element.prototype, 'checkVisibility')
  })
  const root = document.createElement('div')
  root.dataset.id = 'hilos-viewport-table'
  root.innerHTML =
    '<table><tbody><tr data-id="hilos-table-row-same"><td><button data-id="expand" aria-expanded="true">Expand</button></td></tr><tr><td><span data-id="selection">same</span></td></tr></tbody></table>'
  const opener = root.querySelector<HTMLButtonElement>('button')!
  document.body.append(root)
  opener.focus()
  let current: TableSearchApplication = {
    root: 2,
    controller: 1,
    appliedRequestId: 'applied',
    search: 'query',
    filter: { search: 'query' },
    descriptor: { filter: { search: 'query' }, limit: 25 },
    occurrences: [
      {
        rowKey: 'same',
        occurrence: 7,
        expanded: true,
        selected: false,
        placeholder: false,
      },
    ],
    rows: ['same'],
    domRows: ['same'],
    domSearch: 'query',
  }
  const browserWindow = Object.create(window)
  browserWindow[TABLE_SEARCH_HOOK] = () => ({ application: [current] })
  vi.stubGlobal('window', browserWindow)
  const trigger = {
    async evaluateHandle(
      run: (opener: Element, arg: unknown) => unknown,
      arg: unknown,
    ) {
      const watch = run(opener, arg)
      return {
        async evaluate(read: (watch: unknown) => unknown) {
          return read(watch)
        },
        async dispose() {},
      }
    },
  } as unknown as Locator
  const watch = await watchTableContinuity(trigger, ['selection'])
  cleanups.push(() => watch.dispose())
  return {
    watch,
    root,
    opener,
    update: (change: Partial<TableSearchApplication>) => {
      current = { ...current, ...change }
    },
  }
}

describe('native control continuity', () => {
  it('keeps the actual native handles and SDK occurrence through an ordinary body update', async () => {
    const b = await browser()
    b.root.querySelector('span')!.textContent = 'changed source body'
    await Promise.resolve()
    const proof = await b.watch.preserved(expect as unknown as Expect)
    expect(proof.current.application?.occurrence?.occurrence).toBe(7)
    expect(proof.samples.mutation).toBeGreaterThan(0)
    expect(proof.current.focused).toBe(true)
  })

  it('retains a transient focus loss even after the same control regains focus', async () => {
    const b = await browser()
    b.opener.blur()
    b.opener.focus()
    await expect(
      b.watch.preserved(expect as unknown as Expect),
    ).rejects.toThrow()
    expect((await b.watch.read()).current.focused).toBe(true)
  })

  it('detects removal and reinsertion within one mutation batch', async () => {
    const b = await browser()
    const selection = b.root.querySelector('span')!
    const parent = selection.parentNode!
    selection.remove()
    parent.appendChild(selection)
    await Promise.resolve()
    await expect(
      b.watch.preserved(expect as unknown as Expect),
    ).rejects.toThrow()
    expect((await b.watch.read()).violations).toContainEqual(
      expect.objectContaining({ reason: 'held native node removed' }),
    )
  })

  it('rejects a controller occurrence change with the same row key and DOM handle', async () => {
    const b = await browser()
    b.update({
      occurrences: [
        {
          rowKey: 'same',
          occurrence: 8,
          expanded: true,
          selected: false,
          placeholder: false,
        },
      ],
    })
    await expect(
      b.watch.preserved(expect as unknown as Expect),
    ).rejects.toThrow()
    expect((await b.watch.read()).current.sameTrigger).toBe(true)
  })

  it('rejects a held descriptor or controller epoch change on a data-only update', async () => {
    const b = await browser()
    b.update({ appliedRequestId: null })
    await expect(
      b.watch.preserved(expect as unknown as Expect),
    ).rejects.toThrow()
  })

  it('requires a fresh actual node and occurrence at an intentional window boundary', async () => {
    const b = await browser()
    await b.watch.preserved(expect as unknown as Expect)
    await expect(b.watch.fresh(expect as unknown as Expect)).rejects.toThrow()
    const oldRow = b.opener.closest('tr')!
    oldRow.replaceWith(oldRow.cloneNode(true))
    b.update({
      occurrences: [
        {
          rowKey: 'same',
          occurrence: 8,
          expanded: false,
          selected: false,
          placeholder: false,
        },
      ],
    })
    const proof = await b.watch.fresh(expect as unknown as Expect)
    expect(proof.current.openerConnected).toBe(false)
    expect(proof.current.application?.occurrence?.occurrence).toBe(8)
  })
})
