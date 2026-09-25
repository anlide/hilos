import {
  ChangeDetectionStrategy,
  Component,
  effect,
  input,
  signal,
} from '@angular/core'
import { HILOS_STEP_UP_COPY, subscribeSignal } from '@hilos/core'
import type {
  HilosStepUpOpening,
  HilosStepUpStep as HilosStepUpController,
} from '@hilos/core'

import { HilosFormError } from '../HilosFormError.js'

@Component({
  selector: 'hilos-step-up-step',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosFormError],
  template: `
    <div data-id="step-up">
      <p class="small text-body-secondary" data-id="step-up-text">
        {{ text() }}
      </p>
      @if (isCodeMethod()) {
        <label class="form-label" for="hilos-step-up-code">Code</label>
        <input
          id="hilos-step-up-code"
          class="form-control"
          autocomplete="one-time-code"
          inputmode="numeric"
          data-id="step-up-code"
          data-autofocus
          [value]="code()"
          (input)="setCode($event)"
        />
        @if (opening()?.method === 'second_factor') {
          <div class="form-text">
            {{ copy.codeHint }}
            <button
              type="button"
              class="btn btn-sm btn-link p-0 ms-1"
              data-id="step-up-backup-toggle"
              (click)="toggleBackup()"
            >
              {{ backupCode() ? copy.useApp : copy.useBackup }}
            </button>
          </div>
        }
      } @else if (opening()?.method === 'password') {
        <label class="form-label" for="hilos-step-up-password">Password</label>
        <input
          id="hilos-step-up-password"
          class="form-control"
          type="password"
          autocomplete="current-password"
          data-id="step-up-password"
          data-autofocus
          [value]="password()"
          (input)="setPassword($event)"
        />
      }
      <hilos-form-error [message]="refusal()" dataId="step-up-error" />
    </div>
  `,
})
export class HilosStepUpStep {
  readonly controller = input.required<HilosStepUpController>()
  protected readonly copy = HILOS_STEP_UP_COPY
  protected readonly opening = signal<HilosStepUpOpening | null>(null)
  protected readonly code = signal('')
  protected readonly password = signal('')
  protected readonly backupCode = signal(false)
  protected readonly refusal = signal<string | null>(null)

  constructor() {
    effect((onCleanup) => {
      const controller = this.controller()
      this.opening.set(controller.opening.get())
      this.code.set(controller.code.get())
      this.password.set(controller.password.get())
      this.backupCode.set(controller.backupCode.get())
      this.refusal.set(controller.refusal.get())
      const off = [
        subscribeSignal(controller.opening, (value) => this.opening.set(value)),
        subscribeSignal(controller.code, (value) => this.code.set(value)),
        subscribeSignal(controller.password, (value) =>
          this.password.set(value),
        ),
        subscribeSignal(controller.backupCode, (value) =>
          this.backupCode.set(value),
        ),
        subscribeSignal(controller.refusal, (value) => this.refusal.set(value)),
      ]
      onCleanup(() => off.forEach((unsubscribe) => unsubscribe()))
    })
  }

  protected isCodeMethod(): boolean {
    return ['second_factor', 'email_code', 'sms_code'].includes(
      this.opening()?.method ?? '',
    )
  }

  protected text(): string {
    const opening = this.opening()
    const template =
      opening?.method === 'second_factor'
        ? this.copy.secondFactor
        : opening?.method === 'password'
          ? this.copy.password
          : opening?.method === 'passkey'
            ? this.copy.passkey
            : opening
              ? this.copy.code
              : ''
    return template
      .replace('{purpose}', opening?.purpose ?? '')
      .replace('{destination}', opening?.destination ?? '')
  }

  protected setCode(event: Event): void {
    this.controller().code.set((event.target as HTMLInputElement).value)
  }

  protected setPassword(event: Event): void {
    this.controller().password.set((event.target as HTMLInputElement).value)
  }

  protected toggleBackup(): void {
    this.controller().backupCode.set(!this.backupCode())
  }
}
