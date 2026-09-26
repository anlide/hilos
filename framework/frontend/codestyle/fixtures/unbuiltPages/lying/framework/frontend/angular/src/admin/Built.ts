import { Component } from '@angular/core'
import { HilosPages } from '@hilos/core'
@Component({
  template: `<!-- <hilos-admin-page [page]="missing">Not a view</hilos-admin-page> -->
    <hilos-admin-page [page]="page"><p>Built</p></hilos-admin-page>`,
})
export class Built {
  protected readonly page = HilosPages.BUILT
  protected readonly missing = HilosPages.MISSING
}
