import {
  HILOS_INTERACTION_BLOCKED_ATTR,
  HILOS_INTERACTION_REGION_ATTR,
} from '../connection/interactionBlock.js'
import { subscribeSignal, type ReadonlySignal } from '../state/signal.js'
import { available, focusInitial } from './focusTrap.js'

const REGION_SELECTOR = `[${HILOS_INTERACTION_REGION_ATTR}]`
const REGION_CHANGE = 'hilos-interaction-region-change'
const REPLACE_BINDING = 'hilos-interaction-replace-binding'
const SYNC_REGION = 'hilos-interaction-sync-region'
const NATIVE_SELECTOR = 'button, input, select, textarea'
const ROLE_SELECTOR = [
  'button',
  'link',
  'checkbox',
  'radio',
  'switch',
  'tab',
  'menuitem',
  'menuitemcheckbox',
  'menuitemradio',
  'option',
  'combobox',
  'listbox',
  'searchbox',
  'textbox',
  'slider',
  'spinbutton',
  'treeitem',
]
  .map((role) => `[role~="${role}"]`)
  .join(', ')
const CONTROL_SELECTOR = `${NATIVE_SELECTOR}, a[href], area[href], summary, label, [contenteditable], ${ROLE_SELECTOR}`
const ATTRIBUTES = [
  'disabled',
  'aria-disabled',
  'aria-describedby',
  'tabindex',
  'contenteditable',
  'class',
  'href',
  'role',
  HILOS_INTERACTION_REGION_ATTR,
  HILOS_INTERACTION_BLOCKED_ATTR,
]

interface AttributeEffect {
  owner: string | null
}

/**
 * Bind one shell-owned interaction region without replacing its live content.
 * Native controls inherit only the supplied fieldset's disabled state. Other
 * controls receive reversible attribute effects; owner writes observed while
 * blocked become the new underlying values, including before cleanup.
 *
 * @param root The existing focusable main, dialog or confirm region.
 * @param options The permanent fieldset, live policy and explanation's id.
 * @returns Detach listeners, observation and only this binding's effects.
 */
export function bindInteractionBlock(
  root: HTMLElement,
  options: {
    fieldset: HTMLFieldSetElement
    blocked: ReadonlySignal<boolean>
    descriptionId: string
  },
): () => void {
  const { fieldset, blocked, descriptionId } = options
  const document = root.ownerDocument
  const view = document.defaultView!
  const effects = new Map<HTMLElement, Map<string, AttributeEffect>>()
  let disposed = false
  let wasBlocked = false
  let previousFocus: HTMLElement | null = null
  let returningFocus = false

  // Handoff stays on this element: neither a singleton nor a document registry.
  root.dispatchEvent(new view.Event(REPLACE_BINDING))
  const regionBefore = root.getAttribute(HILOS_INTERACTION_REGION_ATTR)
  root.setAttribute(HILOS_INTERACTION_REGION_ATTR, '')
  root.dispatchEvent(new view.Event(REGION_CHANGE, { bubbles: true }))

  const observer = new view.MutationObserver((records) => {
    readOwnerWrites(records)
    refresh()
  })

  function belongs(element: Element): boolean {
    return element.closest(REGION_SELECTOR) === root
  }

  function ownerAttribute(element: HTMLElement, name: string): string | null {
    const attribute = effects.get(element)?.get(name)

    return attribute ? attribute.owner : element.getAttribute(name)
  }

  function editable(element: HTMLElement): boolean {
    const live = element.getAttribute('contenteditable')
    const owner = ownerAttribute(element, 'contenteditable')

    return (
      (live !== null && live !== 'false') ||
      (owner !== null && owner !== 'false')
    )
  }

  function controls(): HTMLElement[] {
    return Array.from(
      root.querySelectorAll<HTMLElement>(CONTROL_SELECTOR),
    ).filter(
      (element) =>
        belongs(element) &&
        (element.matches(
          `${NATIVE_SELECTOR}, a[href], area[href], summary, label, ${ROLE_SELECTOR}`,
        ) ||
          editable(element)),
    )
  }

  function write(
    element: HTMLElement,
    name: string,
    value: string | null,
  ): void {
    if (element.getAttribute(name) === value) return
    if (value === null) element.removeAttribute(name)
    else element.setAttribute(name, value)
  }

  function effect(
    element: HTMLElement,
    name: string,
    value: string | null,
  ): void {
    let attributes = effects.get(element)
    if (!attributes) {
      attributes = new Map()
      effects.set(element, attributes)
    }
    const prior = attributes.get(name)
    attributes.set(name, {
      owner: prior ? prior.owner : element.getAttribute(name),
    })
    write(element, name, value)
  }

  function tokens(value: string | null): string[] {
    return value?.split(/\s+/).filter(Boolean) ?? []
  }

  function addToken(element: HTMLElement, name: string, token: string): void {
    effect(
      element,
      name,
      [...new Set([...tokens(ownerAttribute(element, name)), token])].join(' '),
    )
  }

  function restore(element: HTMLElement): void {
    for (const [name, attribute] of effects.get(element) ?? []) {
      write(element, name, attribute.owner)
    }
    effects.delete(element)
  }

  // Our writes occur with observation disconnected. Thus these records belong
  // to the component, even when it explicitly writes the enforced value again.
  function readOwnerWrites(records: MutationRecord[]): void {
    for (let index = 0; index < records.length; index++) {
      const record = records[index]!
      if (record.type !== 'attributes' || !record.attributeName) continue
      const element = record.target as HTMLElement
      const name = record.attributeName
      const attribute = effects.get(element)?.get(name)
      if (!attribute) continue
      const later = records
        .slice(index + 1)
        .find((next) => next.target === element && next.attributeName === name)
      const value = later ? later.oldValue : element.getAttribute(name)
      if (name === 'class' || name === 'aria-describedby') {
        const before = tokens(record.oldValue)
        const after = tokens(value)
        const added = after.filter((token) => !before.includes(token))
        const removed = before.filter((token) => !after.includes(token))
        attribute.owner =
          added.length || removed.length
            ? [
                ...new Set([
                  ...tokens(attribute.owner).filter(
                    (token) => !removed.includes(token),
                  ),
                  ...added,
                ]),
              ].join(' ') || null
            : value
      } else attribute.owner = value
    }
  }

  function observe(): void {
    observer.observe(root, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeOldValue: true,
      attributeFilter: ATTRIBUTES,
    })
  }

  function refresh(): void {
    if (disposed) return
    readOwnerWrites(observer.takeRecords())
    observer.disconnect()
    const next = blocked.get()
    write(root, HILOS_INTERACTION_REGION_ATTR, '')
    const active = document.activeElement
    const ownedFocus =
      active instanceof view.HTMLElement &&
      active !== root &&
      belongs(active) &&
      controlOf(active) !== null
    if (next && !wasBlocked) {
      if (ownedFocus) previousFocus = active
      returningFocus = ownedFocus || active === root
    }
    // A nested fieldset cannot escape its parent's native disabled inheritance.
    // Let its own binding park focus before the ancestor fieldset disables it,
    // even when the ancestor's signal subscriber runs first.
    if (
      next &&
      active instanceof view.Element &&
      root.contains(active) &&
      !belongs(active)
    ) {
      active
        .closest(REGION_SELECTOR)
        ?.dispatchEvent(new view.Event(SYNC_REGION))
    }
    const currentControls = next ? controls() : []
    const retained = new Set<HTMLElement>(
      next ? [root, fieldset, ...currentControls] : [],
    )
    for (const element of effects.keys()) {
      if (!retained.has(element)) restore(element)
    }
    if (next) {
      effect(root, HILOS_INTERACTION_BLOCKED_ATTR, '')
      addToken(root, 'aria-describedby', descriptionId)
      effect(fieldset, 'disabled', '')
      for (const control of currentControls) {
        addToken(control, 'aria-describedby', descriptionId)
        if (!control.matches(NATIVE_SELECTOR)) {
          effect(control, 'aria-disabled', 'true')
          effect(control, 'tabindex', '-1')
          addToken(control, 'class', 'disabled')
          if (editable(control)) effect(control, 'contenteditable', 'false')
        }
      }
    }
    const returning = wasBlocked && !next
    wasBlocked = next
    observe()
    if (next && ownedFocus) root.focus({ preventScroll: true })
    else if (returning) {
      // Enable ancestor fieldsets before an inner subscriber returns focus.
      root.dispatchEvent(new view.Event(REGION_CHANGE, { bubbles: true }))
      restoreFocus()
    }
  }

  function restoreFocus(): void {
    const active = document.activeElement
    const target = previousFocus
    const shouldReturn = returningFocus
    previousFocus = null
    returningFocus = false
    if (!shouldReturn) return
    if (!root.isConnected || (active !== root && active !== document.body))
      return
    if (available(target) && belongs(target))
      target.focus({ preventScroll: true })
    else focusInitial(root)
  }

  function targetOf(event: Event): HTMLElement | null {
    const target = event.target
    let element =
      target instanceof view.Element
        ? target
        : (target as Node | null)?.parentElement
    // SVG icons inside a control still activate their containing HTML control.
    while (element && !(element instanceof view.HTMLElement))
      element = element.parentElement

    return element && belongs(element) ? element : null
  }

  function controlOf(element: HTMLElement): HTMLElement | null {
    let control = element.closest<HTMLElement>(CONTROL_SELECTOR)
    while (control && control !== root && belongs(control)) {
      if (
        control.matches(
          `${NATIVE_SELECTOR}, a[href], area[href], summary, label, ${ROLE_SELECTOR}`,
        ) ||
        editable(control)
      )
        return control
      control =
        control.parentElement?.closest<HTMLElement>(CONTROL_SELECTOR) ?? null
    }

    return null
  }

  function guard(event: Event): void {
    if (!blocked.get()) return
    const target = targetOf(event)
    if (!target) return
    const control = controlOf(target)
    let cancel = event.type === 'submit'
    if (event.type === 'click' || event.type === 'auxclick')
      cancel = control !== null
    else if (
      event.type === 'keydown' ||
      event.type === 'keyup' ||
      event.type === 'keypress'
    ) {
      const key = event as KeyboardEvent
      cancel =
        control !== null &&
        (key.key === 'Enter' ||
          key.key === ' ' ||
          (!key.ctrlKey &&
            !key.metaKey &&
            !key.altKey &&
            control.matches(ROLE_SELECTOR) &&
            [
              'ArrowUp',
              'ArrowDown',
              'ArrowLeft',
              'ArrowRight',
              'Home',
              'End',
              'PageUp',
              'PageDown',
            ].includes(key.key)))
    } else if (event.type === 'beforeinput' || event.type === 'paste') {
      cancel =
        control !== null &&
        (control.matches(
          'input, textarea, [role~="textbox"], [role~="searchbox"]',
        ) ||
          editable(target.closest<HTMLElement>('[contenteditable]') ?? target))
    } else if (event.type === 'drop') {
      const drop = event as DragEvent
      cancel =
        control !== null ||
        Array.from(drop.dataTransfer?.types ?? []).includes('Files')
      for (
        let node: HTMLElement | null = target;
        node && node !== root;
        node = node.parentElement
      ) {
        if (
          node.ondrop ||
          node.hasAttribute('ondrop') ||
          node.hasAttribute('dropzone')
        )
          cancel = true
      }
    }
    if (cancel) {
      event.preventDefault()
      event.stopImmediatePropagation()
    }
  }

  function guardFocus(event: Event): void {
    if (!blocked.get()) return
    const target = targetOf(event)
    if (target && target !== root && controlOf(target)) {
      if (!previousFocus) previousFocus = target
      returningFocus = true
      root.focus({ preventScroll: true })
    }
  }

  function regionChanged(event: Event): void {
    if (event.target !== root) refresh()
  }

  const events = [
    'click',
    'auxclick',
    'keydown',
    'keyup',
    'keypress',
    'submit',
    'beforeinput',
    'paste',
    'drop',
  ]
  // React delegates capture above main and portal dialogs. Guard before those
  // renderer listeners; targetOf keeps this binding within its nearest region.
  for (const type of events) document.addEventListener(type, guard, true)
  root.addEventListener('focusin', guardFocus, true)
  root.addEventListener(REGION_CHANGE, regionChanged)
  root.addEventListener(REPLACE_BINDING, dispose)
  root.addEventListener(SYNC_REGION, refresh)
  const unsubscribe = subscribeSignal(blocked, refresh)
  refresh()

  function dispose(): void {
    if (disposed) return
    readOwnerWrites(observer.takeRecords())
    observer.disconnect()
    disposed = true
    unsubscribe()
    for (const type of events) document.removeEventListener(type, guard, true)
    root.removeEventListener('focusin', guardFocus, true)
    root.removeEventListener(REGION_CHANGE, regionChanged)
    root.removeEventListener(REPLACE_BINDING, dispose)
    root.removeEventListener(SYNC_REGION, refresh)
    for (const element of effects.keys()) restore(element)
    if (wasBlocked) restoreFocus()
    write(root, HILOS_INTERACTION_REGION_ATTR, regionBefore)
    root.dispatchEvent(new view.Event(REGION_CHANGE, { bubbles: true }))
  }

  return dispose
}
