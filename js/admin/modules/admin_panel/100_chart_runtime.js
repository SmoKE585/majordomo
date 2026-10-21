(function (window, document) {
    'use strict';

    /**
     * Shared ApexCharts runtime.
     *
     * The chart library is a separate Vite chunk and is downloaded only when a
     * page actually draws a chart. Both the dashboard and the generic history
     * drawer use this loader, so the runtime is never duplicated in shell code.
     */
    var Apex = null;
    var apexPromise = null;

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

    function load() {
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

    window.MDJCharts = {
        load: load,
        colors: colors,
        baseOptions: baseOptions,
        merge: merge,
        isDark: isDark,
        cssVar: cssVar
    };
})(window, document);
