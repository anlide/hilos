import { Directive, TemplateRef, inject } from '@angular/core'

/** The page body whose view is created and destroyed by the application shell. */
@Directive({ selector: 'ng-template[hilosPage]' })
export class HilosPageTemplate {
  /** The page template, retaining the bindings and injector of its declaration. */
  readonly template = inject<TemplateRef<unknown>>(TemplateRef)
}
