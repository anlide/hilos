export const view = (
  <div>
    {/* Комментарий JSX */}
    // Текст JSX
  </div>
)
export const markup = `${view}<!-- Комментарий после подстановки -->`

export const declaration = `<p>@let hint = <!-- Комментарий -->;</p>`
export const interpolation = `<p>{{ '<!-- Комментарий HTML -->' }}</p>`

const Component = (metadata: { template: string }) => () => {
  void metadata
}

@Component({ template: `<iframe><!-- Данные HTML --></iframe>` })
export class OtherPanel {}
