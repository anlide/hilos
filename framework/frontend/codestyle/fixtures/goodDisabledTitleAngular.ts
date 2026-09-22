// The look-alikes in an Angular component template: a title that repeats the name
// as written, the same name as a static value on one side and a quoted binding on
// the other, a title on an element that cannot be disabled, a disabled control
// with a name and no title, and a shared component taking the name as an input
// spelled like the attribute it carries. DISABLED-TITLE must stay silent on every
// one of them.

/** Stand-in for Angular's decorator: the fixture depends on nothing. */
declare function Component(definition: object): ClassDecorator

@Component({
  selector: 'hilos-row',
  template: `
    <div class="d-flex gap-2">
      <button
        type="button"
        [disabled]="busy"
        title="Delete backup"
        aria-label="Delete backup"
      ></button>
      <button
        type="button"
        [disabled]="busy"
        [title]="'Send the code via ' + label"
        [attr.aria-label]="'Send the code via ' + label"
      ></button>
      <button
        type="button"
        disabled
        [title]="'Close'"
        aria-label="Close"
      ></button>
      <span [title]="shipError">failed</span>
      <button
        type="button"
        [disabled]="busy"
        aria-label="Restore this backup"
      ></button>
      <hilos-switch
        [disabled]="busy"
        [attr.title]="'Unpin from rotation'"
        [aria-label]="'Unpin from rotation'"
      ></hilos-switch>
    </div>
  `,
})
export class Row {
  busy = false
  label = 'SMS'
  shipError = 'ssh: connect timed out'
}
