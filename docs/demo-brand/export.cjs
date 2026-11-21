// Optional raster export only. The editable sources/*.svg files are the masters.
// Run with Playwright 1.60.0; see README.md for the isolated container command.
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('playwright');

const names = [
  'hilos',
  'binance-btc-tracker',
  'chat',
  'concert-tickets',
  'ecommerce-shop',
  'file-gallery-manager',
  'online-testing',
  'pmd',
  'polls',
  'preferans',
  'roblox-game-server',
  'solar-system-model',
  'tasks',
];
const variants = ['small', 'medium', 'large'];

const TILE_SIZE = 180;
const TILE_PADDING = 20;
const PREVIEW_SIZE = 512;
const PREVIEW_PADDING = 57; // the phone tile's 20 of 180, scaled to 512
const TILE_BACKGROUND = '#FFFFFF';
const ICO_SIZES = [16, 32];

async function main() {
  const [destination, ...requestedSizes] = process.argv.slice(2);
  const sizes = requestedSizes.length ? requestedSizes.map(Number) : [16, 32, 64, 180];
  if (!destination || sizes.some(size => !Number.isInteger(size) || size < 16 || size > 4096)) {
    throw new Error('Usage: node export.cjs OUTPUT_DIRECTORY [SIZE ...] (16–4096 pixels)');
  }
  await fs.mkdir(destination, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  let written = 0;
  try {
    const page = await browser.newPage();
    await page.route('**/*', route => route.abort());
    console.log(`Node ${process.version}; Playwright ${require('playwright/package.json').version}; Chromium ${browser.version()}`);
    const write = async (file, png, report) => {
      const target = path.join(destination, file);
      await fs.writeFile(target, png);
      written++;
      console.log(`${target}: ${report}`);
    };
    for (const name of names) {
      const sources = {};
      for (const variant of variants) {
        sources[variant] = await fs.readFile(path.join(__dirname, 'sources', `${name}-${variant}.svg`));
      }
      for (const variant of variants) {
        for (const size of sizes) {
          const result = await rasterize(page, sources[variant], size);
          const label = `${name}-${variant}-${size}`;
          checkTransparent(result, label);
          checkDimensions(result.png, size, label);
          await write(`${label}.png`, result.png, `${size}×${size}, transparent=${result.transparent}, painted=${result.painted}`);
        }
      }

      const tile = await rasterize(page, sources.large, TILE_SIZE, { background: TILE_BACKGROUND, padding: TILE_PADDING });
      const tileLabel = `${name}-touch-${TILE_SIZE}`;
      if (tile.transparent || tile.partial) {
        throw new Error(`Tile not opaque: ${tileLabel} transparent=${tile.transparent} partial=${tile.partial}`);
      }
      checkDimensions(tile.png, TILE_SIZE, tileLabel);
      await write(`${tileLabel}.png`, tile.png, `${TILE_SIZE}×${TILE_SIZE} on ${TILE_BACKGROUND}, padding ${TILE_PADDING}, opaque=${tile.painted}`);

      const preview = await rasterize(page, sources.large, PREVIEW_SIZE, { background: TILE_BACKGROUND, padding: PREVIEW_PADDING });
      const previewLabel = `${name}-preview-${PREVIEW_SIZE}`;
      if (preview.transparent || preview.partial) {
        throw new Error(`Preview not opaque: ${previewLabel} transparent=${preview.transparent} partial=${preview.partial}`);
      }
      checkDimensions(preview.png, PREVIEW_SIZE, previewLabel);
      await write(`${previewLabel}.png`, preview.png, `${PREVIEW_SIZE}×${PREVIEW_SIZE} on ${TILE_BACKGROUND}, padding ${PREVIEW_PADDING}, opaque=${preview.painted}`);

      const images = [];
      for (const size of ICO_SIZES) {
        const result = await rasterize(page, sources.small, size);
        checkTransparent(result, `${name}.ico ${size}`);
        checkDimensions(result.png, size, `${name}.ico ${size}`);
        images.push({ size, png: result.png });
      }
      const ico = buildIco(images);
      checkIco(ico, images, `${name}.ico`);
      await write(`${name}.ico`, ico, `ICO ${images.map(({ size, png }) => `${size}×${size} PNG ${png.length} B`).join(', ')}`);
    }
  } finally {
    await browser.close();
  }
  console.log(`${written} files written to ${destination}`);
}

async function rasterize(page, svg, size, tile = null) {
  const result = await page.evaluate(async ({ source, size, tile }) => {
    const image = new Image();
    image.src = source;
    await image.decode();
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = size;
    const context = canvas.getContext('2d');
    let inset = 0;
    if (tile) {
      context.fillStyle = tile.background;
      context.fillRect(0, 0, size, size);
      inset = tile.padding;
    }
    context.drawImage(image, inset, inset, size - 2 * inset, size - 2 * inset);
    const pixels = context.getImageData(0, 0, size, size).data;
    let transparent = 0;
    let partial = 0;
    let painted = 0;
    for (let index = 3; index < pixels.length; index += 4) {
      if (pixels[index] === 0) transparent++;
      else painted++;
      if (pixels[index] !== 0 && pixels[index] !== 255) partial++;
    }
    return { png: canvas.toDataURL('image/png').split(',')[1], transparent, partial, painted };
  }, { source: `data:image/svg+xml;base64,${svg.toString('base64')}`, size, tile });
  return { ...result, png: Buffer.from(result.png, 'base64') };
}

function checkTransparent(result, label) {
  if (!result.transparent || !result.painted) throw new Error(`Invalid alpha coverage: ${label}`);
}

function checkDimensions(png, size, label) {
  if (png.readUInt32BE(16) !== size || png.readUInt32BE(20) !== size) {
    throw new Error(`Unexpected PNG dimensions: ${label}`);
  }
}

// ICO with PNG-compressed images: a 6-byte header, a 16-byte entry per image, then the PNGs.
function buildIco(images) {
  const header = Buffer.alloc(6 + 16 * images.length);
  header.writeUInt16LE(0, 0);
  header.writeUInt16LE(1, 2);
  header.writeUInt16LE(images.length, 4);
  let offset = header.length;
  images.forEach(({ size, png }, index) => {
    const entry = 6 + 16 * index;
    header.writeUInt8(size % 256, entry);
    header.writeUInt8(size % 256, entry + 1);
    header.writeUInt8(0, entry + 2);
    header.writeUInt8(0, entry + 3);
    header.writeUInt16LE(1, entry + 4);
    header.writeUInt16LE(32, entry + 6);
    header.writeUInt32LE(png.length, entry + 8);
    header.writeUInt32LE(offset, entry + 12);
    offset += png.length;
  });
  return Buffer.concat([header, ...images.map(({ png }) => png)]);
}

function checkIco(ico, images, label) {
  const signature = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  if (ico.readUInt16LE(0) !== 0 || ico.readUInt16LE(2) !== 1 || ico.readUInt16LE(4) !== images.length) {
    throw new Error(`Bad ICO header: ${label}`);
  }
  let expectedOffset = 6 + 16 * images.length;
  images.forEach(({ size, png }, index) => {
    const entry = 6 + 16 * index;
    const width = ico.readUInt8(entry) || 256;
    const height = ico.readUInt8(entry + 1) || 256;
    const length = ico.readUInt32LE(entry + 8);
    const offset = ico.readUInt32LE(entry + 12);
    const data = ico.subarray(offset, offset + length);
    if (width !== size || height !== size || length !== png.length || offset !== expectedOffset
      || !data.subarray(0, 8).equals(signature) || data.readUInt32BE(16) !== size || data.readUInt32BE(20) !== size) {
      throw new Error(`Bad ICO entry ${index}: ${label}`);
    }
    expectedOffset += length;
  });
  if (expectedOffset !== ico.length) throw new Error(`ICO length mismatch: ${label}`);
}

main().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
