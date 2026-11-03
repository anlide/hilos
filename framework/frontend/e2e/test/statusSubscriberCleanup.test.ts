import { EventEmitter } from 'node:events'
import type { Expect, Page } from '@playwright/test'
import { describe, expect, it } from 'vitest'
import { removeStatusSubscribersIfAny } from '../statusSubscriptions.js'

describe('reserved Status subscriber cleanup', () => {
  for (const ownExists of [false, true]) {
    it(`preserves a substring neighbor when the exact subject ${ownExists ? 'exists' : 'was never created'}`, async () => {
      const email = 'reader@example.net'
      const neighbor = { id: 8, email: `prefix${email}` }
      const rows = ownExists ? [{ id: 7, email }, neighbor] : [neighbor]
      const removed: number[] = []
      const peer = new EventEmitter()
      const socket = new EventEmitter()
      let closed = false
      let target: number | undefined
      const send = (wire: unknown) =>
        socket.emit('framesent', { payload: JSON.stringify(wire) })
      const receive = (wire: unknown) =>
        socket.emit('framereceived', { payload: JSON.stringify(wire) })
      const locator = (id: string | RegExp, selected = rows) => ({
        id,
        selected,
        filter: ({ hasText }: { hasText?: string }): unknown =>
          locator(
            id,
            hasText
              ? selected.filter((row) => row.email.includes(hasText))
              : selected,
          ),
        getAttribute: async () =>
          `hilos-status-subscriber-email-${selected[0].id}`,
        fill: async () => {},
        pressSequentially: async (search: string) => {
          const request = {
            type: 'table_viewport',
            page: 'hilos_status_subscribers',
            tableKey: 'hilosStatusSubscribers',
            requestId: 'search-1',
            filter: { search },
          }
          send(request)
          receive({
            type: 'table_window',
            data: {
              ...request,
              rows: rows
                .filter((row) => row.email.includes(search))
                .map((subscriber) => ({
                  rowKey: subscriber.id,
                  slots: { subscriber },
                })),
              totalCount: rows.length,
              totalExact: true,
            },
          })
        },
        scrollIntoViewIfNeeded: async () => {},
        focus: async () => {},
        click: async () => {
          if (id === 'hilos-status-subscriber-remove-confirm') {
            const action = 'hilos_status_subscriber_remove'
            send({
              type: 'action',
              action,
              requestId: 'remove-1',
              data: { subscriberId: target },
            })
            receive({
              type: 'action_success',
              requestId: 'remove-1',
              data: { action, message: 'Subscriber removed.' },
            })
            removed.push(target!)
            rows.splice(
              rows.findIndex((row) => row.id === target),
              1,
            )
          } else {
            target = Number(
              String(id).replace('hilos-status-subscriber-remove-', ''),
            )
          }
        },
      })
      // The helper opens its own tab in the admin's context and watches it:
      // the tab's socket opens on the first navigation.
      const page = Object.assign(peer, {
        goto: async () => {
          if (peer.listenerCount('websocket') > 0)
            peer.emit('websocket', socket)
        },
        getByTestId: (id: string | RegExp) => locator(id),
        close: async () => {
          closed = true
        },
      }) as unknown as Page
      const admin = {
        context: () => ({ newPage: async () => page }),
      } as unknown as Page
      // Evaluate the real helper's predicates once, without a browser timeout.
      const check = Object.assign(
        (value: unknown) => {
          if (typeof value === 'function')
            return { toPass: async () => value() }
          if (value !== null && typeof value === 'object' && 'id' in value) {
            const cell = value as ReturnType<typeof locator>
            return {
              toBeVisible() {},
              toBeEnabled() {},
              toBeHidden() {},
              toHaveAttribute() {},
              toContainText: (text: string) =>
                expect(`#${target}`).toContain(text),
              toHaveCount: (count: number) =>
                expect(
                  cell.selected.filter(
                    (row) =>
                      cell.id instanceof RegExp ||
                      cell.id === `hilos-status-subscriber-email-${row.id}`,
                  ),
                ).toHaveLength(count),
            }
          }
          return expect(value)
        },
        { poll: (read: () => unknown) => expect(read()) },
      ) as unknown as Expect
      await removeStatusSubscribersIfAny(check, admin, [email])
      expect(removed).toEqual(ownExists ? [7] : [])
      expect(rows).toEqual([neighbor])
      expect(closed).toBe(true)
    })
  }
})
