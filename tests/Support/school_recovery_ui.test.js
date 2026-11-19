import { test } from 'node:test';
import assert from 'node:assert/strict';
import { webcrypto, createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { runInNewContext } from 'node:vm';
import { TextEncoder } from 'node:util';
import { fileURLToPath } from 'node:url';

const testDir = dirname(fileURLToPath(import.meta.url));

function loadPage(name, fetch, secure = true) {
    const html = readFileSync(join(testDir, '../../resources/views/auth', name), 'utf8');
    const match = html.match(/<script>([\s\S]*?)<\/script>/);
    assert.ok(match, 'inline page script exists');
    const elements = new Map();
    const get = id => {
        if (!elements.has(id)) {
            elements.set(id, {
                hidden: false, textContent: '', handlers: {},
                addEventListener(type, fn) { this.handlers[type] = fn; },
                reset() {},
            });
        }
        return elements.get(id);
    };
    const document = {
        querySelector: () => ({ content: 'csrf-synthetic' }),
        getElementById: get,
    };
    runInNewContext(match[1], {
        document, fetch, crypto: webcrypto, window: { isSecureContext: secure, crypto: webcrypto },
        TextEncoder, Uint8Array, Array, JSON, Error, encodeURIComponent,
    });
    return { get, elements };
}

const event = target => ({ target, preventDefault() {} });
const response = data => ({ ok: true, json: async () => data });

test('student browser commits a secret but displays only the request ID', async () => {
    const id = '01J00000000000000000000000';
    const calls = [];
    const page = loadPage('school-recovery-student.blade.php', async (url, options) => {
        calls.push({ url, body: JSON.parse(options.body) });
        return response(url.endsWith('/request') ? { recovery_id: id } : { status: 'password_updated' });
    });
    await page.get('start').handlers.submit(event({ username: { value: 'student-dummy' } }));
    assert.equal(page.get('request-id').textContent, id);
    assert.equal(calls[0].url, '/recovery/school-assisted/request');
    assert.deepEqual(Object.keys(calls[0].body).sort(), ['commitment', 'username']);
    assert.match(calls[0].body.commitment, /^[0-9a-f]{64}$/);
    await page.get('reset').handlers.submit(event({
        password: { value: 'SyntheticPassword!234' },
        password_confirmation: { value: 'SyntheticPassword!234' },
        reset() {},
    }));
    assert.equal(calls[1].body.recovery_id, id);
    assert.match(calls[1].body.token, /^[0-9a-f]{64}$/);
    assert.equal(createHash('sha256').update(calls[1].body.token).digest('hex'), calls[0].body.commitment);
    assert.ok([...page.elements.values()].every(element => !element.textContent.includes(calls[1].body.token)));
    assert.match(page.get('message').textContent, /berhasil/);
});

test('student browser refuses to start in an insecure context', async () => {
    let called = false;
    const page = loadPage('school-recovery-student.blade.php', async () => { called = true; }, false);
    await page.get('start').handlers.submit(event({ username: { value: 'student-dummy' } }));
    assert.equal(called, false);
    assert.match(page.get('message').textContent, /HTTPS/);
});

test('admin browser displays the identity and approves without receiving a student secret', async () => {
    const id = '01J00000000000000000000000';
    const calls = [];
    const page = loadPage('school-recovery-admin.blade.php', async (url, options) => {
        calls.push({ url, options });
        return response(url.endsWith('/approve') ? { status: 'approved' } : {
            data: { recovery_id: id, username: 'student-dummy', display_name: 'Siswa Dummy' },
        });
    });
    await page.get('lookup').handlers.submit(event({ recovery_id: { value: id } }));
    assert.equal(page.get('name').textContent, 'Siswa Dummy');
    assert.equal(page.get('username').textContent, 'student-dummy');
    await page.get('approve').handlers.submit(event({ verified: { checked: true } }));
    assert.equal(calls[1].url, '/members/recovery/' + id + '/approve');
    assert.deepEqual(JSON.parse(calls[1].options.body), { identity_verified_offline: true });
    assert.match(page.get('message').textContent, /disetujui/);
});
