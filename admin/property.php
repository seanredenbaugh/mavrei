<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$property = [];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM properties WHERE id=?');
    $stmt->execute([$id]);
    $property = $stmt->fetch() ?: [];
    if (!$property) { http_response_code(404); exit('Property not found.'); }
}

$defaults = [
    'title'=>'', 'slug'=>'', 'property_type'=>'house', 'status'=>'rented',
    'address_line1'=>'', 'address_line2'=>'', 'city'=>'Evansville', 'state'=>'IN', 'postal_code'=>'',
    'latitude'=>'', 'longitude'=>'', 'bedrooms'=>'', 'bathrooms'=>'', 'square_feet'=>'', 'units'=>'',
    'year_built'=>'', 'purchase_price'=>'', 'purchase_date'=>'', 'zillow_value'=>'', 'zillow_checked_at'=>'',
    'rent_amount'=>'', 'nightly_rate'=>'', 'sale_price'=>'', 'available_date'=>'', 'description'=>'',
    'private_notes'=>'', 'featured_photo'=>'', 'is_visible'=>1,
];
$property = array_merge($defaults, $property);
$error = '';

function nullable_number(string $key): ?string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    return $value === '' ? null : $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $types = ['house','apartment','airbnb','headquarters'];
    $statuses = ['rented','available','coming_soon','sold','active'];
    $title = trim((string) ($_POST['title'] ?? ''));
    $slugSource = trim((string) ($_POST['slug'] ?? '')) ?: $title;
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($slugSource)), '-');
    if (!$title || !in_array($_POST['property_type'] ?? '', $types, true) || !in_array($_POST['status'] ?? '', $statuses, true) || !is_numeric($_POST['latitude'] ?? '') || !is_numeric($_POST['longitude'] ?? '')) {
        $error = 'Title, type, status, latitude, and longitude are required.';
    } else {
        try {
            db()->beginTransaction();
            $data = [
                $slug, $title, $_POST['property_type'], $_POST['status'],
                trim((string) ($_POST['address_line1'] ?? '')), trim((string) ($_POST['address_line2'] ?? '')),
                trim((string) ($_POST['city'] ?? '')), strtoupper(trim((string) ($_POST['state'] ?? ''))),
                trim((string) ($_POST['postal_code'] ?? '')), $_POST['latitude'], $_POST['longitude'],
                nullable_number('bedrooms'), nullable_number('bathrooms'), nullable_number('square_feet'),
                nullable_number('units'), nullable_number('year_built'), nullable_number('purchase_price'),
                ($_POST['purchase_date'] ?? '') ?: null, nullable_number('zillow_value'),
                ($_POST['zillow_checked_at'] ?? '') ?: null, nullable_number('rent_amount'),
                nullable_number('nightly_rate'), nullable_number('sale_price'), ($_POST['available_date'] ?? '') ?: null,
                trim((string) ($_POST['description'] ?? '')), trim((string) ($_POST['private_notes'] ?? '')),
                isset($_POST['is_visible']) ? 1 : 0,
            ];
            $oldZillow = $id ? ($property['zillow_value'] ?: null) : null;
            if ($id) {
                $sql = 'UPDATE properties SET slug=?,title=?,property_type=?,status=?,address_line1=?,address_line2=?,city=?,state=?,postal_code=?,latitude=?,longitude=?,bedrooms=?,bathrooms=?,square_feet=?,units=?,year_built=?,purchase_price=?,purchase_date=?,zillow_value=?,zillow_checked_at=?,rent_amount=?,nightly_rate=?,sale_price=?,available_date=?,description=?,private_notes=?,is_visible=? WHERE id=?';
                $data[] = $id;
                db()->prepare($sql)->execute($data);
            } else {
                $sql = 'INSERT INTO properties (slug,title,property_type,status,address_line1,address_line2,city,state,postal_code,latitude,longitude,bedrooms,bathrooms,square_feet,units,year_built,purchase_price,purchase_date,zillow_value,zillow_checked_at,rent_amount,nightly_rate,sale_price,available_date,description,private_notes,is_visible) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                db()->prepare($sql)->execute($data);
                $id = (int) db()->lastInsertId();
            }
            $newZillow = nullable_number('zillow_value');
            if ($newZillow !== null && (string) $oldZillow !== (string) $newZillow) {
                db()->prepare("INSERT INTO property_value_history(property_id,value_amount,value_source,valued_on) VALUES (?,?,'zillow',?)")
                    ->execute([$id, $newZillow, ($_POST['zillow_checked_at'] ?? '') ?: date('Y-m-d')]);
            }
            db()->commit();
            header('Location: ' . base_url('admin/property.php?id=' . $id . '&saved=1'));
            exit;
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('Property save failed: ' . $exception->getMessage());
            $error = 'The property could not be saved. Please try again. If this continues, check the server error log.';
        }
    }
    $property = array_merge($property, $_POST);
}

$photos = [];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM property_photos WHERE property_id=? ORDER BY sort_order,id');
    $stmt->execute([$id]);
    $photos = $stmt->fetchAll();
}
$photoStatus = (string) ($_GET['photos'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($id ? 'Edit property' : 'Add property') ?> | Admin</title>
  <link rel="stylesheet" href="<?= e(base_url('assets/css/admin.css?v=20260911-2')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/property-photos-admin.css?v=20260930-1')) ?>">
</head>
<body>
<header class="admin-header">
  <a class="logo-home" href="<?= e(base_url()) ?>"><img src="<?= e(base_url('assets/img/mavrei-logo-transparent.png?v=20260910-3')) ?>" alt="Maverick"></a>
  <nav><a href="<?= e(base_url('admin/')) ?>">Properties</a><a href="<?= e(base_url('admin/map-settings.php')) ?>">Map settings</a><a href="<?= e(base_url()) ?>">View map</a></nav>
</header>
<main class="admin-wrap narrow">
  <div class="admin-title"><div><p class="eyebrow">Portfolio administration</p><h1><?= e($id ? 'Edit property' : 'Add property') ?></h1></div></div>
  <?php if (isset($_GET['saved'])): ?><p class="alert success">Property saved.</p><?php endif; ?>
  <?php if ($error): ?><p class="alert error"><?= e($error) ?></p><?php endif; ?>

  <form class="property-form" method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <section><h2>Identity and map</h2><div class="form-grid">
      <label class="span-2">Property name<input name="title" value="<?= e($property['title']) ?>" required></label>
      <label class="span-2">URL slug<input name="slug" value="<?= e($property['slug']) ?>"></label>
      <label>Property type<select name="property_type"><?php foreach (['house'=>'House','apartment'=>'Apartment','airbnb'=>'Airbnb','headquarters'=>'Headquarters'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $property['property_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label>Status<select name="status"><?php foreach (['rented'=>'Rented','available'=>'Available','coming_soon'=>'Coming Soon','sold'=>'Sold','active'=>'Active'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $property['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label class="span-2">Address<input name="address_line1" value="<?= e($property['address_line1']) ?>" required></label>
      <label>City<input name="city" value="<?= e($property['city']) ?>" required></label>
      <label>State<input name="state" maxlength="2" value="<?= e($property['state']) ?>" required></label>
      <label>ZIP<input name="postal_code" value="<?= e($property['postal_code']) ?>" required></label>
      <label>Latitude<input name="latitude" inputmode="decimal" value="<?= e((string) $property['latitude']) ?>" required></label>
      <label>Longitude<input name="longitude" inputmode="decimal" value="<?= e((string) $property['longitude']) ?>" required></label>
      <label class="check"><input type="checkbox" name="is_visible" <?= $property['is_visible'] ? 'checked' : '' ?>> Show on public map</label>
    </div></section>
    <section><h2>Property facts</h2><div class="form-grid">
      <label>Bedrooms<input type="number" step="0.5" name="bedrooms" value="<?= e((string) $property['bedrooms']) ?>"></label>
      <label>Bathrooms<input type="number" step="0.5" name="bathrooms" value="<?= e((string) $property['bathrooms']) ?>"></label>
      <label>Square feet<input type="number" name="square_feet" value="<?= e((string) $property['square_feet']) ?>"></label>
      <label>Units<input type="number" name="units" value="<?= e((string) $property['units']) ?>"></label>
      <label>Year built<input type="number" name="year_built" value="<?= e((string) $property['year_built']) ?>"></label>
      <label>Available date<input type="date" name="available_date" value="<?= e((string) $property['available_date']) ?>"></label>
    </div></section>
    <section><h2>Financial tracking</h2><div class="form-grid">
      <label>Monthly rent<input type="number" step="0.01" name="rent_amount" value="<?= e((string) $property['rent_amount']) ?>"></label>
      <label>Airbnb nightly rate<input type="number" step="0.01" name="nightly_rate" value="<?= e((string) $property['nightly_rate']) ?>"></label>
      <label>Purchase price<input type="number" step="0.01" name="purchase_price" value="<?= e((string) $property['purchase_price']) ?>"></label>
      <label>Purchase date<input type="date" name="purchase_date" value="<?= e((string) $property['purchase_date']) ?>"></label>
      <label>Sale price<input type="number" step="0.01" name="sale_price" value="<?= e((string) $property['sale_price']) ?>"></label>
      <label>Zillow value<input type="number" step="0.01" name="zillow_value" value="<?= e((string) $property['zillow_value']) ?>"></label>
      <label>Zillow checked date<input type="date" name="zillow_checked_at" value="<?= e((string) $property['zillow_checked_at']) ?>"></label>
    </div></section>
    <section><h2>Details and notes</h2>
      <label>Description<textarea name="description" rows="5"><?= e($property['description']) ?></textarea></label>
      <label>Private notes<textarea name="private_notes" rows="5"><?= e($property['private_notes']) ?></textarea></label>
    </section>
    <button class="button" type="submit">Save property</button>
  </form>

  <section id="property-photos" class="admin-photos photo-manager">
    <div class="photo-heading"><div><p class="eyebrow">Gallery management</p><h2>Property photos</h2></div><strong><?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?></strong></div>
    <?php if (!$id): ?>
      <p class="photo-help">Save the property first, then you can upload its photos here.</p>
    <?php else: ?>
      <?php if ($photoStatus === 'uploaded'): ?><p class="alert success"><?= (int) ($_GET['uploaded'] ?? 0) ?> photo<?= (int) ($_GET['uploaded'] ?? 0) === 1 ? '' : 's' ?> uploaded.<?php if ((int) ($_GET['skipped'] ?? 0)): ?> <?= (int) $_GET['skipped'] ?> file<?= (int) $_GET['skipped'] === 1 ? '' : 's' ?> skipped.<?php endif; ?></p><?php endif; ?>
      <?php if ($photoStatus === 'featured'): ?><p class="alert success">Cover photo updated.</p><?php endif; ?>
      <?php if ($photoStatus === 'deleted'): ?><p class="alert success">Photo deleted.</p><?php endif; ?>
      <?php if ($photoStatus === 'reordered'): ?><p class="alert success">Photo order updated.</p><?php endif; ?>
      <?php if (in_array($photoStatus, ['error','notfound'], true)): ?><p class="alert error">The photo change could not be completed.</p><?php endif; ?>

      <form class="photo-upload" method="post" enctype="multipart/form-data" action="<?= e(base_url('admin/photos.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="property_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="upload">
        <label>Select photos<input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required></label>
        <button type="submit">Upload photos</button>
        <small>JPEG, PNG, WebP, or GIF. Up to 20 at a time and 15 MB per photo; your host's total upload limit also applies.</small>
      </form>

      <?php if ($photos): ?>
        <p class="photo-help">Drag photos to reorder them, or use the arrow buttons. The cover photo appears on the map and property cards.</p>
        <div id="photo-grid" class="photo-grid">
          <?php foreach ($photos as $index => $photo): $isFeatured = $property['featured_photo'] === $photo['file_path']; ?>
            <article class="photo-item <?= $isFeatured ? 'is-featured' : '' ?>" draggable="true" data-photo-id="<?= (int) $photo['id'] ?>">
              <div class="photo-image-wrap"><img src="<?= e(base_url($photo['file_path'])) ?>" alt="<?= e($photo['alt_text'] ?: $property['title']) ?>" loading="lazy"><span class="drag-handle" title="Drag to reorder">Drag</span><?php if ($isFeatured): ?><b>Cover photo</b><?php endif; ?></div>
              <div class="photo-actions">
                <form method="post" action="<?= e(base_url('admin/photos.php')) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="property_id" value="<?= $id ?>"><input type="hidden" name="action" value="move"><input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>"><button name="direction" value="earlier" title="Move earlier" <?= $index === 0 ? 'disabled' : '' ?>>←</button><button name="direction" value="later" title="Move later" <?= $index === count($photos) - 1 ? 'disabled' : '' ?>>→</button></form>
                <?php if (!$isFeatured): ?><form method="post" action="<?= e(base_url('admin/photos.php')) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="property_id" value="<?= $id ?>"><input type="hidden" name="action" value="feature"><input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>"><button class="feature-button">Make cover</button></form><?php endif; ?>
                <form method="post" action="<?= e(base_url('admin/photos.php')) ?>" onsubmit="return confirm('Delete this photo?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="property_id" value="<?= $id ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>"><button class="delete-button">Delete</button></form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <form id="reorder-form" method="post" action="<?= e(base_url('admin/photos.php')) ?>">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="property_id" value="<?= $id ?>"><input type="hidden" name="action" value="reorder"><input type="hidden" name="photo_order" value="">
        </form>
      <?php else: ?><p class="empty-photos">No photos yet. Select one or more files above to begin the gallery.</p><?php endif; ?>
    <?php endif; ?>
  </section>
</main>
<script>
const propertyType=document.querySelector('[name="property_type"]');
const propertyStatus=document.querySelector('[name="status"]');
const syncHeadquartersStatus=()=>{if(propertyType.value==='headquarters')propertyStatus.value='active'};
propertyType.addEventListener('change',syncHeadquartersStatus);
syncHeadquartersStatus();

const photoGrid=document.querySelector('#photo-grid');
const reorderForm=document.querySelector('#reorder-form');
let draggedPhoto=null;
photoGrid?.addEventListener('dragstart',event=>{
  draggedPhoto=event.target.closest('.photo-item');
  if(!draggedPhoto)return;
  draggedPhoto.classList.add('is-dragging');
  event.dataTransfer.effectAllowed='move';
});
photoGrid?.addEventListener('dragover',event=>{
  event.preventDefault();
  const target=event.target.closest('.photo-item');
  if(!draggedPhoto||!target||target===draggedPhoto)return;
  const rect=target.getBoundingClientRect();
  const after=event.clientY>rect.top+rect.height/2||(Math.abs(event.clientY-(rect.top+rect.height/2))<rect.height/3&&event.clientX>rect.left+rect.width/2);
  photoGrid.insertBefore(draggedPhoto,after?target.nextSibling:target);
});
photoGrid?.addEventListener('drop',event=>{
  event.preventDefault();
  if(!draggedPhoto||!reorderForm)return;
  reorderForm.elements.photo_order.value=[...photoGrid.querySelectorAll('.photo-item')].map(item=>item.dataset.photoId).join(',');
  reorderForm.requestSubmit();
});
photoGrid?.addEventListener('dragend',()=>{draggedPhoto?.classList.remove('is-dragging');draggedPhoto=null});
</script>
</body>
</html>
