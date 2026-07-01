(function () {
	'use strict';

	var config = window.XRayConfig || {};
	var state = {
		checkTimer: 0,
		checkTimeout: Number(config.checkTimeout || 5000),
		playing: false,
		cycleLogTimer: 0,
		cycleLogCurrent: '',
		filesTomSelect: null
	};

	function el(id) {
		return document.getElementById(id);
	}

	function escapeHtml(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function formatModeLabel(mode) {
		var labels = {
			'': 'Логи',
			'logs': 'Логи',
			'properties': 'Свойства',
			'methods': 'Методы',
			'scripts': 'Скрипты',
			'performance': 'Производительность',
			'timers': 'Таймеры',
			'dead': 'Неактивные',
			'events': 'События',
			'database': 'База',
			'dbload': 'Нагрузка БД',
			'services': 'Сервисы'
		};
		return labels[mode] || mode || 'Логи';
	}

	function setText(node, text) {
		if (node) {
			node.textContent = text;
		}
	}

	function setHtml(node, html) {
		if (node) {
			node.innerHTML = html;
		}
	}

	function getSelectedFiles() {
		var select = el('files');
		if (!select) {
			return [];
		}
		if (state.filesTomSelect) {
			return state.filesTomSelect.items.slice();
		}
		return Array.prototype.filter.call(select.options, function (option) {
			return option.selected;
		}).map(function (option) {
			return option.value;
		});
	}

	function updateSelectedFilesBadge() {
		var count = getSelectedFiles().length;
		setText(el('xraySelectedFilesBadge'), count);
		setText(el('openLogDrawerCount'), count);
	}

	function createTag(text, klass) {
		return '<span class="xray-tag ' + escapeHtml(klass || '') + '">' + escapeHtml(text) + '</span>';
	}

	function createButton(href, label, klass) {
		return '<a href="' + escapeHtml(href) + '" class="btn btn-sm ' + escapeHtml(klass || 'btn-default') + '">' + escapeHtml(label) + '</a>';
	}

	function createActionButton(type, label, klass, attrs) {
		return '<button type="button" class="btn btn-sm ' + escapeHtml(klass || 'btn-default') + '" ' + (attrs || '') + '>' + escapeHtml(label) + '</button>';
	}

	function createEmptyState(title, text) {
		return '<div class="xray-empty-state"><div><div class="xray-spinner"></div><strong>' + escapeHtml(title) + '</strong><div>' + escapeHtml(text) + '</div></div></div>';
	}

	function formatNumber(value) {
		var number = Number(value || 0);
		return number.toLocaleString ? number.toLocaleString('ru-RU') : String(number);
	}

	function formatDbConnectionsText(value) {
		return String(value || '—')
			.replace(/cached:/g, 'в кэше:')
			.replace(/connected:/g, 'подключено:')
			.replace(/created:/g, 'создано:')
			.replace(/running:/g, 'выполняется:')
			.replace(/max:/g, 'лимит:');
	}

	function createTable(head, body) {
		return '<div class="table-responsive xray-table-wrap"><table class="table table-striped table-hover xray-table">' + head + '<tbody>' + body + '</tbody></table></div>';
	}

	function createRows(items) {
		var html = '';
		for (var i = 0; i < items.length; i++) {
			if (!items[i] || items[i].value === '' || items[i].value === null || items[i].value === undefined) {
				continue;
			}
			html += '<div class="xray-data-card__row"><span>' + escapeHtml(items[i].label) + '</span><strong>' + items[i].value + '</strong></div>';
		}
		return html;
	}

	function createCard(options) {
		var title = options.title || '';
		var titleHtml = options.href ? '<a href="' + escapeHtml(options.href) + '">' + escapeHtml(title) + '</a>' : escapeHtml(title);
		var subtitleHtml = options.subtitle ? '<div class="xray-data-card__subtitle">' + escapeHtml(options.subtitle) + '</div>' : '';
		var tagsHtml = options.tags && options.tags.length ? '<div class="xray-data-card__tags">' + options.tags.join('') + '</div>' : '';
		var rowsHtml = options.rows && options.rows.length ? '<div class="xray-data-card__rows">' + createRows(options.rows) + '</div>' : '';
		var actionsHtml = options.actions && options.actions.length ? '<div class="xray-data-card__actions">' + options.actions.join('') + '</div>' : '';
		return '<article class="xray-data-card ' + escapeHtml(options.klass || '') + '">' +
			'<div class="xray-data-card__head"><div class="xray-data-card__title">' + titleHtml + '</div>' + tagsHtml + '</div>' +
			subtitleHtml +
			rowsHtml +
			actionsHtml +
		'</article>';
	}

	function createCardList(cards, extraClass) {
		if (!cards.length) {
			return createEmptyState(config.noDataTitle || 'Нет данных', config.noDataText || 'Для выбранного режима ничего не найдено.');
		}
		return '<div class="xray-data-grid ' + escapeHtml(extraClass || '') + '">' + cards.join('') + '</div>';
	}

	function renderProperties(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			rows += '<tr>' +
				'<td>' + escapeHtml(list[i].NAME || '') + '<div class="xray-muted">' + escapeHtml(list[i].DESC || '') + '</div></td>' +
				'<td>' + escapeHtml(list[i].VALUE || '') + '</td>' +
				'<td>' + escapeHtml(list[i].UPDATE || '') + '</td>' +
				'<td>' + escapeHtml(list[i].SOURCE || '') + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th style="width:34%">' + escapeHtml(config.langTitle || 'Title') + '</th><th style="width:22%">' + escapeHtml(config.langValue || 'Value') + '</th><th style="width:16%">' + escapeHtml(config.langUpdated || 'Updated') + '</th><th>' + escapeHtml(config.langSource || 'Source') + '</th></tr></thead>', rows);
	}

	function renderMethods(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			rows += '<tr>' +
				'<td>' + escapeHtml(list[i].METHOD || '') + '<div class="xray-muted">' + escapeHtml(list[i].DESC || '') + '</div></td>' +
				'<td>' + escapeHtml(list[i].PARAMS || '') + '</td>' +
				'<td>' + escapeHtml(list[i].EXECUTED || '') + '</td>' +
				'<td>' + escapeHtml(list[i].SOURCE || '') + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th style="width:24%">' + escapeHtml(config.langMethod || 'Method') + '</th><th style="width:34%">' + escapeHtml(config.langParams || 'Params') + '</th><th style="width:16%">' + escapeHtml(config.langExecuted || 'Executed') + '</th><th>' + escapeHtml(config.langSource || 'Source') + '</th></tr></thead>', rows);
	}

	function renderScripts(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			if (!list[i] || !list[i].SCRIPT) {
				continue;
			}
			var openLink = list[i].ID ? '<a href="' + escapeHtml((config.rootHtml || '') + 'admin.php?action=scripts&md=scripts&inst=adm&view_mode=edit_scripts&id=' + encodeURIComponent(list[i].ID)) + '">Открыть</a>' : '';
			rows += '<tr>' +
				'<td>' + escapeHtml(list[i].SCRIPT || '') + '<div class="xray-muted">' + escapeHtml(list[i].DESC || '') + '</div></td>' +
				'<td>' + escapeHtml(list[i].PARAMS || '') + '</td>' +
				'<td>' + escapeHtml(list[i].EXECUTED || '') + '</td>' +
				'<td>' + escapeHtml(list[i].SOURCE || '') + '</td>' +
				'<td class="text-right">' + openLink + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th style="width:24%">' + escapeHtml(config.langScript || 'Script') + '</th><th style="width:32%">' + escapeHtml(config.langParams || 'Params') + '</th><th style="width:16%">' + escapeHtml(config.langExecuted || 'Executed') + '</th><th style="width:18%">' + escapeHtml(config.langSource || 'Source') + '</th><th style="width:10%"></th></tr></thead>', rows);
	}

	function renderPerformance(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			rows += '<tr>' +
				'<td>' + escapeHtml(list[i].OPERATION || '') + '</td>' +
				'<td>' + escapeHtml(list[i].COUNTER || '') + '</td>' +
				'<td>' + escapeHtml(list[i].TIME || '') + '</td>' +
				'<td>' + escapeHtml(list[i].AVTIME || '') + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th>Operation</th><th>Counter</th><th>Time</th><th>Av.time</th></tr></thead>', rows);
	}

	function renderTimers(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			rows += '<tr>' +
				'<td>' + escapeHtml(list[i].TITLE || '') + '</td>' +
				'<td><code>' + escapeHtml(list[i].COMMAND || '') + '</code></td>' +
				'<td>' + escapeHtml(list[i].SCHEDULED || '') + '</td>' +
				'<td class="text-right">' + createButton(list[i].STOP_LINK || '#', config.langCancel || 'Cancel', 'btn-outline-danger') + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th style="width:24%">Title</th><th>Command</th><th style="width:20%">Scheduled</th><th style="width:1%"></th></tr></thead>', rows);
	}

	function renderDead(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			rows += '<tr>' +
				'<td>' + escapeHtml(list[i].TITLE || '') + '<div class="xray-muted">' + escapeHtml(list[i].DESCRIPTION || '') + '</div></td>' +
				'<td>' + escapeHtml(list[i].UPDATED || '') + '</td>' +
				'<td>' + escapeHtml(list[i].LOCATIONTITLE || '') + '</td>' +
				'<td>' + createTag('Не отвечает', 'xray-tag--danger') + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th>Объект</th><th style="width:18%">Обновлено</th><th style="width:22%">Расположение</th><th style="width:1%"></th></tr></thead>', rows);
	}

	function renderEvents(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			rows += '<tr>' +
				'<td>' + escapeHtml(list[i].EVENT || '') + '</td>' +
				'<td>' + escapeHtml(list[i].DETAILS || '') + '</td>' +
				'<td>' + escapeHtml(list[i].ADDED || '') + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th style="width:24%">Event</th><th>Description</th><th style="width:20%">Added</th></tr></thead>', rows);
	}

	function renderDatabase(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			var actions = '<div class="xray-table-actions">' +
				createButton(list[i].BTN_ANALYZE || '#', 'Analyze', 'btn-default') +
				createButton(list[i].BTN_OPTIMIZE || '#', 'Optimize', 'btn-default') +
				createButton(list[i].BTN_REPAIR || '#', 'Repair', 'btn-default') +
			'</div>';
			rows += '<tr>' +
				'<td>' + escapeHtml(list[i].NAME || '') + '</td>' +
				'<td>' + escapeHtml(list[i].ENGINE || '') + '</td>' +
				'<td>' + escapeHtml(list[i].ROWS || '') + '</td>' +
				'<td>' + escapeHtml(list[i].UPDATE_TIME || '') + '</td>' +
				'<td class="text-right">' + actions + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th>Name</th><th>Engine</th><th>Rows</th><th>Update</th><th></th></tr></thead>', rows);
	}

	function renderDbLoad(data) {
		var connections = data.connections_data || {};
		var hourValue = typeof data.hour !== 'undefined' ? data.hour : data.hours;
		var level = data.level || 'ok';
		var sourceLabel = data.type === 'rezerv' ? 'резервный расчёт по SHOW GLOBAL STATUS' : 'статистика mysqlnd';
		var usage = typeof data.connection_usage_percent !== 'undefined' ? data.connection_usage_percent : 0;

		return '<div class="md-admin-dbload-widget md-admin-dbload-widget--xray">' +
			'<div class="md-admin-dbload-widget__hero">' +
				'<div>' +
					'<span class="md-admin-dbload-widget__eyebrow">Нагрузка базы данных</span>' +
					'<strong>' + escapeHtml(data.status_text || 'Нагрузка в норме') + '</strong>' +
					'<small>' + escapeHtml(sourceLabel + (data.updated_at ? ' · ' + data.updated_at : '')) + '</small>' +
				'</div>' +
				'<div class="md-admin-dbload-widget__dial is-' + escapeHtml(level) + '">' +
					'<span>' + escapeHtml(usage ? (usage + '%') : '—') + '</span>' +
					'<small>соединения</small>' +
				'</div>' +
			'</div>' +
			'<div class="md-admin-dbload-widget__metrics">' +
				'<div><span>Запросов в секунду</span><strong>' + escapeHtml(formatNumber(data.second)) + '</strong></div>' +
				'<div><span>Запросов в минуту</span><strong>' + escapeHtml(formatNumber(data.minute)) + '</strong></div>' +
				'<div><span>Запросов в час</span><strong>' + escapeHtml(formatNumber(hourValue)) + '</strong></div>' +
			'</div>' +
			'<div class="md-admin-dbload-widget__connections">' +
				'<span><b>' + escapeHtml(typeof connections.running !== 'undefined' ? connections.running : '—') + '</b> выполняется</span>' +
				'<span><b>' + escapeHtml(typeof connections.connected !== 'undefined' ? connections.connected : '—') + '</b> подключено</span>' +
				'<span><b>' + escapeHtml(typeof connections.cached !== 'undefined' ? connections.cached : '—') + '</b> в кэше</span>' +
				'<span><b>' + escapeHtml(typeof connections.created !== 'undefined' ? connections.created : '—') + '</b> создано</span>' +
				'<span><b>' + escapeHtml(typeof connections.max !== 'undefined' ? connections.max : '—') + '</b> лимит</span>' +
			'</div>' +
			'<pre class="md-admin-dbload-widget__raw">' + escapeHtml(formatDbConnectionsText(data.connections)) + '</pre>' +
		'</div>';
	}

	function renderServices(list) {
		var cards = [];
		for (var i = 0; i < list.length; i++) {
			var statusClass = 'xray-tag--muted';
			var statusText = config.statusUnknown || 'Неизвестно';
			if (list[i].STATUS === 'running') {
				statusClass = 'xray-tag--success';
				statusText = config.statusRunning || 'Работает';
			} else if (list[i].STATUS === 'hang') {
				statusClass = 'xray-tag--warning';
				statusText = config.statusHang || 'Завис';
			} else if (list[i].STATUS === 'starting') {
				statusClass = 'xray-tag--info';
				statusText = config.statusStarting || 'Запускается';
			} else if (list[i].STATUS === 'stopping') {
				statusClass = 'xray-tag--warning';
				statusText = config.statusStopping || 'Выключается';
			} else if (list[i].STATUS === 'stopped') {
				statusClass = 'xray-tag--danger';
				statusText = config.statusStopped || 'Остановлен';
			}
			var tags = [createTag(statusText, statusClass)];
			if (list[i].WAIT == 1) {
				tags.push(createTag(config.statusNoResponse || 'Не отвечает', 'xray-tag--warning'));
			}
			if (list[i].UPDATE) {
				tags.push(createTag(list[i].UPDATE, 'xray-tag--muted'));
			}
			var actions = [createActionButton('button', config.langLog || 'Лог', 'btn-outline-secondary js-cycle-log', 'data-cycle="' + escapeHtml(list[i].LOG_LINK || '') + '"')];
			if (list[i].ALIVE == 1) {
				actions.push(createActionButton('button', config.langRestart || 'Рестарт', 'btn-outline-secondary js-service-command', 'data-href="' + escapeHtml(list[i].CNT_RESTART || '#') + '"'));
				actions.push(createActionButton('button', config.langStop || 'Остановить', 'btn-outline-danger js-service-command', 'data-href="' + escapeHtml(list[i].CNT_STOP || '#') + '"'));
			} else {
				actions.push(createActionButton('button', config.langStart || 'Запуск', 'btn-primary js-service-command', 'data-href="' + escapeHtml(list[i].CNT_START || '#') + '"'));
			}
			cards.push(createCard({
				title: list[i].TITLE || '',
				subtitle: list[i].STATUS_DETAILS || '',
				tags: tags,
				rows: list[i].UPDATE ? [{label: 'Updated', value: escapeHtml(list[i].UPDATE || '')}] : [],
				actions: actions,
				klass: list[i].ALIVE == 1 ? 'xray-data-card--alive' : 'xray-data-card--dead'
			}));
		}
		return createCardList(cards, 'xray-data-grid--services');
	}

	function renderLogs(content) {
		if (!content) {
			return createEmptyState(config.consoleEmptyTitle || 'Console is empty', config.consoleEmptyText || 'Wait for new data or adjust the filter.');
		}
		return '<div class="xray-console">' + content + '</div>';
	}

	function updateProgressBar() {
		var progress = el('reloadProgress');
		if (!progress) {
			return;
		}
		progress.style.transition = 'none';
		progress.style.width = '0%';
		window.requestAnimationFrame(function () {
			progress.style.transition = 'width ' + state.checkTimeout + 'ms linear';
			progress.style.width = '100%';
		});
	}

	function setRefreshState(active) {
		var button = el('playpausebtn');
		var badge = el('xrayRefreshBadge');
		if (!button) {
			return;
		}
		if (active) {
			button.classList.remove('btn-warning');
			button.classList.add('btn-outline-secondary');
			button.innerHTML = '<span class="glyphicon glyphicon-pause"></span> ' + escapeHtml(config.pauseLabel || 'Pause');
			if (badge) {
				badge.textContent = Math.round(state.checkTimeout / 1000) + ' c';
			}
		} else {
			button.classList.remove('btn-outline-secondary');
			button.classList.add('btn-warning');
			button.innerHTML = '<span class="glyphicon glyphicon-play"></span> ' + escapeHtml(config.continueLabel || 'Continue');
			if (badge) {
				badge.textContent = 'Пауза';
			}
		}
	}

	function buildPageUrlFromToolbar() {
		var toolbarForm = el('xrayToolbarForm');
		var url = new URL(window.location.href);
		url.searchParams.delete('ajax');
		url.searchParams.delete('op');
		url.searchParams.delete('files[]');

		if (!toolbarForm) {
			return url;
		}

		var viewModeInput = toolbarForm.querySelector('[name="view_mode"]');
		var tabInput = toolbarForm.querySelector('[name="tab"]');
		var filterInput = el('filter');
		var limitInput = el('limit');
		var files = getSelectedFiles();

		if (viewModeInput) {
			url.searchParams.set('view_mode', viewModeInput.value || '');
		}
		if (tabInput && tabInput.value) {
			url.searchParams.set('tab', tabInput.value);
		}
		if (filterInput && filterInput.value.trim()) {
			url.searchParams.set('filter', filterInput.value.trim());
		} else {
			url.searchParams.delete('filter');
		}
		if (limitInput && limitInput.value) {
			url.searchParams.set('limit', limitInput.value);
		} else {
			url.searchParams.delete('limit');
		}
		for (var i = 0; i < files.length; i++) {
			url.searchParams.append('files[]', files[i]);
		}
		return url;
	}

	function buildFetchUrl() {
		var url = buildPageUrlFromToolbar();
		url.searchParams.set('ajax', '1');
		url.searchParams.set('op', 'getcontent');
		return url;
	}

	function fetchContent(continueLoop) {
		var loopEnabled = continueLoop !== false;
		if (loopEnabled) {
			state.playing = true;
		}
		updateProgressBar();
		clearTimeout(state.checkTimer);

		return fetch(buildFetchUrl().toString(), {
			credentials: 'same-origin'
		}).then(function (response) {
			return response.text();
		}).then(function (text) {
			var data;
			try {
				data = JSON.parse(text);
			} catch (error) {
				setHtml(el('xrayContent'), createEmptyState(config.invalidResponseTitle || 'Invalid response', config.invalidResponseText || 'The server returned malformed JSON.'));
				if (loopEnabled && state.playing) {
					state.checkTimer = window.setTimeout(fetchContent, state.checkTimeout);
				}
				return;
			}

			setText(el('xrayModeBadge'), formatModeLabel(data.MODE));

			if (data.MODE === 'properties') {
				setHtml(el('xrayContent'), renderProperties(data.LIST || []));
			} else if (data.MODE === 'methods') {
				setHtml(el('xrayContent'), renderMethods(data.LIST || []));
			} else if (data.MODE === 'scripts') {
				setHtml(el('xrayContent'), renderScripts(data.LIST || []));
			} else if (data.MODE === 'performance') {
				setHtml(el('xrayContent'), renderPerformance(data.LIST || []));
			} else if (data.MODE === 'timers') {
				setHtml(el('xrayContent'), renderTimers(data.LIST || []));
			} else if (data.MODE === 'dead') {
				setHtml(el('xrayContent'), renderDead(data.LIST || []));
			} else if (data.MODE === 'events') {
				setHtml(el('xrayContent'), renderEvents(data.LIST || []));
			} else if (data.MODE === 'dbload') {
				setHtml(el('xrayContent'), renderDbLoad(data));
			} else if (data.MODE === 'database') {
				setHtml(el('xrayContent'), renderDatabase(data.LIST || []));
			} else if (data.MODE === 'services') {
				setHtml(el('xrayContent'), renderServices(data.LIST || []));
			} else if (data.MODE === 'logs') {
				setHtml(el('xrayContent'), renderLogs(data.CONTENT || ''));
			} else {
				setHtml(el('xrayContent'), createEmptyState(config.noDataTitle || 'No data', config.noDataText || 'The selected view did not return any content.'));
			}

			if (loopEnabled && state.playing) {
				state.checkTimer = window.setTimeout(fetchContent, state.checkTimeout);
			}
		}).catch(function () {
			setHtml(el('xrayContent'), createEmptyState(config.requestFailedTitle || 'Request failed', config.requestFailedText || 'Could not refresh X-Ray data.'));
			if (loopEnabled && state.playing) {
				state.checkTimer = window.setTimeout(fetchContent, state.checkTimeout);
			}
		});
	}

	function toggleRefresh() {
		if (state.playing) {
			clearTimeout(state.checkTimer);
			state.playing = false;
			setRefreshState(false);
			var progress = el('reloadProgress');
			if (progress) {
				progress.style.transition = 'none';
				progress.style.width = '0%';
			}
			return;
		}
		state.playing = true;
		setRefreshState(true);
		fetchContent();
	}

	function postForm(url, data) {
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		});
	}

	function submitToolbarForm() {
		var pageUrl = buildPageUrlFromToolbar();
		window.history.replaceState({}, '', pageUrl.toString());
		updateSelectedFilesBadge();
		fetchContent(state.playing);
	}

	function clearXrayLogs() {
		var selectedFiles = getSelectedFiles();
		if (!selectedFiles.length) {
			alert(config.selectAtLeastOneFile || 'Выберите хотя бы один лог-файл.');
			return false;
		}
		if (!window.confirm(config.clearLogConfirm || 'Очистить выбранные лог-файлы?')) {
			return false;
		}

		var url = buildPageUrlFromToolbar();
		url.searchParams.set('ajax', '1');
		url.searchParams.set('op', 'clearlog');

		var formData = new FormData();
		selectedFiles.forEach(function (file) {
			formData.append('files[]', file);
		});

		postForm(url.toString(), formData).then(function (response) {
			return response.text();
		}).then(function (text) {
			var data;
			try {
				data = JSON.parse(text);
			} catch (error) {
				data = {};
			}
			if (data.STATUS === 'OK') {
				fetchContent(state.playing);
			} else {
				alert(data.MESSAGE || config.clearLogFailed || 'Не удалось очистить лог.');
			}
		}).catch(function () {
			alert(config.clearLogRequestFailed || 'Ошибка запроса при очистке лога.');
		});

		return false;
	}

	function clearCycleLog() {
		if (!state.cycleLogCurrent) {
			return false;
		}
		if (!window.confirm(config.cycleLogClearConfirm || 'Очистить лог цикла? Будут удалены все записи из оперативной памяти.')) {
			return false;
		}

		var url = new URL((config.rootHtml || '') + 'panel/xray.html', window.location.origin);
		url.searchParams.set('view_mode', 'services');
		url.searchParams.set('ajax', '1');
		url.searchParams.set('op', 'clearcyclelog');
		url.searchParams.set('cycle', state.cycleLogCurrent);

		fetch(url.toString(), {
			credentials: 'same-origin'
		}).then(function (response) {
			return response.json();
		}).then(function (data) {
			if (data.STATUS === 'OK') {
				setText(el('cycleLogBody'), (config.cycleLogCleared || 'Лог цикла очищен.') + '\n');
			} else {
				setText(el('cycleLogBody'), (data.MESSAGE || config.cycleLogClearFailed || 'Не удалось очистить лог цикла.') + '\n');
			}
		}).catch(function () {
			setText(el('cycleLogBody'), (config.cycleLogClearFailed || 'Не удалось очистить лог цикла.') + '\n');
		});

		return false;
	}

	function closeXrayDrawer(owner) {
		if (window.MDJAdminDrawerHost) {
			window.MDJAdminDrawerHost.close(owner);
		}
	}

	function openLogDrawer() {
		var body = el('xrayLogDrawerBody');
		if (!body || !window.MDJAdminDrawerHost) {
			return false;
		}
		window.MDJAdminDrawerHost.open({
			owner: 'xray-log-files',
			eyebrow: 'X-Ray',
			title: config.drawerLogFilesTitle || 'Файлы логов',
			subtitle: 'Скачивание и быстрое переключение между логами за сегодня.',
			width: 'min(960px, 100vw)',
			body: body,
			footer: el('xrayLogDrawerFooter'),
			focus: el('xrayDrawerCloseBtn')
		});
		return false;
	}

	function clearSelectedFiles() {
		if (state.filesTomSelect) {
			state.filesTomSelect.clear(true);
		} else {
			var select = el('files');
			if (!select) {
				return;
			}
			for (var i = 0; i < select.options.length; i++) {
				select.options[i].selected = false;
			}
		}
		updateSelectedFilesBadge();
	}

	function pickLogFile(file, behavior) {
		if (!file) {
			return;
		}
		if (behavior === 'replace') {
			clearSelectedFiles();
		}
		if (state.filesTomSelect) {
			state.filesTomSelect.addItem(file);
		} else {
			var select = el('files');
			if (!select) {
				return;
			}
			for (var i = 0; i < select.options.length; i++) {
				if (select.options[i].value === file) {
					select.options[i].selected = true;
					break;
				}
			}
		}
		updateSelectedFilesBadge();
		if (behavior === 'replace') {
			closeXrayDrawer('xray-log-files');
			submitToolbarForm();
		}
	}

	function showCycleLog(cycle) {
		if (!cycle || !window.MDJAdminDrawerHost) {
			return false;
		}
		state.cycleLogCurrent = cycle;
		setText(el('cycleLogBody'), config.loadingText || 'Загрузка...');
		window.MDJAdminDrawerHost.open({
			owner: 'xray-cycle-log',
			eyebrow: 'X-Ray',
			title: config.drawerCycleLogTitle || 'Лог цикла',
			subtitle: cycle,
			width: 'min(980px, 100vw)',
			body: el('xrayCycleDrawerBody'),
			footer: el('xrayCycleDrawerFooter'),
			focus: el('xrayCycleDrawerCloseBtn'),
			onClose: function () {
				state.cycleLogCurrent = '';
				clearTimeout(state.cycleLogTimer);
			}
		});
		loadCycleLog();
		return false;
	}

	function loadCycleLog() {
		if (!state.cycleLogCurrent) {
			return false;
		}

		var url = new URL((config.rootHtml || '') + 'panel/xray.html', window.location.origin);
		url.searchParams.set('view_mode', 'services');
		url.searchParams.set('ajax', '1');
		url.searchParams.set('op', 'cyclelog');
		url.searchParams.set('cycle', state.cycleLogCurrent);

		fetch(url.toString(), {
			credentials: 'same-origin'
		}).then(function (response) {
			return response.json();
		}).then(function (data) {
			var content = '';
			if (data.STATUS === 'OK' && data.LOG_CYCLES_ENABLED == 0) {
				content = config.cycleLogDisabledText || 'Файловый лог циклов выключен.';
			} else if (data.STATUS === 'OK' && data.LINES && data.LINES.length) {
				for (var i = 0; i < data.LINES.length; i++) {
					content += data.LINES[i].ADDED + '  ' + data.LINES[i].MESSAGE + '\n';
				}
			} else {
				content = config.cycleLogEmptyText || 'Пока нет данных. Здесь появится stdout/stderr цикла и хвост его файлового лога.';
			}
			setText(el('cycleLogBody'), content);
			var body = el('cycleLogBody');
			if (body) {
				body.scrollTop = body.scrollHeight;
			}
		}).catch(function (error) {
			var message = config.cycleLogError || 'Ошибка загрузки лога.';
			setText(el('cycleLogBody'), message + (error && error.message ? '\n\n' + error.message : ''));
		});

		if (state.cycleLogCurrent) {
			clearTimeout(state.cycleLogTimer);
			state.cycleLogTimer = window.setTimeout(loadCycleLog, 2000);
		}
		return false;
	}

	function onContentClick(event) {
		var target = event.target;
		if (!target) {
			return;
		}
		var logButton = target.closest('.js-cycle-log');
		if (logButton) {
			event.preventDefault();
			showCycleLog(logButton.getAttribute('data-cycle'));
			return;
		}

		var actionButton = target.closest('.js-service-command');
		if (actionButton) {
			event.preventDefault();
			var href = actionButton.getAttribute('data-href');
			if (href && href !== '#') {
				window.location.href = href;
			}
			return;
		}

		var pickButton = target.closest('.js-xray-pick-file');
		if (pickButton) {
			event.preventDefault();
			pickLogFile(pickButton.getAttribute('data-file'), pickButton.getAttribute('data-behavior'));
		}
	}

	function initTomSelect() {
		var filesSelect = el('files');
		if (!filesSelect || typeof window.TomSelect !== 'function') {
			return;
		}
		state.filesTomSelect = new window.TomSelect(filesSelect, {
			plugins: ['remove_button'],
			maxOptions: 500,
			placeholder: config.logFilesLabel || 'Выберите файл лога...',
			persist: false,
			create: false,
			hideSelected: false,
			closeAfterSelect: false,
			render: {
				option: function (data, escape) {
					return '<div class="xray-ts-option"><span>' + escape(data.text) + '</span></div>';
				},
				item: function (data, escape) {
					return '<div>' + escape(data.text) + '</div>';
				}
			}
		});
		filesSelect.addEventListener('change', updateSelectedFilesBadge);
		state.filesTomSelect.on('change', updateSelectedFilesBadge);
		updateSelectedFilesBadge();
	}

	function init() {
		var playButton = el('playpausebtn');
		var clearButton = el('clearlogsbtn');
		var toolbarForm = el('xrayToolbarForm');
		var openLogDrawerButton = el('openLogDrawerBtn');
		var drawerCloseButton = el('xrayDrawerCloseBtn');
		var cycleDrawerCloseButton = el('xrayCycleDrawerCloseBtn');
		var cycleDrawerClearButton = el('xrayCycleDrawerClearBtn');

		setText(el('xrayModeBadge'), formatModeLabel(config.viewMode || ''));
		setText(el('xrayRefreshBadge'), Math.round(state.checkTimeout / 1000) + ' c');
		setRefreshState(true);
		initTomSelect();
		updateSelectedFilesBadge();

		if (toolbarForm) {
			toolbarForm.addEventListener('submit', function (event) {
				event.preventDefault();
				submitToolbarForm();
			});
		}

		if (playButton) {
			playButton.addEventListener('click', function (event) {
				event.preventDefault();
				toggleRefresh();
			});
		}

		if (clearButton) {
			clearButton.addEventListener('click', function (event) {
				event.preventDefault();
				clearXrayLogs();
			});
		}

		if (openLogDrawerButton) {
			openLogDrawerButton.addEventListener('click', function (event) {
				event.preventDefault();
				openLogDrawer();
			});
		}

		if (drawerCloseButton) {
			drawerCloseButton.addEventListener('click', function () {
				closeXrayDrawer('xray-log-files');
			});
		}

		if (cycleDrawerCloseButton) {
			cycleDrawerCloseButton.addEventListener('click', function () {
				closeXrayDrawer('xray-cycle-log');
			});
		}

		if (cycleDrawerClearButton) {
			cycleDrawerClearButton.addEventListener('click', function (event) {
				event.preventDefault();
				clearCycleLog();
			});
		}

		var content = el('xrayContent');
		if (content) {
			content.addEventListener('click', onContentClick);
		}

		var drawerBody = el('xrayLogDrawerBody');
		if (drawerBody) {
			drawerBody.addEventListener('click', onContentClick);
		}

		fetchContent(true);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
