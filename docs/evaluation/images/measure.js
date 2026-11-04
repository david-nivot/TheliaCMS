// Measures every <img> of the page: what the browser announced, what it chose,
// and how well the choice fits the size the image is drawn at.
// One arrow function, so it can be pasted in a console as `await (<file>)()`
// or given to a browser-automation `evaluate_script` as it is. See README.md.
async () => {
  const images = [...document.images];

  // Layout shifts, from the start of the measure (the images are loaded here,
  // so a box that does not hold its size before its file arrives shows up).
  // Entries already buffered by the browser are read too.
  let shift = 0;
  const observer = new PerformanceObserver((list) => {
    list.getEntries().forEach((entry) => { if (!entry.hadRecentInput) { shift += entry.value; } });
  });
  observer.observe({ type: 'layout-shift', buffered: true });

  // A lazy image far below the fold would report no candidate yet.
  images.forEach((image) => { image.loading = 'eager'; });

  await Promise.all(images.map((image) => (image.complete && image.currentSrc
    ? Promise.resolve()
    : new Promise((resolve) => {
      image.addEventListener('load', resolve, { once: true });
      image.addEventListener('error', resolve, { once: true });
      setTimeout(resolve, 8000);
    }))));

  // The `w` descriptor of the candidate the browser took, read from the
  // srcset of the <img> and of the <source> elements of its <picture>.
  const widthOfCandidate = (image) => {
    const sets = [image.getAttribute('srcset') || ''];
    const picture = image.closest('picture');

    if (picture) {
      picture.querySelectorAll('source').forEach((source) => sets.push(source.getAttribute('srcset') || ''));
    }

    // A URL of the image route holds a comma (`480,!`): the entries are read
    // as "<url> <width>w" pairs, never split on commas.
    for (const set of sets) {
      for (const [, url, width] of set.matchAll(/(\S+)\s+(\d+)w/g)) {
        if (new URL(url, location.href).href === image.currentSrc) {
          return parseInt(width, 10);
        }
      }
    }

    return null;
  };

  // `{id}/{region}/{size}/...` of the image route: the part of the file asked.
  const regionOf = (image) => {
    const match = image.currentSrc.match(/\/image-library\/\d+\/([^/]+)\//);
    return match ? match[1] : null;
  };

  // The colour of the four corners of the file the browser took (inset by 4
  // px), as hex, for a picture whose quarters are plain colours: it says which
  // part of the original the file shows. Null for a file the canvas refuses
  // (another origin) or that is not loaded.
  const cornersOf = (image) => {
    try {
      const width = image.naturalWidth;
      const height = image.naturalHeight;
      if (!width || !height) { return null; }
      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      const context = canvas.getContext('2d');
      context.drawImage(image, 0, 0);
      const hex = (x, y) => `#${[...context.getImageData(x, y, 1, 1).data.slice(0, 3)].map((value) => value.toString(16).padStart(2, '0')).join('')}`;
      return [hex(4, 4), hex(width - 5, 4), hex(4, height - 5), hex(width - 5, height - 5)];
    } catch (error) {
      return null;
    }
  };

  const dpr = window.devicePixelRatio;
  const round = (value) => (value === null ? null : Math.round(value * 100) / 100);
  const where = (image) => {
    const holder = image.closest('[data-cms-section]');
    return holder ? holder.getAttribute('data-cms-section') : '(none)';
  };

  const rows = images.map((image) => {
    // Without a srcset the only candidate is the file itself (0 for an SVG
    // that states no size: the ratios are then null).
    const candidate = widthOfCandidate(image) ?? image.naturalWidth;
    const drawn = image.clientWidth * dpr;

    return {
      context: where(image),
      alt: image.alt,
      sizes: image.getAttribute('sizes'),
      currentSrc: image.currentSrc.replace(location.origin, ''),
      candidate,
      naturalWidth: image.naturalWidth,
      clientWidth: image.clientWidth,
      clientHeight: image.clientHeight,
      // Width over height of the box the image is drawn in, as the CSS gives it.
      boxRatio: image.clientHeight ? round(image.clientWidth / image.clientHeight) : null,
      region: regionOf(image),
      // Top left, top right, bottom left, bottom right of the file served.
      corners: cornersOf(image),
      dpr,
      // Above 1: drawn larger than the file offers, so blurred.
      upscale: candidate ? round(drawn / candidate) : null,
      // Above 1.5: a bigger file than the screen needs was downloaded.
      overload: drawn ? round(candidate / drawn) : null,
    };
  });

  // The rows as objects stay in `window.imageRows`. `lines` is the same table,
  // one string per image, for a terminal; `sizes` lists the distinct values
  // of the attribute, `s0`, `s1`... in the lines.
  window.imageRows = rows;
  const sizes = [...new Set(rows.map((row) => row.sizes))];
  const lines = rows.map((row) => [
    row.context, `s${sizes.indexOf(row.sizes)}`, row.currentSrc.replace(/^\/image-library\//, '').replace(/\/0\/default/, ''),
    `region ${row.region}`, `cand ${row.candidate}`, `shown ${row.clientWidth}x${row.clientHeight}`, `ratio ${row.boxRatio}`,
    `up ${row.upscale}`, `over ${row.overload}`, row.corners ? row.corners.join(' ') : '-',
  ].join(' | '));

  // Let the last shifts be reported before the total is read.
  await new Promise((resolve) => setTimeout(resolve, 300));
  observer.disconnect();

  return { viewport: `${window.innerWidth}x${window.innerHeight}`, dpr, layoutShift: round(shift), sizes, lines };
}
