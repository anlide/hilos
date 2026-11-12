import { Component, ViewChild, type TemplateRef } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import type {
  PageHeadContent,
  PageHeadInput,
} from '../../test-support/pageHeadFixtures.js'
import type { PageHeadView } from '../../test-support/pageHeadBlockCases.js'
import { HilosPageHead } from '../src/HilosPageHead.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** Caller templates, with no probe counters or behavioral expectations. */
@Component({
  selector: 'test-page-head-block-templates',
  template: `
    <ng-template #visual>
      <span [attr.data-id]="content.visual?.dataId">{{
        content.visual?.text
      }}</span>
    </ng-template>
    <ng-template #meta>
      <span [attr.data-id]="content.meta?.dataId">{{
        content.meta?.text
      }}</span>
    </ng-template>
    <ng-template #badges>
      <span [attr.data-id]="content.badges?.dataId">{{
        content.badges?.text
      }}</span>
    </ng-template>
    <ng-template #description>
      <p [attr.data-id]="content.description?.dataId">
        {{ content.description?.text }}
      </p>
    </ng-template>
    <ng-template #skeletonVisual>
      <span [attr.data-id]="content.skeletonVisual?.dataId">{{
        content.skeletonVisual?.text
      }}</span>
    </ng-template>
  `,
})
class PageHeadBlockTemplates {
  content: PageHeadContent = {}
  @ViewChild('visual', { static: true }) visual!: TemplateRef<unknown>
  @ViewChild('meta', { static: true }) meta!: TemplateRef<unknown>
  @ViewChild('badges', { static: true }) badges!: TemplateRef<unknown>
  @ViewChild('description', { static: true }) description!: TemplateRef<unknown>
  @ViewChild('skeletonVisual', { static: true })
  skeletonVisual!: TemplateRef<unknown>
}

/** Set required inputs on each real Head before its first detection pass. */
export async function mountPageHeadBlock(
  input: PageHeadInput,
  copies: 1 | 2 = 1,
): Promise<PageHeadView> {
  if (input.router !== undefined) {
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: input.router }],
    })
  }
  const host = document.body.appendChild(document.createElement('div'))
  const heads: ComponentFixture<HilosPageHead>[] = []
  let templates: ComponentFixture<PageHeadBlockTemplates> | undefined
  let current = input

  function render(): void {
    const { content, ...props } = current
    delete props.router
    templates!.componentInstance.content = content ?? {}
    for (const head of heads) {
      for (const [name, value] of Object.entries(props)) {
        head.componentRef.setInput(name, value)
      }
      for (const name of [
        'visual',
        'meta',
        'badges',
        'description',
        'skeletonVisual',
      ] as const) {
        head.componentRef.setInput(
          name,
          content?.[name] === undefined
            ? undefined
            : templates!.componentInstance[name],
        )
      }
    }
    templates!.detectChanges()
    for (const head of heads) head.detectChanges()
  }

  function destroy(): void {
    try {
      for (const head of heads) head.destroy()
    } finally {
      try {
        templates?.destroy()
      } finally {
        host.remove()
      }
    }
  }

  try {
    templates = TestBed.createComponent(PageHeadBlockTemplates)
    templates.componentInstance.content = input.content ?? {}
    templates.detectChanges()
    for (let index = 0; index < copies; index++) {
      const head = TestBed.createComponent(HilosPageHead)
      heads.push(head)
    }
    // TestBed removes earlier root elements when it creates the next fixture.
    // Connect them together only after both real components have been created.
    host.appendChild(templates.nativeElement as HTMLElement)
    for (const head of heads)
      host.appendChild(head.nativeElement as HTMLElement)
    render()
    return {
      root: host,
      async update(next) {
        current = { ...current, ...next }
        render()
      },
      async unmount() {
        destroy()
      },
    }
  } catch (error) {
    destroy()
    throw error
  }
}
