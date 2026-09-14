<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

function ensure_airbnb_schema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS airbnb_rehab_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        property_id INT UNSIGNED NOT NULL,
        area VARCHAR(80) NOT NULL,
        item_name VARCHAR(190) NOT NULL,
        quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
        unit_price DECIMAL(10,2) NULL,
        actual_cost DECIMAL(10,2) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'planned',
        vendor VARCHAR(120) NULL,
        purchased_on DATE NULL,
        notes TEXT NULL,
        sort_order SMALLINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_airbnb_rehab_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
        INDEX idx_airbnb_rehab (property_id, area, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $rehabColumns = db()->query('SHOW COLUMNS FROM airbnb_rehab_items')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('quantity', $rehabColumns, true)) {
        db()->exec('ALTER TABLE airbnb_rehab_items ADD quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00 AFTER item_name');
    }
    if (!in_array('unit_price', $rehabColumns, true)) {
        db()->exec('ALTER TABLE airbnb_rehab_items ADD unit_price DECIMAL(10,2) NULL AFTER quantity');
    }
    db()->exec('UPDATE airbnb_rehab_items SET quantity = 1.00 WHERE quantity IS NULL OR quantity <= 0');
    db()->exec('UPDATE airbnb_rehab_items SET unit_price = actual_cost WHERE unit_price IS NULL AND actual_cost IS NOT NULL');

    db()->exec("CREATE TABLE IF NOT EXISTS airbnb_recurring_costs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        property_id INT UNSIGNED NOT NULL,
        category VARCHAR(80) NOT NULL,
        name VARCHAR(160) NOT NULL,
        typical_amount DECIMAL(10,2) NULL,
        frequency VARCHAR(20) NOT NULL DEFAULT 'monthly',
        active TINYINT(1) NOT NULL DEFAULT 1,
        notes TEXT NULL,
        sort_order SMALLINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_airbnb_recurring_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
        INDEX idx_airbnb_recurring (property_id, active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS airbnb_monthly_expenses (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        property_id INT UNSIGNED NOT NULL,
        recurring_cost_id BIGINT UNSIGNED NULL,
        expense_month DATE NOT NULL,
        category VARCHAR(80) NOT NULL,
        description VARCHAR(190) NOT NULL,
        amount DECIMAL(10,2) NULL,
        paid_on DATE NULL,
        notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_airbnb_expense_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
        CONSTRAINT fk_airbnb_expense_recurring FOREIGN KEY (recurring_cost_id) REFERENCES airbnb_recurring_costs(id) ON DELETE SET NULL,
        UNIQUE KEY uq_airbnb_recurring_month (property_id, recurring_cost_id, expense_month),
        INDEX idx_airbnb_expense_month (property_id, expense_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS airbnb_reference_notes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        property_id INT UNSIGNED NOT NULL,
        category VARCHAR(80) NOT NULL DEFAULT 'Measurements',
        label VARCHAR(160) NOT NULL,
        note_value VARCHAR(255) NOT NULL,
        sort_order SMALLINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_airbnb_note_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
        INDEX idx_airbnb_note (property_id, category, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function nullable_money(string $key): ?string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    return $value !== '' && is_numeric($value) && (float) $value >= 0 ? number_format((float) $value, 2, '.', '') : null;
}

function nullable_date(string $key): ?string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : null;
}

function seed_first_airbnb(int $propertyId): void
{
    $airbnbCount = (int) db()->query("SELECT COUNT(*) FROM properties WHERE property_type = 'airbnb'")->fetchColumn();
    $existing = db()->prepare('SELECT COUNT(*) FROM airbnb_rehab_items WHERE property_id = ?');
    $existing->execute([$propertyId]);
    if ($airbnbCount !== 1 || (int) $existing->fetchColumn() > 0 || site_setting('airbnb_sheet_imported', '') !== '') return;

    $rehab = [
        ['Demo', 'Remove all carpet'], ['Demo', 'Main bedroom wall sconces'], ['Demo', 'Main bedroom ceiling fan'],
        ['Demo', 'Wallpaper master bath'], ['Demo', 'Bath medicine cabinets'], ['Demo', 'Remove bath caulking'],
        ['Demo', 'Fill all holes in walls'], ['Demo', 'Master bath wallpaper'], ['Demo', 'Living room curtains'],
        ['Demo', 'Bathroom toilet paper and towel holders'], ['Demo', 'Dining chandelier'], ['Demo', 'Can lights throughout'],
        ['Demo', 'Kitchen cabinet hardware'],
        ['Master Bath', 'Paint walls'], ['Master Bath', 'New mirror above sink'], ['Master Bath', 'New lights above vanity'],
        ['Master Bath', 'New vanity hardware'], ['Master Bath', 'New toilet paper and towel holders'],
        ['Bedroom 2', 'Paint walls'], ['Bedroom 2', 'New LVP floor'], ['Bedroom 2', 'New can lights x2'],
        ['Bedroom 2', 'New bed frame'], ['Bedroom 2', 'New mattress'], ['Bedroom 2', 'New linens'],
        ['Bedroom 2', 'New dresser'], ['Bedroom 2', 'New nightstands x2'], ['Bedroom 2', 'New closet hangers'],
        ['Main Hall Bath', 'Paint walls'], ['Main Hall Bath', 'New vanity hardware'], ['Main Hall Bath', 'New mirror above sink'],
        ['Main Hall Bath', 'New lights above vanity'], ['Main Hall Bath', 'Caulk tub'],
        ['Main Bedroom', 'Paint walls'], ['Main Bedroom', 'New LVP floor'], ['Main Bedroom', 'New ceiling fan with light'],
        ['Main Bedroom', 'New can light x1'], ['Main Bedroom', 'New bed frame'], ['Main Bedroom', 'New mattress'],
        ['Main Bedroom', 'New linens'], ['Main Bedroom', 'New dresser'], ['Main Bedroom', 'New nightstands x2'],
        ['Main Bedroom', 'New closet hangers'], ['Main Bedroom', 'New wall sconces'],
        ['Living / Dining Room', 'Paint walls'], ['Living / Dining Room', 'Clean ceiling fan'],
        ['Living / Dining Room', 'New can lights x2'], ['Living / Dining Room', 'New chandelier'],
        ['Living / Dining Room', 'New sliding door curtains'], ['Living / Dining Room', 'Trim around electrical panel'],
        ['Living / Dining Room', 'New area rug'], ['Living / Dining Room', 'New couch'],
        ['Living / Dining Room', 'New chair or two'], ['Living / Dining Room', 'New coffee table'],
        ['Living / Dining Room', 'New dining table and chairs'], ['Living / Dining Room', 'New TV'],
        ['Living / Dining Room', 'Console table under TV'], ['Living / Dining Room', 'Build closet shelves in living room closet'],
        ['Living / Dining Room', 'Buffet table in front of mirrors'], ['Living / Dining Room', 'Fake plants in corners'],
        ['Living / Dining Room', 'Bookshelf and books'],
        ['Breakfast Nook', 'Paint walls'], ['Breakfast Nook', 'New small table and chairs'],
        ['Balcony', 'Wyze camera'], ['Balcony', 'Thompson water seal'], ['Balcony', 'Bistro table and chairs'],
        ['Balcony', 'Fake plants'], ['Balcony', 'Exterior light'],
        ['Kitchen', 'Paint walls'], ['Kitchen', 'Paint cabinets'], ['Kitchen', 'New cabinet hardware'],
        ['Kitchen', 'New appliances'], ['Kitchen', 'New ceiling lights x2'],
        ['Entryway', 'Wyze door lock'], ['Entryway', 'Wyze camera'], ['Entryway', 'Paint front door'],
        ['Entryway', 'New can light'], ['Entryway', 'Wreath'], ['Entryway', 'Sign'],
        ['Decor', 'Paintings by Donna'], ['Decor', 'Lamps'], ['Decor', 'Liquor cabinet', null, 25],
        ['Other', 'Fire extinguisher'], ['Other', 'WiFi thermostat'], ['Other', 'Smoke detectors'],
        ['Other', 'Washer and dryer', null, 200],
    ];
    $recurring = [
        ['Utilities', 'Internet / WiFi', 30], ['Utilities', 'CenterPoint', null], ['Association', 'HOA', 255],
        ['Insurance', 'State Farm insurance', 30], ['Taxes', 'Property taxes', 30], ['Financing', 'Loan payment', null],
    ];
    $notes = [
        ['Paint', 'Wall color', 'Requisite Gray - Sherwin-Williams'],
        ['Measurements', 'Toilets', 'Round'], ['Measurements', 'Electrical panel box', '16 x 24 in'],
        ['Measurements', 'Ceiling height', '8 ft'], ['Measurements', 'Main bedroom', '15 x 12 ft'],
        ['Measurements', 'Bedroom 2', '11 x 14 ft'], ['Measurements', 'Living room', '20 x 12 ft'],
        ['Measurements', 'Dining-area mirror', '10 ft wide'], ['Measurements', 'Couch wall', '18 ft'],
        ['Measurements', 'Bath medicine cabinets', '36 x 36 in (both)'],
        ['Measurements', 'Bathroom vanities', '36 x 22 in (both)'], ['Measurements', 'Breakfast nook', '7 x 8 ft'],
        ['Measurements', 'Balcony', '4.5 x 13 ft'], ['Measurements', 'TV wall', '93 in'],
        ['Measurements', 'Bedroom doors', '28 in x2'], ['Measurements', 'Master-bath door', '24 in'],
        ['Measurements', 'Sliding door', '100 in outside trim-to-trim; 82 in top-to-floor'],
    ];

    db()->beginTransaction();
    try {
        $stmt = db()->prepare('INSERT INTO airbnb_rehab_items (property_id, area, item_name, quantity, unit_price, actual_cost, status, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($rehab as $order => $item) {
            $actual = $item[3] ?? null;
            $stmt->execute([$propertyId, $item[0], $item[1], 1, $actual, $actual, $actual === null ? 'planned' : 'purchased', $order + 1]);
        }
        $stmt = db()->prepare('INSERT INTO airbnb_recurring_costs (property_id, category, name, typical_amount, frequency, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($recurring as $order => $item) $stmt->execute([$propertyId, $item[0], $item[1], $item[2], 'monthly', $order + 1]);
        $stmt = db()->prepare('INSERT INTO airbnb_reference_notes (property_id, category, label, note_value, sort_order) VALUES (?, ?, ?, ?, ?)');
        foreach ($notes as $order => $item) $stmt->execute([$propertyId, $item[0], $item[1], $item[2], $order + 1]);
        $stmt = db()->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute(['airbnb_sheet_imported', (string) $propertyId]);
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        throw $exception;
    }
}

ensure_airbnb_schema();
$propertyId = filter_input(INPUT_GET, 'property_id', FILTER_VALIDATE_INT) ?: 0;
if (!$propertyId) {
    $propertyId = (int) db()->query("SELECT id FROM properties WHERE property_type = 'airbnb' ORDER BY id LIMIT 1")->fetchColumn();
}
$stmt = db()->prepare("SELECT * FROM properties WHERE id = ? AND property_type = 'airbnb'");
$stmt->execute([$propertyId]);
$property = $stmt->fetch();
if (!$property) { http_response_code(404); exit('Vacation rental property not found.'); }

$error = '';
try {
    seed_first_airbnb($propertyId);
} catch (Throwable $exception) {
    error_log('Airbnb sheet import failed: ' . $exception->getMessage());
    $error = 'The spreadsheet data could not be imported automatically.';
}

$selectedMonth = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth)) $selectedMonth = date('Y-m');
$monthDate = $selectedMonth . '-01';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_rehab') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
            $area = substr(trim((string) ($_POST['area'] ?? '')), 0, 80);
            $name = substr(trim((string) ($_POST['item_name'] ?? '')), 0, 190);
            $status = (string) ($_POST['status'] ?? 'planned');
            if ($area === '' || $name === '' || !in_array($status, ['planned','in_progress','purchased','completed','skipped'], true)) throw new RuntimeException('Enter an area, item, and valid status.');
            $quantityInput = trim((string) ($_POST['quantity'] ?? '1'));
            if (!is_numeric($quantityInput) || (float) $quantityInput <= 0) throw new RuntimeException('Quantity must be greater than zero.');
            $quantity = number_format((float) $quantityInput, 2, '.', '');
            $unitPrice = nullable_money('unit_price');
            $totalCost = $unitPrice === null ? null : number_format((float) $quantity * (float) $unitPrice, 2, '.', '');
            $values = [$area, $name, $quantity, $unitPrice, $totalCost, $status, substr(trim((string) ($_POST['vendor'] ?? '')), 0, 120) ?: null, nullable_date('purchased_on'), trim((string) ($_POST['notes'] ?? '')) ?: null];
            if ($id) {
                $values[] = $id; $values[] = $propertyId;
                db()->prepare('UPDATE airbnb_rehab_items SET area=?, item_name=?, quantity=?, unit_price=?, actual_cost=?, status=?, vendor=?, purchased_on=?, notes=? WHERE id=? AND property_id=?')->execute($values);
            } else {
                array_unshift($values, $propertyId);
                db()->prepare('INSERT INTO airbnb_rehab_items (property_id,area,item_name,quantity,unit_price,actual_cost,status,vendor,purchased_on,notes) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute($values);
            }
        } elseif ($action === 'delete_rehab') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
            db()->prepare('DELETE FROM airbnb_rehab_items WHERE id=? AND property_id=?')->execute([$id, $propertyId]);
        } elseif ($action === 'save_recurring') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
            $category = substr(trim((string) ($_POST['category'] ?? '')), 0, 80);
            $name = substr(trim((string) ($_POST['name'] ?? '')), 0, 160);
            $frequency = (string) ($_POST['frequency'] ?? 'monthly');
            if ($category === '' || $name === '' || !in_array($frequency, ['weekly','monthly','quarterly','annual'], true)) throw new RuntimeException('Enter a category, name, and valid frequency.');
            $values = [$category, $name, nullable_money('typical_amount'), $frequency, isset($_POST['active']) ? 1 : 0, trim((string) ($_POST['notes'] ?? '')) ?: null];
            if ($id) {
                $values[] = $id; $values[] = $propertyId;
                db()->prepare('UPDATE airbnb_recurring_costs SET category=?,name=?,typical_amount=?,frequency=?,active=?,notes=? WHERE id=? AND property_id=?')->execute($values);
            } else {
                array_unshift($values, $propertyId);
                db()->prepare('INSERT INTO airbnb_recurring_costs(property_id,category,name,typical_amount,frequency,active,notes) VALUES (?,?,?,?,?,?,?)')->execute($values);
            }
        } elseif ($action === 'delete_recurring') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
            db()->prepare('DELETE FROM airbnb_recurring_costs WHERE id=? AND property_id=?')->execute([$id, $propertyId]);
        } elseif ($action === 'create_month') {
            $stmt = db()->prepare("INSERT IGNORE INTO airbnb_monthly_expenses (property_id,recurring_cost_id,expense_month,category,description,amount)
                SELECT property_id,id,?,category,name,typical_amount FROM airbnb_recurring_costs WHERE property_id=? AND active=1");
            $stmt->execute([$monthDate, $propertyId]);
        } elseif ($action === 'save_expense') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
            $category = substr(trim((string) ($_POST['category'] ?? '')), 0, 80);
            $description = substr(trim((string) ($_POST['description'] ?? '')), 0, 190);
            if ($category === '' || $description === '') throw new RuntimeException('Enter an expense category and description.');
            $values = [$category, $description, nullable_money('amount'), nullable_date('paid_on'), trim((string) ($_POST['notes'] ?? '')) ?: null];
            if ($id) {
                $values[] = $id; $values[] = $propertyId; $values[] = $monthDate;
                db()->prepare('UPDATE airbnb_monthly_expenses SET category=?,description=?,amount=?,paid_on=?,notes=? WHERE id=? AND property_id=? AND expense_month=?')->execute($values);
            } else {
                db()->prepare('INSERT INTO airbnb_monthly_expenses(property_id,expense_month,category,description,amount,paid_on,notes) VALUES (?,?,?,?,?,?,?)')->execute([$propertyId,$monthDate,...$values]);
            }
        } elseif ($action === 'delete_expense') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
            db()->prepare('DELETE FROM airbnb_monthly_expenses WHERE id=? AND property_id=? AND expense_month=?')->execute([$id, $propertyId, $monthDate]);
        } elseif ($action === 'save_note') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
            $category = substr(trim((string) ($_POST['category'] ?? '')), 0, 80);
            $label = substr(trim((string) ($_POST['label'] ?? '')), 0, 160);
            $value = substr(trim((string) ($_POST['note_value'] ?? '')), 0, 255);
            if ($category === '' || $label === '' || $value === '') throw new RuntimeException('Complete all reference-note fields.');
            if ($id) db()->prepare('UPDATE airbnb_reference_notes SET category=?,label=?,note_value=? WHERE id=? AND property_id=?')->execute([$category,$label,$value,$id,$propertyId]);
            else db()->prepare('INSERT INTO airbnb_reference_notes(property_id,category,label,note_value) VALUES (?,?,?,?)')->execute([$propertyId,$category,$label,$value]);
        } elseif ($action === 'delete_note') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
            db()->prepare('DELETE FROM airbnb_reference_notes WHERE id=? AND property_id=?')->execute([$id, $propertyId]);
        }
        header('Location: ' . base_url('admin/airbnb.php?property_id=' . $propertyId . '&month=' . $selectedMonth . '&saved=1'));
        exit;
    } catch (Throwable $exception) {
        error_log('Airbnb tracker save failed: ' . $exception->getMessage());
        $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'The change could not be saved. Please try again.';
    }
}

$stmt = db()->prepare('SELECT * FROM airbnb_rehab_items WHERE property_id=? ORDER BY area,sort_order,id'); $stmt->execute([$propertyId]); $rehab = $stmt->fetchAll();
$stmt = db()->prepare('SELECT * FROM airbnb_recurring_costs WHERE property_id=? ORDER BY active DESC,sort_order,id'); $stmt->execute([$propertyId]); $recurring = $stmt->fetchAll();
$stmt = db()->prepare('SELECT * FROM airbnb_monthly_expenses WHERE property_id=? AND expense_month=? ORDER BY category,description'); $stmt->execute([$propertyId,$monthDate]); $expenses = $stmt->fetchAll();
$stmt = db()->prepare('SELECT * FROM airbnb_reference_notes WHERE property_id=? ORDER BY category,sort_order,id'); $stmt->execute([$propertyId]); $referenceNotes = $stmt->fetchAll();

$rehabActual = array_sum(array_map(fn($i)=>(float)($i['actual_cost'] ?? 0), $rehab));
$rehabDone = count(array_filter($rehab, fn($i)=>in_array($i['status'], ['purchased','completed'], true)));
$rehabRemaining = count(array_filter($rehab, fn($i)=>!in_array($i['status'], ['purchased','completed','skipped'], true)));
$monthlyBaseline = 0.0;
foreach ($recurring as $cost) if ($cost['active'] && $cost['typical_amount'] !== null) {
    $amount = (float) $cost['typical_amount'];
    $monthlyBaseline += match ($cost['frequency']) { 'weekly' => $amount * 52 / 12, 'quarterly' => $amount / 3, 'annual' => $amount / 12, default => $amount };
}
$monthActual = array_sum(array_map(fn($i)=>(float)($i['amount'] ?? 0), $expenses));
$monthPending = count(array_filter($expenses, fn($i)=>$i['amount'] === null));
$areas = array_values(array_unique(array_column($rehab, 'area')));
$statusLabels = ['planned'=>'Planned','in_progress'=>'In progress','purchased'=>'Purchased','completed'=>'Completed','skipped'=>'Skipped'];
function money(float $amount): string { return '$' . number_format($amount, 2); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Vacation rental costs | <?= e($property['title']) ?></title>
  <link rel="stylesheet" href="<?= e(base_url('assets/css/admin.css?v=20260911-2')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin.css?v=20260914-2')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v22.css?v=20260914-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v23.css?v=20260914-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v24.css?v=20260914-1')) ?>">
</head>
<body>
<header class="admin-header">
  <a class="logo-home" href="<?= e(base_url()) ?>"><img src="<?= e(base_url('assets/img/mavrei-logo-transparent.png?v=20260910-3')) ?>" alt="Maverick"></a>
  <nav><a href="<?= e(base_url('admin/')) ?>">Properties</a><a href="<?= e(base_url('admin/vacation-income.php?property_id=' . $propertyId)) ?>">Income</a><a href="<?= e(base_url('admin/property.php?id=' . $propertyId)) ?>">Property details</a><a href="<?= e(base_url()) ?>">View map</a></nav>
</header>
<main class="airbnb-wrap">
  <div class="airbnb-title"><div><p class="eyebrow">Vacation rental tracker</p><h1><?= e($property['title']) ?></h1><p><?= e($property['address_line1'] . ', ' . $property['city'] . ', ' . $property['state']) ?> · Airbnb / VRBO</p></div></div>
  <?php if (isset($_GET['saved'])): ?><p class="alert success">Changes saved.</p><?php endif; ?>
  <?php if ($error): ?><p class="alert error"><?= e($error) ?></p><?php endif; ?>

  <section class="metric-grid">
    <article><span>Rehab spent</span><strong><?= money($rehabActual) ?></strong><small><?= $rehabDone ?> of <?= count($rehab) ?> items purchased or complete</small></article>
    <article><span>Rehab progress</span><strong><?= $rehabDone ?> / <?= count($rehab) ?></strong><small><?= $rehabRemaining ?> item<?= $rehabRemaining === 1 ? '' : 's' ?> still planned or in progress</small></article>
    <article><span>Recurring monthly</span><strong><?= money($monthlyBaseline) ?></strong><small>Monthly equivalent of active costs</small></article>
    <article><span><?= e(date('F Y', strtotime($monthDate))) ?></span><strong><?= money($monthActual) ?></strong><small><?= $monthPending ?> amount<?= $monthPending === 1 ? '' : 's' ?> still pending</small></article>
  </section>

  <nav class="section-nav"><a href="#rehab">Setup & rehab</a><a href="#monthly">Monthly costs</a><a href="#reference">Property notes</a></nav>

  <section id="rehab" class="tracker-section">
    <div class="section-heading"><div><p class="eyebrow">One-time costs</p><h2>Setup and rehab</h2><p>Enter a quantity and price. The tracker calculates each cost and room total automatically.</p></div>
      <details class="add-panel"><summary>Add rehab item</summary>
        <form method="post" class="edit-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_rehab">
          <label>Area<input name="area" list="area-list" required></label><label class="wide">Item<input name="item_name" required></label>
          <label>Quantity<input type="number" step="1" min="1" name="quantity" value="1" required></label><label>Price each<input type="number" step="0.01" min="0" name="unit_price"></label>
          <label>Status<select name="status"><?php foreach ($statusLabels as $v=>$l): ?><option value="<?=e($v)?>"><?=e($l)?></option><?php endforeach; ?></select></label>
          <label>Vendor<input name="vendor"></label><label>Date<input type="date" name="purchased_on"></label><label class="wide">Notes<input name="notes"></label><button>Save item</button>
        </form>
      </details>
    </div>
    <datalist id="area-list"><?php foreach ($areas as $area): ?><option value="<?= e($area) ?>"><?php endforeach; ?></datalist>
    <?php foreach ($areas as $area): $areaItems=array_values(array_filter($rehab,fn($i)=>$i['area']===$area)); $areaTotal=array_sum(array_map(fn($i)=>(float)($i['actual_cost']??0),$areaItems)); ?>
      <div class="cost-group" data-area="<?= e($area) ?>"><h3><strong><?= e($area) ?></strong><span><?= count($areaItems) ?> items · <?= money($areaTotal) ?> total</span></h3>
        <div class="cost-table"><div class="cost-row table-head"><span>Item</span><span>Status</span><span>Qty</span><span>Price</span><span>Cost</span><span></span></div>
        <?php foreach ($areaItems as $item): ?><div class="cost-row is-<?=e($item['status'])?>">
          <span><strong><?= e($item['item_name']) ?></strong><?php if($item['vendor']||$item['purchased_on']):?><small><?=e(trim(($item['vendor']?:'').' '.($item['purchased_on']?:'')))?></small><?php endif;?></span>
          <span><i class="status-dot <?=e($item['status'])?>"></i><?=e($statusLabels[$item['status']]??$item['status'])?></span>
          <span><?= number_format((float)$item['quantity'], (float)$item['quantity'] == floor((float)$item['quantity']) ? 0 : 2) ?></span><span><?= $item['unit_price']!==null ? money((float)$item['unit_price']) : '—' ?></span><span><strong><?= $item['actual_cost']!==null ? money((float)$item['actual_cost']) : '—' ?></strong></span>
          <details class="row-actions"><summary>Edit</summary><form method="post" class="edit-grid"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_rehab"><input type="hidden" name="id" value="<?=$item['id']?>">
            <label>Area<input name="area" value="<?=e($item['area'])?>" list="area-list" required></label><label class="wide">Item<input name="item_name" value="<?=e($item['item_name'])?>" required></label>
            <label>Quantity<input type="number" step="1" min="1" name="quantity" value="<?=e((string)$item['quantity'])?>" required></label><label>Price each<input type="number" step="0.01" min="0" name="unit_price" value="<?=e((string)$item['unit_price'])?>"></label>
            <label>Status<select name="status"><?php foreach($statusLabels as $v=>$l):?><option value="<?=e($v)?>" <?=$item['status']===$v?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></label>
            <label>Vendor<input name="vendor" value="<?=e($item['vendor'])?>"></label><label>Date<input type="date" name="purchased_on" value="<?=e((string)$item['purchased_on'])?>"></label><label class="wide">Notes<input name="notes" value="<?=e($item['notes'])?>"></label><button>Save</button>
          </form><form method="post" class="delete-form" onsubmit="return confirm('Delete this rehab item?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_rehab"><input type="hidden" name="id" value="<?=$item['id']?>"><button>Delete</button></form></details>
        </div><?php endforeach; ?></div>
      </div>
    <?php endforeach; ?>
  </section>

  <section id="monthly" class="tracker-section">
    <div class="section-heading"><div><p class="eyebrow">Operating costs</p><h2>Monthly expenses</h2><p>Keep standard bills as recurring costs, then record the real amount for each month.</p></div>
      <details class="add-panel"><summary>Add recurring cost</summary><form method="post" class="edit-grid"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_recurring">
        <label>Category<input name="category" required></label><label class="wide">Name<input name="name" required></label><label>Typical amount<input type="number" step="0.01" min="0" name="typical_amount"></label>
        <label>Frequency<select name="frequency"><option>monthly</option><option>weekly</option><option>quarterly</option><option>annual</option></select></label><label class="check"><input type="checkbox" name="active" checked> Active</label><label class="wide">Notes<input name="notes"></label><button>Save recurring cost</button>
      </form></details>
    </div>
    <div class="recurring-grid"><?php foreach($recurring as $cost):?><article class="recurring-card <?=$cost['active']?'':'inactive'?>"><div><small><?=e($cost['category'])?></small><strong><?=e($cost['name'])?></strong></div><b><?= $cost['typical_amount']!==null?money((float)$cost['typical_amount']):'Amount needed' ?><small>/<?=e($cost['frequency'])?></small></b>
      <details class="row-actions"><summary>Edit</summary><form method="post" class="edit-grid"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_recurring"><input type="hidden" name="id" value="<?=$cost['id']?>"><label>Category<input name="category" value="<?=e($cost['category'])?>" required></label><label>Name<input name="name" value="<?=e($cost['name'])?>" required></label><label>Amount<input type="number" step="0.01" min="0" name="typical_amount" value="<?=e((string)$cost['typical_amount'])?>"></label><label>Frequency<select name="frequency"><?php foreach(['weekly','monthly','quarterly','annual'] as $frequency):?><option <?=$cost['frequency']===$frequency?'selected':''?>><?=e($frequency)?></option><?php endforeach;?></select></label><label class="check"><input type="checkbox" name="active" <?=$cost['active']?'checked':''?>> Active</label><label class="wide">Notes<input name="notes" value="<?=e($cost['notes'])?>"></label><button>Save</button></form><form method="post" class="delete-form" onsubmit="return confirm('Delete this recurring cost?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_recurring"><input type="hidden" name="id" value="<?=$cost['id']?>"><button>Delete</button></form></details>
    </article><?php endforeach;?></div>

    <div class="month-heading"><form method="get"><input type="hidden" name="property_id" value="<?=$propertyId?>"><label>Expense month<input type="month" name="month" value="<?=e($selectedMonth)?>" onchange="this.form.submit()"></label></form><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create_month"><button>Add recurring costs to this month</button></form></div>
    <div class="ledger"><div class="ledger-head"><span>Category</span><span>Expense</span><span>Amount</span><span>Paid</span><span></span></div>
      <?php foreach($expenses as $expense):?><div class="ledger-row"><span><?=e($expense['category'])?></span><span><strong><?=e($expense['description'])?></strong><?php if($expense['notes']):?><small><?=e($expense['notes'])?></small><?php endif;?></span><span><?= $expense['amount']!==null?money((float)$expense['amount']):'<em>Pending</em>'?></span><span><?=e($expense['paid_on']?:'—')?></span><details class="row-actions"><summary>Edit</summary><form method="post" class="edit-grid"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_expense"><input type="hidden" name="id" value="<?=$expense['id']?>"><label>Category<input name="category" value="<?=e($expense['category'])?>" required></label><label class="wide">Description<input name="description" value="<?=e($expense['description'])?>" required></label><label>Amount<input type="number" step="0.01" min="0" name="amount" value="<?=e((string)$expense['amount'])?>"></label><label>Paid date<input type="date" name="paid_on" value="<?=e((string)$expense['paid_on'])?>"></label><label class="wide">Notes<input name="notes" value="<?=e($expense['notes'])?>"></label><button>Save</button></form><form method="post" class="delete-form" onsubmit="return confirm('Delete this expense?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_expense"><input type="hidden" name="id" value="<?=$expense['id']?>"><button>Delete</button></form></details></div><?php endforeach;?>
      <?php if(!$expenses):?><p class="empty-state">No expenses recorded for this month yet.</p><?php endif;?>
    </div>
    <details class="add-panel ledger-add"><summary>Add one-time monthly expense</summary><form method="post" class="edit-grid"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_expense"><label>Category<input name="category" required></label><label class="wide">Description<input name="description" required></label><label>Amount<input type="number" step="0.01" min="0" name="amount"></label><label>Paid date<input type="date" name="paid_on"></label><label class="wide">Notes<input name="notes"></label><button>Save expense</button></form></details>
  </section>

  <section id="reference" class="tracker-section"><div class="section-heading"><div><p class="eyebrow">Quick reference</p><h2>Property notes</h2><p>Measurements, paint colors, model numbers, and other details you need while shopping.</p></div><details class="add-panel"><summary>Add reference note</summary><form method="post" class="edit-grid"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_note"><label>Category<input name="category" required></label><label>Label<input name="label" required></label><label class="wide">Value<input name="note_value" required></label><button>Save note</button></form></details></div>
    <div class="reference-grid"><?php foreach($referenceNotes as $note):?><article><small><?=e($note['category'])?></small><strong><?=e($note['label'])?></strong><span><?=e($note['note_value'])?></span><details class="row-actions"><summary>Edit</summary><form method="post" class="edit-grid"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_note"><input type="hidden" name="id" value="<?=$note['id']?>"><label>Category<input name="category" value="<?=e($note['category'])?>" required></label><label>Label<input name="label" value="<?=e($note['label'])?>" required></label><label class="wide">Value<input name="note_value" value="<?=e($note['note_value'])?>" required></label><button>Save</button></form><form method="post" class="delete-form" onsubmit="return confirm('Delete this note?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_note"><input type="hidden" name="id" value="<?=$note['id']?>"><button>Delete</button></form></details></article><?php endforeach;?></div>
  </section>
</main>
<script>
(() => {
  const prefix = 'mavrei-vacation-rental-<?= (int) $propertyId ?>:';
  const readState = key => { try { return localStorage.getItem(prefix + key); } catch (error) { return null; } };
  const saveState = (key, value) => { try { localStorage.setItem(prefix + key, value); } catch (error) {} };

  document.querySelectorAll('.tracker-section').forEach(section => {
    const heading = section.querySelector(':scope > .section-heading');
    if (!heading) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'collapse-toggle';
    const apply = (collapsed, remember = true) => {
      section.classList.toggle('is-collapsed', collapsed);
      button.setAttribute('aria-expanded', String(!collapsed));
      button.innerHTML = `<span aria-hidden="true">${collapsed ? '＋' : '−'}</span>${collapsed ? 'Expand' : 'Collapse'}`;
      if (remember) saveState('section:' + section.id, collapsed ? 'closed' : 'open');
    };
    button.addEventListener('click', () => apply(!section.classList.contains('is-collapsed')));
    heading.append(button);
    apply(readState('section:' + section.id) === 'closed', false);
  });

  document.querySelectorAll('.cost-group[data-area]').forEach(group => {
    const heading = group.querySelector(':scope > h3');
    const area = group.dataset.area || '';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'group-toggle';
    const apply = (collapsed, remember = true) => {
      group.classList.toggle('room-collapsed', collapsed);
      button.setAttribute('aria-expanded', String(!collapsed));
      button.textContent = collapsed ? '＋' : '−';
      button.setAttribute('aria-label', (collapsed ? 'Expand ' : 'Collapse ') + area);
      if (remember) saveState('room:' + area, collapsed ? 'closed' : 'open');
    };
    button.addEventListener('click', () => apply(!group.classList.contains('room-collapsed')));
    heading.append(button);
    apply(readState('room:' + area) === 'closed', false);
  });

  document.querySelectorAll('.section-nav a[href^="#"]').forEach(link => {
    link.addEventListener('click', () => {
      const section = document.querySelector(link.getAttribute('href'));
      const button = section?.querySelector(':scope > .section-heading > .collapse-toggle');
      if (section?.classList.contains('is-collapsed')) button?.click();
    });
  });
})();
</script>
</body>
</html>
