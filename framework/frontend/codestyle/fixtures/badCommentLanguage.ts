// Первая строка
export const value = 1 /* вторая */ /* ещё один комментарий на той же строке */
/**
 * FIXME(F-1, 20.11.2026): старая метка
 * Следующая строка не освобождена.
 */
export const component = {
  template: `<p><!-- Заголовок --></p>`,
  details: `<!-- До подстановки
    ${'данные'}
    после подстановки -->`,
}
// FIXME(F-2, 2026-11-21): новая метка
// FIXME(F-3, 31.02.2025): неверная дата
// FIXME(F-4, yesterday): дата не читается
export const inline = `<p title="a > b ${
  value + '"> <!-- Данные -->'
} <!-- FIXME(F-1, 2026-11-20)"> <!-- Комментарий после атрибута --></p>`

import { Component } from '@angular/core'

@Component({
  // prettier-ignore
  'template': `@if (count<limit) {
  <!-- Комментарий внутри условия -->
  <p [title]="'<!-- Данные -->'">${'<!-- Данные подстановки -->'}</p>
  @if (hint === '<!-- Данные условия -->') {
    <iframe><!-- Комментарий во фрейме --></iframe>
  }
}`,
})
export class InlinePanel {}

export const unquoted = `<a href=${value} title="a > b <!-- Данные -->">
  <!-- После ссылки -->
</a>`
export const joined = `<a href=before${value}${
  /* Комментарий TS внутри подстановки */
  `<!-- Данные из выражения ${value} -->
   <!-- FIXME(F-1, 2026-11-20) Метка в данных -->`
}after title="a > b <!-- Данные атрибута -->">
  <!-- ${'FIXME(F-1, 2026-11-20)'}Соседний комментарий${value} -->
</a>`

export const quoted = `<a title="${`<!-- Данные -->`}">
  <!-- Комментарий после вложенного литерала -->
</a>`

@Component({
  // prettier-ignore
  template: `<p title=\x22a > b <!-- Данные -->\x22></p>
    <!-- Комментарий после экранированного атрибута -->`,
})
export class EscapedPanel {}

export const length = `${(() => {
  const markup = `<p><!-- Локальный комментарий --></p>`
  return markup.length
})()}`

@Component({
  // prettier-ignore
  template: `<!-- Комментарий с экранированным концом --\x3e
    <!-- Соседний комментарий -->`,
})
export class EscapedEndPanel {}
