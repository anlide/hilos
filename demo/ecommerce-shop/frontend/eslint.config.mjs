// ESLint flat config for the ecommerce-shop demo frontend — an end project consuming
// @hilos/react. Mirrors the SDK baseline (framework/frontend/eslint.config.mjs):
// the recommended presets from ESLint and typescript-eslint, with
// eslint-config-prettier last so all formatting defers to Prettier.

import js from '@eslint/js'
import tseslint from 'typescript-eslint'
import configPrettier from 'eslint-config-prettier'

// The words of every money finding, then the hint of what was found.
const MONEY =
  'Money of the shop is counted and printed only in src/money/money.ts (README, "Money").'

// A name of money: price, discount, kopeck or money in any case, and never a share
// in percent (discountPercent is not money). An esquery regex, pasted into selectors.
const NAME = '/^(?!.*percent).*(price|discount|kopeck|money)/i'

const BARE_MONEY_NAME = `:matches(Identifier[name=${NAME}], MemberExpression[property.name=${NAME}])`

// ?. ! and as wrap the name in a node of their own, which takes its place as the operand.
const MONEY_NAME = `:matches(${BARE_MONEY_NAME}, :matches(ChainExpression, TSNonNullExpression, TSAsExpression):has(> ${BARE_MONEY_NAME}))`

export default tseslint.config(
  // src/generated/** is the license inventory snapshot a framework generator
  // writes before every build, check and dev start — it is not hand-authored code.
  { ignores: ['dist/**', 'dist-prerender/**', '.vite/**', 'src/generated/**'] },
  js.configs.recommended,
  // As of PhpStorm 2026.1, the IDE type inspection falsely flags the next line —
  // it rejects typescript-eslint's CompatibleConfigArray for config()'s parameter,
  // which tsc and eslint accept (typescript-eslint#11519, closed wontfix).
  ...tseslint.configs.recommended,
  // An edit window of the project is the core row-edit session
  // (createHilosRowEdit), not a copy of its merge; a value import of the four
  // merge functions is the old shape coming back
  // (docs/agents/frontend/conflict-resolution.md, "The row-edit session").
  // `import type` of their types stays allowed.
  {
    files: ['src/**'],
    rules: {
      '@typescript-eslint/no-restricted-imports': [
        'error',
        {
          paths: [
            {
              name: '@hilos/core',
              importNames: [
                'openRowEdit',
                'resolveRowEdit',
                'keepMineRowEdit',
                'takeTheirsRowEdit',
              ],
              allowTypeImports: true,
              message:
                'An edit window takes the core row-edit session (createHilosRowEdit or its window factory) and never assembles one from the merge helper: the rules of an edit window live in one place (docs/agents/frontend/conflict-resolution.md, "The row-edit session").',
            },
          ],
        },
      ],
    },
  },
  // Money of the shop is counted and printed in one module, src/money/money.ts:
  // the sign, printing a number, rounding, the hundred, arithmetic on a name of
  // money and printing such a name raw are found anywhere else in src/. Passing a
  // name on — an argument, a JSX attribute — and comparing it stay allowed. Money
  // under another name (total) is not seen: total also names the stock of a
  // product summed over warehouses. An exception is one more path in ignores with
  // its reason in a comment; there are none today.
  {
    files: ['src/**'],
    ignores: ['src/money/**'],
    rules: {
      'no-restricted-syntax': [
        'error',
        {
          selector: 'Literal[value=/₴/]',
          message: `${MONEY} Found: the hryvnia sign in a string.`,
        },
        {
          selector: 'TemplateElement[value.raw=/₴/]',
          message: `${MONEY} Found: the hryvnia sign in a template.`,
        },
        {
          selector: 'JSXText[value=/₴/]',
          message: `${MONEY} Found: the hryvnia sign in JSX text.`,
        },
        {
          selector:
            'CallExpression[callee.property.name=/^(toFixed|toPrecision)$/]',
          message: `${MONEY} Found: a number printed with toFixed or toPrecision.`,
        },
        {
          selector:
            'MemberExpression[object.name="Intl"][property.name="NumberFormat"]',
          message: `${MONEY} Found: a number printed with Intl.NumberFormat.`,
        },
        {
          selector:
            'CallExpression[callee.object.name="Math"][callee.property.name=/^(round|floor|ceil|trunc)$/]',
          message: `${MONEY} Found: rounding with Math.`,
        },
        {
          selector:
            'BinaryExpression:matches([operator="*"], [operator="/"], [operator="%"]) > Literal[value=100]',
          message: `${MONEY} Found: 100 as an operand of * / %.`,
        },
        {
          selector:
            'AssignmentExpression:matches([operator="*="], [operator="/="], [operator="%="]) > Literal[value=100]',
          message: `${MONEY} Found: 100 as an operand of *= /= %=.`,
        },
        {
          selector: `BinaryExpression:matches([operator="+"], [operator="-"], [operator="*"], [operator="/"], [operator="%"], [operator="**"]) > ${MONEY_NAME}`,
          message: `${MONEY} Found: a name of money in arithmetic.`,
        },
        {
          selector: `AssignmentExpression:matches([operator="+="], [operator="-="], [operator="*="], [operator="/="], [operator="%="], [operator="**="]) > ${MONEY_NAME}`,
          message: `${MONEY} Found: a name of money in an arithmetic assignment.`,
        },
        {
          selector: `UpdateExpression > ${MONEY_NAME}`,
          message: `${MONEY} Found: a name of money with ++ or --.`,
        },
        {
          selector: `UnaryExpression[operator="-"] > ${MONEY_NAME}`,
          message: `${MONEY} Found: a name of money with a unary minus.`,
        },
        {
          selector: `:matches(JSXElement, JSXFragment) > JSXExpressionContainer > ${MONEY_NAME}`,
          message: `${MONEY} Found: a name of money printed raw as a JSX child.`,
        },
        {
          selector: `TemplateLiteral > ${MONEY_NAME}`,
          message: `${MONEY} Found: a name of money printed raw in a template.`,
        },
        {
          selector: `CallExpression[callee.name="String"] > ${MONEY_NAME}`,
          message: `${MONEY} Found: a name of money printed raw with String().`,
        },
        {
          selector: `MemberExpression:matches([property.name="toString"], [property.name="toLocaleString"]) > ${MONEY_NAME}.object`,
          message: `${MONEY} Found: a name of money printed raw with toString or toLocaleString.`,
        },
      ],
    },
  },
  configPrettier,
)
