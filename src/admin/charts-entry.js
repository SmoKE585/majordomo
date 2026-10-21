/*
 * Separate Vite entry for ApexCharts.
 *
 * Built to assets/admin/charts.js and loaded on demand by the shared
 * MDJCharts runtime (dashboard and the history drawer). Keeping ApexCharts
 * out of the main admin bundle means pages without charts never download
 * the chart runtime.
 */
import 'apexcharts/dist/apexcharts.css';
import ApexCharts from 'apexcharts';

window.ApexCharts = ApexCharts;
