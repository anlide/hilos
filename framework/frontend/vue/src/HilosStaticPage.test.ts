import { mount, type VueWrapper } from '@vue/test-utils'
import type {
  HilosPathResolver,
  HilosStaticPageNavigation,
} from '@hilos/core/base'
import { defineComponent, h, inject, ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'

import HilosStaticPage from './HilosStaticPage.vue'
import { hilosPageHeadingIdKey } from './hilosPageHeading.js'

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

/** A stateful body that takes its accessible name from the real heading key. */
const Body = defineComponent(() => {
  const headingId = inject(hilosPageHeadingIdKey)
  const count = ref(0)
  return () =>
    h('section', { 'data-id': 'body', 'aria-labelledby': headingId }, [
      h('p', 'Project prose'),
      h(
        'button',
        { 'data-id': 'body-counter', onClick: () => count.value++ },
        String(count.value),
      ),
    ])
})

type PageProps = InstanceType<typeof HilosStaticPage>['$props']
const mounted = new Set<VueWrapper>()

function mountPage(props: Partial<PageProps> = {}): VueWrapper {
  const view = mount(HilosStaticPage, {
    props: { title: 'Terms', ...props },
    slots: { default: () => h(Body) },
  })
  mounted.add(view)
  return view
}

afterEach(() => {
  for (const view of mounted) view.unmount()
  mounted.clear()
})

describe('HilosStaticPage', () => {
  it('renders a ready title-only shared head and body without app providers', () => {
    const view = mountPage()
    expect(view.element.tagName).toBe('ARTICLE')
    expect(view.classes()).toEqual(['row', 'justify-content-center'])
    expect(view.get('.col-12.col-lg-8').element.tagName).toBe('DIV')
    expect(view.findAll('h1')).toHaveLength(1)
    expect(view.get('[data-id="hilos-page-head-title"]').text()).toBe('Terms')
    expect(view.get('[data-id="body"]').text()).toContain('Project prose')
    const head = view.get('[data-id="hilos-page-head"]')
    expect(head.find('i').exists()).toBe(false)
    expect(head.find('p').exists()).toBe(false)
    expect(head.find('nav').exists()).toBe(false)
    expect(head.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      false,
    )
  })

  it('draws the optional icon and subtitle through the section head', () => {
    const view = mountPage({
      icon: 'bi-file-earmark-text',
      subtitle: 'Read these terms.',
    })
    const block = view.get('[data-id="hilos-page-head-block"]')
    expect(
      block
        .get('i.bi-file-earmark-text')
        .element.parentElement?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(block.get('p').text()).toBe('Read these terms.')
  })

  it('updates presentation while preserving the heading id and stateful slot', async () => {
    const view = mountPage()
    const heading = view.get('h1').element
    const id = heading.id
    const body = view.get('[data-id="body"]').element
    await view.get('[data-id="body-counter"]').trigger('click')
    await view.setProps({
      title: 'Privacy',
      icon: 'bi-shield-lock',
      subtitle: 'Your data.',
      navigation: NAVIGATION,
    })
    expect(view.get('h1').element).toBe(heading)
    expect(view.get('h1').text()).toBe('Privacy')
    expect(view.get('h1').attributes('id')).toBe(id)
    expect(view.get('[data-id="hilos-page-head-block"] p').text()).toBe(
      'Your data.',
    )
    expect(view.find('.bi-shield-lock').exists()).toBe(true)
    expect(view.get('[data-id="body"]').element).toBe(body)
    expect(view.get('[data-id="body"]').attributes('aria-labelledby')).toBe(id)
    expect(view.get('[data-id="body-counter"]').text()).toBe('1')
    await view.setProps({
      icon: undefined,
      subtitle: undefined,
      navigation: undefined,
    })
    expect(view.find('[data-id="hilos-page-head"] i').exists()).toBe(false)
    expect(view.find('[data-id="hilos-page-head"] p').exists()).toBe(false)
    expect(view.find('nav').exists()).toBe(false)
    expect(view.get('[data-id="body-counter"]').text()).toBe('1')
  })

  it('provides each slotted consumer with its own heading id', () => {
    const view = mount(
      defineComponent(
        () => () =>
          h('div', [
            h(HilosStaticPage, { title: 'Terms' }, { default: () => h(Body) }),
            h(
              HilosStaticPage,
              { title: 'Privacy' },
              { default: () => h(Body) },
            ),
          ]),
      ),
    )
    mounted.add(view)
    const pages = view.findAll('[data-id="static-page"]')
    const ids = pages.map((page) => page.get('h1').attributes('id'))
    expect(ids[0]).toBeTruthy()
    expect(ids[1]).toBeTruthy()
    expect(new Set(ids).size).toBe(2)
    pages.forEach((page, index) => {
      expect(page.get('[data-id="body"]').attributes('aria-labelledby')).toBe(
        ids[index],
      )
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
      const view = mountPage({ navigation: { ...NAVIGATION, tabs } })
      expect(view.get('h1').text()).toBe('Terms')
      expect(view.find('nav').exists()).toBe(false)
      expect(view.get('[data-id="body"]').text()).toContain('Project prose')
    },
  )

  it('passes empty params by default and omits tabs whose route needs them', () => {
    const resolver = vi.fn(resolvePath)
    const view = mountPage({
      navigation: { page: 'doc_terms', tabs: TABS, resolvePath: resolver },
    })
    expect(resolver).toHaveBeenCalledWith('doc_terms', {})
    expect(resolver).toHaveBeenCalledWith('doc_history', {})
    expect(view.find('nav').exists()).toBe(false)
  })

  it('preserves tab captions and order, resolving current links from live navigation', async () => {
    const view = mountPage({ navigation: NAVIGATION })
    const row = view.get('[data-id="hilos-page-tabs"]')
    expect(row.attributes('aria-label')).toBe('Page sections')
    expect(
      row.findAll('a').map((link) => [link.text(), link.attributes('href')]),
    ).toEqual([
      ['Terms', '/projects/acme/terms'],
      ['Revision history', '/projects/acme/terms/history'],
      ['Privacy', '/projects/acme/privacy'],
    ])
    expect(row.findAll('[aria-current="page"]')).toHaveLength(1)
    expect(row.get('[aria-current="page"]').attributes('data-id')).toBe(
      'hilos-page-tab-doc_terms',
    )
    expect(view.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
    await view.setProps({
      navigation: {
        ...NAVIGATION,
        page: 'doc_history',
        params: { project: 'next' },
        label: 'Document pages',
      },
    })
    expect(row.attributes('aria-label')).toBe('Document pages')
    expect(row.findAll('[aria-current="page"]')).toHaveLength(1)
    expect(row.get('[aria-current="page"]').attributes('data-id')).toBe(
      'hilos-page-tab-doc_history',
    )
    expect(
      row.get('[data-id="hilos-page-tab-doc_history"]').attributes('href'),
    ).toBe('/projects/next/terms/history')
    expect(view.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
    const onlyHistory: HilosPathResolver = (page, params) =>
      page === 'doc_history' ? resolvePath(page, params) : undefined
    await view.setProps({
      navigation: { ...NAVIGATION, resolvePath: onlyHistory },
    })
    expect(view.find('nav').exists()).toBe(false)
    expect(TABS.map((tab) => tab.page)).toEqual([
      'doc_terms',
      'doc_missing',
      'doc_history',
      'doc_privacy',
    ])
  })
})
