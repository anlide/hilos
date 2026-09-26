import { Component } from '@angular/core'
import { HilosPages } from '@hilos/core'
@Component({
  template: `<!-- <hilos-admin-page [page]="missing">Not a view</hilos-admin-page> -->
    <hilos-admin-page [page]="page" />`,
})
export class Stub {
  protected readonly page = HilosPages.STUB
  protected readonly missing = HilosPages.MISSING
}
