import { Component } from '@angular/core'

@Component({
  template: `
    <select
      class="form-select form-select-sm"
      data-id="multiline-select"
      [value]="choice()"
    >
      @for (item of items(); track item) {
        <option
          data-id="multiline-option-with-a-static-selected-attribute"
          [value]="item"
          selected
        >
          {{ item }}
        </option>
      }
    </select>
    @if (ready()) {
      <select bind-value="choice()">
        <option bind-value="other">Other</option>
      </select>
    }
  `,
})
export class Bad {}
