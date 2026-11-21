import { readFileSync } from 'node:fs'

import { expect, it } from 'vitest'

import { checkSource } from './commentLanguage.js'

const FIXTURES = 'framework/frontend/codestyle/fixtures'
const MESSAGE =
  'write this comment in English (see docs/agents/code-style/comment-language.md)'

it.each([
  [
    'badCommentLanguage.ts',
    [1, 2, 5, 8, 9, 11, 13, 14, 15, 18, 25, 28, 35, 38, 42, 46, 52, 57, 63, 64],
  ],
  ['badCommentLanguageEscapes.ts', [4, 5]],
  ['badCommentLanguageLocal.ts', [3, 5]],
  ['badCommentLanguageContinuation.ts', [6]],
  ['badCommentLanguage.tsx', [3, 7, 9, 10, 16]],
  ['badCommentLanguage.vue', [2, 5, 7, 9, 11, 14, 17, 26, 29, 33, 35]],
  ['badCommentLanguage.html', [2, 3, 7, 9, 18, 21, 26, 29, 36, 42, 47, 56]],
  ['goodCommentLanguage.ts', [10]],
  ['goodCommentLanguage.vue', []],
] as const)('reports the exact comment lines in %s', (name, lines) => {
  const source = readFileSync(
    new URL(`./fixtures/${name}`, import.meta.url),
    'utf8',
  )
  const path = `${FIXTURES}/${name}`
  expect(checkSource(path, source)).toEqual(
    lines.map((line) => `COMMENT-LANGUAGE ${path}:${line} — ${MESSAGE}`),
  )
})

it('reads a direct Angular template with a CRLF line continuation', () => {
  const name = 'badCommentLanguageContinuation.ts'
  const path = `${FIXTURES}/${name}`
  const source = readFileSync(
    new URL(`./fixtures/${name}`, import.meta.url),
    'utf8',
  )
  expect(checkSource(path, source.replace(/\r?\n/g, '\r\n'))).toEqual([])
})
