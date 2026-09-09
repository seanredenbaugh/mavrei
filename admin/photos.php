<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
verify_csrf();

$propertyId = filter_input(INPUT_POST, 'property_id', FILTER_VALIDATE_INT) ?: 0;
$stmt = db()->prepare('SELECT id, slug, featured_photo FROM properties WHERE id = ?');
$stmt->execute([$propertyId]);
$property = $stmt->fetch();
if (!$property) { http_response_code(404); exit('Property not found.'); }
$action = (string)($_POST['action'] ?? 'upload');

if ($action === 'upload') {
    $files = $_FILES['photos'] ?? null;
    if ($files && is_array($files['name'])) {
        $allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp', 'image/gif'=>'gif'];
        $directory = APP_ROOT . '/uploads/properties/' . $property['slug'];
        if (!is_dir($directory)) mkdir($directory, 0755, true);
        $orderStmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM property_photos WHERE property_id = ?');
        $orderStmt->execute([$propertyId]);
        $order = (int)$orderStmt->fetchColumn();
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        foreach ($files['name'] as $index => $originalName) {
            if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($files['size'][$index] ?? 0) > 15 * 1024 * 1024) continue;
            $tmp = $files['tmp_name'][$index];
            $mime = $finfo->file($tmp);
            $extension = $allowed[$mime] ?? null;
            if (!$extension || !getimagesize($tmp)) continue;
            $filename = bin2hex(random_bytes(12)) . '.' . $extension;
            if (!move_uploaded_file($tmp, $directory . '/' . $filename)) continue;
            $path = 'uploads/properties/' . $property['slug'] . '/' . $filename;
            db()->prepare('INSERT INTO property_photos(property_id,file_path,alt_text,sort_order) VALUES (?,?,?,?)')->execute([$propertyId,$path,$originalName,++$order]);
            if (!$property['featured_photo']) {
                db()->prepare('UPDATE properties SET featured_photo=? WHERE id=?')->execute([$path,$propertyId]);
                $property['featured_photo'] = $path;
            }
        }
    }
} elseif (in_array($action, ['feature','delete'], true)) {
    $photoId = filter_input(INPUT_POST, 'photo_id', FILTER_VALIDATE_INT) ?: 0;
    $photoStmt = db()->prepare('SELECT id,file_path FROM property_photos WHERE id=? AND property_id=?');
    $photoStmt->execute([$photoId,$propertyId]);
    $photo = $photoStmt->fetch();
    if ($photo && $action === 'feature') {
        db()->prepare('UPDATE properties SET featured_photo=? WHERE id=?')->execute([$photo['file_path'],$propertyId]);
    }
    if ($photo && $action === 'delete') {
        $uploadsRoot = realpath(APP_ROOT . '/uploads/properties');
        $target = realpath(APP_ROOT . '/' . $photo['file_path']);
        if ($uploadsRoot && $target && str_starts_with($target, $uploadsRoot . DIRECTORY_SEPARATOR)) unlink($target);
        db()->prepare('DELETE FROM property_photos WHERE id=? AND property_id=?')->execute([$photoId,$propertyId]);
        if ($property['featured_photo'] === $photo['file_path']) {
            $next = db()->prepare('SELECT file_path FROM property_photos WHERE property_id=? ORDER BY sort_order,id LIMIT 1');
            $next->execute([$propertyId]);
            db()->prepare('UPDATE properties SET featured_photo=? WHERE id=?')->execute([$next->fetchColumn() ?: null,$propertyId]);
        }
    }
}
header('Location: ' . base_url('admin/property.php?id=' . $propertyId . '&photos=1'));
