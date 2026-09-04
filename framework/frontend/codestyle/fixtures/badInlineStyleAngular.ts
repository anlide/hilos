// Deliberately broken sample: an Angular component whose inline template carries
// every spelling the framework offers — the static attribute, the per-property
// binding with and without a unit, the whole-map binding, and the directive
// whose argument cannot be read. STYLE-INLINE must report each on its own line.

/** Stand-in for Angular's decorator: the fixture depends on nothing. */
declare function Component(definition: object): ClassDecorator

@Component({
  selector: 'hilos-widget',
  template: `
    <div class="card" style="max-width: 24rem">
      <div class="progress-bar" [style.width.%]="percent"></div>
      <div class="progress-bar" [style.height]="height"></div>
      <span [style]="{ color: accent }"></span>
      <span [ngStyle]="spacing"></span>
      <span ngStyle="padding: 0"></span>
    </div>
  `,
})
export class Widget {
  percent = 50
  height = '4px'
  accent = 'red'
  spacing = { padding: 0 }
}
