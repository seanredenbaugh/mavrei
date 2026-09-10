<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#10151c">
  <title><?= e(config('app.name')) ?></title>
  <link rel="preconnect" href="https://tiles.openfreemap.org">
  <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@5/dist/maplibre-gl.css">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/app.css')) ?>">
</head>
<body>
  <header class="topbar">
    <a class="brand" href="<?= e(base_url()) ?>">
      <img src="<?= e(base_url('assets/img/mavrei-logo-transparent.png')) ?>" alt="Maverick Real Estate Investments">
    </a>
    <div class="topbar-actions">
      <label class="search"><span>Search</span><input id="property-search" type="search" placeholder="Address or property"></label>
      <label class="style-select"><span>Map theme</span><select id="map-style"><option value="liberty">Liberty</option><option value="positron">Light</option><option value="dark">Dark</option><option value="bright">Bright</option></select></label>
      <a class="admin-link" href="<?= e(base_url('admin/')) ?>">Admin</a>
    </div>
  </header>

  <main class="dashboard">
    <section class="map-panel" aria-label="Property map">
      <div id="map"></div>
      <div class="map-stats" aria-label="Portfolio summary">
        <div><strong id="stat-total">—</strong><span>Properties</span></div>
        <div><strong id="stat-current">—</strong><span>Current</span></div>
        <div><strong id="stat-sold">—</strong><span>Sold</span></div>
      </div>
      <div class="map-legend">
        <strong>Map layers</strong>
        <label><input type="checkbox" data-filter="rented" checked><i class="dot rented"></i> Rented houses</label>
        <label><input type="checkbox" data-filter="available" checked><i class="dot available"></i> Available houses</label>
        <label><input type="checkbox" data-filter="coming_soon" checked><i class="dot coming-soon"></i> Coming soon</label>
        <label><input type="checkbox" data-filter="sold" checked><i class="dot sold"></i> Sold houses</label>
        <label><input type="checkbox" data-filter="apartment" checked><i class="dot apartment"></i> Apartments</label>
        <label><input type="checkbox" data-filter="airbnb" checked><i class="dot airbnb"></i> Airbnb</label>
      </div>
    </section>

    <aside class="property-panel">
      <div class="panel-heading"><div><p class="eyebrow">Portfolio</p><h1>Your properties</h1></div><span id="result-count"></span></div>
      <div id="property-list" class="property-list" aria-live="polite">
        <p class="loading">Loading properties…</p>
      </div>
    </aside>
  </main>

  <script>window.MAVREI = {apiUrl: <?= json_encode(base_url('api/properties.php')) ?>};</script>
  <script src="https://unpkg.com/maplibre-gl@5/dist/maplibre-gl.js"></script>
  <script src="<?= e(base_url('assets/js/map.js')) ?>" defer></script>
</body>
</html>

