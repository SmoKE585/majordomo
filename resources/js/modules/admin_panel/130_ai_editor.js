/*
 * 2026-10-23 — ИИ-помощник в редакторе кода: один drawer, вставка одной
 * отменяемой операцией, проверка конфликта и отсутствие автосохранения формы.
 */
(function (window, document) {
    'use strict';

    var statePromise = null;
    var current = null;

    function endpoint() {
        var root = window.ROOTHTML || '/';
        return root.replace(/\/?$/, '/') + 'panel/ai_assistant.html?ajax=1';
    }

    function csrf() {
        var meta = document.querySelector('meta[name="md-csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    function toast(type, title, text) {
        if (window.MDJUIToast && typeof window.MDJUIToast.show === 'function') {
            window.MDJUIToast.show({type: type, title: title, text: text || '', duration: type === 'danger' ? 6500 : 4200});
        } else if (window.MDJAdminUI && typeof window.MDJAdminUI.showToast === 'function') {
            window.MDJAdminUI.showToast({type: type, message: (title ? title + ': ' : '') + (text || '')});
        }
    }

    function api(op, data) {
        var body = new URLSearchParams();
        body.set('op', op);
        Object.keys(data || {}).forEach(function (key) {
            if (data[key] !== undefined && data[key] !== null) body.set(key, data[key]);
        });
        return fetch(endpoint() + '&op=' + encodeURIComponent(op), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-Token': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: body.toString()
        }).then(function (response) {
            return response.text().then(function (text) {
                var json = null;
                try { json = JSON.parse(text); } catch (e) {}
                if (!response.ok || !json || json.ok === false) {
                    throw new Error(json && json.message ? json.message : 'Сервис ИИ-помощника недоступен.');
                }
                return json;
            });
        });
    }

    function state() {
        if (!statePromise) {
            statePromise = api('editor_state', {}).catch(function (error) {
                statePromise = null;
                throw error;
            });
        }
        return statePromise;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function buildPanel(editor, context, stateData) {
        var root = el('div', 'md-ai-editor');
        var intro = el('div', 'md-ai-editor__intro');
        intro.appendChild(el('strong', '', 'Что сделать с кодом?'));
        var chips = el('div', 'md-ai-editor__chips');
        var summary = context.title || context.method || context.key || 'Редактор';
        chips.appendChild(el('span', 'md-badge md-badge--outline', summary));
        if (context.language) chips.appendChild(el('span', 'md-badge md-badge--neutral', context.language.toUpperCase()));
        if (context.selection) chips.appendChild(el('span', 'md-badge md-badge--info', 'Выделено ' + context.selection.text.length + ' симв.'));
        intro.appendChild(chips);
        root.appendChild(intro);

        var quick = el('div', 'md-ai-editor__quick');
        var prompt = el('textarea', 'md-textarea md-ai-editor__prompt');
        prompt.rows = 4;
        prompt.placeholder = 'Например: объясни, почему метод не срабатывает, и предложи исправление.';
        var actions = [
            ['Объяснить код', 'Объясни, что делает этот код, какие объекты и свойства он затрагивает и какие есть ограничения.'],
            ['Найти ошибку', 'Найди ошибку или риск в этом коде. Объясни причину и предложи исправленный вариант.'],
            ['Дополнить', 'Дополни код, сохраняя существующее поведение. Сначала перечисли, что именно меняешь.'],
            ['Написать заново', 'Предложи более простую и надёжную реализацию этой задачи. Сохрани контракт метода.']
        ];
        actions.forEach(function (pair) {
            var button = el('button', 'md-btn md-btn--outline md-btn--sm', pair[0]);
            button.type = 'button';
            button.addEventListener('click', function () {
                prompt.value = pair[1];
                prompt.focus();
            });
            quick.appendChild(button);
        });
        root.appendChild(quick);
        root.appendChild(prompt);

        var agentRow = el('div', 'md-ai-editor__agent');
        var select = el('select', 'md-select md-select--sm');
        (stateData.agents || []).forEach(function (agent) {
            var option = document.createElement('option');
            option.value = String(agent.id);
            option.textContent = agent.title + (agent.profile === 'editor' ? ' · редактор' : '');
            if (Number(agent.id) === Number(stateData.default_agent)) option.selected = true;
            select.appendChild(option);
        });
        agentRow.appendChild(el('span', 'md-ai-editor__label', 'Агент'));
        agentRow.appendChild(select);
        root.appendChild(agentRow);

        var bar = el('div', 'md-ai-editor__bar');
        var status = el('span', 'md-ai-editor__status', 'Ответ появится здесь. Вставка не сохраняет форму.');
        var stop = el('button', 'md-btn md-btn--secondary md-btn--sm', 'Остановить');
        stop.type = 'button';
        stop.hidden = true;
        var send = el('button', 'md-btn md-btn--primary md-btn--sm', 'Спросить');
        send.type = 'button';
        bar.appendChild(status);
        bar.appendChild(stop);
        bar.appendChild(send);
        root.appendChild(bar);

        var answer = el('div', 'md-ai-editor__answer');
        root.appendChild(answer);

        return {
            root: root, prompt: prompt, select: select, status: status, send: send, stop: stop, answer: answer,
            dialogId: 0, runId: '', snapshot: editor.getValue(), controller: null, busy: false
        };
    }

    function bindPanel(panel, editor, context) {
        function setBusy(value, text) {
            panel.busy = value;
            panel.send.disabled = value;
            panel.stop.hidden = !value;
            panel.prompt.disabled = value;
            if (text) panel.status.textContent = text;
        }

        function renderAnswer(html) {
            panel.answer.innerHTML = html || '';
            panel.answer.querySelectorAll('pre').forEach(function (pre) {
                var code = pre.querySelector('code');
                var text = code ? code.textContent : pre.textContent;
                if (!text || !text.trim()) return;
                var tools = el('div', 'md-ai-editor__code-actions');
                var insert = el('button', 'md-btn md-btn--secondary md-btn--sm', 'Вставить в редактор');
                insert.type = 'button';
                insert.addEventListener('click', function () { applyCode(text, pre); });
                var copy = el('button', 'md-btn md-btn--ghost md-btn--sm', 'Копировать');
                copy.type = 'button';
                copy.addEventListener('click', function () {
                    if (navigator.clipboard) navigator.clipboard.writeText(text);
                    copy.textContent = 'Скопировано';
                });
                tools.appendChild(insert);
                tools.appendChild(copy);
                pre.parentNode.insertBefore(tools, pre);
            });
            if (!panel.answer.querySelector('pre')) {
                var wrap = el('div', 'md-ai-editor__code-actions');
                var insertAll = el('button', 'md-btn md-btn--secondary md-btn--sm', 'Вставить ответ в редактор');
                insertAll.type = 'button';
                insertAll.addEventListener('click', function () {
                    applyCode(panel.answer.textContent || '', null);
                });
                wrap.appendChild(insertAll);
                panel.answer.appendChild(wrap);
            }
        }

        function applyCode(text, node) {
            var currentText = editor.getValue();
            if (currentText !== panel.snapshot) {
                if (!window.confirm('Код в редакторе изменился после запроса. Вставить ответ поверх свежих правок?')) {
                    return;
                }
            }
            var selection = editor.view.state.selection.main;
            // Одна транзакция и отдельная группа истории: вставка отменяется целиком
            // и не склеивается с набором пользователя.
            editor.view.dispatch({
                changes: {from: selection.from, to: selection.to, insert: text},
                selection: {anchor: selection.from + text.length},
                scrollIntoView: true,
                userEvent: 'input.ai'
            });
            editor.focus();
            panel.snapshot = editor.getValue();
            panel.status.textContent = 'Вставлено в редактор. Форма не отправлена; сохраните код сами.';
            toast('success', 'Код вставлен', 'Проверьте изменения и сохраните метод или сценарий обычной кнопкой.');
            if (node) node.classList.add('is-inserted');
        }

        async function send() {
            if (panel.busy) return;
            var prompt = panel.prompt.value.trim();
            if (!prompt) {
                panel.prompt.focus();
                return;
            }
            panel.snapshot = editor.getValue();
            panel.answer.innerHTML = '';
            setBusy(true, 'Помощник готовит ответ…');
            panel.controller = new AbortController();
            try {
                if (!panel.dialogId) {
                    var created = await api('dialog_create', {
                        agent_id: panel.select.value,
                        context_type: 'editor',
                        title: context.title || context.method || 'Помощь с кодом',
                        context: JSON.stringify(context)
                    });
                    panel.dialogId = created.dialog.id;
                }
                var body = new URLSearchParams({
                    dialog_id: String(panel.dialogId),
                    agent_id: panel.select.value,
                    prompt: prompt,
                    context: JSON.stringify(context)
                });
                var response = await fetch(endpoint() + '&op=send', {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: panel.controller.signal,
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-CSRF-Token': csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'text/event-stream'
                    },
                    body: body.toString()
                });
                if (!response.ok) {
                    var text = await response.text();
                    var message = 'Не удалось получить ответ.';
                    try { message = JSON.parse(text).message || message; } catch (e) {}
                    throw new Error(message);
                }
                var reader = response.body.getReader();
                var decoder = new TextDecoder('utf-8');
                var buffer = '';
                var accumulated = '';
                while (true) {
                    var chunk = await reader.read();
                    if (chunk.done) break;
                    buffer += decoder.decode(chunk.value, {stream: true});
                    var index;
                    while ((index = buffer.indexOf('\n\n')) >= 0) {
                        var raw = buffer.slice(0, index);
                        buffer = buffer.slice(index + 2);
                        var event = parse(raw);
                        if (!event) continue;
                        if (event.name === 'run') {
                            panel.runId = event.data.run_id || '';
                            panel.status.textContent = 'Генерация ответа…';
                        } else if (event.name === 'token') {
                            accumulated += event.data.text || '';
                            panel.answer.textContent = accumulated;
                        } else if (event.name === 'tool') {
                            var tool = event.data.tool || {};
                            panel.status.textContent = 'Инструмент: ' + (tool.name || '') + '…';
                        } else if (event.name === 'done') {
                            renderAnswer(event.data.html || '');
                            panel.status.textContent = 'Проверьте ответ и вставьте код.';
                            setBusy(false);
                        } else if (event.name === 'error') {
                            var error = el('div', 'md-alert md-alert--danger', event.data.message || 'Ошибка ответа.');
                            panel.answer.appendChild(error);
                            setBusy(false);
                        }
                    }
                }
            } catch (error) {
                if (error.name !== 'AbortError') {
                    toast('danger', 'ИИ-помощник', error.message);
                    panel.status.textContent = error.message;
                }
            } finally {
                setBusy(false);
                panel.controller = null;
            }
        }

        function parse(raw) {
            var name = 'message';
            var data = '';
            raw.split('\n').forEach(function (line) {
                if (line.indexOf('event:') === 0) name = line.slice(6).trim();
                else if (line.indexOf('data:') === 0) data += line.slice(5).trim();
            });
            if (!data) return null;
            try { return {name: name, data: JSON.parse(data)}; } catch (e) { return null; }
        }

        panel.send.addEventListener('click', send);
        panel.stop.addEventListener('click', async function () {
            if (panel.runId) { try { await api('cancel', {run_id: panel.runId}); } catch (e) {} }
            if (panel.controller) panel.controller.abort();
            setBusy(false, 'Остановлено');
        });
        panel.prompt.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                event.preventDefault();
                send();
            }
        });
        panel.prompt.focus();
    }

    async function openFor(detail) {
        if (!window.MDJAdminUI || typeof window.MDJAdminUI.openDrawer !== 'function') {
            toast('danger', 'ИИ-помощник', 'Оболочка админки не загрузила drawer.');
            detail.handled = true;
            return;
        }
        detail.handled = true;
        var stateData;
        try {
            stateData = await state();
        } catch (error) {
            toast('danger', 'ИИ-помощник', error.message);
            return;
        }
        if (!stateData.enabled) {
            toast('warning', 'ИИ-помощник', stateData.message || 'Модуль выключен.');
            return;
        }
        var panel = buildPanel(detail.editor, detail.context, stateData);
        bindPanel(panel, detail.editor, detail.context);
        current = panel;
        var footer = el('div', 'md-ai-editor__footer');
        var close = el('button', 'md-btn md-btn--secondary md-btn--sm', 'Закрыть');
        close.type = 'button';
        close.addEventListener('click', function () { window.MDJAdminUI.closeDrawer(); });
        var submit = el('button', 'md-btn md-btn--primary md-btn--sm', 'Спросить');
        submit.type = 'button';
        submit.addEventListener('click', function () { panel.send.click(); });
        footer.appendChild(close);
        footer.appendChild(submit);
        window.MDJAdminUI.openDrawer({
            owner: 'ai-editor:' + (detail.context.key || detail.context.id || 'editor'),
            eyebrow: 'ИИ-помощник',
            title: detail.context.title || detail.context.method || 'Помощь с кодом',
            subtitle: 'Ответ вставляется одной отменяемой операцией; форма не сохраняется.',
            width: '560px',
            body: panel.root,
            footer: footer,
            focus: panel.prompt,
            onClose: function () { current = null; }
        });
    }

    document.addEventListener('md:code-editor-ai', function (event) {
        if (!event.detail || !event.detail.editor) return;
        openFor(event.detail);
    });
})(window, document);
