(function () {
	'use strict';

	var config = window.XRayConfig || {};
	var state = {
		checkTimer: 0,
		checkTimeout: Number(config.checkTimeout || 5000),
		playing: false,
		cycleLogTimer: 0,
		cycleLogCurrent: '',
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

	function createBadge(text, klass) {
		return '<span class="label label-' + escapeHtml(klass) + '">' + escapeHtml(text) + '</span>';
	}

	function createButton(href, label, klass, icon) {
		var html = '<a href="' + escapeHtml(href) + '" class="btn btn-xs ' + escapeHtml(klass) + '">';
		if (icon) {
			html += '<i class="glyphicon glyphicon-' + escapeHtml(icon) + '"></i> ';
		}
		return html + escapeHtml(label) + '</a>';
	}

	function createEmptyState(title, text) {
		return '<div class="xray-empty-state"><div><div class="xray-spinner"></div><strong>' + escapeHtml(title) + '</strong><div>' + escapeHtml(text) + '</div></div></div>';
	}

	function createTable(head, body) {
		return '<div class="table-responsive xray-table-wrap"><table class="table table-condensed table-striped table-hover xray-table">' + head + '<tbody>' + body + '</tbody></table></div>';
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
			button.classList.add('btn-primary');
			button.innerHTML = '<i class="glyphicon glyphicon-pause"></i> ' + escapeHtml(config.pauseLabel || 'Pause');
			if (badge) {
				badge.textContent = Math.round(state.checkTimeout / 1000) + ' c';
			}
		} else {
			button.classList.remove('btn-primary');
			button.classList.add('btn-warning');
			button.innerHTML = '<i class="glyphicon glyphicon-play"></i> ' + escapeHtml(config.continueLabel || 'Continue');
			if (badge) {
				badge.textContent = 'Пауза';
			}
		}
	}

	function renderProperties(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			var item = list[i];
			rows += '<tr>' +
				'<td>' + escapeHtml(item.NAME || '') + '<div class="xray-muted">' + escapeHtml(item.DESC || '') + '</div></td>' +
				'<td>' + escapeHtml(item.VALUE || '') + '</td>' +
				'<td>' + escapeHtml(item.UPDATE || '') + '</td>' +
				'<td>' + escapeHtml(item.SOURCE || '') + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th style="width:30%">' + escapeHtml(config.langTitle || 'Title') + '</th><th style="width:20%">' + escapeHtml(config.langValue || 'Value') + '</th><th style="width:15%">' + escapeHtml(config.langUpdated || 'Updated') + '</th><th>' + escapeHtml(config.langSource || 'Source') + '</th></tr></thead>', rows);
	}

	function renderMethods(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			var item = list[i];
			rows += '<tr>' +
				'<td>' + escapeHtml(item.METHOD || '') + '<div class="xray-muted">' + escapeHtml(item.DESC || '') + '</div></td>' +
				'<td>' + escapeHtml(item.PARAMS || '') + '</td>' +
				'<td>' + escapeHtml(item.EXECUTED || '') + '</td>' +
				'<td>' + escapeHtml(item.SOURCE || '') + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th style="width:20%">' + escapeHtml(config.langMethod || 'Method') + '</th><th style="width:30%">' + escapeHtml(config.langParams || 'Params') + '</th><th style="width:15%">' + escapeHtml(config.langExecuted || 'Executed') + '</th><th>' + escapeHtml(config.langSource || 'Source') + '</th></tr></thead>', rows);
	}

	function renderScripts(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			var item = list[i];
			rows += '<tr>' +
				'<td>' + escapeHtml(item.SCRIPT || '') + '<div class="xray-muted">' + escapeHtml(item.DESC || '') + '</div></td>' +
				'<td>' + escapeHtml(item.PARAMS || '') + '</td>' +
				'<td>' + escapeHtml(item.EXECUTED || '') + '</td>' +
				'<td>' + escapeHtml(item.SOURCE || '') + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th style="width:20%">' + escapeHtml(config.langScript || 'Script') + '</th><th style="width:30%">' + escapeHtml(config.langParams || 'Params') + '</th><th style="width:15%">' + escapeHtml(config.langExecuted || 'Executed') + '</th><th>' + escapeHtml(config.langSource || 'Source') + '</th></tr></thead>', rows);
	}

	function renderPerformance(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			var item = list[i];
			rows += '<tr>' +
				'<td>' + escapeHtml(item.OPERATION || '') + '</td>' +
				'<td>' + escapeHtml(item.COUNTER || '') + '</td>' +
				'<td>' + escapeHtml(item.TIME || '') + '</td>' +
				'<td>' + escapeHtml(item.AVTIME || '') + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th style="width:30%">Operation</th><th style="width:20%">Counter</th><th style="width:15%">Time</th><th>Av.time</th></tr></thead>', rows);
	}

	function renderTimers(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			var item = list[i];
			rows += '<tr>' +
				'<td>' + escapeHtml(item.TITLE || '') + '</td>' +
				'<td>' + escapeHtml(item.COMMAND || '') + '</td>' +
				'<td>' + escapeHtml(item.SCHEDULED || '') + '</td>' +
				'<td class="text-center"><a href="' + escapeHtml(item.STOP_LINK || '#') + '" class="btn btn-xs btn-danger">' + escapeHtml(config.langCancel || 'Cancel') + '</a></td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th style="width:25%">Title</th><th>Command</th><th style="width:18%">Scheduled</th><th style="width:1px"></th></tr></thead>', rows);
	}

	function renderDead(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			var item = list[i];
			rows += '<tr>' +
				'<td>' + escapeHtml(item.TITLE || '') + '</td>' +
				'<td>' + escapeHtml(item.DESCRIPTION || '') + '</td>' +
				'<td>' + escapeHtml(item.UPDATED || '') + '</td>' +
				'<td>' + escapeHtml(item.LOCATIONTITLE || '') + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th style="width:20%">Title</th><th>Description</th><th style="width:15%">Updated</th><th>Location</th></tr></thead>', rows);
	}

	function renderEvents(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			var item = list[i];
			rows += '<tr>' +
				'<td>' + escapeHtml(item.EVENT || '') + '</td>' +
				'<td>' + escapeHtml(item.DETAILS || '') + '</td>' +
				'<td>' + escapeHtml(item.ADDED || '') + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th style="width:20%">Event</th><th>Description</th><th style="width:15%">Added</th></tr></thead>', rows);
	}

	function renderDatabase(list) {
		var rows = '';
		for (var i = 0; i < list.length; i++) {
			var item = list[i];
			var actions = '<div class="btn-group btn-group-xs">' +
				createButton(item.BTN_ANALYZE || '#', 'Analyze', 'btn-default') +
				createButton(item.BTN_OPTIMIZE || '#', 'Optimize', 'btn-default') +
				createButton(item.BTN_REPAIR || '#', 'Repair', 'btn-default') +
			'</div>';
			rows += '<tr>' +
				'<td>' + escapeHtml(item.NAME || '') + '</td>' +
				'<td>' + escapeHtml(item.ENGINE || '') + '</td>' +
				'<td>' + escapeHtml(item.ROWS || '') + '</td>' +
				'<td>' + escapeHtml(item.UPDATE_TIME || '') + '</td>' +
				'<td class="text-right">' + actions + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th style="width:25%">Name</th><th style="width:15%">Engine</th><th style="width:12%">Rows</th><th style="width:15%">Update</th><th></th></tr></thead>', rows);
	}

	function renderServices(list) {
		var rows = '';
		var statusLabels = {
			'starting': createBadge(config.statusStarting || 'Запускается', 'warning'),
			'running': createBadge(config.statusRunning || 'Работает', 'success'),
			'hang': createBadge(config.statusHang || 'Завис', 'info'),
			'stopping': createBadge(config.statusStopping || 'Выключается', 'warning'),
			'stopped': createBadge(config.statusStopped || 'Остановлен', 'danger')
		};
		for (var i = 0; i < list.length; i++) {
			var item = list[i];
			var status = item.STATUS || '';
			var statusHtml = statusLabels[status] || createBadge(config.statusUnknown || 'Неизвестно', 'default');
			var waitHtml = item.WAIT == 1 ? createBadge(config.statusNoResponse || 'Не отвечает', 'info') : '';
			var updatedHtml = item.UPDATE ? createBadge(item.UPDATE, 'default') : '';
			var detailsHtml = item.STATUS_DETAILS ? '<div class="xray-muted">' + escapeHtml(item.STATUS_DETAILS) + '</div>' : '';
			var statusBlock = '<div class="xray-service-status">' + statusHtml + waitHtml + updatedHtml + detailsHtml + '</div>';
			var actions;
			if (item.ALIVE == 1) {
				actions = '<div class="btn-group btn-group-xs xray-service-actions">' +
					'<button type="button" class="btn btn-default js-cycle-log" data-cycle="' + escapeHtml(item.LOG_LINK || '') + '">' + escapeHtml(config.langLog || 'Log') + '</button>' +
					'<button type="button" class="btn btn-info js-service-command" data-href="' + escapeHtml(item.CNT_RESTART || '#') + '">' + escapeHtml(config.langRestart || 'Restart') + '</button>' +
					'<button type="button" class="btn btn-danger js-service-command" data-href="' + escapeHtml(item.CNT_STOP || '#') + '">' + escapeHtml(config.langStop || 'Stop') + '</button>' +
				'</div>';
			} else {
				actions = '<div class="btn-group btn-group-xs xray-service-actions">' +
					'<button type="button" class="btn btn-default js-cycle-log" data-cycle="' + escapeHtml(item.LOG_LINK || '') + '">' + escapeHtml(config.langLog || 'Log') + '</button>' +
					'<button type="button" class="btn btn-success js-service-command" data-href="' + escapeHtml(item.CNT_START || '#') + '">' + escapeHtml(config.langStart || 'Start') + '</button>' +
				'</div>';
			}
			rows += '<tr' + (item.ALIVE == 0 ? ' class="danger"' : '') + '>' +
				'<td>' + escapeHtml(item.TITLE || '') + '</td>' +
				'<td class="text-center">' + statusBlock + '</td>' +
				'<td class="text-right">' + actions + '</td>' +
			'</tr>';
		}
		return createTable('<thead><tr><th style="width:24%">Cycle</th><th>Status</th><th style="width:1px" class="text-right">Actions</th></tr></thead>', rows);
	}

	function renderLogs(content) {
		if (!content) {
			return createEmptyState(config.consoleEmptyTitle || 'Console is empty', config.consoleEmptyText || 'Wait for new data or adjust the filter.');
		}
		return '<div class="xray-console">' + content + '</div>';
	}

	function fetchContent(continueLoop) {
		var loopEnabled = continueLoop !== false;
		if (loopEnabled) {
			state.playing = true;
		}
		updateProgressBar();
		clearTimeout(state.checkTimer);

		var url = new URL(window.location.href);
		url.searchParams.set('ajax', '1');
		url.searchParams.set('op', 'getcontent');

		return fetch(url.toString(), {
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

	function clearXrayLogs() {
		var select = el('files');
		if (!select) {
			return false;
		}
		var selectedFiles = Array.prototype.filter.call(select.options, function (option) {
			return option.selected;
		}).map(function (option) {
			return option.value;
		});

		if (!selectedFiles.length) {
			alert(config.selectAtLeastOneFile || 'Выберите хотя бы один лог-файл.');
			return false;
		}
		if (!window.confirm(config.clearLogConfirm || 'Очистить выбранные лог-файлы?')) {
			return false;
		}

		var url = new URL(window.location.href);
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

	function showCycleLog(cycle) {
		if (!cycle) {
			return false;
		}
		state.cycleLogCurrent = cycle;
		setText(el('cycleLogTitle'), cycle);
		setText(el('cycleLogBody'), config.loadingText || 'Загрузка...');
		openModal();
		loadCycleLog();
		return false;
	}

	function openModal() {
		var modalEl = el('cycleLogModal');
		if (!modalEl) {
			return;
		}
		if (window.bootstrap && bootstrap.Modal) {
			var instance = bootstrap.Modal.getOrCreateInstance(modalEl);
			instance.show();
			return;
		}
		modalEl.style.display = 'block';
		modalEl.classList.add('in');
	}

	function closeModal() {
		var modalEl = el('cycleLogModal');
		if (!modalEl) {
			return;
		}
		if (window.bootstrap && bootstrap.Modal) {
			var instance = bootstrap.Modal.getInstance(modalEl);
			if (instance) {
				instance.hide();
			}
			return;
		}
		modalEl.style.display = 'none';
		modalEl.classList.remove('in');
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
		}
	}

	function init() {
		var playButton = el('playpausebtn');
		var clearButton = el('clearlogsbtn');
		var filesSelect = el('files');
		var modalEl = el('cycleLogModal');

		setText(el('xrayModeBadge'), formatModeLabel(config.viewMode || ''));
		setText(el('xrayRefreshBadge'), Math.round(state.checkTimeout / 1000) + ' c');
		setRefreshState(true);

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

		if (modalEl) {
			modalEl.addEventListener('hidden.bs.modal', function () {
				state.cycleLogCurrent = '';
				clearTimeout(state.cycleLogTimer);
			});
		}

		var content = el('xrayContent');
		if (content) {
			content.addEventListener('click', onContentClick);
		}

		if (filesSelect) {
			filesSelect.setAttribute('aria-label', config.logFilesLabel || 'Log files');
		}

		fetchContent(true);
	}

	document.addEventListener('DOMContentLoaded', init);
})();
