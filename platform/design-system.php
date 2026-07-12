<?php
declare(strict_types=1);
/** Portail PLATEFORME — vitrine du Design System (cahier §31). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.dashboard.view');
$v = static fn(string $p) => $base . '/' . ltrim($p, '/') . '?v=' . (is_file(__DIR__ . '/../' . $p) ? filemtime(__DIR__ . '/../' . $p) : '1');
?>
<!doctype html>
<html lang="fr" data-theme="dark" data-theme-pref="dark">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Design System — Plateforme Fronote</title>
  <script>
  (function(){ var el=document.documentElement,s=null; try{s=localStorage.getItem('fronote_dark_mode');}catch(e){}
    var p=s||'dark', t=p; if(p==='auto'){t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}
    else if(['light','dark','liquid'].indexOf(p)<0){t='light';}
    el.setAttribute('data-theme',t); el.setAttribute('data-theme-pref',p);
    try{ el.setAttribute('data-reduce-motion',localStorage.getItem('fronote_reduce_motion')==='true'?'true':'false');
         el.setAttribute('data-reduce-transparency',localStorage.getItem('fronote_reduce_transparency')==='true'?'true':'false'); }catch(e){}
  })();
  </script>
  <link rel="stylesheet" href="../assets/lib/fontawesome/css/all.min.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($v('assets/css/design-system.css')) ?>">
  <style>
    body { margin:0; background:var(--surface-app); color:var(--text-primary); font-family:var(--font-sans); }
    .ds-page-head { display:flex; align-items:center; justify-content:space-between; padding:var(--space-4) var(--space-6); border-bottom:1px solid var(--border-light); background:var(--surface-panel); }
    .ds-page-head a { color:var(--primary); text-decoration:none; }
    .ds-main { max-width:var(--layout-content-max); margin:0 auto; padding:var(--space-6); }
  </style>
</head>
<body class="ds-platform">
  <header class="ds-page-head">
    <strong>⬢ Fronote — Design System (plateforme)</strong>
    <a href="<?= $base ?>/platform/dashboard.php">← Tableau de bord</a>
  </header>
  <main class="ds-main">
    <?php $dsScope = 'platform'; include __DIR__ . '/../templates/design_system_showcase.php'; ?>
  </main>
  <script src="<?= htmlspecialchars($v('assets/js/ui/interactions.js')) ?>" defer></script>
</body>
</html>
