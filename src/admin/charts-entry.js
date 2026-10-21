/*
 * Separate Vite entry for dashboard charts.
 *
 * Built to assets/admin/charts.js and loaded on demand by the dashboard
 * chart module. Keeping ApexCharts out of the main admin bundle means
 * settings, objects and other pages never download the chart runtime.
 */
import 'apexcharts/dist/apexcharts.css';
import ApexCharts from 'apexcharts';

window.ApexCharts = ApexCharts;
