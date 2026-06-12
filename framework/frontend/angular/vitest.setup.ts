// The @hilos/angular public surface now includes partially-compiled Angular
// declarables (the application shell and the navigation components). Importing
// them in the node test environment pulls partial-compiled @angular injectables
// that fall back to the JIT compiler; loading @angular/compiler provides it so
// the surface test can import the public barrel. Component behavior itself is
// proven in the poll demo under the Angular CLI's native unit-test builder
// (testing-strategy.md), not here.
import '@angular/compiler'
