import { Chart, registerables } from 'chart.js';

// Loaded only by pages that embed [data-chart] canvases; every chart has an
// equivalent HTML table rendered alongside it.
Chart.register(...registerables);

for (const canvas of document.querySelectorAll('canvas[data-chart]')) {
  try {
    const config = JSON.parse(canvas.dataset.chart);
    new Chart(canvas.getContext('2d'), config);
  } catch {
    // Malformed chart config: the accessible table still carries the data.
  }
}
