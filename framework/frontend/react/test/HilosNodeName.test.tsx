import { cleanup, render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import { HilosNodeName } from '../src/admin/HilosNodeName.js'

afterEach(cleanup)

describe('HilosNodeName', () => {
  it('shows the reported name and the stable id as separate text values', () => {
    const view = render(<HilosNodeName nodeId="n1" nodeName="North" />)

    expect(view.getByText('North').textContent).toBe('North')
    expect(view.getByText('n1').textContent).toBe('n1')
  })

  it('shows an unknown node id exactly once', () => {
    const view = render(<HilosNodeName nodeId="n1" nodeName={null} />)

    expect(view.container.textContent).toBe('n1')
  })

  it('keeps Unicode and HTML-like values as literal text', () => {
    const view = render(
      <HilosNodeName
        nodeId={'узел-🌍<&>'}
        nodeName={'Север 🌨 <img src=x onerror="alert(1)"> & "雪"'}
      />,
    )

    expect(
      view.getByText('Север 🌨 <img src=x onerror="alert(1)"> & "雪"')
        .textContent,
    ).toBe('Север 🌨 <img src=x onerror="alert(1)"> & "雪"')
    expect(view.getByText('узел-🌍<&>').textContent).toBe('узел-🌍<&>')
    expect(view.container.querySelector('img')).toBeNull()
  })
})
