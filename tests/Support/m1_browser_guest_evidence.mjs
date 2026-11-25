// Read-only public HTTP evidence using an installed Chrome, not the in-app browser.
// Never logs DOM/headers/cookies or submits forms. No third-party packages required.
import assert from 'node:assert/strict';
import { execFile, execFileSync } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';
import { access, copyFile, mkdir, mkdtemp, readFile, realpath, rm, writeFile } from 'node:fs/promises';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const execute = promisify(execFile);
const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const chrome = process.env.M1_BROWSER_EXECUTABLE ?? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const hosts = ['admin.platform.test', 'sekolah-a.platform.test', 'sekolah-b.platform.test', 'sekolah-c.platform.test'];
const cases = [
    ...hosts.map((host, index) => ({ id: `${index + 1}-login-${host.split('.')[0]}`, host, route: '/login', kind: 'login', status: 200 })),
    { id: '5-unknown-host', host: 'unknown.platform.test', route: '/login', kind: 'not-found', status: 404 },
    { id: '6-loopback', host: '127.0.0.1', route: '/', kind: 'not-found', status: 404 },
    ...hosts.map((host, index) => ({ id: `${index + 7}-guest-dashboard-${host.split('.')[0]}`, host, route: '/dashboard', kind: 'login', status: 302 })),
    { id: '11-recovery-public-http', host: 'sekolah-a.platform.test', route: '/recovery/school-assisted', kind: 'recovery', status: 200 },
];

function probe(entry) {
    return new Promise((resolve, reject) => {
        const request = http.get({ hostname: '127.0.0.1', port: 8080, path: entry.route, headers: { Host: `${entry.host}:8080` } }, response => {
            const status = response.statusCode;
            // Only record a boolean, not response cookies or arbitrary redirect data.
            const loginRedirect = response.headers.location === '/login'
                || response.headers.location === `http://${entry.host}:8080/login`;
            response.resume();
            response.on('end', () => resolve({ status, loginRedirect }));
        });
        request.setTimeout(15000, () => request.destroy(new Error('Local HTTP probe timed out.')));
        request.on('error', () => reject(new Error('Local HTTP probe failed; start the existing LMS containers first.')));
    });
}

function visibleText(html, tag) {
    const match = html.match(new RegExp(`<${tag}[^>]*>([\\s\\S]*?)<\\/${tag}>`, 'i'));
    return (match?.[1] ?? '').replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim().slice(0, 160);
}

function inspectPage(entry, html) {
    assert.ok(!/chrome-error:\/\/|ERR_CONNECTION|ERR_NAME_NOT_RESOLVED|ERR_PROXY|ERR_CERT_/i.test(html), 'Browser navigation failed.');
    assert.ok(!/Whoops|Ignition|Stack trace|APP_KEY=|DB_PASSWORD=/i.test(html), 'Unexpected debug page; screenshot withheld.');
    const title = visibleText(html, 'title');
    if (entry.kind === 'not-found') {
        assert.equal(title, 'Not Found');
        return { title, assertion: 'Unregistered host renders the application Not Found page.' };
    }
    if (entry.kind === 'recovery') {
        assert.equal(title, 'Bantuan password siswa');
        assert.ok(html.includes('Fitur ini memerlukan HTTPS'));
        assert.ok(/<form[^>]+id="start"/i.test(html));
        assert.ok(/<section[^>]+id="finish"[^>]+hidden/i.test(html));
        return { title, assertion: 'Public recovery page renders; HTTPS requirement is visible; no request or reset submitted.' };
    }
    const central = entry.host === hosts[0];
    assert.equal(title, central ? 'Login Platform' : 'Login Sekolah');
    assert.equal(visibleText(html, 'h1'), title);
    assert.ok(html.includes(central ? 'Email terverifikasi' : 'Username sekolah'));
    assert.ok(html.includes(central ? 'Portal pusat SaaS' : `Portal ${entry.host.split('.')[0]}`));
    assert.ok(/<input[^>]+type="password"/i.test(html));
    const action = html.match(/<form[^>]+action="([^"]+)"/i)?.[1];
    assert.equal(new URL(action, `http://${entry.host}:8080`).href, `http://${entry.host}:8080/login`);
    return { title, formAction: `http://${entry.host}:8080/login`, assertion: 'Correct host-specific login form renders; no credentials entered.' };
}

await access(chrome);
const stamp = new Date().toISOString().replace(/[-:.]/g, '');
const evidenceRelative = `docs/evidence/m1-browser/public-http-${stamp}-${randomUUID().slice(0, 8)}`;
const evidenceDirectory = path.join(projectRoot, evidenceRelative);
await mkdir(path.dirname(evidenceDirectory), { recursive: true });
await mkdir(evidenceDirectory); // Never overwrite an earlier run.
const tempRoot = await realpath(os.tmpdir());
const profileRoot = await mkdtemp(path.join(tempRoot, 'lms-m1-browser-'));
const manifest = {
    schemaVersion: 1,
    startedAtUtc: new Date().toISOString(),
    timezone: 'Asia/Jakarta',
    commit: execFileSync('git', ['rev-parse', 'HEAD'], { cwd: projectRoot, encoding: 'utf8', windowsHide: true }).trim(),
    browser: 'Installed Google Chrome, unified headless mode, fresh profile per case',
    browserVersion: /^\d+(\.\d+){3}$/.test(process.env.M1_BROWSER_VERSION ?? '') ? process.env.M1_BROWSER_VERSION : 'not recorded',
    transport: 'HTTP only',
    credentialsEntered: false,
    formsSubmitted: false,
    tlsVerificationDisabled: false,
    hostMapping: '*.platform.test -> 127.0.0.1, browser-process only; Windows hosts unchanged',
    scope: 'Public/guest browser rendering and unauthenticated redirects only; NOT authenticated tenant/session isolation or HTTPS acceptance.',
    inAppBrowser: 'Unavailable before navigation: missing field sandboxPolicy (two attempts). Standalone Chrome CLI used instead.',
    notTested: ['Trusted HTTPS and Secure cookies', 'Successful login and MFA', 'Authenticated A/B/C session isolation', 'Password change and old-session rejection', 'Two-session student/admin recovery'],
    results: [],
};

try {
    for (const entry of cases) {
        const httpProbe = await probe(entry);
        assert.equal(httpProbe.status, entry.status, `${entry.id}: unexpected HTTP preflight status.`);
        if (entry.status === 302) assert.equal(httpProbe.loginRedirect, true, 'Guest redirect must stay on the same host login.');
        const profile = path.join(profileRoot, entry.id);
        const temporaryScreenshot = path.join(profileRoot, `${entry.id}.png`);
        const url = `http://${entry.host}:8080${entry.route}`;
        const { stdout } = await execute(chrome, [
            '--headless', '--no-first-run', '--no-default-browser-check', '--disable-background-networking',
            '--disable-component-update', '--disable-sync', '--no-pings', '--no-proxy-server',
            '--host-resolver-rules=MAP *.platform.test 127.0.0.1', '--window-size=1280,900', '--hide-scrollbars',
            `--user-data-dir=${profile}`, `--screenshot=${temporaryScreenshot}`, '--dump-dom', url,
        ], { timeout: 45000, maxBuffer: 2 * 1024 * 1024, windowsHide: true });
        // Keep raw HTML/CSRF only in memory; never persist or print it.
        const page = inspectPage(entry, stdout);
        const png = await readFile(temporaryScreenshot);
        assert.equal(png.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
        const screenshot = `${entry.id}.png`;
        await copyFile(temporaryScreenshot, path.join(evidenceDirectory, screenshot));
        manifest.results.push({ id: entry.id, url, checkedAtUtc: new Date().toISOString(), httpProbe, ...page, screenshot,
            screenshotSha256: createHash('sha256').update(png).digest('hex'), status: 'PASS' });
        console.log(`PASS ${entry.id}`);
    }
    manifest.status = 'PASS_PUBLIC_HTTP_ONLY';
} catch {
    // No raw browser stderr, HTML, stack trace, cookie or token in artifacts.
    manifest.status = 'INCOMPLETE';
    manifest.failure = 'Evidence capture stopped. Inspect the local browser/application safely and rerun; no full acceptance claimed.';
    process.exitCode = 1;
} finally {
    manifest.completedAtUtc = new Date().toISOString();
    await writeFile(path.join(evidenceDirectory, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`, { flag: 'wx' });
    const resolvedProfile = await realpath(profileRoot);
    assert.equal(path.dirname(resolvedProfile).toLowerCase(), tempRoot.toLowerCase());
    assert.ok(path.basename(resolvedProfile).startsWith('lms-m1-browser-'));
    // Delete only this freshly-created, validated temporary browser profile tree.
    await rm(resolvedProfile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
    console.log(`Evidence: ${evidenceRelative}/manifest.json`);
    console.log(`Scope: ${manifest.status}; no authenticated or HTTPS acceptance claimed.`);
}
