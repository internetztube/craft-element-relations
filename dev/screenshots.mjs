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
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const [projectDir, baseUrl = 'http://localhost:8080', outDir = 'docs/screenshots'] = process.argv.slice(2);
if (!projectDir) {
    console.error('Usage: node dev/screenshots.mjs <craft-project-dir> [base-url] [out-dir]');
    process.exit(1);
}

/** Browser viewport of the raw control panel shots, in CSS pixels; captured at twice the resolution. */
const VIEWPORT = { width: 1440, height: 900, scale: 2 };
/** The framed image: canvas size in CSS pixels and its device scale (2400 × 1770 px). */
const FRAME = { width: 1600, height: 1180, scale: 1.5 };
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
        title: 'See where every element is used',
        text: 'One list of everything that links to it: entries, Matrix blocks, products, menus, forms, in every site.',
        path: demo.skyline.path,
        act: `await indexReady(fieldIndex(), 'the usage list'); scrollToElement('.element-relations', 90);`,
    },
    {
        name: 'type-menu', edition: 'Lite',
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
        title: 'Table or cards',
        text: 'The field renders Craft’s native element index, so everything looks and works like the rest of the control panel.',
        path: demo.skyline.path,
        act: `await indexReady(fieldIndex(), 'the usage list'); document.querySelector('.element-relations [data-view="cards"]').click(); await indexReady(fieldIndex(), 'cards'); scrollToElement('.element-relations', 90);`,
    },
    {
        name: 'entry-tab', edition: 'Lite',
        title: 'On any element type',
        text: 'Add the field to entries, assets, categories, users, products or global sets, e.g. on its own tab.',
        path: demo.hongKong.path,
        act: `clickTab('Used in'); await sleep(300); await indexReady(fieldIndex(), 'the usage list');`,
    },
    {
        name: 'index-column', edition: 'Lite',
        title: 'Right in the element index',
        text: 'As a column, with a preview of the first usages and the total. Sortable by number of usages.',
        path: '/admin/assets/images',
        act: `await indexReady(pageIndex(), 'the asset index'); document.querySelector('[data-view="table"]')?.click(); await indexReady(pageIndex(), 'the table');`,
    },
    {
        name: 'global-usages', edition: 'Lite',
        title: 'Plugin settings count, too',
        text: 'Usages that are not elements, such as SEOmatic’s global SEO image, are listed below the field.',
        path: demo.social.path,
        act: `await sleep(800); scrollToElement('.element-relations', 90);`,
    },
    {
        name: 'used-filter', edition: 'Pro',
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
        name: 'wizard-step1', edition: 'Pro',
        title: 'Replace an element everywhere',
        text: 'Step 1: pick the replacement. Every supported field and plugin is rewritten the way it was read.',
        path: demo.skyline.path,
        act: `
            await indexReady(fieldIndex(), 'the usage list');
            document.querySelector('[data-element-relations-replace] .btn').click();
            await until(() => visibleModal()?.elementIndex?.view, 'step 1');
            await indexReady(visibleModal().elementIndex, 'step 1');
        `,
    },
    {
        name: 'wizard-step2', edition: 'Pro',
        title: 'Choose where to replace it',
        text: 'Step 2: tick the usages. Only elements the user may save are touched.',
        act: `
            const step1 = visibleModal();
            step1.elementIndex.view.selectElementById(${demo.replacement.id});
            step1.selectElements();
            await until(() => visibleModal() && visibleModal() !== step1 && visibleModal().elementIndex?.view, 'step 2');
            const step2 = visibleModal();
            await indexReady(step2.elementIndex, 'step 2');
            for (const id of ${JSON.stringify(demo.replaceIn)}) step2.elementIndex.view.selectElementById(id);
            await sleep(400);
        `,
    },
    {
        name: 'undo', edition: 'Pro',
        title: 'Undo in one click',
        text: 'The notice stays until closed and offers Undo. Entries get a revision with a note.',
        act: `
            visibleModal().selectElements();
            await until(() => document.querySelector('[data-element-relations-undo]'), 'the notice');
            await indexReady(fieldIndex(), 'the refreshed list');
            scrollToElement('.element-relations', 90);
            await sleep(500);
        `,
        // undo the replacement, so the demo stays as it was (the history then shows it as rolled back)
        after: `
            document.querySelector('[data-element-relations-undo]').click();
            await until(() => !document.querySelector('[data-element-relations-undo]'), 'the undo');
            await sleep(800);
        `,
    },
    {
        name: 'history', edition: 'Pro',
        title: 'History, rollback and revisions',
        text: 'Utilities → Element Relations lists every replacement with Roll back and Revert to revisions.',
        path: '/admin/utilities/element-relations',
        // one more replacement that stays applied for the shot (rolled back right after), so the history shows both
        // states and the Roll back / Revert to revisions buttons
        act: `
            const response = await Craft.sendActionRequest('POST', 'element-relations/replace/replace', { data: {
                fromId: ${demo.skyline.id}, toId: ${demo.replacement.id}, siteId: 1, sourceIds: [${demo.replaceIn[0]}],
            } });
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
            [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Toggle sidebar')?.click();
            await sleep(300);
        `,
    },
    {
        name: 'field-settings', edition: 'Lite',
        title: 'Field settings like Craft’s relation fields',
        text: 'View mode, status menu and page size.',
        path: demo.usedInField.path,
        act: `await sleep(500);`,
    },
    {
        name: 'permissions', edition: 'Pro',
        title: 'Permissions',
        text: 'Replacing and rolling back are permissions; everything else follows Craft’s view and save permissions.',
        path: demo.editors.path,
        act: `
            await sleep(500);
            const heading = [...document.querySelectorAll('h3, h2, legend, .heading')].find((h) => h.textContent.trim().startsWith('Element Relations'));
            scrollToElement(heading, 260);
            // highlight the plugin's permissions among everything else on the page
            const group = heading.closest('.user-permissions') ?? heading.parentElement;
            Object.assign(group.style, { outline: '3px solid #ff7a43', outlineOffset: '10px', borderRadius: '6px', boxShadow: '0 0 0 14px rgba(255, 122, 67, .08)' });
        `,
    },
    {
        name: 'plugin-settings', edition: 'Lite',
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
 * @param {Browser} browser
 * @returns {Promise<Buffer>}
 */
async function capture(browser) {
    const { data } = await browser.send('Page.captureScreenshot', { format: 'png' });
    return Buffer.from(data, 'base64');
}

// ---------------------------------------------------------------------------------------------------------------
// The frame
// ---------------------------------------------------------------------------------------------------------------

/** @param {string} text */
function escapeHtml(text) {
    return text.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] ?? c);
}

/**
 * @param {Shot} shot
 * @param {string} imageUrl the raw screenshot
 * @param {string} address shown in the address bar
 */
function frameHtml(shot, imageUrl, address) {
    const windowWidth = 1400;
    const imageHeight = Math.round(windowWidth * VIEWPORT.height / VIEWPORT.width);
    return `<!doctype html><html><head><meta charset="utf-8"><style>
        * { box-sizing: border-box; }
        body {
            margin: 0; width: ${FRAME.width}px; height: ${FRAME.height}px; overflow: hidden;
            font-family: -apple-system, 'SF Pro Display', 'Avenir Next', 'Helvetica Neue', sans-serif; color: #1b2236;
            background:
                radial-gradient(900px 600px at 0% 0%, #ffd9cc 0%, transparent 70%),
                radial-gradient(900px 700px at 100% 0%, #e2d9ff 0%, transparent 70%),
                radial-gradient(1200px 700px at 50% 120%, #cfe6ff 0%, transparent 70%),
                #f7f5fb;
        }
        header { position: absolute; left: 100px; right: 100px; top: 58px; }
        .eyebrow {
            display: inline-flex; gap: 8px; align-items: center; font-size: 15px; font-weight: 600; letter-spacing: .08em;
            text-transform: uppercase; color: #5b6478;
        }
        .badge { padding: 3px 9px; border-radius: 99px; font-size: 12px; letter-spacing: .06em; color: #fff; background: ${shot.edition === 'Pro' ? 'linear-gradient(135deg, #ff5f45, #ffb23e)' : '#5b6478'}; }
        h1 { margin: 10px 0 6px; font-size: 40px; line-height: 1.15; font-weight: 700; letter-spacing: -.02em; }
        p { margin: 0; font-size: 20px; color: #4a5470; }
        .window {
            position: absolute; left: 100px; top: 196px; width: ${windowWidth}px; border-radius: 14px; overflow: hidden; background: #fff;
            box-shadow: 0 50px 100px -30px rgba(27, 34, 54, .45), 0 18px 36px -18px rgba(27, 34, 54, .3), 0 0 0 1px rgba(27, 34, 54, .08);
        }
        .bar { height: 40px; display: flex; align-items: center; gap: 8px; padding: 0 16px; background: #f1f2f6; border-bottom: 1px solid #e2e4ea; }
        .dot { width: 12px; height: 12px; border-radius: 50%; }
        .address {
            margin: 0 auto; transform: translateX(-30px); width: 520px; height: 26px; border-radius: 7px; background: #fff;
            border: 1px solid #e2e4ea; font-size: 13px; color: #6b7387; display: flex; align-items: center; justify-content: center;
        }
        img { display: block; width: ${windowWidth}px; height: ${imageHeight}px; }
    </style></head><body>
        <header>
            <div class="eyebrow">Element Relations <span class="badge">${shot.edition}</span></div>
            <h1>${escapeHtml(shot.title)}</h1>
            <p>${escapeHtml(shot.text)}</p>
        </header>
        <div class="window">
            <div class="bar">
                <span class="dot" style="background:#ff5f57"></span><span class="dot" style="background:#febc2e"></span><span class="dot" style="background:#28c840"></span>
                <span class="address">${escapeHtml(address)}</span>
            </div>
            <img src="${imageUrl}" alt="">
        </div>
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

    /** @type {{shot: Shot, raw: string, address: string}[]} */
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
            writeFileSync(raw, await capture(browser));
            taken.push({ shot, raw, address: `transit.test${currentPath.replace(/\?.*$/, '')}` });
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
    for (const { shot, raw, address } of taken) {
        const html = join(workDir, `${shot.name}.html`);
        writeFileSync(html, frameHtml(shot, pathToFileURL(raw).href, address));
        await open(browser, pathToFileURL(html).href);
        const target = resolve(outDir, `${shot.name}.png`);
        writeFileSync(target, await capture(browser));
        console.log(`  framed ${target}`);
    }
} finally {
    await browser.close();
    rmSync(workDir, { recursive: true, force: true });
}
