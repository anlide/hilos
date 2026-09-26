import { Component } from '@angular/core'
import { HilosPages } from '@hilos/core'
@Component({
  template: `<!-- <hilos-admin-page [page]="missing">Not a view</hilos-admin-page> -->
    <h1>Dashboard</h1>`,
})
export class Dashboard {
  protected readonly page = HilosPages.DASHBOARD
  protected readonly missing = HilosPages.MISSING
}
