<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$photoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$sql = 'SELECT ph.id,ph.file_path,ph.image_data,ph.mime_type,ph.file_size,p.is_visible
        FROM property_photos ph
        JOIN properties p ON p.id=ph.property_id
        WHERE ph.id=? LIMIT 1';
try {
    $stmt = db()->prepare($sql);
    $stmt->execute([$photoId]);
} catch (PDOException $exception) {
    if ((int) ($exception->errorInfo[1] ?? 0) !== 1054) throw $exception;
    $stmt = db()->prepare('SELECT ph.id,ph.file_path,NULL AS image_data,NULL AS mime_type,NULL AS file_size,p.is_visible
                           FROM property_photos ph
                           JOIN properties p ON p.id=ph.property_id
                           WHERE ph.id=? LIMIT 1');
    $stmt->execute([$photoId]);
}
$photo = $stmt->fetch();
if (!$photo || (!(int) $photo['is_visible'] && !is_admin())) {
    http_response_code(404);
    exit('Photo not found.');
}

$data = $photo['image_data'] ?? null;
if (is_resource($data)) $data = stream_get_contents($data);
$mime = trim((string) ($photo['mime_type'] ?? ''));
$filename = basename((string) $photo['file_path']);

if (!is_string($data) || $data === '') {
    $uploadsRoot = realpath(APP_ROOT . '/uploads/properties');
    $absolutePath = realpath(APP_ROOT . '/' . ltrim((string) $photo['file_path'], '/'));
    if (!$uploadsRoot || !$absolutePath || !str_starts_with($absolutePath, $uploadsRoot . DIRECTORY_SEPARATOR) || !is_file($absolutePath) || !is_readable($absolutePath)) {
        http_response_code(404);
        exit('Photo file not found.');
    }
    $data = file_get_contents($absolutePath);
    if (!is_string($data) || $data === '') {
        http_response_code(404);
        exit('Photo file not readable.');
    }
    $detector = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $detector->file($absolutePath);
    $filename = basename($absolutePath);
}

if (!str_starts_with(strtolower($mime), 'image/')) {
    http_response_code(415);
    exit('Unsupported photo type.');
}

$etag = '"photo-' . (int) $photo['id'] . '-' . strlen($data) . '"';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . strlen($data));
echo $data;
