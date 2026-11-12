import { describe, expect, it, vi } from 'vitest'
import { act, fireEvent } from '@testing-library/react'
import { createRoot, type Root } from 'react-dom/client'
import type {
  TableBarInput,
  TableBarView,
} from '../../test-support/tableBarFixtures.js'
import { registerTableBarBasicCases } from '../../test-support/tableBarBasicCases.js'
import { registerTableBarListActionCases } from '../../test-support/tableBarListActionCases.js'
import { registerTableBarOrderCases } from '../../test-support/tableBarOrderCases.js'
import { registerTableBarSelectionCases } from '../../test-support/tableBarSelectionCases.js'
import { registerTableBarViewCases } from '../../test-support/tableBarViewCases.js'

import { HilosTableBar } from '../src/HilosTableBar.js'

/** Retain the native root before React first renders the real Bar. */
async function mountTableBar(input: TableBarInput): Promise<TableBarView> {
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
      throw new AggregateError(errors, 'React table bar cleanup failed', {
        cause: errors[0],
      })
  }
  try {
    document.body.appendChild(host)
    root = createRoot(host)
    await act(async () => {
      root!.render(
        <HilosTableBar controller={input.controller} titleId={input.titleId} />,
      )
    })
    return {
      root: host,
      unmount,
      async change(action) {
        await act(async () => action())
      },
      async type(field, value) {
        await act(async () => {
          fireEvent.input(field, { target: { value } })
        })
      },
    }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'React table bar setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableBarBasicCases('React', { describe, it, expect }, mountTableBar)
registerTableBarListActionCases(
  'React',
  { describe, it, expect, vi },
  mountTableBar,
)
registerTableBarOrderCases('React', { describe, it, expect }, mountTableBar)
registerTableBarViewCases('React', { describe, it, expect, vi }, mountTableBar)
registerTableBarSelectionCases('React', { describe, it, expect }, mountTableBar)
