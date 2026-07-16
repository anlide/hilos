// injectIntoTemplate wraps the rendered body in the app mount point and replaces
// the document title, leaving the rest of the client template untouched.
import { expect, it } from 'vitest'

import { injectIntoTemplate } from '../src/template.js'

const TEMPLATE =
  '<!doctype html><html><head><title>App</title></head>' +
  '<body><div id="app"></div></body></html>'

it('wraps the body in the app mount point', () => {
  const html = injectIntoTemplate(TEMPLATE, '<h1>About</h1>', 'About')

  expect(html).toContain('<div id="app"><h1>About</h1></div>')
})

it('replaces the document title', () => {
  const html = injectIntoTemplate(TEMPLATE, '', 'About · Demo')

  expect(html).toContain('<title>About · Demo</title>')
  expect(html).not.toContain('<title>App</title>')
})
