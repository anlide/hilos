import { createRoot } from 'react-dom/client'
import '@hilos/react/base'
import { DateTimePickerFixture } from './DateTimePickerFixture.js'

createRoot(document.getElementById('app')!).render(<DateTimePickerFixture />)
