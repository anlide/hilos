import { describe, expect, it } from 'vitest'

import {
  mergeTextLines,
  type TextLineMergeResult,
} from '../../src/conflict/textLineMerge.js'

/** Every example also holds when the two editors exchange places. */
function expectMerge(
  baseline: string,
  draft: string,
  incoming: string,
  expected: TextLineMergeResult,
): void {
  expect(mergeTextLines(baseline, draft, incoming)).toEqual(expected)
  expect(mergeTextLines(baseline, incoming, draft)).toEqual(expected)
  for (const value of [draft, incoming]) {
    expect(mergeTextLines(baseline, value, baseline)).toEqual({
      merged: true,
      value,
    })
    expect(mergeTextLines(baseline, baseline, value)).toEqual({
      merged: true,
      value,
    })
  }
}

describe('mergeTextLines', () => {
  it('preserves unchanged text and either one-sided edit exactly', () => {
    for (const value of ['', '\n', 'a\n\nb\n', 'a\r\nb', '  text\t\r\n']) {
      expectMerge(value, value, value, { merged: true, value })
      expectMerge('old\ntext', value, 'old\ntext', { merged: true, value })
    }
  })

  it('accepts identical replacements, insertions and complete deletions once', () => {
    for (const value of ['A\nb\n', 'a\nnew\nb\n', '']) {
      expectMerge('a\nb\n', value, value, { merged: true, value })
    }
  })

  it('combines different original lines, including edits far apart in a long text', () => {
    expectMerge('a\nb\nc\n', 'A\nb\nc\n', 'a\nb\nC\n', {
      merged: true,
      value: 'A\nb\nC\n',
    })
    const lines = Array.from({ length: 1024 }, (_, index) => `line ${index}\n`)
    const draft = [...lines]
    const incoming = [...lines]
    draft[0] = 'first draft line\n'
    draft[lines.length - 1] = 'last draft line\n'
    incoming[512] = 'incoming middle line\n'
    const combined = [...draft]
    combined[512] = incoming[512]
    expectMerge(lines.join(''), draft.join(''), incoming.join(''), {
      merged: true,
      value: combined.join(''),
    })
  })

  it('combines adjacent replacements and deduplicates a shared adjacent edit', () => {
    expectMerge('a\nb\nc\n', 'A\nb\nc\n', 'a\nB\nc\n', {
      merged: true,
      value: 'A\nB\nc\n',
    })
    expectMerge('a\nb\nc\n', 'A\nB\nc\n', 'A\nb\nC\n', {
      merged: true,
      value: 'A\nB\nC\n',
    })
  })

  it('refuses different edits of one original line without a partial value', () => {
    expectMerge('a\nb\nc\n', 'A\nb\nc\n', 'other\nb\nC\n', {
      merged: false,
    })
    expectMerge('one line', 'one local line', 'one remote line', {
      merged: false,
    })
  })

  it('refuses competing insertions at the beginning, middle and end', () => {
    for (const [draft, incoming] of [
      ['mine\na\nb\n', 'theirs\na\nb\n'],
      ['a\nmine\nb\n', 'a\ntheirs\nb\n'],
      ['a\nb\nmine\n', 'a\nb\ntheirs\n'],
      ['a\nshared\nb\n', 'a\nshared\nextra\nb\n'],
    ]) {
      expectMerge('a\nb\n', draft, incoming, { merged: false })
    }
  })

  it('deduplicates identical insertions beside independent replacements', () => {
    expectMerge(
      'a\nb\nc\nd\ne\n',
      'a\nshared\nb\nC\nd\ne\n',
      'a\nshared\nb\nc\nd\nE\n',
      {
        merged: true,
        value: 'a\nshared\nb\nC\nd\nE\n',
      },
    )
    expectMerge('a\nb\nc\n', 'a\nshared\nB\nc\n', 'a\nshared\nb\nC\n', {
      merged: false,
    })
  })

  it('combines insertions at different positions without changing their endings', () => {
    expectMerge('a\nb\n', 'first\r\na\nb\n', 'a\nb\nlast\n', {
      merged: true,
      value: 'first\r\na\nb\nlast\n',
    })
    expectMerge('a\nb\nc\n', 'a\nx\nb\nc\n', 'a\nb\ny\nc\n', {
      merged: true,
      value: 'a\nx\nb\ny\nc\n',
    })
  })

  it('combines deletions and removes shared deleted lines only once', () => {
    expectMerge('a\nb\nc\nd\n', 'b\nc\nd\n', 'a\nb\nc\n', {
      merged: true,
      value: 'b\nc\n',
    })
    expectMerge('a\nb\nc\nd\n', 'a\nd\n', 'a\nb\n', {
      merged: true,
      value: 'a\n',
    })
    expectMerge('a\nb\nc\nd\n', 'a\nc\nd\n', 'a\nc\nD\n', {
      merged: true,
      value: 'a\nc\nD\n',
    })
  })

  it('refuses deletion against an edit or an interior insertion but keeps boundary insertions', () => {
    expectMerge('a\nb\nc\n', 'a\nc\n', 'a\nB\nc\n', { merged: false })
    expectMerge('a\nb\nc\nd\n', 'a\nd\n', 'a\nb\nx\nc\nd\n', {
      merged: false,
    })
    expectMerge('a\nb\nc\nd\n', 'a\nd\n', 'a\nx\nb\nc\ny\nd\n', {
      merged: true,
      value: 'a\nx\ny\nd\n',
    })
    expectMerge('a\nb\nc\nd\ne\n', 'a\nc\nD\ne\n', 'a\ne\n', {
      merged: false,
    })
  })

  it.each([
    ['inserted\nsame\nsame\nend\n', 'inserted\nsame\nend\n'],
    ['same\nsame\ninserted\nend\n', 'same\ninserted\nend\n'],
  ])(
    'keeps an external insertion beside a repeated-line deletion (%#)',
    (incoming, value) => {
      expectMerge('same\nsame\nend\n', 'same\nend\n', incoming, {
        merged: true,
        value,
      })
    },
  )

  it('keeps external insertions beside retained blank lines and line endings', () => {
    for (const [baseline, draft, incoming, value] of [
      ['\n\nend\n', '\nend\n', 'inserted\n\n\nend\n', 'inserted\n\nend\n'],
      ['\n\nend\n', '\nend\n', '\n\ninserted\nend\n', '\ninserted\nend\n'],
      [
        'start\nsame\r\nsame\r\nend',
        'start\nsame\r\nend',
        'start\ninserted\nsame\r\nsame\r\nend',
        'start\ninserted\nsame\r\nend',
      ],
      [
        'start\nsame\r\nsame\r\nend',
        'start\nsame\r\nend',
        'start\nsame\r\nsame\r\ninserted\nend',
        'start\nsame\r\ninserted\nend',
      ],
      [
        'start\nsame\nsame\n',
        'start\nsame\n',
        'start\nsame\nsame\ninserted',
        'start\nsame\ninserted',
      ],
    ]) {
      expectMerge(baseline, draft, incoming, { merged: true, value })
    }
  })

  it('refuses interior insertions and mixed replacements around repeated lines', () => {
    expectMerge(
      'same\nsame\nend\n',
      'same\nend\n',
      'same\ninserted\nsame\nend\n',
      { merged: false },
    )
    for (const incoming of [
      'other\ninserted\nsame\nsame\nend\n',
      'same\nsame\ninserted\nother\nend\n',
    ]) {
      expectMerge('same\nsame\nend\n', 'same\ninserted\nend\n', incoming, {
        merged: false,
      })
    }
    expectMerge('a\nb\nc\n', 'A\nextra\nB\nc\n', 'inserted\na\nb\nc\n', {
      merged: false,
    })
    expectMerge('a\nb\nc\n', 'A\nextra\nB\nc\n', 'a\nb\ninserted\nc\n', {
      merged: false,
    })
    for (const incoming of [
      'inserted\nsame\nsame\nsame\nend\n',
      'same\nsame\nsame\ninserted\nend\n',
    ]) {
      expectMerge('same\nsame\nsame\nend\n', 'same\nchanged\nend\n', incoming, {
        merged: false,
      })
    }
  })

  it('keeps blank and repeated lines and refuses an ambiguous overlapping alignment', () => {
    expectMerge('', 'mine\n', 'theirs\n', { merged: false })
    expectMerge('\n\n', 'first\n\n\n', '\n\nlast\n', {
      merged: true,
      value: 'first\n\n\nlast\n',
    })
    expectMerge('same\nsame\nend\n', 'same\nend\n', 'same\nsame\nEND\n', {
      merged: true,
      value: 'same\nEND\n',
    })
    for (const incoming of ['changed\nsame\nend\n', 'same\nchanged\nend\n']) {
      expectMerge('same\nsame\nend\n', 'same\nend\n', incoming, {
        merged: false,
      })
    }
    expectMerge('a\nb\nend\n', 'b\na\nend\n', 'A\nb\nend\n', {
      merged: false,
    })
  })

  it('preserves LF, CRLF, mixed endings and the presence or absence of the final newline', () => {
    for (const [baseline, draft, incoming, value] of [
      ['a\nb\nc', 'A\nb\nc', 'a\nb\nC', 'A\nb\nC'],
      [
        'a\r\nb\r\nc\r\n',
        'A\r\nb\r\nc\r\n',
        'a\r\nb\r\nC\r\n',
        'A\r\nb\r\nC\r\n',
      ],
      ['a\r\nb\nc\r\n', 'A\r\nb\nc\r\n', 'a\r\nb\nC\r\n', 'A\r\nb\nC\r\n'],
      ['a\nb\nc', 'A\nb\nc', 'a\nb\nc\n', 'A\nb\nc\n'],
      ['a\nb\nc\n', 'A\nb\nc\n', 'a\nb\nc', 'A\nb\nc'],
    ]) {
      expectMerge(baseline, draft, incoming, { merged: true, value })
    }
    expectMerge('a\nb', 'a\nb\n', 'a\nB', { merged: false })
    expectMerge('a\nb\n', 'a\r\nb\r\n', 'A\nb\n', { merged: false })
  })
})
