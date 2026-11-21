// Punctuation in English — an arrow → and a middle dot ·.
export const label = 'FIXME(F-1, 2026-11-21): это данные'
export const plain = `Русский текст /* данные */`
export const tail = `${label} // это часть строки`
export const template = `<!-- ${'только данные'} -->`
export const lookalike = '<!-- строка -->'
// FIXME(F-2, 20.11.2026): старая метка
/* FIXME(F-3, 2024-02-29): ещё одна старая метка */
export const attribute = `<p title="<!-- Подпись -->"></p>`
export const conditional = `@if (label === '<!-- Подпись -->') {
  <p>{{ label }}</p>
}`

import * as angular from '@angular/core'

@angular.Component({ template: conditional })
export class InlinePanel {}
