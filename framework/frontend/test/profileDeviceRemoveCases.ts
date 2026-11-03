/**
 * Shared scenario suite for profile device removal confirmation across view frameworks.
 *
 * Exercises the component lifecycle, accessibility, rejection handling, and dismiss paths
 * of the device removal confirmation modal.
 */

import {
  ActionError,
  createSignal,
  PROFILE_DEVICE_REMOVE_ACTION,
  type HilosConnection,
  type HilosProfileDevice,
  type HilosProfileDeviceActions,
  type HilosProfilePushChannel,
  type HilosPushPermission,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'
import type { expect, it, vi } from 'vitest'

/** Mount options accepted by the view framework adapter. */
export interface ProfileDeviceMountProps {
  /** Visible devices. */
  readonly devices?: readonly HilosProfileDevice[]
  /** Commands exposed to the device rows. */
  readonly actions?: HilosProfileDeviceActions
  /** Connection used by the push toggle. */
  readonly connection?: HilosConnection
  /** Enabled push channel or null when unavailable. */
  readonly pushChannel?: HilosProfilePushChannel | null
  /** Push subscription store. */
  readonly pushStore?: HilosPushSubscriptionStore
}

/** Subject-specific view adapter returned by the framework-specific mount function. */
export interface ProfileDeviceViewAdapter {
  /** The element containing the mounted component and teleported modal dialogs. */
  readonly container: HTMLElement
  /** Update props of the mounted component. */
  update(props: Partial<ProfileDeviceMountProps>): Promise<void>
  /** Wait for reactive flush / tick / layout. */
  flush(): Promise<void>
  /** Unmount and cleanup the component. */
  unmount(): Promise<void>
}

/** Framework-specific mount adapter function. */
export type ProfileDeviceMountFn = (
  props?: Partial<ProfileDeviceMountProps>,
) => Promise<ProfileDeviceViewAdapter>

/** Test runner environment passed from each SDK's Vitest project. */
export interface ProfileDeviceCasesRunner {
  readonly it: typeof it
  readonly expect: typeof expect
  readonly vi?: typeof vi
}

/**
 * Register the shared device removal confirmation test cases with a framework-specific mount adapter.
 *
 * @param runner The Vitest runner instance providing it, expect, and optional vi mocks.
 * @param mount Framework-specific mount adapter creating a ProfileDeviceViewAdapter.
 */
export function registerProfileDeviceRemoveCases(
  runner: ProfileDeviceCasesRunner,
  mount: ProfileDeviceMountFn,
): void {
  runner.it(
    'disables the remove button and keeps the modal closed before push store is refreshed',
    async () => {
      const refreshed = createSignal(false)
      const pushStore: HilosPushSubscriptionStore = {
        supported: createSignal(true),
        permission: createSignal<HilosPushPermission>('granted'),
        subscribed: createSignal(false),
        endpointHash: createSignal<string | null>(null),
        refreshed,
        busy: createSignal(false),
        refresh: async () => {},
        enable: async () => true,
        disable: async () => true,
        clear: () => {},
      }
      const removeDevice = runner.vi?.fn()
      const view = await mount({
        devices: [
          {
            id: 1,
            deviceName: 'Mobile Safari on iPhone',
            createdAt: 1767227400000,
            current: false,
            expired: false,
          },
        ],
        actions: {
          removeDevice: (removeDevice ??
            (() => {})) as unknown as HilosProfileDeviceActions['removeDevice'],
        },
        pushStore,
      })
      try {
        const button = view.container.querySelector<HTMLButtonElement>(
          '[data-id="profile-device-remove"]',
        )
        runner.expect(button).not.toBeNull()
        runner
          .expect(button?.disabled || button?.hasAttribute('disabled'))
          .toBe(true)

        button?.click()
        await view.flush()

        runner
          .expect(view.container.querySelector('[role="dialog"]'))
          .toBeNull()
        if (removeDevice) {
          runner.expect(removeDevice).not.toHaveBeenCalled()
        }
      } finally {
        await view.unmount()
      }
    },
  )

  runner.it(
    'opens the confirmation dialog with the device name on row remove click without dispatching the action',
    async () => {
      const removeDevice = runner.vi?.fn()
      const view = await mount({
        devices: [
          {
            id: 42,
            deviceName: 'Firefox on Linux',
            createdAt: 1767227400000,
            current: false,
            expired: false,
          },
        ],
        actions: {
          removeDevice: (removeDevice ??
            (() => {})) as unknown as HilosProfileDeviceActions['removeDevice'],
        },
      })
      try {
        const button = view.container.querySelector<HTMLButtonElement>(
          '[data-id="profile-device-remove"]',
        )
        runner.expect(button).not.toBeNull()
        button?.click()
        await view.flush()

        const dialog =
          view.container.querySelector<HTMLElement>('[role="dialog"]')
        runner.expect(dialog).not.toBeNull()
        runner.expect(dialog?.textContent).toContain('Remove Firefox on Linux?')
        if (removeDevice) {
          runner.expect(removeDevice).not.toHaveBeenCalled()
        }
      } finally {
        await view.unmount()
      }
    },
  )

  runner.it(
    'places initial focus on the dialog and ignores Enter key on the dialog container',
    async () => {
      const removeDevice = runner.vi?.fn()
      const view = await mount({
        devices: [
          {
            id: 11,
            deviceName: 'Pixel 9',
            createdAt: 1767227400000,
            current: false,
            expired: false,
          },
        ],
        actions: {
          removeDevice: (removeDevice ??
            (() => {})) as unknown as HilosProfileDeviceActions['removeDevice'],
        },
      })
      try {
        const button = view.container.querySelector<HTMLButtonElement>(
          '[data-id="profile-device-remove"]',
        )
        button?.click()
        await view.flush()

        const dialog =
          view.container.querySelector<HTMLElement>('[role="dialog"]')
        runner.expect(dialog).not.toBeNull()
        runner.expect(document.activeElement).toBe(dialog)

        dialog?.dispatchEvent(
          new KeyboardEvent('keydown', {
            key: 'Enter',
            bubbles: true,
            cancelable: true,
          }),
        )
        await view.flush()

        if (removeDevice) {
          runner.expect(removeDevice).not.toHaveBeenCalled()
        }
        runner
          .expect(view.container.querySelector('[role="dialog"]'))
          .not.toBeNull()
      } finally {
        await view.unmount()
      }
    },
  )

  runner.it(
    'closes the modal via Cancel, close button, Escape, and backdrop click without dispatching',
    async () => {
      const removeDevice = runner.vi?.fn()
      const view = await mount({
        devices: [
          {
            id: 7,
            deviceName: 'Work Laptop',
            createdAt: 1767227400000,
            current: false,
            expired: false,
          },
        ],
        actions: {
          removeDevice: (removeDevice ??
            (() => {})) as unknown as HilosProfileDeviceActions['removeDevice'],
        },
      })
      try {
        const openModal = async () => {
          const button = view.container.querySelector<HTMLButtonElement>(
            '[data-id="profile-device-remove"]',
          )
          button?.click()
          await view.flush()
          runner
            .expect(view.container.querySelector('[role="dialog"]'))
            .not.toBeNull()
        }

        // Cancel button
        await openModal()
        const cancelButton = view.container.querySelector<HTMLButtonElement>(
          '.modal-footer .btn-secondary',
        )
        runner.expect(cancelButton).not.toBeNull()
        cancelButton?.click()
        await view.flush()
        runner
          .expect(view.container.querySelector('[role="dialog"]'))
          .toBeNull()

        // Close button (X)
        await openModal()
        const closeButton = view.container.querySelector<HTMLButtonElement>(
          '[data-id="modal-close"]',
        )
        runner.expect(closeButton).not.toBeNull()
        closeButton?.click()
        await view.flush()
        runner
          .expect(view.container.querySelector('[role="dialog"]'))
          .toBeNull()

        // Escape key
        await openModal()
        let dialog =
          view.container.querySelector<HTMLElement>('[role="dialog"]')
        dialog?.dispatchEvent(
          new KeyboardEvent('keydown', {
            key: 'Escape',
            bubbles: true,
            cancelable: true,
          }),
        )
        await view.flush()
        runner
          .expect(view.container.querySelector('[role="dialog"]'))
          .toBeNull()

        // Backdrop click
        await openModal()
        dialog = view.container.querySelector<HTMLElement>('[data-id="modal"]')
        dialog?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
        await view.flush()
        runner
          .expect(view.container.querySelector('[role="dialog"]'))
          .toBeNull()

        if (removeDevice) {
          runner.expect(removeDevice).not.toHaveBeenCalled()
        }
      } finally {
        await view.unmount()
      }
    },
  )

  runner.it(
    'confirms removal of strictly the selected device id when rows share the same name',
    async () => {
      let resolveAction!: (value: { reply: null }) => void
      const done = new Promise<{ reply: null }>((resolve) => {
        resolveAction = resolve
      })
      const removeDevice = runner.vi?.fn(() => ({
        requestId: 'req-remove',
        loading: createSignal(false),
        done,
      }))
      const view = await mount({
        devices: [
          {
            id: 101,
            deviceName: 'Matching Name Device',
            createdAt: 1767227400000,
            current: false,
            expired: false,
          },
          {
            id: 202,
            deviceName: 'Matching Name Device',
            createdAt: 1767227460000,
            current: false,
            expired: false,
          },
        ],
        actions: {
          removeDevice: (removeDevice ??
            (() => ({
              requestId: 'req',
              loading: createSignal(false),
              done,
            }))) as unknown as HilosProfileDeviceActions['removeDevice'],
        },
      })
      try {
        const removeButtons =
          view.container.querySelectorAll<HTMLButtonElement>(
            '[data-id="profile-device-remove"]',
          )
        runner.expect(removeButtons).toHaveLength(2)
        removeButtons[1]?.click()
        await view.flush()

        const confirmButton = view.container.querySelector<HTMLButtonElement>(
          '[data-id="profile-device-remove-confirm"]',
        )
        runner.expect(confirmButton).not.toBeNull()
        confirmButton?.click()
        await view.flush()

        if (removeDevice) {
          runner.expect(removeDevice).toHaveBeenCalledExactlyOnceWith(202)
        }
        resolveAction({ reply: null })
        await view.flush()
      } finally {
        await view.unmount()
      }
    },
  )

  runner.it(
    'locks the modal while removal is in flight and avoids optimistic row deletion',
    async () => {
      let resolveAction!: (value: { reply: null }) => void
      const done = new Promise<{ reply: null }>((resolve) => {
        resolveAction = resolve
      })
      const removeDevice = runner.vi?.fn(() => ({
        requestId: 'req-pending',
        loading: createSignal(true),
        done,
      }))
      const view = await mount({
        devices: [
          {
            id: 88,
            deviceName: 'Desktop Display',
            createdAt: 1767227400000,
            current: false,
            expired: false,
          },
        ],
        actions: {
          removeDevice: (removeDevice ??
            (() => ({
              requestId: 'req',
              loading: createSignal(true),
              done,
            }))) as unknown as HilosProfileDeviceActions['removeDevice'],
        },
      })
      try {
        const openButton = view.container.querySelector<HTMLButtonElement>(
          '[data-id="profile-device-remove"]',
        )
        openButton?.click()
        await view.flush()

        const confirmButton = view.container.querySelector<HTMLButtonElement>(
          '[data-id="profile-device-remove-confirm"]',
        )
        confirmButton?.click()
        await view.flush()

        if (removeDevice) {
          runner.expect(removeDevice).toHaveBeenCalledTimes(1)
        }
        runner.expect(confirmButton?.getAttribute('aria-busy')).toBe('true')

        // Repeated click is blocked
        confirmButton?.click()
        await view.flush()
        if (removeDevice) {
          runner.expect(removeDevice).toHaveBeenCalledTimes(1)
        }

        // Cancel and close are disabled
        const cancelButton = view.container.querySelector<HTMLButtonElement>(
          '.modal-footer .btn-secondary',
        )
        runner
          .expect(
            cancelButton?.disabled || cancelButton?.hasAttribute('disabled'),
          )
          .toBe(true)

        const closeButton = view.container.querySelector<HTMLButtonElement>(
          '[data-id="modal-close"]',
        )
        runner
          .expect(
            closeButton?.disabled || closeButton?.hasAttribute('disabled'),
          )
          .toBe(true)

        // Escape and backdrop do not dismiss
        const dialog =
          view.container.querySelector<HTMLElement>('[data-id="modal"]')
        dialog?.dispatchEvent(
          new KeyboardEvent('keydown', {
            key: 'Escape',
            bubbles: true,
            cancelable: true,
          }),
        )
        await view.flush()
        runner
          .expect(view.container.querySelector('[role="dialog"]'))
          .not.toBeNull()

        dialog?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
        await view.flush()
        runner
          .expect(view.container.querySelector('[role="dialog"]'))
          .not.toBeNull()

        // No optimistic deletion
        runner
          .expect(
            view.container.querySelectorAll('[data-id="profile-device-row"]'),
          )
          .toHaveLength(1)

        resolveAction({ reply: null })
        await view.flush()
      } finally {
        await view.unmount()
      }
    },
  )

  runner.it(
    'closes the modal on successful removal and only removes the row when props update',
    async () => {
      let resolveAction!: (value: { reply: null }) => void
      const done = new Promise<{ reply: null }>((resolve) => {
        resolveAction = resolve
      })
      const removeDevice = runner.vi?.fn(() => ({
        requestId: 'req-success',
        loading: createSignal(false),
        done,
      }))
      const view = await mount({
        devices: [
          {
            id: 55,
            deviceName: 'Office PC',
            createdAt: 1767227400000,
            current: false,
            expired: false,
          },
        ],
        actions: {
          removeDevice: (removeDevice ??
            (() => ({
              requestId: 'req',
              loading: createSignal(false),
              done,
            }))) as unknown as HilosProfileDeviceActions['removeDevice'],
        },
      })
      try {
        const openButton = view.container.querySelector<HTMLButtonElement>(
          '[data-id="profile-device-remove"]',
        )
        openButton?.click()
        await view.flush()

        const confirmButton = view.container.querySelector<HTMLButtonElement>(
          '[data-id="profile-device-remove-confirm"]',
        )
        confirmButton?.click()
        await view.flush()

        resolveAction({ reply: null })
        await view.flush()

        // Modal closed
        runner
          .expect(view.container.querySelector('[role="dialog"]'))
          .toBeNull()

        // Row is still present before server updates props
        runner
          .expect(
            view.container.querySelectorAll('[data-id="profile-device-row"]'),
          )
          .toHaveLength(1)

        // Server updates props
        await view.update({ devices: [] })
        await view.flush()

        runner
          .expect(
            view.container.querySelectorAll('[data-id="profile-device-row"]'),
          )
          .toHaveLength(0)
      } finally {
        await view.unmount()
      }
    },
  )

  runner.it(
    'keeps modal open on failure, shows error message, supports retry, and clears error on reopening',
    async () => {
      let rejectAction!: (reason: unknown) => void
      let done = new Promise<{ reply: null }>((_, reject) => {
        rejectAction = reject
      })
      const removeDevice = runner.vi?.fn(() => ({
        requestId: 'req-fail',
        loading: createSignal(false),
        done,
      }))
      const view = await mount({
        devices: [
          {
            id: 1,
            deviceName: 'First Device',
            createdAt: 1767227400000,
            current: false,
            expired: false,
          },
          {
            id: 2,
            deviceName: 'Second Device',
            createdAt: 1767227460000,
            current: false,
            expired: false,
          },
        ],
        actions: {
          removeDevice: (removeDevice ??
            (() => ({
              requestId: 'req',
              loading: createSignal(false),
              done,
            }))) as unknown as HilosProfileDeviceActions['removeDevice'],
        },
      })
      try {
        const removeButtons =
          view.container.querySelectorAll<HTMLButtonElement>(
            '[data-id="profile-device-remove"]',
          )
        removeButtons[0]?.click()
        await view.flush()

        const confirmButton = view.container.querySelector<HTMLButtonElement>(
          '[data-id="profile-device-remove-confirm"]',
        )
        confirmButton?.click()
        await view.flush()

        rejectAction(
          new ActionError(
            PROFILE_DEVICE_REMOVE_ACTION,
            'fail',
            'Failed to remove destination.',
          ),
        )
        await view.flush()

        // Modal remains open
        runner
          .expect(view.container.querySelector('[role="dialog"]'))
          .not.toBeNull()

        // Error is displayed in HilosFormError slot
        const errorSlot = view.container.querySelector<HTMLElement>(
          '[data-id="profile-device-remove-error"]',
        )
        runner.expect(errorSlot).not.toBeNull()
        runner
          .expect(errorSlot?.textContent)
          .toContain('Failed to remove destination.')

        // Retry: prepare new promise and click confirm again
        let rejectRetry!: (reason: unknown) => void
        done = new Promise<{ reply: null }>((_, reject) => {
          rejectRetry = reject
        })
        confirmButton?.click()
        await view.flush()
        if (removeDevice) {
          runner.expect(removeDevice).toHaveBeenCalledTimes(2)
          runner.expect(removeDevice).toHaveBeenLastCalledWith(1)
        }

        // Retry also fails so modal remains and busy clears
        rejectRetry(
          new ActionError(
            PROFILE_DEVICE_REMOVE_ACTION,
            'fail',
            'Retry failed.',
          ),
        )
        await view.flush()

        // Cancel
        const cancelButton = view.container.querySelector<HTMLButtonElement>(
          '.modal-footer .btn-secondary',
        )
        cancelButton?.click()
        await view.flush()
        runner
          .expect(view.container.querySelector('[role="dialog"]'))
          .toBeNull()

        // Reopen with Second Device
        removeButtons[1]?.click()
        await view.flush()

        const secondDialog =
          view.container.querySelector<HTMLElement>('[role="dialog"]')
        runner.expect(secondDialog).not.toBeNull()
        runner
          .expect(secondDialog?.textContent)
          .toContain('Remove Second Device?')

        const reopenedError = view.container.querySelector<HTMLElement>(
          '[data-id="profile-device-remove-error"]',
        )
        runner.expect(reopenedError).toBeNull()

        const reopenedErrorSlot = view.container.querySelector<HTMLElement>(
          '[data-id="profile-device-remove-error-slot"]',
        )
        runner.expect(reopenedErrorSlot).not.toBeNull()
        runner
          .expect(reopenedErrorSlot?.textContent)
          .not.toContain('Failed to remove destination.')
      } finally {
        await view.unmount()
      }
    },
  )

  runner.it(
    'disables confirm and shows already removed message if target disappears from props before confirmation',
    async () => {
      const removeDevice = runner.vi?.fn()
      const view = await mount({
        devices: [
          {
            id: 99,
            deviceName: 'Vanishing Device',
            createdAt: 1767227400000,
            current: false,
            expired: false,
          },
        ],
        actions: {
          removeDevice: (removeDevice ??
            (() => {})) as unknown as HilosProfileDeviceActions['removeDevice'],
        },
      })
      try {
        const openButton = view.container.querySelector<HTMLButtonElement>(
          '[data-id="profile-device-remove"]',
        )
        openButton?.click()
        await view.flush()

        const dialog =
          view.container.querySelector<HTMLElement>('[role="dialog"]')
        runner.expect(dialog).not.toBeNull()
        runner.expect(dialog?.textContent).toContain('Remove Vanishing Device?')

        // Target disappears from props
        await view.update({ devices: [] })
        await view.flush()

        // Modal stays open with the device name
        runner.expect(dialog?.textContent).toContain('Remove Vanishing Device?')

        // Confirm button is disabled
        const confirmButton = view.container.querySelector<HTMLButtonElement>(
          '[data-id="profile-device-remove-confirm"]',
        )
        runner
          .expect(
            confirmButton?.disabled || confirmButton?.hasAttribute('disabled'),
          )
          .toBe(true)

        // Error slot displays "This device has already been removed."
        const errorSlot = view.container.querySelector<HTMLElement>(
          '[data-id="profile-device-remove-error"]',
        )
        runner
          .expect(errorSlot?.textContent)
          .toContain('This device has already been removed.')

        confirmButton?.click()
        await view.flush()
        if (removeDevice) {
          runner.expect(removeDevice).not.toHaveBeenCalled()
        }
      } finally {
        await view.unmount()
      }
    },
  )

  runner.it('displays Unknown device when deviceName is null', async () => {
    const view = await mount({
      devices: [
        {
          id: 33,
          deviceName: null,
          createdAt: 1767227400000,
          current: false,
          expired: false,
        },
      ],
    })
    try {
      const openButton = view.container.querySelector<HTMLButtonElement>(
        '[data-id="profile-device-remove"]',
      )
      openButton?.click()
      await view.flush()

      const dialog =
        view.container.querySelector<HTMLElement>('[role="dialog"]')
      runner.expect(dialog).not.toBeNull()
      runner.expect(dialog?.textContent).toContain('Remove Unknown device?')
    } finally {
      await view.unmount()
    }
  })

  runner.it(
    'asserts accessibility attributes and semantics on the modal and error slot',
    async () => {
      let resolveAction!: (value: { reply: null }) => void
      const done = new Promise<{ reply: null }>((resolve) => {
        resolveAction = resolve
      })
      const removeDevice = runner.vi?.fn(() => ({
        requestId: 'req-a11y',
        loading: createSignal(true),
        done,
      }))
      const view = await mount({
        devices: [
          {
            id: 15,
            deviceName: 'Living Room TV',
            createdAt: 1767227400000,
            current: false,
            expired: false,
          },
        ],
        actions: {
          removeDevice: (removeDevice ??
            (() => ({
              requestId: 'req',
              loading: createSignal(true),
              done,
            }))) as unknown as HilosProfileDeviceActions['removeDevice'],
        },
      })
      try {
        const openButton = view.container.querySelector<HTMLButtonElement>(
          '[data-id="profile-device-remove"]',
        )
        openButton?.click()
        await view.flush()

        const dialog =
          view.container.querySelector<HTMLElement>('[role="dialog"]')
        runner.expect(dialog).not.toBeNull()
        runner.expect(dialog?.getAttribute('role')).toBe('dialog')
        runner.expect(dialog?.getAttribute('aria-modal')).toBe('true')
        runner
          .expect(dialog?.getAttribute('aria-label'))
          .toBe('Remove Living Room TV?')

        const errorSlot =
          view.container.querySelector<HTMLElement>(
            '[data-id="profile-device-remove-error-slot"]',
          ) ?? view.container.querySelector<HTMLElement>('[role="alert"]')
        runner.expect(errorSlot).not.toBeNull()
        runner.expect(errorSlot?.getAttribute('role')).toBe('alert')
        runner.expect(errorSlot?.getAttribute('aria-live')).toBe('assertive')

        const confirmButton = view.container.querySelector<HTMLButtonElement>(
          '[data-id="profile-device-remove-confirm"]',
        )
        confirmButton?.click()
        await view.flush()

        runner.expect(confirmButton?.getAttribute('aria-busy')).toBe('true')
        resolveAction({ reply: null })
        await view.flush()
      } finally {
        await view.unmount()
      }
    },
  )

  runner.it(
    'preserves the opener element and returns focus on cancel',
    async () => {
      const view = await mount({
        devices: [
          {
            id: 22,
            deviceName: 'Gaming Laptop',
            createdAt: 1767227400000,
            current: false,
            expired: false,
          },
        ],
      })
      try {
        const openButton = view.container.querySelector<HTMLButtonElement>(
          '[data-id="profile-device-remove"]',
        )
        runner.expect(openButton).not.toBeNull()
        openButton?.focus()
        runner.expect(document.activeElement).toBe(openButton)

        openButton?.click()
        await view.flush()

        runner
          .expect(view.container.querySelector('[role="dialog"]'))
          .not.toBeNull()

        const cancelButton = view.container.querySelector<HTMLButtonElement>(
          '.modal-footer .btn-secondary',
        )
        cancelButton?.click()
        await view.flush()

        runner
          .expect(view.container.querySelector('[role="dialog"]'))
          .toBeNull()
        runner.expect(document.activeElement).toBe(openButton)
      } finally {
        await view.unmount()
      }
    },
  )
}
