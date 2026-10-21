// @ts-check
/**
 * Control panel tests in a real browser (headless Chrome over the DevTools protocol, dev/browser.mjs): what a person
 * sees and does in the demo site (dev/demo.php): the field's list, its dropdowns, columns, sorting and filter, ticking
 * rows and the replace wizard's counts, replacing and undoing, the delete dialog, and what an editor without the
 * "in use" permission gets. Every number is checked against the list the user looks at.
 *
 *   dev/dev.sh ui                       (started by dev/dev.sh ci as well)
 *   ONLY="wizard counts" dev/dev.sh ui  one scenario (a substring of its name)
 *
 * Env: CHROME (path to Chrome), CP_PASSWORD (the demo users' password, default: the dev password).
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { evaluate, launchChrome, login, open, PAGE_HELPERS, resize, sleep } from './browser.mjs';

const [projectDir, baseUrl = 'http://localhost:8080'] = process.argv.slice(2);
if (!projectDir) {
    console.error('Usage: node dev/ui.mjs <craft-project-dir> [base-url]');
    process.exit(1);
}
const here = dirname(fileURLToPath(import.meta.url));
const password = process.env.CP_PASSWORD ?? 'password';
const only = process.env.ONLY ?? '';
/** @type {{skyline: {id: number, path: string}, replacement: {id: number}, replaceIn: number[], hongKong: {id: number, path: string}, taipei: {id: number, path: string}, notes: {id: number, path: string}}} */
const demo = JSON.parse(execFileSync('php', [join(here, 'screenshots.php'), projectDir], { encoding: 'utf8' }));

let failures = 0;
let passed = 0;
/**
 * @param {boolean} ok
 * @param {string} what
 */
function check(ok, what) {
    console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${what}`);
    ok ? passed++ : failures++;
    if (!ok) {
        throw new Error(what); // the scenario stops here; its page is kept as a screenshot
    }
}

const browser = await launchChrome(9334);
await browser.send('Page.enable');
await resize(browser, { width: 1400, height: 1200 });
let currentUser = '';
/** @param {string} username */
const as = async (username) => {
    if (currentUser !== username) {
        await login(browser, baseUrl, username, password);
        currentUser = username;
    }
};
/** @param {string} path */
const visit = async (path) => {
    await open(browser, baseUrl + path);
    await evaluate(browser, PAGE_HELPERS);
};
/** @param {string} code */
const run = (code) => evaluate(browser, code);
const failureDir = join(projectDir, 'storage', 'ui-failures');

/**
 * @param {string} name
 * @param {() => Promise<void>} body
 */
async function scenario(name, body) {
    if (only && !name.includes(only)) {
        return;
    }
    console.log(name);
    try {
        await body();
    } catch (error) {
        if (!(error instanceof Error) || !error.message.startsWith('  ')) {
            failures++;
            console.log(`  FAIL ${error instanceof Error ? error.message : String(error)}`);
        }
        try {
            mkdirSync(failureDir, { recursive: true });
            const file = join(failureDir, name.replace(/[^a-z0-9]+/gi, '-').toLowerCase() + '.png');
            const { data } = await browser.send('Page.captureScreenshot', { format: 'png' });
            writeFileSync(file, Buffer.from(data, 'base64'));
            console.log(`       (page saved to ${file})`);
        } catch {
            // the page is gone (navigated or closed); nothing to save
        }
    }
}

try {
    // ---------------------------------------------------------------------------------------------------------------
    // The field's list, as an admin
    // ---------------------------------------------------------------------------------------------------------------
    await as('mara');

    await scenario('field list: rows, dropdown badges and the site filter agree', async () => {
        await visit(demo.taipei.path);
        const facts = await run(`
            clickTab('Used in');
            await indexReady(fieldIndex(), 'the usage list');
            const index = fieldIndex();
            const rows = () => $$('.element-relations tbody tr[data-id]').length;
            const countText = () => (index.$countContainer ?? $('.element-relations .count-container')).text().replace(/\\s+/g, ' ').trim();
            // Garnish moves a menu's container to the end of the page: read the badges from the menu button's menu
            const badges = (param) => {
                const button = $('[data-element-relations-menu][data-param="' + param + '"] .menubtn').data('menubtn');
                return button ? [...button.menu.$container[0].querySelectorAll('.badge')].map((b) => Number(b.textContent.replace(/\\D/g, ''))) : [];
            };
            // Craft fetches the "1–20 of 32" text after the rows, in a request of its own
            const counted = () => until(() => /of \\d+/.test(countText()), 'the element count');
            await counted();
            const before = { rows: rows(), count: countText(), types: badges('elementTypes'), sites: badges('inSites') };
            // the site dropdown: English only
            const menu = $('[data-element-relations-menu][data-param="inSites"] .menubtn').data('menubtn');
            const option = [...menu.menu.$container[0].querySelectorAll('a')].find((a) => a.textContent.includes('English'));
            menu.menu.selectOption(option);
            await indexReady(index, 'the English rows');
            await counted();
            const siteCells = column('Site').slice(1).map((td) => td.textContent.trim());
            return { before, after: { rows: rows(), count: countText(), siteCells } };
        `);
        const total = Number(/of (\d+)/.exec(facts.before.count)?.[1] ?? 0);
        check(facts.before.rows === 20 && total === 32 && /elements/.test(facts.before.count), `a page shows 20 of 32 elements (one row per element, whatever its sites): "${facts.before.count}"`);
        check(facts.before.sites.length > 0 && facts.before.sites[0] === total && (facts.before.types.length === 0 || facts.before.types[0] === total), `the "All" badges of the site (${facts.before.sites[0]}) and type (${facts.before.types[0] ?? 'no dropdown'}) dropdowns equal the list's total`);
        check(facts.before.types.length === 0 || facts.before.types.slice(1).reduce((a, b) => a + b, 0) === total, `the type badges add up to the total (${facts.before.types.slice(1).join(' + ') || 'no dropdown'})`);
        check(facts.before.sites.slice(1).every((n) => n <= total) && facts.before.sites.slice(1).reduce((a, b) => a + b, 0) >= total, `a site badge is at most the total, an element in two sites counts in both (${facts.before.sites.slice(1).join(', ')})`);
        const after = Number(/of (\d+)/.exec(facts.after.count)?.[1] ?? 0);
        check(after === 32 && facts.after.siteCells.every((site) => site.split(', ').includes('English')), `filtered to English: the 32 elements used there, each naming English among its sites ("${facts.after.count}", ${[...new Set(facts.after.siteCells)].join(' / ')})`);
    });

    await scenario('field list: columns and sorting of the types in the list', async () => {
        await visit(demo.taipei.path);
        const facts = await run(`
            clickTab('Used in');
            await indexReady(fieldIndex(), 'the usage list');
            const index = fieldIndex();
            // the View menu's column options sit in an attribute of the index's markup
            const attributes = [...document.querySelectorAll('.element-relations *')].flatMap((e) => [...e.attributes].map((a) => a.value));
            const offered = (attr) => attributes.some((v) => v.includes('"attr":"' + attr + '"'));
            // sort by Post Date through the index, like the sort menu does
            index.setSortAttribute('postDate');
            index.setSortDirection('desc');
            index.updateElements();
            await indexReady(index, 'sorted by Post Date');
            const dates = column('Date Updated').slice(1).length; // the table rendered
            const errors = notices().filter((n) => /error|exception/i.test(n));
            return { offered: { section: offered('section'), type: offered('type'), postDate: offered('postDate'), filename: offered('filename'), email: offered('email') }, rows: $$('.element-relations tbody tr[data-id]').length, errors };
        `);
        check(facts.offered.section && facts.offered.type && facts.offered.postDate, 'the View menu offers the entries\' own columns (Section, Entry Type, Post Date)');
        check(!facts.offered.filename && !facts.offered.email, 'and no columns of types not in the list (Filename, Email)');
        check(facts.rows === 20 && facts.errors.length === 0, `sorting by Post Date keeps the list working (${facts.rows} rows, no error)`);
    });

    await scenario('field list: the filter offers the stock rules of the types in the list', async () => {
        await visit(demo.taipei.path);
        const facts = await run(`
            clickTab('Used in');
            await indexReady(fieldIndex(), 'the usage list');
            const index = fieldIndex();
            index.showFilterHud();
            const hud = () => index.filterHuds[index.siteId][index.sourceKey];
            await until(() => hud()?.$body?.find('button.add').data('menubtn'), 'the filter');
            const options = hud().$body.find('button.add').data('menubtn').menu.$options.map((i, o) => o.textContent.trim()).get();
            return options;
        `);
        check(facts.includes('Section') && facts.includes('Entry Type') && facts.includes('Post Date'), `"Add a filter" offers Section, Entry Type and Post Date (${facts.length} rules)`);
        check(!facts.includes('Filename') && !facts.includes('Email'), 'and no rules of types not in the list (Filename, Email)');
    });

    // ---------------------------------------------------------------------------------------------------------------
    // Ticking and the wizard's counts
    // ---------------------------------------------------------------------------------------------------------------
    await scenario('wizard counts: a ticked page of a multi-site list', async () => {
        await visit(demo.taipei.path);
        const facts = await run(`
            clickTab('Used in');
            await indexReady(fieldIndex(), 'the usage list');
            const index = fieldIndex();
            index.view.elementSelect.selectAll();
            await sleep(400);
            const ticked = index.getSelectedElementIds().length;
            replaceButton().click();
            await until(() => document.querySelectorAll('.element-relations-wizard [data-usage]').length > 0, 'the wizard');
            await sleep(400);
            const radios = $$('.element-relations-wizard [data-scope]').map((r) => ({ value: r.value, checked: r.checked, label: r.parentElement.textContent.replace(/\\s+/g, ' ').trim() }));
            const summary = document.querySelector('.element-relations-wizard [data-summary]').textContent.trim();
            const boxes = document.querySelectorAll('.element-relations-wizard [data-usage]').length;
            const submit = wizardSlideout().querySelector('button[type="submit"]').textContent.trim();
            const sites = [...document.querySelectorAll('.element-relations-wizard p')].map((p) => p.textContent.trim()).find((t) => t.startsWith('In every site')) ?? '';
            return { ticked, radios, summary, boxes, submit, sites };
        `);
        check(facts.ticked === 20 && facts.boxes === 20, `select all ticks the page: 20 rows, 20 elements in the wizard (${facts.ticked} / ${facts.boxes})`);
        const ticked = facts.radios.find((r) => r.value === 'ticked');
        const list = facts.radios.find((r) => r.value === 'list');
        check(ticked?.label === 'The 20 elements you ticked', `"${ticked?.label}"`);
        check(list?.label === 'All 32 elements in the list, ticked or not', `"${list?.label}"`);
        check(list?.checked === true, 'the whole page was ticked, so "all in the list" is preselected');
        check(facts.summary.includes('Choose a replacement'), `without a replacement the summary asks for one: "${facts.summary}"`);
        check(facts.sites === 'In every site they use it: English, German.', `"${facts.sites}"`);
        await run(`wizardSlideout().querySelector('button.btn:not([type="submit"])')?.click(); await sleep(300);`);
    });

    await scenario('wizard counts: two ticked usages, then replace and undo', async () => {
        await visit(demo.skyline.path);
        const facts = await run(`
            await indexReady(fieldIndex(), 'the usage list');
            const index = fieldIndex();
            const rowsBefore = $$('.element-relations tbody tr[data-id]').map((r) => r.dataset.id);
            $$('.element-relations tbody tr[data-id]').filter((row) => ${JSON.stringify(demo.replaceIn)}.includes(Number(row.dataset.id))).forEach((row) => index.view.elementSelect.selectItem($(row)));
            await until(() => replaceButton(), 'the Replace… button');
            replaceButton().click();
            await until(() => document.querySelector('.element-relations-wizard'), 'the wizard');
            await sleep(600);
            const picker = $('.element-relations-wizard .elementselect').data('elementSelect');
            picker.showModal();
            await until(() => picker.modal?.elementIndex?.view, 'the picker');
            await indexReady(picker.modal.elementIndex, 'the picker');
            picker.modal.elementIndex.view.selectElementById(${demo.replacement.id});
            picker.modal.selectElements();
            await until(() => document.querySelector('.element-relations-wizard [data-review-to] .chip'), 'the chosen replacement');
            const summary = document.querySelector('.element-relations-wizard [data-summary]').textContent.trim();
            const submit = wizardSlideout().querySelector('button[type="submit"]').textContent.trim();
            const places = $$('.element-relations-wizard [data-usage]').length;
            const labels = $$('.element-relations-wizard [data-usage]').map((box) => box.parentElement.textContent.replace(/\\s+/g, ' ').trim());
            const sites = labels.every((t) => /English, German/.test(t));
            wizardSlideout().querySelector('button[type="submit"]').click();
            await until(() => notices().some((n) => /background/.test(n)), 'the running notice');
            await until(() => document.querySelector('[data-element-relations-undo]'), 'the result with Undo', 90000);
            const result = notices().find((n) => /replaced/.test(n)) ?? '';
            await indexReady(fieldIndex(), 'the refreshed list');
            const rowsAfter = $$('.element-relations tbody tr[data-id]').map((r) => r.dataset.id);
            document.querySelector('[data-element-relations-undo]').click();
            await until(() => notices().some((n) => /Rolled back/.test(n)), 'the undo', 90000);
            const undone = notices().find((n) => /Rolled back/.test(n)) ?? '';
            await indexReady(fieldIndex(), 'the restored list');
            const rowsRestored = $$('.element-relations tbody tr[data-id]').map((r) => r.dataset.id);
            return { summary, submit, places, sites, labels, result, rowsBefore, rowsAfter, undone, rowsRestored };
        `);
        check(facts.places === 2 && facts.sites && facts.summary.endsWith('in 2 elements.') && facts.submit === 'Replace in 2 elements', `two elements, each listed once with "English, German": "${facts.summary}" / "${facts.submit}" / ${JSON.stringify(facts.labels)}`);
        const replaced = Number(/in (\d+) elements/.exec(facts.result)?.[1] ?? 0);
        check(replaced === 2, `the notice counts the same 2 elements: "${facts.result}"`);
        check(facts.rowsAfter.length === facts.rowsBefore.length - 2, `the list lost those 2 rows (${facts.rowsBefore.length} → ${facts.rowsAfter.length})`);
        const restored = Number(/Rolled back (\d+) elements/.exec(facts.undone)?.[1] ?? 0);
        check(restored === 2 && facts.rowsRestored.length === facts.rowsBefore.length, `Undo restores the 2 elements and the list (${facts.rowsRestored.length} rows): "${facts.undone}"`);
    });

    // ---------------------------------------------------------------------------------------------------------------
    // The delete dialog
    // ---------------------------------------------------------------------------------------------------------------
    await scenario('delete dialog: an admin sees the usages, Replace… and Delete anyway', async () => {
        await visit(demo.skyline.path);
        const facts = await run(`
            new Craft.ElementDeletionManager('craft\\\\elements\\\\Asset', [${demo.skyline.id}], { siteId: 1 });
            await until(() => $$('.modal').some((m) => /Element Relations found/.test(m.textContent)), 'the delete dialog', 30000);
            await sleep(800);
            const modal = $$('.modal').find((m) => /Element Relations found/.test(m.textContent));
            const summary = (/Element Relations found \\d+ live elements that use the asset[^.]*\\./.exec(modal.textContent.replace(/\\s+/g, ' ')) ?? [''])[0];
            const buttons = [...modal.querySelectorAll('button')].map((b) => b.textContent.trim());
            return { summary, buttons };
        `);
        const usages = Number(/(\d+) live elements that use/.exec(facts.summary)?.[1] ?? 0);
        const named = facts.summary.match(/“[^”]+”/g) ?? [];
        check(usages === 3 && named.length === 3 && new Set(named).size === 3 && !/\(English/.test(facts.summary), `three elements, each named once, without their sites: "${facts.summary}"`);
        check(facts.buttons.includes('Replace…') && facts.buttons.includes('Delete anyway'), `the admin gets Replace… and Delete anyway (${facts.buttons.filter((b) => b).join(' | ')})`);
    });

    // ---------------------------------------------------------------------------------------------------------------
    // An editor without the "in use" permission
    // ---------------------------------------------------------------------------------------------------------------
    await as('jonas');

    await scenario('editor: the delete dialog has no way past the usages', async () => {
        await visit(demo.skyline.path);
        const facts = await run(`
            new Craft.ElementDeletionManager('craft\\\\elements\\\\Asset', [${demo.skyline.id}], { siteId: 1 });
            await until(() => $$('.modal').some((m) => /Element Relations found/.test(m.textContent)), 'the delete dialog', 30000);
            await sleep(800);
            const modal = $$('.modal').find((m) => /Element Relations found/.test(m.textContent));
            const buttons = [...modal.querySelectorAll('button')].map((b) => b.textContent.trim());
            const submit = [...modal.querySelectorAll('button')].find((b) => /^Delete/.test(b.textContent.trim()) && b.classList.contains('submit'));
            return { buttons, submitDisabled: submit ? submit.disabled || submit.classList.contains('disabled') : null, hint: /permission/.test(modal.textContent) };
        `);
        check(facts.buttons.includes('Replace…') && !facts.buttons.includes('Delete anyway'), `Replace… yes, Delete anyway no (${facts.buttons.filter((b) => b).join(' | ')})`);
        check(facts.submitDisabled === true && facts.hint, 'the dialog\'s Delete button stays disabled and the text names the permission');
    });

    await scenario('editor: the index refuses to delete or disable what is in use', async () => {
        await visit('/admin/assets/images');
        const del = await run(`
            await indexReady(pageIndex(), 'the asset index');
            document.querySelector('[data-view="table"]')?.click();
            await indexReady(pageIndex(), 'the table');
            await findAndRun(pageIndex(), 'city towers', ${demo.skyline.id}, 'craft\\\\elements\\\\actions\\\\DeleteAssets', {}, /can’t be deleted/);
            await indexReady(pageIndex(), 'the list');
            return { notice: notices().find((n) => /can’t be deleted/.test(n)) ?? '', still: !!document.querySelector('.elements table tbody tr[data-id="${demo.skyline.id}"]') };
        `);
        check(/3 live elements use it/.test(del.notice) && del.still, `Delete: "${del.notice.slice(0, 140)}…" and the asset is still listed`);
        await visit('/admin/content/entries/destinations');
        const dis = await run(`
            await indexReady(pageIndex(), 'the destinations');
            await findAndRun(pageIndex(), 'Hong Kong', ${demo.hongKong.id}, 'craft\\\\elements\\\\actions\\\\SetStatus', { status: 'disabled' }, /can’t be disabled/);
            await indexReady(pageIndex(), 'the list');
            const row = document.querySelector('.elements table tbody tr[data-id="${demo.hongKong.id}"]');
            return { notice: notices().find((n) => /can’t be disabled/.test(n)) ?? '', live: !!row?.querySelector('.status.live, .status.enabled') };
        `);
        // Craft's action disables one site of a multi-site entry: only the elements using it in that site count
        check(/can’t be disabled for English\. 33 live elements use it there/.test(dis.notice) && dis.live, `Set status → Disabled: "${dis.notice.slice(0, 120)}…" and Hong Kong stays live`);
    });

    await scenario('editor: the edit page refuses disabling, but saves otherwise', async () => {
        await visit(demo.taipei.path);
        /** Clicks Save and waits for the page Craft answers with. @param {string} prepare */
        const save = async (prepare) => {
            const loaded = browser.once('Page.loadEventFired');
            await run(`${prepare} await sleep(300); document.querySelector('#save-btn-container button.submit, #action-buttons button.submit').click();`);
            await loaded;
            await sleep(800);
            await evaluate(browser, PAGE_HELPERS);
            return run(`return { notices: notices(), errors: $$('.errors li, ul.errors li').map((e) => e.textContent.trim()), live: $('.lightswitch').filter((i, e) => /^enabledForSite/.test($(e).find('input').attr('name') ?? '')).first().hasClass('on') };`);
        };
        // the sidebar of a multi-site entry has one switch per site the user may edit: English here
        const siteSwitch = `$('.lightswitch').filter((i, e) => /^enabledForSite/.test($(e).find('input').attr('name') ?? '')).first().data('lightswitch')`;
        const refused = await save(`${siteSwitch}?.turnOff();`);
        check(refused.errors.some((e) => /can’t be disabled for English\. 32 live elements use it there/.test(e)) && !refused.notices.some((n) => /saved/i.test(n)), `disabling Taipei for English is refused on the switch: "${refused.errors.find((e) => /can’t be disabled/.test(e))?.slice(0, 120) ?? refused.notices.join(' | ')}"`);
        const saved = await save(`${siteSwitch}?.turnOn();`);
        // a successful save goes back to the entries index; the entry's own page tells whether it is live
        await visit(demo.taipei.path);
        const live = await run(`return $('.lightswitch').filter((i, e) => /^enabledForSite/.test($(e).find('input').attr('name') ?? '')).first().hasClass('on');`);
        check(saved.notices.some((n) => /saved/i.test(n)) && live, `a plain save still works and Taipei stays live: "${saved.notices.find((n) => /saved/i.test(n)) ?? saved.notices.join(' | ')}"`);
    });
} finally {
    await browser.close();
}

console.log(`\n${passed} ok, ${failures} failed`);
process.exit(failures ? 1 : 0);
