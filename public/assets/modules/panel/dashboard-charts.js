(function (window, document) {
    'use strict';

    // 2026-10-24 — события по 10 минут; повторный partLoad использует прежний runtime.
    if (window.MDJDashboardCharts) return;

    /**
     * Dashboard charts.
     *
     * ApexCharts is loaded lazily through the shared MDJCharts runtime, so
     * pages without charts never download the chart bundle.
     */
    var charts = {};
    var state = {
        activity: null,
        cycles: null,
        resources: null
    };
    var themeObserver = null;

    var runtime = window.MDJCharts;

    function loadApex() {
        return runtime.load();
    }

    function colors() {
        return runtime.colors();
    }

    function baseOptions() {
        return runtime.baseOptions();
    }

    function merge(target, source) {
        return runtime.merge(target, source);
    }

    function container(key) {
        return document.querySelector('[data-md-dash-chart="' + key + '"]');
    }

    function createOrUpdate(key, element, options) {
        options.chart.id = 'md-dashboard-' + key;
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
        var ApexCtor = window.ApexCharts;
        var instance = new ApexCtor(element, options);
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
            var methods = Array.isArray(data.methods) ? data.methods : [];
            var scripts = Array.isArray(data.scripts) ? data.scripts : [];
            var total = properties.reduce(function (sum, value) { return sum + Number(value || 0); }, 0)
                + methods.reduce(function (sum, value) { return sum + Number(value || 0); }, 0)
                + scripts.reduce(function (sum, value) { return sum + Number(value || 0); }, 0);
            if (data.available === false) total = 0;
            var options = merge(baseOptions(), {
                chart: {
                    type: 'area',
                    height: 270,
                    sparkline: { enabled: false }
                },
                series: total > 0 ? [
                    { name: 'Изменения свойств', data: properties },
                    { name: 'Вызовы методов', data: methods },
                    { name: 'Запуски сценариев', data: scripts }
                ] : [],
                noData: {
                    text: data.available === false ? 'Журнал событий ещё недоступен' : 'За последние 24 часа активности нет',
                    align: 'center',
                    verticalAlign: 'middle',
                    style: { color: palette.muted, fontSize: '13px' }
                },
                colors: [palette.primary, palette.success, palette.warning],
                stroke: { curve: 'straight', width: 2, dashArray: [0, 4, 2], lineCap: 'round' },
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
                    x: {
                        formatter: function (value, context) {
                            return (data.ranges || [])[context.dataPointIndex] || String(value);
                        }
                    },
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
