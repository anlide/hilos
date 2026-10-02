// HilosHideable — a value a viewer of the admin view mode may be sent hidden
// (Hideable<T>, @hilos/core): the hidden one is drawn as HilosHiddenMark, any
// other goes to the projected ng-template (its implicit `let-value`, the way a
// table hands a cell template its row), so the template draws the value the way
// the screen always has and never meets the mark. With no template the value is
// printed as text. The page reads the field as hideable and hands it here as it
// is; nothing on the screen tests for the mark itself. Unlike Vue's typed slot,
// the template's `let-value` is not typed T for the page: a template found by
// contentChild carries no context guard, so Angular types it `any`, and a
// helper the template calls states the type it takes.
import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  TemplateRef,
  computed,
  contentChild,
  input,
} from '@angular/core'
import { type Hideable, isHiddenValue } from '@hilos/core'

import { HilosHiddenMark } from './HilosHiddenMark.js'

/** The context the projected template of a {@link HilosHideable} receives. */
export interface HilosHideableContext<T> {
  /** The value when it is not hidden, narrowed to its own type (`let-value`). */
  $implicit: T
}

/**
 * Draw a value a viewer of the admin view mode may be sent hidden: the mark in
 * its place when it is hidden, otherwise the projected template (or the value
 * as text, with no template):
 * `<hilos-hideable [value]="row.email"><ng-template let-email>…</ng-template></hilos-hideable>`.
 */
@Component({
  selector: 'hilos-hideable',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosHiddenMark, NgTemplateOutlet],
  // The fallback text sits flush in its container: the whitespace of an indented
  // template would be printed around the value as a space on each side, which
  // Vue's slot fallback does not print.
  template: `
    @if (shown(); as shown) {
      @if (template(); as template) {
        <ng-container
          [ngTemplateOutlet]="template"
          [ngTemplateOutletContext]="{ $implicit: shown.value }"
        />
      } @else {
        <ng-container>{{ shown.value }}</ng-container>
      }
    } @else {
      <hilos-hidden-mark />
    }
  `,
})
export class HilosHideable<T> {
  /** The value, or the hidden mark the server sent in its place. */
  readonly value = input.required<Hideable<T>>()

  protected readonly template =
    contentChild<TemplateRef<HilosHideableContext<T>>>(TemplateRef)
  // Boxed so a falsy value (0, '', false) still takes the shown branch.
  protected readonly shown = computed((): { value: T } | undefined => {
    const value = this.value()

    return isHiddenValue(value) ? undefined : { value }
  })
}
