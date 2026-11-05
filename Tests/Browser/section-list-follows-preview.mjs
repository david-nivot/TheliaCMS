// Browser check of "the section list follows the preview" (content screen of a
// page, back office). Not part of the PHPUnit suites and not run in CI: see
// "Browser checks" in docs/developing.md.
//
// Read-only: it logs in, opens the content screen and scrolls; it saves
// nothing and submits no form but the login.
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

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
const editorScript = process.env.CMS_EDITOR_SCRIPT || '';
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

let served = 0;

if (editorScript) {
    const body = readFileSync(editorScript, 'utf8');

    await page.route('**/content-editor.js*', (route) => {
        served++;

        return route.fulfill({ status: 200, contentType: 'application/javascript', body });
    });
}

async function state() {
    return page.evaluate(() => {
        const items = [...document.querySelectorAll('[data-cms-section-item]')];
        const panel = document.querySelector('.cms-content__panel');
        const marked = items.filter((item) => item.classList.contains('is-current'));
        const area = panel.getBoundingClientRect();
        const box = marked.length === 1 ? marked[0].getBoundingClientRect() : null;

        return {
            order: items.map((item) => item.getAttribute('data-section')),
            current: items.findIndex((item) => item.classList.contains('is-current')),
            currentCount: marked.length,
            ariaCount: items.filter((item) => item.hasAttribute('aria-current')).length,
            panelTop: Math.round(panel.scrollTop),
            overflows: panel.scrollHeight > panel.clientHeight + 1,
            visible: box !== null && box.top >= area.top - 1 && box.bottom <= area.bottom + 1,
            pageY: Math.round(window.scrollY),
            active: document.activeElement === document.body
                ? 'body'
                : document.activeElement.tagName + '#' + document.activeElement.id + '.' + document.activeElement.className,
        };
    });
}

const previewScroll = (call) => page.evaluate(
    (code) => new Function('win', code)(document.querySelector('[data-cms-frame]').contentWindow),
    call,
);

async function wheelOnPreview(delta) {
    const box = await page.locator('[data-cms-frame]').first().boundingBox();

    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.wheel(0, delta);
}

try {
    await page.goto(baseUrl + '/admin/login');
    await page.getByTestId('login-username').fill(user);
    await page.getByTestId('login-password').fill(password);
    await Promise.all([page.waitForNavigation(), page.getByTestId('login-submit').click()]);

    await page.goto(baseUrl + '/admin/cms/pages/' + pageId + '/content');

    if (!(await page.waitForSelector('[data-cms-frame]', { timeout: 15000 }).catch(() => null))) {
        fail('no preview frame on /admin/cms/pages/' + pageId + '/content: wrong CMS_PAGE_ID, login refused, or no section');
    }

    await page.waitForFunction(() => {
        const frame = document.querySelector('[data-cms-frame]');

        return frame && frame.contentDocument && frame.contentDocument.querySelector('[data-cms-section]');
    }, null, { timeout: 15000 }).catch(() => fail('the preview frame shows no [data-cms-section]'));
    await pause(1500);

    if (editorScript) {
        check(served > 0, 'CMS_EDITOR_SCRIPT is served in place of the site content-editor.js');
    }

    const start = await state();

    check(start.overflows, 'the section list is taller than its panel (the page needs sections that overflow it)');
    check(
        (await previewScroll('return win.document.documentElement.scrollHeight > win.innerHeight * 2')),
        'the preview is at least twice its own height (the page needs enough content to scroll)',
    );

    // 1. At opening
    check(start.currentCount === 1 && start.current === 0, 'opening: exactly one section is marked, the first (marked: ' + start.currentCount + ', index: ' + start.current + ')');
    check(start.ariaCount === 1, 'opening: exactly one aria-current (' + start.ariaCount + ')');

    // 2. Wheel on the preview
    const focusBefore = start.active;
    let moved = start;

    for (let step = 0; step < 8 && moved.current === 0; step++) {
        await wheelOnPreview(400);
        await pause(600);
        moved = await state();
    }

    check(moved.current > 0, 'scrolling the preview: the mark leaves the first section (index: ' + moved.current + ')');
    await pause(1000);
    moved = await state();
    check(moved.currentCount === 1, 'scrolling the preview: one .is-current (' + moved.currentCount + ')');
    check(moved.ariaCount === 1, 'scrolling the preview: one aria-current (' + moved.ariaCount + ')');
    check(moved.visible, 'scrolling the preview: the marked section is inside the visible part of the panel');
    check(moved.panelTop > 0, 'scrolling the preview: the panel scrolled to follow (scrollTop ' + moved.panelTop + ')');
    check(moved.pageY === 0, 'scrolling the preview: the page itself did not scroll (scrollY ' + moved.pageY + ')');
    check(moved.active === focusBefore, 'scrolling the preview: the focus did not move (' + focusBefore + ' -> ' + moved.active + ')');

    // 3. Locate button of a far section
    await previewScroll('win.scrollTo(0, 0)');
    await pause(1500);
    const last = page.locator('[data-cms-section-item]').last();
    const lastId = await last.getAttribute('data-section');

    await last.locator('[data-cms-locate]').click();

    // Measured from the instant after the click: the tool scrolls the panel itself to reach the button.
    const settled = (await state()).panelTop;
    const samples = [];

    for (let elapsed = 0; elapsed < 1200; elapsed += 100) {
        await pause(100);
        samples.push((await state()).panelTop);
    }

    check(
        samples.every((top) => top === settled),
        'locate: the panel does not scroll for 1.2 s after the click (scrollTop ' + settled + ', samples ' + samples.join(',') + ')',
    );
    check(
        (await page.locator('[data-cms-section-item].is-current').getAttribute('data-section')) === lastId,
        'locate: the located section is the marked one',
    );

    // 4. Edit dialog open
    await previewScroll('win.scrollTo(0, 0)');
    await pause(1500);

    const opener = page.locator('[data-cms-section-item]').first().locator('[data-cms-edit-open]');

    check((await opener.count()) > 0, 'edit: the first section has an edit button');
    await opener.click();
    await pause(500);
    check(await page.evaluate(() => document.querySelector('dialog[open]') !== null), 'edit: the dialog is open');

    const dialogTop = (await state()).panelTop;

    await previewScroll('win.scrollBy(0, 2500)');
    await pause(1000);
    check((await state()).panelTop === dialogTop, 'edit: the panel does not scroll with the preview while the dialog is open (scrollTop ' + dialogTop + ')');

    await page.keyboard.press('Escape');
    await pause(400);
    check(await page.evaluate(() => document.querySelector('dialog[open]') === null), 'edit: the dialog is closed again');

    // 5. Drag held, then released in place
    await previewScroll('win.scrollTo(0, 0)');
    await pause(1500);
    const beforeDrag = await state();

    // Native drag and drop is not reliable in headless Chromium: the events are dispatched by hand.
    // dragstart is what the list reads; with no dragover and no drop the section stays where it is.
    await page.evaluate(() => {
        const grip = document.querySelector('[data-cms-section-item] [data-cms-grip]');

        grip.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));
        grip.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: new DataTransfer() }));
    });
    check(await page.evaluate(() => document.querySelector('.cms-content__item--dragged') !== null), 'drag: a drag is in progress (item marked as dragged)');

    const dragTop = (await state()).panelTop;

    await previewScroll('win.scrollBy(0, 2500)');
    await pause(1000);
    check((await state()).panelTop === dragTop, 'drag: the panel does not scroll with the preview while a section is held (scrollTop ' + dragTop + ')');

    await page.evaluate(() => {
        const grip = document.querySelector('[data-cms-section-item] [data-cms-grip]');

        grip.dispatchEvent(new DragEvent('dragend', { bubbles: true, cancelable: true }));
        grip.dispatchEvent(new PointerEvent('pointerup', { bubbles: true }));
    });
    await pause(1000);

    const afterDrag = await state();

    check(await page.evaluate(() => document.querySelector('.cms-content__item--dragged') === null), 'drag: released, nothing is marked as dragged any more');
    check(JSON.stringify(afterDrag.order) === JSON.stringify(beforeDrag.order), 'drag: released in place, the order of the sections is unchanged');

    // 6. Errors
    check(problems.length === 0, 'no console error and no pageerror' + (problems.length ? ': ' + problems.join(' | ') : ''));
} finally {
    await browser.close();
}

console.log(passed + ' checks passed');
