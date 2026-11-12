import { act } from '@testing-library/react'
import { createRoot, type Root } from 'react-dom/client'
import { describe, expect, it } from 'vitest'
import { HilosPages, resolveHilosPath } from '@hilos/core/base'
import {
  registerBreadcrumbCases,
  type BreadcrumbInput,
  type BreadcrumbView,
} from '../../test-support/breadcrumbCases.js'
import { HilosBreadcrumb } from '../src/HilosBreadcrumb.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** Mount the real breadcrumb and release its signal bridge before its host. */
async function mountBreadcrumb(
  { crumbs, label, router }: BreadcrumbInput,
  className?: string,
): Promise<BreadcrumbView> {
  const host = document.body.appendChild(document.createElement('div'))
  let root: Root | undefined
  try {
    root = createRoot(host)
    const breadcrumb = (
      <HilosBreadcrumb crumbs={crumbs} label={label} className={className} />
    )
    act(() =>
      root!.render(
        router === undefined ? (
          breadcrumb
        ) : (
          <HilosRouterContext.Provider value={router}>
            {breadcrumb}
          </HilosRouterContext.Provider>
        ),
      ),
    )
    return {
      root: host,
      async unmount() {
        try {
          act(() => root!.unmount())
        } finally {
          host.remove()
        }
      },
    }
  } catch (error) {
    try {
      act(() => root?.unmount())
    } finally {
      host.remove()
    }
    throw error
  }
}

registerBreadcrumbCases('React', { describe, it, expect }, mountBreadcrumb)

// className is a React-only caller API, separate from shared trail behavior.
describe('HilosBreadcrumb React API', () => {
  it('passes caller classes to the navigation element', async () => {
    const view = await mountBreadcrumb(
      {
        crumbs: [
          {
            page: HilosPages.DASHBOARD,
            label: 'Dashboard',
            to: resolveHilosPath(HilosPages.DASHBOARD),
          },
        ],
      },
      'caller-marker',
    )
    try {
      const nav = view.root.querySelector('nav[data-id="hilos-breadcrumb"]')
      expect(nav).not.toBeNull()
      expect(nav?.className).toBe('caller-marker')
      expect(nav?.classList.contains('hilos-breadcrumb')).toBe(false)
    } finally {
      await view.unmount()
    }
  })
})
