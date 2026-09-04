// The look-alikes: the one legal channel in the static and in the bound Angular
// spelling, and a binding whose name only reads like a style. STYLE-INLINE must
// stay silent on all of them.

/** Stand-in for Angular's decorator: the fixture depends on nothing. */
declare function Component(definition: object): ClassDecorator

@Component({
  selector: 'hilos-widget',
  template: `
    <div class="card" style="--hilos-gap: 0.25rem">
      <div class="progress-bar" [style.--hilos-progress]="percent"></div>
      <span [class.text-muted]="quiet" data-style="compact"></span>
    </div>
  `,
})
export class Widget {
  percent = 50
  quiet = true
}
