import { Component } from '@angular/core'
import { HilosPages } from '@hilos/core'
@Component({
  template: `<!-- <hilos-admin-page [page]="missing">Not a view</hilos-admin-page> -->
    <hilos-setting-presets-page [page]="page" />`,
})
export class Presets {
  protected readonly page = HilosPages.PRESETS
  protected readonly missing = HilosPages.MISSING
}
