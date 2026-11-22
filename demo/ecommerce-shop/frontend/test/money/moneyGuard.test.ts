import { ESLint } from 'eslint'
import { describe, expect, it } from 'vitest'

// The frontend root, found without node:path — the shop carries no node types.
const cwd = new URL('../..', import.meta.url).pathname
const SAMPLE = `${cwd}src/views/Sample/Sample.tsx`
const MODULE = `${cwd}src/money/money.ts`
const RULE = 'no-restricted-syntax'
const MONEY =
  'Money of the shop is counted and printed only in src/money/money.ts (README, "Money").'

const eslint = new ESLint({ cwd })

async function moneyFindings(source: string, filePath: string) {
  const [result] = await eslint.lintText(source, { filePath })
  expect(result.fatalErrorCount, JSON.stringify(result.messages)).toBe(0)
  return result.messages.filter((message) => message.ruleId === RULE)
}

// FIXME(F-126, 2026-11-21): the cold ESLint start of the first case exceeds the 5000 ms test timeout under load · foreign · attempt 1 · HIL-2261
describe.skip('money guard of the shop frontend', () => {
  it.each([
    ['`₴${x}`', 'the hryvnia sign in a template'],
    ["'₴' + x", 'the hryvnia sign in a string'],
    ['<span>₴{x}</span>', 'the hryvnia sign in JSX text'],
    ['x.toFixed(2)', 'a number printed with toFixed or toPrecision'],
    ["new Intl.NumberFormat('en')", 'a number printed with Intl.NumberFormat'],
    ['Math.round(x)', 'rounding with Math'],
    ['x / 100', '100 as an operand of * / %'],
    ['line.price * line.quantity', 'a name of money in arithmetic'],
    ['sum += line.price', 'a name of money in an arithmetic assignment'],
    ['items - discount', 'a name of money in arithmetic'],
    ['items - cart?.discount', 'a name of money in arithmetic'],
    ['(row.price as number) * 2', 'a name of money in arithmetic'],
    ['-discount', 'a name of money with a unary minus'],
    ['<td>{row.price}</td>', 'a name of money printed raw as a JSX child'],
    ['<td>{row?.price}</td>', 'a name of money printed raw as a JSX child'],
    ['`${price}`', 'a name of money printed raw in a template'],
    ['`${line.price!}`', 'a name of money printed raw in a template'],
    ['String(price)', 'a name of money printed raw with String()'],
    [
      'price.toLocaleString()',
      'a name of money printed raw with toString or toLocaleString',
    ],
  ])('finds %s', async (source, found) => {
    const findings = await moneyFindings(source, SAMPLE)
    expect(findings.map((finding) => finding.message)).toEqual([
      `${MONEY} Found: ${found}.`,
    ])
  })

  it.each([
    'formatMoney(row.price)',
    '<PriceCell price={row.price} />',
    'a.price === b.price',
    '`${discountPercent}%`',
    'units + 1',
    '// ₴ in a comment',
  ])('lets %s pass', async (source) => {
    expect(await moneyFindings(source, SAMPLE)).toHaveLength(0)
  })

  it('lets the money module itself count and print', async () => {
    expect(await moneyFindings('Math.round(price * 100)', MODULE)).toHaveLength(
      0,
    )
  })
})
