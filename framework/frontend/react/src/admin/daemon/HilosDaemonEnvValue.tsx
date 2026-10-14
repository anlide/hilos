// Value presentation consumes only the core's masked display model.
import type { HilosDaemonEnvValueView } from '@hilos/core'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'

/** @param props The permitted value presentation and missing-value emphasis. */
export function HilosDaemonEnvValue({
  view,
  required = false,
}: {
  view: HilosDaemonEnvValueView
  required?: boolean
}) {
  switch (view.kind) {
    case 'hidden':
      return <HilosHiddenMark />
    case 'secret':
      return (
        <span className="text-body-secondary d-inline-flex align-items-center gap-1">
          <i className="bi bi-key" aria-hidden="true" />
          {view.text}
        </span>
      )
    case 'none':
      return (
        <span
          className={required ? 'text-danger-emphasis' : 'text-body-secondary'}
        >
          —
        </span>
      )
    case 'empty':
      return (
        <span
          className="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle flex-shrink-0"
          title="An empty string is a value"
        >
          empty
        </span>
      )
    case 'boolean':
      return (
        <input
          type="checkbox"
          className="form-check-input mt-0 flex-shrink-0"
          disabled
          tabIndex={-1}
          checked={view.on}
          aria-label={view.on ? 'On' : 'Off'}
        />
      )
    case 'text':
      return (
        <code className={`text-break ${view.numeric ? 'fst-italic' : ''}`}>
          {view.text}
        </code>
      )
  }
}
