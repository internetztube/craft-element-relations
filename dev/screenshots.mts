/**
 * Documentation screenshots of the plugin in the demo site (dev/demo.php), taken with headless Chrome over the
 * DevTools protocol (dev/browser.mts, no packages; Node 24 runs it as TypeScript, no build step): the whole control panel page in a browser window, rings on the
 * feature and a zoomed inset of it where the page shows it small.
 *
 *   dev/dev.sh screenshots            all shots into docs/screenshots/
 *   ONLY=replace dev/dev.sh screenshots
 *
 * Env: CHROME (path to Chrome), CP_USER / CP_PASSWORD (default: the demo's editor mara / the dev password).
 * The replace shots really replace an element and undo it again, like a user would.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { evaluate, launchChrome, login, open, PAGE_HELPERS as CP_HELPERS, resize, type Browser } from './browser.mts';

const [projectDir, baseUrl = 'http://localhost:8080', outDir = 'docs/screenshots'] = process.argv.slice(2);
if (!projectDir) {
    console.error('Usage: node dev/screenshots.mts <craft-project-dir> [base-url] [out-dir]');
    process.exit(1);
}

/**
 * Browser viewport of the control panel, in CSS pixels (a laptop's), captured at 3× so the insets stay sharp. Shots of
 * wide tables set a wider one.
 */
const VIEWPORT = { width: 1440, height: 860, scale: 3 };
/** The framed image: canvas size in CSS pixels and its device scale (2400 × 1800 px). */
const FRAME = { width: 1600, height: 1200, scale: 1.5 };
/** Where the browser window sits, in frame pixels: below the copy, as large as fits, centred. */
const STAGE = { left: 88, top: 248, width: 1424, height: 900 };
/** Height of the window's title bar, in frame pixels. */
const BAR = 36;
/** The zoomed inset: at most this much of the window, at most this many frame pixels per CSS pixel, this far from the edge. */
const INSET = { maxWidth: 0.42, maxHeight: 0.5, maxZoom: 2, margin: 28, pad: 10 };
const DEBUGGING_PORT = 9333;

/** Paths and ids of the demo elements, from dev/screenshots.php. */
interface DemoTargets {
    /** the asset most screenshots show */
    skyline: { id: number, path: string };
    /** the asset the replace shots replace it with */
    replacement: { id: number };
    /** usages the replace shots tick */
    replaceIn: number[];
    social: { path: string };
    guide: { id: number, path: string };
    hongKong: { id: number, path: string };
    homepage: { path: string };
    usedInField: { path: string };
    editors: { path: string };
    thankYou: { id: number, path: string };
}

interface Shot {
    /** file name in docs/screenshots (without .jpg) */
    name: string;
    /** headline above the window */
    title: string;
    /** one line below the headline */
    text: string;
    /** page to open first; without it the shot continues on the current page */
    path?: string;
    /** who the shot is taken as (default: CP_USER, an admin); e.g. the demo's editor `jonas` */
    user?: string;
    /** another browser viewport for this shot, in CSS pixels */
    viewport?: { width?: number, height?: number };
    /** two or three key points next to the shot */
    points: string[];
    /** JavaScript expression for the element(s) the page is scrolled to (default: the top); without `highlight` what the inset zooms into */
    focus?: string;
    /** JavaScript expression for groups of elements that get a ring; the inset zooms into the first group */
    highlight?: string;
    /** false: rings only, no zoomed inset (the page shows the feature big enough) */
    inset?: boolean;
    /** JavaScript expression for the part of the page the cover shows as a close-up (COVER_SHOTS) */
    cover?: string;
    /** under that close-up */
    caption?: string;
    /** instead of one page: close-ups taken one after another (`protect`: the plugin's "Protect elements in use" set first; then
     * `act`, then `region`), framed under their labels */
    compare?: { label: string, act: string, region: string, protect?: boolean }[];
    /** JavaScript run in the page before the capture (may await) */
    act?: string;
    /** reload the page after `act` (e.g. when it changed what the page shows) */
    reload?: boolean;
    /** JavaScript run after that reload */
    afterReload?: string;
    /** JavaScript run after the capture, e.g. to undo what the shot changed */
    after?: string;
}

/** A box in CSS pixels. */
interface Region {
    x: number;
    y: number;
    width: number;
    height: number;
}

/** What a shot shows, in CSS pixels of the viewport. */
interface Area {
    viewport: { width: number, height: number };
    /** the page's scroll position (CDP clips are in page coordinates) */
    scroll: { x: number, y: number };
    /** the groups that get a ring */
    highlights: Region[];
    /** what the inset zooms into: the first highlight, else the focus */
    target: Region | null;
    /** the part of the page captured for the inset (the target with some padding) */
    inset?: Region;
}

/** A shot as taken: its page, inset and cover close-up as files in the work directory. */
interface Taken {
    shot: Shot;
    base: string;
    inset: string | null;
    cover: string | null;
    coverRegion: Region | null;
    area: Area;
    address: string;
}

/** A close-up of a comparison shot. */
interface Part {
    label: string;
    file: string;
    region: Region;
}

const here = dirname(fileURLToPath(import.meta.url));
const demo: DemoTargets = JSON.parse(execFileSync('php', [join(here, 'screenshots.php'), projectDir], { encoding: 'utf8' }));

/** Page helpers, defined once per page load: the control panel's (dev/browser.mts) and the shots' own. */
const PAGE_HELPERS = CP_HELPERS + `
/** The box around one or more elements, in CSS pixels of the viewport. */
window.regionOf = (elements, padding = 0) => {
    const list = [elements].flat(3).filter(Boolean);
    if (!list.length) throw new Error('Nothing to frame');
    const boxes = list.map((e) => e.getBoundingClientRect());
    const left = Math.min(...boxes.map((b) => b.left)), top = Math.min(...boxes.map((b) => b.top));
    const right = Math.max(...boxes.map((b) => b.right)), bottom = Math.max(...boxes.map((b) => b.bottom));
    return { x: left - padding, y: top - padding, width: right - left + 2 * padding, height: bottom - top + 2 * padding };
};
/** An element's rows, as wide as the content pane it is in (the window then starts at the pane's edge). */
window.inPane = (element) => ({
    getBoundingClientRect: () => {
        const pane = (element.closest('.content-pane') ?? element).getBoundingClientRect(), box = element.getBoundingClientRect();
        return { left: pane.left, right: pane.right, top: box.top, bottom: box.bottom };
    },
});
/** A table's header row and its first rows (long lists are cut for a closer look). */
window.firstRows = (selector, count) => {
    const table = $$(selector)[0];
    if (!table) return [];
    return [table.querySelector('thead'), ...[...table.querySelectorAll('tbody tr')].slice(0, count)];
};
`;

/** What the feature shots do on their pages; the README's panels repeat them in a narrower window. */
const UNUSED_FILTER_ACT = `
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
        `;
const TICK_USAGES_ACT = `
            await indexReady(fieldIndex(), 'the usage list');
            const index = fieldIndex();
            // the demo's usages to replace in, each with its rows in every site
            $$('.element-relations tbody tr[data-id]')
                .filter((row) => ${JSON.stringify(demo.replaceIn)}.includes(Number(row.dataset.id)))
                .forEach((row) => index.view.elementSelect.selectItem($(row)));
            await until(() => replaceButton(), 'the Replace… button');
            await sleep(600);
        `;
const OPEN_WIZARD_ACT = `
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
        `;
const DELETE_DIALOG_ACT = `
            new Craft.ElementDeletionManager('craft\\\\elements\\\\Entry', [${demo.thankYou.id}], { siteId: 1 });
            await until(() => $$('.modal').some((m) => /Element Relations found/.test(m.textContent)), 'the delete dialog', 30000);
            await sleep(800);
            // open the usages, like clicking the toggle
            $$('.modal .fieldtoggle').at(-1)?.click();
            await sleep(1500);
        `;

const SHOTS: Shot[] = [
    {
        name: 'usage-list',
        points: ['Every element type in one list', 'Where it lives, which field holds the link', 'Drafts and disabled elements included'],
        focus: `inPane(document.querySelector('.element-relations'))`,
        highlight: `[[...column('Field')]]`,
        inset: false,
        // the asset's page: title, preview and the "Used in" field, so it reads as an element's usages, not any index
        cover: `[document.querySelector('#header') ?? document.querySelector('h1'), document.querySelector('.element-relations').closest('.field'), document.querySelector('#details')]`,
        caption: 'Where an element is used',
        title: 'See where every element is used',
        text: 'Everything that links to it, in one list: entries, Matrix blocks, products, menus, forms.',
        path: demo.skyline.path,
        act: `await indexReady(fieldIndex(), 'the usage list');`,
    },
    {
        // the README's first image: the same page at a narrower window with Craft's sidebar collapsed, so the field reads
        // at the README's width
        name: 'readme-hero', viewport: { width: 1120, height: 600 },
        caption: 'Where an element is used',
        points: ['Every element type in one list', 'Where it lives, which field holds the link', 'Replace, undo, roll back'],
        focus: `inPane(document.querySelector('.element-relations').closest('.field'))`,
        highlight: `[[...column('Field')]]`,
        inset: false,
        title: 'See where every element is used',
        text: 'Everything that links to it, in one list: entries, Matrix blocks, products, menus, forms.',
        path: demo.skyline.path,
        act: `document.querySelector('#sidebar-toggle')?.click(); await sleep(600); await indexReady(fieldIndex(), 'the usage list');`,
    },
    {
        name: 'readme-unused', viewport: { width: 1120, height: 580 },
        points: ['Works on any source, with Craft’s bulk actions', 'The same rule in custom sources'],
        focus: `[...$$('.hud').filter((h) => h.querySelector('.condition-container')), document.querySelector('.elements table')]`,
        highlight: `[[...byText('button', 'Used anywhere'), ...$$('.hud .lightswitch')], column('Used in')]`,
        inset: false,
        caption: 'Find what nobody uses',
        title: 'Find what nobody uses',
        text: 'A “Used anywhere” rule in Craft’s filter. It works on any source, with Craft’s bulk actions.',
        path: '/admin/assets/images',
        act: `${UNUSED_FILTER_ACT}`,
    },
    {
        name: 'readme-replace', viewport: { width: 1120, height: 700 },
        points: ['Pick the replacement with Craft’s own picker', 'Untick what should keep the element', 'A summary before anything changes'],
        focus: `wizardSlideout()`,
        highlight: `[[document.querySelector('.element-relations-wizard__review')], [wizardSlideout().querySelector('button[type="submit"]')]]`,
        inset: false,
        caption: 'Replace it everywhere, with a review first',
        title: 'Review, then replace',
        text: 'Pick the replacement, check the usages, read the summary. Then it runs in the background.',
        path: demo.skyline.path,
        act: `${TICK_USAGES_ACT} ${OPEN_WIZARD_ACT}`,
    },
    {
        name: 'readme-protect', viewport: { width: 1120, height: 700 }, user: 'jonas',
        points: ['Usages Craft does not see: forms, menus, links, SEO', 'In Craft’s own delete dialog, “Replace…” right there', 'No “Delete anyway” without the permission'],
        focus: `$$('.modal').filter((m) => /Element Relations found/.test(m.textContent)).at(-1)`,
        highlight: `[[...$$('.modal h2, .modal h3')].filter((e) => /Element Relations found/.test(e.textContent)).slice(-1)]`,
        inset: false,
        caption: 'Can’t be deleted while in use',
        title: 'Can’t be deleted while in use',
        text: 'Two forms send people to “Thank you”. Craft would delete it without a warning. Element Relations stops that.',
        path: demo.thankYou.path,
        act: `${DELETE_DIALOG_ACT}`,
        after: `$$('.modal').at(-1)?.querySelector('button.btn:not(.submit)')?.click(); await sleep(300);`,
    },
    {
        name: 'type-menu',
        points: ['Filter by element type', 'Next to Craft’s own filters: section, entry type, author, …'],
        focus: `[inPane(document.querySelector('.element-relations')), ...$$('.hud').filter((h) => h.querySelector('.condition-container'))]`,
        highlight: `[$$('.hud .condition-rule')]`,
        title: 'Filter by element type',
        text: 'The “Element Type” filter, next to Craft’s own filters, search, sorting, status menu and pagination.',
        path: demo.guide.path,
        act: `
            await indexReady(fieldIndex(), 'the usage list');
            const index = fieldIndex();
            index.showFilterHud();
            const hud = () => index.filterHuds[index.siteId][index.sourceKey];
            await until(() => hud()?.$body?.find('button.add').data('menubtn'), 'the Add a filter menu');
            // picked too early, the condition builder sometimes ignores it: pick again
            for (let attempt = 1; !hud().$body.find('.condition-rule').length; attempt++) {
                await sleep(500);
                const addButton = hud().$body.find('button.add').data('menubtn');
                const option = addButton.menu.$options.filter((i, o) => o.textContent.trim() === 'Element Type')[0];
                if (!option) throw new Error('No "Element Type" in: ' + addButton.menu.$options.map((i, o) => o.textContent.trim()).get().join(', '));
                addButton.menu.selectOption(option);
                await until(() => hud().$body.find('.condition-rule').length, 'the Element Type rule', attempt < 3 ? 8000 : 30000).catch((error) => {
                    if (attempt >= 3) throw error;
                });
            }
            await sleep(700);
            // pick the first type, as an author would
            const select = hud().$body.find('.condition-rule select[multiple]')[0];
            select?.selectize?.setValue([Object.keys(select.selectize.options)[0]]);
            select?.selectize?.blur();
            $$('.hud-shade').forEach((shade) => { shade.style.background = 'transparent'; });
            await sleep(500);
        `,
    },
    {
        name: 'cards',
        points: ['Table or cards, like every index', 'More columns in the View menu'],
        focus: `inPane(document.querySelector('.element-relations'))`,
        highlight: `[[fieldIndex().$viewModeBtnContainer?.[0]]]`,
        title: 'Table or cards',
        text: 'The field is Craft’s own element index, so it looks and works like the rest of the control panel.',
        path: demo.skyline.path,
        act: `await indexReady(fieldIndex(), 'the usage list'); document.querySelector('.element-relations [data-view="cards"]').click(); await indexReady(fieldIndex(), 'cards');`,
        // back to the table (Craft remembers the view), where the replace shots tick rows
        after: `document.querySelector('.element-relations [data-view="table"]').click(); await indexReady(fieldIndex(), 'the table');`,
    },
    {
        name: 'entry-tab',
        points: ['Entries, assets, categories, users, products…', 'On its own tab or next to the content'],
        focus: `[document.querySelector('[role="tablist"]'), inPane(document.querySelector('.element-relations'))]`,
        highlight: `[byText('[role="tab"]', 'Used in')]`,
        title: 'On any element type',
        text: 'Add the field to entries, assets, categories, users, products or global sets, for example on its own tab.',
        path: demo.hongKong.path,
        act: `clickTab('Used in'); await sleep(300); await indexReady(fieldIndex(), 'the usage list');`,
    },
    {
        name: 'index-column', viewport: { width: 1600 },
        points: ['The first usages at a glance, plus the total', 'Used in other sites, never “Unused”', 'Sortable by the number of usages'],
        focus: `[document.querySelector('#toolbar'), firstRows('.elements table', 7)]`,
        highlight: `[column('Used in').slice(0, 5), [document.querySelector('.elements table [data-element-relations-other-sites]')?.closest('td')]]`,
        title: 'Right in the element index',
        text: 'A column with the first usages and the total. The index can be sorted by it.',
        path: '/admin/assets/images',
        act: `await indexReady(pageIndex(), 'the asset index'); document.querySelector('[data-view="table"]')?.click(); await indexReady(pageIndex(), 'the table');`,
    },
    {
        name: 'global-usages',
        points: ['SEOmatic’s global and per-section images', 'Linked to their settings page'],
        focus: `inPane(document.querySelector('.element-relations'))`,
        highlight: `[[document.querySelector('.element-relations-heading'), document.querySelector('.element-relations-heading')?.nextElementSibling]]`,
        title: 'Plugin settings count, too',
        text: 'Usages that are not elements, like SEOmatic’s global SEO image, are listed below the field.',
        path: demo.social.path.replace(/site=[^&]*/, 'site=de'), // the German SEOmatic settings use the German version
        act: `await sleep(800);`,
    },
    {
        name: 'used-filter', viewport: { width: 1600 },
        points: ['Works on any source, with Craft’s bulk actions', 'The same rule in custom sources'],
        focus: `[...$$('.hud').filter((h) => h.querySelector('.condition-container')), document.querySelector('.elements table')]`,
        highlight: `[[...byText('button', 'Used anywhere'), ...$$('.hud .lightswitch')], column('Used in')]`,
        cover: `[...$$('.hud').filter((h) => h.querySelector('.condition-container')), document.querySelector('.elements table')]`,
        caption: 'Find what nobody uses',
        title: 'Find what nobody uses',
        text: 'A “Used anywhere” rule in Craft’s filter. It works on any source, with Craft’s bulk actions.',
        path: '/admin/assets/images',
        act: `${UNUSED_FILTER_ACT}`,
    },
    {
        name: 'replace',
        points: ['Tick the usages, like in any index', 'Or all in the list, on every page', 'Its own button, next to Craft’s'],
        focus: `inPane(document.querySelector('.element-relations'))`,
        highlight: `[[replaceButton()]]`,
        title: 'Replace it where you choose',
        text: 'Tick usages in the list and choose “Replace…”. Every field and plugin is rewritten the same way it was read.',
        path: demo.skyline.path,
        act: `${TICK_USAGES_ACT}`,
    },
    {
        name: 'wizard',
        points: ['Pick the replacement with Craft’s own picker', 'Only in this site, or in all of them', 'See what changes in which site'],
        focus: `wizardSlideout()`,
        highlight: `[[document.querySelector('.element-relations-wizard__sites')], [document.querySelector('.element-relations-wizard__changes')]]`,
        cover: `wizardSlideout()`,
        caption: 'Replace, with a review first',
        title: 'Review, then replace',
        text: 'Pick the replacement, choose the usages and the sites, see what changes where. Then it runs in the background.',
        act: `${OPEN_WIZARD_ACT}`,
    },
    {
        name: 'background',
        points: ['Saving many entries never blocks the page', 'Keep working while it runs'],
        focus: `inPane(document.querySelector('.element-relations'))`,
        highlight: `[$$('.notification')]`,
        title: 'Runs in the background',
        text: 'Replacements run as queue jobs, as the user who started them. The notice follows the job.',
        act: `
            wizardSlideout().querySelector('button[type="submit"]').click();
            await until(() => $$('.notification').some((n) => /background/.test(n.textContent)), 'the running notice');
            await sleep(400);
        `,
    },
    {
        name: 'undo',
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
        name: 'history', viewport: { width: 1600 },
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
        name: 'in-use-delete', viewport: { width: 1600 }, user: 'jonas',
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
        name: 'in-use-dialog', user: 'jonas',
        points: ['Usages Craft does not see: forms, menus, links, SEO', 'In Craft’s own delete dialog, “Replace…” right there', 'No “Delete anyway” without the permission'],
        focus: `$$('.modal').filter((m) => /Element Relations found/.test(m.textContent)).at(-1)`,
        highlight: `[[...$$('.modal h2, .modal h3')].filter((e) => /Element Relations found/.test(e.textContent)).slice(-1)]`,
        title: 'Can’t be deleted while in use',
        text: 'Two forms send people to “Thank you”. Craft would delete it without a warning. Element Relations stops that.',
        path: demo.thankYou.path,
        act: `${DELETE_DIALOG_ACT}`,
        after: `$$('.modal').at(-1)?.querySelector('button.btn:not(.submit)')?.click(); await sleep(300);`,
    },
    {
        name: 'delete-compare', user: 'jonas', viewport: { height: 1500 },
        points: ['The same asset, the same dialog', 'Craft checks relation fields and CKEditor', 'Element Relations also finds links, menus and forms'],
        title: 'What Craft’s delete dialog misses',
        text: 'The same PDF, deleted by an editor. Left: Craft 5.10 alone. Right: with Element Relations.',
        path: demo.guide.path,
        compare: [
            {
                label: 'Craft 5.10',
                protect: false,
                act: `
                    window.confirm = () => false; // no blocker means Craft's plain confirm(): cancelled, nothing is deleted
                    new Craft.ElementDeletionManager('craft\\\\elements\\\\Asset', [${demo.guide.id}], { siteId: 1 });
                    await until(() => $$('.modal').some((m) => /Before deleting/.test(m.textContent)), 'Craft’s delete dialog', 30000);
                    await sleep(1500);
                    if ($$('.modal').some((m) => /Element Relations/.test(m.textContent))) throw new Error('The plugin’s part is still in the dialog');
                    // the dialog's heading and its checks, without the empty space and the buttons below
                    window.dialogChecks = () => { const m = $$('.modal').at(-1); return [m.querySelector('.body > h1'), m.querySelector('.edm-blockers')]; };
                `,
                region: `dialogChecks()`,
            },
            {
                label: 'Craft 5.10 with Element Relations',
                protect: true,
                act: `
                    visibleModal()?.hide();
                    await sleep(800);
                    new Craft.ElementDeletionManager('craft\\\\elements\\\\Asset', [${demo.guide.id}], { siteId: 1 });
                    await until(() => $$('.modal').some((m) => /Element Relations found/.test(m.textContent)), 'the delete dialog', 30000);
                    // opened, like a user would to see which elements
                    const ours = [...$$('.modal').at(-1).querySelectorAll('.edm-blocker')].find((b) => /Element Relations found/.test(b.textContent));
                    ours.querySelector('button, [role="button"], .fieldtoggle')?.click();
                    await until(() => ours.querySelector('.element-relations tbody tr[data-id], tbody tr'), 'the usage list in the dialog', 15000);
                    await sleep(1500);
                `,
                region: `dialogChecks()`,
            },
        ],
    },
    {
        // Craft's own list of what relates to the asset next to the plugin's: Craft names the Matrix block, the plugin
        // the page that holds it, with the field and the sites
        name: 'compare',
        points: ['The page, not the Matrix block inside it', 'Where it sits, which field, which sites', 'Rich text, links, menus, forms and SEO too'],
        title: 'Know what you replace',
        text: 'The same asset. Craft lists the Matrix blocks that link to it. Element Relations lists the pages your editors know.',
        path: demo.skyline.path,
        compare: [
            {
                label: 'Craft’s delete dialog',
                act: `
                    new Craft.ElementDeletionManager('craft\\\\elements\\\\Asset', [${demo.skyline.id}], { siteId: 1 });
                    await until(() => $$('.modal h2, .modal h3').some((h) => /is related by/.test(h.textContent)), 'the delete dialog', 30000);
                    await sleep(800);
                    window.craftBlocker = () => $$('.modal h2, .modal h3').find((h) => /is related by/.test(h.textContent)).parentElement.parentElement;
                    craftBlocker().querySelector('.fieldtoggle')?.click();
                    await sleep(2500); // the related elements load into the expanded part
                `,
                region: `craftBlocker()`,
            },
            {
                label: 'Element Relations',
                act: `
                    $$('.modal').at(-1)?.querySelector('button.btn:not(.submit)')?.click();
                    await sleep(500);
                    await indexReady(fieldIndex(), 'the usage list');
                    const index = fieldIndex();
                    $$('.element-relations tbody tr[data-id]').forEach((row) => index.view.elementSelect.selectItem($(row)));
                    await until(() => replaceButton(), 'the Replace… button');
                    replaceButton().click();
                    await until(() => document.querySelector('.element-relations-wizard'), 'the wizard');
                    await sleep(1200);
                    window.wizardUsages = () => {
                        const boxes = $$('.element-relations-wizard input[type="checkbox"]');
                        const heading = $$('.element-relations-wizard h2, .element-relations-wizard h3, .element-relations-wizard legend').find((h) => /Where/.test(h.textContent));
                        return [heading, ...boxes.map((box) => box.closest('li, tr, label, div'))];
                    };
                `,
                region: `wizardUsages()`,
            },
        ],
        after: `wizardSlideout()?.querySelector('button:not([type="submit"])')?.click(); await sleep(300);`,
    },
    {
        name: 'in-use-disable', viewport: { width: 1600 }, user: 'jonas',
        points: ['Also per site, expiry dates and post dates', 'Drafts and disabled entries don’t count'],
        focus: `[document.querySelector('#toolbar'), firstRows('.elements table', 3)]`,
        highlight: `[$$('.notification'), $$('.elements table tbody tr.sel')]`,
        title: 'Disabling is checked too',
        text: '33 stories link to Hong Kong. It stays live until they are replaced, or someone with the permission disables it.',
        path: '/admin/content/entries/destinations',
        act: `
            await indexReady(pageIndex(), 'the destinations');
            await findAndRun(pageIndex(), 'Hong Kong', ${demo.hongKong.id}, 'craft\\\\elements\\\\actions\\\\SetStatus', { status: 'disabled' }, /can’t be disabled/);
        `,
    },
    {
        name: 'field-settings',
        points: ['Like Craft’s relation fields', 'Authors switch the view in the index', 'How many usages an index column shows'],
        focus: `['View Mode', 'Show the status menu', 'Elements per page', 'Elements in index columns'].map((label) => inPane(byText('label', label)[0]?.closest('.field')))`,
        highlight: `[[byText('label', 'Elements in index columns')[0]?.closest('.field')]]`,
        inset: false,
        title: 'Field settings',
        text: 'View mode, status menu, page size, and how many usages an index column shows.',
        path: demo.usedInField.path,
        act: `await sleep(500);`,
    },
    {
        name: 'permissions',
        points: ['Replacing, rolling back, deleting what is in use', 'The rest follows Craft’s view and save rights'],
        focus: `inPane(permissionGroup())`,
        highlight: `[[permissionGroup()]]`,
        title: 'Permissions',
        text: 'Replacing, rolling back and deleting what is in use are permissions of their own. The rest follows Craft’s.',
        path: demo.editors.path,
        act: `
            await sleep(500);
            const heading = [...document.querySelectorAll('h3, h2, legend, .heading')].find((h) => h.textContent.trim().startsWith('Element Relations'));
            window.permissionGroup = () => heading.closest('.user-permissions') ?? heading.parentElement;
        `,
    },
    {
        name: 'plugin-settings',
        points: ['Protection on or off, per environment too', 'Rebuild under Utilities or on the command line'],
        focus: `$$('#content .field').map(inPane)`,
        title: 'Plugin settings',
        text: 'Protect elements in use, and how many elements a queue job handles when the cache is rebuilt.',
        path: '/admin/settings/plugins/element-relations',
        act: `await sleep(500);`,
    },
];

// ---------------------------------------------------------------------------------------------------------------
// Capture
// ---------------------------------------------------------------------------------------------------------------

/** A part of the page (`clip` in page coordinates) at the viewport's device scale. */
async function capture(browser: Browser, clip: Region): Promise<Buffer> {
    const { data } = await browser.send('Page.captureScreenshot', { format: 'png', clip: { ...clip, scale: 1 } });
    return Buffer.from(data, 'base64');
}

/** The whole viewport at its device scale. */
async function captureViewport(browser: Browser): Promise<Buffer> {
    const { data } = await browser.send('Page.captureScreenshot', { format: 'png' });
    return Buffer.from(data, 'base64');
}

/** The framed image for the docs, as JPG. */
async function captureFramed(browser: Browser): Promise<Buffer> {
    const { data } = await browser.send('Page.captureScreenshot', { format: 'jpeg', quality: 90 });
    return Buffer.from(data, 'base64');
}

/** Scrolls the page so the shot's focus is in view and measures the highlights and the inset's target. */
async function measure(browser: Browser, shot: Shot): Promise<Area> {
    const area: Area = await evaluate(browser, `
        const focus = () => ${shot.focus ? `regionOf(${shot.focus})` : 'null'};
        const first = focus();
        // under Craft's sticky header when scrolled
        if (first && (first.y < 0 || first.y + first.height > innerHeight)) {
            window.scrollBy(0, Math.round(first.y) - 60);
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
    /** Cut to the viewport (a column may continue below it). */
    const clip = (r: Region): Region => {
        const x = Math.max(r.x, 0), y = Math.max(r.y, 0);
        const right = Math.min(r.x + r.width, area.viewport.width), bottom = Math.min(r.y + r.height, area.viewport.height);
        return { x, y, width: right - x, height: bottom - y };
    };
    const visible = (r: Region) => r.width > 0 && r.height > 0;
    area.highlights = area.highlights.map(clip).filter(visible);
    const target = area.target && clip(area.target);
    area.target = target && visible(target) ? target : null;
    if (area.target && shot.inset !== false) {
        const t = area.target, pad = INSET.pad;
        area.inset = clip({ x: t.x - pad, y: t.y - pad, width: t.width + 2 * pad, height: t.height + 2 * pad });
    }
    return area;
}

// ---------------------------------------------------------------------------------------------------------------
// The frame
// ---------------------------------------------------------------------------------------------------------------

function escapeHtml(text: string): string {
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
 */
function layoutOf(area: Area) {
    const scale = Math.min(STAGE.width / area.viewport.width, (STAGE.height - BAR) / area.viewport.height);
    const width = Math.round(area.viewport.width * scale), height = Math.round(area.viewport.height * scale);
    return {
        scale, width, height,
        left: Math.round(STAGE.left + (STAGE.width - width) / 2),
        top: Math.round(STAGE.top + (STAGE.height - height - BAR) / 2),
    };
}

const scaled = (r: Region, scale: number): Region => ({ x: r.x * scale, y: r.y * scale, width: r.width * scale, height: r.height * scale });

/**
 * The zoomed inset: the first highlight (or the focus) captured at 3× and shown larger, in the corner of the window
 * farthest from it, with a line from the ring to it. Nothing when the page already shows it big enough. `scale`: frame
 * pixels per CSS pixel of the page; `width` and `height`: the window's content, in frame pixels.
 */
function insetHtml(area: Area, insetUrl: string, scale: number, width: number, height: number): string {
    if (!area.inset || !area.target) return '';
    const zoom = Math.min(INSET.maxZoom, INSET.maxWidth * width / area.inset.width, INSET.maxHeight * height / area.inset.height);
    if (zoom < scale * 1.35) return '';
    const iw = Math.round(area.inset.width * zoom), ih = Math.round(area.inset.height * zoom);
    const ring = scaled(area.target, scale);
    const m = INSET.margin;
    const corners = [{ x: m, y: m }, { x: width - m - iw, y: m }, { x: m, y: height - m - ih }, { x: width - m - iw, y: height - m - ih }];
    const overlap = (c: { x: number, y: number }) => Math.max(0, Math.min(c.x + iw, ring.x + ring.width) - Math.max(c.x, ring.x))
        * Math.max(0, Math.min(c.y + ih, ring.y + ring.height) - Math.max(c.y, ring.y));
    const distance = (c: { x: number, y: number }) => Math.hypot(c.x + iw / 2 - ring.x - ring.width / 2, c.y + ih / 2 - ring.y - ring.height / 2);
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
 * The whole page in a browser window, with rings on the highlights (and, in the frames, the zoomed inset); `address` is
 * shown in its title bar.
 */
function windowHtml(imageUrl: string, area: Area, address: string, scale: number, inset = ''): string {
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
    h1 { margin: 0; font-weight: 800; letter-spacing: -.03em; line-height: 1.05; }
    p { margin: 0; color: #c4cce0; }
    ${WINDOW_CSS}
`;

function frameHtml({ shot, base, inset, area, address }: Taken): string {
    const box = layoutOf(area);
    const insetMarkup = inset ? insetHtml(area, pathToFileURL(inset).href, box.scale, box.width, box.height) : '';
    const points = shot.points.map((point) => `<li>${escapeHtml(point)}</li>`).join('');
    return `<!doctype html><html><head><meta charset="utf-8"><style>
        ${PAGE_CSS}
        /* the copy above the window: brand, headline and line on the left, the points on the right */
        .copy { position: absolute; left: ${STAGE.left}px; right: ${STAGE.left}px; top: 0; height: ${STAGE.top}px; padding-bottom: 30px;
            display: grid; grid-template-columns: 1fr 460px; column-gap: 64px; align-content: end; align-items: end; }
        .copy .brand, .copy h1, .copy p { grid-column: 1; }
        .copy ul { grid-column: 2; grid-row: 1 / span 3; align-self: end; margin: 0; padding: 0; list-style: none; display: grid; gap: 11px; }
        h1 { font-size: 48px; margin: 16px 0 8px; }
        p { font-size: 19px; line-height: 1.45; text-wrap: balance; }
        li { position: relative; padding-left: 32px; font-size: 16.5px; line-height: 1.3; color: #e7ebf5; }
        li::before { content: ''; position: absolute; left: 0; top: 0; width: 21px; height: 21px; border-radius: 50%;
            background: rgba(255, 90, 60, .2) url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M6 12.5l4 4 8-9' fill='none' stroke='%23ff8a5c' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'/></svg>") center / 14px no-repeat; }
        .stage { position: absolute; left: ${box.left}px; top: ${box.top}px; }
    </style></head><body>
        <div class="copy">
            <div class="brand"><img src="${PLUGIN_ICON}" alt="">Element Relations</div>
            <h1>${escapeHtml(shot.title)}</h1>
            <p>${escapeHtml(shot.text)}</p>
            <ul>${points}</ul>
        </div>
        <div class="stage">${windowHtml(pathToFileURL(base).href, area, address, box.scale, insetMarkup)}</div>
    </body></html>`;
}

/**
 * Two close-ups side by side under their labels, with the shot's copy above, like frameHtml(); the second (the
 * plugin's) gets the accent ring.
 */
function compareHtml({ shot, parts }: { shot: Shot, parts: Part[] }): string {
    // stacked, one zoom for both (the same UI size), as large as the stage allows
    const gap = 36, label = 44, top = STAGE.top;
    const zoom = Math.min(
        STAGE.width / Math.max(...parts.map((part) => part.region.width)),
        (STAGE.height - parts.length * label - (parts.length - 1) * gap) / parts.reduce((sum, part) => sum + part.region.height, 0),
        2.4,
    );
    let y = top;
    const cards = parts.map((part, i) => {
        const width = Math.round(part.region.width * zoom), height = Math.round(part.region.height * zoom);
        const html = `<figure class="part${i === parts.length - 1 ? ' ours' : ''}" style="left:${Math.round(STAGE.left + (STAGE.width - width) / 2)}px;top:${y}px;width:${width}px">
            <figcaption><i></i>${escapeHtml(part.label)}</figcaption>
            <div class="pic"><img src="${pathToFileURL(part.file).href}" alt="" style="width:${width}px;height:${height}px"></div>
        </figure>`;
        y += label + height + gap;
        return html;
    }).join('');
    const points = shot.points.map((point) => `<li>${escapeHtml(point)}</li>`).join('');
    return `<!doctype html><html><head><meta charset="utf-8"><style>
        ${PAGE_CSS}
        .copy { position: absolute; left: ${STAGE.left}px; right: ${STAGE.left}px; top: 0; height: ${STAGE.top}px; padding-bottom: 30px;
            display: grid; grid-template-columns: 1fr 460px; column-gap: 64px; align-content: end; align-items: end; }
        .copy .brand, .copy h1, .copy p { grid-column: 1; }
        .copy ul { grid-column: 2; grid-row: 1 / span 3; align-self: end; margin: 0; padding: 0; list-style: none; display: grid; gap: 11px; }
        h1 { font-size: 48px; margin: 16px 0 8px; }
        p { font-size: 19px; line-height: 1.45; text-wrap: balance; }
        li { position: relative; padding-left: 32px; font-size: 16.5px; line-height: 1.3; color: #e7ebf5; }
        li::before { content: ''; position: absolute; left: 0; top: 0; width: 21px; height: 21px; border-radius: 50%;
            background: rgba(255, 90, 60, .2) url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M6 12.5l4 4 8-9' fill='none' stroke='%23ff8a5c' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'/></svg>") center / 14px no-repeat; }
        .part { position: absolute; margin: 0; }
        .pic { border-radius: 14px; overflow: hidden; background: #fff; box-shadow: 0 60px 120px -30px rgba(0, 0, 0, .8), 0 0 0 1px rgba(255, 255, 255, .16); }
        .ours .pic { box-shadow: 0 0 0 3px ${ACCENT}, 0 0 0 9px rgba(255, 90, 60, .2), 0 60px 120px -30px rgba(0, 0, 0, .8); }
        .pic img { display: block; }
        figcaption { height: ${label}px; display: flex; align-items: center; gap: 9px; font-size: 16px; font-weight: 600; color: #c4cce0; }
        .ours figcaption { color: #fff; }
        figcaption i { width: 9px; height: 9px; border-radius: 50%; background: #7a8296; }
        .ours figcaption i { background: ${ACCENT}; box-shadow: 0 0 10px rgba(255, 90, 60, .9); }
    </style></head><body>
        <div class="copy">
            <div class="brand"><img src="${PLUGIN_ICON}" alt="">Element Relations</div>
            <h1>${escapeHtml(shot.title)}</h1>
            <p>${escapeHtml(shot.text)}</p>
            <ul>${points}</ul>
        </div>
        ${cards}
    </body></html>`;
}

/** Close-ups on the cover: the first two stacked on the left, the third (tall) on the right. */
const COVER_SHOTS = ['usage-list', 'used-filter', 'wizard'];
/** The README's first image: the feature panels in one tall picture, each window as wide as the frame, so they read at the README's width. */
const README_SHOTS = ['readme-hero', 'readme-unused', 'readme-replace', 'readme-protect'];

function readmeHtml(cards: Taken[]): { html: string, height: number } {
    const margin = 88, width = FRAME.width - 2 * margin, caption = 52, gap = 72, head = 300;
    let y = head;
    const panels = cards.map((card) => {
        const scale = width / card.area.viewport.width;
        const top = y;
        y += caption + BAR + Math.round(card.area.viewport.height * scale) + gap;
        return `<figure class="panel" style="left:${margin}px;top:${top}px;width:${width}px">
            <figcaption><i></i>${escapeHtml(card.shot.caption ?? card.shot.title)}</figcaption>
            ${windowHtml(pathToFileURL(card.base).href, card.area, card.address, scale)}
        </figure>`;
    }).join('');
    const height = y - gap + margin;
    const chips = COVER_CHIPS.map((chip) => `<span>${escapeHtml(chip)}</span>`).join('');
    const html = `<!doctype html><html><head><meta charset="utf-8"><style>
        ${PAGE_CSS}
        body { height: ${height}px; }
        .head { position: absolute; left: ${margin}px; right: ${margin}px; top: 64px; display: grid; grid-template-columns: 1fr auto; column-gap: 48px; align-items: end; }
        .head .brand, .head h1, .head p { grid-column: 1; }
        h1 { font-size: 52px; margin: 18px 0 10px; }
        p { font-size: 21px; line-height: 1.4; }
        .chips { grid-column: 2; grid-row: 1 / span 3; align-self: end; display: grid; gap: 10px; justify-items: end; }
        .chips span { padding: 8px 16px; border-radius: 99px; font-size: 15px; font-weight: 600; color: #e7ebf5; white-space: nowrap;
            background: rgba(255, 255, 255, .08); border: 1px solid rgba(255, 255, 255, .16); }
        .panel { position: absolute; margin: 0; }
        figcaption { height: ${caption}px; display: flex; align-items: center; gap: 10px; font-size: 20px; font-weight: 700; letter-spacing: -.01em; color: #eef1f8; }
        figcaption i { width: 10px; height: 10px; border-radius: 50%; background: ${ACCENT}; box-shadow: 0 0 10px rgba(255, 90, 60, .9); }
    </style></head><body>
        <div class="head">
            <div class="brand"><img src="${PLUGIN_ICON}" alt="">Element Relations</div>
            <h1>See where every element is used. Replace it everywhere, safely.</h1>
            <p>One list of everything that links to an entry, asset, category or product. All element types, all sites, rich text and plugins.</p>
            <div class="chips">${chips}</div>
        </div>
        ${panels}
    </body></html>`;
    return { html, height };
}

/** What the cover promises, under the title. */
const COVER_CHIPS = ['Every relation field, 13 plugins', 'Replace, undo, roll back', 'Tested with 100,000 usages', 'Craft CMS 5'];

/**
 * The cover for the README: the plugin's name over three close-ups of the control panel, so a reader sees what to
 * expect at a glance.
 */
function coverHtml(cards: (Taken & { cover: string, coverRegion: Region })[]): string {
    const top = 450, bottom = 1150, gap = 32, caption = 40, margin = 88;
    type Card = typeof cards[number];
    const ratio = (card: Card) => card.coverRegion.height / card.coverRegion.width;
    const height = (card: Card, w: number) => Math.round(ratio(card) * w);
    const [first, second, tall] = cards;
    // two cards stacked on the left, the tall one on the right, both columns ending on the same line
    const layout = (width: number) => {
        const right = ((width - gap) * (ratio(first) + ratio(second)) + caption + gap) / (ratio(first) + ratio(second) + ratio(tall));
        const left = width - gap - right;
        const x = Math.round((FRAME.width - width) / 2);
        return {
            slots: [
                { card: first, left: x, top, width: Math.round(left) },
                { card: second, left: x, top: top + caption + height(first, left) + gap, width: Math.round(left) },
                { card: tall, left: x + Math.round(left) + gap, top, width: Math.round(right) },
            ],
            bottom: top + caption + height(tall, right),
        };
    };
    let { slots, bottom: end } = layout(FRAME.width - 2 * margin);
    if (end > bottom) {
        ({ slots } = layout(Math.round((FRAME.width - 2 * margin) * (bottom - top) / (end - top))));
    }
    const crops = slots.map(({ card, left, top, width }) => `<figure class="crop" style="left:${left}px;top:${top}px;width:${width}px">
        <figcaption><i></i>${escapeHtml(card.shot.caption ?? card.shot.title)}</figcaption>
        <div class="pic"><img src="${pathToFileURL(card.cover).href}" alt="" style="width:${width}px;height:${height(card, width)}px"></div>
    </figure>`).join('');
    const chips = COVER_CHIPS.map((chip) => `<span>${escapeHtml(chip)}</span>`).join('');
    return `<!doctype html><html><head><meta charset="utf-8"><style>
        ${PAGE_CSS}
        .head { position: absolute; left: 0; right: 0; top: 72px; text-align: center; }
        .head img { width: 84px; height: 84px; border-radius: 22px; box-shadow: 0 20px 50px rgba(0, 0, 0, .5); }
        h1 { margin: 22px 0 12px; font-size: 72px; letter-spacing: -.035em; }
        p { font-size: 25px; }
        .chips { margin-top: 24px; display: flex; justify-content: center; gap: 12px; }
        .chips span { padding: 8px 16px; border-radius: 99px; font-size: 15px; font-weight: 600; color: #e7ebf5;
            background: rgba(255, 255, 255, .08); border: 1px solid rgba(255, 255, 255, .16); }
        .spot { position: absolute; left: 50%; top: ${top + 120}px; width: 1500px; height: 700px; transform: translateX(-50%); pointer-events: none;
            background: radial-gradient(closest-side, rgba(255, 90, 60, .16), transparent); filter: blur(40px); }
        .crop { position: absolute; margin: 0; }
        .pic { border-radius: 14px; overflow: hidden; background: #fff;
            box-shadow: 0 60px 120px -30px rgba(0, 0, 0, .8), 0 20px 40px -20px rgba(0, 0, 0, .6), 0 0 0 1px rgba(255, 255, 255, .16), inset 0 1px 0 rgba(255, 255, 255, .6); }
        .pic img { display: block; }
        figcaption { height: ${caption}px; display: flex; align-items: center; gap: 9px; font-size: 15px; font-weight: 600; letter-spacing: .01em; color: #eef1f8; }
        figcaption i { width: 9px; height: 9px; border-radius: 50%; background: ${ACCENT}; box-shadow: 0 0 10px rgba(255, 90, 60, .9); }
    </style></head><body>
        <div class="head">
            <img src="${PLUGIN_ICON}" alt=""><h1>Element Relations</h1>
            <p>See where every element is used in Craft CMS. Replace it everywhere, safely.</p>
            <div class="chips">${chips}</div>
        </div>
        <div class="spot"></div>
        ${crops}
    </body></html>`;
}

// ---------------------------------------------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------------------------------------------

const only = (process.env.ONLY ?? '').split(',').filter(Boolean);
const workDir = mkdtempSync(join(tmpdir(), 'element-relations-frames-'));
mkdirSync(outDir, { recursive: true });
const browser = await launchChrome(DEBUGGING_PORT);
try {
    await browser.send('Page.enable');
    await resize(browser, VIEWPORT);
    const defaultUser = process.env.CP_USER ?? 'mara';
    const password = process.env.CP_PASSWORD ?? 'password'; // the demo users have the dev password
    await login(browser, baseUrl, defaultUser, password);
    let currentUser = defaultUser;

    const taken: Taken[] = [];
    const comparisons: { shot: Shot, parts: Part[] }[] = [];
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
                await login(browser, baseUrl, currentUser, password);
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
        if (shot.compare && (!only.length || only.includes(shot.name))) {
            const parts: Part[] = [];
            const protect = (on: boolean) => execFileSync('php', [join(here, 'screenshots.php'), projectDir, 'protect', on ? '1' : '0']);
            try {
            for (const [i, part] of shot.compare.entries()) {
                if (part.protect !== undefined) {
                    protect(part.protect);
                }
                await evaluate(browser, part.act).catch(async (error) => {
                    writeFileSync(join(outDir, `failed-${shot.name}.png`), await captureViewport(browser));
                    throw error;
                });
                const found: { region: Region, scroll: { x: number, y: number } } = await evaluate(browser, `return { region: regionOf(${part.region}, 6), scroll: { x: scrollX, y: scrollY } };`);
                const file = join(workDir, `${shot.name}-${i}.png`);
                writeFileSync(file, await capture(browser, { ...found.region, x: found.region.x + found.scroll.x, y: found.region.y + found.scroll.y }));
                parts.push({ label: part.label, file, region: found.region });
            }
            } finally {
                if (shot.compare.some((part) => part.protect !== undefined)) {
                    protect(true); // the default, what the other shots and the tests expect
                }
            }
            comparisons.push({ shot, parts });
            console.log(`  shot ${shot.name}`);
        } else if (!only.length || only.includes(shot.name)) {
            const area = await measure(browser, shot);
            const base = join(workDir, `${shot.name}.png`);
            writeFileSync(base, await captureViewport(browser));
            let inset = null;
            if (area.inset) {
                inset = join(workDir, `${shot.name}-inset.png`);
                writeFileSync(inset, await capture(browser, { ...area.inset, x: area.inset.x + area.scroll.x, y: area.inset.y + area.scroll.y }));
            }
            let cover = null, coverRegion = null;
            if (shot.cover) {
                coverRegion = await evaluate(browser, `return regionOf(${shot.cover}, 4);`) as Region;
                cover = join(workDir, `${shot.name}-cover.png`);
                writeFileSync(cover, await capture(browser, { ...coverRegion, x: coverRegion.x + area.scroll.x, y: coverRegion.y + area.scroll.y }));
            }
            taken.push({ shot, base, inset, cover, coverRegion, area, address: `transit.test${currentPath.replace(/\?.*$/, '')}` });
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
        if (README_SHOTS.includes(card.shot.name)) {
            continue; // only panels of the README image
        }
        const html = join(workDir, `${card.shot.name}.html`);
        writeFileSync(html, frameHtml(card));
        await open(browser, pathToFileURL(html).href);
        const target = resolve(outDir, `${card.shot.name}.jpg`);
        writeFileSync(target, await captureFramed(browser));
        console.log(`  framed ${target}`);
    }

    for (const comparison of comparisons) {
        const html = join(workDir, `${comparison.shot.name}.html`);
        writeFileSync(html, compareHtml(comparison));
        await open(browser, pathToFileURL(html).href);
        const target = resolve(outDir, `${comparison.shot.name}.jpg`);
        writeFileSync(target, await captureFramed(browser));
        console.log(`  framed ${target}`);
    }

    // the README image: its panels stacked, when they were all taken in this run
    const readmeCards = README_SHOTS.map((name) => taken.find((t) => t.shot.name === name));
    if (readmeCards.every(Boolean)) {
        const { html: readme, height } = readmeHtml(readmeCards as Taken[]);
        const file = join(workDir, 'readme.html');
        writeFileSync(file, readme);
        await resize(browser, { ...FRAME, height });
        await open(browser, pathToFileURL(file).href);
        writeFileSync(resolve(outDir, 'readme.jpg'), await captureFramed(browser));
        console.log(`  readme ${resolve(outDir, 'readme.jpg')}`);
        await resize(browser, FRAME);
    }

    // the cover: three of the shots, when they were all taken in this run
    const coverCards = COVER_SHOTS.map((name) => taken.find((t) => t.shot.name === name && t.cover));
    if (coverCards.every(Boolean)) {
        const html = join(workDir, 'cover.html');
        writeFileSync(html, coverHtml(coverCards as (Taken & { cover: string, coverRegion: Region })[]));
        await open(browser, pathToFileURL(html).href);
        writeFileSync(resolve(outDir, 'cover.jpg'), await captureFramed(browser));
        console.log(`  cover ${resolve(outDir, 'cover.jpg')}`);
    }
} finally {
    await browser.close();
    rmSync(workDir, { recursive: true, force: true });
}
