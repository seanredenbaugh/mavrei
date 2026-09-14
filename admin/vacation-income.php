<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

$propertyId = filter_input(INPUT_GET, 'property_id', FILTER_VALIDATE_INT) ?: 0;
$selectedMonth = (string) ($_GET['month'] ?? '');
$query = ['property_id'=>$propertyId];
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth)) $query['month'] = $selectedMonth;
header('Location: ' . base_url('admin/airbnb.php?' . http_build_query($query)) . '#income');
exit;
