import { mount } from '@vue/test-utils'
import { expect, it } from 'vitest'
import { clause } from '../../../core/test/legal/fixtures.js'
import HilosLegalRevisionText from './HilosLegalRevisionText.vue'

it('numbers clauses, preserves paragraphs and marks only project deviations', () => {
  const view = mount(HilosLegalRevisionText, {
    props: {
      clauses: [
        clause,
        { ...clause, clauseKey: 'plain', source: 'standard', direction: null },
      ],
    },
  })
  expect(view.get('ol').findAll('li')).toHaveLength(2)
  expect(
    view.findAll('[data-id="legal-revision-clause-deviation"]'),
  ).toHaveLength(1)
  expect(
    view.get('[data-id="legal-revision-clause-deviation"]').text(),
  ).toContain('Project deviation from: Standard retention')
  expect(view.findAll('p')).toHaveLength(4)
})
