/*
 * 2026-10-23 — ИИ-помощник в редакторе кода: один drawer, вставка одной
 * отменяемой операцией, проверка конфликта и отсутствие автосохранения формы.
 */
(function (window, document) {
    'use strict';

    function toast(type, title, text) {
        if (window.MDJUIToast && typeof window.MDJUIToast.show === 'function') {
            window.MDJUIToast.show({type: type, title: title, text: text || '', duration: type === 'danger' ? 6500 : 4200});
        } else if (window.MDJAdminUI && typeof window.MDJAdminUI.showToast === 'function') {
            window.MDJAdminUI.showToast({type: type, message: (title ? title + ': ' : '') + (text || '')});
        }
    }

    function api(op, data) { return window.MDAssistant.request(op, data); }

    function state() {
        return api('editor_state', {});
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
            dialogId: 0, runId: '', snapshot: editor.getValue(), controller: null, busy: false, closed: false
        };
    }

    function bindPanel(panel, editor, context) {
        function setBusy(value, text) {
            panel.busy = value;
            panel.send.disabled = value;
            panel.stop.hidden = !value;
            panel.prompt.disabled = value;
            panel.select.disabled = value;
            if (text) panel.status.textContent = text;
        }

        function bindCode(root) {
            root.querySelectorAll('pre').forEach(function (pre) {
                var code = pre.querySelector('code');
                var text = code ? code.textContent : pre.textContent;
                if (!text || !text.trim()) return;
                var insert = el('button', 'md-btn md-btn--secondary md-btn--sm', 'Вставить в редактор');
                insert.type = 'button';
                insert.addEventListener('click', function () { applyCode(text, pre); });
                var sample = pre.closest('[data-md-code-sample]');
                (sample ? sample.querySelector('.md-code-sample__actions') || sample : pre.parentNode).appendChild(insert);
            });
            if (window.MDCodeSample) window.MDCodeSample.init(root);
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
            if (panel.busy || !panel.prompt.value.trim()) return;
            var ai = window.MDAssistant;
            var prompt = panel.prompt.value.trim();
            panel.snapshot = editor.getValue();
            var selection = editor.view.state.selection.main;
            var freshContext = Object.assign({}, context, {code: panel.snapshot,
                selection: selection.empty ? '' : editor.view.state.sliceDoc(selection.from, selection.to)});
            panel.answer.innerHTML = '';
            var question = el('div', 'md-ai-editor__question', prompt);
            panel.answer.appendChild(question);
            var steps = el('div'); panel.answer.appendChild(steps);
            var timeline = ai.progress(steps);
            var content = el('div', 'md-prose'); panel.answer.appendChild(content);
            var actions = el('div', 'md-ai-actions'); panel.answer.appendChild(actions);
            var terminal = false, failed = false, accumulated = '';
            setBusy(true, 'Помощник готовит ответ…');
            panel.runId = ai.newRunId();
            panel.controller = new AbortController();
            try {
                if (!panel.dialogId) {
                    var created = await api('dialog_create', {agent_id: panel.select.value, context_type: 'editor',
                        title: context.title || context.method || 'Помощь с кодом', context: JSON.stringify(freshContext)});
                    panel.dialogId = created.dialog.id;
                }
                if (panel.closed) return;
                var response = await ai.request('send', {dialog_id: panel.dialogId, agent_id: panel.select.value,
                    prompt: prompt, context: JSON.stringify(freshContext), request_id: panel.runId}, panel.controller.signal, true);
                await ai.consume(response, function (name, data) {
                    timeline.event(name, data);
                    if (name === 'run') panel.runId = data.run_id || '';
                    else if (name === 'token') { accumulated += data.text || ''; content.textContent = accumulated; }
                    else if (name === 'status' && data.text) panel.status.textContent = data.text;
                    else if (name === 'done') {
                        terminal = true;
                        // Сохраняем этапы и вопрос при замене текста готовой разметкой.
                        content.innerHTML = data.html || '';
                        bindCode(content);
                        ai.showActions(actions, data.actions);
                        ai.showSources(content, data.sources);
                        panel.status.textContent = data.status === 'approval' ? 'Подтвердите действие ниже.' : 'Проверьте ответ и вставьте код.';
                    } else if (name === 'error') {
                        terminal = true; failed = true;
                        var alert = el('div', 'md-alert md-alert--danger', data.message || 'Ошибка ответа.');
                        alert.setAttribute('role', 'alert'); panel.answer.appendChild(alert);
                        panel.status.textContent = 'Запрос завершился ошибкой. Можно повторить.';
                    }
                });
            } catch (error) {
                failed = error.name !== 'AbortError';
                var message = error.name === 'AbortError' ? 'Остановлено пользователем.' : ai.errorText(error);
                if (failed) {
                    var alert = el('div', 'md-alert md-alert--danger', message);
                    alert.setAttribute('role', 'alert'); panel.answer.appendChild(alert);
                }
                panel.status.textContent = message;
                if (panel.dialogId && !terminal) {
                    try { await api('cancel', {run_id: panel.runId, dialog_id: panel.dialogId}); }
                    catch (cancelError) { panel.status.textContent += ' Не удалось подтвердить остановку. Откройте диалог для проверки.'; }
                }
            } finally {
                timeline.finish(failed);
                setBusy(false);
                panel.controller = null;
                if (panel.dialogId && !terminal && !panel.closed) {
                    try {
                        var state = await api('run_status', {dialog_id: panel.dialogId});
                        if (state.run && state.run.STATUS === 'running') {
                            panel.runId = state.run.ID;
                            setBusy(true, 'Запрос ещё выполняется. Нажмите «Остановить».');
                        }
                    } catch (error) { panel.status.textContent = 'Не удалось проверить состояние. Откройте диалог после восстановления связи.'; }
                }
            }
        }

        panel.send.addEventListener('click', send);
        panel.stop.addEventListener('click', async function () {
            if (panel.dialogId) {
                try { await api('cancel', {run_id: panel.runId, dialog_id: panel.dialogId}); }
                catch (error) { panel.status.textContent = window.MDAssistant.errorText(error); return; }
            }
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
        window.MDJAdminUI.openDrawer({
            owner: 'ai-editor:' + (detail.context.key || detail.context.id || 'editor'),
            eyebrow: 'ИИ-помощник',
            title: detail.context.title || detail.context.method || 'Помощь с кодом',
            subtitle: 'Ответ вставляется одной отменяемой операцией; форма не сохраняется.',
            width: '560px',
            body: panel.root,

            focus: panel.prompt,
            onClose: function () {
                panel.closed = true;
                if (panel.controller) {
                    panel.controller.abort();
                    api('cancel', {run_id: panel.runId, dialog_id: panel.dialogId}).catch(function (error) { toast('danger', 'Не удалось подтвердить остановку', window.MDAssistant.errorText(error)); });
                }
            }
        });
    }

    document.addEventListener('md:code-editor-ai', function (event) {
        if (!event.detail || !event.detail.editor) return;
        openFor(event.detail);
    });
})(window, document);
