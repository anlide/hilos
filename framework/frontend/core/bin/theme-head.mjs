import { error, log } from 'node:console'
import process from 'node:process'
import {
  findDeferredStylesheet,
  insertThemeHeadScript,
} from '../dist/theme/themeHeadScript.js'
import { sweepThemeHead } from './themeHeadSweep.mjs'

if (process.argv.length !== 3) {
  error('usage: node node_modules/@hilos/core/bin/theme-head.mjs <dir>')
  process.exitCode = 2
} else {
  const dir = process.argv[2]
  const { pages, refusals } = sweepThemeHead(
    dir,
    insertThemeHeadScript,
    findDeferredStylesheet,
  )
  log(`theme-head: ${pages} pages in ${dir}`)
  for (const refusal of refusals) error(refusal)
  if (refusals.some((refusal) => refusal.includes('deferred stylesheet'))) {
    error(
      'the first frame cannot wear the theme: turn off critical CSS inlining (Angular: optimization.styles.inlineCritical = false)',
    )
  }
  if (refusals.length > 0) process.exitCode = 1
}
