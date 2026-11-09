// Browser check of the tree of pages opened and closed in place, on the sample
// site of Tests/Browser/sample-site. Not part of the PHPUnit suites and not run
// in CI: see "Browser checks" in docs/developing.md.
//
// Read-only: it logs in, opens and closes branches; it moves, saves and
// publishes nothing and submits no form but the login.
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
// The sample site is written in French: the tree is read in that language.
const languageId = env('CMS_LANGUAGE_ID');
const executablePath = process.env.CHROME_PATH || undefined;

let passed = 0;

function check(condition, message) {
    if (!condition) {
        fail(message);
    }

    passed++;
    console.log('ok   ' + message);
}

const browser = await chromium.launch({ headless: true, executablePath }).catch((error) => {
    fail('Chromium does not start (' + error.message.split('\n')[0] + '): set CHROME_PATH');
});
const problems = [];

async function session(options = {}) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 620 }, ignoreHTTPSErrors: true, ...options });
    const page = await context.newPage();

    page.on('pageerror', (error) => problems.push('pageerror: ' + error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            problems.push('console: ' + message.text());
        }
    });
    await page.goto(baseUrl + '/admin/login');
    await page.getByTestId('login-username').fill(user);
    await page.getByTestId('login-password').fill(password);
    await Promise.all([page.waitForNavigation(), page.getByTestId('login-submit').click()]);
    await page.goto(baseUrl + '/admin/cms/pages?edit_language_id=' + languageId);

    return { context, page };
}

const rowOf = (page, title) => page.locator('tbody tr[data-row-id]', { has: page.locator('a.fw-medium', { hasText: new RegExp('^' + title + '$') }) });
const top = async (locator) => Math.round((await locator.boundingBox()).y);

try {
    const { page } = await session();

    if (await rowOf(page, 'Actualités').count() !== 1) {
        fail('no "Actualités" at the root of the tree: load the sample site (Tests/Browser/sample-site), set CMS_LANGUAGE_ID to French, or the login was refused');
    }

    // 1. Opened in place: same document, the row does not move, it keeps the focus
    await page.evaluate(() => { window.cmsSameDocument = true; });
    const news = rowOf(page, 'Actualités');
    await news.scrollIntoViewIfNeeded();
    await page.mouse.wheel(0, 150);
    await page.waitForTimeout(300);
    const scrolled = await page.evaluate(() => window.scrollY);
    const before = await top(news);
    const id = await news.getAttribute('data-row-id');

    check(scrolled > 0, 'the tree is scrolled before a branch is opened (' + scrolled + ' px)');

    await news.locator('[data-cms-tree-toggle]').click();
    await rowOf(page, '2026').waitFor();

    check(await page.evaluate(() => window.cmsSameDocument === true), 'opening a branch does not load the screen again');
    check(Math.abs(await top(rowOf(page, 'Actualités')) - before) <= 2, 'the row opened stays where it was on the screen');
    check(await page.evaluate(() => window.scrollY) > 0, 'the screen did not go back to the top');
    check(await page.evaluate(() => document.activeElement?.getAttribute('data-testid')) === 'cms-page-fold-' + id, 'the chevron keeps the focus');
    check(await rowOf(page, 'Actualités').locator('[data-cms-tree-toggle]').getAttribute('aria-expanded') === 'true', 'the chevron says the branch is open');
    check(new URL(page.url()).searchParams.get('open') === id && !page.url().includes('#'), 'the address names the open branch, without the anchor');

    // 2. A branch inside it, then closed again
    const year = rowOf(page, '2025');
    const yearTop = await top(year);
    await year.locator('[data-cms-tree-toggle]').click();
    await page.locator('tbody tr[data-row-id]').filter({ hasText: 'Les gagnants du concours photo 2025' }).waitFor();
    check(Math.abs(await top(rowOf(page, '2025')) - yearTop) <= 2, 'a branch inside a branch opens in place too');

    await rowOf(page, 'Actualités').locator('[data-cms-tree-toggle]').click();
    await rowOf(page, '2026').waitFor({ state: 'detached' });
    check(await page.evaluate(() => window.cmsSameDocument === true), 'closing a branch does not load the screen again');
    check(Math.abs(await top(rowOf(page, 'Actualités')) - before) <= 2, 'the row closed stays where it was on the screen');

    // 3. The address kept is the tree as it is: loaded again, it opens the same way
    await rowOf(page, 'Actualités').locator('[data-cms-tree-toggle]').click();
    await rowOf(page, '2026').waitFor();
    await page.reload();
    check(await rowOf(page, '2026').count() === 1 && await page.locator('tbody tr[data-row-id]').filter({ hasText: 'Les gagnants du concours photo 2025' }).count() === 1, 'a reload shows the branches opened in place');

    // 4. The button of a page that holds others; a large branch shows its first pages and leads to all of them
    const button = rowOf(page, 'Actualités').locator('[data-testid^="cms-page-children-"]');
    check((await button.innerText()).includes('7 pages') && await button.isVisible(), 'the button of a page says how many pages it holds: ' + (await button.innerText()).trim());

    const large = rowOf(page, 'Brèves');
    const largeId = await large.getAttribute('data-row-id');
    await large.locator('[data-cms-tree-toggle]').click();
    const more = page.getByTestId('cms-page-more-' + largeId);
    await more.waitFor();
    const shownInBranch = await large.evaluate((row) => {
        let count = 0;

        for (let next = row.nextElementSibling; next && !next.matches('[data-testid^="cms-page-more-"]'); next = next.nextElementSibling) {
            count += next.matches('tr[data-row-id]') ? 1 : 0;
        }

        return count;
    });
    check(shownInBranch === 20, 'a branch of 30 pages shows its first 20 (' + shownInBranch + ')');
    check((await more.innerText()).includes('10'), 'the line under them says how many are left: ' + (await more.innerText()).trim());
    await Promise.all([page.waitForNavigation(), more.locator('a').click()]);
    check(await page.getByTestId('cms-pages-section').count() === 1 && await page.locator('tbody tr[data-row-id]').count() === 30, 'it leads to the list of all 30');
    await page.goBack();

    // 5. "Close every branch" in place
    await page.evaluate(() => { window.cmsSameDocument = true; });
    await page.getByTestId('cms-pages-fold-all').click();
    await rowOf(page, '2026').waitFor({ state: 'detached' });
    check(await page.evaluate(() => window.cmsSameDocument === true), 'closing every branch does not load the screen again');

    // 6. Without JavaScript, the link lands on the row
    const plain = await session({ javaScriptEnabled: false });
    await rowOf(plain.page, 'Actualités').scrollIntoViewIfNeeded();
    await Promise.all([plain.page.waitForNavigation(), rowOf(plain.page, 'Actualités').locator('[data-cms-tree-toggle]').click()]);
    // The browser goes to the anchor once the screen has loaded.
    await plain.page.waitForLoadState('load');
    await plain.page.waitForTimeout(300);
    const landed = await rowOf(plain.page, 'Actualités').boundingBox();
    check(plain.page.url().endsWith('#cms-page-' + id) && landed.y >= 0 && landed.y + landed.height <= 620, 'without JavaScript, the screen loaded again lands on the row opened');

    // 7. Errors
    check(problems.length === 0, 'no console error and no pageerror' + (problems.length ? ': ' + problems.join(' | ') : ''));
} finally {
    await browser.close();
}

console.log(passed + ' checks passed');
