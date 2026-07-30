import './bootstrap';
import './tiptap-editor';
import './attachment-preview';

import Chart from 'chart.js/auto';
import ChartDataLabels from 'chartjs-plugin-datalabels';

import '@fortawesome/fontawesome-free/css/all.min.css';

Chart.register(ChartDataLabels);

window.Chart = Chart;
