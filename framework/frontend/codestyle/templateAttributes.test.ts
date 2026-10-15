import { expect, it } from 'vitest'

import {
  templateAttributes,
  templateElements,
  templateTags,
} from './templateAttributes.js'

it('preserves tag names and separate tag/attribute lines through comments and quoted delimiters', () => {
  const markup = `<!-- <option [value]="ignored">
  <select bind-value="ignored"> -->
<select
  [disabled]="count > 0"
  data-label='a > b'>
  @for (item of items; track item) {
    <option [value]="item" [selected]="item === choice">{{ item }}</option>
  }
  <custom-select data-id=picker />
</select>`
  const tags = [
    {
      name: 'select',
      line: 3,
      attributes: [
        { name: '[disabled]', value: 'count > 0', line: 4 },
        { name: 'data-label', value: 'a > b', line: 5 },
      ],
    },
    {
      name: 'option',
      line: 7,
      attributes: [
        { name: '[value]', value: 'item', line: 7 },
        { name: '[selected]', value: 'item === choice', line: 7 },
      ],
    },
    {
      name: 'custom-select',
      line: 9,
      attributes: [{ name: 'data-id', value: 'picker', line: 9 }],
    },
  ]
  expect(templateTags(markup)).toEqual(tags)
  expect(templateElements(markup)).toEqual(tags.map((tag) => tag.attributes))
  expect(templateAttributes(markup)).toEqual(
    tags.flatMap((tag) => tag.attributes),
  )
})

it('keeps empty opening tags and boolean attributes, without returning closing tags', () => {
  expect(
    templateTags('<select><option selected>Only</option></select>'),
  ).toEqual([
    { name: 'select', line: 1, attributes: [] },
    {
      name: 'option',
      line: 1,
      attributes: [{ name: 'selected', value: '', line: 1 }],
    },
  ])
})
