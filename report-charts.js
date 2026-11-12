/**
 * Draws the three administrative report charts.
 * reports.php supplies the figures as window.kyuReports; Chart.js is loaded before this file.
 */
(() => {
  'use strict';
  if (!window.Chart || !window.kyuReports) return;

  const data = window.kyuReports;
  const palette = { green: '#15803d', amber: '#f59e0b', blue: '#0369a1', red: '#dc2626' };
  const canvas = (id) => document.getElementById(id);

  new Chart(canvas('chartOccupancy'), {
    type: 'bar',
    data: {
      labels: data.occupancy.labels,
      datasets: [
        { label: 'Occupied beds', data: data.occupancy.occupied, backgroundColor: palette.red },
        { label: 'Unoccupied beds', data: data.occupancy.free, backgroundColor: palette.green },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } },
    },
  });

  new Chart(canvas('chartFinance'), {
    type: 'doughnut',
    data: {
      labels: ['Fees collected', 'Pending balance'],
      datasets: [{ data: data.finance, backgroundColor: [palette.green, palette.amber] }],
    },
    options: { responsive: true, maintainAspectRatio: false },
  });

  new Chart(canvas('chartMaintenance'), {
    type: 'bar',
    data: {
      labels: data.maintenance.labels,
      datasets: [
        { label: 'Pending', data: data.maintenance.pending, backgroundColor: palette.amber },
        { label: 'In progress', data: data.maintenance.in_progress, backgroundColor: palette.blue },
        { label: 'Resolved', data: data.maintenance.resolved, backgroundColor: palette.green },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
    },
  });
})();
