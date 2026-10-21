(function (window, document) {
    'use strict';

    /**
     * Dashboard charts.
     *
     * ApexCharts is loaded lazily: the dashboard is the only shell page that
     * needs it, so other admin pages must not pay for the chart runtime.
     */
    var Apex = null;
    var apexPromise = null;
    var charts = {};
    var state = {
        activity: null,
        cycles: null,
        resources: null
    };
    var themeObserver = null;

    function chartScriptUrl() {
        var config = window.MDChartsConfig || {};
        return config.script || ((window.ROOTHTML || '/') + 'assets/admin/charts.js');
    }

    function chartStyleUrl() {
        var config = window.MDChartsConfig || {};
        return config.style || ((window.ROOTHTML || '/') + 'assets/admin/charts.css');
    }

    function ensureChartStyle() {
        if (document.querySelector('[data-md-charts-asset="style"]')) {
            return;
        }
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = chartStyleUrl();
        link.setAttribute('data-md-charts-asset', 'style');
        document.head.appendChild(link);
    }

    function loadApex() {
        if (Apex) {
            return Promise.resolve(Apex);
        }
        if (window.ApexCharts) {
            Apex = window.ApexCharts;
            return Promise.resolve(Apex);
        }
        if (apexPromise) {
            return apexPromise;
        }
        ensureChartStyle();
        apexPromise = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = chartScriptUrl();
            script.async = true;
            script.setAttribute('data-md-charts-asset', 'script');
            script.onload = function () {
                if (window.ApexCharts) {
                    Apex = window.ApexCharts;
                    resolve(Apex);
                } else {
                    apexPromise = null;
                    reject(new Error('ApexCharts is not available'));
                }
            };
            script.onerror = function () {
                apexPromise = null;
                reject(new Error('Failed to load ApexCharts'));
            };
            document.head.appendChild(script);
        });
        return apexPromise;
    }

    function cssVar(name, fallback) {
        var value = window.getComputedStyle(document.body).getPropertyValue(name);
        value = value ? String(value).trim() : '';
        return value || fallback;
    }

    function isDark() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark';
    }

    function colors() {
        return {
            text: cssVar('--md-ui-text', '#101828'),
            muted: cssVar('--md-ui-muted', '#667085'),
            border: cssVar('--md-ui-border', '#e4e8ef'),
            surface: cssVar('--md-ui-surface', '#ffffff'),
            surface3: cssVar('--md-ui-surface-3', '#f1f5f9'),
            primary: cssVar('--md-ui-primary', '#2f7fbd'),
            success: cssVar('--md-ui-success', '#12915c'),
            warning: cssVar('--md-ui-warning', '#b25e09'),
            danger: cssVar('--md-ui-danger', '#c4322b'),
            info: cssVar('--md-ui-info', '#2570a8'),
            neutral: cssVar('--md-ui-muted', '#667085')
        };
    }

    function baseOptions() {
        var palette = colors();
        return {
            chart: {
                background: 'transparent',
                fontFamily: 'Rubik, system-ui, -apple-system, "Segoe UI", sans-serif',
                foreColor: palette.muted,
                toolbar: { show: false },
                zoom: { enabled: false },
                animations: { enabled: true, speed: 320 },
                parentHeightOffset: 0
            },
            theme: { mode: isDark() ? 'dark' : 'light' },
            dataLabels: { enabled: false },
            legend: { show: false },
            grid: {
                borderColor: palette.border,
                strokeDashArray: 3,
                padding: { left: 2, right: 8, top: 4, bottom: 0 }
            },
            tooltip: {
                theme: isDark() ? 'dark' : 'light',
                style: { fontSize: '12px' }
            },
            states: {
                hover: { filter: { type: 'lighten', value: 0.04 } },
                active: { filter: { type: 'none' } }
            },
            noData: {
                text: 'Нет данных',
                align: 'center',
                verticalAlign: 'middle',
                style: { color: palette.muted, fontSize: '13px' }
            }
        };
    }

    function merge(target, source) {
        var result = {};
        var key;
        for (key in target) {
            if (Object.prototype.hasOwnProperty.call(target, key)) {
                result[key] = target[key];
            }
        }
        for (key in source) {
            if (!Object.prototype.hasOwnProperty.call(source, key)) {
                continue;
            }
            if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])
                && target[key] && typeof target[key] === 'object' && !Array.isArray(target[key])) {
                result[key] = merge(target[key], source[key]);
            } else {
                result[key] = source[key];
            }
        }
        return result;
    }

    function container(key) {
        return document.querySelector('[data-md-dash-chart="' + key + '"]');
    }

    function createOrUpdate(key, element, options) {
        var existing = charts[key];
        if (existing && existing.element === element && existing.instance) {
            existing.instance.updateOptions(options, false, true);
            existing.options = options;
            return;
        }
        if (existing && existing.instance) {
            existing.instance.destroy();
        }
        element.innerHTML = '';
        var instance = new Apex(element, options);
        instance.render();
        charts[key] = { element: element, instance: instance, options: options };
    }

    function renderActivity(data) {
        state.activity = data;
        var element = container('activity');
        if (!element || !data) {
            return;
        }
        loadApex().then(function () {
            if (!document.body.contains(element)) {
                return;
            }
            var palette = colors();
            var labels = Array.isArray(data.labels) ? data.labels : [];
            var properties = Array.isArray(data.properties) ? data.properties : [];
            var scripts = Array.isArray(data.scripts) ? data.scripts : [];
            var total = properties.reduce(function (sum, value) { return sum + Number(value || 0); }, 0)
                + scripts.reduce(function (sum, value) { return sum + Number(value || 0); }, 0);
            var options = merge(baseOptions(), {
                chart: {
                    type: 'area',
                    height: 270,
                    sparkline: { enabled: false }
                },
                series: total > 0 ? [
                    { name: 'Свойства', data: properties },
                    { name: 'Скрипты', data: scripts }
                ] : [],
                noData: {
                    text: 'За последние 24 часа активности нет',
                    align: 'center',
                    verticalAlign: 'middle',
                    style: { color: palette.muted, fontSize: '13px' }
                },
                colors: [palette.primary, palette.warning],
                stroke: { curve: 'smooth', width: 2, lineCap: 'round' },
                fill: {
                    type: 'gradient',
                    gradient: {
                        shadeIntensity: 1,
                        opacityFrom: 0.26,
                        opacityTo: 0.02,
                        stops: [0, 92]
                    }
                },
                markers: { size: 0, strokeWidth: 0, hover: { size: 4 } },
                grid: { show: total > 0 },
                xaxis: {
                    categories: labels,
                    tickAmount: 8,
                    axisBorder: { show: false },
                    axisTicks: { show: false },
                    labels: {
                        show: total > 0,
                        style: { colors: palette.muted, fontSize: '11px' },
                        rotate: 0,
                        hideOverlappingLabels: true
                    },
                    tooltip: { enabled: false }
                },
                yaxis: {
                    show: total > 0,
                    min: 0,
                    forceNiceScale: true,
                    tickAmount: 3,
                    decimalsInFloat: 0,
                    labels: {
                        style: { colors: palette.muted, fontSize: '11px' },
                        formatter: function (value) {
                            return Math.round(value);
                        }
                    }
                },
                tooltip: {
                    shared: true,
                    intersect: false,
                    y: {
                        formatter: function (value) {
                            return value + ' ' + (value === 1 ? 'событие' : 'событий');
                        }
                    }
                }
            });
            createOrUpdate('activity', element, options);
        }).catch(function () {
            element.innerHTML = '<div class="md-dash-chart__fallback">Не удалось загрузить библиотеку графиков</div>';
        });
    }

    function renderCycles(data) {
        state.cycles = data;
        var element = container('cycles');
        if (!element || !data) {
            return;
        }
        var counts = data.counts || {};
        var total = Number(counts.total) || 0;
        if (total === 0) {
            if (charts.cycles) {
                charts.cycles.instance.destroy();
                delete charts.cycles;
            }
            element.innerHTML = '<div class="md-dash-chart__fallback">Нет данных о циклах</div>';
            return;
        }
        loadApex().then(function () {
            if (!document.body.contains(element)) {
                return;
            }
            var palette = colors();
            var running = Number(counts.running) || 0;
            var broken = (Number(counts.stopped) || 0) + (Number(counts.offline) || 0);
            var disabled = Number(counts.disabled) || 0;
            var series = [running, broken, disabled];
            var labels = ['Работают', 'Не работают', 'Отключены'];
            var chartColors = [palette.success, palette.danger, palette.neutral];
            if (disabled === 0) {
                series = series.slice(0, 2);
                labels = labels.slice(0, 2);
                chartColors = chartColors.slice(0, 2);
            }
            var options = merge(baseOptions(), {
                chart: {
                    type: 'donut',
                    height: 218
                },
                series: series,
                labels: labels,
                colors: chartColors,
                stroke: {
                    width: 2,
                    colors: [palette.surface]
                },
                plotOptions: {
                    pie: {
                        expandOnClick: false,
                        donut: {
                            size: '74%',
                            labels: {
                                show: true,
                                name: {
                                    show: true,
                                    fontSize: '12px',
                                    color: palette.muted,
                                    offsetY: -4
                                },
                                value: {
                                    show: true,
                                    fontSize: '24px',
                                    fontWeight: 600,
                                    color: palette.text,
                                    offsetY: 2,
                                    formatter: function (value) {
                                        return value;
                                    }
                                },
                                total: {
                                    show: true,
                                    showAlways: true,
                                    label: 'Всего',
                                    fontSize: '12px',
                                    color: palette.muted,
                                    formatter: function () {
                                        return String(total);
                                    }
                                }
                            }
                        }
                    }
                },
                tooltip: {
                    y: {
                        formatter: function (value) {
                            return value + ' ' + (value === 1 ? 'цикл' : 'циклов');
                        }
                    }
                }
            });
            createOrUpdate('cycles', element, options);
        }).catch(function () {
            element.innerHTML = '<div class="md-dash-chart__fallback">Не удалось загрузить библиотеку графиков</div>';
        });
    }

    function renderResources(data) {
        state.resources = data;
        var element = container('resources');
        if (!element || !data) {
            return;
        }
        loadApex().then(function () {
            if (!document.body.contains(element)) {
                return;
            }
            var palette = colors();
            var cpu = data.cpu && data.cpu.percent !== null && typeof data.cpu.percent !== 'undefined'
                ? Number(data.cpu.percent) : 0;
            var memory = data.memory && data.memory.percent !== null && typeof data.memory.percent !== 'undefined'
                ? Number(data.memory.percent) : 0;
            var disk = data.disk && data.disk.percent !== null && typeof data.disk.percent !== 'undefined'
                ? Number(data.disk.percent) : 0;
            var options = merge(baseOptions(), {
                chart: {
                    type: 'radialBar',
                    height: 238
                },
                series: [cpu, memory, disk],
                labels: ['CPU', 'Память', 'Диск'],
                colors: [palette.primary, palette.info, palette.warning],
                plotOptions: {
                    radialBar: {
                        hollow: { size: '32%' },
                        track: {
                            background: palette.surface3,
                            strokeWidth: '100%',
                            margin: 7
                        },
                        dataLabels: {
                            name: { show: false },
                            value: { show: false },
                            total: {
                                show: true,
                                label: 'Ресурсы',
                                fontSize: '11px',
                                color: palette.muted,
                                formatter: function () {
                                    return 'сервер';
                                }
                            }
                        }
                    }
                },
                stroke: { lineCap: 'round' },
                tooltip: {
                    y: {
                        formatter: function (value) {
                            return value + '%';
                        }
                    }
                }
            });
            createOrUpdate('resources', element, options);
        }).catch(function () {
            element.innerHTML = '<div class="md-dash-chart__fallback">Не удалось загрузить библиотеку графиков</div>';
        });
    }

    function redrawAll() {
        Object.keys(charts).forEach(function (key) {
            if (charts[key] && charts[key].instance) {
                charts[key].instance.destroy();
            }
        });
        charts = {};
        if (state.activity) {
            renderActivity(state.activity);
        }
        if (state.cycles) {
            renderCycles(state.cycles);
        }
        if (state.resources) {
            renderResources(state.resources);
        }
    }

    function observeTheme() {
        if (themeObserver || !window.MutationObserver) {
            return;
        }
        themeObserver = new MutationObserver(function (mutations) {
            var changed = false;
            mutations.forEach(function (mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'data-bs-theme') {
                    changed = true;
                }
            });
            if (changed) {
                redrawAll();
            }
        });
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
    }

    function destroy() {
        Object.keys(charts).forEach(function (key) {
            if (charts[key] && charts[key].instance) {
                charts[key].instance.destroy();
            }
        });
        charts = {};
        state = { activity: null, cycles: null, resources: null };
    }

    observeTheme();

    window.MDJDashboardCharts = {
        renderActivity: renderActivity,
        renderCycles: renderCycles,
        renderResources: renderResources,
        destroy: destroy
    };
})(window, document);
