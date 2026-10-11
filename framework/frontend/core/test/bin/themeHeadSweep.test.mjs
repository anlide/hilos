import {
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  statSync,
  utimesSync,
  writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, beforeEach, expect, it } from 'vitest'
import { sweepThemeHead } from '../../bin/themeHeadSweep.mjs'
import {
  findDeferredStylesheet,
  insertThemeHeadScript,
} from '../../src/theme/themeHeadScript.js'

let dir

beforeEach(() => {
  dir = mkdtempSync(join(tmpdir(), 'hilos-theme-head-'))
})

afterEach(() => {
  rmSync(dir, { recursive: true, force: true })
})

function page(path, head = '') {
  const full = join(dir, path)
  mkdirSync(join(full, '..'), { recursive: true })
  writeFileSync(full, `<html><head>${head}</head><body></body></html>`)

  return full
}

function sweep(path = dir) {
  return sweepThemeHead(path, insertThemeHeadScript, findDeferredStylesheet)
}

it('visits nested HTML pages and leaves identical output untouched on repeat', () => {
  const files = [
    page('index.html'),
    page('about.html'),
    page('about/index.html'),
    page('index.csr.html'),
  ]
  expect(sweep()).toEqual({ pages: 4, refusals: [] })
  for (const file of files) {
    const html = readFileSync(file, 'utf8')
    expect(html.match(/data-hilos-theme-head/g)).toHaveLength(1)
    expect(html.indexOf('data-hilos-theme-head')).toBeLessThan(
      html.indexOf('</head>'),
    )
    utimesSync(file, 1_000_000, 1_000_000)
  }
  expect(sweep()).toEqual({ pages: 4, refusals: [] })
  for (const file of files) {
    expect(statSync(file).mtimeMs).toBe(1_000_000_000)
    expect(
      readFileSync(file, 'utf8').match(/data-hilos-theme-head/g),
    ).toHaveLength(1)
  }
})

it('names a page without a head close and a deferred style', () => {
  writeFileSync(join(dir, 'broken.html'), '<html><head>')
  page(
    'nested/late.html',
    `<link rel="stylesheet" media="print" onload="this.media='all'">`,
  )
  const result = sweep()
  expect(result.pages).toBe(2)
  expect(result.refusals).toEqual([
    'broken.html: Error: missing </head>',
    'nested/late.html: deferred stylesheet',
  ])
})

it('refuses an empty or missing directory', () => {
  expect(sweep()).toEqual({ pages: 0, refusals: ['.: no .html pages found'] })
  expect(sweep(join(dir, 'missing'))).toEqual({
    pages: 0,
    refusals: ['.: directory does not exist or is unreadable'],
  })
})
