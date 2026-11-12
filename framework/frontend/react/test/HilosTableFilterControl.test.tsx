import { act, fireEvent } from '@testing-library/react'
import { createRoot, type Root } from 'react-dom/client'
import { describe, expect, it } from 'vitest'
import {
  registerTableFilterControlCases,
  type TableFilterControlInput,
  type TableFilterControlView,
} from '../../test-support/tableFilterControlCases.js'
import { HilosTableFilterControl } from '../src/HilosTableFilterControl.js'

/** Retain the native root before React first renders the real component. */
async function mountFilterControl(
  input: TableFilterControlInput,
): Promise<TableFilterControlView> {
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
      throw new AggregateError(errors, 'React filter control cleanup failed')
  }
  try {
    document.body.appendChild(host)
    root = createRoot(host)
    await act(async () => {
      root!.render(<HilosTableFilterControl {...input} />)
    })
    return {
      root: host,
      unmount,
      async interact(action) {
        await act(async () => action())
      },
      async enterDate(field, value) {
        await act(async () => {
          fireEvent.change(field, { target: { value } })
        })
      },
    }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'React filter control setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableFilterControlCases(
  'React',
  { describe, it, expect },
  mountFilterControl,
)
