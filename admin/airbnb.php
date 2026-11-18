<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require_admin();
require_once APP_ROOT . '/app/vacation-income-support.php';
$rehabOnly = defined('MAVREI_REHAB_ONLY') && MAVREI_REHAB_ONLY === true;
$notesOnly = defined('MAVREI_NOTES_ONLY') && MAVREI_NOTES_ONLY === true;
$expensesOnly = defined('MAVREI_EXPENSES_ONLY') && MAVREI_EXPENSES_ONLY === true;
$incomeOnly = defined('MAVREI_INCOME_ONLY') && MAVREI_INCOME_ONLY === true;
$trackerHome = !$rehabOnly && !$notesOnly && !$expensesOnly && !$incomeOnly;

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
ensure_vacation_income_schema();
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
$monthObject = new DateTimeImmutable($monthDate);
$previousMonth = $monthObject->modify('-1 month')->format('Y-m');
$nextMonth = $monthObject->modify('+1 month')->format('Y-m');
$currentMonth = date('Y-m');
$monthLabel = $monthObject->format('F Y');
$previousMonthLabel = $monthObject->modify('-1 month')->format('M Y');
$nextMonthLabel = $monthObject->modify('+1 month')->format('M Y');
$monthPage = $incomeOnly ? 'admin/airbnb-income.php' : ($expensesOnly ? 'admin/airbnb-expenses.php' : 'admin/airbnb.php');
$previousMonthUrl = base_url($monthPage . '?' . http_build_query(['property_id'=>$propertyId, 'month'=>$previousMonth]));
$nextMonthUrl = base_url($monthPage . '?' . http_build_query(['property_id'=>$propertyId, 'month'=>$nextMonth]));
$currentMonthUrl = base_url($monthPage . '?' . http_build_query(['property_id'=>$propertyId, 'month'=>$currentMonth]));
$activePage = $rehabOnly ? 'admin/airbnb-rehab.php' : ($notesOnly ? 'admin/airbnb-notes.php' : ($expensesOnly ? 'admin/airbnb-expenses.php' : ($incomeOnly ? 'admin/airbnb-income.php' : 'admin/airbnb.php')));
$activePageQuery = ['property_id'=>$propertyId];
if ($expensesOnly || $incomeOnly) $activePageQuery['month'] = $selectedMonth;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if (vacation_income_is_action($action)) {
            $result = vacation_income_handle_post($propertyId, $action);
            $query = http_build_query(['property_id'=>$propertyId, 'month'=>$selectedMonth] + $result);
            header('Location: ' . base_url('admin/airbnb-income.php?' . $query) . '#income');
            exit;
        } elseif ($action === 'reorder_rehab') {
            $area = substr(trim((string) ($_POST['area'] ?? '')), 0, 80);
            $submittedIds = array_values(array_unique(array_filter(
                array_map('intval', explode(',', (string) ($_POST['item_order'] ?? ''))),
                static fn(int $id): bool => $id > 0
            )));
            if ($area === '' || !$submittedIds) throw new RuntimeException('The new item order was not valid.');

            $stmt = db()->prepare('SELECT id FROM airbnb_rehab_items WHERE property_id=? AND area=? ORDER BY sort_order,id');
            $stmt->execute([$propertyId, $area]);
            $storedIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));
            $expectedIds = $storedIds;
            $receivedIds = $submittedIds;
            sort($expectedIds);
            sort($receivedIds);
            if ($expectedIds !== $receivedIds) throw new RuntimeException('The item list changed before the order could be saved. Refresh and try again.');

            db()->beginTransaction();
            try {
                $update = db()->prepare('UPDATE airbnb_rehab_items SET sort_order=? WHERE id=? AND property_id=? AND area=?');
                foreach ($submittedIds as $position => $itemId) {
                    $update->execute([$position + 1, $itemId, $propertyId, $area]);
                }
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) db()->rollBack();
                throw $exception;
            }
            header('Location: ' . base_url($activePage . '?' . http_build_query($activePageQuery + ['saved'=>1])) . '#rehab');
            exit;
        } elseif ($action === 'save_rehab') {
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
                $stmt = db()->prepare('SELECT area,sort_order FROM airbnb_rehab_items WHERE id=? AND property_id=?');
                $stmt->execute([$id, $propertyId]);
                $existingItem = $stmt->fetch();
                if (!$existingItem) throw new RuntimeException('That rehab item could not be found.');
                $sortOrder = (int) $existingItem['sort_order'];
                if ($existingItem['area'] !== $area) {
                    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM airbnb_rehab_items WHERE property_id=? AND area=?');
                    $stmt->execute([$propertyId, $area]);
                    $sortOrder = (int) $stmt->fetchColumn();
                }
                $values[] = $sortOrder; $values[] = $id; $values[] = $propertyId;
                db()->prepare('UPDATE airbnb_rehab_items SET area=?, item_name=?, quantity=?, unit_price=?, actual_cost=?, status=?, vendor=?, purchased_on=?, notes=?, sort_order=? WHERE id=? AND property_id=?')->execute($values);
            } else {
                $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM airbnb_rehab_items WHERE property_id=? AND area=?');
                $stmt->execute([$propertyId, $area]);
                $sortOrder = (int) $stmt->fetchColumn();
                array_unshift($values, $propertyId);
                $values[] = $sortOrder;
                db()->prepare('INSERT INTO airbnb_rehab_items (property_id,area,item_name,quantity,unit_price,actual_cost,status,vendor,purchased_on,notes,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute($values);
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
        header('Location: ' . base_url($activePage . '?' . http_build_query($activePageQuery + ['saved'=>1])));
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
$stmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM airbnb_monthly_expenses WHERE property_id=?');
$stmt->execute([$propertyId]);
$allTimeOperatingExpenses = (float) $stmt->fetchColumn();
$stmt = db()->prepare('SELECT COALESCE(SUM(net_payout),0) FROM vacation_rental_income WHERE property_id=?');
$stmt->execute([$propertyId]);
$allTimeIncome = (float) $stmt->fetchColumn();
$allTimeExpenses = $allTimeOperatingExpenses + $rehabActual;
$allTimeCashFlow = $allTimeIncome - $allTimeExpenses;
$trackerChartData = [];
$chartYear = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options'=>['min_range'=>2000, 'max_range'=>2100]]) ?: (int) date('Y');
if ($trackerHome) {
    $chartStart = new DateTimeImmutable($chartYear . '-01-01');
    $chartAfter = $chartStart->modify('+1 year');
    $stmt = db()->prepare("SELECT DATE_FORMAT(COALESCE(payout_date,check_in),'%Y-%m') AS report_month, SUM(COALESCE(net_payout,0)) AS total FROM vacation_rental_income WHERE property_id=? AND COALESCE(payout_date,check_in)>=? AND COALESCE(payout_date,check_in)<? GROUP BY report_month");
    $stmt->execute([$propertyId, $chartStart->format('Y-m-d'), $chartAfter->format('Y-m-d')]);
    $chartIncome = [];
    foreach ($stmt->fetchAll() as $row) $chartIncome[(string) $row['report_month']] = (float) $row['total'];
    $stmt = db()->prepare("SELECT DATE_FORMAT(expense_month,'%Y-%m') AS report_month, SUM(COALESCE(amount,0)) AS total FROM airbnb_monthly_expenses WHERE property_id=? AND expense_month>=? AND expense_month<? GROUP BY report_month");
    $stmt->execute([$propertyId, $chartStart->format('Y-m-d'), $chartAfter->format('Y-m-d')]);
    $chartExpenses = [];
    foreach ($stmt->fetchAll() as $row) $chartExpenses[(string) $row['report_month']] = (float) $row['total'];
    for ($index = 0; $index < 12; $index++) {
        $month = $chartStart->modify('+' . $index . ' months');
        $key = $month->format('Y-m');
        $trackerChartData[] = ['month'=>$key, 'label'=>$month->format('M'), 'income'=>$chartIncome[$key] ?? 0, 'expenses'=>$chartExpenses[$key] ?? 0];
    }
}
$monthlyBaseline = 0.0;
foreach ($recurring as $cost) if ($cost['active'] && $cost['typical_amount'] !== null) {
    $amount = (float) $cost['typical_amount'];
    $monthlyBaseline += match ($cost['frequency']) { 'weekly' => $amount * 52 / 12, 'quarterly' => $amount / 3, 'annual' => $amount / 12, default => $amount };
}
$monthActual = array_sum(array_map(fn($i)=>(float)($i['amount'] ?? 0), $expenses));
$monthPending = count(array_filter($expenses, fn($i)=>$i['amount'] === null));
$platforms = vacation_income_platforms();
$statuses = vacation_income_statuses();
$incomeSummary = vacation_income_load($propertyId, $monthDate, $monthActual);
$incomeComparison = $incomeOnly ? vacation_income_monthly_comparison($propertyId, $selectedMonth, 6) : [];
$incomeImports = $incomeOnly ? vacation_income_import_history($propertyId) : [];
$areas = array_values(array_unique(array_column($rehab, 'area')));
$statusLabels = ['planned'=>'Planned','in_progress'=>'In progress','purchased'=>'Purchased','completed'=>'Completed','skipped'=>'Skipped'];
function money(float $amount): string { return '$' . number_format($amount, 2); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $rehabOnly ? 'Setup and rehab' : ($notesOnly ? 'Property notes' : ($expensesOnly ? 'Monthly expenses' : ($incomeOnly ? 'Income' : 'Vacation rental tracker'))) ?> | <?= e($property['title']) ?></title>
  <link rel="stylesheet" href="<?= e(base_url('assets/css/admin.css?v=20260911-2')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin.css?v=20260914-2')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v22.css?v=20260914-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v23.css?v=20260914-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v24.css?v=20260914-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v35.css?v=20261001-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v36.css?v=20261001-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v37.css?v=20261001-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v38.css?v=20261001-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v40.css?v=20261007-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/vacation-income.css?v=20260914-2')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v41.css?v=20261012-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v45.css?v=20261021-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v46.css?v=20261021-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v53.css?v=20261108-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v54.css?v=20261118-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v55.css?v=20261118-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v56.css?v=20261118-1')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/airbnb-admin-v57.css?v=20261118-1')) ?>">
</head>
<body>
<header class="admin-header">
  <a class="logo-home" href="<?= e(base_url()) ?>"><img src="<?= e(base_url('assets/img/mavrei-logo-transparent.png?v=20260910-3')) ?>" alt="Maverick"></a>
  <nav><a href="<?= e(base_url('admin/')) ?>">Properties</a><a href="<?= e(base_url('admin/airbnb.php?' . http_build_query(['property_id'=>$propertyId,'month'=>$selectedMonth]))) ?>">Costs / Income</a><a href="<?= e(base_url('admin/property.php?id=' . $propertyId)) ?>">Property details</a><a href="<?= e(base_url()) ?>">View map</a></nav>
</header>
<main class="airbnb-wrap">
  <div class="airbnb-title"><div><p class="eyebrow"><?= $rehabOnly ? 'Project archive' : ($notesOnly ? 'Quick reference' : ($expensesOnly ? 'Operating costs' : ($incomeOnly ? 'Bookings and payouts' : 'Vacation rental tracker'))) ?></p><h1><?= $rehabOnly ? 'Setup and rehab' : ($notesOnly ? 'Property notes' : ($expensesOnly ? 'Monthly expenses' : ($incomeOnly ? 'Income' : e($property['title'])))) ?></h1><p><?= ($rehabOnly || $notesOnly || $expensesOnly || $incomeOnly) ? e($property['title'] . ' · ' . $property['address_line1']) : e($property['address_line1'] . ', ' . $property['city'] . ', ' . $property['state']) . ' · Airbnb / VRBO' ?></p></div></div>
  <?php if (isset($_GET['saved'])): ?><p class="alert success">Changes saved.</p><?php endif; ?>
  <?php if ($error): ?><p class="alert error"><?= e($error) ?></p><?php endif; ?>

  <?php if (!$notesOnly && !$incomeOnly): ?><section class="metric-grid <?= (!$rehabOnly && !$expensesOnly) ? 'tracker-overview-metrics' : 'compact-metrics' ?>">
    <?php if ($rehabOnly): ?>
    <article><span>Rehab spent</span><strong><?= money($rehabActual) ?></strong><small><?= $rehabDone ?> of <?= count($rehab) ?> items purchased or complete</small></article>
    <article><span>Rehab progress</span><strong><?= $rehabDone ?> / <?= count($rehab) ?></strong><small><?= $rehabRemaining ?> item<?= $rehabRemaining === 1 ? '' : 's' ?> still planned or in progress</small></article>
    <?php elseif ($expensesOnly): ?>
    <article><span>Recurring monthly</span><strong><?= money($monthlyBaseline) ?></strong><small>Monthly equivalent of active costs</small></article>
    <article><span><?= e(date('F Y', strtotime($monthDate))) ?></span><strong><?= money($monthActual) ?></strong><small><?= $monthPending ?> amount<?= $monthPending === 1 ? '' : 's' ?> still pending</small></article>
    <?php else: ?>
    <article><span>All-time income</span><strong><?= vacation_income_display_money($allTimeIncome) ?></strong><small>Net payouts from all recorded bookings</small></article>
    <article><span>All-time expenses</span><strong><?= vacation_income_display_money($allTimeExpenses) ?></strong><small><?= vacation_income_display_money($allTimeOperatingExpenses) ?> operating + <?= vacation_income_display_money($rehabActual) ?> setup and rehab</small></article>
    <article class="<?= $allTimeCashFlow < 0 ? 'negative' : 'positive' ?>"><span>All-time cash flow</span><strong><?= vacation_income_display_money($allTimeCashFlow) ?></strong><small>Income minus all recorded expenses</small></article>
    <?php endif; ?>
  </section><?php endif; ?>

  <?php if ($rehabOnly): ?>
  <nav class="section-nav"><a href="<?= e(base_url('admin/airbnb.php?' . http_build_query(['property_id'=>$propertyId,'month'=>$selectedMonth]))) ?>">Back to costs / income</a><a href="#rehab">Setup &amp; rehab</a></nav>
  <?php elseif ($notesOnly): ?>
  <nav class="section-nav"><a href="<?= e(base_url('admin/airbnb.php?' . http_build_query(['property_id'=>$propertyId,'month'=>$selectedMonth]))) ?>">Back to costs / income</a><a href="#reference">Property notes</a></nav>
  <?php elseif ($expensesOnly): ?>
  <nav class="section-nav"><a href="<?= e(base_url('admin/airbnb.php?' . http_build_query(['property_id'=>$propertyId]))) ?>">Back to tracker</a><a href="#monthly">Monthly expenses</a></nav>
  <?php elseif ($incomeOnly): ?>
  <nav class="section-nav"><a href="<?= e(base_url('admin/airbnb.php?' . http_build_query(['property_id'=>$propertyId]))) ?>">Back to tracker</a><a href="#income">Income</a></nav>
  <?php else: ?>
  <nav class="section-nav tracker-link-menu"><a href="<?= e(base_url('admin/airbnb-expenses.php?' . http_build_query(['property_id'=>$propertyId,'month'=>$selectedMonth]))) ?>">Monthly expenses</a><a href="<?= e(base_url('admin/airbnb-income.php?' . http_build_query(['property_id'=>$propertyId,'month'=>$selectedMonth]))) ?>">Income</a><a href="<?= e(base_url('admin/airbnb-notes.php?' . http_build_query(['property_id'=>$propertyId]))) ?>">Property notes</a><a href="<?= e(base_url('admin/airbnb-rehab.php?' . http_build_query(['property_id'=>$propertyId]))) ?>">Setup &amp; rehab</a></nav>
  <?php endif; ?>

  <?php if ($trackerHome): ?>
  <section class="tracker-chart-card" aria-labelledby="tracker-chart-title">
    <div class="tracker-chart-heading"><div><p class="eyebrow">Calendar year</p><h2 id="tracker-chart-title">Income vs. expenses</h2></div><div class="tracker-chart-controls"><form method="get"><input type="hidden" name="property_id" value="<?= (int) $propertyId ?>"><label>Year<input type="number" name="year" min="2000" max="2100" value="<?= (int) $chartYear ?>" onchange="this.form.submit()"></label></form><div class="tracker-chart-legend"><span class="income-line">Income</span><span class="expense-line">Expenses</span></div></div></div>
    <div id="tracker-cashflow-chart" class="tracker-chart" data-points="<?= e(json_encode($trackerChartData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?>" role="img" aria-label="Line chart comparing monthly net income and operating expenses for calendar year <?= (int) $chartYear ?>"></div>
  </section>
  <?php endif; ?>

  <div id="tracker-sections">
  <?php if ($rehabOnly): ?>
  <section id="rehab" class="tracker-section">
    <div class="section-heading"><div><p class="eyebrow">One-time costs</p><h2>Setup and rehab</h2><p>Drag areas into your preferred order, then drag the items within each area into the order you plan to complete them.</p></div>
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
      <div class="cost-group" data-area="<?= e($area) ?>"><h3><span class="area-drag-handle" draggable="true" title="Drag to reorder area" aria-label="Drag <?= e($area) ?> to reorder">⋮⋮</span><strong><?= e($area) ?></strong><span><?= count($areaItems) ?> items · <?= money($areaTotal) ?> total</span></h3>
        <div class="cost-table"><div class="cost-row table-head"><span>Item</span><span>Status</span><span>Qty</span><span>Price</span><span>Cost</span><span></span></div>
        <?php foreach ($areaItems as $item): ?><div class="cost-row rehab-sortable is-<?=e($item['status'])?>" data-rehab-id="<?= (int) $item['id'] ?>">
          <span><span class="rehab-drag-handle" draggable="true" title="Drag to reorder" aria-label="Drag <?= e($item['item_name']) ?> to reorder">⋮⋮</span><strong><?= e($item['item_name']) ?></strong><?php if($item['vendor']||$item['purchased_on']):?><small><?=e(trim(($item['vendor']?:'').' '.($item['purchased_on']?:'')))?></small><?php endif;?></span>
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
    <form id="rehab-reorder-form" method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="reorder_rehab">
      <input type="hidden" name="area" value="">
      <input type="hidden" name="item_order" value="">
    </form>
  </section>

  <?php elseif ($notesOnly): ?>
  <?php include APP_ROOT . '/app/airbnb-property-notes-section.php'; ?>

  <?php elseif ($expensesOnly): ?>
  <section id="monthly" class="tracker-section">
    <div class="section-heading"><div><p class="eyebrow">Operating costs</p><h2>Monthly expenses</h2><p>Keep standard bills as recurring costs, then record the real amount for each month.</p></div>
      <details class="add-panel"><summary>Add recurring cost</summary><form method="post" class="edit-grid"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_recurring">
        <label>Category<input name="category" required></label><label class="wide">Name<input name="name" required></label><label>Typical amount<input type="number" step="0.01" min="0" name="typical_amount"></label>
        <label>Frequency<select name="frequency"><option>monthly</option><option>weekly</option><option>quarterly</option><option>annual</option></select></label><label class="check"><input type="checkbox" name="active" checked> Active</label><label class="wide">Notes<input name="notes"></label><button>Save recurring cost</button>
      </form></details>
    </div>
    <div class="income-month-picker">
      <a href="<?= e($previousMonthUrl . '#monthly') ?>">Previous month</a>
      <form method="get" action="<?= e(base_url('admin/airbnb-expenses.php')) ?>#monthly"><input type="hidden" name="property_id" value="<?= (int) $propertyId ?>"><label>Expense month<input type="month" name="month" value="<?= e($selectedMonth) ?>" onchange="this.form.submit()"></label></form>
      <a href="<?= e($nextMonthUrl . '#monthly') ?>">Next month</a>
    </div>
    <div class="recurring-grid"><?php foreach($recurring as $cost):?><article class="recurring-card <?=$cost['active']?'':'inactive'?>"><div><small><?=e($cost['category'])?></small><strong><?=e($cost['name'])?></strong></div><b><?= $cost['typical_amount']!==null?money((float)$cost['typical_amount']):'Amount needed' ?><small>/<?=e($cost['frequency'])?></small></b>
      <details class="row-actions"><summary>Edit</summary><form method="post" class="edit-grid"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_recurring"><input type="hidden" name="id" value="<?=$cost['id']?>"><label>Category<input name="category" value="<?=e($cost['category'])?>" required></label><label>Name<input name="name" value="<?=e($cost['name'])?>" required></label><label>Amount<input type="number" step="0.01" min="0" name="typical_amount" value="<?=e((string)$cost['typical_amount'])?>"></label><label>Frequency<select name="frequency"><?php foreach(['weekly','monthly','quarterly','annual'] as $frequency):?><option <?=$cost['frequency']===$frequency?'selected':''?>><?=e($frequency)?></option><?php endforeach;?></select></label><label class="check"><input type="checkbox" name="active" <?=$cost['active']?'checked':''?>> Active</label><label class="wide">Notes<input name="notes" value="<?=e($cost['notes'])?>"></label><button>Save</button></form><form method="post" class="delete-form" onsubmit="return confirm('Delete this recurring cost?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_recurring"><input type="hidden" name="id" value="<?=$cost['id']?>"><button>Delete</button></form></details>
    </article><?php endforeach;?></div>

    <div class="month-heading"><div class="expense-month-nav"><a href="<?= e($previousMonthUrl . '#monthly') ?>" aria-label="Previous month">‹</a><div><p class="eyebrow">Expenses for</p><strong><?= e($monthLabel) ?></strong></div><a href="<?= e($nextMonthUrl . '#monthly') ?>" aria-label="Next month">›</a></div><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create_month"><button>Add recurring costs to this month</button></form></div>
    <div class="ledger"><div class="ledger-head"><span>Category</span><span>Expense</span><span>Amount</span><span>Paid</span><span></span></div>
      <?php foreach($expenses as $expense):?><div class="ledger-row"><span><?=e($expense['category'])?></span><span><strong><?=e($expense['description'])?></strong><?php if($expense['notes']):?><small><?=e($expense['notes'])?></small><?php endif;?></span><span><?= $expense['amount']!==null?money((float)$expense['amount']):'<em>Pending</em>'?></span><span><?=e($expense['paid_on']?:'—')?></span><details class="row-actions"><summary>Edit</summary><form method="post" class="edit-grid"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_expense"><input type="hidden" name="id" value="<?=$expense['id']?>"><label>Category<input name="category" value="<?=e($expense['category'])?>" required></label><label class="wide">Description<input name="description" value="<?=e($expense['description'])?>" required></label><label>Amount<input type="number" step="0.01" min="0" name="amount" value="<?=e((string)$expense['amount'])?>"></label><label>Paid date<input type="date" name="paid_on" value="<?=e((string)$expense['paid_on'])?>"></label><label class="wide">Notes<input name="notes" value="<?=e($expense['notes'])?>"></label><button>Save</button></form><form method="post" class="delete-form" onsubmit="return confirm('Delete this expense?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_expense"><input type="hidden" name="id" value="<?=$expense['id']?>"><button>Delete</button></form></details></div><?php endforeach;?>
      <?php if(!$expenses):?><p class="empty-state">No expenses recorded for <?= e($monthLabel) ?> yet.</p><?php endif;?>
    </div>
    <details class="add-panel ledger-add"><summary>Add expense to <?= e($monthLabel) ?></summary><form method="post" class="edit-grid"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_expense"><label>Category<input name="category" required></label><label class="wide">Description<input name="description" required></label><label>Amount<input type="number" step="0.01" min="0" name="amount"></label><label>Paid date<input type="date" name="paid_on"></label><label class="wide">Notes<input name="notes"></label><div class="add-popup-footer"><button type="button" class="edit-cancel add-expense-cancel">Cancel</button><button type="submit">Save expense to <?= e($monthLabel) ?></button></div></form></details>
  </section>

  <?php elseif ($incomeOnly): ?>
  <?php include APP_ROOT . '/app/vacation-income-section.php'; ?>

  <?php endif; ?>
  </div>
</main>
<script>
(() => {
  const prefix = 'mavrei-vacation-rental-<?= (int) $propertyId ?>:';
  const readState = key => { try { return localStorage.getItem(prefix + key); } catch (error) { return null; } };
  const saveState = (key, value) => { try { localStorage.setItem(prefix + key, value); } catch (error) {} };
  document.querySelectorAll('.row-actions').forEach(actions => {
    const editForm = actions.querySelector(':scope > .edit-grid');
    const deleteForm = actions.querySelector(':scope > .delete-form');
    if (!editForm || !deleteForm) return;
    const popup = document.createElement('div');
    popup.className = 'row-edit-popup';
    const footer = document.createElement('div');
    footer.className = 'edit-popup-footer';
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'edit-cancel';
    cancel.textContent = 'Cancel';
    cancel.addEventListener('click', () => {
      editForm.reset();
      actions.open = false;
    });
    actions.insertBefore(popup, editForm);
    popup.append(editForm, footer);
    footer.append(cancel, deleteForm);
  });

  document.querySelectorAll('.income-edit').forEach(actions => {
    const editForm = actions.querySelector(':scope > .income-form');
    const deleteForm = actions.querySelector(':scope > .income-delete');
    if (!editForm || !deleteForm) return;
    const popup = document.createElement('div');
    popup.className = 'income-edit-popup';
    const footer = document.createElement('div');
    footer.className = 'edit-popup-footer';
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'edit-cancel';
    cancel.textContent = 'Cancel';
    cancel.addEventListener('click', () => {
      editForm.reset();
      actions.open = false;
    });
    actions.insertBefore(popup, editForm);
    popup.append(editForm, footer);
    footer.append(cancel, deleteForm);
  });

  document.querySelectorAll('.add-expense-cancel').forEach(cancel => {
    cancel.addEventListener('click', () => {
      const panel = cancel.closest('.add-panel');
      cancel.closest('form')?.reset();
      if (panel) panel.open = false;
    });
  });

  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const openEditor = document.querySelector('.income-edit[open], .row-actions[open]');
    if (openEditor) {
      openEditor.querySelector('.edit-cancel')?.click();
      return;
    }
    document.querySelector('.add-panel[open] .add-expense-cancel')?.click();
  });

  const trackerChart = document.getElementById('tracker-cashflow-chart');
  if (trackerChart) {
    const points = JSON.parse(trackerChart.dataset.points || '[]');
    const moneyLabel = value => value >= 1000000 ? '$' + (value / 1000000).toFixed(1) + 'm' : value >= 1000 ? '$' + (value / 1000).toFixed(value >= 10000 ? 0 : 1) + 'k' : '$' + Math.round(value);
    const exactMoney = value => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value);
    const niceMaximum = value => {
      if (value <= 0) return 100;
      const power = 10 ** Math.floor(Math.log10(value));
      const fraction = value / power;
      return (fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10) * power;
    };
    const renderChart = () => {
      const width = Math.max(320, trackerChart.clientWidth || 1000);
      const height = width < 650 ? 320 : 390;
      const margin = { top: 20, right: 24, bottom: 48, left: width < 500 ? 54 : 68 };
      const plotWidth = width - margin.left - margin.right;
      const plotHeight = height - margin.top - margin.bottom;
      const maximum = niceMaximum(Math.max(100, ...points.flatMap(point => [Number(point.income) || 0, Number(point.expenses) || 0])));
      const x = index => margin.left + (plotWidth * index / Math.max(1, points.length - 1));
      const y = value => margin.top + plotHeight - ((Number(value) || 0) / maximum * plotHeight);
      const pathFor = key => points.map((point, index) => `${index ? 'L' : 'M'} ${x(index).toFixed(1)} ${y(point[key]).toFixed(1)}`).join(' ');
      let markup = `<svg viewBox="0 0 ${width} ${height}" aria-hidden="true">`;
      for (let line = 0; line <= 4; line++) {
        const value = maximum * (4 - line) / 4;
        const lineY = margin.top + plotHeight * line / 4;
        markup += `<line x1="${margin.left}" y1="${lineY}" x2="${width - margin.right}" y2="${lineY}" stroke="#dce3e8" stroke-width="1"/><text x="${margin.left - 10}" y="${lineY + 4}" text-anchor="end" fill="#66758a" font-size="11">${moneyLabel(value)}</text>`;
      }
      points.forEach((point, index) => {
        if (width >= 620 || index % 2 === 0) markup += `<text x="${x(index)}" y="${height - 16}" text-anchor="middle" fill="#66758a" font-size="11" font-weight="700">${point.label}</text>`;
      });
      markup += `<path d="${pathFor('income')}" fill="none" stroke="#16704c" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/><path d="${pathFor('expenses')}" fill="none" stroke="#c40022" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>`;
      points.forEach((point, index) => {
        markup += `<circle cx="${x(index)}" cy="${y(point.income)}" r="5" fill="#fff" stroke="#16704c" stroke-width="3"><title>${point.label}: Income ${exactMoney(point.income)}</title></circle>`;
        markup += `<circle cx="${x(index)}" cy="${y(point.expenses)}" r="5" fill="#fff" stroke="#c40022" stroke-width="3"><title>${point.label}: Expenses ${exactMoney(point.expenses)}</title></circle>`;
      });
      trackerChart.innerHTML = markup + '</svg>';
    };
    renderChart();
    if ('ResizeObserver' in window) new ResizeObserver(renderChart).observe(trackerChart);
    else window.addEventListener('resize', renderChart);
  }

  const rehabSection = document.getElementById('rehab');
  const areaOrderAnchor = document.getElementById('rehab-reorder-form');
  const currentAreaGroups = () => Array.from(rehabSection?.querySelectorAll(':scope > .cost-group[data-area]') || []);
  let storedAreaOrder = [];
  try {
    const savedAreaOrder = JSON.parse(readState('area-order') || '[]');
    if (Array.isArray(savedAreaOrder)) storedAreaOrder = savedAreaOrder;
  } catch (error) {}
  const availableAreaGroups = new Map(currentAreaGroups().map(group => [group.dataset.area || '', group]));
  storedAreaOrder.forEach(area => {
    const group = availableAreaGroups.get(area);
    if (group) {
      rehabSection.insertBefore(group, areaOrderAnchor);
      availableAreaGroups.delete(area);
    }
  });
  availableAreaGroups.forEach(group => rehabSection?.insertBefore(group, areaOrderAnchor));

  let draggedAreaGroup = null;
  document.querySelectorAll('.area-drag-handle').forEach(handle => {
    handle.addEventListener('dragstart', event => {
      draggedAreaGroup = handle.closest('.cost-group[data-area]');
      if (!draggedAreaGroup) {
        event.preventDefault();
        return;
      }
      draggedAreaGroup.classList.add('is-area-dragging');
      rehabSection?.classList.add('is-reordering-areas');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', draggedAreaGroup.dataset.area || '');
    });
    handle.addEventListener('dragend', () => {
      draggedAreaGroup?.classList.remove('is-area-dragging');
      rehabSection?.classList.remove('is-reordering-areas');
      currentAreaGroups().forEach(group => group.classList.remove('is-area-drop-target'));
      draggedAreaGroup = null;
    });
  });

  rehabSection?.addEventListener('dragover', event => {
    if (!draggedAreaGroup) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    const target = event.target.closest('.cost-group[data-area]');
    currentAreaGroups().forEach(group => group.classList.remove('is-area-drop-target'));
    if (!target || target === draggedAreaGroup || target.parentElement !== rehabSection) return;
    target.classList.add('is-area-drop-target');
    const rect = target.getBoundingClientRect();
    rehabSection.insertBefore(draggedAreaGroup, event.clientY < rect.top + rect.height / 2 ? target : target.nextSibling);
  });

  rehabSection?.addEventListener('drop', event => {
    if (!draggedAreaGroup) return;
    event.preventDefault();
    saveState('area-order', JSON.stringify(currentAreaGroups().map(group => group.dataset.area || '')));
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

  const reorderForm = document.getElementById('rehab-reorder-form');
  let draggedRow = null;
  let draggedGroup = null;

  document.querySelectorAll('.rehab-drag-handle').forEach(handle => {
    handle.addEventListener('dragstart', event => {
      draggedRow = handle.closest('.rehab-sortable');
      draggedGroup = handle.closest('.cost-group[data-area]');
      if (!draggedRow || !draggedGroup) {
        event.preventDefault();
        return;
      }
      draggedRow.classList.add('is-dragging');
      draggedGroup.classList.add('is-reordering');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', draggedRow.dataset.rehabId || '');
    });

    handle.addEventListener('dragend', () => {
      draggedRow?.classList.remove('is-dragging');
      draggedGroup?.classList.remove('is-reordering');
      document.querySelectorAll('.rehab-sortable.is-drop-target').forEach(row => row.classList.remove('is-drop-target'));
      draggedRow = null;
      draggedGroup = null;
    });
  });

  document.querySelectorAll('.cost-group[data-area] .cost-table').forEach(table => {
    table.addEventListener('dragover', event => {
      if (!draggedRow || table.closest('.cost-group') !== draggedGroup) return;
      event.preventDefault();
      event.dataTransfer.dropEffect = 'move';
      const target = event.target.closest('.rehab-sortable');
      document.querySelectorAll('.rehab-sortable.is-drop-target').forEach(row => row.classList.remove('is-drop-target'));
      if (!target || target === draggedRow) return;
      target.classList.add('is-drop-target');
      const rect = target.getBoundingClientRect();
      target.parentNode.insertBefore(draggedRow, event.clientY < rect.top + rect.height / 2 ? target : target.nextSibling);
    });

    table.addEventListener('drop', event => {
      if (!draggedRow || table.closest('.cost-group') !== draggedGroup || !reorderForm) return;
      event.preventDefault();
      const ids = Array.from(table.querySelectorAll('.rehab-sortable')).map(row => row.dataset.rehabId);
      reorderForm.elements.area.value = draggedGroup.dataset.area || '';
      reorderForm.elements.item_order.value = ids.join(',');
      reorderForm.requestSubmit();
    });
  });
})();
</script>
</body>
</html>
