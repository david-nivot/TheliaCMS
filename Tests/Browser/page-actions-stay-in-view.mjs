// Browser check of the action bar of the settings screen of a page, and of the
// Publish button of the content screen (back office). Not part of the PHPUnit
// suites and not run in CI: see "Browser checks" in docs/developing.md.
//
// Read-only: it logs in, opens the settings and the content screen, scrolls and
// tabs; it saves and publishes nothing and submits no form but the login.
import { createRequire } from 'node:module';

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

function fail(message) {
    console.error('FAIL ' + message);
    process.exit(1);
}

function env(name) {
    const value = process.env[name];

    if (!value) {
        fail('the environment variable ' + name + ' is required');
    }

    return value;
}

const baseUrl = env('CMS_BASE_URL').replace(/\/+$/, '');
const user = env('CMS_ADMIN_USER');
const password = env('CMS_ADMIN_PASSWORD');
const pageId = env('CMS_PAGE_ID');
const executablePath = process.env.CHROME_PATH || undefined;

let passed = 0;

function check(condition, message) {
    if (!condition) {
        fail(message);
    }

    passed++;
    console.log('ok   ' + message);
}

const pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

const browser = await chromium.launch({ headless: true, executablePath }).catch((error) => {
    fail('Chromium does not start (' + error.message.split('\n')[0] + '): set CHROME_PATH');
});
const context = await browser.newContext({ viewport: { width: 1440, height: 620 } });
const page = await context.newPage();
const problems = [];

page.on('pageerror', (error) => problems.push('pageerror: ' + error.message));
page.on('console', (message) => {
    if (message.type() === 'error') {
        problems.push('console: ' + message.text());
    }
});

// The back office scrolls smoothly: a measure taken while it moves says nothing.
async function settled() {
    let last = -1;
    let still = 0;

    for (let waited = 0; waited < 5000 && still < 3; waited += 100) {
        const y = await page.evaluate(() => Math.round(window.scrollY));

        still = y === last ? still + 1 : 0;
        last = y;
        await pause(100);
    }
}

const barBox = () => page.evaluate(() => {
    const bar = document.querySelector('[data-cms-page-save-bar]');

    if (!bar) {
        return null;
    }

    const box = bar.getBoundingClientRect();

    return {
        top: Math.round(box.top),
        bottom: Math.round(box.bottom),
        height: Math.round(box.height),
        position: getComputedStyle(bar).position,
        viewport: window.innerHeight,
        pageHeight: document.documentElement.scrollHeight,
    };
});

async function openSettings(tabTestId) {
    await page.goto(baseUrl + '/admin/cms/pages/' + pageId);

    if (!(await page.waitForSelector('[data-testid="cms-page-actions"]', { timeout: 15000 }).catch(() => null))) {
        fail('no action bar on /admin/cms/pages/' + pageId + ': wrong CMS_PAGE_ID (a saved page that is not a model), or login refused');
    }

    await page.getByTestId(tabTestId).click();
    await pause(500);
}

try {
    await page.goto(baseUrl + '/admin/login');
    await page.getByTestId('login-username').fill(user);
    await page.getByTestId('login-password').fill(password);
    await Promise.all([page.waitForNavigation(), page.getByTestId('login-submit').click()]);

    // 1. The bar stays in view on a long tab
    await openSettings('cms-page-tab-seo');
    await page.evaluate(() => window.scrollTo(0, 0));
    await pause(300);

    const top = await barBox();

    check(top.pageHeight > top.viewport, 'the SEO tab is taller than the window (' + top.pageHeight + ' > ' + top.viewport + '), so the check means something');
    check(top.position === 'sticky', 'the bar is sticky at 1440 (position: ' + top.position + ')');
    check(top.top >= 0 && top.bottom <= top.viewport, 'scrolled to the top, the bar is inside the window (' + top.top + ' to ' + top.bottom + ' of ' + top.viewport + ')');

    const buttons = await page.getByTestId('cms-page-actions').locator('button').evaluateAll((all) => all.map((button) => button.getAttribute('data-testid')));

    check(
        JSON.stringify(buttons) === JSON.stringify(['cms-page-save', 'cms-page-save-and-publish', 'cms-page-publish', 'cms-page-unpublish']),
        'the bar holds Save, Save and publish, Publish, Unpublish (' + buttons.join(', ') + ')',
    );

    // 2. What has the focus is never under the bar
    await page.evaluate(() => {
        window.scrollTo(0, 0);
        document.activeElement.blur();
        document.querySelector('#tab-seo input, #tab-seo textarea, #tab-seo select').focus();
    });

    let hidden = [];
    let focusedFields = 0;

    for (let step = 0; step < 40; step++) {
        await settled();

        const covered = await page.evaluate(() => {
            const bar = document.querySelector('[data-cms-page-save-bar]');
            const focused = document.activeElement;

            if (!focused || focused === document.body || bar.contains(focused)) {
                return null;
            }

            const box = focused.getBoundingClientRect();

            if (box.width === 0 || box.height === 0) {
                return null;
            }

            // The point of the field the bar would hide first: its bottom edge.
            const under = document.elementFromPoint(box.left + box.width / 2, Math.min(box.bottom - 1, window.innerHeight - 1));

            return { field: focused.tagName + '#' + focused.id, covered: bar.contains(under) };
        });

        if (covered !== null) {
            focusedFields++;

            if (covered.covered) {
                hidden.push(covered.field);
            }
        }

        await page.keyboard.press('Tab');
        await settled();
    }

    check(focusedFields >= 10, 'tabbing reached ' + focusedFields + ' fields');
    check(hidden.length === 0, 'no focused field is under the bar' + (hidden.length ? ' (hidden: ' + hidden.join(', ') + ')' : ''));

    // 3. Zoomed to 400 %: a window of 360 px wide
    await page.setViewportSize({ width: 360, height: 640 });
    await pause(400);

    const zoomed = await barBox();

    check(
        zoomed.position !== 'sticky' || zoomed.height <= zoomed.viewport / 3,
        'at 360 px the bar is not sticky or covers at most a third of the window (position ' + zoomed.position + ', ' + zoomed.height + ' of ' + zoomed.viewport + ')',
    );

    // 4. Publish stays in the first row of the header of the content screen
    for (const width of [1440, 1024]) {
        await page.setViewportSize({ width, height: 620 });
        await page.goto(baseUrl + '/admin/cms/pages/' + pageId + '/content');
        await page.waitForSelector('[data-testid="cms-content-publish"]', { timeout: 15000 }).catch(() => fail('no Publish button on the content screen of page ' + pageId));
        await pause(500);

        const rows = await page.evaluate(() => {
            const centre = (element) => {
                const box = element.getBoundingClientRect();

                return box.top + box.height / 2;
            };
            const publish = document.querySelector('[data-testid="cms-content-publish"] button');
            const form = publish.closest('form');
            const others = [...document.querySelectorAll('.cms-content__actions > *')].filter((element) => element !== form && element.getBoundingClientRect().height > 0);
            const row = centre(publish);
            const left = form.getBoundingClientRect().left;

            return {
                // First line of the actions: no other action is above it.
                firstRow: others.every((element) => centre(element) >= row - 20),
                // Not alone on a line, and first of it: another action shares the row, to its right.
                shared: others.some((element) => Math.abs(centre(element) - row) < 20 && element.getBoundingClientRect().left > left),
            };
        });

        check(rows.firstRow, 'at ' + width + ' px Publish is on the first line of the actions of the header');
        check(rows.shared, 'at ' + width + ' px Publish is not alone on its line');
    }

    // 5. Errors
    check(problems.length === 0, 'no console error and no pageerror' + (problems.length ? ': ' + problems.join(' | ') : ''));
} finally {
    await browser.close();
}

console.log(passed + ' checks passed');
