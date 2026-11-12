// One union of ErrorPage behavior, run through three real SDK mounts.
import type { PageError } from '@hilos/core/base'
import type * as Vitest from 'vitest'

import { ROUTE_NOT_FOUND_ERROR } from '../core/src/routing/pageError.js'
import { DELETED, FORBIDDEN, NOT_FOUND } from './errorPageFixtures.js'

type ErrorPageTestRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

/** The real error surface and its framework's input delivery. */
export interface ErrorPageView {
  readonly root: ParentNode
  update(error: PageError): Promise<void>
}

/** Enclose creation, updates and assertions in the SDK's cleanup lifetime. */
export type WithErrorPage = (
  error: PageError,
  steps: (view: ErrorPageView) => Promise<void>,
) => Promise<void>

/** Find a required semantic element without depending on Bootstrap classes. */
function required(root: ParentNode, selector: string): HTMLElement {
  const element = root.querySelector<HTMLElement>(selector)
  if (element === null)
    throw new Error(`Missing error page element: ${selector}`)
  return element
}

/** Register the semantic union of the existing ErrorPage checks. */
export function registerErrorPageCases(
  sdk: string,
  runner: ErrorPageTestRunner,
  withErrorPage: WithErrorPage,
): void {
  const { describe, it, expect } = runner
  const surfaceSelector = '[data-id="page-error"]'
  const lineSelector = '[data-id="page-error-transition-message"]'
  const homeSelector = '[data-id="page-error-home"]'
  // The title and description are the first two paragraphs, before the
  // optional transition line. Their order is meaningful; their classes are not.
  const titleSelector = 'h1 + p'
  const descriptionSelector = 'h1 + p + p'

  describe(`ErrorPage on ${sdk}`, () => {
    it('renders the HTTP code, server title and description', async () => {
      await withErrorPage(NOT_FOUND, async ({ root }) => {
        const surface = required(root, surfaceSelector)
        expect(surface.getAttribute('data-error-code')).toBe('404')
        expect(surface.querySelectorAll('h1')).toHaveLength(1)
        expect(required(surface, 'h1').textContent?.trim()).toBe('404')
        expect(required(surface, titleSelector).textContent).toBe(
          'Page Not Found',
        )
        expect(required(surface, descriptionSelector).textContent).toBe(
          'The page you are looking for does not exist.',
        )
      })
    })

    it('renders server fallback wording for an unmapped status', async () => {
      await withErrorPage(
        {
          ...NOT_FOUND,
          httpCode: 418,
          title: 'Error',
          message: 'An error occurred.',
        },
        async ({ root }) => {
          const surface = required(root, surfaceSelector)
          expect(surface.getAttribute('data-error-code')).toBe('418')
          expect(required(surface, 'h1').textContent?.trim()).toBe('418')
          expect(required(surface, titleSelector).textContent).toBe('Error')
          expect(required(surface, descriptionSelector).textContent).toBe(
            'An error occurred.',
          )
        },
      )
    })

    it('renders arbitrary server title and message literally', async () => {
      await withErrorPage(
        {
          ...FORBIDDEN,
          title: 'Server <b>title</b>',
          message: 'Server <em>description</em> & details.',
        },
        async ({ root }) => {
          const surface = required(root, surfaceSelector)
          expect(surface.getAttribute('data-error-code')).toBe('403')
          expect(required(surface, 'h1').textContent?.trim()).toBe('403')
          expect(required(surface, titleSelector).textContent).toBe(
            'Server <b>title</b>',
          )
          expect(required(surface, descriptionSelector).textContent).toBe(
            'Server <em>description</em> & details.',
          )
          expect(surface.querySelector('b, em')).toBeNull()
          expect(required(surface, homeSelector).getAttribute('href')).toBe('/')
        },
      )
    })

    it('refuses a 403 without calling the reader a guest', async () => {
      await withErrorPage(FORBIDDEN, async ({ root }) => {
        const surface = required(root, surfaceSelector)
        expect(surface.getAttribute('data-error-code')).toBe('403')
        expect(surface.textContent).toContain('Forbidden')
        expect(surface.textContent).toContain(
          'You do not have access to this page.',
        )
        expect(surface.textContent).not.toMatch(/guest/i)
      })
    })

    it('renders a route miss that carries no page key', async () => {
      await withErrorPage(ROUTE_NOT_FOUND_ERROR, async ({ root }) => {
        const surface = required(root, surfaceSelector)
        expect(root.querySelectorAll('h1')).toHaveLength(1)
        expect(required(surface, 'h1').textContent?.trim()).toBe('404')
        expect(required(surface, titleSelector).textContent).toBe(
          'Page Not Found',
        )
        expect(required(surface, descriptionSelector).textContent).toBe(
          'The page you are looking for does not exist.',
        )
        expect(required(surface, homeSelector).getAttribute('href')).toBe('/')
      })
    })

    it('offers one named, keyboard-focusable way home', async () => {
      await withErrorPage(NOT_FOUND, async ({ root }) => {
        expect(root.querySelectorAll(homeSelector)).toHaveLength(1)
        const home = required(root, homeSelector)
        expect(home.tagName).toBe('A')
        expect(home.getAttribute('href')).toBe('/')
        expect(home.textContent?.trim()).toBe('Back to home')
        expect(home.tabIndex).toBe(0)
        home.focus()
        expect(document.activeElement).toBe(home)
      })
    })
  })

  describe(`ErrorPage transition line on ${sdk}`, () => {
    it('draws the line under the description and before the way home', async () => {
      await withErrorPage(DELETED, async ({ root }) => {
        const surface = required(root, surfaceSelector)
        const description = required(surface, descriptionSelector)
        const line = required(surface, lineSelector)
        const home = required(surface, homeSelector)
        expect(description.textContent).toBe(NOT_FOUND.message)
        expect(line.tagName).toBe('P')
        expect(line.textContent?.trim()).toBe('It was deleted just now.')
        expect(description.nextElementSibling).toBe(line)
        expect(line.nextElementSibling).toBe(home)
        expect(surface.querySelectorAll('h1')).toHaveLength(1)
        expect(surface.querySelectorAll(homeSelector)).toHaveLength(1)
      })
    })

    it('shows any server line literally, never as markup', async () => {
      await withErrorPage(
        { ...DELETED, transitionMessage: 'Gone <b>now</b> & for good' },
        async ({ root }) => {
          const line = required(root, lineSelector)
          expect(line.textContent?.trim()).toBe('Gone <b>now</b> & for good')
          expect(line.querySelector('b')).toBeNull()
          expect(line.innerHTML.trim()).toBe(
            'Gone &lt;b&gt;now&lt;/b&gt; &amp; for good',
          )
        },
      )
    })

    it('draws no transition line when the key is absent', async () => {
      await withErrorPage(NOT_FOUND, async ({ root }) => {
        const surface = required(root, surfaceSelector)
        expect(surface.querySelector(lineSelector)).toBeNull()
        expect(surface.querySelectorAll('p')).toHaveLength(2)
        expect(required(surface, descriptionSelector).nextElementSibling).toBe(
          required(surface, homeSelector),
        )
      })
    })

    it('uses key presence even when the supplied line is empty', async () => {
      await withErrorPage(
        { ...FORBIDDEN, transitionMessage: '' },
        async ({ root }) => {
          const surface = required(root, surfaceSelector)
          const line = required(surface, lineSelector)
          expect(line.tagName).toBe('P')
          expect(line.textContent?.trim()).toBe('')
          expect(
            required(surface, descriptionSelector).nextElementSibling,
          ).toBe(line)
          expect(line.nextElementSibling).toBe(required(surface, homeSelector))
        },
      )
    })

    it('takes the line away when the next refusal carries no key', async () => {
      await withErrorPage(DELETED, async (view) => {
        expect(required(view.root, lineSelector).textContent?.trim()).toBe(
          'It was deleted just now.',
        )
        await view.update(NOT_FOUND)
        expect(view.root.querySelector(lineSelector)).toBeNull()

        await view.update({ ...FORBIDDEN, transitionMessage: 'Moved.' })
        expect(required(view.root, lineSelector).textContent?.trim()).toBe(
          'Moved.',
        )
        await view.update(FORBIDDEN)
        expect(view.root.querySelector(lineSelector)).toBeNull()
        expect(view.root.querySelectorAll('h1')).toHaveLength(1)
        expect(view.root.querySelectorAll(homeSelector)).toHaveLength(1)
        const surface = required(view.root, surfaceSelector)
        expect(required(surface, descriptionSelector).textContent).toBe(
          FORBIDDEN.message,
        )
        expect(required(surface, descriptionSelector).nextElementSibling).toBe(
          required(surface, homeSelector),
        )
      })
    })
  })
}
