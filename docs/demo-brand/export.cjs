// Optional raster export only. The editable sources/*.svg files are the masters.
// Run with Playwright 1.60.0; see README.md for the isolated container command.
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('playwright');

const demos = [
  'chat',
  'tasks',
  'polls',
  'binance-btc-tracker',
  'ecommerce-shop',
  'online-testing',
];

async function main() {
  const [destination, ...requestedSizes] = process.argv.slice(2);
  const sizes = requestedSizes.length ? requestedSizes.map(Number) : [16, 32, 64];
  if (!destination || sizes.some(size => !Number.isInteger(size) || size < 16 || size > 4096)) {
    throw new Error('Usage: node export.cjs OUTPUT_DIRECTORY [SIZE ...] (16–4096 pixels)');
  }
  await fs.mkdir(destination, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    await page.route('**/*', route => route.abort());
    console.log(`Node ${process.version}; Playwright ${require('playwright/package.json').version}; Chromium ${browser.version()}`);
    for (const demo of demos) {
      const svg = await fs.readFile(path.join(__dirname, 'sources', `${demo}.svg`));
      for (const size of sizes) {
        const result = await page.evaluate(async ({ source, size }) => {
          const image = new Image();
          image.src = source;
          await image.decode();
          const canvas = document.createElement('canvas');
          canvas.width = canvas.height = size;
          const context = canvas.getContext('2d');
          context.drawImage(image, 0, 0, size, size);
          const pixels = context.getImageData(0, 0, size, size).data;
          let transparent = 0;
          let painted = 0;
          for (let index = 3; index < pixels.length; index += 4) {
            if (pixels[index] === 0) transparent++;
            else painted++;
          }
          return { png: canvas.toDataURL('image/png').split(',')[1], transparent, painted };
        }, { source: `data:image/svg+xml;base64,${svg.toString('base64')}`, size });
        if (!result.transparent || !result.painted) throw new Error(`Invalid alpha coverage: ${demo} ${size}`);
        const png = Buffer.from(result.png, 'base64');
        if (png.readUInt32BE(16) !== size || png.readUInt32BE(20) !== size) {
          throw new Error(`Unexpected PNG dimensions: ${demo} ${size}`);
        }
        const target = path.join(destination, `${demo}-${size}.png`);
        await fs.writeFile(target, png);
        console.log(`${target}: ${size}×${size}, transparent=${result.transparent}, painted=${result.painted}`);
      }
    }
  } finally {
    await browser.close();
  }
}

main().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
