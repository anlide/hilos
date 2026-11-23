import { Component } from '@angular/core'
import {
  HilosModal as Dialog,
  HilosViewportTable as Grid,
} from '@hilos/angular/base'

@Component({
  imports: [Dialog, Grid],
  template: `
    <section>
      <div class="alert invisible" data-id="page-reserve"></div>
      <hilos-form-error />
      <ng-template #nameCell>
        @if (error) {
          <div class="alert alert-danger">{{ error }}</div>
        } @else {
          <div class="hilos-table-error-reserve" data-id="cell-reserve"></div>
        }
      </ng-template>
      <hilos-viewport-table>
        <ng-template hilosTableCell="name">
          <ng-container [ngTemplateOutlet]="nameCell" />
        </ng-template>
        <ng-template hilosTableCell="note">
          @if (error) {
            <div class="alert alert-danger">{{ error }}</div>
          }
          <div class="opacity-0" aria-hidden="true"></div>
          <i class="bi bi-snow invisible"></i>
          <span class="hilos-table-marks invisible">
            <span
              class="badge border border-warning-subtle text-warning-emphasis"
              >Will move</span
            >
          </span>
          <div class="hilos-table-work-space hilos-table-work-reserve"></div>
          <div class="hilos-table-record-space">Gone</div>
          @if (rowMessage) {
            <span data-id="hilos-table-row-message">{{ rowMessage }}</span>
          }
          <!-- <div class="alert invisible"></div> -->
          <hilos-modal>
            <div class="alert invisible" data-id="dialog-reserve"></div>
            <hilos-viewport-table>
              <ng-template #rowProgress>
                <div
                  role="alert"
                  class="border border-danger opacity-0"
                  data-id="nested-reserve"
                ></div>
              </ng-template>
            </hilos-viewport-table>
          </hilos-modal>
        </ng-template>
      </hilos-viewport-table>
    </section>
  `,
})
export class Screen {}
