// demo-simple-poll — an end project consuming the Hilos frontend SDK
// (@hilos/angular) through the canonical Angular CLI toolchain, doubling as the
// Angular conformance demo (docs/agents/frontend/multiframework-core.md).
//
// The root entry is deliberately thin: it pulls in the bootstrap module and
// nothing else. Every Hilos wiring step and the app bootstrap live under
// src/app/bootstrap/ (docs/agents/frontend/bootstrap-structure.md). Keep this
// file a single import — do not add application logic here.
import './app/bootstrap/main'
