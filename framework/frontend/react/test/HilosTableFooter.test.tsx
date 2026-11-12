import { act } from '@testing-library/react'
import { createRoot, type Root } from 'react-dom/client'
import { describe, expect, it } from 'vitest'
import {
  registerTableFooterCases,
  type TableFooterInput,
  type TableFooterView,
} from '../../test-support/tableFooterCases.js'
import { HilosTableFooter } from '../src/HilosTableFooter.js'

/** Retain the native root before React first renders the real component. */
async function mountFooter(input: TableFooterInput): Promise<TableFooterView> {
  const host = document.createElement('div')
  let root: Root | undefined
  let released = false
  async function unmount(): Promise<void> {
    if (released) return
    released = true
    const errors: unknown[] = []
    for (const release of [
      () =>
        act(async () => {
          root?.unmount()
        }),
      () => host.remove(),
    ]) {
      try {
        await release()
      } catch (error) {
        errors.push(error)
      }
    }
    if (errors.length === 1) throw errors[0]
    if (errors.length > 1)
      throw new AggregateError(errors, 'React table footer cleanup failed')
  }
  try {
    document.body.appendChild(host)
    root = createRoot(host)
    await act(async () => {
      root!.render(<HilosTableFooter {...input} />)
    })
    return {
      root: host,
      unmount,
      async change(action) {
        await act(async () => action())
      },
    }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'React table footer setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableFooterCases('React', { describe, it, expect }, mountFooter)
