import { createRoot } from 'react-dom/client'
import '@hilos/react/base'
import { TableGeometryFixture } from './TableGeometryFixture.js'

createRoot(document.getElementById('app')!).render(<TableGeometryFixture />)
