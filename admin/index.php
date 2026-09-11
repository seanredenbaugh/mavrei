<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require_admin();
$properties = db()->query("SELECT id,title,property_type,status,city,state,rent_amount,nightly_rate,zillow_value,is_visible FROM properties ORDER BY status='sold', title")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Properties | Admin</title>
  <link rel="stylesheet" href="<?= e(base_url('assets/css/admin.css?v=20260911-2')) ?>">
</head>
<body>
<header class="admin-header">
  <a class="logo-home" href="<?= e(base_url()) ?>"><img src="<?= e(base_url('assets/img/mavrei-logo-transparent.png?v=20260910-3')) ?>" alt="Maverick"></a>
  <nav>
    <a href="<?= e(base_url('admin/map-settings.php')) ?>">Map settings</a>
    <a href="<?= e(base_url()) ?>">View map</a>
    <form method="post" action="<?= e(base_url('admin/logout.php')) ?>">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <button type="submit">Sign out</button>
    </form>
  </nav>
</header>
<main class="admin-wrap">
  <div class="admin-title">
    <div><p class="eyebrow">Portfolio administration</p><h1>Properties</h1></div>
    <a class="button" href="<?= e(base_url('admin/property.php')) ?>">Add property</a>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>Property</th><th>Type</th><th>Status</th><th>Income</th><th>Zillow value</th><th>Visible</th><th></th></tr></thead>
    <tbody><?php foreach ($properties as $p): ?><tr>
      <td><strong><?= e($p['title']) ?></strong><small><?= e($p['city'] . ', ' . $p['state']) ?></small></td>
      <td><?= e(type_label($p['property_type'])) ?></td>
      <td><span class="badge <?= e($p['status']) ?>"><?= e(status_label($p['status'])) ?></span></td>
      <td><?php if ($p['nightly_rate']): ?>$<?= number_format((float) $p['nightly_rate'], 2) ?>/night<?php elseif ($p['rent_amount']): ?>$<?= number_format((float) $p['rent_amount'], 2) ?>/month<?php else: ?>—<?php endif; ?></td>
      <td><?= $p['zillow_value'] ? '$' . number_format((float) $p['zillow_value']) : '—' ?></td>
      <td><?= $p['is_visible'] ? 'Yes' : 'No' ?></td>
      <td><a href="<?= e(base_url('admin/property.php?id=' . $p['id'])) ?>">Edit</a></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
</main>
</body>
</html>
