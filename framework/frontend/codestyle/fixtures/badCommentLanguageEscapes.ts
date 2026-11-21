import { Component } from '@angular/core'

@Component({
  template: `\x3c!-- Комментарий с экранированным началом -->
    \x3c!-- Комментарий с двумя экранированными границами --\x3e
    \x3c!-- \u0410 --\x3e
    \x3c!-- Незавершённый комментарий`,
})
export class Panel {}
