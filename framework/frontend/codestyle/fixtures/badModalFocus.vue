<!-- Deliberately broken sample: four mounts MODAL-FOCUS must report — no
     declaration, an expression, an unknown value, and a self-closing mount
     with no prop. Each report sits on the opening tag. -->
<template>
  <HilosModal>
    <p>nothing to fill</p>
  </HilosModal>
  <HilosModal :initial-focus="where">
    <p>bound</p>
  </HilosModal>
  <HilosModal initial-focus="first">
    <p>unknown</p>
  </HilosModal>
  <HilosModal />
</template>
