// Deliberately broken sample: four mounts MODAL-FOCUS must report, in an
// Angular component template — no declaration, an expression, an unknown
// value, and a self-closing mount with no prop. Each report sits on the
// opening tag.

/** Stand-in for Angular's decorator: the fixture depends on nothing. */
declare function Component(definition: object): ClassDecorator

@Component({
  selector: 'hilos-row',
  template: `
    <hilos-modal>
      <p>nothing to fill</p>
    </hilos-modal>
    <hilos-modal [initialFocus]="where">
      <p>bound</p>
    </hilos-modal>
    <hilos-modal initialFocus="first">
      <p>unknown</p>
    </hilos-modal>
    <hilos-modal />
  `,
})
export class Row {
  where = 'dialog'
}
