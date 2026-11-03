// Author tooling only: importing this module does not read stdin or start Chromium.
import { randomInt } from 'node:crypto'
import { createRequire } from 'node:module'
import { join, resolve } from 'node:path'
import process from 'node:process'
import { pathToFileURL } from 'node:url'

const HELP = `Usage: composer run test:e2e-time-zone -- [--count N] [--exclude IANA]...
Use test:cluster:e2e-time-zone for a cluster browser entry.
The command reads PHP project metadata on stdin, including with --help.
--count N       Print N different zones (default: 1).
--exclude IANA  Exclude a saved role and its aliases; may be repeated.
--help          Print this help without starting Chromium.
Copy the output into explicit timezoneId literals once; keep them on test runs.
`
const ZONE_NAME = /^[A-Za-z][A-Za-z0-9._+-]*(?:\/[A-Za-z0-9._+-]+)*$/

function isZoneName(value) {
  return (
    typeof value === 'string' && value.trim() === value && ZONE_NAME.test(value)
  )
}

function argumentsFrom(argv) {
  let count = 1
  let countSeen = false
  let help = false
  const exclude = []
  for (let index = 0; index < argv.length; index++) {
    const arg = argv[index]
    if (arg === '--help') {
      help = true
    } else if (arg === '--count') {
      const value = argv[++index]
      if (
        countSeen ||
        !/^[1-9][0-9]*$/.test(value ?? '') ||
        value.trim() !== value ||
        !Number.isSafeInteger(Number(value))
      ) {
        throw new Error('--count requires one positive integer.')
      }
      count = Number(value)
      countSeen = true
    } else if (arg === '--exclude') {
      const value = argv[++index]
      if (!isZoneName(value)) {
        throw new Error('--exclude requires an IANA time zone name.')
      }
      exclude.push(value)
    } else {
      throw new Error('Unknown argument. Use --help for supported options.')
    }
  }
  return { count, exclude, help }
}

function metadataFrom(input) {
  let metadata
  try {
    metadata = JSON.parse(input)
  } catch {
    throw new Error('Expected PHP project time zone metadata as JSON on stdin.')
  }
  if (
    metadata === null ||
    typeof metadata !== 'object' ||
    Array.isArray(metadata) ||
    Object.keys(metadata).length !== 2 ||
    !isZoneName(metadata.projectTimeZone) ||
    !Array.isArray(metadata.timeZones) ||
    metadata.timeZones.length === 0 ||
    metadata.timeZones.some((zone) => !isZoneName(zone)) ||
    !metadata.timeZones.includes(metadata.projectTimeZone)
  ) {
    throw new Error('Invalid PHP project time zone metadata.')
  }
  return metadata
}

async function readInput() {
  process.stdin.setEncoding('utf8')
  let input = ''
  for await (const chunk of process.stdin) input += chunk
  return input
}

function launchBrowser(cwd) {
  const { chromium } = createRequire(join(cwd, 'package.json'))(
    '@playwright/test',
  )
  return chromium.launch()
}

async function readCatalog(page, required) {
  return page.evaluate((zones) => {
    const timeZones = globalThis.Intl.supportedValuesOf('timeZone')
    const canonical = Object.fromEntries(
      [...new Set([...timeZones, ...zones])].map((zone) => {
        try {
          return [
            zone,
            new globalThis.Intl.DateTimeFormat('en', {
              timeZone: zone,
            }).resolvedOptions().timeZone,
          ]
        } catch (error) {
          if (!(error instanceof RangeError)) throw error
          return [zone, null]
        }
      }),
    )
    return { timeZones, canonical }
  }, required)
}

/**
 * Generate saved browser-zone literals, writing the complete selection only.
 *
 * @param {string[]} argv Author options, without the script name.
 * @param {object} options Injectable stdin, output, randomness and browser boundaries.
 * @returns {Promise<number>} Zero on success, nonzero on any refusal.
 */
export async function runTimeZoneGenerator(argv, options = {}) {
  const stdout = options.stdout ?? ((chunk) => process.stdout.write(chunk))
  const stderr = options.stderr ?? ((chunk) => process.stderr.write(chunk))
  try {
    const { count, exclude, help } = argumentsFrom(argv)
    // Validate even for help: an upstream PHP failure must fail the pipeline.
    const metadata = metadataFrom(await (options.readInput ?? readInput)())
    if (help) {
      stderr(HELP)
      return 0
    }
    const browser = await (options.launchBrowser ?? launchBrowser)(
      options.cwd ?? process.cwd(),
    )
    let context
    let selected
    try {
      context = await browser.newContext()
      const page = await context.newPage()
      // A new page is blank. No product URL, config or globalSetup is loaded.
      const required = ['UTC', metadata.projectTimeZone, ...exclude]
      const { timeZones, canonical } = await (
        options.readCatalog ?? readCatalog
      )(page, required)
      if (required.some((zone) => !canonical[zone])) {
        throw new Error(
          'The project or an excluded time zone is unsupported by Chromium.',
        )
      }
      const excluded = new Set(required.map((zone) => canonical[zone]))
      const phpNames = new Set(metadata.timeZones)
      const candidates = new Map()
      for (const zone of timeZones) {
        const identity = canonical[zone]
        if (
          phpNames.has(zone) &&
          identity &&
          !excluded.has(identity) &&
          !candidates.has(identity)
        ) {
          candidates.set(identity, zone)
        }
      }
      if (count > candidates.size) {
        throw new Error(
          `Requested ${count} zones, but only ${candidates.size} different candidates remain.`,
        )
      }
      const pool = [...candidates.values()]
      selected = []
      const draw = options.randomInt ?? randomInt
      while (selected.length < count) {
        selected.push(pool.splice(draw(pool.length), 1)[0])
      }
    } finally {
      try {
        await context?.close()
      } finally {
        await browser.close()
      }
    }
    stdout(selected.join('\n') + '\n')
    return 0
  } catch (error) {
    stderr(`e2e-time-zone: ${error.message}\n`)
    return 1
  }
}

if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(resolve(process.argv[1])).href
) {
  process.exitCode = await runTimeZoneGenerator(process.argv.slice(2))
}
