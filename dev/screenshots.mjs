// @ts-check
/**
 * Documentation screenshots of the plugin in the demo site (dev/demo.php), taken with headless Chrome over the
 * DevTools protocol (no packages needed, Node 22+): the whole control panel page in a browser window, rings on the
 * feature and a zoomed inset of it where the page shows it small.
 *
 *   dev/dev.sh screenshots            all shots into docs/screenshots/
 *   ONLY=replace dev/dev.sh screenshots
 *
 * Env: CHROME (path to Chrome), CP_USER / CP_PASSWORD (default: the demo's editor mara / the dev password).
 * The replace shots really replace an element and undo it again, like a user would.
 */
import { execFileSync, spawn } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const [projectDir, baseUrl = 'http://localhost:8080', outDir = 'docs/screenshots'] = process.argv.slice(2);
if (!projectDir) {
    console.error('Usage: node dev/screenshots.mjs <craft-project-dir> [base-url] [out-dir]');
    process.exit(1);
}

/**
 * Browser viewport of the control panel, in CSS pixels (a laptop's), captured at 3× so the insets stay sharp. Shots of
 * wide tables set a wider one.
 */
const VIEWPORT = { width: 1368, height: 820, scale: 3 };
/** The framed image: canvas size in CSS pixels and its device scale (2400 × 1800 px). */
const FRAME = { width: 1600, height: 1200, scale: 1.5 };
/** Where the browser window sits, in frame pixels: below the copy, as large as fits, centred. */
const STAGE = { left: 88, top: 300, width: 1424, height: 830 };
/** Height of the window's title bar, in frame pixels. */
const BAR = 36;
/** The zoomed inset: at most this much of the window, at most this many frame pixels per CSS pixel, this far from the edge. */
const INSET = { maxWidth: 0.42, maxHeight: 0.5, maxZoom: 2, margin: 28, pad: 10 };
const DEBUGGING_PORT = 9333;

/**
 * @typedef {object} DemoTargets Paths and ids of the demo elements, from dev/screenshots.php
 * @property {{id: number, path: string}} skyline the asset most screenshots show
 * @property {{id: number}} replacement the asset the replace shots replace it with
 * @property {number[]} replaceIn usages the replace shots tick
 * @property {{path: string}} social
 * @property {{path: string}} guide
 * @property {{id: number, path: string}} hongKong
 * @property {{path: string}} homepage
 * @property {{path: string}} usedInField
 * @property {{path: string}} editors
 */

/**
 * @typedef {object} Shot
 * @property {string} name file name in docs/screenshots (without .jpg)
 * @property {string} title headline above the window
 * @property {string} text one line below the headline
 * @property {'Lite' | 'Pro'} edition
 * @property {string} [path] page to open first; without it the shot continues on the current page
 * @property {string} [user] who the shot is taken as (default: CP_USER, an admin); e.g. the demo's editor `jonas`
 * @property {{width?: number, height?: number}} [viewport] another browser viewport for this shot, in CSS pixels
 * @property {string[]} points two or three key points next to the shot
 * @property {string} [focus] JavaScript expression for the element(s) the page is scrolled to (default: the top); without `highlight` what the inset zooms into
 * @property {string} [highlight] JavaScript expression for groups of elements that get a ring; the inset zooms into the first group
 * @property {string} [act] JavaScript run in the page before the capture (may await)
 * @property {boolean} [reload] reload the page after `act` (e.g. when it changed what the page shows)
 * @property {string} [afterReload] JavaScript run after that reload
 * @property {string} [after] JavaScript run after the capture, e.g. to undo what the shot changed
 */

const here = dirname(fileURLToPath(import.meta.url));
/** @type {DemoTargets} */
const demo = JSON.parse(execFileSync('php', [join(here, 'screenshots.php'), projectDir], { encoding: 'utf8' }));

/** Page helpers, defined once per page load. */
const PAGE_HELPERS = `
window.sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
window.until = async (test, what, timeout = 15000) => {
    const start = Date.now();
    while (!test()) {
        if (Date.now() - start > timeout) throw new Error('Timed out waiting for ' + what);
        await sleep(100);
    }
};
window.fieldIndex = () => $('.element-relations .element-index').data('elementIndex');
window.pageIndex = () => $('#main .element-index, #content .element-index').first().data('elementIndex') ?? Craft.elementIndex;
window.indexReady = async (index, what) => {
    await sleep(150); // a request started just now may not have set isIndexBusy yet
    await until(() => index && !index.isIndexBusy && index.view, what);
    await sleep(600);
};
window.clickTab = (label) => {
    const tab = [...document.querySelectorAll('[role="tab"]')].find((t) => t.textContent.trim() === label);
    if (!tab) throw new Error('No tab ' + label);
    tab.click();
};
// the open modal; Garnish.Modal.visibleModal is unset when one modal opens another from its onSelect
window.$$ = (selector) => [...document.querySelectorAll(selector)].filter((e) => e.getClientRects().length > 0);
window.byText = (selector, text) => $$(selector).filter((e) => e.textContent.trim() === text);
/** A table column by its header label: the header cell and the cells below it. */
window.column = (label) => {
    // the table's own header (Craft also renders a sticky copy of it)
    const header = byText('th', label).find((th) => th.closest('table')?.querySelector('tbody tr'));
    if (!header) return [];
    const index = [...header.parentElement.children].indexOf(header);
    const rows = [...header.closest('table').querySelectorAll('tbody tr')];
    return [header, ...rows.map((row) => row.children[index]).filter(Boolean)];
};
/** The box around one or more elements, in CSS pixels of the viewport. */
window.regionOf = (elements, padding = 0) => {
    const list = [elements].flat(3).filter(Boolean);
    if (!list.length) throw new Error('Nothing to frame');
    const boxes = list.map((e) => e.getBoundingClientRect());
    const left = Math.min(...boxes.map((b) => b.left)), top = Math.min(...boxes.map((b) => b.top));
    const right = Math.max(...boxes.map((b) => b.right)), bottom = Math.max(...boxes.map((b) => b.bottom));
    return { x: left - padding, y: top - padding, width: right - left + 2 * padding, height: bottom - top + 2 * padding };
};
/** A table's header row and its first rows (long lists are cut for a closer look). */
/** An element's rows, as wide as the content pane it is in (the window then starts at the pane's edge). */
window.inPane = (element) => ({
    getBoundingClientRect: () => {
        const pane = (element.closest('.content-pane') ?? element).getBoundingClientRect(), box = element.getBoundingClientRect();
        return { left: pane.left, right: pane.right, top: box.top, bottom: box.bottom };
    },
});
window.firstRows = (selector, count) => {
    const table = $$(selector)[0];
    if (!table) return [];
    return [table.querySelector('thead'), ...[...table.querySelectorAll('tbody tr')].slice(0, count)];
};
/** Runs Craft's queue until the replacement or rollback is done (it runs as a queue job). */
window.waitForOperation = async (operationId) => {
    for (let i = 0; i < 120; i++) {
        Craft.cp.runQueue?.();
        const { data } = await Craft.sendActionRequest('POST', 'element-relations/replace/status', { data: { operationId } });
        if (data.done) return data;
        await sleep(1000);
    }
    throw new Error('Operation ' + operationId + ' did not finish');
};
/** The type dropdown's open menu (Garnish renders it at the end of the page). */
window.typeMenu = () => $('[data-element-relations-menu][data-param="elementTypes"] .menubtn').data('menubtn')?.menu?.$container?.[0];
// index actions ask with the browser's confirm() (e.g. Delete): answered "OK" like the user would; a native dialog
// would block the headless page
window.confirm = () => true;
/** The usage list's "Replace…" button, once usages are ticked. */
window.replaceButton = () => $$('.element-relations button').find((button) => button.textContent.trim() === 'Replace…');
/** The open replace wizard's slideout. */
window.wizardSlideout = () => document.querySelector('.element-relations-wizard')?.closest('.slideout');
/** Searches an index, ticks one element and runs an index action on it, then waits for the notice it answers with. */
window.findAndRun = async (index, search, id, action, params, expect) => {
    index.$search.val(search).trigger('input'); // like typing: Craft searches after a short pause
    await sleep(900);
    await indexReady(index, 'the search');
    const row = document.querySelector('.elements table tbody tr[data-id="' + id + '"]');
    if (!row) throw new Error('Not listed: ' + id);
    index.view.elementSelect.selectItem($(row));
    await sleep(300);
    index.submitAction(action, params);
    await until(() => $$('.notification').some((n) => expect.test(n.textContent)), 'the notice');
    await sleep(500);
};
window.visibleModal = () => $('.modal:visible').last().data('modal') ?? Garnish.Modal.visibleModal;
// trial and dev mode notices are not part of what the plugin looks like
const style = document.createElement('style');
style.textContent = '#trial-info, #devmode { display: none !important; }';
document.head.append(style);
`;

/** @type {Shot[]} */
const SHOTS = [
    {
        name: 'usage-list', edition: 'Lite',
        points: ['Every element type in one list', 'Where it lives, which field, which site', 'Drafts and disabled elements included'],
        focus: `inPane(document.querySelector('.element-relations'))`,
        highlight: `[[...column('Field').slice(0, 3), ...column('Site').slice(0, 3)]]`,
        title: 'See where every element is used',
        text: 'One list of everything that links to it: entries, Matrix blocks, products, menus, forms, in every site.',
        path: demo.skyline.path,
        act: `await indexReady(fieldIndex(), 'the usage list');`,
    },
    {
        name: 'type-menu', edition: 'Lite',
        points: ['A count per element type', 'With Craft’s search, sorting and status menu'],
        focus: `[inPane(document.querySelector('.element-relations')), typeMenu()]`,
        highlight: `[[typeMenu()]]`,
        title: 'Filter by element type',
        text: 'Counts per type, plus Craft’s own search, sorting, status menu and pagination.',
        path: demo.guide.path,
        act: `
            await indexReady(fieldIndex(), 'the usage list');
            $('[data-element-relations-menu][data-param="elementTypes"] .menubtn').data('menubtn').showMenu();
            await sleep(500);
        `,
    },
    {
        name: 'cards', edition: 'Lite',
        points: ['Table or cards, like every index', 'More columns in the View menu'],
        focus: `inPane(document.querySelector('.element-relations'))`,
        highlight: `[[fieldIndex().$viewModeBtnContainer?.[0]]]`,
        title: 'Table or cards',
        text: 'The field renders Craft’s native element index, so everything looks and works like the rest of the control panel.',
        path: demo.skyline.path,
        act: `await indexReady(fieldIndex(), 'the usage list'); document.querySelector('.element-relations [data-view="cards"]').click(); await indexReady(fieldIndex(), 'cards');`,
        // back to the table (Craft remembers the view), where the replace shots tick rows
        after: `document.querySelector('.element-relations [data-view="table"]').click(); await indexReady(fieldIndex(), 'the table');`,
    },
    {
        name: 'entry-tab', edition: 'Lite',
        points: ['Entries, assets, categories, users, products, global sets', 'On its own tab or next to the content'],
        focus: `[document.querySelector('[role="tablist"]'), inPane(document.querySelector('.element-relations'))]`,
        highlight: `[byText('[role="tab"]', 'Used in')]`,
        title: 'On any element type',
        text: 'Add the field to entries, assets, categories, users, products or global sets, e.g. on its own tab.',
        path: demo.hongKong.path,
        act: `clickTab('Used in'); await sleep(300); await indexReady(fieldIndex(), 'the usage list');`,
    },
    {
        name: 'index-column', edition: 'Lite', viewport: { width: 1600 },
        points: ['The first usages at a glance, plus the total', 'Sortable by the number of usages'],
        focus: `[document.querySelector('#toolbar'), firstRows('.elements table', 7)]`,
        highlight: `[column('Used in').slice(0, 5)]`,
        title: 'Right in the element index',
        text: 'As a column, with a preview of the first usages and the total. Sortable by number of usages.',
        path: '/admin/assets/images',
        act: `await indexReady(pageIndex(), 'the asset index'); document.querySelector('[data-view="table"]')?.click(); await indexReady(pageIndex(), 'the table');`,
    },
    {
        name: 'global-usages', edition: 'Lite',
        points: ['SEOmatic’s global and per-section images', 'Linked to their settings page'],
        focus: `inPane(document.querySelector('.element-relations'))`,
        highlight: `[[document.querySelector('.element-relations-heading'), document.querySelector('.element-relations-heading')?.nextElementSibling]]`,
        title: 'Plugin settings count, too',
        text: 'Usages that are not elements, such as SEOmatic’s global SEO image, are listed below the field.',
        path: demo.social.path,
        act: `await sleep(800);`,
    },
    {
        name: 'used-filter', edition: 'Pro', viewport: { width: 1600 },
        points: ['On top of any source, with Craft’s bulk actions', 'The same rule in custom sources'],
        focus: `[...$$('.hud').filter((h) => h.querySelector('.condition-container')), document.querySelector('.elements table')]`,
        highlight: `[[...byText('button', 'Used anywhere'), ...$$('.hud .lightswitch')], column('Used in')]`,
        title: 'Find what nobody uses',
        text: 'A “Used anywhere” rule in Craft’s filter, on top of any source and with Craft’s bulk actions.',
        path: '/admin/assets/images',
        act: `
            await indexReady(pageIndex(), 'the asset index');
            const index = pageIndex();
            index.showFilterHud();
            const hud = () => index.filterHuds[index.siteId][index.sourceKey];
            await until(() => hud()?.$body?.find('[name="condition[new-rule-type]"]').length, 'the filter');
            // add the rule like picking it from "Add a filter" (Craft's condition builder renders it via htmx)
            await until(() => hud().$body.find('button.add').data('menubtn'), 'the Add a filter menu');
            // picked too early, the condition builder sometimes ignores it: pick again
            for (let attempt = 1; !hud().$body.find('.lightswitch').length; attempt++) {
                await sleep(500);
                const addButton = hud().$body.find('button.add').data('menubtn');
                const option = addButton.menu.$options.filter((i, o) => o.textContent.trim() === 'Used anywhere')[0];
                if (!option) throw new Error('No "Used anywhere" in: ' + addButton.menu.$options.map((i, o) => o.textContent.trim()).get().join(', '));
                addButton.menu.selectOption(option);
                await until(() => hud().$body.find('.lightswitch').length, 'the Used anywhere rule', attempt < 3 ? 8000 : 30000).catch((error) => {
                    if (attempt >= 3) throw error;
                });
            }
            await sleep(300);
            hud().$body.find('.lightswitch').data('lightswitch').turnOff();
            await sleep(500);
            hud().$body.find('button[type="submit"]').trigger('click');
            await sleep(300);
            await indexReady(index, 'the filtered assets');
            index.showFilterHud();
            await sleep(700);
            // the filter opens over the list; below it, both are in the shot (its tip pointed at the filter button)
            const filter = $$('.hud').find((h) => h.querySelector('.condition-container'));
            filter.style.top = document.querySelector('.elements table').getBoundingClientRect().bottom + window.scrollY + 12 + 'px';
            filter.querySelector('.tip')?.remove();
            $$('.hud-shade').forEach((shade) => { shade.style.background = 'transparent'; });
            await sleep(200);
        `,
    },
    {
        name: 'replace', edition: 'Pro',
        points: ['Tick the usages, like in any index', 'Or all in the list, on every page', 'Element Relations’ own button, next to Craft’s'],
        focus: `inPane(document.querySelector('.element-relations'))`,
        highlight: `[[replaceButton()]]`,
        title: 'Replace it where you choose',
        text: 'Tick the usages right in the list and choose “Replace…”. Every supported field and plugin is rewritten the way it was read.',
        path: demo.skyline.path,
        act: `
            await indexReady(fieldIndex(), 'the usage list');
            const index = fieldIndex();
            // the demo's usages to replace in, each with its rows in every site
            $$('.element-relations tbody tr[data-id]')
                .filter((row) => ${JSON.stringify(demo.replaceIn)}.includes(Number(row.dataset.id)))
                .forEach((row) => index.view.elementSelect.selectItem($(row)));
            await until(() => replaceButton(), 'the Replace… button');
            await sleep(600);
        `,
    },
    {
        name: 'wizard', edition: 'Pro',
        points: ['Pick the replacement with Craft’s own picker', 'Untick what should keep the element', 'A summary before anything changes'],
        focus: `wizardSlideout()`,
        highlight: `[[document.querySelector('.element-relations-wizard__review')], [wizardSlideout().querySelector('button[type="submit"]')]]`,
        title: 'Review, then replace',
        text: 'The replacement, the ticked usages and a summary in one slideout. Then it runs in the background.',
        act: `
            replaceButton().click();
            await until(() => document.querySelector('.element-relations-wizard'), 'the wizard');
            await sleep(800);
            const picker = $('.element-relations-wizard .elementselect').data('elementSelect');
            picker.showModal();
            await until(() => picker.modal?.elementIndex?.view, 'the picker');
            await indexReady(picker.modal.elementIndex, 'the picker');
            picker.modal.elementIndex.view.selectElementById(${demo.replacement.id});
            picker.modal.selectElements();
            await until(() => document.querySelector('.element-relations-wizard [data-review-to] .chip'), 'the chosen replacement');
            // the whole review (summary and the sites line) in view, step 1 may scroll off
            document.querySelector('.element-relations-wizard').lastElementChild.scrollIntoView({ block: 'end' });
            await sleep(700);
        `,
    },
    {
        name: 'background', edition: 'Pro',
        points: ['Saving many or large entries never blocks the page', 'Keep working while it runs'],
        focus: `inPane(document.querySelector('.element-relations'))`,
        highlight: `[$$('.notification')]`,
        title: 'Runs in the background',
        text: 'Replacements and rollbacks run as queue jobs, as the user who started them. The notice follows the job.',
        act: `
            wizardSlideout().querySelector('button[type="submit"]').click();
            await until(() => $$('.notification').some((n) => /background/.test(n.textContent)), 'the running notice');
            await sleep(400);
        `,
    },
    {
        name: 'undo', edition: 'Pro',
        points: ['Undo right in the notice', 'Entries get a revision with a note'],
        focus: `inPane(document.querySelector('.element-relations'))`,
        highlight: `[[document.querySelector('[data-element-relations-undo]').closest('.notification') ?? document.querySelector('[data-element-relations-undo]')]]`,
        title: 'Undo in one click',
        text: 'When the job is done, the list refreshes and the notice offers Undo.',
        act: `
            await until(() => document.querySelector('[data-element-relations-undo]'), 'the result with Undo', 90000);
            await indexReady(fieldIndex(), 'the refreshed list');
            await sleep(500);
        `,
        // undo it (also a queue job), so the demo stays as it was
        after: `
            document.querySelector('[data-element-relations-undo]').click();
            await until(() => $$('.notification').some((n) => /Rolled back/.test(n.textContent)), 'the undo', 90000);
            await sleep(800);
        `,
    },
    {
        name: 'history', edition: 'Pro', viewport: { width: 1600 },
        points: ['Roll back keeps changes made since', 'Revert to revisions uses Craft’s own revisions'],
        focus: `[byText('h2, h3', 'Replacements')[0], document.querySelector('#element-relations-history')]`,
        highlight: `[[...byText('button', 'Roll back'), ...byText('button', 'Revert to revisions')]]`,
        title: 'History, rollback and revisions',
        text: 'Utilities → Element Relations lists every replacement with Roll back and Revert to revisions.',
        path: '/admin/utilities/element-relations',
        // one more replacement that stays applied for the shot (rolled back right after), so the history shows both
        // states and the Roll back / Revert to revisions buttons
        act: `
            const response = await Craft.sendActionRequest('POST', 'element-relations/replace/replace', { data: {
                fromId: ${demo.skyline.id}, toId: ${demo.replacement.id}, siteId: 1, sourceIds: [${demo.replaceIn[0]}],
            } });
            await waitForOperation(response.data.operationId);
            sessionStorage.setItem('demoOperationId', String(response.data.operationId));
        `,
        reload: true,
        afterReload: `
            // the table is wider than the content next to the nav: collapse it, like a user with a small screen would
            [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Toggle sidebar')?.click();
            // only the demo's replacements (the tests leave theirs in the same project)
            const rows = [...document.querySelectorAll('#element-relations-history tbody tr')];
            rows.filter((row) => !row.textContent.includes('Train passing the city towers')).forEach((row) => row.remove());
            rows.filter((row) => row.isConnected).slice(3).forEach((row) => row.remove());
            await sleep(600);
        `,
        after: `
            const operationId = Number(sessionStorage.getItem('demoOperationId'));
            await Craft.sendActionRequest('POST', 'element-relations/replace/rollback', { data: { operationId } });
            await waitForOperation(operationId);
            [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Toggle sidebar')?.click();
            await sleep(300);
        `,
    },
    {
        name: 'in-use-delete', edition: 'Pro', viewport: { width: 1600 }, user: 'jonas',
        points: ['Delete, disable, expire, schedule', 'Only live usages count', 'A permission lets editors through'],
        focus: `[document.querySelector('#toolbar'), firstRows('.elements table', 3)]`,
        highlight: `[$$('.notification'), $$('.elements table tbody tr.sel')]`,
        title: 'Keep what is in use',
        text: 'Editors can’t delete or take offline what live content uses. The error says where it is used.',
        path: '/admin/assets/images',
        act: `
            await indexReady(pageIndex(), 'the asset index');
            document.querySelector('[data-view="table"]')?.click();
            await indexReady(pageIndex(), 'the table');
            await findAndRun(pageIndex(), 'city towers', ${demo.skyline.id}, 'craft\\\\elements\\\\actions\\\\DeleteAssets', {}, /can’t be deleted/);
        `,
    },
    {
        name: 'in-use-dialog', edition: 'Pro', user: 'jonas',
        points: ['In Craft’s own delete dialog', 'The usages listed, “Replace…” right there', 'No “Delete anyway” without the permission'],
        focus: `$$('.modal').filter((m) => /visitors see/.test(m.textContent)).at(-1)`,
        highlight: `[[...$$('.modal *')].filter((e) => e.children.length < 3 && /visitors see/.test(e.textContent)).slice(-1)]`,
        title: 'Before deleting: where it is used',
        text: 'Craft’s delete dialog lists the live usages, and “Replace…” swaps them in one go. Without the permission there is no way past it.',
        path: demo.skyline.path,
        act: `
            new Craft.ElementDeletionManager('craft\\\\elements\\\\Asset', [${demo.skyline.id}], { siteId: 1 });
            await until(() => $$('.modal').some((m) => /visitors see/.test(m.textContent)), 'the delete dialog', 30000);
            await sleep(1500);
        `,
        after: `$$('.modal').at(-1)?.querySelector('button.btn:not(.submit)')?.click(); await sleep(300);`,
    },
    {
        name: 'in-use-disable', edition: 'Pro', viewport: { width: 1600 }, user: 'jonas',
        points: ['Also for one site, an expiry date or a later post date', 'Drafts and disabled entries don’t count'],
        focus: `[document.querySelector('#toolbar'), firstRows('.elements table', 3)]`,
        highlight: `[$$('.notification'), $$('.elements table tbody tr.sel')]`,
        title: 'Disabling is checked too',
        text: 'Hong Kong has 33 stories pointing to it: it stays live until they are replaced or an editor with the permission decides.',
        path: '/admin/content/entries/destinations',
        act: `
            await indexReady(pageIndex(), 'the destinations');
            await findAndRun(pageIndex(), 'Hong Kong', ${demo.hongKong.id}, 'craft\\\\elements\\\\actions\\\\SetStatus', { status: 'disabled' }, /can’t be disabled/);
        `,
    },
    {
        name: 'field-settings', edition: 'Lite',
        points: ['Like Craft’s relation fields', 'Authors switch the view in the index'],
        focus: `['View Mode', 'Show the status menu', 'Elements per page'].map((label) => inPane(byText('label', label)[0]?.closest('.field')))`,
        title: 'Field settings like Craft’s relation fields',
        text: 'View mode, status menu and page size.',
        path: demo.usedInField.path,
        act: `await sleep(500);`,
    },
    {
        name: 'permissions', edition: 'Pro',
        points: ['Replacing, rolling back, deleting what is in use', 'Everything else follows Craft’s view and save permissions'],
        focus: `inPane(permissionGroup())`,
        highlight: `[[permissionGroup()]]`,
        title: 'Permissions',
        text: 'Replacing, rolling back and deleting or disabling what is in use are permissions; everything else follows Craft’s.',
        path: demo.editors.path,
        act: `
            await sleep(500);
            const heading = [...document.querySelectorAll('h3, h2, legend, .heading')].find((h) => h.textContent.trim().startsWith('Element Relations'));
            window.permissionGroup = () => heading.closest('.user-permissions') ?? heading.parentElement;
        `,
    },
    {
        name: 'plugin-settings', edition: 'Lite',
        points: ['Per environment in config/element-relations.php', 'Rebuild any time under Utilities or on the command line'],
        focus: `$$('#content .field').map(inPane)`,
        title: 'Plugin settings',
        text: 'How many elements a queue job handles when the relations cache is rebuilt. Everything can be set per environment.',
        path: '/admin/settings/plugins/element-relations',
        act: `await sleep(500);`,
    },
];

// ---------------------------------------------------------------------------------------------------------------
// DevTools protocol, minimal
// ---------------------------------------------------------------------------------------------------------------

/**
 * @typedef {object} Browser
 * @property {(method: string, params?: object) => Promise<any>} send
 * @property {(event: string) => Promise<any>} once
 * @property {() => Promise<void>} close
 */

/** @returns {Promise<Browser>} */
async function launchChrome() {
    const chromePath = process.env.CHROME ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
    const profile = mkdtempSync(join(tmpdir(), 'element-relations-shots-'));
    const chrome = spawn(chromePath, [
        '--headless=new', `--remote-debugging-port=${DEBUGGING_PORT}`, `--user-data-dir=${profile}`,
        '--hide-scrollbars', '--no-first-run', '--no-default-browser-check', '--force-color-profile=srgb',
        '--allow-file-access-from-files', 'about:blank',
    ], { stdio: 'ignore' });

    /** @type {{type: string, webSocketDebuggerUrl: string}[]} */
    let targets = [];
    for (let attempt = 0; attempt < 100 && !targets.some((t) => t.type === 'page'); attempt++) {
        await sleep(100);
        targets = await fetch(`http://127.0.0.1:${DEBUGGING_PORT}/json/list`).then((r) => r.json()).catch(() => []);
    }
    const page = targets.find((t) => t.type === 'page');
    if (!page) {
        chrome.kill();
        throw new Error('Chrome did not start (set CHROME to its path)');
    }

    const socket = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        socket.onopen = resolve;
        socket.onerror = reject;
    });
    let lastId = 0;
    /** @type {Map<number, {resolve: (value: any) => void, reject: (error: Error) => void}>} */
    const pending = new Map();
    /** @type {Map<string, ((params: any) => void)[]>} */
    const waiting = new Map();
    socket.onmessage = (message) => {
        const data = JSON.parse(String(message.data));
        if (data.id && pending.has(data.id)) {
            const { resolve, reject } = pending.get(data.id) ?? { resolve: () => {}, reject: () => {} };
            pending.delete(data.id);
            data.error ? reject(new Error(data.error.message)) : resolve(data.result);
        } else if (data.method && waiting.has(data.method)) {
            for (const callback of waiting.get(data.method) ?? []) callback(data.params);
            waiting.delete(data.method);
        }
    };
    return {
        send: (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++lastId;
            pending.set(id, { resolve, reject });
            socket.send(JSON.stringify({ id, method, params }));
        }),
        once: (event) => new Promise((resolve) => waiting.set(event, [...(waiting.get(event) ?? []), resolve])),
        close: async () => {
            socket.close();
            const exited = new Promise((resolve) => chrome.once('exit', resolve));
            chrome.kill();
            await exited;
            rmSync(profile, { recursive: true, force: true });
        },
    };
}

/** @param {number} ms */
function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Runs JavaScript in the page (top-level await allowed) and returns its value.
 * @param {Browser} browser
 * @param {string} code
 */
async function evaluate(browser, code) {
    const result = await browser.send('Runtime.evaluate', {
        expression: `(async () => { ${code} })()`,
        awaitPromise: true,
        returnByValue: true,
    });
    if (result.exceptionDetails) {
        throw new Error(result.exceptionDetails.exception?.description ?? result.exceptionDetails.text);
    }
    return result.result?.value;
}

/**
 * @param {Browser} browser
 * @param {string} url
 */
async function open(browser, url) {
    const loaded = browser.once('Page.loadEventFired');
    await browser.send('Page.navigate', { url });
    await loaded;
    await sleep(800);
}

/**
 * @param {Browser} browser
 * @param {{width: number, height: number, scale: number}} size
 */
async function resize(browser, size) {
    await browser.send('Emulation.setDeviceMetricsOverride', {
        width: size.width, height: size.height, deviceScaleFactor: size.scale, mobile: false,
    });
}

/**
 * @typedef {{x: number, y: number, width: number, height: number}} Region a box in CSS pixels
 */

/**
 * A part of the page at the viewport's device scale.
 * @param {Browser} browser
 * @param {Region} clip in page coordinates
 * @returns {Promise<Buffer>}
 */
async function capture(browser, clip) {
    const { data } = await browser.send('Page.captureScreenshot', { format: 'png', clip: { ...clip, scale: 1 } });
    return Buffer.from(data, 'base64');
}

/**
 * The whole viewport at its device scale.
 * @param {Browser} browser
 * @returns {Promise<Buffer>}
 */
async function captureViewport(browser) {
    const { data } = await browser.send('Page.captureScreenshot', { format: 'png' });
    return Buffer.from(data, 'base64');
}

/**
 * The framed image for the docs, as JPG.
 * @param {Browser} browser
 * @returns {Promise<Buffer>}
 */
async function captureFramed(browser) {
    const { data } = await browser.send('Page.captureScreenshot', { format: 'jpeg', quality: 90 });
    return Buffer.from(data, 'base64');
}

/**
 * @typedef {object} Area what a shot shows, in CSS pixels of the viewport
 * @property {{width: number, height: number}} viewport
 * @property {{x: number, y: number}} scroll the page's scroll position (CDP clips are in page coordinates)
 * @property {Region[]} highlights the groups that get a ring
 * @property {Region | null} target what the inset zooms into: the first highlight, else the focus
 * @property {Region} [inset] the part of the page captured for the inset (the target with some padding)
 */

/**
 * Scrolls the page so the shot's focus is in view and measures the highlights and the inset's target.
 * @param {Browser} browser
 * @param {Shot} shot
 * @returns {Promise<Area>}
 */
async function measure(browser, shot) {
    /** @type {Area} */
    const area = await evaluate(browser, `
        const focus = () => ${shot.focus ? `regionOf(${shot.focus})` : 'null'};
        const first = focus();
        // under Craft's sticky header when scrolled
        if (first && (first.y < 0 || first.y + first.height > innerHeight)) {
            window.scrollBy(0, Math.round(first.y) - 100);
            await sleep(400);
        }
        const groups = (${shot.highlight ?? '[]'}).filter((group) => [group].flat().filter(Boolean).length).map((group) => regionOf(group, 6));
        return {
            viewport: { width: innerWidth, height: innerHeight },
            scroll: { x: window.scrollX, y: window.scrollY },
            highlights: groups,
            target: groups[0] ?? focus(),
        };
    `);
    /** Cut to the viewport (a column may continue below it). @param {Region} r */
    const clip = (r) => {
        const x = Math.max(r.x, 0), y = Math.max(r.y, 0);
        const right = Math.min(r.x + r.width, area.viewport.width), bottom = Math.min(r.y + r.height, area.viewport.height);
        return { x, y, width: right - x, height: bottom - y };
    };
    /** @param {Region} r */
    const visible = (r) => r.width > 0 && r.height > 0;
    area.highlights = area.highlights.map(clip).filter(visible);
    const target = area.target && clip(area.target);
    area.target = target && visible(target) ? target : null;
    if (area.target) {
        const t = area.target, pad = INSET.pad;
        area.inset = clip({ x: t.x - pad, y: t.y - pad, width: t.width + 2 * pad, height: t.height + 2 * pad });
    }
    return area;
}

// ---------------------------------------------------------------------------------------------------------------
// The frame
// ---------------------------------------------------------------------------------------------------------------

/** @param {string} text */
function escapeHtml(text) {
    return text.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] ?? c);
}

/** The plugin icon (src/icon.svg) as a data URL for the frames. */
const PLUGIN_ICON = `data:image/svg+xml;base64,${readFileSync(join(here, '..', 'src', 'icon.svg')).toString('base64')}`;

/** The accent of the rings, the inset and the Pro badge: the icon's coral. */
const ACCENT = '#ff5a3c';

/** Background of the frames and the cover: near-black navy, one warm glow from the icon's colour, a faint cool one, a fine grid. */
const BACKDROP_CSS = `
    background:
        radial-gradient(900px 640px at 0% 0%, rgba(255, 90, 60, .34), transparent 70%),
        radial-gradient(800px 600px at 100% 100%, rgba(72, 118, 255, .22), transparent 70%),
        linear-gradient(rgba(255, 255, 255, .035) 1px, transparent 1px) 0 0 / 48px 48px,
        linear-gradient(90deg, rgba(255, 255, 255, .035) 1px, transparent 1px) 0 0 / 48px 48px,
        linear-gradient(165deg, #0d1020 0%, #141a2f 60%, #0f1426 100%);
`;
/** A little film grain over the gradients, so they don't band. */
const GRAIN = `url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='3' stitchTiles='stitch'/></filter><rect width='100%' height='100%' filter='url(%23n)' opacity='.5'/></svg>")`;

const WINDOW_CSS = `
    .window { position: relative; border-radius: 14px; overflow: hidden; background: #fff;
        box-shadow: 0 70px 140px -40px rgba(0, 0, 0, .75), 0 30px 60px -30px rgba(0, 0, 0, .6), 0 0 0 1px rgba(255, 255, 255, .14); }
    .bar { height: ${BAR}px; display: flex; align-items: center; gap: 7px; padding: 0 14px; background: #f3f4f7; border-bottom: 1px solid #e3e5ec; }
    .dot { width: 11px; height: 11px; border-radius: 50%; flex: none; }
    .address { margin-left: 12px; font-size: 12.5px; color: #7a8296; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .shot { position: relative; }
    .shot > img { display: block; }
    .ring { position: absolute; border-radius: 8px; border: 2.5px solid ${ACCENT}; box-shadow: 0 0 0 4px rgba(255, 90, 60, .2), 0 6px 18px rgba(255, 90, 60, .3); }
    .link { position: absolute; left: 0; top: 0; overflow: visible; pointer-events: none; }
    .inset { position: absolute; border-radius: 12px; overflow: hidden; background: #fff;
        box-shadow: 0 0 0 3px ${ACCENT}, 0 0 0 8px rgba(255, 90, 60, .22), 0 40px 80px -20px rgba(0, 0, 0, .75); }
    .inset img { display: block; }
`;

/**
 * The window's size and place on the stage, in frame pixels: the whole viewport, as large as fits, centred.
 * @param {Area} area
 */
function layoutOf(area) {
    const scale = Math.min(STAGE.width / area.viewport.width, (STAGE.height - BAR) / area.viewport.height);
    const width = Math.round(area.viewport.width * scale), height = Math.round(area.viewport.height * scale);
    return {
        scale, width, height,
        left: Math.round(STAGE.left + (STAGE.width - width) / 2),
        top: Math.round(STAGE.top + (STAGE.height - height - BAR) / 2),
    };
}

/** @param {Region} r @param {number} scale */
const scaled = (r, scale) => ({ x: r.x * scale, y: r.y * scale, width: r.width * scale, height: r.height * scale });

/**
 * The zoomed inset: the first highlight (or the focus) captured at 3× and shown larger, in the corner of the window
 * farthest from it, with a line from the ring to it. Nothing when the page already shows it big enough.
 * @param {Area} area
 * @param {string} insetUrl
 * @param {number} scale frame pixels per CSS pixel of the page
 * @param {number} width the window's content, in frame pixels
 * @param {number} height
 */
function insetHtml(area, insetUrl, scale, width, height) {
    if (!area.inset || !area.target) return '';
    const zoom = Math.min(INSET.maxZoom, INSET.maxWidth * width / area.inset.width, INSET.maxHeight * height / area.inset.height);
    if (zoom < scale * 1.35) return '';
    const iw = Math.round(area.inset.width * zoom), ih = Math.round(area.inset.height * zoom);
    const ring = scaled(area.target, scale);
    const m = INSET.margin;
    const corners = [{ x: m, y: m }, { x: width - m - iw, y: m }, { x: m, y: height - m - ih }, { x: width - m - iw, y: height - m - ih }];
    /** @param {{x: number, y: number}} c */
    const overlap = (c) => Math.max(0, Math.min(c.x + iw, ring.x + ring.width) - Math.max(c.x, ring.x))
        * Math.max(0, Math.min(c.y + ih, ring.y + ring.height) - Math.max(c.y, ring.y));
    /** @param {{x: number, y: number}} c */
    const distance = (c) => Math.hypot(c.x + iw / 2 - ring.x - ring.width / 2, c.y + ih / 2 - ring.y - ring.height / 2);
    // least over the ring; then on the right (Craft's nav is on the left); then farthest from the ring
    const at = corners.sort((a, b) => overlap(a) - overlap(b) || (b.x > width / 2 ? 1 : 0) - (a.x > width / 2 ? 1 : 0) || distance(b) - distance(a))[0];
    // the line: between the facing edges of the ring and the inset
    const dx = at.x + iw / 2 - ring.x - ring.width / 2, dy = at.y + ih / 2 - ring.y - ring.height / 2;
    const horizontal = Math.abs(dx) > Math.abs(dy);
    const start = horizontal
        ? { x: dx > 0 ? ring.x + ring.width : ring.x, y: ring.y + ring.height / 2 }
        : { x: ring.x + ring.width / 2, y: dy > 0 ? ring.y + ring.height : ring.y };
    const end = horizontal
        ? { x: dx > 0 ? at.x : at.x + iw, y: at.y + ih / 2 }
        : { x: at.x + iw / 2, y: dy > 0 ? at.y : at.y + ih };
    const apart = horizontal ? (dx > 0 ? end.x > start.x : end.x < start.x) : (dy > 0 ? end.y > start.y : end.y < start.y);
    const line = apart
        ? `<svg class="link" width="${width}" height="${height}"><line x1="${start.x}" y1="${start.y}" x2="${end.x}" y2="${end.y}" stroke="${ACCENT}" stroke-width="2.5" stroke-opacity=".9"/><circle cx="${start.x}" cy="${start.y}" r="5" fill="${ACCENT}"/></svg>`
        : '';
    return `${line}<div class="inset" style="left:${at.x}px;top:${at.y}px;width:${iw}px;height:${ih}px"><img src="${insetUrl}" alt="" style="width:${iw}px;height:${ih}px"></div>`;
}

/**
 * The whole page in a browser window, with rings on the highlights (and, in the frames, the zoomed inset).
 * @param {string} imageUrl
 * @param {Area} area
 * @param {string} address shown in the window's title bar
 * @param {number} scale
 * @param {string} [inset]
 */
function windowHtml(imageUrl, area, address, scale, inset = '') {
    const width = Math.round(area.viewport.width * scale), height = Math.round(area.viewport.height * scale);
    const rings = area.highlights.map((h) => scaled(h, scale))
        .map((h) => `<span class="ring" style="left:${h.x}px;top:${h.y}px;width:${h.width}px;height:${h.height}px"></span>`)
        .join('');
    return `<div class="window" style="width:${width}px">
        <div class="bar"><span class="dot" style="background:#ff5f57"></span><span class="dot" style="background:#febc2e"></span><span class="dot" style="background:#28c840"></span><span class="address">${escapeHtml(address)}</span></div>
        <div class="shot" style="width:${width}px;height:${height}px"><img src="${imageUrl}" alt="" style="width:${width}px;height:${height}px">${rings}${inset}</div>
    </div>`;
}

/** The page around the frames and the cover. */
const PAGE_CSS = `
    * { box-sizing: border-box; }
    body { margin: 0; width: ${FRAME.width}px; height: ${FRAME.height}px; overflow: hidden; position: relative; color: #fff;
        font-family: -apple-system, 'SF Pro Display', 'Inter', 'Helvetica Neue', sans-serif; -webkit-font-smoothing: antialiased; ${BACKDROP_CSS} }
    body::after { content: ''; position: absolute; inset: 0; background: ${GRAIN}; opacity: .07; mix-blend-mode: overlay; pointer-events: none; z-index: 5; }
    .brand { display: flex; align-items: center; gap: 12px; font-size: 16px; font-weight: 600; letter-spacing: .01em; color: rgba(255, 255, 255, .85); }
    .brand img { width: 34px; height: 34px; border-radius: 9px; box-shadow: 0 6px 16px rgba(0, 0, 0, .4); }
    .badge { padding: 4px 10px; border-radius: 99px; font-size: 11.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
    .badge.lite { background: rgba(255, 255, 255, .14); }
    .badge.pro { background: linear-gradient(135deg, #e5422b, #ff9a3d); }
    h1 { margin: 0; font-weight: 800; letter-spacing: -.03em; line-height: 1.05; }
    p { margin: 0; color: #c4cce0; }
    ${WINDOW_CSS}
`;

/**
 * @param {{shot: Shot, base: string, inset: string | null, area: Area, address: string}} taken
 */
function frameHtml({ shot, base, inset, area, address }) {
    const box = layoutOf(area);
    const insetMarkup = inset ? insetHtml(area, pathToFileURL(inset).href, box.scale, box.width, box.height) : '';
    const points = shot.points.map((point) => `<li>${escapeHtml(point)}</li>`).join('');
    return `<!doctype html><html><head><meta charset="utf-8"><style>
        ${PAGE_CSS}
        /* the copy above the window: brand, headline and line on the left, the points on the right */
        .copy { position: absolute; left: ${STAGE.left}px; right: ${STAGE.left}px; top: 0; height: ${STAGE.top}px;
            display: grid; grid-template-columns: 1fr 420px; column-gap: 72px; align-content: center; align-items: end; }
        .copy .brand, .copy h1, .copy p { grid-column: 1; }
        .copy ul { grid-column: 2; grid-row: 1 / span 3; align-self: end; margin: 0; padding: 0; list-style: none; display: grid; gap: 11px; }
        h1 { font-size: 50px; margin: 18px 0 10px; }
        p { font-size: 19px; line-height: 1.45; max-width: 980px; }
        li { position: relative; padding-left: 32px; font-size: 16.5px; line-height: 1.3; color: #e7ebf5; }
        li::before { content: ''; position: absolute; left: 0; top: 0; width: 21px; height: 21px; border-radius: 50%;
            background: rgba(255, 90, 60, .2) url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M6 12.5l4 4 8-9' fill='none' stroke='%23ff8a5c' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'/></svg>") center / 14px no-repeat; }
        .stage { position: absolute; left: ${box.left}px; top: ${box.top}px; }
    </style></head><body>
        <div class="copy">
            <div class="brand"><img src="${PLUGIN_ICON}" alt="">Element Relations <span class="badge ${shot.edition.toLowerCase()}">${shot.edition}</span></div>
            <h1>${escapeHtml(shot.title)}</h1>
            <p>${escapeHtml(shot.text)}</p>
            <ul>${points}</ul>
        </div>
        <div class="stage">${windowHtml(pathToFileURL(base).href, area, address, box.scale, insetMarkup)}</div>
    </body></html>`;
}

/** Shots on the cover, left to right (the middle one in front). */
const COVER_SHOTS = ['usage-list', 'wizard', 'used-filter'];
/** What the cover promises, under the title. */
const COVER_CHIPS = ['Every relation field, 13 plugins', 'Replace, undo, roll back', 'Tested with 12,000 usages', 'Craft CMS 5'];

/**
 * The cover for the README: the plugin's name over three of the shots, fanned out.
 * @param {{shot: Shot, base: string, area: Area, address: string}[]} cards
 */
function coverHtml(cards) {
    const scale = 0.52;
    const placements = [
        { dx: -380, top: 570, rotate: -5, z: 1 },
        { dx: 0, top: 530, rotate: 0, z: 3 },
        { dx: 380, top: 570, rotate: 5, z: 1 },
    ];
    const windows = cards.map((card, i) => {
        const box = layoutOf(card.area), place = placements[i];
        const left = FRAME.width / 2 - box.width / 2 + place.dx; // scaled about its top centre
        return `<div class="card" style="left:${left}px;top:${place.top}px;transform:rotate(${place.rotate}deg) scale(${scale});z-index:${place.z}">${windowHtml(pathToFileURL(card.base).href, card.area, card.address, box.scale)}</div>`;
    }).join('');
    const chips = COVER_CHIPS.map((chip) => `<span>${escapeHtml(chip)}</span>`).join('');
    return `<!doctype html><html><head><meta charset="utf-8"><style>
        ${PAGE_CSS}
        .head { position: absolute; left: 0; right: 0; top: 96px; text-align: center; }
        .head img { width: 92px; height: 92px; border-radius: 24px; box-shadow: 0 20px 50px rgba(0, 0, 0, .5); }
        h1 { margin: 28px 0 14px; font-size: 80px; letter-spacing: -.035em; }
        p { font-size: 27px; }
        .chips { margin-top: 30px; display: flex; justify-content: center; gap: 12px; }
        .chips span { padding: 9px 18px; border-radius: 99px; font-size: 16px; font-weight: 600; color: #e7ebf5;
            background: rgba(255, 255, 255, .08); border: 1px solid rgba(255, 255, 255, .16); backdrop-filter: blur(6px); }
        .card { position: absolute; transform-origin: top center; }
    </style></head><body>
        <div class="head">
            <img src="${PLUGIN_ICON}" alt=""><h1>Element Relations</h1>
            <p>See where every element is used in Craft CMS. Replace it everywhere, safely.</p>
            <div class="chips">${chips}</div>
        </div>
        ${windows}
    </body></html>`;
}

// ---------------------------------------------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------------------------------------------

const only = (process.env.ONLY ?? '').split(',').filter(Boolean);
const workDir = mkdtempSync(join(tmpdir(), 'element-relations-frames-'));
mkdirSync(outDir, { recursive: true });
const browser = await launchChrome();
try {
    await browser.send('Page.enable');
    await resize(browser, VIEWPORT);
    const defaultUser = process.env.CP_USER ?? 'mara';
    /** Logs in (a fresh session; the demo users have the dev password). @param {string} username */
    const login = async (username) => {
        await browser.send('Network.clearBrowserCookies');
        await open(browser, `${baseUrl}/admin/login`);
        const status = await evaluate(browser, `
            const response = await fetch(Craft.getActionUrl('users/login'), {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': Craft.csrfTokenValue },
                body: JSON.stringify({ loginName: ${JSON.stringify(username)}, password: ${JSON.stringify(process.env.CP_PASSWORD ?? 'password')} }),
            });
            return response.status;
        `);
        if (status !== 200) {
            throw new Error(`Login as ${username} failed (HTTP ${status})`);
        }
    };
    await login(defaultUser);
    let currentUser = defaultUser;

    /** @type {{shot: Shot, base: string, inset: string | null, area: Area, address: string}[]} */
    const taken = [];
    let currentPath = '';
    // with ONLY, run each wanted shot together with the shots it continues from (back to the one opening its page)
    const needed = new Set(SHOTS.map((_, i) => i));
    if (only.length) {
        needed.clear();
        SHOTS.forEach((shot, i) => {
            if (!only.includes(shot.name)) return;
            let start = i;
            while (start > 0 && !SHOTS[start].path) start--;
            for (let j = start; j <= i; j++) needed.add(j);
        });
    }
    for (const shot of SHOTS.filter((_, i) => needed.has(i))) {
        const viewport = { ...VIEWPORT, ...shot.viewport };
        await resize(browser, viewport);
        // shots without a path continue on the page of the previous one, so they run with it
        if (shot.path) {
            if ((shot.user ?? defaultUser) !== currentUser) {
                currentUser = shot.user ?? defaultUser;
                await login(currentUser);
            }
            currentPath = shot.path;
            await open(browser, baseUrl + shot.path);
            await evaluate(browser, PAGE_HELPERS);
        }
        if (shot.act) {
            await evaluate(browser, shot.act).catch(async (error) => {
                // what the page looked like, to see why
                writeFileSync(join(outDir, `failed-${shot.name}.png`), await captureViewport(browser));
                throw error;
            });
        }
        if (shot.reload) {
            await open(browser, baseUrl + currentPath);
            await evaluate(browser, PAGE_HELPERS);
            if (shot.afterReload) {
                await evaluate(browser, shot.afterReload);
            }
        }
        if (!only.length || only.includes(shot.name)) {
            const area = await measure(browser, shot);
            const base = join(workDir, `${shot.name}.png`);
            writeFileSync(base, await captureViewport(browser));
            let inset = null;
            if (area.inset) {
                inset = join(workDir, `${shot.name}-inset.png`);
                writeFileSync(inset, await capture(browser, { ...area.inset, x: area.inset.x + area.scroll.x, y: area.inset.y + area.scroll.y }));
            }
            taken.push({ shot, base, inset, area, address: `transit.test${currentPath.replace(/\?.*$/, '')}` });
            console.log(`  shot ${shot.name}`);
        }
        if (process.env.DEBUG_EVAL && shot.name === only.at(-1)) {
            console.log(await evaluate(browser, process.env.DEBUG_EVAL));
        }
        if (shot.after) {
            await evaluate(browser, shot.after);
        }
    }

    await resize(browser, FRAME);
    for (const card of taken) {
        const html = join(workDir, `${card.shot.name}.html`);
        writeFileSync(html, frameHtml(card));
        await open(browser, pathToFileURL(html).href);
        const target = resolve(outDir, `${card.shot.name}.jpg`);
        writeFileSync(target, await captureFramed(browser));
        console.log(`  framed ${target}`);
    }

    // the cover: three of the shots, when they were all taken in this run
    const coverCards = COVER_SHOTS.map((name) => taken.find((t) => t.shot.name === name));
    if (coverCards.every(Boolean)) {
        const html = join(workDir, 'cover.html');
        writeFileSync(html, coverHtml(/** @type {typeof taken} */ (coverCards)));
        await open(browser, pathToFileURL(html).href);
        writeFileSync(resolve(outDir, 'cover.jpg'), await captureFramed(browser));
        console.log(`  cover ${resolve(outDir, 'cover.jpg')}`);
    }
} finally {
    await browser.close();
    rmSync(workDir, { recursive: true, force: true });
}
