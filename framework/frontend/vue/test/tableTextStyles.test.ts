import { compile } from 'sass-embedded'
import { expect, it } from 'vitest'
import { hilosTableTextClass } from '@hilos/core/base'

const LINE_COUNTS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] as const
const HEIGHT_PRESETS = [
  { rem: 2, token: '2' },
  { rem: 3, token: '3' },
  { rem: 4.5, token: '4-5' },
  { rem: 6, token: '6' },
  { rem: 9, token: '9' },
  { rem: 12, token: '12' },
] as const
const FRONTEND_ROOT = (
  globalThis as typeof globalThis & { process: { cwd: () => string } }
).process.cwd()
const SASS_DELIVERIES = [
  `${FRONTEND_ROOT}/vue/src/hilos-styles.scss`,
  `${FRONTEND_ROOT}/react/src/hilos-styles.scss`,
  `${FRONTEND_ROOT}/angular/src/hilos-styles.scss`,
]

/** Read every compiled rule owned by the table text catalog. */
function compiledTextRules(css: string): string[] {
  const rules: string[] = []
  const rulePattern = /([^{}]+)\{([^{}]*)\}/g

  for (const match of css.matchAll(rulePattern)) {
    const selectorList = match[1]
    const declarations = match[2]
    if (selectorList === undefined || declarations === undefined) {
      continue
    }

    const normalizedDeclarations = declarations
      .split(';')
      .map((declaration) => declaration.trim().replace(/\s+/g, ' '))
      .filter(Boolean)
      .join(';')

    for (const selector of selectorList.split(',').map((part) => part.trim())) {
      if (selector.includes('.hilos-table-text')) {
        rules.push(`${selector}{${normalizedDeclarations}}`)
      }
    }
  }

  return rules.sort()
}

/** Describe the approved CSS declarations for every catalog selector. */
function expectedTextRules(): string[] {
  const rules = [
    '.hilos-table-text{white-space: pre-wrap;overflow-wrap: anywhere}',
    `.hilos-table-text-lines-1{block-size: 1lh;overflow: hidden}`,
    `.hilos-table-text-lines-2{block-size: 2lh;overflow: hidden}`,
    `.hilos-table-text-lines-3{block-size: 3lh;overflow: hidden}`,
    `.hilos-table-text-lines-4{block-size: 4lh;overflow: hidden}`,
    `.hilos-table-text-lines-5{block-size: 5lh;overflow: hidden}`,
    `.hilos-table-text-lines-6{block-size: 6lh;overflow: hidden}`,
    `.hilos-table-text-lines-7{block-size: 7lh;overflow: hidden}`,
    `.hilos-table-text-lines-8{block-size: 8lh;overflow: hidden}`,
    `.hilos-table-text-lines-9{block-size: 9lh;overflow: hidden}`,
    `.hilos-table-text-lines-10{block-size: 10lh;overflow: hidden}`,
    `.hilos-table-text-lines-11{block-size: 11lh;overflow: hidden}`,
    `.hilos-table-text-lines-12{block-size: 12lh;overflow: hidden}`,
    `.hilos-table-text-height-2{block-size: 2rem;overflow: hidden}`,
    `.hilos-table-text-height-3{block-size: 3rem;overflow: hidden}`,
    `.hilos-table-text-height-4-5{block-size: 4.5rem;overflow: hidden}`,
    `.hilos-table-text-height-6{block-size: 6rem;overflow: hidden}`,
    `.hilos-table-text-height-9{block-size: 9rem;overflow: hidden}`,
    `.hilos-table-text-height-12{block-size: 12rem;overflow: hidden}`,
    `.hilos-table-text-lines-1.is-open{block-size: auto;min-block-size: 1lh}`,
    `.hilos-table-text-lines-2.is-open{block-size: auto;min-block-size: 2lh}`,
    `.hilos-table-text-lines-3.is-open{block-size: auto;min-block-size: 3lh}`,
    `.hilos-table-text-lines-4.is-open{block-size: auto;min-block-size: 4lh}`,
    `.hilos-table-text-lines-5.is-open{block-size: auto;min-block-size: 5lh}`,
    `.hilos-table-text-lines-6.is-open{block-size: auto;min-block-size: 6lh}`,
    `.hilos-table-text-lines-7.is-open{block-size: auto;min-block-size: 7lh}`,
    `.hilos-table-text-lines-8.is-open{block-size: auto;min-block-size: 8lh}`,
    `.hilos-table-text-lines-9.is-open{block-size: auto;min-block-size: 9lh}`,
    `.hilos-table-text-lines-10.is-open{block-size: auto;min-block-size: 10lh}`,
    `.hilos-table-text-lines-11.is-open{block-size: auto;min-block-size: 11lh}`,
    `.hilos-table-text-lines-12.is-open{block-size: auto;min-block-size: 12lh}`,
    `.hilos-table-text-height-2.is-open{block-size: auto;min-block-size: 2rem}`,
    `.hilos-table-text-height-3.is-open{block-size: auto;min-block-size: 3rem}`,
    `.hilos-table-text-height-4-5.is-open{block-size: auto;min-block-size: 4.5rem}`,
    `.hilos-table-text-height-6.is-open{block-size: auto;min-block-size: 6rem}`,
    `.hilos-table-text-height-9.is-open{block-size: auto;min-block-size: 9rem}`,
    `.hilos-table-text-height-12.is-open{block-size: auto;min-block-size: 12rem}`,
  ]

  return rules.sort()
}

it('compiles the complete table text catalog identically in all Sass deliveries', () => {
  expect(hilosTableTextClass({ mode: 'content' })).toBe('hilos-table-text')
  for (const count of LINE_COUNTS) {
    expect(hilosTableTextClass({ mode: 'lines', count })).toBe(
      `hilos-table-text hilos-table-text-lines-${count}`,
    )
  }
  for (const { rem, token } of HEIGHT_PRESETS) {
    expect(hilosTableTextClass({ mode: 'height', rem })).toBe(
      `hilos-table-text hilos-table-text-height-${token}`,
    )
  }

  const catalogs = SASS_DELIVERIES.map((delivery) =>
    compiledTextRules(compile(delivery, { style: 'expanded' }).css),
  )
  const expected = expectedTextRules()

  expect(catalogs[0]).toEqual(expected)
  expect(catalogs[1]).toEqual(catalogs[0])
  expect(catalogs[2]).toEqual(catalogs[0])
})
