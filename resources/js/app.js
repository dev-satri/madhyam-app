import './bootstrap';

import Chart from 'chart.js/auto';
import ChartDataLabels from 'chartjs-plugin-datalabels';
import Sortable from 'sortablejs';

import '@fortawesome/fontawesome-free/css/all.min.css';

Chart.register(ChartDataLabels);

window.Chart = Chart;
window.Sortable = Sortable;
