/**
 * Plugin Chart.js global : affiche « Aucune donnée » au centre d'un graphique vide.
 * À charger APRÈS chart.umd.min.js. Auto-enregistré, sans dépendance.
 *
 * « Vide » = aucun jeu de données, aucun point, ou somme de toutes les valeurs = 0
 * (un doughnut/bar tout à zéro n'affiche rien → on remplace par un message lisible).
 */
(function () {
    'use strict';
    if (typeof Chart === 'undefined' || !Chart.register) return;

    function isEmpty(chart) {
        var datasets = (chart.data && chart.data.datasets) || [];
        if (!datasets.length) return true;
        var total = 0, points = 0;
        datasets.forEach(function (d) {
            (d.data || []).forEach(function (v) {
                var n = (v && typeof v === 'object') ? (v.y != null ? v.y : v.value) : v;
                if (n === null || n === undefined || n === '') return;
                points++;
                var num = Number(n);
                if (!isNaN(num)) total += Math.abs(num);
            });
        });
        return points === 0 || total === 0;
    }

    Chart.register({
        id: 'fronoteEmptyState',
        afterDraw: function (chart) {
            if (!isEmpty(chart)) return;
            var area = chart.chartArea;
            if (!area) return;
            var ctx = chart.ctx;
            var cx = (area.left + area.right) / 2;
            var cy = (area.top + area.bottom) / 2;
            var muted = '#94a3b8';
            try { muted = getComputedStyle(chart.canvas).getPropertyValue('--text-muted') || muted; } catch (e) {}
            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = '600 13px -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif';
            ctx.fillStyle = (muted && muted.trim()) || '#94a3b8';
            ctx.fillText('Aucune donnée', cx, cy);
            ctx.restore();
        }
    });
})();
