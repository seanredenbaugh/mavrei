<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

$sql = "SELECT p.id, p.slug, p.title, p.property_type, p.status,
               p.address_line1, p.address_line2, p.city, p.state, p.postal_code,
               p.latitude, p.longitude, p.bedrooms, p.bathrooms, p.square_feet,
               p.units, p.featured_photo,
               (SELECT COUNT(*) FROM property_photos ph WHERE ph.property_id = p.id) AS photo_count
        FROM properties p
        WHERE p.is_visible = 1
        ORDER BY p.sort_order, p.title";

$properties = db()->query($sql)->fetchAll();
foreach ($properties as &$property) {
    $property['id'] = (int) $property['id'];
    $property['latitude'] = (float) $property['latitude'];
    $property['longitude'] = (float) $property['longitude'];
    $property['photo_count'] = (int) $property['photo_count'];
    $property['detail_url'] = base_url('property.php?id=' . $property['id']);
    if ($property['featured_photo']) {
        $property['featured_photo'] = base_url($property['featured_photo']);
    }
}

echo json_encode(['properties' => $properties], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
