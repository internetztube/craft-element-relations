/**
 * Headless Chrome over the DevTools protocol, no packages (Node 24, runs as TypeScript without a build step): what dev/ui.mts (the control panel tests) and
 * dev/screenshots.mts drive. Env: CHROME (path to Chrome; otherwise the usual places are tried).
 */
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export interface Browser {
    send: (method: string, params?: object) => Promise<any>;
    once: (event: string) => Promise<any>;
    close: () => Promise<void>;
}

export interface Size {
    width: number;
    height: number;
    scale?: number;
}

export function sleep(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/** Chrome's executable: CHROME, else the usual places (macOS app, Linux packages). */
export function chromePath(): string {
    const candidates = [
        process.env.CHROME,
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser',
    ];
    const found = candidates.find((path): path is string => !!path && existsSync(path));
    if (!found) {
        throw new Error('Chrome not found (set CHROME to its path)');
    }
    return found;
}

export async function launchChrome(debuggingPort: number): Promise<Browser> {
    const profile = mkdtempSync(join(tmpdir(), 'element-relations-chrome-'));
    const chrome = spawn(chromePath(), [
        '--headless=new', `--remote-debugging-port=${debuggingPort}`, `--user-data-dir=${profile}`,
        '--hide-scrollbars', '--no-first-run', '--no-default-browser-check', '--force-color-profile=srgb',
        '--allow-file-access-from-files', '--no-sandbox', 'about:blank',
    ], { stdio: 'ignore' });

    let targets: { type: string, webSocketDebuggerUrl: string }[] = [];
    // up to 30 s: a CI runner busy with the coverage run can be slow to start it
    for (let attempt = 0; attempt < 300 && !targets.some((t) => t.type === 'page'); attempt++) {
        await sleep(100);
        targets = await fetch(`http://127.0.0.1:${debuggingPort}/json/list`).then((r) => r.json()).catch(() => []);
    }
    const page = targets.find((t) => t.type === 'page');
    if (!page) {
        chrome.kill();
        throw new Error('Chrome did not start');
    }

    const socket = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        socket.onopen = resolve;
        socket.onerror = reject;
    });
    let lastId = 0;
    const pending = new Map<number, { resolve: (value: any) => void, reject: (error: Error) => void }>();
    const waiting = new Map<string, ((params: any) => void)[]>();
    const send = (method: string, params: object = {}) => new Promise<any>((resolve, reject) => {
        const id = ++lastId;
        pending.set(id, { resolve, reject });
        socket.send(JSON.stringify({ id, method, params }));
    });
    socket.onmessage = (message) => {
        const data = JSON.parse(String(message.data));
        if (data.id && pending.has(data.id)) {
            const { resolve, reject } = pending.get(data.id) ?? { resolve: () => {}, reject: () => {} };
            pending.delete(data.id);
            data.error ? reject(new Error(data.error.message)) : resolve(data.result);
        } else if (data.method === 'Page.javascriptDialogOpening') {
            send('Page.handleJavaScriptDialog', { accept: true }); // confirm() of index actions: "OK", like a user would
        } else if (data.method && waiting.has(data.method)) {
            for (const callback of waiting.get(data.method) ?? []) callback(data.params);
            waiting.delete(data.method);
        }
    };
    return {
        send,
        once: (event: string) => new Promise((resolve) => waiting.set(event, [...(waiting.get(event) ?? []), resolve])),
        close: async () => {
            socket.close();
            const exited = new Promise((resolve) => chrome.once('exit', resolve));
            chrome.kill();
            await exited;
            // Chrome's helper processes may still write to the profile after the main one exited; a leftover temp
            // profile is harmless, so cleaning up never fails the suite
            try {
                rmSync(profile, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
            } catch {
                // left to the OS's temp cleanup
            }
        },
    };
}

/** Runs JavaScript in the page (top-level await allowed) and returns its value. */
export async function evaluate(browser: Browser, code: string): Promise<any> {
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

export async function open(browser: Browser, url: string): Promise<void> {
    const loaded = browser.once('Page.loadEventFired');
    await browser.send('Page.navigate', { url });
    await loaded;
    await sleep(800);
}

export async function resize(browser: Browser, size: Size): Promise<void> {
    await browser.send('Emulation.setDeviceMetricsOverride', {
        width: size.width, height: size.height, deviceScaleFactor: size.scale ?? 1, mobile: false,
    });
}

/** A fresh control panel session for this user (the demo users have the dev password). */
export async function login(browser: Browser, baseUrl: string, username: string, password: string): Promise<void> {
    await browser.send('Network.clearBrowserCookies');
    await open(browser, `${baseUrl}/admin/login`);
    const status = await evaluate(browser, `
        const response = await fetch(Craft.getActionUrl('users/login'), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': Craft.csrfTokenValue },
            body: JSON.stringify({ loginName: ${JSON.stringify(username)}, password: ${JSON.stringify(password)} }),
        });
        return response.status;
    `);
    if (status !== 200) {
        throw new Error(`Login as ${username} failed (HTTP ${status})`);
    }
}

/** Page helpers for the control panel, defined once per page load (window.*). */
export const PAGE_HELPERS = `
window.sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
window.until = async (test, what, timeout = 15000) => {
    const start = Date.now();
    while (!test()) {
        if (Date.now() - start > timeout) throw new Error('Timed out waiting for ' + what);
        await sleep(100);
    }
};
/** The field's usage list (Craft's element index inside the Element Relations field). */
window.fieldIndex = () => $('.element-relations .element-index').data('elementIndex');
/** The page's element index (Entries, Assets, …). */
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
window.$$ = (selector) => [...document.querySelectorAll(selector)].filter((e) => e.getClientRects().length > 0);
window.byText = (selector, text) => $$(selector).filter((e) => e.textContent.trim() === text);
/** A table column by its header label: the header cell and the cells below it. */
window.column = (label) => {
    const header = byText('th', label).find((th) => th.closest('table')?.querySelector('tbody tr'));
    if (!header) return [];
    const index = [...header.parentElement.children].indexOf(header);
    const rows = [...header.closest('table').querySelectorAll('tbody tr')];
    return [header, ...rows.map((row) => row.children[index]).filter(Boolean)];
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
window.visibleModal = () => $('.modal:visible').last().data('modal') ?? Garnish.Modal.visibleModal;
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
/** Texts of the notices on the page. */
window.notices = () => $$('.notification').map((n) => n.textContent.replace(/\\s+/g, ' ').trim());
// index actions ask with the browser's confirm() (e.g. Delete): answered "OK" like the user would
window.confirm = () => true;
// trial and dev mode notices are not part of what the plugin looks like
const style = document.createElement('style');
style.textContent = '#trial-info, #devmode { display: none !important; }';
document.head.append(style);
`;
