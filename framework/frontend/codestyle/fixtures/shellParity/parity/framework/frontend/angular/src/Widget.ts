import { Component } from '@angular/core'

@Component({
  template: `
    <div data-id="shared-static"></div>
    <div [attr.data-id]="'shared-dynamic-' + row.id"></div>
    <div [attr.data-id]="'shared-long-' + row.id"></div>
    <div [attr.data-id]="dataId"></div>
    <div [attr.data-id]="dataId + '-slot'"></div>
    <hilos-child dataId="shared-prop"></hilos-child>
    <hilos-child [dataId]="'shared-prop-dynamic-' + row.id"></hilos-child>
  `,
})
export class Widget {}
