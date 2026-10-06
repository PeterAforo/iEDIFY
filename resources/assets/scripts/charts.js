import { Chart, registerables } from 'chart.js';

// Loaded only by pages that embed [data-chart] canvases; every chart has an
// equivalent HTML table rendered alongside it.
Chart.register(...registerables);

Chart.defaults.font.family = "'Instrument Sans', 'Segoe UI', sans-serif";
Chart.defaults.color = '#4b4638';
Chart.defaults.borderColor = 'rgba(23, 61, 41, .12)';
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.boxWidth = 8;

for (const canvas of document.querySelectorAll('canvas[data-chart]')) {
  try {
    const config = JSON.parse(canvas.dataset.chart);
    new Chart(canvas.getContext('2d'), config);
  } catch {
    // Malformed chart config: the accessible table still carries the data.
  }
}
