/**
 * Reporting — graphiques (Chart.js). Données : <script type="application/json" id="rp-data">.
 * - hauteur FIXE par graphique (le conteneur .rp-chart fixe la taille, plus de ratio géant) ;
 *   barres horizontales : hauteur selon le nombre de lignes ;
 * - couleurs reprises du thème (clair / sombre / liquide), mises à jour au changement de thème ;
 * - valeurs écrites au bout des barres et ligne de référence 10/20 : lisible sans survol.
 */
(function () {
    'use strict';
    if (typeof Chart === 'undefined') return;
    var node = document.getElementById('rp-data');
    if (!node) return;
    var D = {};
    try { D = JSON.parse(node.textContent || '{}'); } catch (e) { return; }

    var root = document.documentElement;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var fr = function (v, dec) { return Number(v).toLocaleString('fr-FR', { maximumFractionDigits: dec == null ? 1 : dec }); };

    function cssVar(name, fallback) {
        var v = getComputedStyle(document.body).getPropertyValue(name).trim();
        return v || fallback;
    }
    function isDark() { var t = root.getAttribute('data-theme'); return t === 'dark' || t === 'liquid'; }
    function palette() {
        var dark = isDark();
        return {
            text: cssVar('--text-secondary', dark ? '#cbd5e1' : '#6b7280'),
            strong: cssVar('--text-primary', dark ? '#f1f5f9' : '#1d1d1f'),
            grid: dark ? 'rgba(148,163,184,.14)' : 'rgba(15,23,42,.07)',
            good: dark ? '#34d399' : '#059669',
            mid: dark ? '#fbbf24' : '#d97706',
            low: dark ? '#f87171' : '#dc2626',
            blue: dark ? '#60a5fa' : '#2563eb',
            amber: dark ? '#fbbf24' : '#d97706',
            ref: dark ? 'rgba(241,245,249,.45)' : 'rgba(15,23,42,.35)'
        };
    }
    var P = palette();

    // Couleur selon le niveau (moyenne /20 ou taux %).
    function level(v, kind) {
        if (kind === 'taux') return v >= 75 ? P.good : (v >= 50 ? P.mid : P.low);
        return v >= 12 ? P.good : (v >= 10 ? P.mid : P.low);
    }

    function applyDefaults() {
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.font.size = 12;
        Chart.defaults.color = P.text;
        Chart.defaults.borderColor = P.grid;
        Chart.defaults.maintainAspectRatio = false;
        Chart.defaults.responsive = true;
        Chart.defaults.animation.duration = reduce ? 0 : 500;
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 8;
        Chart.defaults.plugins.legend.labels.boxHeight = 8;
        Chart.defaults.locale = 'fr-FR';
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 8;
        Chart.defaults.plugins.tooltip.displayColors = false;
    }
    applyDefaults();

    // Valeurs au bout des barres + ligne de référence (options.plugins.rp = { values, suffix, ref }).
    Chart.register({
        id: 'rp',
        afterDatasetsDraw: function (chart) {
            var o = chart.options.plugins.rp || {};
            var ctx = chart.ctx;
            var horiz = chart.options.indexAxis === 'y';
            if (o.ref != null) {
                var sc = chart.scales[horiz ? 'x' : 'y'];
                var a = chart.chartArea;
                if (sc && a) {
                    var px = sc.getPixelForValue(o.ref);
                    ctx.save();
                    ctx.strokeStyle = P.ref; ctx.setLineDash([4, 4]); ctx.lineWidth = 1;
                    ctx.beginPath();
                    if (horiz) { ctx.moveTo(px, a.top); ctx.lineTo(px, a.bottom); } else { ctx.moveTo(a.left, px); ctx.lineTo(a.right, px); }
                    ctx.stroke(); ctx.restore();
                }
            }
            if (!o.values) return;
            var meta = chart.getDatasetMeta(0);
            var data = chart.data.datasets[0].data;
            ctx.save();
            ctx.font = '600 11px ' + Chart.defaults.font.family;
            ctx.fillStyle = P.strong;
            ctx.textBaseline = 'middle';
            meta.data.forEach(function (el, i) {
                var v = data[i];
                if (v == null) return;
                var txt = fr(v, o.dec) + (o.suffix || '');
                if (horiz) { ctx.textAlign = 'left'; ctx.fillText(txt, el.x + 6, el.y); }
                else { ctx.textAlign = 'center'; ctx.fillText(txt, el.x, el.y - 9); }
            });
            ctx.restore();
        }
    });

    var charts = [];
    function make(id, cfg, rows) {
        var cv = document.getElementById(id);
        if (!cv) return;
        // Barres horizontales : ~30 px par ligne, bornées.
        if (rows) cv.parentNode.style.height = Math.max(150, Math.min(640, rows * 30 + 46)) + 'px';
        charts.push({ chart: new Chart(cv, cfg), refresh: cfg._refresh });
    }

    function barH(id, labels, values, opts) {
        opts = opts || {};
        var colors = function () { return values.map(function (v) { return level(v, opts.kind); }); };
        make(id, {
            type: 'bar',
            data: { labels: labels, datasets: [{ data: values, backgroundColor: colors(), borderRadius: 5, maxBarThickness: 18 }] },
            options: {
                indexAxis: 'y',
                layout: { padding: { right: 34 } },
                scales: {
                    x: { beginAtZero: true, max: opts.max, ticks: { stepSize: opts.step }, grid: { drawTicks: false } },
                    y: { grid: { display: false } }
                },
                plugins: {
                    legend: { display: false },
                    rp: { values: true, suffix: opts.suffix || '', ref: opts.ref, dec: opts.dec },
                    tooltip: { callbacks: { label: opts.tooltip || function (c) { return fr(c.raw) + (opts.suffix || ''); } } }
                }
            },
            _refresh: function (c) { c.data.datasets[0].backgroundColor = colors(); }
        }, labels.length);
    }

    // ── Vue globale ───────────────────────────────────────────────────────────────
    if (D.moyClasse) barH('chartMoyClasse', D.moyClasse.labels, D.moyClasse.values, { max: 20, step: 5, ref: 10, dec: 2 });
    if (D.reussite) barH('chartReussite', D.reussite.labels, D.reussite.values, { max: 100, step: 25, kind: 'taux', suffix: ' %', dec: 0 });
    if (D.evolution) {
        make('chartEvolution', {
            type: 'line',
            data: {
                labels: D.evolution.labels,
                datasets: [
                    { label: 'Absences', data: D.evolution.absences, borderColor: P.amber, backgroundColor: P.amber, tension: .3, pointRadius: 3, borderWidth: 2 },
                    { label: 'Retards', data: D.evolution.retards, borderColor: P.blue, backgroundColor: P.blue, tension: .3, pointRadius: 3, borderWidth: 2 }
                ]
            },
            options: {
                interaction: { mode: 'index', intersect: false },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } },
                plugins: { legend: { position: 'top', align: 'end' } }
            },
            _refresh: function (c) {
                c.data.datasets[0].borderColor = c.data.datasets[0].backgroundColor = P.amber;
                c.data.datasets[1].borderColor = c.data.datasets[1].backgroundColor = P.blue;
            }
        });
    }
    if (D.repartition) {
        var repColors = function () {
            // Tranches basses en rouge, moyennes en ambre, hautes en vert.
            return D.repartition.labels.map(function (l) {
                var lo = parseFloat(String(l)); return lo >= 12 ? P.good : (lo >= 10 ? P.mid : P.low);
            });
        };
        make('chartRepartition', {
            type: 'bar',
            data: { labels: D.repartition.labels, datasets: [{ data: D.repartition.values, backgroundColor: repColors(), borderRadius: 5, maxBarThickness: 42 }] },
            options: {
                layout: { padding: { top: 16 } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } },
                plugins: {
                    legend: { display: false },
                    rp: { values: true, dec: 0 },
                    tooltip: { callbacks: { title: function (c) { return 'Notes de ' + c[0].label; }, label: function (c) { return fr(c.raw, 0) + ' notes'; } } }
                }
            },
            _refresh: function (c) { c.data.datasets[0].backgroundColor = repColors(); }
        });
    }

    // ── Rapport de classe ─────────────────────────────────────────────────────────
    if (D.matieres) {
        var m = D.matieres;
        barH('chartMoyennes', m.labels, m.values, {
            max: 20, step: 5, ref: 10, dec: 2,
            tooltip: function (c) {
                var i = c.dataIndex;
                return ['Moyenne : ' + fr(m.values[i], 2) + '/20', 'Écart : ' + fr(m.min[i]) + ' – ' + fr(m.max[i]), m.nb[i] + ' notes'];
            }
        });
    }
    if (D.vie) {
        var vieColors = function () { return [P.amber, P.blue, P.low]; };
        make('chartVie', {
            type: 'doughnut',
            data: { labels: ['Absences', 'Retards', 'Incidents'], datasets: [{ data: D.vie, backgroundColor: vieColors(), borderWidth: 0, hoverOffset: 6 }] },
            options: { cutout: '68%', plugins: { legend: { position: 'right' } } },
            _refresh: function (c) { c.data.datasets[0].backgroundColor = vieColors(); }
        });
    }

    // Changement de thème : nouvelles couleurs, sans recharger la page.
    new MutationObserver(function () {
        P = palette();
        applyDefaults();
        charts.forEach(function (c) { if (c.refresh) c.refresh(c.chart); c.chart.update('none'); });
    }).observe(root, { attributes: true, attributeFilter: ['data-theme'] });
})();

// Jauges du classement : largeur posée en JS (pas de style inline dans le HTML).
(function () {
    var els = document.querySelectorAll('.rp-meter > span[data-w]');
    for (var i = 0; i < els.length; i++) els[i].style.width = els[i].getAttribute('data-w') + '%';
})();
