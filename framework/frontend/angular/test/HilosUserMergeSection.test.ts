import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it } from 'vitest'

import type { HilosUserMergeSection as HilosUserMergeSectionState } from '@hilos/core/admin/users'

import { HilosUserMergeSection } from '../src/admin/users/HilosUserMergeSection.js'

// The details panel is a HilosModal, which teleports to <body>.
const mounted: ComponentFixture<HilosUserMergeSection>[] = []

afterEach(() => {
  for (const fixture of mounted) {
    fixture.destroy()
  }
  mounted.length = 0
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

/**
 * Mount the section. Focus tests attach it so the details button can hold
 * focus and receive it back.
 */
function mountSection(
  section: HilosUserMergeSectionState | null,
  attach = false,
): ComponentFixture<HilosUserMergeSection> {
  TestBed.resetTestingModule()
  const fixture = TestBed.createComponent(HilosUserMergeSection)
  fixture.componentRef.setInput('section', section)
  if (attach) {
    document.body.appendChild(fixture.nativeElement)
  }
  fixture.detectChanges()
  mounted.push(fixture)
  return fixture
}

/** The normalized text of every node carrying this data-id. */
function lines(fixture: ComponentFixture<unknown>, id: string): string[] {
  return Array.from(
    (fixture.nativeElement as HTMLElement).querySelectorAll(
      `[data-id="${id}"]`,
    ),
  ).map((node) => (node.textContent ?? '').replace(/\s+/g, ' ').trim())
}

/**
 * What a person sees: text outside hidden twins and outside the voice, which
 * repeats the status for announcement rather than as a second sentence.
 */
function visibleWords(fixture: ComponentFixture<unknown>): string {
  const root = (fixture.nativeElement as HTMLElement).querySelector(
    '[data-id="hilos-user-merge"]',
  )!
  const parts: string[] = []
  const walk = (node: Node): void => {
    if (!(node instanceof Element)) {
      return
    }
    if (node.getAttribute('aria-hidden') === 'true') {
      return
    }
    if (node.getAttribute('role') === 'status') {
      return
    }
    if (node.childNodes.length === 0) {
      return
    }
    for (const child of Array.from(node.childNodes)) {
      if (child.nodeType === Node.TEXT_NODE) {
        parts.push(child.textContent ?? '')
      } else {
        walk(child)
      }
    }
  }
  walk(root)
  return parts.join(' ').replace(/\s+/g, ' ').trim()
}

describe('HilosUserMergeSection', () => {
  describe('current facts', () => {
    it('shows the status, the unfinished parts and the waits', () => {
      const fixture = mountSection({
        status: 'Merge in progress',
        successful: false,
        pendingParts: [
          {
            key: '["give","survivor",74]',
            participant: 'Transfer account data',
            account: 74,
          },
        ],
        waits: [
          {
            key: '["receive",74]',
            participant: 'Accept account data',
            account: 74,
          },
        ],
        refusal: null,
      })

      const root = fixture.nativeElement as HTMLElement
      expect(root.querySelector('h2')?.textContent).toBe('Account merge')
      expect(
        root
          .querySelector('[data-id="hilos-user-merge-status"]')
          ?.textContent?.trim(),
      ).toBe('Merge in progress')
      expect(lines(fixture, 'hilos-user-merge-part')).toEqual([
        'Transfer account data · #74',
      ])
      expect(lines(fixture, 'hilos-user-merge-wait')).toEqual([
        'Accept account data · #74',
      ])
      expect(
        Array.from(root.querySelectorAll('h3')).map((h) => h.textContent),
      ).toEqual(['Unfinished parts', 'Waiting for participants'])
    })
  })

  describe('an incomplete or hidden outcome is not a success', () => {
    it('keeps an unfinished merge as its status and its remaining part', () => {
      const fixture = mountSection({
        status: 'Preparing merge',
        successful: false,
        pendingParts: [
          {
            key: '["prepare_survivor","survivor",null]',
            participant: 'Prepare receiving account',
            account: null,
          },
        ],
        waits: [],
        refusal: null,
      })

      const root = fixture.nativeElement as HTMLElement
      expect(
        root
          .querySelector('[data-id="hilos-user-merge-status"]')
          ?.textContent?.trim(),
      ).toBe('Preparing merge')
      expect(lines(fixture, 'hilos-user-merge-part')).toEqual([
        'Prepare receiving account',
      ])
      expect(root.querySelector('[data-id="hilos-user-merge-wait"]')).toBeNull()
      expect(visibleWords(fixture)).not.toContain('Merge completed')
    })

    it('does not turn an empty list or a hidden account into success or into nothing happening', () => {
      const fixture = mountSection({
        status: 'Merge finished',
        successful: false,
        pendingParts: [],
        waits: [
          {
            key: '["sessions",null]',
            participant: 'Close source sessions',
            account: null,
          },
        ],
        refusal: null,
      })

      const root = fixture.nativeElement as HTMLElement
      expect(
        root
          .querySelector('[data-id="hilos-user-merge-status"]')
          ?.textContent?.trim(),
      ).toBe('Merge finished')
      expect(lines(fixture, 'hilos-user-merge-wait')).toEqual([
        'Close source sessions',
      ])
      expect(root.querySelector('[data-id="hilos-user-merge-part"]')).toBeNull()
      expect(
        Array.from(root.querySelectorAll('h3')).map((h) => h.textContent),
      ).toEqual(['Waiting for participants'])
      const words = visibleWords(fixture)
      expect(words).not.toContain('Merge completed')
      expect(words.toLowerCase()).not.toContain('nothing')
    })

    it('draws the frame and no claim while the section is absent', () => {
      const fixture = mountSection(null)

      const root = fixture.nativeElement as HTMLElement
      expect(root.querySelector('h2')?.textContent).toBe('Account merge')
      expect(
        root
          .querySelector('[data-id="hilos-user-merge-status"]')
          ?.textContent?.trim(),
      ).toBe('')
      expect(
        root
          .querySelector('[data-id="hilos-user-merge-status-live"]')
          ?.textContent?.trim(),
      ).toBe('')
      expect(root.querySelector('[data-id="hilos-user-merge-part"]')).toBeNull()
      expect(root.querySelector('[data-id="hilos-user-merge-wait"]')).toBeNull()
      const words = visibleWords(fixture)
      expect(words).toBe('Account merge')
      expect(words.toLowerCase()).not.toContain('nothing')
    })
  })

  describe('refusal details stay current and close when cleared', () => {
    const first = 'The source account is already being merged.'
    const second = 'The receiving account closed before the merge could finish.'

    function refused(reason: string | null): HilosUserMergeSectionState {
      return {
        status: reason === null ? 'Preparing merge' : 'Merge refused',
        successful: false,
        pendingParts: [
          {
            key: '["give","survivor",8]',
            participant: 'Transfer account data',
            account: 8,
          },
        ],
        waits: [
          {
            key: '["receive",8]',
            participant: 'Accept account data',
            account: 8,
          },
        ],
        refusal: reason,
      }
    }

    it('opens the whole current reason and drops the panel when the refusal clears', async () => {
      const fixture = mountSection(refused(first))
      const root = fixture.nativeElement as HTMLElement

      expect(lines(fixture, 'hilos-user-merge-part')).toEqual([
        'Transfer account data · #8',
      ])
      expect(lines(fixture, 'hilos-user-merge-wait')).toEqual([
        'Accept account data · #8',
      ])
      const row = root.querySelector<HTMLElement>(
        '[data-id="hilos-user-merge-refusal"]',
      )!
      expect(row.textContent).toContain(first)
      expect(row.getAttribute('role')).toBeNull()
      expect(row.getAttribute('aria-live')).toBeNull()
      expect(
        root.querySelector('[data-id="hilos-user-merge-refusal-type"]'),
      ).toBeNull()

      root
        .querySelector<HTMLButtonElement>(
          '[data-id="hilos-user-merge-refusal-details"]',
        )
        ?.click()
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()

      expect(document.querySelector('.modal-title')?.textContent).toBe(
        "Couldn't merge the accounts",
      )
      expect(
        document
          .querySelector('[data-id="hilos-user-merge-refusal-full"]')
          ?.textContent?.trim(),
      ).toBe(first)
      expect(
        document.querySelector('[data-id="hilos-user-merge-refusal-detail"]'),
      ).toBeNull()

      fixture.componentRef.setInput('section', refused(second))
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()

      expect(document.querySelector('[role="dialog"]')).not.toBeNull()
      expect(
        document
          .querySelector('[data-id="hilos-user-merge-refusal-full"]')
          ?.textContent?.trim(),
      ).toBe(second)

      fixture.componentRef.setInput('section', refused(null))
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()

      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(
        root.querySelector('[data-id="hilos-user-merge-refusal"]'),
      ).toBeNull()
      expect(
        root.querySelector('[data-id="hilos-user-merge-refusal-idle"]'),
      ).not.toBeNull()
      expect(document.body.textContent?.includes(second)).toBe(false)
    })
  })

  describe('viewer-safe data', () => {
    it('shows a participant as text and hides an account the section left unnamed', () => {
      const fixture = mountSection({
        status: 'Merge in progress',
        successful: false,
        pendingParts: [
          {
            key: '["give","survivor",null]',
            participant: '<img src=x onerror=alert(1)>',
            account: null,
          },
        ],
        waits: [
          {
            key: '["sessions",null]',
            participant: 'Close source sessions',
            account: null,
          },
        ],
        refusal: null,
      })

      const root = fixture.nativeElement as HTMLElement
      const part = root.querySelector<HTMLElement>(
        '[data-id="hilos-user-merge-part"]',
      )!
      expect(part.textContent?.trim()).toBe('<img src=x onerror=alert(1)>')
      expect(part.querySelector('img')).toBeNull()
      expect(part.textContent).not.toMatch(/#\d/)
      expect(
        root
          .querySelector('[data-id="hilos-user-merge-wait"]')
          ?.textContent?.trim(),
      ).toBe('Close source sessions')
      expect(root.querySelector('a')).toBeNull()
      expect(root.querySelector('button')).toBeNull()
      expect(root.querySelector('input')).toBeNull()
    })

    it('offers no start, cancel, fate or proof control beside the refusal', () => {
      const fixture = mountSection({
        status: 'Merge refused',
        successful: false,
        pendingParts: [],
        waits: [],
        refusal: 'Refused by the server.',
      })

      const root = fixture.nativeElement as HTMLElement
      const buttons = Array.from(root.querySelectorAll('button')).map(
        (button) => ({
          text: button.textContent?.trim() ?? '',
          name: button.getAttribute('aria-label') ?? '',
        }),
      )
      expect(buttons).toEqual([{ text: '', name: 'Show error details' }])
    })
  })

  describe('permanent live regions, heading and keyboard details', () => {
    it('keeps the heading and both regions before there is anything to say', () => {
      const fixture = mountSection(null)
      const root = fixture.nativeElement as HTMLElement

      expect(root.querySelector('h2')?.textContent).toBe('Account merge')
      const live = root.querySelector<HTMLElement>(
        '[data-id="hilos-user-merge-status-live"]',
      )!
      expect(live.getAttribute('role')).toBe('status')
      expect(live.getAttribute('aria-live')).toBe('polite')
      expect(live.textContent?.trim()).toBe('')
      const slot = root.querySelector<HTMLElement>(
        '[data-id="hilos-user-merge-refusal-slot"]',
      )!
      expect(slot.getAttribute('role')).toBe('alert')
      expect(slot.getAttribute('aria-live')).toBe('assertive')
      const status = root.querySelector<HTMLElement>(
        '[data-id="hilos-user-merge-status"]',
      )!
      expect(status.getAttribute('role')).toBeNull()
      expect(status.getAttribute('aria-live')).toBeNull()
      expect(status.textContent?.trim()).toBe('')
      expect(root.querySelectorAll('[role="status"]')).toHaveLength(1)
      expect(root.querySelectorAll('[role="alert"]')).toHaveLength(1)
      expect(
        Array.from(root.querySelectorAll('[aria-hidden="true"]')).some(
          (node) => node.textContent?.trim() === 'Releasing merge reservations',
        ),
      ).toBe(true)
    })

    it('keeps the same regions when the words arrive and leaves the visible line without a role', () => {
      const fixture = mountSection(null)
      const root = fixture.nativeElement as HTMLElement
      const live = root.querySelector(
        '[data-id="hilos-user-merge-status-live"]',
      )!
      const slot = root.querySelector(
        '[data-id="hilos-user-merge-refusal-slot"]',
      )!

      fixture.componentRef.setInput('section', {
        status: 'Releasing merge reservations',
        successful: false,
        pendingParts: [],
        waits: [],
        refusal: 'Could not release the reservation.',
      })
      fixture.detectChanges()

      expect(
        root.querySelector('[data-id="hilos-user-merge-status-live"]'),
      ).toBe(live)
      expect(
        root.querySelector('[data-id="hilos-user-merge-refusal-slot"]'),
      ).toBe(slot)
      expect(live.textContent?.trim()).toBe('Releasing merge reservations')
      const status = root.querySelector<HTMLElement>(
        '[data-id="hilos-user-merge-status"]',
      )!
      expect(status.textContent?.trim()).toBe('Releasing merge reservations')
      expect(status.getAttribute('role')).toBeNull()
      expect(status.getAttribute('aria-live')).toBeNull()
      const row = root.querySelector<HTMLElement>(
        '[data-id="hilos-user-merge-refusal"]',
      )!
      expect(row.getAttribute('role')).toBeNull()
      expect(row.getAttribute('aria-live')).toBeNull()
      expect(root.querySelectorAll('[role="status"]')).toHaveLength(1)
      expect(root.querySelectorAll('[role="alert"]')).toHaveLength(1)
    })

    it('closes the details from the keyboard and returns focus to the button', async () => {
      const fixture = mountSection(
        {
          status: 'Merge refused',
          successful: false,
          pendingParts: [],
          waits: [],
          refusal: 'The source account is already being merged.',
        },
        true,
      )
      const root = fixture.nativeElement as HTMLElement
      const button = root.querySelector<HTMLButtonElement>(
        '[data-id="hilos-user-merge-refusal-details"]',
      )!
      button.focus()
      button.click()
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()

      const dialog = document.querySelector<HTMLElement>('[role="dialog"]')
      expect(dialog).not.toBeNull()
      expect(
        document
          .querySelector('[data-id="hilos-user-merge-refusal-full"]')
          ?.textContent?.trim(),
      ).toBe('The source account is already being merged.')

      dialog?.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()

      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(document.activeElement).toBe(button)
    })
  })

  describe('a cleared section leaves no previous operation and sends nothing', () => {
    it('drops the previous facts and the open refusal without emitting', async () => {
      const fixture = mountSection(
        {
          status: 'Merge in progress',
          successful: false,
          pendingParts: [
            {
              key: '["give","survivor",74]',
              participant: 'Transfer account data',
              account: 74,
            },
          ],
          waits: [
            {
              key: '["receive",3]',
              participant: 'Accept account data',
              account: 3,
            },
          ],
          refusal: 'The merge was refused.',
        },
        true,
      )
      const root = fixture.nativeElement as HTMLElement
      root
        .querySelector<HTMLButtonElement>(
          '[data-id="hilos-user-merge-refusal-details"]',
        )
        ?.click()
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()
      expect(document.querySelector('[role="dialog"]')).not.toBeNull()

      fixture.componentRef.setInput('section', null)
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()

      expect(root.querySelector('h2')?.textContent).toBe('Account merge')
      expect(
        root
          .querySelector('[data-id="hilos-user-merge-status"]')
          ?.textContent?.trim(),
      ).toBe('')
      expect(
        root
          .querySelector('[data-id="hilos-user-merge-status-live"]')
          ?.textContent?.trim(),
      ).toBe('')
      expect(root.querySelector('[data-id="hilos-user-merge-part"]')).toBeNull()
      expect(root.querySelector('[data-id="hilos-user-merge-wait"]')).toBeNull()
      expect(
        root.querySelector('[data-id="hilos-user-merge-refusal"]'),
      ).toBeNull()
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(document.body.textContent).not.toContain('Transfer account data')
      expect(document.body.textContent).not.toContain('Accept account data')
      expect(document.body.textContent).not.toContain('The merge was refused.')
      expect(visibleWords(fixture)).toBe('Account merge')
    })
  })
})
