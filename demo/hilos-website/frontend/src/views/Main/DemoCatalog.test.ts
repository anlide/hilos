import { enableAutoUnmount, mount, type DOMWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import DemoCatalog from './DemoCatalog.vue'

enableAutoUnmount(afterEach)

const SOURCE_TREE = 'https://github.com/anlide/hilos/tree/total-refac'

/** Find an implicitly named region by the heading that labels its section. */
function regionByName(
  root: DOMWrapper<Element>,
  name: string,
): DOMWrapper<Element> {
  const region = root.findAll('section[aria-labelledby]').find((section) => {
    const heading = section.find(
      `[id="${section.attributes('aria-labelledby')}"]`,
    )
    return heading.exists() && heading.text() === name
  })
  if (!region) throw new Error(`Missing region: ${name}`)
  expect(region.get('h3').attributes('id')).toBe(
    region.attributes('aria-labelledby'),
  )
  return region
}

describe('DemoCatalog', () => {
  it('groups the working demos and all ten application starters in named regions', () => {
    const wrapper = mount(DemoCatalog)
    const root = wrapper.find('section')
    const working = regionByName(root, 'Explore the working demos')
    const starters = regionByName(root, 'Application starters')

    expect(
      working
        .findAll('article')
        .map((card) => card.get('h4').text())
        .sort(),
    ).toEqual(['Chat', 'E-commerce shop'])
    expect(
      starters
        .findAll('article')
        .map((card) => card.get('h4').text())
        .sort(),
    ).toEqual([
      'BTC tracker',
      'Concert tickets',
      'File gallery manager',
      'Online testing',
      'PMD',
      'Polls',
      'Preferans',
      'Roblox game server',
      'Solar system model',
      'Tasks',
    ])
    expect(wrapper.findAll('article')).toHaveLength(12)
  })

  it.each([
    ['Chat', 'chat', 'https://chat-demo.hilos-dev.org/'],
    ['E-commerce shop', 'ecommerce-shop', 'https://shop-demo.hilos-dev.org/'],
    ['BTC tracker', 'binance-btc-tracker', 'https://btc-demo.hilos-dev.org/'],
    ['PMD', 'pmd', null],
    ['Roblox game server', 'roblox-game-server', null],
    ['File gallery manager', 'file-gallery-manager', null],
    ['Solar system model', 'solar-system-model', null],
    ['Tasks', 'tasks', 'https://tasks-demo.hilos-dev.org/'],
    ['Concert tickets', 'concert-tickets', null],
    ['Online testing', 'online-testing', 'https://testing-demo.hilos-dev.org/'],
    ['Polls', 'polls', 'https://polls-demo.hilos-dev.org/'],
    ['Preferans', 'preferans', null],
  ])(
    'offers the source and the available public address for %s',
    (name, folder, address) => {
      const wrapper = mount(DemoCatalog)
      const card = wrapper
        .findAll('article')
        .find((item) => item.get('h4').text() === name)
      if (!card) throw new Error(`Missing demo: ${name}`)

      const images = card.findAll('img')
      expect(images).toHaveLength(1)
      expect(images[0].attributes('alt')).toBe('')
      expect(images[0].attributes('src')).toBe(`/demos/${folder}-large.svg`)

      const source = card.get(`a[href="${SOURCE_TREE}/demo/${folder}"]`)
      expect(source.text()).toContain(`Source of ${name} on GitHub`)
      expect(source.attributes('target')).toBeUndefined()

      const demoLinks = card
        .findAll('a')
        .filter((link) => link.text().startsWith('Open demo'))
      if (address) {
        expect(demoLinks).toHaveLength(1)
        expect(demoLinks[0].attributes('href')).toBe(address)
        expect(demoLinks[0].text()).toBe(`Open demo ${name}`)
        expect(demoLinks[0].attributes('target')).toBeUndefined()
        expect(card.text()).not.toContain('No public address yet')
      } else {
        expect(demoLinks).toHaveLength(0)
        expect(card.findAll('a')).toHaveLength(1)
        expect(card.text()).toContain('No public address yet')
      }
    },
  )
})
