# Frontend SDK: Edit in Modal (mandatory)

**Rule: every entity edit on the frontend must happen inside a `Modal` from
`@hilos/sdk/components`. Inline edit forms on pages are forbidden.**

This applies to any mutation of an existing entity — rename, change a field,
toggle a flag, etc. Create/Add and Delete dialogs follow the same rule.

## Why

Only `Modal` participates in the optimistic-update / conflict-resolution
mechanism:

- `confirm-on-close` + `isFormDirty` prompt the user before dropping unsaved
  changes;
- `modal-type` (`edit` / `add` / `delete`) is what the conflict-resolution
  layer uses to classify in-flight edits;
- the stacked-modal machinery in `framework/frontend/src/components/Modal.vue`
  handles ESC / backdrop / z-index consistently and is the only path where a
  concurrent-edit conflict dialog can be stacked on top.

Inline forms on a page bypass all of this. When two admins open the same
entity and both hit Save, there is no code path to detect and resolve the
conflict — the last write silently wins. The conflict-resolution mechanism
itself may currently be buggy, but it only exists at all for modals; moving
edits to inline forms erases the only surface where it can be fixed.

## Required pattern

Every edit screen must use this shape (see
[demo/chat/frontend/src/views/AdminUsers.vue](../../../demo/chat/frontend/src/views/AdminUsers.vue)
and
[demo/chat/frontend/src/views/Hilos/HilosUserDetail.vue](../../../demo/chat/frontend/src/views/Hilos/HilosUserDetail.vue)
for reference).

```vue
<template>
  <!-- 1. A trigger button on the page — NOT a <form> with Save -->
  <button type="button" class="btn btn-primary" @click="handleEdit">
    Rename
  </button>

  <!-- 2. The actual edit surface lives inside Modal -->
  <Modal
    v-model="showModal"
    title="Edit User"
    modal-name="hilos-user-modal"
    modal-type="edit"
    :confirm-on-close="isFormDirty"
    @cancel="resetForm"
    @ok="saveUser"
  >
    <form @submit.prevent="saveUser">
      <!-- fields, with data-autofocus on the primary input -->
    </form>

    <template #actions="{ requestClose }">
      <button type="button" class="btn btn-secondary" @click="requestClose">Cancel</button>
      <LoadingButton
        type="button"
        variant="btn-primary"
        :loading="saveLoading"
        :disabled="!isFormValid || !isFormDirty"
        :loading-delay="300"
        @click="saveUser"
      >
        Save
      </LoadingButton>
    </template>
  </Modal>
</template>
```

Mandatory elements:

| Element | Why |
|---|---|
| `v-model="showModal"` | opened only from an explicit trigger button |
| `modal-name="..."` | stable name for e2e tests and conflict tracking |
| `modal-type="edit" \| "add" \| "delete"` | hook for conflict-resolution layer |
| `:confirm-on-close="isFormDirty"` | no silent loss of unsaved edits |
| `@cancel="resetForm"` | reset local state on dismiss |
| slot `#actions` with `Cancel` + `LoadingButton` (Save) | consistent save UX, shared spinner |
| `baseline` snapshot + `isFormDirty` computed | required for `confirm-on-close` to be meaningful |

## Error handling

If the backend action can fail (ack signal with `fail` outcome), keep the
modal open when the fail signal arrives and render the error inside the
modal. Close the modal only when the success signal arrives. See
`onUpdateSuccess` / `onUpdateFail` in
[HilosUserDetail.vue](../../../demo/chat/frontend/src/views/Hilos/HilosUserDetail.vue).

## What NOT to do

- Do not put a `<form>` with a `Save` button directly in the page body.
- Do not wire `router-link` / page-level buttons to `sendAction(...)` for
  entity mutations — mutations go through a modal trigger.
- Do not skip `confirm-on-close` just because the form has a single field —
  it is the only anti-clobber guard we have.
- Do not reimplement your own modal wrapper; always use `Modal` from
  `@hilos/sdk/components`.
