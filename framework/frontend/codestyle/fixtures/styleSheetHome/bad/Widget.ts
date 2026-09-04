// Deliberately broken sample: an Angular component declaring styles of its own,
// in both spellings — inline and by URL — so STYLE-SHEET-HOME must report each
// property on its own line.

/** Stand-in for Angular's decorator: the fixture tree depends on nothing. */
declare function Component(definition: object): ClassDecorator

@Component({
  selector: 'hilos-widget',
  template: '<div class="widget"></div>',
  styles: ['.widget { padding: 1rem; }'],
  styleUrls: ['./widget.css'],
})
export class Widget {}
