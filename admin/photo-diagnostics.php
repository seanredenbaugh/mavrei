<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

header('Cache-Control: no-store, private');

$propertyId = filter_input(INPUT_GET, 'property_id', FILTER_VALIDATE_INT) ?: 0;
$params = [];
$where = '';
if ($propertyId) {
    $where = 'WHERE p.id = ?';
    $params[] = $propertyId;
}

$stmt = db()->prepare("SELECT p.id AS property_id, p.title, p.slug, p.featured_photo,
                              ph.id AS photo_id, ph.file_path, ph.caption, ph.sort_order
                       FROM properties p
                       JOIN property_photos ph ON ph.property_id = p.id
                       {$where}
                       ORDER BY p.title, ph.sort_order, ph.id");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$uploadsDirectory = APP_ROOT . '/uploads/properties';
$uploadsRoot = realpath($uploadsDirectory);
$directoryExists = is_dir($uploadsDirectory);
$directoryReadable = $directoryExists && is_readable($uploadsDirectory);
$directoryWritable = $directoryExists && is_writable($uploadsDirectory);
$directoryPermissions = $directoryExists ? substr(sprintf('%o', fileperms($uploadsDirectory)), -4) : '—';
$finfo = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
$foundCount = 0;
$decodableCount = 0;

foreach ($rows as &$row) {
    $relativePath = ltrim(str_replace('\\', '/', (string) $row['file_path']), '/');
    $expectedPath = APP_ROOT . '/' . $relativePath;
    $resolvedPath = realpath($expectedPath);
    $insideUploads = $uploadsRoot && $resolvedPath && ($resolvedPath === $uploadsRoot || str_starts_with($resolvedPath, $uploadsRoot . DIRECTORY_SEPARATOR));
    $exists = $insideUploads && is_file($resolvedPath);
    $readable = $exists && is_readable($resolvedPath);
    $imageInfo = $readable ? @getimagesize($resolvedPath) : false;
    $mime = $readable && $finfo ? (string) @$finfo->file($resolvedPath) : '';
    $size = $readable ? (int) @filesize($resolvedPath) : 0;
    $modified = $readable ? (int) @filemtime($resolvedPath) : 0;
    $permissions = $exists ? substr(sprintf('%o', fileperms($resolvedPath)), -4) : '—';
    if ($exists) $foundCount++;
    if ($imageInfo) $decodableCount++;
    $row['relative_path'] = $relativePath;
    $row['exists'] = $exists;
    $row['readable'] = $readable;
    $row['decodable'] = (bool) $imageInfo;
    $row['mime'] = $mime ?: '—';
    $row['size'] = $size;
    $row['modified'] = $modified;
    $row['permissions'] = $permissions;
    $row['dimensions'] = $imageInfo ? ((int) $imageInfo[0] . ' × ' . (int) $imageInfo[1]) : '—';
    $row['url'] = base_url($relativePath) . '?photo_check=' . ($modified ?: time());
}
unset($row);

function diagnostic_bytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
    return number_format($bytes / 1048576, 1) . ' MB';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Photo diagnostics | Admin</title>
  <link rel="stylesheet" href="<?= e(base_url('assets/css/admin.css?v=20260911-2')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/photo-diagnostics-v47.css?v=20261102-1')) ?>">
</head>
<body>
<header class="admin-header">
  <a class="logo-home" href="<?= e(base_url()) ?>"><img src="<?= e(base_url('assets/img/mavrei-logo-transparent.png?v=20260910-3')) ?>" alt="Maverick"></a>
  <nav><a href="<?= e(base_url('admin/')) ?>">Properties</a><?php if ($propertyId): ?><a href="<?= e(base_url('admin/property.php?id=' . $propertyId . '#property-photos')) ?>">Back to property</a><?php endif; ?><a href="<?= e(base_url()) ?>">View map</a></nav>
</header>
<main class="diagnostic-wrap">
  <div class="diagnostic-title"><div><p class="eyebrow">Read-only server test</p><h1>Photo diagnostics</h1><p>This page does not change or delete any files.</p></div><button type="button" id="rerun-http">Recheck browser access</button></div>

  <section class="diagnostic-summary">
    <article><span>Database records</span><strong><?= count($rows) ?></strong></article>
    <article class="<?= $foundCount === count($rows) ? 'good' : 'bad' ?>"><span>Files found</span><strong><?= $foundCount ?> / <?= count($rows) ?></strong></article>
    <article class="<?= $decodableCount === count($rows) ? 'good' : 'bad' ?>"><span>Readable images</span><strong><?= $decodableCount ?> / <?= count($rows) ?></strong></article>
    <article id="http-summary"><span>Browser access</span><strong>Checking…</strong></article>
  </section>

  <section class="folder-status <?= $directoryExists && $directoryReadable ? 'good' : 'bad' ?>">
    <strong>Uploads folder:</strong>
    <?= $directoryExists ? 'found' : 'missing' ?> ·
    <?= $directoryReadable ? 'readable' : 'not readable' ?> ·
    <?= $directoryWritable ? 'writable' : 'not writable' ?> · permissions <?= e($directoryPermissions) ?>
  </section>

  <?php if (!$rows): ?><p class="empty-diagnostic">No photo records were found<?= $propertyId ? ' for this property' : '' ?>.</p><?php endif; ?>
  <div class="diagnostic-list">
  <?php foreach ($rows as $row):
      $serverOk = $row['exists'] && $row['readable'] && $row['decodable'];
      $isFeatured = $row['featured_photo'] === $row['file_path'];
  ?>
    <article class="diagnostic-photo <?= $serverOk ? 'server-good' : 'server-bad' ?>" data-photo-url="<?= e($row['url']) ?>">
      <div class="diagnostic-preview"><img src="<?= e($row['url']) ?>" alt="" loading="lazy"><?php if ($isFeatured): ?><b>Cover</b><?php endif; ?></div>
      <div class="diagnostic-info">
        <div class="diagnostic-property"><strong><?= e($row['title']) ?></strong><span>Photo #<?= (int) $row['photo_id'] ?></span></div>
        <code><?= e($row['relative_path']) ?></code>
        <dl>
          <div><dt>File</dt><dd><?= $row['exists'] ? 'Found' : 'Missing' ?></dd></div>
          <div><dt>Readable</dt><dd><?= $row['readable'] ? 'Yes' : 'No' ?></dd></div>
          <div><dt>Image data</dt><dd><?= $row['decodable'] ? 'Valid' : 'Invalid' ?></dd></div>
          <div><dt>HTTP</dt><dd class="http-result">Checking…</dd></div>
          <div><dt>Type</dt><dd><?= e($row['mime']) ?></dd></div>
          <div><dt>Dimensions</dt><dd><?= e($row['dimensions']) ?></dd></div>
          <div><dt>Size</dt><dd><?= e(diagnostic_bytes($row['size'])) ?></dd></div>
          <div><dt>Permissions</dt><dd><?= e($row['permissions']) ?></dd></div>
          <div><dt>Modified</dt><dd><?= $row['modified'] ? e(date('M j, Y g:i a', $row['modified'])) : '—' ?></dd></div>
        </dl>
        <a href="<?= e($row['url']) ?>" target="_blank" rel="noopener">Open image directly</a>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</main>
<script>
(() => {
  const photos = Array.from(document.querySelectorAll('[data-photo-url]'));
  const summary = document.querySelector('#http-summary');
  const button = document.querySelector('#rerun-http');

  const runChecks = async () => {
    button.disabled = true;
    summary.classList.remove('good', 'bad');
    summary.querySelector('strong').textContent = 'Checking…';
    let passed = 0;
    await Promise.all(photos.map(async card => {
      const result = card.querySelector('.http-result');
      result.textContent = 'Checking…';
      result.className = 'http-result';
      try {
        const response = await fetch(card.dataset.photoUrl + '&browser=' + Date.now(), {cache: 'no-store', credentials: 'same-origin'});
        const type = response.headers.get('content-type') || 'no content type';
        const ok = response.ok && type.toLowerCase().startsWith('image/');
        result.textContent = `${response.status} · ${type}`;
        result.classList.add(ok ? 'http-good' : 'http-bad');
        if (ok) passed++;
      } catch (error) {
        result.textContent = 'Request failed';
        result.classList.add('http-bad');
      }
    }));
    summary.querySelector('strong').textContent = `${passed} / ${photos.length}`;
    summary.classList.add(passed === photos.length ? 'good' : 'bad');
    button.disabled = false;
  };

  button.addEventListener('click', runChecks);
  runChecks();
})();
</script>
</body>
</html>
