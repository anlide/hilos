import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import type { RenderResult } from '@testing-library/react'

import { HilosPageHead } from '../src/HilosPageHead.js'
import type { HilosPageHeadProps } from '../src/HilosPageHead.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

import {
  SECTION,
  resolvePath,
  type PageHeadInput,
} from '../../test-support/pageHeadFixtures.js'
import {
  registerPageHeadBlockCases,
  type PageHeadView,
} from '../../test-support/pageHeadBlockCases.js'
import { registerPageHeadNavigationCases } from '../../test-support/pageHeadNavigationCases.js'
import { registerPageHeadCenteringCases } from '../../test-support/pageHeadCenteringCases.js'

/** A mounted head and a way to hand it new props over the ones it started with. */
interface MountedHead extends RenderResult {
  update(next: Partial<HilosPageHeadProps>): void
}

/**
 * Mount a head over the section by default, optionally into a container
 * that is not in the document.
 *
 * @param props The props laid over the section defaults.
 * @param options The container to render into.
 */
function mountHead(
  props: Partial<HilosPageHeadProps> = {},
  options: { container?: HTMLElement } = {},
): MountedHead {
  let current = props
  const ui = () => {
    const head = (
      <HilosPageHead
        identity={SECTION}
        page="demo_section"
        params={{}}
        resolvePath={resolvePath}
        {...current}
      />
    )

    return head
  }
  const result = render(ui(), { container: options.container })

  return {
    ...result,
    update(next) {
      current = { ...current, ...next }
      result.rerender(ui())
    },
  }
}

/**
 * @param head The mounted head.
 * @param selector The CSS selector to look for inside it.
 */
function find(head: RenderResult, selector: string): HTMLElement | null {
  return head.container.querySelector<HTMLElement>(selector)
}

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

/** Translate caller content to ReactNodes on the real component. */
async function mountBlock(
  input: PageHeadInput,
  copies: 1 | 2 = 1,
): Promise<PageHeadView> {
  let current = input
  const ui = () => {
    const { content, router, ...props } = current
    const nodes = Object.fromEntries(
      Object.entries(content ?? {}).map(([name, part]) => [
        name,
        name === 'description' ? (
          <p data-id={part.dataId}>{part.text}</p>
        ) : (
          <span data-id={part.dataId}>{part.text}</span>
        ),
      ]),
    )
    const heads = Array.from({ length: copies }, (_, key) => (
      <HilosPageHead key={key} {...props} {...nodes} />
    ))
    return router === undefined ? (
      heads
    ) : (
      <HilosRouterContext.Provider value={router}>
        {heads}
      </HilosRouterContext.Provider>
    )
  }
  const host = document.body.appendChild(document.createElement('div'))
  let head: Pick<RenderResult, 'rerender' | 'unmount'> | undefined
  try {
    head = render(ui(), { container: host })
    return {
      root: host,
      async update(next) {
        current = { ...current, ...next }
        head!.rerender(ui())
      },
      async unmount() {
        try {
          head!.unmount()
        } finally {
          host.remove()
        }
      },
    }
  } catch (error) {
    try {
      head?.unmount()
    } finally {
      host.remove()
    }
    throw error
  }
}

registerPageHeadBlockCases('React', { describe, it, expect }, mountBlock)
registerPageHeadNavigationCases(
  'React',
  { describe, it, expect, vi },
  mountBlock,
)
registerPageHeadCenteringCases(
  'React',
  { describe, it, expect, vi },
  mountBlock,
)

describe('HilosPageHead', () => {
  it('distinguishes explicit null from undefined in the ReactNode API', () => {
    const silenced = mountHead({ visual: null, description: null })
    const bare = find(silenced, '[data-id="hilos-page-head-block"]')
    expect(bare?.querySelector('i')).toBeNull()
    expect(bare?.querySelector('p')).toBeNull()
    expect(bare?.querySelector('h1')?.textContent).toBe('Demo section')
    // ReactNode-specific: null suppresses defaults, undefined restores them.
    silenced.update({ visual: undefined, description: undefined })
    expect(bare?.querySelector('i')).not.toBeNull()
    expect(bare?.querySelector('p')?.textContent).toBe(
      'Languages, countries, and translation screens.',
    )
  })
})
