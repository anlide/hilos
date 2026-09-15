// The look-alikes in an Angular component template: a mark in mutually
// exclusive branches, a dialog with nothing to fill, a body drawn by another
// component, and a nested mount that names its own landing while only the
// outer one is marked. MODAL-FOCUS must stay silent on every one of them.

/** Stand-in for Angular's decorator: the fixture depends on nothing. */
declare function Component(definition: object): ClassDecorator

@Component({
  selector: 'hilos-row',
  template: `
    <hilos-modal>
      <input *ngIf="step === 1" data-autofocus />
      <input *ngIf="step !== 1" data-autofocus />
    </hilos-modal>
    <hilos-modal initialFocus="dialog">
      <p>nothing to fill</p>
    </hilos-modal>
    <hilos-modal initialFocus="inner">
      <ng-container *ngComponentOutlet="body" />
    </hilos-modal>
    <hilos-modal>
      <input data-autofocus />
      <hilos-modal initialFocus="dialog">
        <p>inner layer</p>
      </hilos-modal>
    </hilos-modal>
  `,
})
export class Row {
  step = 1
  body = class Inner {}
}
