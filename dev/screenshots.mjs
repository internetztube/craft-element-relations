// @ts-check
/**
 * Documentation screenshots of the plugin in the demo site (dev/demo.php), taken with headless Chrome over the
 * DevTools protocol (no packages needed, Node 22+) and framed as a browser window on a soft gradient.
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
 * Browser viewport of the control panel, in CSS pixels, captured at 3× (sharp at the zoom below). Tall, so nothing
 * scrolls: no sticky header over the content, and the capture boxes are plain page coordinates. As wide as makes an
 * edit page's content pane a little narrower than the side window (shots on other pages set their own width).
 */
const VIEWPORT = { width: 1368, height: 1400, scale: 3 };
/** The framed image: canvas size in CSS pixels and its device scale (2400 × 1500 px). */
const FRAME = { width: 1600, height: 1000, scale: 1.5 };
/** Frame pixels per control panel pixel, the same in every shot, so the UI has the same size everywhere. */
const ZOOM = 1.2;
/** Height of the window's title bar, in frame pixels. */
const BAR = 36;
/**
 * The window, in frame pixels, the same in every shot of a layout: right of the text (side), or below it (wide, for
 * tables). It shows a part of the page as big as fits at ZOOM, starting just above and left of the shot's focus.
 */
const LAYOUTS = {
    side: { left: 620, top: 90, width: 900, height: 820 },
    wide: { left: 88, top: 304, width: 1424, height: 616 },
};
/** Space around the focus in the window, in CSS pixels. */
const PAD = 24;
/** Short shots get a shorter window, but not shorter than this, in CSS pixels of the page. */
const MIN_HEIGHT = 200;
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
 * @property {'side' | 'wide'} [layout] where the window sits (default: side)
 * @property {{width?: number, height?: number}} [viewport] another browser viewport for this shot, in CSS pixels
 * @property {string[]} points two or three key points next to the shot
 * @property {string} [focus] JavaScript expression for the element(s) the window starts at (default: the page's top left)
 * @property {string} [place] JavaScript run once the window's part of the page (`region`) is known, e.g. to move a notice into it
 * @property {string} [highlight] JavaScript expression for groups of elements that get a glowing ring
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
/** Moves Craft's notices (fixed at the bottom of the viewport) into the shot, bottom left. */
window.placeNotices = (region) => {
    const container = $$('.notification')[0]?.parentElement;
    if (!container) throw new Error('No notice');
    Object.assign(container.style, { position: 'fixed', left: region.x + 24 + 'px', right: 'auto', bottom: 'auto' });
    container.style.top = region.y + region.height - 24 - container.offsetHeight + 'px';
};
// index actions ask with the browser's confirm() (e.g. Delete): answered "OK" like the user would; a native dialog
// would block the headless page
window.confirm = () => true;
/** The sources next to an index would show at the window's left edge, cut. */
window.hideSources = () => { document.querySelector('#sidebar').style.visibility = 'hidden'; };
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
        highlight: `[[...column('Field'), ...column('Site')]]`,
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
        name: 'index-column', edition: 'Lite', layout: 'wide', viewport: { width: 1600 },
        points: ['The first usages at a glance, plus the total', 'Sortable by the number of usages'],
        focus: `[document.querySelector('#toolbar'), firstRows('.elements table', 7)]`,
        highlight: `[column('Used in')]`,
        title: 'Right in the element index',
        text: 'As a column, with a preview of the first usages and the total. Sortable by number of usages.',
        path: '/admin/assets/images',
        act: `await indexReady(pageIndex(), 'the asset index'); document.querySelector('[data-view="table"]')?.click(); await indexReady(pageIndex(), 'the table'); hideSources();`,
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
        name: 'used-filter', edition: 'Pro', layout: 'wide', viewport: { width: 1600 },
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
            hideSources();
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
        // as tall as the window, so the whole slideout (it fills the viewport's height) is in it
        viewport: { width: 1280, height: Math.round((LAYOUTS.side.height - BAR) / ZOOM) },
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
            // the page shows through the shade left of the slideout; just the shade looks cleaner
            document.querySelector('#global-container').style.visibility = 'hidden';
            await sleep(700);
        `,
    },
    {
        name: 'background', edition: 'Pro',
        points: ['Saving many or large entries never blocks the page', 'Keep working while it runs'],
        focus: `inPane(document.querySelector('.element-relations'))`,
        place: `placeNotices(region);`,
        highlight: `[$$('.notification')]`,
        title: 'Runs in the background',
        text: 'Replacements and rollbacks run as queue jobs, as the user who started them. The notice follows the job.',
        act: `
            document.querySelector('#global-container').style.visibility = '';
            wizardSlideout().querySelector('button[type="submit"]').click();
            await until(() => $$('.notification').some((n) => /background/.test(n.textContent)), 'the running notice');
            await sleep(400);
        `,
    },
    {
        name: 'undo', edition: 'Pro',
        points: ['Undo right in the notice', 'Entries get a revision with a note'],
        focus: `inPane(document.querySelector('.element-relations'))`,
        place: `placeNotices(region);`,
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
        // the table needs 1180 px; as wide as that with the sidebar hidden
        name: 'history', edition: 'Pro', layout: 'wide', viewport: { width: 1480 },
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
        name: 'in-use-delete', edition: 'Pro', layout: 'wide', viewport: { width: 1600 }, user: 'jonas',
        points: ['Delete, disable, expire, schedule', 'Only live usages count', 'A permission lets editors through'],
        focus: `[document.querySelector('#toolbar'), firstRows('.elements table', 3)]`,
        place: `placeNotices(region);`,
        highlight: `[$$('.notification'), $$('.elements table tbody tr.sel')]`,
        title: 'Keep what is in use',
        text: 'Editors can’t delete or take offline what live content uses. The error says where it is used.',
        path: '/admin/assets/images',
        act: `
            await indexReady(pageIndex(), 'the asset index');
            document.querySelector('[data-view="table"]')?.click();
            await indexReady(pageIndex(), 'the table');
            hideSources();
            await findAndRun(pageIndex(), 'city towers', ${demo.skyline.id}, 'craft\\\\elements\\\\actions\\\\DeleteAssets', {}, /can’t be deleted/);
        `,
    },
    {
        name: 'in-use-dialog', edition: 'Pro', user: 'jonas', viewport: { width: 1100, height: 900 },
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
        name: 'in-use-disable', edition: 'Pro', layout: 'wide', viewport: { width: 1600 }, user: 'jonas',
        points: ['Also for one site, an expiry date or a later post date', 'Drafts and disabled entries don’t count'],
        focus: `[document.querySelector('#toolbar'), firstRows('.elements table', 3)]`,
        place: `placeNotices(region);`,
        highlight: `[$$('.notification'), $$('.elements table tbody tr.sel')]`,
        title: 'Disabling is checked too',
        text: 'Hong Kong has 33 stories pointing to it: it stays live until they are replaced or an editor with the permission decides.',
        path: '/admin/content/entries/destinations',
        act: `
            await indexReady(pageIndex(), 'the destinations');
            hideSources();
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
        // below 1000 px the nav collapses: the pane is the viewport minus 48 px
        name: 'permissions', edition: 'Pro', viewport: { width: 770, height: 4000 },
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
            permissionGroup().style.margin = '40px 0'; // keep the neighbouring groups out of the shot
        `,
    },
    {
        name: 'plugin-settings', edition: 'Lite', viewport: { width: 770 },
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
 * @param {Browser} browser
 * @param {Region} clip the part of the page, in page coordinates
 * @param {number} viewportHeight
 * @returns {Promise<Buffer>}
 */
async function capture(browser, clip, viewportHeight) {
    const { data } = await browser.send('Page.captureScreenshot', {
        format: 'png', clip: { ...clip, scale: 1 }, captureBeyondViewport: clip.y + clip.height > viewportHeight,
    });
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
 * The part of the page a layout's window shows at ZOOM, in CSS pixels.
 * @param {'side' | 'wide'} layout
 */
function regionSize(layout) {
    return { width: Math.round(LAYOUTS[layout].width / ZOOM), height: Math.round((LAYOUTS[layout].height - BAR) / ZOOM) };
}

/**
 * @typedef {{region: Region, highlights: Region[]}} Area what a window shows: the part of the page and the rings on it
 */

/**
 * The window's part of the page: as wide as the layout's window, the focus centred in it (or starting at its left edge
 * when it is wider), starting just above the focus. As tall as the layout's window, or as the focus when that is shorter (shots
 * that `place` something keep the full height for it). Highlights are relative to it.
 * @param {Browser} browser
 * @param {Shot} shot
 * @returns {Promise<Area>}
 */
async function areaOf(browser, shot) {
    const max = regionSize(shot.layout ?? 'side');
    /** @type {{focus: Region | null, pageWidth: number, pageHeight: number}} */
    const found = await evaluate(browser, `
        window.scrollTo(0, 0);
        return {
            focus: ${shot.focus ? `regionOf(${shot.focus})` : 'null'},
            pageWidth: document.documentElement.scrollWidth,
            pageHeight: document.documentElement.scrollHeight,
        };
    `);
    const focus = found.focus ?? { x: PAD, y: PAD, width: 0, height: 0 };
    /** @param {number} value @param {number} max */
    const clamp = (value, max) => Math.round(Math.max(0, Math.min(value, max)));
    const size = { width: max.width, height: shot.place ? max.height : Math.min(max.height, Math.max(MIN_HEIGHT, Math.ceil(focus.height) + 2 * PAD)) };
    const left = focus.x - Math.max(0, (size.width - focus.width) / 2);
    /** @type {Region} */
    const region = { x: clamp(left, found.pageWidth - size.width), y: clamp(focus.y - PAD, found.pageHeight - size.height), ...size };
    if (shot.place) {
        await evaluate(browser, `const region = ${JSON.stringify(region)}; ${shot.place}`);
        await sleep(300);
    }
    /** @type {Region[]} */
    const boxes = await evaluate(browser, `
        return (${shot.highlight ?? '[]'}).filter((group) => [group].flat().filter(Boolean).length).map((group) => regionOf(group, 6));
    `);
    // relative to the region, and cut to it (a column may continue below the window)
    const inset = 4;
    const highlights = boxes.map((h) => {
        const left = Math.max(h.x - region.x, inset), top = Math.max(h.y - region.y, inset);
        const right = Math.min(h.x + h.width - region.x, region.width - inset), bottom = Math.min(h.y + h.height - region.y, region.height - inset);
        return { x: left, y: top, width: right - left, height: bottom - top };
    }).filter((h) => h.width > 0 && h.height > 0);
    return { region, highlights };
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

/** Background of the frames and the cover: deep navy, soft colour blobs, a dot grid. */
const BACKDROP_CSS = `
    background:
        radial-gradient(700px 520px at 8% 12%, rgba(229, 66, 43, .55), transparent 70%),
        radial-gradient(760px 560px at 96% 8%, rgba(123, 97, 255, .45), transparent 70%),
        radial-gradient(900px 620px at 70% 115%, rgba(54, 197, 240, .35), transparent 70%),
        radial-gradient(rgba(255, 255, 255, .07) 1px, transparent 1px) 0 0 / 22px 22px,
        linear-gradient(160deg, #10152a 0%, #1b2238 55%, #131a2e 100%);
`;
/** A little film grain over the gradients, so they don't band. */
const GRAIN = `url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='3' stitchTiles='stitch'/></filter><rect width='100%' height='100%' filter='url(%23n)' opacity='.5'/></svg>")`;

const WINDOW_CSS = `
    .window { border-radius: 16px; overflow: hidden; background: #fff; box-shadow: 0 60px 120px -30px rgba(0, 0, 0, .6), 0 24px 48px -24px rgba(0, 0, 0, .5), 0 0 0 1px rgba(255, 255, 255, .12); }
    .bar { height: 36px; display: flex; align-items: center; gap: 7px; padding: 0 14px; background: #f1f2f6; border-bottom: 1px solid #e2e4ea; }
    .dot { width: 11px; height: 11px; border-radius: 50%; flex: none; }
    .address { margin-left: 12px; font-size: 12.5px; color: #7a8296; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .shot { position: relative; }
    .shot img { display: block; }
    .ring { position: absolute; border-radius: 10px; border: 2.5px solid #ff6a3d; box-shadow: 0 0 0 5px rgba(255, 106, 61, .22), 0 0 34px 6px rgba(255, 106, 61, .45); }
`;

/**
 * The captured part of the page in a browser window, with glowing rings on the highlights.
 * @param {string} imageUrl
 * @param {Area} area
 * @param {string} address shown in the window's title bar
 */
function windowHtml(imageUrl, area, address) {
    const width = area.region.width * ZOOM, height = area.region.height * ZOOM;
    const rings = area.highlights
        .map((h) => `<span class="ring" style="left:${h.x * ZOOM}px;top:${h.y * ZOOM}px;width:${h.width * ZOOM}px;height:${h.height * ZOOM}px"></span>`)
        .join('');
    return `<div class="window" style="width:${width}px">
        <div class="bar"><span class="dot" style="background:#ff5f57"></span><span class="dot" style="background:#febc2e"></span><span class="dot" style="background:#28c840"></span><span class="address">${escapeHtml(address)}</span></div>
        <div class="shot" style="width:${width}px;height:${height}px"><img src="${imageUrl}" alt="" style="width:${width}px;height:${height}px">${rings}</div>
    </div>`;
}

/** The page around the frames and the cover. */
const PAGE_CSS = `
    * { box-sizing: border-box; }
    body { margin: 0; width: ${FRAME.width}px; height: ${FRAME.height}px; overflow: hidden; position: relative; color: #fff;
        font-family: -apple-system, 'SF Pro Display', 'Avenir Next', 'Helvetica Neue', sans-serif; ${BACKDROP_CSS} }
    body::after { content: ''; position: absolute; inset: 0; background: ${GRAIN}; opacity: .08; mix-blend-mode: overlay; pointer-events: none; z-index: 5; }
    ${WINDOW_CSS}
`;

/**
 * @param {Shot} shot
 * @param {string} imageUrl the captured part of the page
 * @param {Area} area
 * @param {string} address
 */
function frameHtml(shot, imageUrl, area, address) {
    const layout = shot.layout ?? 'side';
    const box = LAYOUTS[layout];
    const points = shot.points.map((point) => `<li>${escapeHtml(point)}</li>`).join('');
    return `<!doctype html><html><head><meta charset="utf-8"><style>
        ${PAGE_CSS}
        /* side: a column left of the window, centred on it; wide: a row above it, the points on the right */
        .copy { position: absolute; left: 88px; top: ${box.top}px; height: ${box.height}px; width: ${box.left - 88 - 64}px; display: flex; flex-direction: column; justify-content: center; }
        .wide .copy { top: 0; height: ${box.top}px; width: auto; right: 88px; display: grid; grid-template-columns: 1fr 440px; column-gap: 64px; align-content: center; align-items: end; }
        .wide .copy .brand, .wide .copy h1, .wide .copy p { grid-column: 1; }
        .wide .copy ul { grid-column: 2; grid-row: 1 / span 3; margin: 0; align-self: end; }
        .wide h1 { font-size: 48px; margin: 20px 0 12px; }
        .brand { display: flex; align-items: center; gap: 12px; font-size: 16px; font-weight: 600; letter-spacing: .02em; color: rgba(255, 255, 255, .85); }
        .brand img { width: 34px; height: 34px; border-radius: 9px; box-shadow: 0 6px 16px rgba(0, 0, 0, .35); }
        .badge { padding: 4px 10px; border-radius: 99px; font-size: 11.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
            background: ${shot.edition === 'Pro' ? 'linear-gradient(135deg, #e5422b, #ff9a3d)' : 'rgba(255, 255, 255, .16)'}; }
        h1 { margin: 26px 0 16px; font-size: 54px; line-height: 1.05; font-weight: 800; letter-spacing: -.03em; }
        p { margin: 0; font-size: 20px; line-height: 1.5; color: #c4cce0; }
        ul { margin: 30px 0 0; padding: 0; list-style: none; display: grid; gap: 12px; }
        li { position: relative; padding-left: 34px; font-size: 17px; color: #e7ebf5; }
        li::before { content: ''; position: absolute; left: 0; top: 1px; width: 22px; height: 22px; border-radius: 50%;
            background: rgba(255, 106, 61, .18) url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M6 12.5l4 4 8-9' fill='none' stroke='%23ff8a5c' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'/></svg>") center / 15px no-repeat; }
        .stage { position: absolute; left: ${box.left}px; top: ${layout === 'side' ? box.top + (box.height - BAR - area.region.height * ZOOM) / 2 : box.top}px; }
    </style></head><body class="${layout}">
        <div class="copy">
            <div class="brand"><img src="${PLUGIN_ICON}" alt="">Element Relations <span class="badge">${shot.edition}</span></div>
            <h1>${escapeHtml(shot.title)}</h1>
            <p>${escapeHtml(shot.text)}</p>
            <ul>${points}</ul>
        </div>
        <div class="stage">${windowHtml(imageUrl, area, address)}</div>
    </body></html>`;
}

/** Shots on the cover, left to right (the middle one in front); all in the side layout, so the windows match. */
const COVER_SHOTS = ['usage-list', 'wizard', 'replace'];

/**
 * The cover for the README: the plugin's name over three of the shots, fanned out.
 * @param {{shot: Shot, raw: string, area: Area, address: string}[]} cards
 */
function coverHtml(cards) {
    const scale = 0.58;
    const width = LAYOUTS.side.width * scale;
    const placements = [
        { left: 800 - width / 2 - 390, top: 410, rotate: -6 },
        { left: 800 - width / 2, top: 372, rotate: 0 },
        { left: 800 - width / 2 + 390, top: 410, rotate: 6 },
    ];
    const windows = cards.map((card, i) => {
        const place = placements[i];
        return `<div class="card" style="left:${place.left}px;top:${place.top}px;transform:rotate(${place.rotate}deg) scale(${scale});z-index:${i === 1 ? 3 : 1}">${windowHtml(pathToFileURL(card.raw).href, card.area, card.address)}</div>`;
    }).join('');
    return `<!doctype html><html><head><meta charset="utf-8"><style>
        ${PAGE_CSS}
        .head { position: absolute; left: 0; right: 0; top: 76px; text-align: center; }
        .head img { width: 84px; height: 84px; border-radius: 22px; box-shadow: 0 18px 40px rgba(0, 0, 0, .45); }
        h1 { margin: 26px 0 12px; font-size: 76px; line-height: 1; font-weight: 800; letter-spacing: -.035em; }
        p { margin: 0; font-size: 26px; color: #c4cce0; }
        .card { position: absolute; transform-origin: top left; }
    </style></head><body>
        <div class="head"><img src="${PLUGIN_ICON}" alt=""><h1>Element Relations</h1><p>See where every element is used in Craft CMS, and replace it everywhere.</p></div>
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

    /** @type {{shot: Shot, raw: string, area: Area, address: string}[]} */
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
                writeFileSync(join(outDir, `failed-${shot.name}.png`), await capture(browser, { x: 0, y: 0, ...viewport }, viewport.height));
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
            const raw = join(workDir, `${shot.name}.png`);
            const area = await areaOf(browser, shot);
            writeFileSync(raw, await capture(browser, area.region, viewport.height));
            taken.push({ shot, raw, area, address: `transit.test${currentPath.replace(/\?.*$/, '')}` });
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
    for (const { shot, raw, area, address } of taken) {
        const html = join(workDir, `${shot.name}.html`);
        writeFileSync(html, frameHtml(shot, pathToFileURL(raw).href, area, address));
        await open(browser, pathToFileURL(html).href);
        const target = resolve(outDir, `${shot.name}.jpg`);
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
