<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

header('Cache-Control: no-store, private');

$uploadsDirectory = APP_ROOT . '/uploads/properties';
$uploadsRoot = realpath($uploadsDirectory);
$propertyId = ($_SERVER['REQUEST_METHOD'] === 'POST'
    ? filter_input(INPUT_POST, 'property_id', FILTER_VALIDATE_INT)
    : filter_input(INPUT_GET, 'property_id', FILTER_VALIDATE_INT)) ?: 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action !== 'remove_missing' || !$propertyId) {
        http_response_code(400);
        exit('Invalid cleanup request.');
    }

    $photoStmt = db()->prepare('SELECT id,file_path,OCTET_LENGTH(image_data) AS database_bytes FROM property_photos WHERE property_id=? ORDER BY sort_order,id');
    $photoStmt->execute([$propertyId]);
    $missingIds = [];
    foreach ($photoStmt->fetchAll() as $photo) {
        if ((int) ($photo['database_bytes'] ?? 0) > 0) continue;
        $relativePath = ltrim(str_replace('\\', '/', (string) $photo['file_path']), '/');
        $resolvedPath = realpath(APP_ROOT . '/' . $relativePath);
        $insideUploads = $uploadsRoot && $resolvedPath && ($resolvedPath === $uploadsRoot || str_starts_with($resolvedPath, $uploadsRoot . DIRECTORY_SEPARATOR));
        if (!$insideUploads || !is_file($resolvedPath)) $missingIds[] = (int) $photo['id'];
    }

    if ($missingIds) {
        db()->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
            $delete = db()->prepare("DELETE FROM property_photos WHERE property_id=? AND id IN ({$placeholders})");
            $delete->execute(array_merge([$propertyId], $missingIds));

            $featuredStmt = db()->prepare('SELECT featured_photo FROM properties WHERE id=?');
            $featuredStmt->execute([$propertyId]);
            $featuredPath = $featuredStmt->fetchColumn();
            $featuredStillExists = false;
            if (is_string($featuredPath) && $featuredPath !== '') {
                $checkFeatured = db()->prepare('SELECT COUNT(*) FROM property_photos WHERE property_id=? AND file_path=?');
                $checkFeatured->execute([$propertyId, $featuredPath]);
                $featuredStillExists = (int) $checkFeatured->fetchColumn() > 0;
            }
            if (!$featuredStillExists) {
                $nextStmt = db()->prepare('SELECT file_path FROM property_photos WHERE property_id=? ORDER BY sort_order,id LIMIT 1');
                $nextStmt->execute([$propertyId]);
                db()->prepare('UPDATE properties SET featured_photo=? WHERE id=?')->execute([$nextStmt->fetchColumn() ?: null, $propertyId]);
            }
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            throw $exception;
        }
    }

    header('Location: ' . base_url('admin/photo-diagnostics.php?' . http_build_query(['property_id'=>$propertyId, 'removed'=>count($missingIds)])));
    exit;
}

$params = [];
$where = '';
if ($propertyId) {
    $where = 'WHERE p.id = ?';
    $params[] = $propertyId;
}

$stmt = db()->prepare("SELECT p.id AS property_id, p.title, p.slug, p.featured_photo,
                              ph.id AS photo_id, ph.file_path, ph.caption, ph.sort_order,
                              ph.mime_type, ph.file_size, ph.created_at,
                              OCTET_LENGTH(ph.image_data) AS database_bytes
                       FROM properties p
                       JOIN property_photos ph ON ph.property_id = p.id
                       {$where}
                       ORDER BY p.title, ph.sort_order, ph.id");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$directoryExists = is_dir($uploadsDirectory);
$directoryReadable = $directoryExists && is_readable($uploadsDirectory);
$directoryWritable = $directoryExists && is_writable($uploadsDirectory);
$directoryPermissions = $directoryExists ? substr(sprintf('%o', fileperms($uploadsDirectory)), -4) : '—';
$finfo = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
$foundCount = 0;
$decodableCount = 0;

foreach ($rows as &$row) {
    $relativePath = ltrim(str_replace('\\', '/', (string) $row['file_path']), '/');
    $databaseBytes = (int) ($row['database_bytes'] ?? 0);
    $storedInDatabase = $databaseBytes > 0;
    if ($storedInDatabase) {
        $mime = trim((string) ($row['mime_type'] ?? ''));
        $exists = true;
        $readable = true;
        $imageInfo = str_starts_with(strtolower($mime), 'image/') ? true : false;
        $size = $databaseBytes;
        $modified = !empty($row['created_at']) ? (int) strtotime((string) $row['created_at']) : 0;
        $permissions = 'Database';
    } else {
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
    }
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
    $row['storage'] = $storedInDatabase ? 'Database' : 'File';
    $row['dimensions'] = is_array($imageInfo) ? ((int) $imageInfo[0] . ' × ' . (int) $imageInfo[1]) : ($storedInDatabase && $imageInfo ? 'Valid image' : '—');
    $row['url'] = property_photo_url((int) $row['photo_id'], (string) $row['file_path']) . '&photo_check=' . ($modified ?: time());
}
unset($row);
$missingCount = count($rows) - $foundCount;

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
  <link rel="stylesheet" href="<?= e(base_url('assets/css/photo-diagnostics-v47.css?v=20261102-2')) ?>">
</head>
<body>
<header class="admin-header">
  <a class="logo-home" href="<?= e(base_url()) ?>"><img src="<?= e(base_url('assets/img/mavrei-logo-transparent.png?v=20260910-3')) ?>" alt="Maverick"></a>
  <nav><a href="<?= e(base_url('admin/')) ?>">Properties</a><?php if ($propertyId): ?><a href="<?= e(base_url('admin/property.php?id=' . $propertyId . '#property-photos')) ?>">Back to property</a><?php endif; ?><a href="<?= e(base_url()) ?>">View map</a></nav>
</header>
<main class="diagnostic-wrap">
  <div class="diagnostic-title"><div><p class="eyebrow">Server file test</p><h1>Photo diagnostics</h1><p>Testing is read-only. Cleanup runs only when you confirm it below.</p></div><button type="button" id="rerun-http">Recheck browser access</button></div>

  <?php if (isset($_GET['removed'])): ?><p class="alert success"><?= (int) $_GET['removed'] ?> missing photo record<?= (int) $_GET['removed'] === 1 ? '' : 's' ?> removed. No existing image files were deleted.</p><?php endif; ?>

  <section class="diagnostic-summary">
    <article><span>Database records</span><strong><?= count($rows) ?></strong></article>
    <article class="<?= $foundCount === count($rows) ? 'good' : 'bad' ?>"><span>Photos found</span><strong><?= $foundCount ?> / <?= count($rows) ?></strong></article>
    <article class="<?= $decodableCount === count($rows) ? 'good' : 'bad' ?>"><span>Readable images</span><strong><?= $decodableCount ?> / <?= count($rows) ?></strong></article>
    <article id="http-summary"><span>Browser access</span><strong>Checking…</strong></article>
  </section>

  <section class="folder-status <?= $directoryExists && $directoryReadable ? 'good' : 'bad' ?>">
    <strong>Uploads folder:</strong>
    <?= $directoryExists ? 'found' : 'missing' ?> ·
    <?= $directoryReadable ? 'readable' : 'not readable' ?> ·
    <?= $directoryWritable ? 'writable' : 'not writable' ?> · permissions <?= e($directoryPermissions) ?>
  </section>

  <?php if ($propertyId && $missingCount > 0): ?>
  <section class="cleanup-panel">
    <div><strong>Ready to re-upload?</strong><span>Remove <?= $missingCount ?> database record<?= $missingCount === 1 ? '' : 's' ?> whose image files are already missing. The <?= $foundCount ?> existing file<?= $foundCount === 1 ? '' : 's' ?> will be kept.</span></div>
    <form method="post" onsubmit="return confirm('Remove <?= $missingCount ?> missing photo records? Existing image files will be kept.')">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="property_id" value="<?= $propertyId ?>">
      <input type="hidden" name="action" value="remove_missing">
      <button type="submit">Remove missing records</button>
    </form>
  </section>
  <?php endif; ?>

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
          <div><dt>Storage</dt><dd><?= e($row['storage']) ?></dd></div>
          <div><dt>Photo</dt><dd><?= $row['exists'] ? 'Found' : 'Missing' ?></dd></div>
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
