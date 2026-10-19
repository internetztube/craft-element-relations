// @ts-check
/**
 * Documentation screenshots of the plugin in the demo site (dev/demo.php), taken with headless Chrome over the
 * DevTools protocol (no packages needed, Node 22+) and framed as a browser window on a soft gradient.
 *
 *   dev/dev.sh screenshots            all shots into docs/screenshots/
 *   ONLY=wizard-step1 dev/dev.sh screenshots
 *
 * Env: CHROME (path to Chrome), CP_USER / CP_PASSWORD (default: the demo's editor mara / the dev password).
 * The wizard shots really replace an element and undo it again, like a user would.
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

/** Browser viewport of the raw control panel shots, in CSS pixels; captured at twice the resolution. */
const VIEWPORT = { width: 1280, height: 1100, scale: 3 };
/** The framed image: canvas size in CSS pixels and its device scale (2400 × 1500 px). */
const FRAME = { width: 1600, height: 1000, scale: 1.5 };
/**
 * Space for the zoomed shot, in CSS pixels: right of the text, or below it for wide, flat shots (the stacked layout).
 * The zoom is capped so the 3× capture stays sharp.
 */
const STAGE = { left: 640, top: 70, width: 890, height: 860, maxZoom: 2 };
const STACKED_STAGE = { left: 86, top: 330, width: 1428, height: 610, maxZoom: 2 };
/** Shots wider than this (width / height) use the stacked layout. */
const STACKED_ASPECT = 1.9;
const DEBUGGING_PORT = 9333;

/**
 * @typedef {object} DemoTargets Paths and ids of the demo elements, from dev/screenshots.php
 * @property {{id: number, path: string}} skyline the asset most screenshots show
 * @property {{id: number}} replacement the asset the wizard replaces it with
 * @property {number[]} replaceIn usages ticked in the wizard's second step
 * @property {{path: string}} social
 * @property {{path: string}} guide
 * @property {{path: string}} hongKong
 * @property {{path: string}} homepage
 * @property {{path: string}} usedInField
 * @property {{path: string}} editors
 */

/**
 * @typedef {object} Shot
 * @property {string} name file name in docs/screenshots (without .png)
 * @property {string} title headline above the window
 * @property {string} text one line below the headline
 * @property {'Lite' | 'Pro'} edition
 * @property {string} [path] page to open first; without it the shot continues on the current page
 * @property {string[]} points two or three key points next to the shot
 * @property {string} [focus] JavaScript expression for the element(s) to zoom in on (default: the whole page)
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
window.scrollToElement = (selector, offset = 110) => {
    const element = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if (!element) throw new Error('Not found: ' + selector);
    window.scrollTo({ top: element.getBoundingClientRect().top + window.scrollY - offset, behavior: 'instant' });
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
window.typeMenu = () => $('[data-element-relations-type-menu] .menubtn').data('menubtn')?.menu?.$container?.[0];
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
        focus: `document.querySelector('.element-relations')`,
        highlight: `[[...column('Field'), ...column('Site')]]`,
        title: 'See where every element is used',
        text: 'One list of everything that links to it: entries, Matrix blocks, products, menus, forms, in every site.',
        path: demo.skyline.path,
        act: `await indexReady(fieldIndex(), 'the usage list'); scrollToElement('.element-relations', 90);`,
    },
    {
        name: 'type-menu', edition: 'Lite',
        points: ['A count per element type', 'With Craft’s search, sorting and status menu'],
        focus: `[document.querySelector('.element-relations'), typeMenu()]`,
        highlight: `[[typeMenu()]]`,
        title: 'Filter by element type',
        text: 'Counts per type, plus Craft’s own search, sorting, status menu and pagination.',
        path: demo.guide.path,
        act: `
            await indexReady(fieldIndex(), 'the usage list');
            scrollToElement('.element-relations', 90);
            $('[data-element-relations-type-menu] .menubtn').data('menubtn').showMenu();
            await sleep(500);
        `,
    },
    {
        name: 'cards', edition: 'Lite',
        points: ['Table or cards, like every index', 'More columns in the View menu'],
        focus: `document.querySelector('.element-relations')`,
        highlight: `[$$('.element-relations [data-view]')]`,
        title: 'Table or cards',
        text: 'The field renders Craft’s native element index, so everything looks and works like the rest of the control panel.',
        path: demo.skyline.path,
        act: `await indexReady(fieldIndex(), 'the usage list'); document.querySelector('.element-relations [data-view="cards"]').click(); await indexReady(fieldIndex(), 'cards'); scrollToElement('.element-relations', 90);`,
    },
    {
        name: 'entry-tab', edition: 'Lite',
        points: ['Entries, assets, categories, users, products, global sets', 'On its own tab or next to the content'],
        focus: `[document.querySelector('[role="tablist"]'), document.querySelector('.element-relations')]`,
        highlight: `[byText('[role="tab"]', 'Used in')]`,
        title: 'On any element type',
        text: 'Add the field to entries, assets, categories, users, products or global sets, e.g. on its own tab.',
        path: demo.hongKong.path,
        act: `clickTab('Used in'); await sleep(300); await indexReady(fieldIndex(), 'the usage list');`,
    },
    {
        name: 'index-column', edition: 'Lite',
        points: ['The first usages at a glance, plus the total', 'Sortable by the number of usages'],
        focus: `firstRows('.elements table', 7)`,
        highlight: `[column('Used in')]`,
        title: 'Right in the element index',
        text: 'As a column, with a preview of the first usages and the total. Sortable by number of usages.',
        path: '/admin/assets/images',
        act: `await indexReady(pageIndex(), 'the asset index'); document.querySelector('[data-view="table"]')?.click(); await indexReady(pageIndex(), 'the table');`,
    },
    {
        name: 'global-usages', edition: 'Lite',
        points: ['SEOmatic’s global and per-section images', 'Linked to their settings page'],
        focus: `document.querySelector('.element-relations')`,
        highlight: `[[document.querySelector('.element-relations-heading'), document.querySelector('.element-relations-heading')?.nextElementSibling]]`,
        title: 'Plugin settings count, too',
        text: 'Usages that are not elements, such as SEOmatic’s global SEO image, are listed below the field.',
        path: demo.social.path,
        act: `await sleep(800); scrollToElement('.element-relations', 90);`,
    },
    {
        name: 'used-filter', edition: 'Pro',
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
            const addButton = hud().$body.find('button.add').data('menubtn');
            const option = addButton.menu.$options.filter((i, o) => o.textContent.trim() === 'Used anywhere')[0];
            if (!option) throw new Error('No "Used anywhere" in: ' + addButton.menu.$options.map((i, o) => o.textContent.trim()).get().join(', '));
            addButton.menu.selectOption(option);
            await until(() => hud().$body.find('.lightswitch').length, 'the Used anywhere rule');
            await sleep(300);
            hud().$body.find('.lightswitch').data('lightswitch').turnOff();
            await sleep(500);
            hud().$body.find('button[type="submit"]').trigger('click');
            await sleep(300);
            await indexReady(index, 'the filtered assets');
            index.showFilterHud();
            await sleep(700);
        `,
    },
    {
        name: 'wizard', edition: 'Pro',
        points: ['Pick the replacement with Craft’s own picker', 'Untick what should keep the element', 'Review, then replace in the background'],
        focus: `document.querySelector('.element-relations-wizard').closest('.slideout')`,
        highlight: `[[document.querySelector('.element-relations-wizard [data-summary]')], [document.querySelector('.element-relations-wizard').closest('.slideout').querySelector('button[type="submit"]')]]`,
        title: 'Replace an element everywhere',
        text: 'A slideout right from the field: choose the replacement, the usages, review. Every supported field and plugin is rewritten the way it was read.',
        path: demo.skyline.path,
        act: `
            await indexReady(fieldIndex(), 'the usage list');
            document.querySelector('[data-element-relations-replace] .btn').click();
            await until(() => document.querySelector('.element-relations-wizard'), 'the wizard');
            await sleep(800);
            const wizard = document.querySelector('.element-relations-wizard');
            const picker = $(wizard).find('.elementselect').data('elementSelect');
            picker.showModal();
            await until(() => picker.modal?.elementIndex?.view, 'the picker');
            await indexReady(picker.modal.elementIndex, 'the picker');
            picker.modal.elementIndex.view.selectElementById(${demo.replacement.id});
            picker.modal.selectElements();
            await until(() => wizard.querySelector('.elementselect .chip'), 'the chosen replacement');
            const usages = wizard.querySelectorAll('[data-usage]');
            usages[usages.length - 1].click(); // leave one usage as it is
            await sleep(700);
        `,
    },
    {
        name: 'background', edition: 'Pro',
        points: ['Saving many or large entries never blocks the page', 'Keep working while it runs'],
        focus: `[...$$('.notification'), document.querySelector('.element-relations')]`,
        highlight: `[$$('.notification')]`,
        title: 'Runs in the background',
        text: 'Replacements and rollbacks run as queue jobs, as the user who started them. The notice follows the job.',
        act: `
            document.querySelector('.element-relations-wizard').closest('.slideout').querySelector('button[type="submit"]').click();
            await until(() => $$('.notification').some((n) => /background/.test(n.textContent)), 'the running notice');
            await sleep(400);
        `,
    },
    {
        name: 'undo', edition: 'Pro',
        points: ['Undo right in the notice', 'Entries get a revision with a note'],
        focus: `[document.querySelector('[data-element-relations-undo]').closest('.notification') ?? document.querySelector('[data-element-relations-undo]').parentElement, document.querySelector('.element-relations')]`,
        highlight: `[[document.querySelector('[data-element-relations-undo]').closest('.notification') ?? document.querySelector('[data-element-relations-undo]')]]`,
        title: 'Undo in one click',
        text: 'When the job is done, the list refreshes and the notice offers Undo.',
        act: `
            await until(() => document.querySelector('[data-element-relations-undo]'), 'the result with Undo', 90000);
            await indexReady(fieldIndex(), 'the refreshed list');
            scrollToElement('.element-relations', 90);
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
        name: 'history', edition: 'Pro',
        points: ['Roll back keeps changes made since', 'Revert to revisions uses Craft’s own revisions'],
        focus: `[byText('h2, h3', 'Replacements')[0], document.querySelector('.element-relations-history')]`,
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
        name: 'field-settings', edition: 'Lite',
        points: ['Like Craft’s relation fields', 'Authors switch the view in the index'],
        focus: `['View Mode', 'Show the status menu', 'Elements per page'].map((label) => byText('label', label)[0]?.closest('.field'))`,
        title: 'Field settings like Craft’s relation fields',
        text: 'View mode, status menu and page size.',
        path: demo.usedInField.path,
        act: `await sleep(500);`,
    },
    {
        name: 'permissions', edition: 'Pro',
        points: ['Replacing and rolling back are permissions', 'Everything else follows Craft’s view and save permissions'],
        focus: `permissionGroup()`,
        highlight: `[[permissionGroup()]]`,
        title: 'Permissions',
        text: 'Replacing and rolling back are permissions; everything else follows Craft’s view and save permissions.',
        path: demo.editors.path,
        act: `
            await sleep(500);
            const heading = [...document.querySelectorAll('h3, h2, legend, .heading')].find((h) => h.textContent.trim().startsWith('Element Relations'));
            scrollToElement(heading, 260);
            window.permissionGroup = () => heading.closest('.user-permissions') ?? heading.parentElement;
        `,
    },
    {
        name: 'plugin-settings', edition: 'Lite',
        points: ['Per environment in config/element-relations.php', 'Large replacements move to the queue'],
        focus: `$$('#content .field')`,
        title: 'Plugin settings',
        text: 'Batch size for rebuilding the cache, the background limit for replacements. Everything can be set per environment.',
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
 * @param {Region} [clip] only this part of the page
 * @returns {Promise<Buffer>}
 */
async function capture(browser, clip) {
    const { data } = await browser.send('Page.captureScreenshot', { format: 'png', ...(clip ? { clip: { ...clip, scale: 1 } } : {}) });
    return Buffer.from(data, 'base64');
}

/**
 * The focus and highlight boxes of a shot, the focus clamped to the viewport, highlights relative to it.
 * @param {Browser} browser
 * @param {Shot} shot
 * @returns {Promise<{focus: Region, highlights: Region[]}>}
 */
async function regions(browser, shot) {
    /** @type {{focus: Region | null, highlights: Region[]}} */
    const found = await evaluate(browser, `
        const focus = ${shot.focus ? `regionOf(${shot.focus}, 28)` : 'null'};
        const highlights = (${shot.highlight ?? '[]'}).filter((group) => [group].flat().filter(Boolean).length).map((group) => regionOf(group, 6));
        return { focus, highlights };
    `);
    const focus = found.focus ?? { x: 0, y: 0, width: VIEWPORT.width, height: VIEWPORT.height };
    const x = Math.max(0, Math.floor(focus.x)), y = Math.max(0, Math.floor(focus.y));
    const clamped = {
        x, y,
        width: Math.min(VIEWPORT.width, Math.ceil(focus.x + focus.width)) - x,
        height: Math.min(VIEWPORT.height, Math.ceil(focus.y + focus.height)) - y,
    };
    // relative to the focus box, and cut to it (a column may continue below the visible rows)
    const inset = 4;
    const highlights = found.highlights.map((h) => {
        const left = Math.max(h.x - clamped.x, inset), top = Math.max(h.y - clamped.y, inset);
        const right = Math.min(h.x + h.width - clamped.x, clamped.width - inset), bottom = Math.min(h.y + h.height - clamped.y, clamped.height - inset);
        return { x: left, y: top, width: right - left, height: bottom - top };
    }).filter((h) => h.width > 0 && h.height > 0);
    return { focus: clamped, highlights };
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
 * A zoomed shot in a small window, with glowing rings on the highlights.
 * @param {string} imageUrl
 * @param {Region} focus size of the captured part, in CSS pixels
 * @param {Region[]} highlights relative to the captured part
 * @param {string} address shown in the window's title bar
 * @param {number} zoom
 */
function windowHtml(imageUrl, focus, highlights, address, zoom) {
    const width = Math.round(focus.width * zoom), height = Math.round(focus.height * zoom);
    const rings = highlights
        .map((h) => `<span class="ring" style="left:${h.x * zoom}px;top:${h.y * zoom}px;width:${h.width * zoom}px;height:${h.height * zoom}px"></span>`)
        .join('');
    return `<div class="window" style="width:${width}px">
        <div class="bar"><span class="dot" style="background:#ff5f57"></span><span class="dot" style="background:#febc2e"></span><span class="dot" style="background:#28c840"></span><span class="address">${escapeHtml(address)}</span></div>
        <div class="shot" style="width:${width}px;height:${height}px"><img src="${imageUrl}" alt="" style="width:${width}px;height:${height}px">${rings}</div>
    </div>`;
}

/**
 * @param {Shot} shot
 * @param {string} imageUrl the captured part of the page
 * @param {{focus: Region, highlights: Region[]}} area
 * @param {string} address
 */
function frameHtml(shot, imageUrl, area, address) {
    const stacked = area.focus.width / area.focus.height > STACKED_ASPECT;
    const stage = stacked ? STACKED_STAGE : STAGE;
    const zoom = Math.min(stage.width / area.focus.width, (stage.height - 36) / area.focus.height, stage.maxZoom);
    const top = stage.top + (stage.height - (area.focus.height * zoom + 36)) / 2;
    const left = stage.left + (stage.width - area.focus.width * zoom) / 2;
    const points = shot.points.map((point) => `<li>${escapeHtml(point)}</li>`).join('');
    return `<!doctype html><html><head><meta charset="utf-8"><style>
        * { box-sizing: border-box; }
        body { margin: 0; width: ${FRAME.width}px; height: ${FRAME.height}px; overflow: hidden; position: relative; color: #fff;
            font-family: -apple-system, 'SF Pro Display', 'Avenir Next', 'Helvetica Neue', sans-serif; ${BACKDROP_CSS} }
        body::after { content: ''; position: absolute; inset: 0; background: ${GRAIN}; opacity: .08; mix-blend-mode: overlay; pointer-events: none; }
        .copy { position: absolute; left: 86px; top: 0; bottom: 0; width: 500px; display: flex; flex-direction: column; justify-content: center; }
        .stacked .copy { bottom: auto; top: 64px; width: auto; right: 86px; display: grid; grid-template-columns: 1fr 440px; column-gap: 60px; align-items: end; }
        .stacked .copy .brand, .stacked .copy h1, .stacked .copy p { grid-column: 1; }
        .stacked .copy ul { grid-column: 2; grid-row: 1 / span 3; margin: 0; align-self: end; }
        .stacked h1 { font-size: 48px; margin: 20px 0 12px; }
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
        .stage { position: absolute; left: ${left}px; top: ${top}px; }
        ${WINDOW_CSS}
    </style></head><body class="${stacked ? 'stacked' : ''}">
        <div class="copy">
            <div class="brand"><img src="${PLUGIN_ICON}" alt="">Element Relations <span class="badge">${shot.edition}</span></div>
            <h1>${escapeHtml(shot.title)}</h1>
            <p>${escapeHtml(shot.text)}</p>
            <ul>${points}</ul>
        </div>
        <div class="stage">${windowHtml(imageUrl, area.focus, area.highlights, address, zoom)}</div>
    </body></html>`;
}

/**
 * The cover for the README: the plugin's name over three of the zoomed shots, fanned out.
 * @param {{shot: Shot, raw: string, area: {focus: Region, highlights: Region[]}, address: string}[]} cards
 */
function coverHtml(cards) {
    const placements = [
        { left: 70, top: 430, width: 560, rotate: -6 },
        { left: 520, top: 390, width: 600, rotate: 0 },
        { left: 1000, top: 430, width: 540, rotate: 6 },
    ];
    const windows = cards.slice(0, 3).map((card, i) => {
        const place = placements[i];
        const zoom = place.width / card.area.focus.width;
        return `<div class="card" style="left:${place.left}px;top:${place.top}px;transform:rotate(${place.rotate}deg);z-index:${i === 1 ? 3 : 1}">${windowHtml(pathToFileURL(card.raw).href, card.area.focus, card.area.highlights, card.address, zoom)}</div>`;
    }).join('');
    return `<!doctype html><html><head><meta charset="utf-8"><style>
        * { box-sizing: border-box; }
        body { margin: 0; width: ${FRAME.width}px; height: ${FRAME.height}px; overflow: hidden; position: relative; color: #fff;
            font-family: -apple-system, 'SF Pro Display', 'Avenir Next', 'Helvetica Neue', sans-serif; ${BACKDROP_CSS} }
        body::after { content: ''; position: absolute; inset: 0; background: ${GRAIN}; opacity: .08; mix-blend-mode: overlay; pointer-events: none; z-index: 5; }
        .head { position: absolute; left: 0; right: 0; top: 84px; text-align: center; }
        .head img { width: 84px; height: 84px; border-radius: 22px; box-shadow: 0 18px 40px rgba(0, 0, 0, .45); }
        h1 { margin: 26px 0 12px; font-size: 76px; line-height: 1; font-weight: 800; letter-spacing: -.035em; }
        p { margin: 0; font-size: 26px; color: #c4cce0; }
        .card { position: absolute; transform-origin: center top; }
        ${WINDOW_CSS}
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
    await open(browser, `${baseUrl}/admin/login`);
    const login = await evaluate(browser, `
        const response = await fetch(Craft.getActionUrl('users/login'), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': Craft.csrfTokenValue },
            body: JSON.stringify({ loginName: ${JSON.stringify(process.env.CP_USER ?? 'mara')}, password: ${JSON.stringify(process.env.CP_PASSWORD ?? 'password')} }),
        });
        return response.status;
    `);
    if (login !== 200) {
        throw new Error(`Login failed (HTTP ${login})`);
    }

    /** @type {{shot: Shot, raw: string, area: {focus: Region, highlights: Region[]}, address: string}[]} */
    const taken = [];
    let currentPath = '';
    const lastWanted = only.length ? Math.max(...SHOTS.map((s, i) => (only.includes(s.name) ? i : -1))) : SHOTS.length - 1;
    for (const shot of SHOTS.slice(0, lastWanted + 1)) {
        // shots without a path continue on the page of the previous one, so they run with it
        if (shot.path) {
            currentPath = shot.path;
            await open(browser, baseUrl + shot.path);
            await evaluate(browser, PAGE_HELPERS);
        }
        if (shot.act) {
            await evaluate(browser, shot.act);
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
            const area = await regions(browser, shot);
            writeFileSync(raw, await capture(browser, area.focus));
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
        const target = resolve(outDir, `${shot.name}.png`);
        writeFileSync(target, await capture(browser));
        console.log(`  framed ${target}`);
    }

    // the cover: three of the shots, when they were all taken in this run
    const coverCards = ['usage-list', 'wizard', 'used-filter'].map((name) => taken.find((t) => t.shot.name === name));
    if (coverCards.every(Boolean)) {
        const html = join(workDir, 'cover.html');
        writeFileSync(html, coverHtml(/** @type {typeof taken} */ (coverCards)));
        await open(browser, pathToFileURL(html).href);
        writeFileSync(resolve(outDir, 'cover.png'), await capture(browser));
        console.log(`  cover ${resolve(outDir, 'cover.png')}`);
    }
} finally {
    await browser.close();
    rmSync(workDir, { recursive: true, force: true });
}
