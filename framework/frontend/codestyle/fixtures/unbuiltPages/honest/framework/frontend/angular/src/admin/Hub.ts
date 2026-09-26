import { Component } from '@angular/core'
import { HilosPages } from '@hilos/core'
@Component({
  template: `<!-- <hilos-admin-page [page]="missing">Not a view</hilos-admin-page> -->
    <hilos-admin-page [page]="page" />`,
})
export class Hub {
  protected readonly page = HilosPages.HUB
  protected readonly missing = HilosPages.MISSING
}
