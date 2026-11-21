export const length = `${(() => {
  class Card {
    static markup = `<p><!-- Комментарий --></p>`
  }
  const markup = `<p><!-- Локальный комментарий --></p>`
  return Card.markup.length + markup.length
})()}${`<!-- Данные -->`}`
