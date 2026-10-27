import type { Locator } from '@playwright/test'

/**
 * Record one upload's browser interval, from a nonempty native file selection
 * to its sole new chip first reading received. Both marks use this document's
 * clock; reading the result never moves them. Dispose in the caller's finally.
 */
export async function watchUploadReady(input: Locator, fileDataId: string) {
  const handle = await input.evaluateHandle((element, fileDataId) => {
    if (!(element instanceof HTMLInputElement) || element.type !== 'file')
      throw new Error('A native file input is required')

    let startedAt: number | null = null
    let receivedAt: number | null = null
    let stopped = false
    const existing = new Set<Element>()
    const chips = () =>
      [...document.querySelectorAll('[data-id]')].filter(
        (node) => node.getAttribute('data-id') === fileDataId,
      )
    const onChange = () => {
      if (stopped || startedAt !== null || !element.files?.length) return
      startedAt = performance.now()
      for (const chip of chips()) existing.add(chip)
    }
    const observer = new MutationObserver(() => {
      if (stopped || startedAt === null || receivedAt !== null) return
      const current = chips()
      if (
        current.length === 1 &&
        !existing.has(current[0]!) &&
        current[0]!.getAttribute('data-state') === 'received'
      )
        receivedAt = performance.now()
    })
    element.addEventListener('change', onChange, true)
    observer.observe(document, {
      childList: true,
      attributes: true,
      attributeFilter: ['data-state'],
      subtree: true,
    })
    return {
      read: () => ({
        startedAt,
        receivedAt,
        elapsedMs:
          startedAt === null || receivedAt === null
            ? null
            : receivedAt - startedAt,
      }),
      stop: () => {
        stopped = true
        element.removeEventListener('change', onChange, true)
        observer.disconnect()
      },
    }
  }, fileDataId)

  let disposed = false
  return {
    read: () => handle.evaluate((watch) => watch.read()),
    async dispose() {
      if (disposed) return
      disposed = true
      try {
        await handle.evaluate((watch) => watch.stop())
      } finally {
        await handle.dispose()
      }
    },
  }
}
