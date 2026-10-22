/* Shared authentication transport for fetch, legacy XHR and dynamic forms. */
(function (window, document) {
    'use strict';
    function csrf() { return document.querySelector('meta[name="md-csrf-token"]')?.content || ''; }
    function local(url) { try { return new URL(url, document.baseURI).origin === window.location.origin; } catch { return false; } }
    function expired(status) {
        if (status === 401 && csrf()) window.location.assign(new URL((window.ROOTHTML || '/') + 'admin.php?auth=login', window.location.origin));
    }
    const originalFetch = window.fetch;
    window.fetch = function (input, init) {
        const url = input instanceof Request ? input.url : input;
        if (!csrf() || !local(url)) return originalFetch.call(this, input, init);
        const options = { ...init };
        const headers = new Headers(options.headers || (input instanceof Request ? input.headers : undefined));
        if (!headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', csrf());
        if (!headers.has('X-Requested-With')) headers.set('X-Requested-With', 'XMLHttpRequest');
        options.headers = headers;
        return originalFetch.call(this, input, options).then(response => { expired(response.status); return response; });
    };
    const open = XMLHttpRequest.prototype.open;
    const send = XMLHttpRequest.prototype.send;
    const setHeader = XMLHttpRequest.prototype.setRequestHeader;
    const destinations = new WeakMap();
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        destinations.set(this, { local: local(url), csrf: false, ajax: false });
        return open.call(this, method, url, ...rest);
    };
    XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
        const state = destinations.get(this);
        if (state && name.toLowerCase() === 'x-csrf-token') state.csrf = true;
        if (state && name.toLowerCase() === 'x-requested-with') state.ajax = true;
        return setHeader.call(this, name, value);
    };
    XMLHttpRequest.prototype.send = function (body) {
        const state = destinations.get(this);
        if (state?.local && csrf()) {
            if (!state.csrf) this.setRequestHeader('X-CSRF-Token', csrf());
            if (!state.ajax) this.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            this.addEventListener('load', () => expired(this.status), { once: true });
        }
        return send.call(this, body);
    };
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !csrf() || !local(form.action) || form.method.toLowerCase() === 'get') return;
        let field = form.querySelector('input[name="_md_csrf"]');
        if (!field) { field = document.createElement('input'); field.type = 'hidden'; field.name = '_md_csrf'; form.append(field); }
        field.value = csrf();
    }, true);
})(window, document);
