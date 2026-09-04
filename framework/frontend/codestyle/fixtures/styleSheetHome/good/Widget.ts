// The look-alikes: an Angular component that declares no styles, and a plain
// configuration object that carries a `styles` key while being no decorator
// argument at all — STYLE-SHEET-HOME must stay silent on both.

/** Stand-in for Angular's decorator: the fixture tree depends on nothing. */
declare function Component(definition: object): ClassDecorator

/** A `styles` key outside a decorator: a value the rule has no business reading. */
export const chartOptions = { styles: ['solid', 'dashed'] }

@Component({
  selector: 'hilos-widget',
  template: '<div class="p-3 border rounded"></div>',
})
export class Widget {}
