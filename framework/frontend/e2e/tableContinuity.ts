import type { Expect, Locator } from '@playwright/test'
import { TABLE_SEARCH_HOOK, type TableSearchApplication } from './tableFocus.js'

/**
 * Keep actual native handles and the renderer's read-only controller identity.
 * Observe every paint, focus transition and DOM mutation, including removal and
 * reinsertion within one mutation batch. A later good frame cannot erase a loss.
 * This journal belongs to this test document, not to the application runtime.
 */
export async function watchTableContinuity(
  trigger: Locator,
  relatedIdPrefixes: readonly string[] = [],
) {
  const handle = await trigger.evaluateHandle(
    (opener, { hook, relatedIdPrefixes }) => {
      const root = opener.closest('[data-id="hilos-viewport-table"]')!
      const row = opener.closest('tr[data-id^="hilos-table-row-"]')!
      if (!root || !row) throw new Error('A live table row is required')
      const rowId = row.getAttribute('data-id')!
      const rowKey = rowId.slice('hilos-table-row-'.length)
      const id = opener.getAttribute('data-id')!
      const visible = (element: Element) => element.checkVisibility()
      const related = [...root.querySelectorAll('[data-id]')].filter(
        (node) =>
          relatedIdPrefixes.some((prefix) =>
            node.getAttribute('data-id')!.startsWith(prefix),
          ) && visible(node),
      )
      if (relatedIdPrefixes.length > 0 && related.length === 0)
        throw new Error('The retained companion must already be visible')
      const application = () => {
        const read = (
          window as unknown as Record<
            string,
            (root: Element) => { application: TableSearchApplication[] }
          >
        )[hook]!
        const applied = read(root).application
        if (applied.length !== 1) return null
        const table = applied[0]!
        return {
          controller: table.controller,
          root: table.root,
          appliedRequestId: table.appliedRequestId,
          descriptor: table.descriptor,
          search: table.search,
          filter: table.filter,
          occurrence: table.occurrences.find((item) => item.rowKey === rowKey),
        }
      }
      const read = () => {
        const current = [...root.querySelectorAll('[data-id]')].find(
          (node) => node.getAttribute('data-id') === id && visible(node),
        )
        return {
          rootConnected: root.isConnected,
          rowConnected: row.isConnected,
          openerConnected: opener.isConnected,
          sameTrigger: current === opener,
          sameRow:
            [...root.querySelectorAll('tr[data-id]')].find(
              (node) => node.getAttribute('data-id') === rowId,
            ) === row,
          visible: opener.isConnected && visible(opener),
          focused: document.activeElement === opener,
          expanded: opener.getAttribute('aria-expanded'),
          related: related.map((node) => ({
            id: node.getAttribute('data-id'),
            connected: node.isConnected,
            visible: node.isConnected && visible(node),
          })),
          application: application(),
        }
      }
      const baseline = read()
      if (!baseline.focused || !baseline.application?.occurrence)
        throw new Error('Focus and an applied row occurrence are required')
      const history: {
        at: number
        source: string
        state: ReturnType<typeof read>
      }[] = []
      const violations: { at: number; source: string; reason: string }[] = []
      const samples: Record<string, number> = {}
      let previous = ''
      let stopped = false
      let raf = 0
      const record = (source: string) => {
        samples[source] = (samples[source] ?? 0) + 1
        const state = read()
        const at = performance.now()
        const encoded = JSON.stringify(state)
        if (encoded !== previous) {
          history.push({ at, source, state })
          previous = encoded
        }
        if (JSON.stringify(state) !== JSON.stringify(baseline))
          violations.push({ at, source, reason: 'native or SDK state changed' })
      }
      const sample = () => {
        if (stopped) return
        record('paint')
        raf = requestAnimationFrame(sample)
      }
      const onFocus = () => record('focus')
      const mutations = (records: MutationRecord[]) => {
        if (records.length === 0) return
        const held = [root, row, opener, ...related]
        for (const mutation of records) {
          for (const removed of mutation.removedNodes) {
            if (held.some((node) => removed === node || removed.contains(node)))
              violations.push({
                at: performance.now(),
                source: 'mutation',
                reason: 'held native node removed',
              })
          }
        }
        record('mutation')
      }
      const observer = new MutationObserver(mutations)
      observer.observe(document.body, {
        attributes: true,
        childList: true,
        subtree: true,
      })
      document.addEventListener('focusin', onFocus)
      document.addEventListener('focusout', onFocus)
      record('start')
      raf = requestAnimationFrame(sample)
      const stop = () => {
        if (stopped) return
        mutations(observer.takeRecords())
        record('stop')
        stopped = true
        cancelAnimationFrame(raf)
        observer.disconnect()
        document.removeEventListener('focusin', onFocus)
        document.removeEventListener('focusout', onFocus)
      }
      return {
        read: () => ({
          baseline,
          current: read(),
          history,
          violations,
          samples,
        }),
        stop,
      }
    },
    { hook: TABLE_SEARCH_HOOK, relatedIdPrefixes },
  )
  return {
    read: () => handle.evaluate((watch) => watch.read()),
    /** End this ordinary-update interval before the next intentional user action. */
    async preserved(expect: Expect) {
      await handle.evaluate((watch) => watch.stop())
      const evidence = await handle.evaluate((watch) => watch.read())
      expect(evidence.violations, JSON.stringify(evidence)).toEqual([])
      expect(evidence.current).toEqual(evidence.baseline)
      return evidence
    },
    /** An intentional, settled window must mint a new occurrence and native node. */
    async fresh(expect: Expect) {
      const evidence = await handle.evaluate((watch) => watch.read())
      const { baseline, current } = evidence
      expect(current.rootConnected).toBe(true)
      expect(current.openerConnected).toBe(false)
      expect(current.rowConnected).toBe(false)
      expect(current.sameTrigger).toBe(false)
      expect(current.application?.controller).toBe(
        baseline.application?.controller,
      )
      expect(current.application?.root).toBe(baseline.application?.root)
      expect(current.application?.occurrence?.rowKey).toBe(
        baseline.application?.occurrence?.rowKey,
      )
      expect(typeof current.application?.occurrence?.occurrence).toBe('number')
      expect(current.application?.occurrence?.occurrence).not.toBe(
        baseline.application?.occurrence?.occurrence,
      )
      return evidence
    },
    async dispose() {
      await handle.evaluate((watch) => watch.stop())
      await handle.dispose()
    },
  }
}
