// Browser check of a round of the bench of the generator of components
// (docs/evaluation/components/README.md). Not part of the PHPUnit suites and
// not run in CI: see "Browser checks" in docs/developing.md.
//
// For every run of the round whose outcome is a component, it opens the
// signed preview of the candidate at 375 and 1440 px, measures the horizontal
// scroll of the page, runs axe-core on the component, takes a capture of it
// next to the run file, and writes what it saw under `browser` in the run
// file. `thelia_cms:component-bench:check` reads that block. Read-only on the
// shop: it opens previews and nothing else.
//
// BENCH_ROUND_DIR=<host>/var/eval/components/<round> \
// NODE_PATH=<dir>/node_modules (playwright and axe-core) \
// [CHROME_PATH=<chromium>] [FORCE=1] node Tests/Browser/component-bench.mjs
import { createRequire } from 'node:module';
import { readdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';

const require = createRequire(import.meta.url);
let chromium;
try {
    ({ chromium } = require('playwright'));
} catch {
    try {
        ({ chromium } = require('playwright-core'));
    } catch {
        fail('the "playwright" library is not found: point NODE_PATH at a node_modules that holds it');
    }
}

let axeSource;
try {
    axeSource = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
} catch {
    fail('the "axe-core" library is not found: point NODE_PATH at a node_modules that holds it');
}

function fail(message) {
    console.error('FAIL ' + message);
    process.exit(1);
}

const roundDir = process.env.BENCH_ROUND_DIR;
if (!roundDir || !existsSync(roundDir)) {
    fail('BENCH_ROUND_DIR names no directory');
}

const force = '1' === process.env.FORCE;
const widths = [375, 1440];
const executablePath = process.env.CHROME_PATH || undefined;
const browser = await chromium.launch({ headless: true, executablePath }).catch((error) => {
    fail('Chromium does not start (' + error.message + '): set CHROME_PATH');
});

let checked = 0;
let failed = 0;

for (const caseId of readdirSync(roundDir, { withFileTypes: true }).filter((entry) => entry.isDirectory()).map((entry) => entry.name).sort()) {
    const caseDir = join(roundDir, caseId);

    for (const file of readdirSync(caseDir).filter((name) => /^\d+\.json$/.test(name)).sort()) {
        const path = join(caseDir, file);
        const run = JSON.parse(readFileSync(path, 'utf8'));

        if ('component' !== run.outcome || !run.preview_url || (run.browser && !force)) {
            continue;
        }

        const seen = { checked_at: new Date().toISOString() };

        for (const width of widths) {
            seen[String(width)] = await look(run.preview_url, width, join(caseDir, file.replace('.json', `-${width}.png`)));
        }

        run.browser = seen;
        writeFileSync(path, JSON.stringify(run, null, 2) + '\n');
        checked++;

        const problems = widths.flatMap((width) => {
            const at = seen[String(width)];
            const serious = (at.axe || []).filter((violation) => ['serious', 'critical'].includes(violation.impact));

            return [
                ...(at.error ? [`${width}px: ${at.error}`] : []),
                ...(at.scroll ? [`${width}px: horizontal scroll (${at.scroll_width} > ${at.client_width})`] : []),
                ...serious.map((violation) => `${width}px: axe ${violation.id} (${violation.impact}, ${violation.nodes} node(s))`),
            ];
        });

        if (problems.length > 0) {
            failed++;
        }

        console.log(`${problems.length > 0 ? 'FAIL' : 'ok  '} ${caseId}/${file} ${problems.join('; ')}`);
    }
}

await browser.close();
console.log(`${checked} run(s) checked, ${failed} with a problem`);

async function look(url, width, capture) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });

    try {
        const response = await page.goto(url, { waitUntil: 'networkidle' });

        if (!response || 200 !== response.status()) {
            return { error: `the preview answers ${response ? response.status() : 'nothing'} (expired? run check --refresh-previews)` };
        }

        const component = page.locator('.cms-site').first();

        if (0 === await component.count()) {
            return { error: 'no .cms-site element on the preview' };
        }

        const { scrollWidth, clientWidth } = await page.evaluate(() => ({
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
        }));

        await page.addScriptTag({ content: axeSource });
        const violations = await page.evaluate(async () => {
            const result = await window.axe.run(document.querySelector('.cms-site'), { resultTypes: ['violations'] });

            return result.violations.map((violation) => ({ id: violation.id, impact: violation.impact, nodes: violation.nodes.length }));
        });

        await component.screenshot({ path: capture });

        return { scroll: scrollWidth > clientWidth + 1, scroll_width: scrollWidth, client_width: clientWidth, axe: violations, capture };
    } catch (error) {
        return { error: error.message.split('\n')[0] };
    } finally {
        await page.close();
    }
}
