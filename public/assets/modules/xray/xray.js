(function () {
	'use strict';

	var config = window.XRayConfig || {};
	var state = {
		checkTimer: 0,
		checkTimeout: Number(config.checkTimeout || 5000),
		playing: false,
		cycleLogTimer: 0,
		cycleLogCurrent: '',
		cycleLogPaused: false,
		cycleLogFilter: '',
		cycleLogLines: [],
		cycleLogResponse: null,
		cycleLogAutoScroll: true,
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
			if (window.MDJCycleWidgets && typeof window.MDJCycleWidgets.init === 'function') {
				window.MDJCycleWidgets.init(node);
			}
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
		var tone = '';
		if (klass && klass.indexOf('success') !== -1) {
			tone = ' md-badge--success';
		} else if (klass && klass.indexOf('warning') !== -1) {
			tone = ' md-badge--warning';
		} else if (klass && klass.indexOf('danger') !== -1) {
			tone = ' md-badge--danger';
		} else if (klass && klass.indexOf('info') !== -1) {
			tone = ' md-badge--info';
		}
		return '<span class="md-badge' + tone + '">' + escapeHtml(text) + '</span>';
	}

	function buttonClass(klass) {
		if (klass && klass.indexOf('primary') !== -1) {
			return 'md-btn md-btn--primary md-btn--sm';
		}
		if (klass && klass.indexOf('danger') !== -1) {
			return 'md-btn md-btn--danger md-btn--sm';
		}
		return 'md-btn md-btn--secondary md-btn--sm';
	}

	function createButton(href, label, klass) {
		return '<a href="' + escapeHtml(href) + '" class="' + buttonClass(klass) + '">' + escapeHtml(label) + '</a>';
	}

	function createActionButton(type, label, klass, attrs) {
		var extra = String(klass || '').split(/\s+/).filter(function (name) {
			return name.indexOf('js-') === 0;
		}).join(' ');
		return '<button type="button" class="' + buttonClass(klass) + (extra ? ' ' + extra : '') + '" ' + (attrs || '') + '>' + escapeHtml(label) + '</button>';
	}

	function createEmptyState(title, text) {
		var icon = /ошиб|error|некоррект|failed/i.test(String(title)) ? 'ti-alert-triangle' : 'ti-search-off';
		return '<div class="md-empty"><div class="md-empty__icon"><i class="ti ' + icon + '" aria-hidden="true"></i></div><div class="md-empty__title">' + escapeHtml(title) + '</div><div class="md-empty__text">' + escapeHtml(text) + '</div></div>';
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
		return '<div class="md-table__scroll xray-table-wrap"><table class="md-table xray-table">' + head + '<tbody>' + body + '</tbody></table></div>';
	}

	function createRows(items) {
		var html = '';
		for (var i = 0; i < items.length; i++) {
			if (!items[i] || items[i].value === '' || items[i].value === null || items[i].value === undefined) {
				continue;
			}
			html += '<div class="xray-service-card__row"><span>' + escapeHtml(items[i].label) + '</span><strong>' + items[i].value + '</strong></div>';
		}
		return html;
	}

	function createCard(options) {
		var title = options.title || '';
		var titleHtml = options.href ? '<a href="' + escapeHtml(options.href) + '">' + escapeHtml(title) + '</a>' : escapeHtml(title);
		var subtitleHtml = options.subtitle ? '<div class="md-card__subtitle">' + escapeHtml(options.subtitle) + '</div>' : '';
		var tagsHtml = options.tags && options.tags.length ? '<div class="md-card__actions">' + options.tags.join('') + '</div>' : '';
		var rowsHtml = options.rows && options.rows.length ? '<div class="xray-service-card__rows">' + createRows(options.rows) + '</div>' : '';
		var actionsHtml = options.actions && options.actions.length ? '<div class="md-card__footer"><div class="xray-service-card__actions">' + options.actions.join('') + '</div></div>' : '';
		return '<article class="md-card md-card--compact xray-service-card ' + escapeHtml(options.klass || '') + '">' +
			'<div class="md-card__head"><div class="md-card__titles"><div class="md-card__title">' + titleHtml + '</div>' + subtitleHtml + '</div>' + tagsHtml + '</div>' +
			'<div class="md-card__body">' + rowsHtml + '</div>' +
			actionsHtml +
		'</article>';
	}

	function createCardList(cards, extraClass) {
		if (!cards.length) {
			return createEmptyState(config.noDataTitle || 'Нет данных', config.noDataText || 'Для выбранного режима ничего не найдено.');
		}
		return '<div class="xray-service-grid ' + escapeHtml(extraClass || '') + '">' + cards.join('') + '</div>';
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
				'<td class="text-end">' + openLink + '</td>' +
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
				'<td class="text-end">' + createButton(list[i].STOP_LINK || '#', config.langCancel || 'Cancel', 'btn-outline-danger') + '</td>' +
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
				'<td class="text-end">' + actions + '</td>' +
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

	function cycleStatus(status) {
		if (status === 'running') {
			return { state: 'running', text: config.statusRunning || 'Работает', tone: 'success' };
		}
		if (status === 'hang') {
			return { state: 'hang', text: config.statusHang || 'Завис', tone: 'warning' };
		}
		if (status === 'starting') {
			return { state: 'starting', text: config.statusStarting || 'Запускается', tone: 'info' };
		}
		if (status === 'stopping') {
			return { state: 'stopping', text: config.statusStopping || 'Выключается', tone: 'warning' };
		}
		if (status === 'stopped') {
			return { state: 'stopped', text: config.statusStopped || 'Остановлен', tone: 'danger' };
		}
		if (status === 'disabled') {
			return { state: 'disabled', text: config.statusDisabled || 'Отключён', tone: 'neutral' };
		}
		return { state: 'unknown', text: config.statusUnknown || 'Неизвестно', tone: 'neutral' };
	}

	function cycleIconButton(label, icon, command, hidden, extraClass) {
		return '<button type="button" class="md-icon-btn md-icon-btn--sm ' + (extraClass || '') + '"' +
			(command ? ' data-md-cycle-command="' + command + '"' : '') +
			' title="' + escapeHtml(label) + '" aria-label="' + escapeHtml(label) + '"' +
			(hidden ? ' hidden' : '') + '>' +
			'<i class="ti ' + icon + '" aria-hidden="true"></i>' +
		'</button>';
	}

	function createCycleRow(item, view) {
		var alive = item.ALIVE == 1;
		var disabled = item.DISABLED == 1 || view.state === 'disabled';
		var title = item.TITLE || '';
		var endpoint = (window.XRayConfig && window.XRayConfig.rootHtml ? window.XRayConfig.rootHtml : '/') + 'ajax/xray.html?action=service';
		var badges = '<span class="md-badge' + (view.tone !== 'neutral' ? ' md-badge--' + view.tone : '') + '" data-md-cycle-status>' + escapeHtml(view.text) + '</span>' +
			'<span class="md-cycle-card__link ' + (alive ? 'is-online' : 'is-offline') + '" data-md-cycle-online>' + (alive ? 'ONLINE' : 'OFFLINE') + '</span>';
		if (item.WAIT == 1) {
			badges += '<span class="md-badge md-badge--warning">' + escapeHtml(config.statusNoResponse || 'Не отвечает') + '</span>';
		}
		var actions =
			'<button type="button" class="md-icon-btn md-icon-btn--sm md-icon-btn--ghost js-cycle-log" data-cycle="' + escapeHtml(item.LOG_LINK || '') + '" title="' + escapeHtml(config.langLog || 'Лог') + '" aria-label="' + escapeHtml(config.langLog || 'Лог') + '">' +
				'<i class="ti ti-file-text" aria-hidden="true"></i>' +
			'</button>' +
			cycleIconButton(config.langStart || 'Запуск', 'ti-player-play', 'start', alive || disabled, 'md-icon-btn--primary') +
			cycleIconButton(config.langRestart || 'Рестарт', 'ti-refresh', 'restart', !alive || disabled, 'md-icon-btn--secondary') +
			cycleIconButton(config.langStop || 'Остановить', 'ti-player-stop', 'stop', !alive || disabled, 'md-icon-btn--danger') +
			cycleIconButton(config.langDisable || 'Отключить', 'ti-power', 'disable', disabled, 'md-icon-btn--ghost') +
			cycleIconButton(config.langEnable || 'Включить', 'ti-power', 'enable', !disabled, 'md-icon-btn--primary');
		return '<div class="md-cycle-table__row is-' + view.state + '"' +
			' data-md-cycle data-md-cycle-name="' + escapeHtml(title) + '"' +
			' data-md-cycle-state="' + view.state + '"' +
			' data-md-cycle-endpoint="' + escapeHtml(endpoint) + '"' +
			' data-md-cycle-poll="0" role="row">' +
			'<div class="md-cycle-table__cell md-cycle-table__identity" role="cell">' +
				'<span class="md-cycle-card__signal" aria-hidden="true"></span>' +
				'<div class="md-cycle-table__titles">' +
					'<span class="md-cycle-card__name">' + escapeHtml(title) + '</span>' +
					(item.STATUS_DETAILS ? '<span class="md-cycle-card__desc">' + escapeHtml(item.STATUS_DETAILS) + '</span>' : '') +
				'</div>' +
			'</div>' +
			'<div class="md-cycle-table__cell md-cycle-table__status" role="cell">' + badges + '</div>' +
			'<div class="md-cycle-table__cell md-cycle-table__updated" role="cell"><span class="md-cycle-table__updated-label">Обновлено</span><strong data-md-cycle-updated>' + escapeHtml(item.UPDATE || '—') + '</strong></div>' +
			'<div class="md-cycle-table__cell md-cycle-table__actions" role="cell">' + actions + '</div>' +
		'</div>';
	}

	function renderServices(list) {
		if (!list || !list.length) {
			return createEmptyState(config.noDataTitle || 'Нет данных', config.noDataText || 'Для выбранного режима ничего не найдено.');
		}
		var rows = [];
		for (var i = 0; i < list.length; i++) {
			rows.push(createCycleRow(list[i], cycleStatus(list[i].STATUS)));
		}
		return '<div class="md-cycle-table" role="table">' +
			'<div class="md-cycle-table__head" role="row">' +
				'<span role="columnheader">Цикл</span>' +
				'<span role="columnheader">Состояние</span>' +
				'<span role="columnheader">Обновлено</span>' +
				'<span role="columnheader"></span>' +
			'</div>' +
			'<div class="md-cycle-table__body" role="rowgroup">' + rows.join('') + '</div>' +
		'</div>';
	}

	function renderLogs(content, lines) {
		if (lines && lines.length) {
			var entries = [];
			for (var i = 0; i < lines.length; i++) {
				var entry = lines[i] || {};
				var level = entry.LEVEL || 'info';
				var tone = level === 'error' ? 'danger' : (level === 'warning' ? 'warning' : 'info');
				var levelLabel = level === 'error' ? 'Ошибка' : (level === 'warning' ? 'Предупреждение' : 'Сообщение');
				var summary = entry.SUMMARY || entry.MESSAGE || '';
				var message = entry.MESSAGE || '';
				var meta = '<div class="xray-log-entry__meta">';
				if (entry.DATE_LABEL) {
					meta += '<span class="xray-log-entry__date">' + escapeHtml(entry.DATE_LABEL) + '</span>';
				}
				if (entry.TIME) {
					meta += '<span class="xray-log-entry__time">' + escapeHtml(entry.TIME) + '</span>';
				}
				if (entry.FILE) {
					meta += '<span class="xray-log-entry__file"><i class="ti ti-file-text" aria-hidden="true"></i>' + escapeHtml(entry.FILE) + '</span>';
				}
				meta += '<span class="md-badge md-badge--' + tone + '">' + levelLabel + '</span></div>';
				var details = '';
				if (message && message !== summary) {
					details = '<details class="xray-log-entry__details"><summary>Показать полностью</summary><pre>' + escapeHtml(message) + '</pre></details>';
				}
				entries.push('<article class="xray-log-entry xray-log-entry--' + escapeHtml(level) + '">' + meta + '<div class="xray-log-entry__summary">' + escapeHtml(summary) + '</div>' + details + '</article>');
			}
			return '<div class="xray-log-list">' + entries.join('') + '</div>';
		}
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
			button.classList.remove('is-paused');
			button.innerHTML = '<i class="ti ti-player-pause" aria-hidden="true"></i> <span class="md-btn__label">' + escapeHtml(config.pauseLabel || 'Pause') + '</span>';
			if (badge) {
				badge.textContent = Math.round(state.checkTimeout / 1000) + ' c';
			}
		} else {
			button.classList.add('is-paused');
			button.innerHTML = '<i class="ti ti-player-play" aria-hidden="true"></i> <span class="md-btn__label">' + escapeHtml(config.continueLabel || 'Continue') + '</span>';
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
		var dateFilter = el('xrayDateFilter');
		var files = getSelectedFiles();

		if (viewModeInput) {
			url.searchParams.set('view_mode', viewModeInput.value || '');
		}
		if (tabInput && tabInput.value) {
			url.searchParams.set('tab', tabInput.value);
		}
		if (dateFilter && dateFilter.value) {
			url.searchParams.set('date', dateFilter.value);
		} else {
			url.searchParams.delete('date');
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
			credentials: 'same-origin',
			cache: 'no-store'
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
				setHtml(el('xrayContent'), renderLogs(data.CONTENT || '', data.LINES || []));
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
			var list = el('xrayCycleLogList');
			if (data.STATUS === 'OK') {
				state.cycleLogLines = [];
				state.cycleLogResponse = {STATUS: 'OK', LINES: []};
				if (list) {
					list.innerHTML = cycleLogEmpty('ti-check', 'Лог цикла очищен', 'Новые записи появятся здесь автоматически.');
				}
				setText(el('xrayCycleCount'), '0 записей');
			} else if (list) {
				list.innerHTML = cycleLogEmpty('ti-alert-triangle', 'Не удалось очистить лог', data.MESSAGE || config.cycleLogClearFailed || 'Не удалось очистить лог цикла.');
			}
		}).catch(function () {
			var list = el('xrayCycleLogList');
			if (list) {
				list.innerHTML = cycleLogEmpty('ti-alert-triangle', 'Не удалось очистить лог', config.cycleLogClearFailed || 'Не удалось очистить лог цикла.');
			}
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
		var dateFilter = el('xrayDateFilter');
		if (dateFilter && dateFilter.value && fileDateFromValue(file) !== dateFilter.value) {
			dateFilter.value = '';
			applyFileDateFilter(true);
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

	function decodeHtmlEntities(value) {
		var textarea = document.createElement('textarea');
		textarea.innerHTML = String(value === null || value === undefined ? '' : value);
		return textarea.value;
	}

	function cycleLogCountLabel(count) {
		var n = Math.abs(count) % 100;
		var d = n % 10;
		var word = 'записей';
		if (d === 1 && n !== 11) {
			word = 'запись';
		} else if (d >= 2 && d <= 4 && (n < 12 || n > 14)) {
			word = 'записи';
		}
		return count + ' ' + word;
	}

	function cycleLogEmpty(icon, title, text) {
		return '<div class="md-empty md-empty--compact xray-cycle-empty">'
			+ '<div class="md-empty__icon"><i class="ti ' + escapeHtml(icon) + '" aria-hidden="true"></i></div>'
			+ '<div class="md-empty__title">' + escapeHtml(title) + '</div>'
			+ '<div class="md-empty__text">' + escapeHtml(text) + '</div>'
			+ '</div>';
	}

	function setCyclePauseUi() {
		var paused = state.cycleLogPaused;
		var button = el('xrayCyclePauseBtn');
		if (button) {
			var icon = button.querySelector('i');
			if (icon) {
				icon.className = 'ti ' + (paused ? 'ti-player-play' : 'ti-player-pause');
			}
			var label = button.querySelector('.md-btn__label');
			if (label) {
				label.textContent = paused ? 'Продолжить' : 'Пауза';
			}
		}
		var status = el('xrayCycleStatus');
		if (status) {
			status.className = 'md-badge ' + (paused ? 'md-badge--warning' : 'md-badge--success');
			status.textContent = paused ? 'Пауза' : 'Автообновление';
		}
	}

	function renderCycleLog(data) {
		state.cycleLogResponse = data || {};
		state.cycleLogLines = (data && data.LINES) ? data.LINES : [];

		var list = el('xrayCycleLogList');
		var count = el('xrayCycleCount');
		var updated = el('xrayCycleUpdated');
		if (!list) {
			return;
		}

		if (updated) {
			updated.textContent = (data && data.STATUS === 'OK' && data.LOG_CYCLES_ENABLED == 0 ? 'Файловый лог выключен · ' : '')
				+ 'Обновлено ' + new Date().toLocaleTimeString('ru-RU', {hour12: false});
		}

		if (!data || data.STATUS !== 'OK') {
			list.innerHTML = cycleLogEmpty('ti-alert-triangle', 'Не удалось загрузить лог', data && data.MESSAGE ? data.MESSAGE : (config.cycleLogError || 'Ошибка загрузки лога.'));
			if (count) {
				count.textContent = '0 записей';
			}
			return;
		}

		if (data.LOG_CYCLES_ENABLED == 0 && !state.cycleLogLines.length) {
			list.innerHTML = cycleLogEmpty('ti-file-off', 'Файловый лог выключен', config.cycleLogDisabledText || 'Файловый лог циклов выключен. Включите LOG_CYCLES, чтобы видеть хвост файлового лога.');
			if (count) {
				count.textContent = '0 записей';
			}
			return;
		}

		if (!state.cycleLogLines.length) {
			list.innerHTML = cycleLogEmpty('ti-history', 'Пока нет записей', config.cycleLogEmptyText || 'Здесь появятся stdout/stderr цикла и хвост его файлового лога.');
			if (count) {
				count.textContent = '0 записей';
			}
			return;
		}

		var filter = state.cycleLogFilter.trim().toLowerCase();
		var rows = [];
		var visible = 0;
		var lines = state.cycleLogLines;

		for (var i = 0; i < lines.length; i++) {
			var line = lines[i] || {};
			var message = decodeHtmlEntities(line.MESSAGE || '');
			var source = '';
			if (message.indexOf('[file] ') === 0) {
				source = 'файл';
				message = message.slice(7);
			}

			if (filter && message.toLowerCase().indexOf(filter) === -1 && String(line.ADDED || '').indexOf(filter) === -1) {
				continue;
			}

			var level = '';
			if (/(fatal error|parse error|uncaught|exception|\berror\b|e_error|e_compile_error)/i.test(message)) {
				level = 'error';
			} else if (/(warning|deprecated|notice|e_warning)/i.test(message)) {
				level = 'warning';
			}

			visible++;
			rows.push(
				'<div class="xray-cycle-entry' + (level ? ' xray-cycle-entry--' + level : '') + '">'
				+ '<time class="xray-cycle-entry__time">' + escapeHtml(line.ADDED || '') + '</time>'
				+ '<div class="xray-cycle-entry__body">'
				+ (source ? '<span class="md-badge md-badge--outline xray-cycle-entry__source">' + source + '</span>' : '')
				+ '<span class="xray-cycle-entry__message">' + escapeHtml(message) + '</span>'
				+ '</div>'
				+ '</div>'
			);
		}

		if (count) {
			count.textContent = filter
				? visible + ' из ' + lines.length
				: cycleLogCountLabel(lines.length);
		}

		if (!visible) {
			list.innerHTML = cycleLogEmpty('ti-search-off', 'Ничего не найдено', 'Измените фильтр по сообщению или времени.');
			return;
		}

		var nearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 80;
		list.innerHTML = rows.join('');
		if (state.cycleLogAutoScroll || nearBottom) {
			list.scrollTop = list.scrollHeight;
		}
	}

	function scheduleCycleLog() {
		clearTimeout(state.cycleLogTimer);
		if (state.cycleLogCurrent && !state.cycleLogPaused) {
			state.cycleLogTimer = window.setTimeout(loadCycleLog, 2000);
		}
	}

	function showCycleLog(cycle) {
		if (!cycle || !window.MDJAdminDrawerHost) {
			return false;
		}
		state.cycleLogCurrent = cycle;
		state.cycleLogPaused = false;
		state.cycleLogFilter = '';
		state.cycleLogLines = [];
		state.cycleLogResponse = null;
		state.cycleLogAutoScroll = true;
		var filter = el('xrayCycleLogFilter');
		if (filter) {
			filter.value = '';
		}
		setCyclePauseUi();
		var count = el('xrayCycleCount');
		if (count) {
			count.textContent = '0 записей';
		}
		var updated = el('xrayCycleUpdated');
		if (updated) {
			updated.textContent = '';
		}
		var list = el('xrayCycleLogList');
		if (list) {
			list.innerHTML = cycleLogEmpty('ti-loader', 'Загружаем лог', 'Запрашиваем оперативные записи и хвост файлового лога.');
		}
		window.MDJAdminDrawerHost.open({
			owner: 'xray-cycle-log',
			eyebrow: 'X-Ray · цикл',
			title: config.drawerCycleLogTitle || 'Лог цикла',
			subtitle: cycle,
			width: 'min(900px, 100vw)',
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
			renderCycleLog(data);
			scheduleCycleLog();
		}).catch(function (error) {
			renderCycleLog({
				STATUS: 'ERROR',
				MESSAGE: (config.cycleLogError || 'Ошибка загрузки лога.') + (error && error.message ? ' ' + error.message : '')
			});
			scheduleCycleLog();
		});

		return false;
	}

	function toggleCyclePause() {
		state.cycleLogPaused = !state.cycleLogPaused;
		setCyclePauseUi();
		if (state.cycleLogPaused) {
			clearTimeout(state.cycleLogTimer);
		} else {
			loadCycleLog();
		}
	}

	function downloadCycleLog() {
		if (!state.cycleLogLines.length) {
			return false;
		}
		var lines = [];
		for (var i = 0; i < state.cycleLogLines.length; i++) {
			var line = state.cycleLogLines[i] || {};
			lines.push((line.ADDED || '') + '  ' + decodeHtmlEntities(line.MESSAGE || ''));
		}
		var blob = new Blob([lines.join('\n')], {type: 'text/plain;charset=utf-8'});
		var link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = 'cycle-' + String(state.cycleLogCurrent || 'log').replace(/[^\w.-]+/g, '_') + '.log';
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		window.setTimeout(function () {
			URL.revokeObjectURL(link.href);
		}, 1000);
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

	function fileDateFromValue(value) {
		var match = String(value || '').match(/^(\d{4}-\d{2}-\d{2})\//);
		return match ? match[1] : '';
	}

	function applyFileDateFilter(preserveSelection) {
		var dateFilter = el('xrayDateFilter');
		if (!dateFilter || !state.filesTomSelect) {
			return;
		}
		var date = dateFilter.value;
		state.filesTomSelect.clearOptions();
		state.filesTomSelect.addOptions(state.allFiles.filter(function (item) {
			return date === '' || item.date === date;
		}));
		state.filesTomSelect.refreshOptions(false);
		if (!preserveSelection) {
			state.filesTomSelect.clear(true);
		}
		updateSelectedFilesBadge();
	}

	function initTomSelect() {
		var filesSelect = el('files');
		if (!filesSelect || typeof window.TomSelect !== 'function') {
			return;
		}
		state.allFiles = Array.prototype.map.call(filesSelect.options, function (option) {
			return {
				value: option.value,
				text: option.text,
				date: option.getAttribute('data-date') || fileDateFromValue(option.value)
			};
		});
		state.filesTomSelect = new window.TomSelect(filesSelect, {
			plugins: ['remove_button'],
			maxOptions: 500,
			placeholder: config.logFilesLabel || 'Имя файла...',
			persist: false,
			create: false,
			hideSelected: false,
			closeAfterSelect: false,
			render: {
				option: function (data, escape) {
					var date = data.date || fileDateFromValue(data.value);
					return '<div class="xray-ts-option"><span>' + escape(data.text) + '</span>' + (date ? '<small>' + escape(date) + '</small>' : '') + '</div>';
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
		var dateFilter = el('xrayDateFilter');
		var openLogDrawerButton = el('openLogDrawerBtn');
		var drawerCloseButton = el('xrayDrawerCloseBtn');
		var cycleDrawerCloseButton = el('xrayCycleDrawerCloseBtn');
		var cycleDrawerClearButton = el('xrayCycleDrawerClearBtn');
		var cycleDrawerDownloadButton = el('xrayCycleDrawerDownloadBtn');
		var cyclePauseButton = el('xrayCyclePauseBtn');
		var cycleFilter = el('xrayCycleLogFilter');
		var cycleList = el('xrayCycleLogList');

		setText(el('xrayModeBadge'), formatModeLabel(config.viewMode || ''));
		setText(el('xrayRefreshBadge'), Math.round(state.checkTimeout / 1000) + ' c');
		setRefreshState(true);
		initTomSelect();
		updateSelectedFilesBadge();

		if (dateFilter) {
			var initialDate = new URL(window.location.href).searchParams.get('date') || '';
			if (initialDate) {
				dateFilter.value = initialDate;
			}
			if (dateFilter.value) {
				applyFileDateFilter(true);
			}
			dateFilter.addEventListener('change', function () {
				applyFileDateFilter(false);
			});
		}

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

		if (cycleDrawerDownloadButton) {
			cycleDrawerDownloadButton.addEventListener('click', function (event) {
				event.preventDefault();
				downloadCycleLog();
			});
		}

		if (cyclePauseButton) {
			cyclePauseButton.addEventListener('click', function (event) {
				event.preventDefault();
				toggleCyclePause();
			});
		}

		if (cycleFilter) {
			cycleFilter.addEventListener('input', function () {
				state.cycleLogFilter = cycleFilter.value || '';
				if (state.cycleLogResponse) {
					renderCycleLog(state.cycleLogResponse);
				}
			});
		}

		if (cycleList) {
			cycleList.addEventListener('scroll', function () {
				state.cycleLogAutoScroll = cycleList.scrollHeight - cycleList.scrollTop - cycleList.clientHeight < 80;
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
