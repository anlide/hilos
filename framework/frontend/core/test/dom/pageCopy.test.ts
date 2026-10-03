// @vitest-environment happy-dom

import { afterEach, describe, expect, it, vi } from 'vitest'

import { copyPageInto } from '../../src/dom/pageCopy.js'

afterEach(() => {
  vi.restoreAllMocks()
  document.body.replaceChildren()
})

describe('copyPageInto', () => {
  it('copies live form state and inner scroll without identifiers or modal layers', () => {
    const source = document.createElement('div')
    source.innerHTML =
      '<!-- Vue v-if -->' +
      '<section id="page" data-id="page" name="outer">' +
      '<div id="scroll" data-id="scroll" name="inner">' +
      '<input type="text" value="old" id="text" data-id="text" name="text">' +
      '<input type="checkbox" id="check" data-id="check" name="check">' +
      '<textarea id="area" data-id="area" name="area">old</textarea>' +
      '<select multiple id="select" data-id="select" name="select">' +
      '<option selected id="one" data-id="one" name="one">One</option>' +
      '<option id="two" data-id="two" name="two">Two</option>' +
      '</select></div>' +
      '<div class="hilos-modal-layer" data-id="modal">Modal</div></section>'
    const target = document.createElement('div')
    target.innerHTML = '<p>Previous copy</p>'
    document.body.append(source, target)

    const text = source.querySelector<HTMLInputElement>('#text')!
    const check = source.querySelector<HTMLInputElement>('#check')!
    const area = source.querySelector<HTMLTextAreaElement>('#area')!
    const options = source.querySelectorAll<HTMLOptionElement>('option')
    const scroll = source.querySelector<HTMLElement>('#scroll')!
    text.value = 'edited'
    check.checked = true
    check.indeterminate = true
    area.value = 'changed'
    options[0].selected = false
    options[1].selected = true
    scroll.scrollTop = 37
    scroll.scrollLeft = 12

    copyPageInto(source, target)

    expect(target.children).toHaveLength(1)
    expect(target.textContent).not.toContain('Previous copy')
    expect(target.textContent).not.toContain('Modal')
    expect(target.querySelector('.hilos-modal-layer')).toBeNull()
    expect(target.querySelectorAll('[id], [data-id], [name]')).toHaveLength(0)
    expect(target.childNodes).toHaveLength(1)
    expect(
      target.querySelector<HTMLInputElement>('input[type="text"]')?.value,
    ).toBe('edited')
    const copiedCheck = target.querySelector<HTMLInputElement>(
      'input[type="checkbox"]',
    )!
    expect(copiedCheck.checked).toBe(true)
    expect(copiedCheck.indeterminate).toBe(true)
    expect(target.querySelector<HTMLTextAreaElement>('textarea')?.value).toBe(
      'changed',
    )
    const copiedOptions = target.querySelectorAll<HTMLOptionElement>('option')
    expect([...copiedOptions].map((option) => option.selected)).toEqual([
      false,
      true,
    ])
    const copiedScroll = target.querySelector<HTMLElement>('div')!
    expect(copiedScroll.scrollTop).toBe(37)
    expect(copiedScroll.scrollLeft).toBe(12)
  })

  it('draws the source canvas into the copied canvas', () => {
    const drawImage = vi.fn()
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
      drawImage,
    } as unknown as CanvasRenderingContext2D)
    const source = document.createElement('div')
    const canvas = document.createElement('canvas')
    source.append(canvas)
    const target = document.createElement('div')
    document.body.append(source, target)

    copyPageInto(source, target)

    expect(drawImage).toHaveBeenCalledWith(canvas, 0, 0)
  })

  it('copies a selected file without assigning its forbidden nonempty value', () => {
    const source = document.createElement('div')
    const input = document.createElement('input')
    input.type = 'file'
    const transfer = new DataTransfer()
    transfer.items.add(
      new File(['picture'], 'portrait.png', { type: 'image/png' }),
    )
    input.files = transfer.files
    source.append(input)
    const target = document.createElement('div')
    document.body.append(source, target)

    expect(() => copyPageInto(source, target)).not.toThrow()
    expect(
      target.querySelector<HTMLInputElement>('input')?.files?.[0].name,
    ).toBe('portrait.png')
  })
})
