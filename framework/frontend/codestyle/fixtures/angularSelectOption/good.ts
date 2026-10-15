import { Component } from '@angular/core'
export const ordinary =
  '<select [value]="bad"><option [value]="bad"></option></select>'
// @Component({ template: '<select [value]="bad"></select>' })
/* @Component({ template: '<option [value]="bad"></option>' }) */
@Component({
  template: `
    <select>
      <option value="" [selected]="choice() === ''">Choose</option>
      @for (item of items(); track item) {
        <option [value]="item" [selected]="choice() === item">
          {{ item }}
        </option>
      }
    </select>
    <!-- <option [value]="comment"></option> -->
  `,
})
export class Good {}
