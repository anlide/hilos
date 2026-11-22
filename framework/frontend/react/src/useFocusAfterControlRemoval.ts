import { useCallback, useLayoutEffect, useRef } from 'react'
import { focusInitial } from '@hilos/core/base'

/**
 * Keep focus in the containing dialog when a conditional button disappears.
 * React releases the ref while the button still owns focus. After the commit,
 * the dialog's initial focus target reflects the current disabled state.
 *
 * @param visible Whether the button is rendered.
 * @returns The callback ref for the conditional button.
 */
export function useFocusAfterControlRemoval(visible: boolean) {
  const leftDialog = useRef<HTMLElement | null>(null)
  const buttonRef = useCallback((button: HTMLButtonElement | null) => {
    if (!button) return

    return () => {
      leftDialog.current =
        button.ownerDocument.activeElement === button
          ? button.closest<HTMLElement>('[role="dialog"]')
          : null
    }
  }, [])

  useLayoutEffect(() => {
    const dialog = leftDialog.current
    leftDialog.current = null
    if (visible || !dialog?.isConnected) return
    const { activeElement, body } = dialog.ownerDocument
    if (activeElement === null || activeElement === body) focusInitial(dialog)
  }, [visible])

  return buttonRef
}
