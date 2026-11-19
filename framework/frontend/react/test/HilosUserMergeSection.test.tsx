import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'
import { HIDDEN_VALUE } from '@hilos/core/base'
import {
  hilosUserMergeSection,
  readHilosAccountMerge,
} from '@hilos/core/admin/users'

import { HilosUserMergeSection } from '../src/admin/users/HilosUserMergeSection.js'

afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function lines(id: string): string[] {
  return Array.from(document.querySelectorAll(`[data-id="${id}"]`)).map(
    (line) => line.textContent ?? '',
  )
}

/**
 * Project actual wire progress through the core model and card projection.
 *
 * @param facts Fields that differ from a preparing merge.
 */
function section(facts: Record<string, unknown> = {}) {
  return hilosUserMergeSection(
    readHilosAccountMerge({
      operationId: 40,
      survivorUserId: 1,
      loserUserId: 74,
      state: 'preparing',
      stage: null,
      outcome: null,
      given: false,
      received: false,
      choice: null,
      refusal: null,
      parts: [],
      waits: [],
      ...facts,
    }),
    1,
  )
}

describe('HilosUserMergeSection', () => {
  describe('states and hidden outcomes', () => {
    it.each([
      ['preparing', null, null, 'Preparing merge'],
      ['wait', 'go', null, 'Merge in progress'],
      ['wait', 'cancelling', null, 'Releasing merge reservations'],
      ['refusal', null, null, 'Merge refused'],
      ['end', null, 'completed', 'Merge completed'],
      ['end', null, 'not_started', 'Merge not started'],
      ['end', null, HIDDEN_VALUE, 'Merge finished'],
    ])(
      'draws %s / %s / %s as one status sentence',
      (state, stage, outcome, expected) => {
        render(
          <HilosUserMergeSection
            section={section({ state, stage, outcome })}
          />,
        )
        expect(byId('hilos-user-merge-status')?.textContent).toBe(expected)
        expect(byId('hilos-user-merge-status-live')?.textContent).toBe(expected)
        expect(lines('hilos-user-merge-part')).toEqual([])
        expect(lines('hilos-user-merge-wait')).toEqual([])
        expect(byId('hilos-user-merge-refusal')).toBeNull()
        expect(
          byId('hilos-user-merge')?.querySelectorAll('button, input, a'),
        ).toHaveLength(0)
      },
    )
  })

  describe('parts and waits', () => {
    it('keeps the groups distinct and names only another available account', () => {
      render(
        <HilosUserMergeSection
          section={section({
            parts: [
              {
                participant: 'prepare_survivor',
                owner: 'auth',
                userId: 1,
                done: true,
              },
              { participant: 'give', owner: 'user', userId: 74, done: false },
              { participant: 'cleanup', owner: 'user', userId: 1, done: false },
              {
                participant: '<img src=x onerror=alert(1)>',
                owner: 'user',
                userId: HIDDEN_VALUE,
                done: false,
              },
            ],
            waits: [
              { participant: 'receive', userId: 74 },
              { participant: 'sessions', userId: HIDDEN_VALUE },
            ],
          })}
        />,
      )
      expect(lines('hilos-user-merge-part')).toEqual([
        'Transfer account data · #74',
        'Remove transfer copy',
        '<img src=x onerror=alert(1)>',
      ])
      expect(lines('hilos-user-merge-wait')).toEqual([
        'Accept account data · #74',
        'Close source sessions',
      ])
      expect(
        Array.from(byId('hilos-user-merge')!.querySelectorAll('h3'))
          .map((h) => h.textContent)
          .sort(),
      ).toEqual(['Unfinished parts', 'Waiting for participants'].sort())
      expect(byId('hilos-user-merge')?.querySelector('img')).toBeNull()
    })
  })

  describe('current refusal details', () => {
    it('shows exact text, updates an open panel, and closes it when cleared', () => {
      const first = '<b>The source account is already being merged.</b>'
      const second =
        'The receiving account closed before the merge could finish.'
      const refused = (reason: string) =>
        section({
          state: 'refusal',
          refusal: { code: 'occupied', reason, permanent: false },
        })
      const view = render(<HilosUserMergeSection section={refused(first)} />)
      expect(byId('hilos-user-merge-refusal')?.textContent).toBe(first)
      expect(byId('hilos-user-merge-refusal')?.querySelector('b')).toBeNull()
      expect(byId('hilos-user-merge-refusal')?.getAttribute('role')).toBeNull()
      const button = byId('hilos-user-merge-refusal-details')!
      button.focus()
      fireEvent.click(button)
      expect(
        view.getByRole('dialog', { name: "Couldn't merge the accounts" }),
      ).not.toBeNull()
      expect(document.querySelector('.modal-title')?.textContent).toBe(
        "Couldn't merge the accounts",
      )
      expect(byId('hilos-user-merge-refusal-full')?.textContent).toBe(first)
      view.rerender(<HilosUserMergeSection section={refused(second)} />)
      expect(byId('hilos-user-merge-refusal-full')?.textContent).toBe(second)
      expect(byId('hilos-user-merge-refusal')?.textContent).toBe(second)
      fireEvent.keyDown(document.querySelector('[role="dialog"]')!, {
        key: 'Escape',
      })
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(document.activeElement).toBe(button)
      fireEvent.click(button)
      view.rerender(<HilosUserMergeSection section={section()} />)
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(byId('hilos-user-merge-refusal')).toBeNull()
      expect(document.body.textContent).not.toContain(second)
    })
  })

  describe('permanent accessible frame', () => {
    it('mounts both regions and the heading before words and retains their nodes', () => {
      const view = render(<HilosUserMergeSection section={null} />)
      const heading = byId('hilos-user-merge')?.querySelector('h2')
      const status = byId('hilos-user-merge-status-live')
      const refusal = byId('hilos-user-merge-refusal-slot')
      expect(heading?.textContent).toBe('Account merge')
      expect(status?.getAttribute('role')).toBe('status')
      expect(status?.getAttribute('aria-live')).toBe('polite')
      expect(status?.textContent).toBe('')
      expect(refusal?.getAttribute('role')).toBe('alert')
      expect(refusal?.getAttribute('aria-live')).toBe('assertive')
      view.rerender(
        <HilosUserMergeSection
          section={section({
            state: 'refusal',
            refusal: {
              code: 'occupied',
              reason: 'Reservation refused',
              permanent: true,
            },
          })}
        />,
      )
      expect(byId('hilos-user-merge-status-live')).toBe(status)
      expect(byId('hilos-user-merge-refusal-slot')).toBe(refusal)
      expect(byId('hilos-user-merge')?.querySelector('h2')).toBe(heading)
      expect(status?.textContent).toBe('Merge refused')
      expect(byId('hilos-user-merge-status')?.getAttribute('role')).toBeNull()
      expect(
        byId('hilos-user-merge-status')?.getAttribute('aria-live'),
      ).toBeNull()
      expect(
        byId('hilos-user-merge')?.querySelectorAll('[role="status"]'),
      ).toHaveLength(1)
      expect(
        byId('hilos-user-merge')?.querySelectorAll('[role="alert"]'),
      ).toHaveLength(1)
    })
  })

  describe('absent section', () => {
    it('drops previous parts, waits and the open refusal without claiming an outcome', () => {
      const view = render(
        <HilosUserMergeSection
          section={section({
            state: 'refusal',
            refusal: {
              code: 'occupied',
              reason: 'The source account is reserved',
              permanent: false,
            },
            parts: [
              { participant: 'give', owner: 'user', userId: 74, done: false },
            ],
            waits: [{ participant: 'receive', userId: 74 }],
          })}
        />,
      )
      fireEvent.click(byId('hilos-user-merge-refusal-details')!)
      view.rerender(<HilosUserMergeSection section={null} />)
      expect(byId('hilos-user-merge-status')?.textContent).toBe('')
      expect(byId('hilos-user-merge-status-live')?.textContent).toBe('')
      expect(lines('hilos-user-merge-part')).toEqual([])
      expect(lines('hilos-user-merge-wait')).toEqual([])
      expect(byId('hilos-user-merge-refusal')).toBeNull()
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(document.body.textContent).not.toContain(
        'The source account is reserved',
      )
      expect(byId('hilos-user-merge')?.querySelector('h3')).toBeNull()
    })
  })
})
