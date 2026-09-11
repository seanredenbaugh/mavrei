<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

$styles = [
    'liberty' => 'Liberty',
    'positron' => 'Positron',
    'bright' => 'Bright',
    'dark' => 'Dark',
    'fiord' => 'Fiord',
    '3d' => '3D',
];
$error = '';
$saved = false;

try {
    db()->exec("CREATE TABLE IF NOT EXISTS site_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $requested = (string) ($_POST['map_style'] ?? '');
        if (!array_key_exists($requested, $styles)) {
            $error = 'Choose a valid map theme.';
        } else {
            $stmt = db()->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            $stmt->execute(['map_style', $requested]);
            $saved = true;
        }
    }
} catch (Throwable $exception) {
    error_log('Map settings failed: ' . $exception->getMessage());
    $error = 'Unable to save map settings. Please try again. If this continues, check the server error log.';
}

$current = site_setting('map_style', 'liberty');
if (!array_key_exists($current, $styles)) {
    $current = 'liberty';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Map Settings | Admin</title>
  <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@5/dist/maplibre-gl.css">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/admin.css?v=20260911-2')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/map-settings.css?v=20260911-1')) ?>">
</head>
<body>
  <header class="admin-header">
    <a class="logo-home" href="<?= e(base_url()) ?>"><img src="<?= e(base_url('assets/img/mavrei-logo-transparent.png?v=20260910-3')) ?>" alt="Maverick"></a>
    <nav><a href="<?= e(base_url('admin/')) ?>">Properties</a><a href="<?= e(base_url()) ?>">View map</a><form method="post" action="<?= e(base_url('admin/logout.php')) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button type="submit">Sign out</button></form></nav>
  </header>
  <main class="admin-wrap map-settings-wrap">
    <div class="admin-title"><div><p class="eyebrow">Portfolio administration</p><h1>Map theme</h1></div></div>
    <?php if ($saved): ?><p class="alert success">Map theme saved. The public map now uses <?= e($styles[$current]) ?>.</p><?php endif; ?>
    <?php if ($error): ?><p class="alert error"><?= e($error) ?></p><?php endif; ?>
    <div class="map-settings-grid">
      <form method="post" class="map-theme-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Theme
          <select id="map-style-preview" name="map_style">
            <?php foreach ($styles as $value => $label): ?><option value="<?= e($value) ?>" <?= $current === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </label>
        <p>Select a theme to preview it, then save it as the public map default.</p>
        <button type="submit">Save map theme</button>
      </form>
      <section class="map-preview-panel" aria-label="Map theme preview"><div id="map-preview"></div></section>
    </div>
  </main>
  <script src="https://unpkg.com/maplibre-gl@5/dist/maplibre-gl.js"></script>
  <script>
    const styleBase = 'https://tiles.openfreemap.org/styles/';
    const picker = document.querySelector('#map-style-preview');
    const preview = new maplibregl.Map({container:'map-preview',style:styleBase+picker.value,center:[-87.56,37.98],zoom:10,pitch:picker.value==='3d'?45:0});
    preview.addControl(new maplibregl.NavigationControl(),'top-right');
    picker.addEventListener('change',()=>{
      preview.setStyle(styleBase+picker.value);
      preview.easeTo({pitch:picker.value==='3d'?45:0,duration:400});
    });
  </script>
</body>
</html>
