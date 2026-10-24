import { cleanup, fireEvent, render } from '@testing-library/react'
import type {
  HilosPathResolver,
  HilosStaticPageNavigation,
} from '@hilos/core/base'
import { useContext, useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosStaticPage } from '../src/HilosStaticPage.js'
import type { HilosStaticPageProps } from '../src/HilosStaticPage.js'
import { HilosPageHeadingIdContext } from '../src/hilosPageHeadingContext.js'

/** Synthetic document pages, resolved without a router or a connection. */
const ROUTES: Record<string, string> = {
  doc_terms: '/projects/{project}/terms',
  doc_history: '/projects/{project}/terms/history',
  doc_privacy: '/projects/{project}/privacy',
}

const resolvePath: HilosPathResolver = (page, params = {}) => {
  const route = ROUTES[page]
  if (route === undefined) return undefined
  let missing = false
  const path = route.replace(/\{(\w+)\}/g, (_token, name: string) => {
    const value = params[name]
    missing ||= value === undefined
    return value ?? ''
  })
  return missing ? undefined : path
}

const TABS = Object.freeze([
  Object.freeze({ page: 'doc_terms', label: 'Terms' }),
  Object.freeze({ page: 'doc_missing', label: 'Not built' }),
  Object.freeze({ page: 'doc_history', label: 'Revision history' }),
  Object.freeze({ page: 'doc_privacy', label: 'Privacy' }),
])

const NAVIGATION: HilosStaticPageNavigation = {
  page: 'doc_terms',
  tabs: TABS,
  params: { project: 'acme' },
  resolvePath,
}

/** A stateful body that takes its accessible name from the real context. */
function Body() {
  const headingId = useContext(HilosPageHeadingIdContext)
  const [count, setCount] = useState(0)
  return (
    <section data-id="body" aria-labelledby={headingId}>
      <p>Project prose</p>
      <button data-id="body-counter" onClick={() => setCount(count + 1)}>
        {count}
      </button>
    </section>
  )
}

function mountPage(props: Partial<HilosStaticPageProps> = {}) {
  let current = { title: 'Terms', ...props }
  const ui = () => (
    <HilosStaticPage {...current}>
      <Body />
    </HilosStaticPage>
  )
  const result = render(ui())
  return {
    ...result,
    update(next: Partial<HilosStaticPageProps>) {
      current = { ...current, ...next }
      result.rerender(ui())
    },
  }
}

function get(root: ParentNode, selector: string): HTMLElement {
  const element = root.querySelector<HTMLElement>(selector)
  if (element === null) throw new Error(`Nothing matches ${selector}`)
  return element
}

afterEach(cleanup)

describe('HilosStaticPage', () => {
  it('renders a ready title-only shared head and body without app providers', () => {
    const { container } = mountPage()
    const article = get(container, '[data-id="static-page"]')
    expect(article.tagName).toBe('ARTICLE')
    expect(article.className).toBe('row justify-content-center')
    expect(article.querySelector('.col-12.col-lg-8')).not.toBeNull()
    expect(article.querySelectorAll('h1')).toHaveLength(1)
    expect(get(article, '[data-id="hilos-page-head-title"]').textContent).toBe(
      'Terms',
    )
    expect(get(article, '[data-id="body"]').textContent).toContain(
      'Project prose',
    )
    const head = get(article, '[data-id="hilos-page-head"]')
    expect(head.querySelector('i')).toBeNull()
    expect(head.querySelector('p')).toBeNull()
    expect(head.querySelector('nav')).toBeNull()
    expect(
      head.querySelector('[data-id="hilos-page-head-skeleton"]'),
    ).toBeNull()
  })

  it('draws the optional icon and subtitle through the section head', () => {
    const { container } = mountPage({
      icon: 'bi-file-earmark-text',
      subtitle: 'Read these terms.',
    })
    const block = get(container, '[data-id="hilos-page-head-block"]')
    expect(
      get(block, 'i.bi-file-earmark-text').parentElement?.getAttribute(
        'aria-hidden',
      ),
    ).toBe('true')
    expect(get(block, 'p').textContent).toBe('Read these terms.')
  })

  it('updates presentation while preserving the heading id and stateful children', () => {
    const view = mountPage()
    const heading = get(view.container, 'h1')
    const id = heading.id
    const body = get(view.container, '[data-id="body"]')
    fireEvent.click(get(body, '[data-id="body-counter"]'))
    view.update({
      title: 'Privacy',
      icon: 'bi-shield-lock',
      subtitle: 'Your data.',
      navigation: NAVIGATION,
    })
    expect(get(view.container, 'h1')).toBe(heading)
    expect(heading.textContent).toBe('Privacy')
    expect(heading.id).toBe(id)
    expect(
      get(view.container, '[data-id="hilos-page-head-block"] p').textContent,
    ).toBe('Your data.')
    expect(view.container.querySelector('.bi-shield-lock')).not.toBeNull()
    expect(get(view.container, '[data-id="body"]')).toBe(body)
    expect(body.getAttribute('aria-labelledby')).toBe(id)
    expect(get(body, '[data-id="body-counter"]').textContent).toBe('1')
    view.update({ icon: undefined, subtitle: undefined, navigation: undefined })
    expect(
      view.container.querySelector('[data-id="hilos-page-head"] i'),
    ).toBeNull()
    expect(
      view.container.querySelector('[data-id="hilos-page-head"] p'),
    ).toBeNull()
    expect(view.container.querySelector('nav')).toBeNull()
    expect(get(body, '[data-id="body-counter"]').textContent).toBe('1')
  })

  it('provides each child consumer with its own heading id', () => {
    const { container } = render(
      <>
        <HilosStaticPage title="Terms">
          <Body />
        </HilosStaticPage>
        <HilosStaticPage title="Privacy">
          <Body />
        </HilosStaticPage>
      </>,
    )
    const pages = Array.from(
      container.querySelectorAll('[data-id="static-page"]'),
    )
    const ids = pages.map((page) => get(page, 'h1').id)
    expect(ids[0]).toBeTruthy()
    expect(ids[1]).toBeTruthy()
    expect(new Set(ids).size).toBe(2)
    pages.forEach((page, index) => {
      expect(
        get(page, '[data-id="body"]').getAttribute('aria-labelledby'),
      ).toBe(ids[index])
    })
  })

  it.each([
    { tabs: [] },
    { tabs: [TABS[0]] },
    { tabs: [TABS[0], TABS[1]] },
    { tabs: [TABS[1]] },
  ])(
    'keeps the head and hides fewer than two resolved tabs: $tabs',
    ({ tabs }) => {
      const { container } = mountPage({ navigation: { ...NAVIGATION, tabs } })
      expect(get(container, 'h1').textContent).toBe('Terms')
      expect(container.querySelector('nav')).toBeNull()
      expect(get(container, '[data-id="body"]').textContent).toContain(
        'Project prose',
      )
    },
  )

  it('passes empty params by default and omits tabs whose route needs them', () => {
    const resolver = vi.fn(resolvePath)
    const { container } = mountPage({
      navigation: { page: 'doc_terms', tabs: TABS, resolvePath: resolver },
    })
    expect(resolver).toHaveBeenCalledWith('doc_terms', {})
    expect(resolver).toHaveBeenCalledWith('doc_history', {})
    expect(container.querySelector('nav')).toBeNull()
  })

  it('preserves tab captions and order, resolving current links from live navigation', () => {
    const view = mountPage({ navigation: NAVIGATION })
    const row = get(view.container, '[data-id="hilos-page-tabs"]')
    expect(row.getAttribute('aria-label')).toBe('Page sections')
    expect(
      Array.from(row.querySelectorAll('a'), (link) => [
        link.textContent,
        link.getAttribute('href'),
      ]),
    ).toEqual([
      ['Terms', '/projects/acme/terms'],
      ['Revision history', '/projects/acme/terms/history'],
      ['Privacy', '/projects/acme/privacy'],
    ])
    expect(row.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
    expect(get(row, '[aria-current="page"]').getAttribute('data-id')).toBe(
      'hilos-page-tab-doc_terms',
    )
    expect(
      view.container.querySelector('[data-id="hilos-breadcrumb"]'),
    ).toBeNull()
    view.update({
      navigation: {
        ...NAVIGATION,
        page: 'doc_history',
        params: { project: 'next' },
        label: 'Document pages',
      },
    })
    expect(row.getAttribute('aria-label')).toBe('Document pages')
    expect(row.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
    expect(get(row, '[aria-current="page"]').getAttribute('data-id')).toBe(
      'hilos-page-tab-doc_history',
    )
    expect(
      get(row, '[data-id="hilos-page-tab-doc_history"]').getAttribute('href'),
    ).toBe('/projects/next/terms/history')
    expect(
      view.container.querySelector('[data-id="hilos-breadcrumb"]'),
    ).toBeNull()
    const onlyHistory: HilosPathResolver = (page, params) =>
      page === 'doc_history' ? resolvePath(page, params) : undefined
    view.update({ navigation: { ...NAVIGATION, resolvePath: onlyHistory } })
    expect(view.container.querySelector('nav')).toBeNull()
    expect(TABS.map((tab) => tab.page)).toEqual([
      'doc_terms',
      'doc_missing',
      'doc_history',
      'doc_privacy',
    ])
  })
})
