// The public License page (HilosPages.LICENSE). A framework-declared static
// page; this project supplies the content. See views/about/about.ts.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosStaticPage } from '@hilos/angular'

@Component({
  selector: 'app-license',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosStaticPage],
  template: `<hilos-static-page title="License">
    <p>The Hilos framework is released under the MIT license.</p>
    <p>
      Permission is hereby granted, free of charge, to any person obtaining a
      copy of this software and associated documentation files, to deal in the
      software without restriction — including the rights to use, copy, modify,
      merge, publish, distribute, sublicense, and/or sell copies of it.
    </p>
    <p class="mb-0">
      The software is provided “as is”, without warranty of any kind.
    </p>
  </hilos-static-page>`,
})
export class License {}
