// Browser check of the list of the pages of one page (`?inside=<id>`), on the
// sample site of Tests/Browser/sample-site. Not part of the PHPUnit suites and
// not run in CI: see "Browser checks" in docs/developing.md.
//
// Read-only: it logs in, opens the tree, the pages of a page, a search; it
// moves, saves and publishes nothing and submits no form but the login and
// the search.
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
// The sample site is written in French: the list is read in that language.
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
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ignoreHTTPSErrors: true });
const page = await context.newPage();
const problems = [];

page.on('pageerror', (error) => problems.push('pageerror: ' + error.message));
page.on('console', (message) => {
    if (message.type() === 'error') {
        problems.push('console: ' + message.text());
    }
});

const list = (query = '') => baseUrl + '/admin/cms/pages?edit_language_id=' + languageId + query;
const titles = () => page.$$eval('tbody tr[data-row-id] a.fw-medium', (links) => links.map((link) => link.textContent.trim()));
const rowOf = (title) => page.locator('tbody tr[data-row-id]', { has: page.locator('a.fw-medium', { hasText: new RegExp('^' + title + '$') }) });
const overflow = () => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const open = async (title) => {
    const link = rowOf(title).locator('[data-testid^="cms-page-children-"]');

    check(await link.count() === 1, 'the row of ' + title + ' links to its pages');
    await Promise.all([page.waitForNavigation(), link.click()]);
};

try {
    // 1. Login
    await page.goto(baseUrl + '/admin/login');
    await page.getByTestId('login-username').fill(user);
    await page.getByTestId('login-password').fill(password);
    await Promise.all([page.waitForNavigation(), page.getByTestId('login-submit').click()]);

    // 2. The tree, folded: the site holds more than forty pages
    await page.goto(list());
    const tree = await titles();

    if (!tree.includes('Magazine')) {
        fail('no "Magazine" at the root of the tree: load the sample site (Tests/Browser/sample-site), set CMS_LANGUAGE_ID to French, or the login was refused');
    }

    check(!tree.includes('Matériel'), 'the tree opens folded');

    // 3. Down to a subsection, by the number of pages of each row
    await open('Magazine');
    check(await page.getByTestId('cms-pages-section').count() === 1, 'the number of pages of Magazine opens their list');
    check(JSON.stringify(await titles()) === JSON.stringify(['Matériel', 'Conseils', 'Portraits']), 'its own pages, in the order of the site');

    await open('Matériel');
    await open('Vélos de route');
    const crumbs = await page.$$eval('.breadcrumb-item', (items) => items.map((item) => item.textContent.trim()));
    check(crumbs.length === 4 && crumbs.slice(1).join(' > ') === 'Magazine > Matériel > Vélos de route', 'the breadcrumb names every page above: ' + crumbs.join(' > '));
    check((await titles()).length === 8, 'the eight articles of the subsection');
    check(await page.locator('tbody tr[data-row-id]').first().locator('form[action*="/move/up"] button').isDisabled(), 'the first page cannot go up');
    check(await page.locator('tbody tr[data-row-id]').last().locator('form[action*="/move/down"] button').isDisabled(), 'the last page cannot go down');
    check(/[?&]parent=\d+/.test(await page.getByTestId('cms-pages-section-create').getAttribute('href')), 'Create a page here names the page as parent');
    check(await overflow() <= 0, 'no horizontal scroll of the page at 1440 px');

    // 4. A search typed there stays inside
    await page.fill('#cms-pages-search', 'Essai');
    await Promise.all([page.waitForNavigation(), page.press('#cms-pages-search', 'Enter')]);
    const found = await titles();
    check(found.length > 0 && found.every((title) => title.includes('Essai')) && page.url().includes('inside='), 'a search stays among the pages of the page (' + found.length + ' found)');
    check(await page.locator('[data-testid="cms-pages-active-filters"] a', { hasText: 'Vélos de route' }).count() === 1, 'a chip names the page');
    check(await page.locator('form[action*="/move/"]').count() === 0, 'no move among search results');

    await page.setViewportSize({ width: 1024, height: 900 });
    await page.goto(page.url().replace(/&?q=[^&]*/, ''));
    check(await overflow() <= 0, 'no horizontal scroll of the page at 1024 px');
    await page.setViewportSize({ width: 1440, height: 1000 });

    // 5. From a result, up to the pages of a page above it
    await page.goto(list('&q=cols'));
    check((await page.locator('[data-testid^="cms-page-ancestors-"] a').count()) > 0, 'a result names the pages above it as links');
    await Promise.all([page.waitForNavigation(), page.locator('[data-testid^="cms-page-ancestors-"] a').last().click()]);
    check(await page.getByTestId('cms-pages-section').count() === 1, 'a page above a result opens its list');
    await Promise.all([page.waitForNavigation(), page.getByTestId('cms-pages-section-all').click()]);
    check(await page.getByTestId('cms-pages-section').count() === 0 && !page.url().includes('inside='), 'All pages comes back to the tree');

    // 6. Errors
    check(problems.length === 0, 'no console error and no pageerror' + (problems.length ? ': ' + problems.join(' | ') : ''));
} finally {
    await browser.close();
}

console.log(passed + ' checks passed');
