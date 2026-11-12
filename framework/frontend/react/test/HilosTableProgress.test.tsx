import { act } from '@testing-library/react'
import { createRoot, type Root } from 'react-dom/client'
import { describe, expect, it } from 'vitest'
import {
  registerTableProgressCases,
  type TableProgressInput,
  type TableProgressView,
} from '../../test-support/tableProgressCases.js'
import { HilosTableProgress } from '../src/HilosTableProgress.js'

/** Retain the native root before React first renders the real component. */
async function mountProgress(
  input: TableProgressInput,
): Promise<TableProgressView> {
  const host = document.createElement('div')
  let root: Root | undefined
  async function unmount(): Promise<void> {
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
      throw new AggregateError(errors, 'React progress cleanup failed')
  }
  try {
    document.body.appendChild(host)
    root = createRoot(host)
    await act(async () => {
      root!.render(<HilosTableProgress {...input} />)
    })
    return { root: host, unmount }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'React progress setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableProgressCases('React', { describe, it, expect }, mountProgress)
