/*
 * 2026-10-23 — ИИ-помощник: чат с SSE, диагностика, сборка индекса.
 * Без jQuery; повторная инициализация после partLoad.
 */
(function (window, document) {
    'use strict';

    function csrf() {
        var meta = document.querySelector('meta[name="md-csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

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

    function endpoint() {
        var page = document.querySelector('[data-md-ai-panel]');
        return page ? page.getAttribute('data-ai-endpoint') || '' : '';
    }

    function post(op, data, signal) {
        var body = new URLSearchParams();
        body.set('op', op);
        Object.keys(data || {}).forEach(function (key) {
            if (data[key] !== undefined && data[key] !== null) body.set(key, data[key]);
        });
        return fetch(endpoint() + '&op=' + encodeURIComponent(op), {
            method: 'POST',
            credentials: 'same-origin',
            signal: signal,
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
                try { json = JSON.parse(text); } catch (e) { json = null; }
                if (!response.ok || !json || json.ok === false) {
                    throw new Error(json && json.message ? json.message : 'Сервер отклонил запрос.');
                }
                return json;
            });
        });
    }

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
        var messages = chat.querySelector('[data-ai-messages]');
        var composer = chat.querySelector('[data-ai-composer]');
        var prompt = chat.querySelector('[data-ai-prompt]');
        var send = chat.querySelector('[data-ai-send]');
        var stop = chat.querySelector('[data-ai-stop]');
        var status = chat.querySelector('[data-ai-status]');
        var agentSelect = chat.querySelector('[data-ai-agent]');
        var dialogId = parseInt(chat.getAttribute('data-dialog-id') || '0', 10);
        var runId = '';
        var controller = null;
        var busy = false;

        function setBusy(value, text) {
            busy = value;
            if (composer) composer.classList.toggle('is-busy', value);
            if (prompt) prompt.disabled = value;
            if (send) send.disabled = value;
            if (stop) { stop.hidden = !value; stop.classList.toggle('is-hidden', !value); }
            if (status && text) status.textContent = text;
        }

        function scrollDown() {
            if (messages) messages.scrollTop = messages.scrollHeight;
        }

        function agentId() {
            return agentSelect ? parseInt(agentSelect.value || '0', 10) : 0;
        }

        function ensureEmptyGone() {
            var empty = messages ? messages.querySelector('[data-ai-empty]') : null;
            if (empty) empty.remove();
        }

        function bubbleUser(text) {
            ensureEmptyGone();
            var article = el('article', 'md-ai-msg md-ai-msg--user');
            var avatar = el('span', 'md-ai-msg__avatar');
            avatar.innerHTML = '<i class="ti ti-user" aria-hidden="true"></i>';
            var body = el('div', 'md-ai-msg__body');
            var head = el('div', 'md-ai-msg__head');
            head.appendChild(el('strong', '', 'Вы'));
            var content = el('div', 'md-ai-msg__content');
            content.textContent = text;
            body.appendChild(head);
            body.appendChild(content);
            article.appendChild(avatar);
            article.appendChild(body);
            messages.appendChild(article);
        }

        function bubbleAssistant() {
            ensureEmptyGone();
            var article = el('article', 'md-ai-msg md-ai-msg--assistant');
            article.setAttribute('data-ai-stream', '1');
            var avatar = el('span', 'md-ai-msg__avatar');
            avatar.innerHTML = '<i class="ti ti-robot" aria-hidden="true"></i>';
            var body = el('div', 'md-ai-msg__body');
            var head = el('div', 'md-ai-msg__head');
            head.appendChild(el('strong', '', 'Помощник'));
            var content = el('div', 'md-ai-msg__content');
            content.setAttribute('data-ai-stream-content', '');
            body.appendChild(head);
            body.appendChild(content);
            article.appendChild(avatar);
            article.appendChild(body);
            messages.appendChild(article);
            return {article: article, body: body, content: content};
        }

        function setToolStatus(article, text) {
            if (!article) return;
            var body = article.querySelector('.md-ai-msg__body');
            var line = body.querySelector('[data-ai-tool-status]');
            if (!line) {
                line = el('div', 'md-ai-msg__usage');
                line.setAttribute('data-ai-tool-status', '');
                body.appendChild(line);
            }
            line.textContent = text;
        }

        function addSources(article, sources) {
            if (!sources || !sources.length) return;
            var body = article.querySelector('.md-ai-msg__body');
            if (body.querySelector('.md-ai-msg__sources')) return;
            var wrap = el('div', 'md-ai-msg__sources');
            var label = el('span', 'md-ai-msg__label');
            label.textContent = 'Источники';
            wrap.appendChild(label);
            var chips = el('div', 'md-ai-msg__chips');
            sources.forEach(function (source) {
                if (!source || (!source.title && !source.url)) return;
                var node = document.createElement(source.url ? 'a' : 'span');
                node.className = 'md-badge md-badge--outline';
                node.textContent = source.title || source.url;
                if (source.url) {
                    node.href = source.url;
                    node.target = '_blank';
                    node.rel = 'noopener noreferrer';
                }
                chips.appendChild(node);
            });
            wrap.appendChild(chips);
            body.appendChild(wrap);
        }

        async function sendPrompt(text) {
            if (busy) return;
            if (!dialogId) {
                await createDialog();
                return;
            }
            if (!agentId()) {
                toast('danger', 'Нет агента', 'Выберите включённого агента с моделью.');
                return;
            }
            bubbleUser(text);
            var assistant = bubbleAssistant();
            setBusy(true, 'Помощник ищет данные…');
            controller = new AbortController();
            runId = '';
            try {
                var response = await fetch(endpoint() + '&op=send', {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-CSRF-Token': csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'text/event-stream'
                    },
                    body: new URLSearchParams({
                        dialog_id: String(dialogId),
                        agent_id: String(agentId()),
                        prompt: text
                    }).toString()
                });
                if (!response.ok) {
                    var errorText = await response.text();
                    var message = 'Не удалось отправить запрос.';
                    try { message = JSON.parse(errorText).message || message; } catch (e) {}
                    throw new Error(message);
                }
                if (!response.body) throw new Error('Браузер не поддерживает потоковый ответ.');
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
                        var event = parseSse(raw);
                        if (!event) continue;
                        var data = event.data;
                        if (event.name === 'run') {
                            runId = data.run_id || '';
                            if (status) status.textContent = 'Генерация ответа…';
                        } else if (event.name === 'token') {
                            accumulated += data.text || '';
                            assistant.content.textContent = accumulated;
                            scrollDown();
                        } else if (event.name === 'tool') {
                            setToolStatus(assistant.article, 'Инструмент: ' + (data.tool && data.tool.name ? data.tool.name : '') + '…');
                            if (status) status.textContent = 'Помощник использует инструмент…';
                        } else if (event.name === 'tool_result') {
                            setToolStatus(assistant.article, 'Инструмент ' + (data.name || '') + ' завершён.');
                        } else if (event.name === 'done') {
                            if (data.html) assistant.content.innerHTML = data.html;
                            assSources(assistant.article, data.sources);
                            setToolStatus(assistant.article, '');
                            if (data.usage) {
                                var usage = el('div', 'md-ai-msg__usage');
                                usage.textContent = 'Токены: ' + (data.usage.input_tokens || 0) + ' вход / ' + (data.usage.output_tokens || 0) + ' выход';
                                assistant.body.appendChild(usage);
                            }
                            if (status) status.textContent = 'Enter — отправить, Shift+Enter — новая строка';
                            setBusy(false);
                        } else if (event.name === 'error') {
                            assistant.content.textContent = '';
                            var alert = el('div', 'md-alert md-alert--danger', data.message || 'Ошибка ответа.');
                            alert.setAttribute('role', 'alert');
                            assistant.body.appendChild(alert);
                            setBusy(false);
                        } else if (event.name === 'close') {
                            controller = null;
                        }
                    }
                }
            } catch (error) {
                if (error.name === 'AbortError') {
                    setToolStatus(assistant.article, 'Остановлено пользователем.');
                } else {
                    var box = el('div', 'md-alert md-alert--danger', error.message || 'Поток ответа прерван.');
                    box.setAttribute('role', 'alert');
                    assistant.body.appendChild(box);
                }
            } finally {
                setBusy(false);
                controller = null;
                if (status) status.textContent = 'Enter — отправить, Shift+Enter — новая строка';
            }
        }

        function assSources(article, sources) { addSources(article, sources); }

        async function stopGeneration() {
            if (runId) {
                try { await post('cancel', {run_id: runId}); } catch (e) {}
            }
            if (controller) controller.abort();
            setBusy(false, 'Остановлено');
        }

        async function createDialog() {
            var id = agentId();
            if (!id) {
                toast('danger', 'Нет агента', 'Сначала включите агента и назначьте модель.');
                return;
            }
            try {
                var result = await post('dialog_create', {agent_id: id, context_type: 'chat', title: 'Новый диалог'});
                var page = document.querySelector('[data-md-ai-panel]');
                var base = page ? page.getAttribute('data-ai-url') || '' : '';
                window.location.href = base + '/chat/' + result.dialog.id + '.html';
            } catch (error) {
                toast('danger', 'Не удалось создать диалог', error.message);
            }
        }

        function parseSse(raw) {
            var name = 'message';
            var data = '';
            raw.split('\n').forEach(function (line) {
                if (line.indexOf('event:') === 0) name = line.slice(6).trim();
                else if (line.indexOf('data:') === 0) data += line.slice(5).trim();
            });
            if (!data || data.charAt(0) === ':') return null;
            try { return {name: name, data: JSON.parse(data)}; } catch (e) { return null; }
        }

        if (composer) {
            composer.addEventListener('submit', function (event) {
                event.preventDefault();
                var text = prompt ? prompt.value.trim() : '';
                if (text === '') return;
                prompt.value = '';
                sendPrompt(text);
            });
            if (prompt) {
                prompt.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        composer.dispatchEvent(new Event('submit', {cancelable: true, bubbles: true}));
                    }
                });
            }
        }
        if (stop) stop.addEventListener('click', stopGeneration);

        var newDialog = page.querySelector('[data-ai-new-dialog]');
        if (newDialog) newDialog.addEventListener('click', createDialog);

        var deleteDialog = page.querySelector('[data-ai-delete-dialog]');
        if (deleteDialog) {
            deleteDialog.addEventListener('click', async function () {
                if (!window.confirm('Удалить диалог и его сообщения из списка?')) return;
                try {
                    await post('dialog_delete', {dialog_id: dialogId});
                    var panel = document.querySelector('[data-md-ai-panel]');
                    window.location.href = (panel ? panel.getAttribute('data-ai-url') : '') + '.html';
                } catch (error) {
                    toast('danger', 'Не удалось удалить диалог', error.message);
                }
            });
        }
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

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('ai_assistant', {init: init});
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }
})(window, document);
