import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import HomeVideo from './HomeVideo.vue'

enableAutoUnmount(afterEach)

describe('HomeVideo', () => {
  it('offers a named video with native playback controls and a file fallback', () => {
    const wrapper = mount(HomeVideo)
    const heading = wrapper.get('h2')
    const video = wrapper.get('video')

    expect(wrapper.get('section').attributes('aria-labelledby')).toBe(
      heading.attributes('id'),
    )
    expect(heading.text()).not.toBe('')
    expect(video.attributes('aria-labelledby')).toBe(heading.attributes('id'))
    expect(video.attributes('src')).toBe('/media/hilos.mp4')
    expect(video.element.controls).toBe(true)
    expect(video.attributes('preload')).toBe('metadata')
    expect(video.element.hasAttribute('playsinline')).toBe(true)
    expect(video.get('a').attributes('href')).toBe('/media/hilos.mp4')
  })

  it('replaces a failed video with a placeholder while keeping its heading and description', async () => {
    const wrapper = mount(HomeVideo)
    const heading = wrapper.get('h2').element
    const description = wrapper.get('section > p').element

    await wrapper.get('video').trigger('error')

    expect(wrapper.find('video').exists()).toBe(false)
    expect(wrapper.text()).toContain('The video is coming soon.')
    expect(wrapper.get('h2').element).toBe(heading)
    expect(wrapper.get('section > p').element).toBe(description)
    expect(description.textContent?.trim()).not.toBe('')
  })
})
