/* 2026-10-23 — восстановление результата оборванного SSE без повторного запуска. */
(function (window, document) {
    'use strict';
    var labels = {
        find_objects: 'Ищу устройства', read_object: 'Изучаю объект', read_property: 'Читаю свойство',
        find_references: 'Проверяю связи и соседние устройства', read_code: 'Читаю код',
        read_history: 'Изучаю историю', find_scripts: 'Ищу сценарии',
        search_knowledge: 'Ищу в справке', read_knowledge: 'Читаю документацию', create_entity: 'Готовлю предложение создания'
    };
    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined) node.textContent = text;
        return node;
    }
    function endpoint() { return (window.ROOTHTML || '/').replace(/\/?$/, '/') + 'panel/ai_assistant.html?ajax=1'; }
    async function request(op, data, signal, stream) {
        var meta = document.querySelector('meta[name="md-csrf-token"]');
        var response = await fetch(endpoint() + '&op=' + encodeURIComponent(op), {
            method: 'POST', credentials: 'same-origin', signal: signal,
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-Token': meta ? meta.content : '', 'X-Requested-With': 'XMLHttpRequest',
                'Accept': stream ? 'text/event-stream' : 'application/json'},
            body: new URLSearchParams(data || {}).toString()
        });
        if (stream && response.ok && (response.headers.get('Content-Type') || '').includes('text/event-stream')) return response;
        var json;
        try { json = await response.json(); }
        catch (error) { throw new Error('Сервер вернул неполный ответ. Проверьте соединение и журнал PHP.'); }
        if (!response.ok || !json || json.ok === false || stream) throw new Error(json && json.message || 'Сервер отклонил запрос.');
        return json;
    }
    function errorText(error) {
        if (error && /network|failed to fetch|fetch failed|load failed/i.test(error.message)) {
            return 'Соединение с помощником прервано. Полученная часть ответа сохранена на экране. Можно повторить запрос.';
        }
        return error && error.message || 'Поток ответа прерван.';
    }
    async function consume(response, onEvent) {
        if (!response.body) throw new Error('Браузер не поддерживает потоковый ответ.');
        var reader = response.body.getReader();
        var decoder = new TextDecoder('utf-8');
        var buffer = '', terminal = false;
        function dispatch(raw) {
            var name = 'message', data = [];
            raw.split('\n').forEach(function (line) {
                if (line.startsWith('event:')) name = line.slice(6).trim();
                else if (line.startsWith('data:')) data.push(line.slice(5).trimStart());
            });
            if (!data.length) return;
            var payload;
            try { payload = JSON.parse(data.join('\n')); }
            catch (error) { throw new Error('Сервер прислал повреждённое событие ответа.'); }
            if (name === 'done' || name === 'error') terminal = true;
            onEvent(name, payload);
        }
        try {
            while (true) {
                var timer;
                var chunk;
                try {
                    chunk = await Promise.race([reader.read(), new Promise(function (_, reject) {
                        timer = window.setTimeout(function () { reject(new Error('Нет связи с помощником более 90 секунд. Запрос остановлен.')); }, 90000);
                    })]);
                } finally { window.clearTimeout(timer); }
                buffer += decoder.decode(chunk.value || new Uint8Array(), {stream: !chunk.done});
                buffer = buffer.replace(/\r\n/g, '\n');
                var index;
                while ((index = buffer.indexOf('\n\n')) >= 0) {
                    dispatch(buffer.slice(0, index));
                    buffer = buffer.slice(index + 2);
                    // После конечного события не ждём закрытия HTTP: прокси может
                    // оборвать его, хотя готовый ответ уже доставлен.
                    if (terminal) return;
                }
                if (chunk.done) break;
            }
            if (buffer.trim()) dispatch(buffer);
            if (!terminal) throw new Error('Поток завершился без ответа сервера. Запрос можно повторить.');
        } finally {
            // Не ждём сетевого завершения отмены: сломанный поток может не ответить.
            reader.cancel().catch(function () {});
            reader.releaseLock();
        }
    }
    async function recoverRun(dialogId, runId, signal, onEvent) {
        onEvent('status', {text: 'Связь прервана. Проверяю результат запроса на сервере…'});
        var started = Date.now();
        while (Date.now() - started < 650000) {
            var state = await request('run_status', {dialog_id: dialogId, run_id: runId}, signal);
            var run = state.run;
            if (!run || run.ID !== runId) throw new Error('Не удалось найти состояние этого запроса. Откройте диалог для проверки.');
            if (run.STATUS === 'error') {
                onEvent('error', {message: run.ERROR || 'Запрос завершился ошибкой на сервере.'});
                return;
            }
            if (run.STATUS === 'done' || run.STATUS === 'cancelled') {
                onEvent('done', state.result || {status: run.STATUS});
                if (!state.result && run.STATUS === 'done') onEvent('status', {text: 'Запрос завершён. Ответ доступен в истории диалога.'});
                return;
            }
            if (run.STATUS !== 'running') throw new Error('Сервер вернул неизвестное состояние запроса.');
            await new Promise(function (resolve, reject) {
                function abort() {
                    window.clearTimeout(timer);
                    signal.removeEventListener('abort', abort);
                    reject(new DOMException('Остановлено пользователем.', 'AbortError'));
                }
                var timer = window.setTimeout(function () {
                    if (signal) signal.removeEventListener('abort', abort);
                    resolve();
                }, 1500);
                if (signal) {
                    signal.addEventListener('abort', abort, {once: true});
                    if (signal.aborted) abort();
                }
            });
        }
        throw new Error('Не удалось дождаться результата сервера. Остановите запрос и проверьте журнал PHP.');
    }
    function progress(root) {
        var disclosure = el('details', 'md-disclosure md-ai-progress');
        var summary = el('summary', 'md-disclosure__summary md-ai-progress__summary');
        var current = el('span', 'md-ai-progress__label');
        current.setAttribute('role', 'status');
        current.setAttribute('aria-atomic', 'true');
        var chevron = el('i', 'ti ti-chevron-down md-disclosure__chevron');
        chevron.setAttribute('aria-hidden', 'true');
        summary.appendChild(current);
        summary.appendChild(chevron);
        disclosure.appendChild(summary);
        var body = el('div', 'md-disclosure__body');
        var list = el('ol', 'md-ai-progress__history');
        list.setAttribute('aria-label', 'Этапы работы помощника');
        body.appendChild(list);
        disclosure.appendChild(body);
        root.appendChild(disclosure);
        var running = null;
        function finishStep(failed) {
            if (running) {
                running.classList.remove('is-running');
                running.classList.add(failed ? 'is-error' : 'is-done');
                running = null;
            }
        }
        function finish(failed, text) {
            if (!running) return;
            finishStep(failed);
            summary.classList.remove('is-running');
            summary.classList.add(failed ? 'is-error' : 'is-done');
            current.textContent = text || (failed ? 'Работа прервана' : 'Ответ готов');
        }
        function add(text, detail) {
            finishStep(false);
            summary.classList.remove('is-error', 'is-done');
            summary.classList.add('is-running');
            current.textContent = text;
            running = el('li', 'md-ai-progress__step is-running');
            running.appendChild(el('span', 'md-ai-progress__label', text));
            if (detail) running.appendChild(el('small', '', detail));
            list.appendChild(running);
        }
        add('Готовлю ответ');
        return {event: function (name, data) {
            if (name === 'tool') {
                var tool = data.tool || {};
                add(labels[tool.name] || 'Использую инструмент', tool.arguments || '');
            } else if (name === 'tool_result') {
                add('Анализирую найденное');
            } else if (name === 'token' && running && ['Готовлю ответ', 'Анализирую найденное'].includes(running.textContent)) {
                running.firstChild.textContent = 'Пишу ответ';
                current.textContent = 'Пишу ответ';
            } else if (name === 'done' || name === 'error') {
                finish(name === 'error', data.status === 'approval' ? 'Требуется подтверждение'
                    : data.status === 'cancelled' ? 'Остановлено' : '');
            }
        }, finish: finish};
    }
    var pendingScrolls = new WeakSet();
    function scrollToBottom(container) {
        if (!container || pendingScrolls.has(container)) return;
        pendingScrolls.add(container);
        // Ждём перерасчёта высоты после очередной части ответа или готовой разметки.
        window.requestAnimationFrame(function () {
            pendingScrolls.delete(container);
            if (container.isConnected) container.scrollTop = container.scrollHeight;
        });
    }
    async function copy(text) {
        if (navigator.clipboard && window.isSecureContext) {
            try { await navigator.clipboard.writeText(text); return; } catch (error) { /* HTTP-панель использует локальный fallback. */ }
        }
        var area = el('textarea', 'md-ai-copy-buffer');
        area.value = text;
        document.body.appendChild(area);
        area.select();
        var copied = document.execCommand('copy');
        area.remove();
        if (!copied) throw new Error('Буфер обмена недоступен. Выделите и скопируйте текст вручную.');
    }
    function bindMessages(root) {
        root.querySelectorAll('.md-ai-msg').forEach(function (article) {
            if (article.querySelector('[data-ai-copy-message]')) return;
            var head = article.querySelector('.md-ai-msg__head');
            if (!head) return;
            var button = el('button', 'md-btn md-btn--ghost md-btn--sm', 'Копировать');
            button.type = 'button';
            button.setAttribute('data-ai-copy-message', '');
            button.addEventListener('click', async function () {
                try {
                    var source = article.querySelector('[data-ai-copy-source]');
                    await copy(source ? source.textContent : (article.querySelector('.md-ai-msg__content') || article).textContent);
                    button.textContent = 'Скопировано';
                    window.setTimeout(function () { button.textContent = 'Копировать'; }, 1800);
                } catch (error) { button.textContent = error.message; }
            });
            head.appendChild(button);
        });
        if (window.MDCodeSample) window.MDCodeSample.init(root);
    }
    function showActions(root, actions) {
        (actions || []).forEach(function (action) {
            var old = root.querySelector('[data-ai-action="' + Number(action.id) + '"]');
            if (old) old.remove();
            var parsed = el('div');
            parsed.innerHTML = action.html || '';
            var box = parsed.firstElementChild;
            if (!box) return;
            var state = box.querySelector('.md-ai-action__status');
            var bar = box.querySelector('.md-ai-action__buttons');
            function update(value) {
                action = value;
                state.textContent = value.result || ({pending: 'Требуется ваше подтверждение', applying: 'Создание выполняется…', rejected: 'Отменено', created: 'Создано', failed: 'Не удалось создать'}[value.status] || value.status);
                bar.hidden = value.status !== 'pending';
            }
            bar.querySelectorAll('[data-ai-action-decision]').forEach(function (button) {
                var decision = button.dataset.aiActionDecision;
                button.addEventListener('click', async function () {
                    bar.querySelectorAll('button').forEach(function (item) { item.disabled = true; });
                    try {
                        var result = await request('action_decide', {action_id: action.id, decision: decision});
                        update(result.action);
                    } catch (error) { state.textContent = errorText(error); }
                    finally { bar.querySelectorAll('button').forEach(function (item) { item.disabled = false; }); }
                });
            });
            root.appendChild(box);
            update(action);
        });
        if (window.MDCodeSample) window.MDCodeSample.init(root);
    }
    function showSources(root, sources) {
        var items = Array.isArray(sources) ? sources : Object.values(sources || {});
        if (!items.length) return;
        var list = el('div', 'md-ai-msg__chips');
        list.setAttribute('aria-label', 'Источники ответа');
        items.forEach(function (source) {
            if (!source || !source.url) return;
            var url;
            try { url = new URL(source.url, window.location.href); } catch (error) { return; }
            if (url.origin !== window.location.origin || !/^https?:$/.test(url.protocol)) return;
            var link = el('a', 'md-badge md-badge--outline', source.title || 'Источник');
            link.href = url.href; link.target = '_blank'; link.rel = 'noopener noreferrer';
            list.appendChild(link);
        });
        root.appendChild(list);
    }
    var pageScript = null;
    function loadPage() {
        if (window.MDAIPage) return Promise.resolve();
        if (!pageScript) pageScript = new Promise(function (resolve, reject) {
            var link = el('link'); link.rel = 'stylesheet'; link.href = (window.ROOTHTML || '/') + 'assets/modules/ai_assistant/ai_assistant.css?v=20261023-11';
            document.head.appendChild(link);
            var script = el('script'); script.src = (window.ROOTHTML || '/') + 'assets/modules/ai_assistant/ai_assistant.js?v=20261023-11';
            script.onload = resolve;
            script.onerror = function () { pageScript = null; script.remove(); reject(new Error('Не удалось загрузить чат. Повторите открытие.')); };
            document.head.appendChild(script);
        });
        return pageScript;
    }
    document.addEventListener('click', async function (event) {
        var button = event.target.closest('[data-ai-open]');
        if (!button || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        var body = el('div', 'md-ai-drawer', 'Загружаю диалог…');
        window.MDJAdminUI.openDrawer({owner: 'ai-chat', title: 'ИИ-помощник', width: 'min(640px, 100vw)', body: body,
            onClose: function () { body.dispatchEvent(new Event('ai:close', {bubbles: true})); }});
        try {
            var results = await Promise.all([request('drawer', {}), loadPage()]);
            if (!body.isConnected) return;
            body.innerHTML = results[0].html;
            window.MDAIPage.init(body);
            var prompt = body.querySelector('[data-ai-prompt]');
            if (prompt) prompt.focus();
        } catch (error) { body.textContent = errorText(error); }
    });
    function newRunId() {
        return Array.from(window.crypto.getRandomValues(new Uint8Array(16)), function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
    }
    window.MDAssistant = {request: request, consume: consume, progress: progress, copy: copy, newRunId: newRunId,
        bindMessages: bindMessages, showActions: showActions, showSources: showSources, errorText: errorText,
        scrollToBottom: scrollToBottom, recoverRun: recoverRun};
})(window, document);
