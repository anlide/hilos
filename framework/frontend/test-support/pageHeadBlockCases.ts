// PageHead's owner block and unanswered identity, on the three real SDKs.
import type * as Vitest from 'vitest'
import {
  ENTITY,
  ENTITY_CONTENT,
  SECTION,
  createMalformedPageHeadAnswer,
  entityHeadInput,
  sectionHeadInput,
  type PageHeadInput,
} from './pageHeadFixtures.js'

type PageHeadRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

/** Native rendering and cleanup, with no expectations in the adapter. */
export interface PageHeadView {
  readonly root: HTMLElement
  update(input: Partial<PageHeadInput>): Promise<void>
  unmount(): Promise<void>
}

/** Two copies share one application, as Vue's generated ids do. */
export type MountPageHead = (
  input: PageHeadInput,
  copies?: 1 | 2,
) => Promise<PageHeadView>

/** Register the portable block, title and silent skeleton union. */
export function registerPageHeadBlockCases(
  sdk: string,
  runner: PageHeadRunner,
  mount: MountPageHead,
): void {
  const { describe, it, expect } = runner
  describe(`HilosPageHead block on ${sdk}`, () => {
    it('H01 draws the owner title, lead and decorative section visual', async () => {
      const view = await mount(sectionHeadInput({ headingId: 'page-heading' }))
      try {
        const block = view.root.querySelector(
          '[data-id="hilos-page-head-block"]',
        )
        expect(block).not.toBeNull()
        const icon = block?.querySelector('i')
        expect(icon).not.toBeNull()
        expect(icon?.parentElement?.getAttribute('aria-hidden')).toBe('true')
        const headings = view.root.querySelectorAll('h1')
        expect(headings).toHaveLength(1)
        expect(headings[0].id).toBe('page-heading')
        expect(headings[0].getAttribute('data-id')).toBe(
          'hilos-page-head-title',
        )
        expect(headings[0].textContent?.trim()).toBe('Demo section')
        expect(headings[0].textContent).not.toContain('Gaps')
        expect(block?.textContent).toContain(
          'Languages, countries, and translation screens.',
        )
        expect(block?.textContent).not.toContain('What is still untranslated.')
      } finally {
        await view.unmount()
      }
    })

    it('H02 gives two live heads distinct connected heading ids', async () => {
      const view = await mount(sectionHeadInput(), 2)
      try {
        const heads = [
          ...view.root.querySelectorAll('[data-id="hilos-page-head"]'),
        ]
        expect(heads).toHaveLength(2)
        const ids = heads.map((head) => {
          const headings = head.querySelectorAll('h1')
          expect(headings).toHaveLength(1)
          const heading = headings[0]
          expect(heading.isConnected).toBe(true)
          expect(heading.id).toBeTruthy()
          expect(document.getElementById(heading.id)).toBe(heading)
          return heading.id
        })
        expect(ids[1]).not.toBe(ids[0])
      } finally {
        await view.unmount()
      }
    })

    it('H03 passes the caller title class to the same named h1', async () => {
      const view = await mount(
        sectionHeadInput({
          headingId: 'page-heading',
          titleClass: 'head-title-extra',
        }),
      )
      try {
        const headings = view.root.querySelectorAll('h1')
        expect(headings).toHaveLength(1)
        expect(headings[0].id).toBe('page-heading')
        expect(headings[0].textContent?.trim()).toBe('Demo section')
        expect(headings[0].classList.contains('head-title-extra')).toBe(true)
      } finally {
        await view.unmount()
      }
    })

    it('H04 draws caller entity content and one owner name without section defaults', async () => {
      const view = await mount(entityHeadInput({ content: ENTITY_CONTENT }))
      try {
        const block = view.root.querySelector(
          '[data-id="hilos-page-head-block"]',
        )
        expect(block).not.toBeNull()
        for (const [probe, text] of [
          ['tile', 'US'],
          ['code', 'us'],
          ['badge', 'Custom'],
          ['description', 'Currency $ USD'],
        ]) {
          const part = block?.querySelector(`[data-id="probe-${probe}"]`)
          expect(part).not.toBeNull()
          expect(part?.textContent?.trim()).toBe(text)
        }
        expect(block?.querySelector('i')).toBeNull()
        expect(block?.textContent).not.toContain('One entity of the list.')
        const headings = view.root.querySelectorAll('h1')
        expect(headings).toHaveLength(1)
        expect(headings[0].textContent?.trim()).toBe('United States')
        const titleLine = headings[0].parentElement
        expect(
          titleLine?.querySelector('[data-id="probe-code"]'),
        ).not.toBeNull()
        expect(
          titleLine?.querySelector('[data-id="probe-badge"]'),
        ).not.toBeNull()
        expect(
          titleLine?.querySelector('[data-id="probe-description"]'),
        ).toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('H05 draws an entity without caller content as its one title alone', async () => {
      const view = await mount(entityHeadInput())
      try {
        const block = view.root.querySelector(
          '[data-id="hilos-page-head-block"]',
        )
        expect(block).not.toBeNull()
        expect(block?.querySelector('i')).toBeNull()
        expect(block?.querySelector('p')).toBeNull()
        expect(view.root.querySelectorAll('h1')).toHaveLength(1)
        expect(block?.querySelector('h1')?.textContent?.trim()).toBe(
          'United States',
        )
      } finally {
        await view.unmount()
      }
    })

    it('H06 replaces the section visual and description without duplicating defaults', async () => {
      const view = await mount(
        sectionHeadInput({
          content: {
            visual: ENTITY_CONTENT.visual,
            description: ENTITY_CONTENT.description,
          },
        }),
      )
      try {
        const block = view.root.querySelector(
          '[data-id="hilos-page-head-block"]',
        )
        expect(block).not.toBeNull()
        expect(
          block?.querySelector('[data-id="probe-tile"]')?.textContent?.trim(),
        ).toBe('US')
        expect(
          block
            ?.querySelector('[data-id="probe-description"]')
            ?.textContent?.trim(),
        ).toBe('Currency $ USD')
        expect(block?.querySelector('i')).toBeNull()
        expect(block?.textContent).not.toContain(
          'Languages, countries, and translation screens.',
        )
      } finally {
        await view.unmount()
      }
    })

    it('H07 orders the breadcrumb, owner block and page row inside the head', async () => {
      const view = await mount(sectionHeadInput())
      try {
        const head = view.root.querySelector('[data-id="hilos-page-head"]')
        expect(head).not.toBeNull()
        const order = [...head!.querySelectorAll('[data-id]')].flatMap(
          (node) => {
            const id = node.getAttribute('data-id')
            return id === 'hilos-breadcrumb' ||
              id === 'hilos-page-head-block' ||
              id === 'hilos-page-tabs'
              ? [id]
              : []
          },
        )
        expect(order).toEqual([
          'hilos-breadcrumb',
          'hilos-page-head-block',
          'hilos-page-tabs',
        ])
      } finally {
        await view.unmount()
      }
    })

    it('H08 keeps unanswered content, controls and a second loading voice absent', async () => {
      const view = await mount(
        entityHeadInput({
          identity: undefined,
          content: ENTITY_CONTENT,
          action: { page: 'demo_section', label: 'Back' },
        }),
      )
      try {
        expect(
          view.root
            .querySelector('[data-id="hilos-page-head-skeleton"]')
            ?.getAttribute('aria-hidden'),
        ).toBe('true')
        for (const selector of [
          'h1',
          '[data-id="hilos-breadcrumb"]',
          '[data-id="hilos-page-tabs"]',
          '[data-id="hilos-page-head-block"]',
          '[data-id="hilos-page-head-action"]',
          '[data-id^="probe-"]',
          '[role="status"]',
          '[aria-live]',
        ]) {
          expect(view.root.querySelector(selector), selector).toBeNull()
        }
        expect(view.root.textContent?.trim()).toBe('')
        await view.update({ identity: ENTITY })
        expect(
          view.root.querySelector('[data-id="hilos-page-head-skeleton"]'),
        ).toBeNull()
        expect(view.root.querySelector('h1')?.textContent?.trim()).toBe(
          'United States',
        )
        expect(
          view.root.querySelector('[data-id="hilos-page-head-block"]'),
        ).not.toBeNull()
        for (const probe of ['tile', 'code', 'badge', 'description']) {
          expect(
            view.root.querySelector(`[data-id="probe-${probe}"]`),
          ).not.toBeNull()
        }
      } finally {
        await view.unmount()
      }
    })

    it('H09 keeps the custom loading picture inside the decorative skeleton until the same head answers', async () => {
      const view = await mount(
        sectionHeadInput({
          identity: undefined,
          content: {
            ...ENTITY_CONTENT,
            skeletonVisual: { dataId: 'probe-skeleton-visual', text: '' },
          },
        }),
      )
      try {
        const skeleton = view.root.querySelector(
          '[data-id="hilos-page-head-skeleton"]',
        )
        expect(skeleton?.getAttribute('aria-hidden')).toBe('true')
        expect(
          skeleton?.querySelector('[data-id="probe-skeleton-visual"]'),
        ).not.toBeNull()
        expect(view.root.querySelector('[data-id="probe-tile"]')).toBeNull()
        expect(
          view.root.querySelector('[data-id="probe-description"]'),
        ).toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-page-head-block"]'),
        ).toBeNull()
        expect(view.root.querySelector('h1')).toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-breadcrumb"]'),
        ).toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-page-tabs"]'),
        ).toBeNull()
        await view.update({ identity: SECTION })
        expect(
          view.root.querySelector('[data-id="hilos-page-head-skeleton"]'),
        ).toBeNull()
        expect(
          view.root.querySelector('[data-id="probe-skeleton-visual"]'),
        ).toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-page-head-block"]'),
        ).not.toBeNull()
        expect(view.root.querySelector('[data-id="probe-tile"]')).not.toBeNull()
        expect(
          view.root.querySelector('[data-id="probe-description"]'),
        ).not.toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('H24 keeps an answered identity without a head owner on the empty skeleton', async () => {
      const identity = {
        label: SECTION.label,
        lead: SECTION.lead,
        breadcrumb: SECTION.breadcrumb,
        children: [],
      }
      expect(identity).toBeDefined()
      const view = await mount(
        sectionHeadInput({
          identity,
          content: ENTITY_CONTENT,
          action: { page: 'demo_section', label: 'Back' },
        }),
      )
      try {
        expect(
          view.root
            .querySelector('[data-id="hilos-page-head-skeleton"]')
            ?.getAttribute('aria-hidden'),
        ).toBe('true')
        for (const selector of [
          'h1',
          '[data-id="hilos-breadcrumb"]',
          '[data-id="hilos-page-tabs"]',
          '[data-id="hilos-page-head-block"]',
          '[data-id="hilos-page-head-action"]',
          '[data-id^="probe-"]',
        ]) {
          expect(view.root.querySelector(selector), selector).toBeNull()
        }
        expect(view.root.textContent?.trim()).toBe('')
      } finally {
        await view.unmount()
      }
    })

    it('H25 keeps a malformed wire owner rejected by the real core selector on the skeleton', async () => {
      const answer = createMalformedPageHeadAnswer()
      let view: PageHeadView | undefined
      try {
        expect(answer.identity).toBeDefined()
        expect(answer.identity?.pageHeadOwner).toBeUndefined()
        view = await mount(
          sectionHeadInput({
            identity: answer.identity,
            content: ENTITY_CONTENT,
            action: { page: 'demo_section', label: 'Back' },
          }),
        )
        expect(
          view.root
            .querySelector('[data-id="hilos-page-head-skeleton"]')
            ?.getAttribute('aria-hidden'),
        ).toBe('true')
        for (const selector of [
          'h1',
          '[data-id="hilos-breadcrumb"]',
          '[data-id="hilos-page-tabs"]',
          '[data-id="hilos-page-head-block"]',
          '[data-id="hilos-page-head-action"]',
          '[data-id^="probe-"]',
        ]) {
          expect(view.root.querySelector(selector), selector).toBeNull()
        }
        expect(view.root.textContent?.trim()).toBe('')
      } finally {
        try {
          await view?.unmount()
        } finally {
          answer.dispose()
        }
      }
    })
  })
}
