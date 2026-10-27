// @vitest-environment happy-dom
import type { Locator } from '@playwright/test'
import type { Window } from 'happy-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { watchUploadReady } from '../uploadTiming.js'

type Timing = Awaited<
  ReturnType<Awaited<ReturnType<typeof watchUploadReady>>['read']>
>
type BrowserJournal = { read: () => Timing; stop: () => void }

const cleanups: (() => Promise<void>)[] = []
afterEach(async () => {
  for (const cleanup of cleanups.splice(0)) await cleanup()
  document.body.replaceChildren()
  vi.restoreAllMocks()
})

/** Drain DOM delivery without using wall time for either timing mark. */
async function flushMutations() {
  await (window as unknown as Window).happyDOM.whenAsyncComplete()
}

async function browser(clearAtTarget = false) {
  let now = 0
  vi.spyOn(performance, 'now').mockImplementation(() => now)
  const input = document.createElement('input')
  input.type = 'file'
  document.body.append(input)
  const target = vi.fn(() => {
    if (clearAtTarget) input.value = ''
  })
  input.addEventListener('change', target)

  let journal: BrowserJournal
  const evaluate = vi.fn(async (read: (watch: BrowserJournal) => unknown) =>
    read(journal),
  )
  const dispose = vi.fn(async () => {})
  const locator = {
    async evaluateHandle(
      run: (input: HTMLInputElement, arg: string) => BrowserJournal,
      arg: string,
    ) {
      journal = run(input, arg)
      return { evaluate, dispose }
    },
  } as unknown as Locator
  const watch = await watchUploadReady(locator, 'upload-chip')
  cleanups.push(async () => {
    try {
      await watch.dispose()
    } finally {
      journal.stop()
    }
  })
  return {
    watch,
    input,
    target,
    evaluate,
    dispose,
    journalRead: () => journal.read(),
    at: (value: number) => {
      now = value
    },
    select: () => {
      const transfer = new DataTransfer()
      transfer.items.add(new File(['bytes'], 'selected.txt'))
      input.files = transfer.files
      input.dispatchEvent(new Event('change', { bubbles: true }))
    },
  }
}

function chip(state: string, dataId = 'upload-chip') {
  const element = document.createElement('span')
  element.dataset.id = dataId
  element.dataset.state = state
  document.body.append(element)
  return element
}

describe('upload ready browser timing', () => {
  it('excludes time before selection and freezes received before a late read', async () => {
    const b = await browser()
    b.at(10_000)
    b.select()
    const file = chip('uploading')
    await flushMutations()
    b.at(10_375)
    file.dataset.state = 'received'
    await flushMutations()

    b.at(20_000)
    expect(await b.watch.read()).toEqual({
      startedAt: 10_000,
      receivedAt: 10_375,
      elapsedMs: 375,
    })
    file.dataset.state = 'failed'
    await flushMutations()
    expect(await b.watch.read()).toEqual({
      startedAt: 10_000,
      receivedAt: 10_375,
      elapsedMs: 375,
    })
  })

  it('captures selection before an earlier target handler clears the input', async () => {
    const b = await browser(true)
    b.at(42)
    b.select()
    expect(b.target).toHaveBeenCalledOnce()
    expect(b.input.files?.length).toBe(0)
    expect(await b.watch.read()).toEqual({
      startedAt: 42,
      receivedAt: null,
      elapsedMs: null,
    })
  })

  it('records a chip inserted already received, including a valid zero interval', async () => {
    const b = await browser()
    b.select()
    chip('received')
    await flushMutations()
    expect(await b.watch.read()).toEqual({
      startedAt: 0,
      receivedAt: 0,
      elapsedMs: 0,
    })
  })

  it('keeps absent selection and absent receipt nullable instead of succeeding at zero', async () => {
    const b = await browser()
    b.input.dispatchEvent(new Event('change', { bubbles: true }))
    const old = chip('received')
    await flushMutations()
    expect(await b.watch.read()).toEqual({
      startedAt: null,
      receivedAt: null,
      elapsedMs: null,
    })
    old.remove()
    b.at(50)
    b.select()
    chip('uploading')
    await flushMutations()
    b.at(1000)
    expect(await b.watch.read()).toEqual({
      startedAt: 50,
      receivedAt: null,
      elapsedMs: null,
    })
  })

  it('ignores an old received chip and its queued mutations across selection', async () => {
    const b = await browser()
    const old = chip('received')
    b.at(100)
    b.select()
    await flushMutations()
    old.remove()
    document.body.append(old)
    await flushMutations()
    expect((await b.watch.read()).receivedAt).toBeNull()

    old.remove()
    b.at(250)
    chip('received')
    await flushMutations()
    expect((await b.watch.read()).elapsedMs).toBe(150)
  })

  it('requires the sole matching chip, ignoring another consumer and ambiguous chips', async () => {
    const b = await browser()
    b.select()
    chip('received', 'other-upload-chip')
    await flushMutations()
    expect((await b.watch.read()).receivedAt).toBeNull()
    chip('received')
    const second = chip('uploading')
    await flushMutations()
    expect((await b.watch.read()).receivedAt).toBeNull()
    b.at(75)
    second.remove()
    await flushMutations()
    expect((await b.watch.read()).elapsedMs).toBe(75)
  })

  it('never restarts the first interval on another selection or later receipt', async () => {
    const b = await browser()
    b.at(10)
    b.select()
    b.at(50)
    b.select()
    const file = chip('received')
    await flushMutations()
    b.at(100)
    b.select()
    file.remove()
    chip('received')
    await flushMutations()
    expect(await b.watch.read()).toEqual({
      startedAt: 10,
      receivedAt: 50,
      elapsedMs: 40,
    })
  })

  it('disposes before selection without leaving the listener or observer recording', async () => {
    const b = await browser()
    await b.watch.dispose()
    b.select()
    chip('received')
    await flushMutations()
    expect(b.journalRead()).toEqual({
      startedAt: null,
      receivedAt: null,
      elapsedMs: null,
    })
    await b.watch.dispose()
    expect(b.dispose).toHaveBeenCalledOnce()
  })

  it('discards pending mutations at disposal and preserves the incomplete journal', async () => {
    const b = await browser()
    b.at(10)
    b.select()
    chip('received')
    await b.watch.dispose()
    b.at(100)
    b.select()
    chip('received')
    await flushMutations()
    expect(b.journalRead()).toEqual({
      startedAt: 10,
      receivedAt: null,
      elapsedMs: null,
    })
    expect(b.dispose).toHaveBeenCalledOnce()
  })

  it('releases the handle even when stopping the browser observer fails', async () => {
    const b = await browser()
    b.evaluate.mockRejectedValueOnce(new Error('Document closed'))
    await expect(b.watch.dispose()).rejects.toThrow('Document closed')
    expect(b.dispose).toHaveBeenCalledOnce()
  })
})
