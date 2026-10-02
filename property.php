<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare('SELECT * FROM properties WHERE id = ? AND (is_visible = 1 OR ? = 1)');
$stmt->execute([$id ?: 0, is_admin() ? 1 : 0]);
$property = $stmt->fetch();
if (!$property) {
    http_response_code(404);
    exit('Property not found.');
}
$photoStmt = db()->prepare('SELECT * FROM property_photos WHERE property_id = ? ORDER BY sort_order, id');
$photoStmt->execute([$property['id']]);
$photos = $photoStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($property['title']) ?> | <?= e(config('app.name')) ?></title>
  <link rel="stylesheet" href="<?= e(base_url('assets/css/app.css?v=20260910-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/property.css?v=20260911-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/property-lightbox-v39.css?v=20261001-1')) ?>">
</head>
<body class="detail-page">
  <header class="detail-header"><a href="<?= e(base_url()) ?>">← Back to map</a><a class="logo-home" href="<?= e(base_url()) ?>"><img src="<?= e(base_url('assets/img/mavrei-logo-transparent.png?v=20260910-3')) ?>" alt="Maverick Real Estate Investments"></a><?php if (is_admin()): ?><a href="<?= e(base_url('admin/property.php?id=' . $property['id'])) ?>">Edit property</a><?php else: ?><span></span><?php endif; ?></header>
  <main class="detail-wrap">
    <div class="detail-title"><div><span class="status-pill <?= e($property['property_type'] === 'house' ? $property['status'] : $property['property_type']) ?>"><?= e(status_label($property['status'])) ?></span><p class="eyebrow"><?= e(type_label($property['property_type'])) ?></p><h1><?= e($property['title']) ?></h1><p><?= e($property['address_line1']) ?>, <?= e($property['city']) ?>, <?= e($property['state']) ?> <?= e($property['postal_code']) ?></p></div></div>
    <?php if ($photos): ?><section class="gallery"><?php foreach ($photos as $index => $photo): ?><button class="gallery-photo<?= $index === 0 ? ' primary' : '' ?>" data-full="<?= e(base_url($photo['file_path'])) ?>"><img src="<?= e(base_url($photo['file_path'])) ?>" alt="<?= e($photo['alt_text'] ?: $property['title']) ?>" loading="<?= $index > 3 ? 'lazy' : 'eager' ?>"></button><?php endforeach; ?></section><?php endif; ?>
    <section class="facts">
      <div><strong><?= e($property['bedrooms'] ?: '—') ?></strong><span>Bedrooms</span></div>
      <div><strong><?= e($property['bathrooms'] ?: '—') ?></strong><span>Bathrooms</span></div>
      <div><strong><?= $property['square_feet'] ? number_format((int)$property['square_feet']) : '—' ?></strong><span>Square feet</span></div>
      <?php if ($property['units']): ?><div><strong><?= (int)$property['units'] ?></strong><span>Units</span></div><?php endif; ?>
    </section>
    <?php if ($property['description']): ?><section class="description"><h2>About this property</h2><?= nl2br(e($property['description'])) ?></section><?php endif; ?>
  </main>
  <dialog id="lightbox" aria-label="Property photo viewer">
    <button type="button" class="lightbox-close" aria-label="Close photo viewer">×</button>
    <button type="button" class="lightbox-nav lightbox-prev" aria-label="Previous photo">‹</button>
    <img alt="Property photo">
    <button type="button" class="lightbox-nav lightbox-next" aria-label="Next photo">›</button>
    <span class="lightbox-count" aria-live="polite"></span>
  </dialog>
  <script>
  (() => {
    const box = document.querySelector('#lightbox');
    const photos = Array.from(document.querySelectorAll('.gallery-photo'));
    if (!box || !photos.length) return;

    const image = box.querySelector('img');
    const count = box.querySelector('.lightbox-count');
    const previous = box.querySelector('.lightbox-prev');
    const next = box.querySelector('.lightbox-next');
    let currentIndex = 0;

    const showPhoto = index => {
      currentIndex = (index + photos.length) % photos.length;
      const photo = photos[currentIndex];
      const thumbnail = photo.querySelector('img');
      image.src = photo.dataset.full;
      image.alt = thumbnail?.alt || 'Property photo';
      count.textContent = `${currentIndex + 1} / ${photos.length}`;
      previous.hidden = photos.length < 2;
      next.hidden = photos.length < 2;

      if (photos.length > 1) {
        const preload = new Image();
        preload.src = photos[(currentIndex + 1) % photos.length].dataset.full;
      }
    };

    photos.forEach((photo, index) => {
      photo.addEventListener('click', () => {
        showPhoto(index);
        if (!box.open) box.showModal();
      });
    });

    previous.addEventListener('click', () => showPhoto(currentIndex - 1));
    next.addEventListener('click', () => showPhoto(currentIndex + 1));
    box.querySelector('.lightbox-close').addEventListener('click', () => box.close());
    box.addEventListener('click', event => {
      if (event.target === box) box.close();
    });
    document.addEventListener('keydown', event => {
      if (!box.open || photos.length < 2) return;
      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        showPhoto(currentIndex - 1);
      } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        showPhoto(currentIndex + 1);
      }
    });
  })();
  </script>
</body></html>
