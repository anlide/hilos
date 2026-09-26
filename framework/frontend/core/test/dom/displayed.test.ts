// @vitest-environment happy-dom

import { afterEach, describe, expect, it } from 'vitest'

import { isDisplayed } from '../../src/dom/displayed.js'

afterEach(() => {
  document.head.innerHTML = ''
  document.body.innerHTML = ''
})

/**
 * An element in the document, built from markup.
 *
 * @param html The markup of the one element.
 * @returns The mounted element.
 */
function mount(html: string): Element {
  const holder = document.createElement('div')
  holder.innerHTML = html
  document.body.append(holder)

  const element = holder.firstElementChild
  if (element === null) {
    throw new Error('the markup holds no element')
  }

  return element
}

describe('isDisplayed', () => {
  it('reports an ordinary element as on display', () => {
    expect(isDisplayed(mount('<button type="button">Key ↓</button>'))).toBe(
      true,
    )
  })

  it('reports an element hidden by an inline style as not on display', () => {
    expect(
      isDisplayed(
        mount('<button type="button" style="display: none">Key ↓</button>'),
      ),
    ).toBe(false)
  })

  it('reports an element hidden by a class of a style sheet as not on display', () => {
    const style = document.createElement('style')
    style.textContent = '.d-none { display: none !important; }'
    document.head.append(style)

    expect(
      isDisplayed(mount('<button type="button" class="d-none">Key ↓</button>')),
    ).toBe(false)
  })
})
