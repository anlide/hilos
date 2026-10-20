// Build-only attribution of project components. Declarations are inspected as
// syntax, never executed. Only actual bound component owners acquire a verdict;
// their shared dependencies (including profile contexts) do not inherit it.
import { existsSync, readFileSync, realpathSync, statSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, isAbsolute, join, relative, resolve } from 'node:path'
import { sha256 } from './core-provenance.mjs'

function slash(path) {
  return path.replaceAll('\\', '/')
}

function clean(id) {
  return slash(id).replace(/^\0/, '').split(/[?#]/, 1)[0]
}

export function projectEvidence(projectRoot, configuration, inputs, sdk) {
  if (configuration === undefined)
    return { report: { status: 'not-configured' }, verdict: () => null }
  const fail = (message) => {
    throw new Error(
      `initial-bundle-budget: unproven project bindings: ${message}`,
    )
  }
  if (
    !configuration ||
    typeof configuration !== 'object' ||
    Array.isArray(configuration) ||
    Object.keys(configuration).sort().join(',') !== 'routes,views' ||
    !['routes', 'views'].every(
      (key) => typeof configuration[key] === 'string' && configuration[key],
    )
  )
    fail('expected only routes and views source paths')
  const root = slash(realpathSync(projectRoot))
  function inside(path) {
    const name = slash(relative(root, path))
    return name && !name.startsWith('../') && !isAbsolute(name)
  }
  function projectFile(name) {
    const path = resolve(root, name)
    if (!existsSync(path) || !statSync(path).isFile())
      fail(`missing source: ${path}`)
    const actual = slash(realpathSync(path))
    if (!inside(actual) || actual.includes('/node_modules/'))
      fail(`source escapes project: ${path}`)
    return actual
  }
  const ids = new Set(
    inputs.map((id) => {
      const path = clean(id)
      const file = isAbsolute(path) ? path : resolve(root, path)
      return existsSync(file) ? slash(realpathSync(file)) : slash(file)
    }),
  )
  const routes = projectFile(configuration.routes)
  const views = projectFile(configuration.views)
  for (const file of [routes, views]) {
    if (!ids.has(file)) fail(`configured source is not a client input: ${file}`)
  }
  let ts
  try {
    ts = createRequire(join(root, 'package.json'))('typescript')
  } catch {
    fail('the consumer build toolchain must provide typescript')
  }
  const files = new Map()
  const snapshots = new Map()
  function source(path) {
    if (files.has(path)) return files.get(path)
    const text = readFileSync(path, 'utf8')
    snapshots.set(path, sha256(text))
    let script = text
    if (path.endsWith('.vue')) {
      script = text.replace(/[^\n]/g, ' ')
      for (const match of text.matchAll(
        /<script\b[^>]*>([\s\S]*?)<\/script>/g,
      )) {
        const start = match.index + match[0].indexOf('>') + 1
        script =
          script.slice(0, start) +
          match[1] +
          script.slice(start + match[1].length)
      }
    }
    const file = ts.createSourceFile(
      path,
      script,
      ts.ScriptTarget.Latest,
      true,
      path.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
    )
    if (file.parseDiagnostics.length) fail(`invalid source syntax: ${path}`)
    files.set(path, file)
    return file
  }
  const unwrap = (node) => {
    while (
      node &&
      (ts.isAsExpression(node) ||
        ts.isSatisfiesExpression(node) ||
        ts.isParenthesizedExpression(node))
    )
      node = node.expression
    return node
  }
  function imported(file, name) {
    for (const node of file.statements) {
      if (
        !ts.isImportDeclaration(node) ||
        !ts.isStringLiteral(node.moduleSpecifier) ||
        node.importClause?.isTypeOnly
      )
        continue
      const clause = node.importClause
      if (clause?.name?.text === name)
        return { specifier: node.moduleSpecifier.text, name: 'default' }
      const bindings = clause?.namedBindings
      if (bindings && ts.isNamedImports(bindings)) {
        const entry = bindings.elements.find(
          (item) => !item.isTypeOnly && item.name.text === name,
        )
        if (entry)
          return {
            specifier: node.moduleSpecifier.text,
            name: (entry.propertyName ?? entry.name).text,
          }
      }
    }
    return undefined
  }
  function sdkImport(file, node, name, pattern) {
    if (!ts.isIdentifier(node)) return false
    const entry = imported(file, node.text)
    return entry?.name === name && pattern.test(entry.specifier)
  }
  function viewImports(file) {
    return file.statements.flatMap((node) => {
      if (
        !ts.isImportDeclaration(node) ||
        !ts.isStringLiteral(node.moduleSpecifier) ||
        node.importClause?.isTypeOnly ||
        !/^@hilos\/(?:vue|react|angular)\/base$/.test(node.moduleSpecifier.text)
      )
        return []
      const bindings = node.importClause?.namedBindings
      return bindings && ts.isNamedImports(bindings)
        ? bindings.elements
            .filter(
              (item) =>
                !item.isTypeOnly &&
                (item.propertyName ?? item.name).text === 'HilosView',
            )
            .map((item) => item.name.text)
        : []
    })
  }
  // These are the installed production inputs, validated by the same SDK proof
  // as the emitted graph. Source-less packages need no checkout of core/src.
  const core = sdk.modules('@hilos/core/base')
  for (const id of core) {
    const refusal = sdk.verdict(id)
    if (refusal) fail(`core route provenance: ${id}: ${refusal}`)
  }
  const declarations = core.filter((id) =>
    id.endsWith('/dist/routing/hilosPages.js'),
  )
  if (declarations.length !== 1)
    fail('cannot identify installed core route declarations')
  const coreRoutes = declarations[0]
  const coreDirectory = dirname(dirname(coreRoutes))
  function moduleFile(file, specifier) {
    if (/^@hilos\/core(?:\/base)?$/.test(specifier)) return coreRoutes
    if (!specifier.startsWith('.'))
      fail(`external source reference: ${file.fileName}: ${specifier}`)
    const base = resolve(dirname(file.fileName), specifier)
    const stem = base.replace(/\.js$/, '')
    const found = [
      base,
      `${stem}.ts`,
      `${stem}.tsx`,
      `${stem}.vue`,
      join(base, 'index.ts'),
      join(base, 'index.tsx'),
    ].find((path) => existsSync(path) && statSync(path).isFile())
    if (!found)
      fail(`unresolved source reference: ${file.fileName}: ${specifier}`)
    const actual = slash(realpathSync(found))
    if (file.fileName.startsWith(`${coreDirectory}/`)) {
      if (!actual.startsWith(`${coreDirectory}/`))
        fail(`core source reference escapes package: ${found}`)
    } else if (!inside(actual) || actual.includes('/node_modules/'))
      fail(`source reference escapes project: ${found}`)
    return actual
  }
  const targets = new Map()
  function local(file, name, seen = new Set()) {
    const key = `${file.fileName}:${name}`
    if (seen.has(key)) fail(`cyclic source binding: ${key}`)
    const next = new Set([...seen, key])
    const ref = imported(file, name)
    if (ref) return exported(moduleFile(file, ref.specifier), ref.name, next)
    for (const node of file.statements) {
      if (
        (ts.isClassDeclaration(node) || ts.isFunctionDeclaration(node)) &&
        node.name?.text === name
      )
        return { file, node, name }
      if (ts.isVariableStatement(node)) {
        const item = node.declarationList.declarations.find(
          (entry) => ts.isIdentifier(entry.name) && entry.name.text === name,
        )
        if (item) {
          if (!(node.declarationList.flags & ts.NodeFlags.Const))
            fail(`mutable source binding: ${key}`)
          const value = unwrap(item.initializer)
          return value && ts.isIdentifier(value)
            ? local(file, value.text, next)
            : { file, node: item, name }
        }
      }
    }
    fail(`missing source binding: ${key}`)
  }
  function exported(path, name, seen, required = true) {
    const key = `${path}:export:${name}`
    if (seen.has(key)) fail(`cyclic source export: ${key}`)
    if (targets.has(key)) return targets.get(key)
    const next = new Set([...seen, key])
    const file = source(path)
    if (path.endsWith('.vue')) {
      if (name !== 'default') fail(`missing Vue value export: ${key}`)
      return { file, name }
    }
    const found = []
    const stars = []
    for (const node of file.statements) {
      if (ts.isExportDeclaration(node) && !node.isTypeOnly) {
        if (node.exportClause && ts.isNamedExports(node.exportClause)) {
          const entry = node.exportClause.elements.find(
            (item) => !item.isTypeOnly && item.name.text === name,
          )
          if (entry) {
            const original = (entry.propertyName ?? entry.name).text
            found.push(
              node.moduleSpecifier && ts.isStringLiteral(node.moduleSpecifier)
                ? exported(
                    moduleFile(file, node.moduleSpecifier.text),
                    original,
                    next,
                  )
                : local(file, original, next),
            )
          }
        } else if (
          !node.exportClause &&
          node.moduleSpecifier &&
          ts.isStringLiteral(node.moduleSpecifier)
        )
          stars.push(node.moduleSpecifier.text)
      }
      if (
        name === 'default' &&
        ts.isExportAssignment(node) &&
        !node.isExportEquals
      ) {
        const value = unwrap(node.expression)
        found.push(
          ts.isIdentifier(value)
            ? local(file, value.text, next)
            : { file, node, name },
        )
      }
      const modifiers = ts.canHaveModifiers(node)
        ? (ts.getModifiers(node) ?? [])
        : []
      if (!modifiers.some((item) => item.kind === ts.SyntaxKind.ExportKeyword))
        continue
      if (
        (ts.isClassDeclaration(node) || ts.isFunctionDeclaration(node)) &&
        (modifiers.some((item) => item.kind === ts.SyntaxKind.DefaultKeyword)
          ? name === 'default'
          : node.name?.text === name)
      )
        found.push({ file, node, name })
      if (
        ts.isVariableStatement(node) &&
        node.declarationList.declarations.some(
          (item) => item.name.getText(file) === name,
        )
      )
        found.push(local(file, name, next))
    }
    // Named exports take precedence; star resolution distinguishes absence from
    // an invalid or ambiguous declaration rather than swallowing resolver errors.
    if (!found.length && name !== 'default') {
      for (const specifier of stars) {
        const target = exported(moduleFile(file, specifier), name, next, false)
        if (target) found.push(target)
      }
    }
    const unique = new Map(
      found.map((entry) => [`${entry.file.fileName}:${entry.name}`, entry]),
    )
    if (!unique.size && !required) return undefined
    if (unique.size !== 1) fail(`missing or ambiguous value export: ${key}`)
    const result = [...unique.values()][0]
    targets.set(key, result)
    return result
  }
  function value(file, expression) {
    const node = unwrap(expression)
    if (!node) fail(`missing initializer: ${file.fileName}`)
    if (ts.isStringLiteral(node)) return node.text
    if (ts.isIdentifier(node)) {
      const target = local(file, node.text)
      return value(target.file, target.node?.initializer)
    }
    if (
      ts.isPropertyAccessExpression(node) &&
      ts.isIdentifier(node.expression)
    ) {
      const target = local(file, node.expression.text)
      const object = unwrap(target.node?.initializer)
      if (object && ts.isObjectLiteralExpression(object)) {
        const props = object.properties.filter(
          (item) =>
            ts.isPropertyAssignment(item) &&
            item.name.getText(target.file) === node.name.text,
        )
        if (props.length === 1) return value(target.file, props[0].initializer)
      }
    }
    fail(`nonliteral page key: ${file.fileName}: ${node.getText(file)}`)
  }
  function key(file, node) {
    if (ts.isComputedPropertyName(node)) return value(file, node.expression)
    if (ts.isIdentifier(node) || ts.isStringLiteral(node)) return node.text
    fail(`unsupported map key: ${file.fileName}`)
  }
  function object(file, expression) {
    const node = unwrap(expression)
    if (node && ts.isObjectLiteralExpression(node)) return { file, node }
    if (node && ts.isIdentifier(node)) {
      const target = local(file, node.text)
      return object(target.file, target.node?.initializer)
    }
    fail(`expected literal map: ${file.fileName}`)
  }
  const routeMap = new Map()
  function readRoutes(file, map) {
    for (const entry of map.properties) {
      if (!ts.isPropertyAssignment(entry))
        fail(`unsupported route declaration: ${file.fileName}`)
      const page = key(file, entry.name)
      const record = object(file, entry.initializer)
      const fields = new Map()
      for (const prop of record.node.properties) {
        if (!ts.isPropertyAssignment(prop))
          fail(`unsupported route fields: ${page}`)
        const name = key(record.file, prop.name)
        if (fields.has(name)) fail(`duplicate route field: ${page}.${name}`)
        fields.set(name, unwrap(prop.initializer))
      }
      const admin = fields.get('admin')
      if (
        !admin ||
        ![ts.SyntaxKind.TrueKeyword, ts.SyntaxKind.FalseKeyword].includes(
          admin.kind,
        ) ||
        !ts.isStringLiteral(fields.get('path') ?? record.node)
      )
        fail(`missing literal path/admin: ${page}`)
      if (routeMap.has(page)) fail(`duplicate route declaration: ${page}`)
      routeMap.set(page, {
        admin: admin.kind === ts.SyntaxKind.TrueKeyword,
        source: file.fileName,
        line: file.getLineAndCharacterOfPosition(entry.getStart(file)).line + 1,
      })
    }
  }
  const builtins = local(source(coreRoutes), 'HILOS_ROUTE_DECLARATIONS')
  readRoutes(
    builtins.file,
    object(builtins.file, builtins.node.initializer).node,
  )
  const routeFile = source(routes)
  const routers = []
  function visit(node, select) {
    select(node)
    ts.forEachChild(node, (child) => visit(child, select))
  }
  // An included registry or outlet cannot be replaced by a conveniently public
  // metadata input. Check the actual inputs for unaccounted declaration owners.
  for (const id of ids) {
    if (
      !inside(id) ||
      id.includes('/node_modules/') ||
      !/\.(?:ts|tsx|vue|js)$/.test(id) ||
      !existsSync(id)
    )
      continue
    if (!/\b(?:createAppPageRouter|HilosView)\b/.test(readFileSync(id, 'utf8')))
      continue
    const file = source(id)
    if (id !== views && viewImports(file).length)
      fail(`unaccounted project outlet input: ${id}`)
    visit(file, (node) => {
      if (
        id !== routes &&
        ts.isCallExpression(node) &&
        sdkImport(
          file,
          node.expression,
          'createAppPageRouter',
          /^@hilos\/core(?:\/base)?$/,
        )
      )
        fail(`unaccounted project router input: ${id}`)
    })
  }
  function stable(file, name) {
    visit(file, (node) => {
      if (!ts.isIdentifier(node) || node.text !== name) return
      const parent = node.parent
      if (
        (ts.isVariableDeclaration(parent) ||
          ts.isPropertyDeclaration(parent)) &&
        parent.name === node
      )
        return
      if (ts.isJsxAttribute(parent) && parent.name === node) return
      if (
        ts.isJsxExpression(parent) &&
        parent.expression === node &&
        ts.isJsxAttribute(parent.parent) &&
        parent.parent.name.getText(file) === 'pages'
      )
        return
      if (
        ts.isCallExpression(parent) &&
        parent.arguments.includes(node) &&
        sdkImport(
          file,
          parent.expression,
          'createAppPageRouter',
          /^@hilos\/core(?:\/base)?$/,
        )
      )
        return
      fail(`mutable or escaped declaration: ${file.fileName}: ${name}`)
    })
  }
  visit(routeFile, (node) => {
    if (
      ts.isCallExpression(node) &&
      sdkImport(
        routeFile,
        node.expression,
        'createAppPageRouter',
        /^@hilos\/core(?:\/base)?$/,
      )
    )
      routers.push(node)
  })
  if (routers.length !== 1 || routers[0].arguments.length !== 1)
    fail(`expected one createAppPageRouter(map): ${routes}`)
  if (ts.isIdentifier(routers[0].arguments[0])) {
    const owner = local(routeFile, routers[0].arguments[0].text)
    if (owner.file !== routeFile) fail(`expected a local route map: ${routes}`)
    stable(owner.file, owner.name)
  }
  const projectRoutes = object(routeFile, routers[0].arguments[0])
  const frameworkKeys = new Set(routeMap.keys())
  readRoutes(projectRoutes.file, projectRoutes.node)
  const viewFile = source(views)
  const maps = []
  visit(viewFile, (node) => {
    if (
      (ts.isVariableDeclaration(node) || ts.isPropertyDeclaration(node)) &&
      ts.isIdentifier(node.name) &&
      node.name.text === 'pages'
    )
      maps.push(node)
  })
  if (maps.length !== 1) fail(`expected one stable pages map: ${views}`)
  if (
    ts.isVariableDeclaration(maps[0])
      ? !(maps[0].parent.flags & ts.NodeFlags.Const)
      : !(ts.getModifiers(maps[0]) ?? []).some(
          (node) => node.kind === ts.SyntaxKind.ReadonlyKeyword,
        )
  )
    fail(`mutable pages map: ${views}`)
  stable(viewFile, 'pages')
  if (
    !maps[0].initializer ||
    !ts.isObjectLiteralExpression(unwrap(maps[0].initializer))
  )
    fail(`expected a literal pages map: ${views}`)
  const outlets = viewImports(viewFile)
  const templates = []
  let bound = 0
  if (views.endsWith('.vue')) {
    const text = readFileSync(views, 'utf8')
    for (const match of text.matchAll(
      /<template\b[^>]*>([\s\S]*)<\/template>/g,
    ))
      templates.push(match[1])
  }
  visit(viewFile, (node) => {
    if (
      (ts.isJsxSelfClosingElement(node) || ts.isJsxOpeningElement(node)) &&
      outlets.includes(node.tagName.getText(viewFile))
    ) {
      const props = node.attributes.properties.filter(
        (item) =>
          ts.isJsxAttribute(item) && item.name.getText(viewFile) === 'pages',
      )
      if (
        props.length !== 1 ||
        !props[0].initializer ||
        !ts.isJsxExpression(props[0].initializer) ||
        props[0].initializer.expression?.getText(viewFile) !== 'pages'
      )
        fail(`unsupported HilosView pages binding: ${views}`)
      bound++
    }
    if (
      ts.isDecorator(node) &&
      ts.isCallExpression(node.expression) &&
      sdkImport(
        viewFile,
        node.expression.expression,
        'Component',
        /^@angular\/core$/,
      )
    ) {
      const config = node.expression.arguments[0]
      if (config && ts.isObjectLiteralExpression(config)) {
        for (const prop of config.properties) {
          if (
            ts.isPropertyAssignment(prop) &&
            prop.name.getText(viewFile) === 'template' &&
            (ts.isStringLiteral(prop.initializer) ||
              ts.isNoSubstitutionTemplateLiteral(prop.initializer))
          )
            templates.push(prop.initializer.text)
        }
      }
    }
  })
  for (const template of templates) {
    const text = template.replace(/<!--[\s\S]*?-->/g, '')
    for (const tag of text.matchAll(/<([\w-]+)\b[^>]*>/g)) {
      if (
        !outlets.includes(tag[1]) &&
        !(tag[1] === 'hilos-view' && outlets.length === 1)
      )
        continue
      if (!/(?:\[pages\]|:pages)\s*=\s*["']pages["']/.test(tag[0]))
        fail(`unsupported HilosView pages binding: ${views}`)
      bound++
    }
  }
  if (bound !== 1)
    fail(`pages map is not uniquely bound to HilosView: ${views}`)
  const map = object(viewFile, maps[0].initializer)
  const bindings = []
  const pages = new Set()
  for (const entry of map.node.properties) {
    if (ts.isSpreadAssignment(entry)) {
      const call = unwrap(entry.expression)
      if (
        !ts.isCallExpression(call) ||
        call.arguments.length ||
        !sdkImport(
          map.file,
          call.expression,
          'hilosAdminViews',
          /^@hilos\/(?:vue|react|angular)\/admin-views$/,
        )
      )
        fail(`unsupported page-map spread: ${views}`)
      continue
    }
    if (!ts.isPropertyAssignment(entry))
      fail(`unsupported page binding: ${views}`)
    const page = key(map.file, entry.name)
    const route = routeMap.get(page)
    if (!route || pages.has(page))
      fail(`missing or ambiguous route for binding: ${page}`)
    pages.add(page)
    const expression = unwrap(entry.initializer)
    let target
    let mode = 'eager'
    if (ts.isIdentifier(expression)) target = local(map.file, expression.text)
    else if (
      ts.isCallExpression(expression) &&
      expression.arguments.length === 1 &&
      sdkImport(
        map.file,
        expression.expression,
        'lazyPage',
        /^@hilos\/core(?:\/base)?$/,
      )
    ) {
      mode = 'lazy'
      const load = expression.arguments[0]
      const body =
        ts.isArrowFunction(load) && !load.parameters.length
          ? unwrap(load.body)
          : null
      const select =
        body &&
        ts.isCallExpression(body) &&
        body.arguments.length === 1 &&
        ts.isPropertyAccessExpression(body.expression) &&
        body.expression.name.text === 'then'
          ? body.arguments[0]
          : null
      const call = select ? body.expression.expression : null
      if (
        !select ||
        !ts.isArrowFunction(select) ||
        select.parameters.length !== 1 ||
        !ts.isIdentifier(select.parameters[0].name) ||
        select.parameters[0].initializer ||
        select.parameters[0].dotDotDotToken ||
        !ts.isPropertyAccessExpression(select.body) ||
        !ts.isIdentifier(select.body.expression) ||
        select.body.expression.text !== select.parameters[0].name.text ||
        !ts.isCallExpression(call) ||
        call.expression.kind !== ts.SyntaxKind.ImportKeyword ||
        call.arguments.length !== 1 ||
        !ts.isStringLiteral(call.arguments[0])
      )
        fail(`unresolved lazy component: ${page}`)
      target = exported(
        moduleFile(map.file, call.arguments[0].text),
        select.body.name.text,
        new Set(),
      )
    } else fail(`unresolved component binding: ${page}`)
    const id = target.file.fileName
    if (!inside(id) || id.includes('/node_modules/'))
      fail(`component is not project-owned: ${page}: ${id}`)
    if (!ids.has(id))
      fail(`bound component is not a client input: ${page}: ${id}`)
    bindings.push({
      page,
      ...route,
      id,
      export: target.name,
      mode,
      binding: {
        source: map.file.fileName,
        line:
          map.file.getLineAndCharacterOfPosition(entry.getStart(map.file))
            .line + 1,
      },
    })
  }
  if (!bindings.length) fail(`empty page bindings: ${views}`)
  for (const page of routeMap.keys()) {
    if (!frameworkKeys.has(page) && !pages.has(page))
      fail(`missing project route binding: ${page}`)
  }
  const admin = new Map()
  for (const binding of bindings.filter((entry) => entry.admin)) {
    const chain = `${binding.source}:${binding.line} (${binding.page}, admin:true) -> ${binding.binding.source}:${binding.binding.line} -> ${binding.id}#${binding.export}`
    admin.set(binding.id, [...(admin.get(binding.id) ?? []), chain])
  }
  return {
    report: {
      status: 'verified',
      routes,
      views,
      bindings,
      sources: [...snapshots]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([id, hash]) => ({ id, sha256: hash })),
    },
    verdict: (id) => {
      const path = clean(id)
      const canonical = existsSync(path) ? slash(realpathSync(path)) : path
      const chains = admin.get(canonical)
      return chains ? `project admin component: ${chains.join('; ')}` : null
    },
  }
}
