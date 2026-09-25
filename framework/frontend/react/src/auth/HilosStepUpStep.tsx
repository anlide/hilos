import { HILOS_STEP_UP_COPY } from '@hilos/core'
import type { HilosStepUpStep as HilosStepUpController } from '@hilos/core'

import { HilosFormError } from '../HilosFormError.js'
import { useSignal } from '../useSignal.js'

export interface HilosStepUpStepProps {
  controller: HilosStepUpController
}

export function HilosStepUpStep({ controller }: HilosStepUpStepProps) {
  const opening = useSignal(controller.opening)
  const code = useSignal(controller.code)
  const password = useSignal(controller.password)
  const backupCode = useSignal(controller.backupCode)
  const refusal = useSignal(controller.refusal)
  const copy = (template: string) =>
    template
      .replace('{purpose}', opening?.purpose ?? '')
      .replace('{destination}', opening?.destination ?? '')

  const codeMethod =
    opening?.method === 'second_factor' ||
    opening?.method === 'email_code' ||
    opening?.method === 'sms_code'

  return (
    <div data-id="step-up">
      <p className="small text-body-secondary" data-id="step-up-text">
        {opening?.method === 'second_factor'
          ? copy(HILOS_STEP_UP_COPY.secondFactor)
          : opening?.method === 'password'
            ? copy(HILOS_STEP_UP_COPY.password)
            : opening?.method === 'passkey'
              ? copy(HILOS_STEP_UP_COPY.passkey)
              : opening
                ? copy(HILOS_STEP_UP_COPY.code)
                : ''}
      </p>
      {codeMethod ? (
        <>
          <label className="form-label" htmlFor="hilos-step-up-code">
            Code
          </label>
          <input
            id="hilos-step-up-code"
            className="form-control"
            autoComplete="one-time-code"
            inputMode="numeric"
            data-id="step-up-code"
            data-autofocus
            value={code}
            onChange={(event) => controller.code.set(event.target.value)}
          />
          {opening?.method === 'second_factor' ? (
            <div className="form-text">
              {HILOS_STEP_UP_COPY.codeHint}
              <button
                type="button"
                className="btn btn-sm btn-link p-0 ms-1"
                data-id="step-up-backup-toggle"
                onClick={() => controller.backupCode.set(!backupCode)}
              >
                {backupCode
                  ? HILOS_STEP_UP_COPY.useApp
                  : HILOS_STEP_UP_COPY.useBackup}
              </button>
            </div>
          ) : null}
        </>
      ) : opening?.method === 'password' ? (
        <>
          <label className="form-label" htmlFor="hilos-step-up-password">
            Password
          </label>
          <input
            id="hilos-step-up-password"
            className="form-control"
            type="password"
            autoComplete="current-password"
            data-id="step-up-password"
            data-autofocus
            value={password}
            onChange={(event) => controller.password.set(event.target.value)}
          />
        </>
      ) : null}
      <HilosFormError message={refusal} dataId="step-up-error" />
    </div>
  )
}
