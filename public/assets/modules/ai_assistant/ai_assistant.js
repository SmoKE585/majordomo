/*
 * 2026-10-24 — ИИ-помощник: чат с SSE, диагностика, сборка индекса и голосовой ввод.
 * Без jQuery; повторная инициализация после partLoad.
 */
(function (window, document) {
    'use strict';
    if (window.MDAIPage) { window.MDAIPage.init(document); return; }

    function toast(type, title, text) {
        if (window.MDJUIToast && typeof window.MDJUIToast.show === 'function') {
            window.MDJUIToast.show({type: type, title: title, text: text || '', duration: type === 'danger' ? 7000 : 4200});
            return;
        }
        var page = document.querySelector('[data-md-ai-panel]');
        if (page) {
            var box = page.querySelector('[data-ai-toast]');
            if (!box) {
                box = document.createElement('div');
                box.setAttribute('data-ai-toast', '');
                page.insertBefore(box, page.firstChild);
            }
            box.textContent = (title ? title + ': ' : '') + (text || '');
            box.className = 'md-alert md-alert--' + (type === 'danger' ? 'danger' : 'success');
            window.setTimeout(function () { box.remove(); }, 6000);
        }
    }

    function post(op, data, signal) { return window.MDAssistant.request(op, data, signal); }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function initChat(page) {
        var chat = page.querySelector('[data-ai-chat]');
        if (!chat || chat.dataset.aiReady === '1') return;
        chat.dataset.aiReady = '1';
        var ai = window.MDAssistant;
        var messages = chat.querySelector('[data-ai-messages]');
        var composer = chat.querySelector('[data-ai-composer]');
        var prompt = chat.querySelector('[data-ai-prompt]');
        var send = chat.querySelector('[data-ai-send]');
        var stop = chat.querySelector('[data-ai-stop]');
        var voiceButton = chat.querySelector('[data-ai-voice]');
        var status = chat.querySelector('[data-ai-status]');
        var select = chat.querySelector('[data-ai-agent]');
        var dialogId = Number(chat.dataset.dialogId || 0);
        var runId = '', controller = null, busy = false, closed = false;
        var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        var voiceSession = null;
        var actions = chat.querySelector('[data-ai-actions]');
        var recovered = chat.querySelector('[data-ai-recovery]');
        var initialActions = JSON.parse(chat.dataset.actions || '[]');
        ai.showActions(actions, initialActions);
        ai.bindMessages(messages);
        function scrollDown() { ai.scrollToBottom(messages); }
        function setBusy(value, text) {
            busy = value;
            prompt.disabled = value || !select.value;
            send.disabled = value || !select.value;
            if (voiceButton) voiceButton.disabled = value || !select.value;
            select.disabled = value;
            stop.hidden = !value;
            stop.classList.toggle('is-hidden', !value);
            if (text) status.textContent = text;
            messages.setAttribute('aria-busy', value ? 'true' : 'false');
            page.querySelectorAll('[data-ai-new-dialog]').forEach(function (button) { button.disabled = value; });
        }
        function appendVoiceText(base, text) {
            if (!text) return base;
            return base !== '' && !/\s$/.test(base) ? base + ' ' + text : base + text;
        }
        function setVoiceState(active) {
            if (!voiceButton) return;
            var label = active ? 'Остановить голосовой ввод' : 'Начать голосовой ввод';
            voiceButton.classList.toggle('is-recording', active);
            voiceButton.setAttribute('aria-pressed', active ? 'true' : 'false');
            voiceButton.setAttribute('aria-label', label);
            voiceButton.setAttribute('data-bs-title', label);
        }
        function finishVoice(session, message) {
            if (!session || voiceSession !== session) return;
            if (prompt.value === session.rendered) {
                var completed = appendVoiceText(session.base, session.finalText);
                if (prompt.value !== completed) {
                    prompt.value = completed;
                    prompt.dispatchEvent(new window.Event('input', {bubbles: true}));
                }
            }
            voiceSession = null;
            setVoiceState(false);
            if (!busy && select.value) status.textContent = message || 'Голосовой ввод завершён. Проверьте текст и отправьте.';
        }
        function stopVoice(abort) {
            var session = voiceSession;
            if (!session) return;
            try {
                if (abort) session.recognition.abort();
                else session.recognition.stop();
            } catch (error) {
                finishVoice(session);
            }
        }
        function startVoice() {
            if (!SpeechRecognition || busy || !select.value || voiceSession) return;
            var recognition = new SpeechRecognition();
            var session = {recognition: recognition, base: prompt.value, finalText: '', rendered: prompt.value};
            voiceSession = session;
            recognition.lang = document.documentElement.lang || 'ru-RU';
            recognition.continuous = false;
            recognition.interimResults = true;
            recognition.maxAlternatives = 1;
            recognition.onresult = function (event) {
                var finalText = '', interimText = '';
                if (prompt.value !== session.rendered) {
                    session.base = prompt.value;
                    session.finalText = '';
                }
                for (var index = event.resultIndex; index < event.results.length; index++) {
                    var transcript = event.results[index][0].transcript;
                    if (event.results[index].isFinal) finalText += transcript;
                    else interimText += transcript;
                }
                session.finalText += finalText;
                session.rendered = appendVoiceText(session.base, session.finalText + interimText);
                prompt.value = session.rendered;
                prompt.dispatchEvent(new window.Event('input', {bubbles: true}));
            };
            recognition.onerror = function (event) {
                var messages = {
                    'not-allowed': 'Браузер не дал доступ к микрофону.',
                    'service-not-allowed': 'Браузер не дал доступ к микрофону.',
                    'no-speech': 'Речь не распознана. Попробуйте ещё раз.'
                };
                session.errorMessage = messages[event.error] || 'Не удалось распознать речь. Попробуйте ещё раз.';
            };
            recognition.onend = function () { finishVoice(session, session.errorMessage); };
            setVoiceState(true);
            status.textContent = 'Слушаю… Говорите, затем нажмите кнопку микрофона для остановки.';
            try { recognition.start(); }
            catch (error) { finishVoice(session, 'Не удалось начать голосовой ввод. Попробуйте ещё раз.'); }
        }
        function failure(text) {
            recovered.hidden = false;
            recovered.setAttribute('role', 'alert');
            recovered.textContent = text;
        }
        async function refresh(id) {
            var result = await ai.request('messages', {dialog_id: id});
            if (closed) return;
            dialogId = Number(result.dialog.id);
            chat.dataset.dialogId = String(dialogId);
            messages.innerHTML = result.messages.map(function (row) { return row.HTML; }).join('');
            messages.appendChild(actions);
            var title = chat.querySelector('[data-ai-dialog-title]');
            if (title) title.textContent = result.dialog.title;
            select.value = String(result.dialog.agent_id || select.value);
            actions.innerHTML = '';
            ai.showActions(actions, result.actions);
            ai.bindMessages(messages);
            recovered.hidden = true;
            var run = result.run;
            if (run && run.STATUS === 'running') {
                runId = run.ID;
                setBusy(true, 'Есть активный запрос. Можно остановить его и отправить новый.');
            } else {
                setBusy(false, 'Enter — отправить, Shift+Enter — новая строка');
                if (run && run.STATUS === 'error') failure(run.ERROR || 'Предыдущий запрос прерван. Можно повторить.');
            }
            scrollDown();
        }
        function bubble(role, text) {
            var empty = messages.querySelector('[data-ai-empty]');
            if (empty) empty.remove();
            var article = el('article', 'md-ai-msg md-ai-msg--' + role);
            var avatar = el('span', 'md-ai-msg__avatar');
            avatar.innerHTML = '<i class="ti ti-' + (role === 'user' ? 'user' : 'robot') + '" aria-hidden="true"></i>';
            var body = el('div', 'md-ai-msg__body');
            var head = el('div', 'md-ai-msg__head');
            head.appendChild(el('strong', '', role === 'user' ? 'Вы' : 'Помощник'));
            var content = el('div', 'md-ai-msg__content md-prose', text || '');
            body.appendChild(head); body.appendChild(content);
            article.appendChild(avatar); article.appendChild(body); messages.insertBefore(article, actions);
            ai.bindMessages(messages);
            return {article: article, body: body, content: content};
        }
        async function createDialog() {
            if (busy || !select.value) return false;
            setBusy(true, 'Создаю новый чат…');
            stop.hidden = true;
            var result;
            try { result = await ai.request('dialog_create', {agent_id: select.value, context_type: 'chat', title: 'Новый диалог'}); }
            finally { setBusy(false, 'Enter — отправить, Shift+Enter — новая строка'); }
            dialogId = Number(result.dialog.id);
            messages.innerHTML = ''; actions.innerHTML = ''; messages.appendChild(actions); recovered.hidden = true;
            prompt.value = '';
            chat.querySelector('[data-ai-dialog-title]').textContent = 'Новый диалог';
            var list = chat.querySelector('[data-ai-dialogs]');
            if (list) {
                var link = el('a', 'md-ai-dialogs__item', 'Новый диалог');
                link.href = page.dataset.aiUrl + '/chat/' + dialogId + '.html';
                link.dataset.dialogId = String(dialogId); list.prepend(link);
            }
            return true;
        }
        async function sendPrompt(text) {
            if (busy || !select.value) return;
            var assistant, timeline, accumulated = '', terminal = false, failed = false;
            function receive(name, data) {
                    timeline.event(name, data);
                    if (name === 'run') runId = data.run_id || '';
                    if (name === 'status' && data.text) status.textContent = data.text;
                    if (name === 'token') {
                        accumulated += data.text || ''; assistant.content.textContent = accumulated;
                    } else if (name === 'done') {
                        terminal = true;
                        if (typeof data.content === 'string') accumulated = data.content;
                        if (data.html) assistant.content.innerHTML = data.html;
                        var source = el('span', '', accumulated || assistant.content.textContent);
                        source.hidden = true;
                        source.dataset.aiCopySource = ''; assistant.body.appendChild(source);
                        ai.showActions(actions, data.actions);
                        ai.showSources(assistant.body, data.sources);
                        ai.bindMessages(messages);
                        status.textContent = data.status === 'approval' ? 'Ожидается подтверждение действия'
                            : data.status === 'cancelled' ? 'Остановлено. Можно отправить новый запрос.' : 'Ответ получен';
                    } else if (name === 'error') {
                        terminal = true; failed = true;
                        var alert = el('div', 'md-alert md-alert--danger', data.message || 'Ошибка ответа.');
                        alert.setAttribute('role', 'alert'); assistant.body.appendChild(alert);
                        status.textContent = 'Запрос завершился ошибкой. Можно повторить.';
                    }
                    if (['tool', 'tool_result', 'token', 'done', 'error'].includes(name)) scrollDown();
            }
            try {
                if (!dialogId && !await createDialog()) return;
                if (closed) return;
                bubble('user', text);
                assistant = bubble('assistant', '');
                timeline = ai.progress(assistant.body);
                prompt.value = ''; recovered.hidden = true;
                setBusy(true, 'Помощник готовит ответ…');
                scrollDown();
                controller = new AbortController(); runId = ai.newRunId();
                var response = await ai.request('send', {dialog_id: dialogId, agent_id: select.value, prompt: text, request_id: runId}, controller.signal, true);
                await ai.consume(response, receive);
            } catch (error) {
                if (error.name !== 'AbortError' && !terminal && controller && assistant && !closed) {
                    try { await ai.recoverRun(dialogId, runId, controller.signal, receive); return; }
                    catch (recoveryError) { error = recoveryError; }
                }
                failed = error.name !== 'AbortError';
                if (timeline) timeline.finish(failed, !terminal && !failed ? 'Остановлено' : '');
                if (!failed) status.textContent = 'Остановлено. Можно отправить новый запрос.';
                if (error.name !== 'AbortError') {
                    failure(ai.errorText(error));
                    if (!prompt.value) prompt.value = text;
                }
                // Отмена адресуется владельцу диалога даже если событие run не дошло.
                if (dialogId && !terminal) {
                    try { await ai.request('cancel', {run_id: runId, dialog_id: dialogId}); }
                    catch (cancelError) { failure(ai.errorText(error) + ' Не удалось подтвердить остановку. Кнопка «Остановить» останется доступна.'); }
                }
            } finally {
                if (timeline) timeline.finish(failed, !terminal && !failed ? 'Остановлено' : '');
                controller = null;
                setBusy(false, failed ? 'Проверьте сообщение об ошибке выше.' : status.textContent);
                if (dialogId && !terminal && !closed) {
                    try {
                        var state = await ai.request('run_status', {dialog_id: dialogId});
                        if (state.run && state.run.STATUS === 'running') { runId = state.run.ID; setBusy(true, 'Запрос ещё выполняется. Можно остановить.'); }
                    } catch (error) { failure('Не удалось проверить состояние запроса. Восстановите соединение и откройте диалог снова.'); }
                }
                scrollDown();
            }
        }
        async function stopGeneration() {
            if (!dialogId) return;
            try {
                await ai.request('cancel', {run_id: runId, dialog_id: dialogId});
                if (controller) controller.abort();
                else await refresh(dialogId);
                setBusy(false, 'Остановлено. Можно отправить новый запрос.');
            } catch (error) { failure(ai.errorText(error)); }
        }
        composer.addEventListener('submit', function (event) {
            event.preventDefault();
            if (prompt.value.trim()) sendPrompt(prompt.value.trim());
        });
        prompt.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault(); composer.requestSubmit();
            }
        });
        select.addEventListener('change', function () { setBusy(busy); });
        stop.addEventListener('click', stopGeneration);
        if (voiceButton) {
            voiceButton.hidden = typeof SpeechRecognition !== 'function';
            voiceButton.addEventListener('click', function () {
                if (voiceSession) stopVoice(false);
                else startVoice();
            });
        }
        page.addEventListener('click', async function (event) {
            var link = event.target.closest('[data-dialog-id]');
            if (link && link !== chat) {
                event.preventDefault();
                if (busy) return;
                try { await refresh(Number(link.dataset.dialogId)); } catch (error) { failure(ai.errorText(error)); }
            }
            if (event.target.closest('[data-ai-new-dialog]')) {
                event.preventDefault();
                try { await createDialog(); prompt.focus(); } catch (error) { failure(ai.errorText(error)); }
            }
            if (event.target.closest('[data-ai-delete-dialog]') && !busy && dialogId) {
                if (!window.confirm('Удалить диалог и его сообщения?')) return;
                try {
                    await ai.request('dialog_delete', {dialog_id: dialogId});
                    page.querySelectorAll('a[data-dialog-id="' + dialogId + '"]').forEach(function (node) { node.remove(); });
                    dialogId = 0; messages.innerHTML = ''; actions.innerHTML = ''; messages.appendChild(actions);
                } catch (error) { failure(ai.errorText(error)); }
            }
        });
        var drawer = page.closest('.md-ai-drawer');
        if (drawer) drawer.addEventListener('ai:close', function () {
            closed = true;
            stopVoice(true);
            if (controller) { controller.abort(); ai.request('cancel', {run_id: runId, dialog_id: dialogId}).catch(function () {}); }
        }, {once: true});
        if (dialogId) {
            setBusy(true, 'Проверяю состояние диалога…');
            refresh(dialogId).catch(function (error) { setBusy(false); failure(ai.errorText(error)); });
        }
        else setBusy(false);
        scrollDown();
    }

    function initProbes(page) {
        if (page.dataset.aiProbesReady === '1') return;
        page.dataset.aiProbesReady = '1';
        page.addEventListener('click', async function (event) {
            var button = event.target.closest('[data-ai-probe], [data-ai-rag-probe]');
            if (!button) return;
            event.preventDefault();
            var box = page.querySelector('[data-ai-probe-result]');
            if (box) {
                box.hidden = false;
                box.className = 'md-ai-probe-result';
                box.textContent = 'Проверка…';
            }
            try {
                var result;
                if (button.hasAttribute('data-ai-rag-probe')) {
                    result = await post('rag_status', {});
                    result = result.status;
                } else {
                    result = await post('probe', {
                        model_id: button.getAttribute('data-model-id') || '0',
                        capability: button.getAttribute('data-capability') || 'chat'
                    });
                    result = result.result;
                }
                var text = (result.message || result.hint || 'Готово.') + (result.dimensions ? ' Размерность: ' + result.dimensions : '');
                if (box) {
                    box.className = 'md-ai-probe-result ' + (result.ok || result.available ? 'is-ok' : 'is-error');
                    box.textContent = text;
                } else {
                    toast(result.ok || result.available ? 'success' : 'danger', 'Проверка', text);
                }
            } catch (error) {
                if (box) {
                    box.className = 'md-ai-probe-result is-error';
                    box.textContent = error.message;
                } else {
                    toast('danger', 'Проверка не удалась', error.message);
                }
            }
        });
        page.addEventListener('click', async function (event) {
            var presetButton = event.target.closest('[data-ai-vector-preset]');
            if (presetButton) {
                event.preventDefault();
                var form = presetButton.closest('form');
                if (form && form.elements) {
                    if (form.elements.vector_name) form.elements.vector_name.value = '';
                    if (form.elements.vector_size) form.elements.vector_size.value = '3072';
                    if (form.elements.distance) form.elements.distance.value = 'Cosine';
                    if (form.elements.hnsw_m) form.elements.hnsw_m.value = '16';
                    if (form.elements.hnsw_ef_construct) form.elements.hnsw_ef_construct.value = '100';
                    toast('info', 'Параметры OpenAI Large подставлены', 'Проверьте остальные поля и нажмите «Сохранить всё».');
                }
            }
            var startButton = event.target.closest('[data-ai-index-start]');
            if (startButton) {
                event.preventDefault();
                await buildIndex(parseInt(startButton.getAttribute('data-ai-index-start'), 10));
            }
            var cancelButton = event.target.closest('[data-ai-index-cancel]');
            if (cancelButton) {
                event.preventDefault();
                try {
                    await post('index_cancel', {model_id: cancelButton.getAttribute('data-ai-index-cancel')});
                    toast('success', 'Сборка остановлена', 'Рабочий индекс не изменён.');
                    window.setTimeout(function () { window.location.reload(); }, 600);
                } catch (error) {
                    toast('danger', 'Не удалось остановить сборку', error.message);
                }
            }
        });
    }

    async function buildIndex(modelId) {
        if (!modelId) return;
        var list = document.querySelector('[data-ai-index-list]');
        var card = list ? list.querySelector('[data-ai-index="' + modelId + '"]') : null;
        if (!card && list) {
            card = el('article', 'md-ai-index');
            card.setAttribute('data-ai-index', '');
            card.setAttribute('data-model-id', String(modelId));
            card.innerHTML = '<div class="md-ai-index__head"><strong>Сборка индекса…</strong></div>' +
                '<div class="md-ai-index__stats"><span data-ai-index-progress-text>Подготовка…</span></div>' +
                '<div class="md-progress md-progress--primary md-progress--sm" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-ai-index-progress><span class="md-progress__bar" style="width:0%"></span></div>';
            list.appendChild(card);
        }
        var text = card ? card.querySelector('[data-ai-index-progress-text]') : null;
        var bar = card ? card.querySelector('[data-ai-index-progress]') : null;
        var fill = bar ? bar.querySelector('.md-progress__bar') : null;
        function update(row) {
            if (fill) fill.style.width = (row.percent || 0) + '%';
            if (bar) bar.setAttribute('aria-valuenow', String(row.percent || 0));
            if (text) text.textContent = 'Обработано ' + (row.processed || 0) + ' из ' + (row.chunks || 0) + ' фрагментов · ' + (row.status || '');
        }
        try {
            var started = await post('index_start', {model_id: modelId});
            update(started.index);
            var row = started.index;
            var guard = 0;
            while (row.status === 'building' && guard < 5000) {
                guard++;
                var step = await post('index_step', {model_id: modelId, batch: 8});
                row = step.index;
                update(row);
                if (row.status !== 'building') break;
            }
            if (row.status === 'ready') {
                toast('success', 'Индекс готов', 'Рабочий индекс переключён на новую коллекцию.');
            } else if (row.status === 'error') {
                toast('danger', 'Сборка не завершена', row.error || '');
            }
            window.setTimeout(function () { window.location.reload(); }, 900);
        } catch (error) {
            if (text) text.textContent = error.message;
            toast('danger', 'Сборка индекса прервана', error.message);
        }
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var pages = [];
        if (scope.matches && scope.matches('[data-md-ai-panel]')) pages.push(scope);
        scope.querySelectorAll('[data-md-ai-panel]').forEach(function (page) { pages.push(page); });
        pages.forEach(function (page) {
            if (page.dataset.aiInitialized === '1') return;
            page.dataset.aiInitialized = '1';
            initChat(page);
            initProbes(page);
        });
    }

    window.MDAIPage = {init: init};
    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('ai_assistant', {init: init});
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }
})(window, document);
