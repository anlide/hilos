// demo-simple-todo — an end project consuming the Hilos frontend SDK
// (@hilos/react), doubling as the React conformance demo
// (docs/agents/frontend/multiframework-core.md).
//
// The root entry is deliberately thin: it pulls in the bootstrap module and
// nothing else. Every Hilos wiring step and the app mount live under
// src/bootstrap/ (docs/agents/frontend/bootstrap-structure.md). Keep this file a
// single import — do not add application logic here.
import './bootstrap/main'
