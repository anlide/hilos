import { Component } from '@angular/core'

@Component({
  template: `
    <div data-id="shared-static"></div>
    <div [attr.data-id]="'shared-dynamic-' + row.id"></div>
    <div [attr.data-id]="'shared-long-' + row.id"></div>
    <div [attr.data-id]="dataId"></div>
    <div [attr.data-id]="dataId + '-slot'"></div>
  `,
})
export class Widget {}
