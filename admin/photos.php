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
$redirect = ['id' => $propertyId];

function photo_processor_available(): bool
{
    return class_exists('Imagick') || (function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'));
}

function normalize_jpeg(string $source, string $destination, int $maxDimension = 2400): bool
{
    if (class_exists('Imagick')) {
        try {
            $image = new Imagick();
            $image->readImage($source);
            $image->setIteratorIndex(0);
            if (method_exists($image, 'autoOrientImage')) $image->autoOrientImage();
            if (method_exists($image, 'transformImageColorspace')) $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            $image->thumbnailImage($maxDimension, $maxDimension, true, true);
            $image->setImagePage(0, 0, 0, 0);
            $image->stripImage();
            $image->setImageFormat('jpeg');
            $image->setImageCompression(Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality(86);
            $written = $image->writeImage($destination);
            $image->clear();
            $image->destroy();
            if ($written && is_file($destination) && filesize($destination) > 0) {
                @chmod($destination, 0644);
                return true;
            }
        } catch (Throwable $exception) {
            error_log('Imagick JPEG normalization failed: ' . $exception->getMessage());
        }
        if (is_file($destination)) @unlink($destination);
    }

    if (!function_exists('imagecreatefromjpeg') || !function_exists('imagejpeg')) return false;
    try {
        $image = @imagecreatefromjpeg($source);
        if (!$image) return false;

        $orientation = 1;
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($source, 'IFD0', true);
            if (is_array($exif)) $orientation = (int) ($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1);
        }
        if ($orientation === 3 || $orientation === 6 || $orientation === 8) {
            $angle = $orientation === 3 ? 180 : ($orientation === 6 ? -90 : 90);
            $rotated = @imagerotate($image, $angle, 0);
            if ($rotated) {
                imagedestroy($image);
                $image = $rotated;
            }
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 1 || $height < 1) {
            imagedestroy($image);
            return false;
        }
        $scale = min(1, $maxDimension / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$target) {
            imagedestroy($image);
            return false;
        }
        $white = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $white);
        imagecopyresampled($target, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imageinterlace($target, true);
        $written = imagejpeg($target, $destination, 86);
        imagedestroy($target);
        imagedestroy($image);
        if ($written && is_file($destination) && filesize($destination) > 0) {
            @chmod($destination, 0644);
            return true;
        }
    } catch (Throwable $exception) {
        error_log('GD JPEG normalization failed: ' . $exception->getMessage());
    }
    if (is_file($destination)) @unlink($destination);
    return false;
}

try {
  if ($action === 'upload') {
    $files = $_FILES['photos'] ?? null;
    $uploaded = 0;
    $skipped = 0;
    if ($files && is_array($files['name'] ?? null)) {
        $allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp', 'image/gif'=>'gif'];
        $directory = APP_ROOT . '/uploads/properties/' . $property['slug'];
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('The property photo folder could not be created.');
        }
        $orderStmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM property_photos WHERE property_id = ?');
        $orderStmt->execute([$propertyId]);
        $order = (int)$orderStmt->fetchColumn();
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $fileCount = count($files['name']);
        $limit = min($fileCount, 20);
        $skipped += max(0, $fileCount - $limit);
        for ($index = 0; $index < $limit; $index++) {
            $originalName = basename((string) ($files['name'][$index] ?? 'photo'));
            if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($files['size'][$index] ?? 0) <= 0 || ($files['size'][$index] ?? 0) > 15 * 1024 * 1024) { $skipped++; continue; }
            $tmp = (string) ($files['tmp_name'][$index] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) { $skipped++; continue; }
            $mime = $finfo->file($tmp);
            $extension = $allowed[$mime] ?? null;
            if (!$extension || !getimagesize($tmp)) { $skipped++; continue; }
            $filename = bin2hex(random_bytes(12)) . '.' . $extension;
            $destination = $directory . '/' . $filename;
            if ($mime === 'image/jpeg' && photo_processor_available()) {
                if (!normalize_jpeg($tmp, $destination)) { $skipped++; continue; }
            } elseif (!move_uploaded_file($tmp, $destination)) {
                $skipped++;
                continue;
            }
            $path = 'uploads/properties/' . $property['slug'] . '/' . $filename;
            db()->prepare('INSERT INTO property_photos(property_id,file_path,alt_text,sort_order) VALUES (?,?,?,?)')->execute([$propertyId,$path,substr($originalName,0,255),++$order]);
            $uploaded++;
            if (!$property['featured_photo']) {
                db()->prepare('UPDATE properties SET featured_photo=? WHERE id=?')->execute([$path,$propertyId]);
                $property['featured_photo'] = $path;
            }
        }
    }
    $redirect += ['photos'=>'uploaded', 'uploaded'=>$uploaded, 'skipped'=>$skipped];
  } elseif ($action === 'repair') {
    if (!photo_processor_available()) {
        $redirect['photos'] = 'repair_unavailable';
    } else {
        $photoStmt = db()->prepare('SELECT id,file_path FROM property_photos WHERE property_id=? ORDER BY sort_order,id');
        $photoStmt->execute([$propertyId]);
        $propertyPhotos = $photoStmt->fetchAll();
        $uploadsRoot = realpath(APP_ROOT . '/uploads/properties');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $repaired = 0;
        $failed = 0;
        $notJpeg = 0;
        foreach ($propertyPhotos as $photo) {
            $absolutePath = realpath(APP_ROOT . '/' . $photo['file_path']);
            if (!$uploadsRoot || !$absolutePath || !str_starts_with($absolutePath, $uploadsRoot . DIRECTORY_SEPARATOR) || !is_file($absolutePath)) {
                $failed++;
                continue;
            }
            if ($finfo->file($absolutePath) !== 'image/jpeg') {
                $notJpeg++;
                continue;
            }

            $newFilename = bin2hex(random_bytes(12)) . '.jpg';
            $newAbsolutePath = dirname($absolutePath) . '/' . $newFilename;
            if (!normalize_jpeg($absolutePath, $newAbsolutePath)) {
                $failed++;
                continue;
            }
            $pathDirectory = str_replace('\\', '/', dirname($photo['file_path']));
            $newRelativePath = ($pathDirectory === '.' ? '' : $pathDirectory . '/') . $newFilename;
            try {
                db()->beginTransaction();
                db()->prepare('UPDATE property_photos SET file_path=? WHERE id=? AND property_id=?')->execute([$newRelativePath, $photo['id'], $propertyId]);
                db()->prepare('UPDATE properties SET featured_photo=? WHERE id=? AND featured_photo=?')->execute([$newRelativePath, $propertyId, $photo['file_path']]);
                db()->commit();
                @unlink($absolutePath);
                $repaired++;
            } catch (Throwable $exception) {
                if (db()->inTransaction()) db()->rollBack();
                @unlink($newAbsolutePath);
                error_log('JPEG repair database update failed: ' . $exception->getMessage());
                $failed++;
            }
        }
        $redirect += ['photos'=>'repaired', 'repaired'=>$repaired, 'repair_failed'=>$failed, 'not_jpeg'=>$notJpeg];
    }
  } elseif ($action === 'reorder') {
    $requested = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['photo_order'] ?? ''))))));
    $orderStmt = db()->prepare('SELECT id FROM property_photos WHERE property_id=? ORDER BY sort_order,id');
    $orderStmt->execute([$propertyId]);
    $current = array_map('intval', $orderStmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($requested) !== count($current) || array_diff($requested, $current) || array_diff($current, $requested)) {
        throw new RuntimeException('The requested photo order was invalid.');
    }
    db()->beginTransaction();
    try {
        $updateOrder = db()->prepare('UPDATE property_photos SET sort_order=? WHERE id=? AND property_id=?');
        foreach ($requested as $index => $photoId) $updateOrder->execute([$index + 1, $photoId, $propertyId]);
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        throw $exception;
    }
    $redirect['photos'] = 'reordered';
  } elseif (in_array($action, ['feature','delete','move'], true)) {
    $photoId = filter_input(INPUT_POST, 'photo_id', FILTER_VALIDATE_INT) ?: 0;
    $photoStmt = db()->prepare('SELECT id,file_path FROM property_photos WHERE id=? AND property_id=?');
    $photoStmt->execute([$photoId,$propertyId]);
    $photo = $photoStmt->fetch();
    if ($photo && $action === 'feature') {
        db()->prepare('UPDATE properties SET featured_photo=? WHERE id=?')->execute([$photo['file_path'],$propertyId]);
        $redirect['photos'] = 'featured';
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
        $redirect['photos'] = 'deleted';
    }
    if ($photo && $action === 'move') {
        $direction = (string) ($_POST['direction'] ?? '');
        if (!in_array($direction, ['earlier','later'], true)) throw new RuntimeException('Unknown move direction.');
        $orderStmt = db()->prepare('SELECT id FROM property_photos WHERE property_id=? ORDER BY sort_order,id');
        $orderStmt->execute([$propertyId]);
        $orderedIds = array_map('intval', $orderStmt->fetchAll(PDO::FETCH_COLUMN));
        $position = array_search($photoId, $orderedIds, true);
        $otherPosition = $position === false ? -1 : ($direction === 'earlier' ? $position - 1 : $position + 1);
        if ($position !== false && isset($orderedIds[$otherPosition])) {
            [$orderedIds[$position], $orderedIds[$otherPosition]] = [$orderedIds[$otherPosition], $orderedIds[$position]];
            db()->beginTransaction();
            try {
                $updateOrder = db()->prepare('UPDATE property_photos SET sort_order=? WHERE id=? AND property_id=?');
                foreach ($orderedIds as $index => $orderedId) $updateOrder->execute([$index + 1, $orderedId, $propertyId]);
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) db()->rollBack();
                throw $exception;
            }
        }
        $redirect['photos'] = 'reordered';
    }
    if (!$photo) $redirect['photos'] = 'notfound';
  } else {
    throw new RuntimeException('Unknown photo action.');
  }
} catch (Throwable $exception) {
    error_log('Property photo action failed: ' . $exception->getMessage());
    $redirect['photos'] = 'error';
}
header('Location: ' . base_url('admin/property.php?' . http_build_query($redirect) . '#property-photos'));
exit;
