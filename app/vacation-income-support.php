<?php

function ensure_vacation_income_schema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS vacation_rental_income (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        property_id INT UNSIGNED NOT NULL,
        platform VARCHAR(30) NOT NULL DEFAULT 'airbnb',
        reservation_code VARCHAR(100) NULL,
        guest_name VARCHAR(120) NULL,
        booking_date DATE NULL,
        check_in DATE NULL,
        check_out DATE NULL,
        nights SMALLINT UNSIGNED NULL,
        gross_rent DECIMAL(10,2) NULL,
        cleaning_fee DECIMAL(10,2) NULL,
        taxes_collected DECIMAL(10,2) NULL,
        platform_fee DECIMAL(10,2) NULL,
        adjustments DECIMAL(10,2) NULL,
        net_payout DECIMAL(10,2) NULL,
        payout_date DATE NULL,
        currency CHAR(3) NOT NULL DEFAULT 'USD',
        status VARCHAR(30) NOT NULL DEFAULT 'paid',
        notes TEXT NULL,
        source_type VARCHAR(20) NOT NULL DEFAULT 'manual',
        source_key VARCHAR(190) NULL,
        source_hash CHAR(64) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_vacation_income_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
        UNIQUE KEY uq_vacation_income_source_key (property_id, platform, source_key),
        UNIQUE KEY uq_vacation_income_source (source_hash),
        INDEX idx_vacation_income_month (property_id, payout_date),
        INDEX idx_vacation_income_stay (property_id, check_in)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = array_map('strval', db()->query('SHOW COLUMNS FROM vacation_rental_income')->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array('source_key', $columns, true)) {
        db()->exec('ALTER TABLE vacation_rental_income ADD source_key VARCHAR(190) NULL AFTER source_type');
    }
    $indexes = array_map('strval', db()->query('SHOW INDEX FROM vacation_rental_income')->fetchAll(PDO::FETCH_COLUMN, 2));
    if (!in_array('uq_vacation_income_source_key', $indexes, true)) {
        db()->exec('ALTER TABLE vacation_rental_income ADD UNIQUE KEY uq_vacation_income_source_key (property_id, platform, source_key)');
    }

    db()->exec("CREATE TABLE IF NOT EXISTS vacation_income_imports (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        property_id INT UNSIGNED NOT NULL,
        platform VARCHAR(30) NOT NULL,
        file_name VARCHAR(190) NOT NULL,
        file_hash CHAR(64) NOT NULL,
        rows_total INT UNSIGNED NOT NULL DEFAULT 0,
        rows_added INT UNSIGNED NOT NULL DEFAULT 0,
        rows_updated INT UNSIGNED NOT NULL DEFAULT 0,
        rows_unchanged INT UNSIGNED NOT NULL DEFAULT 0,
        rows_skipped INT UNSIGNED NOT NULL DEFAULT 0,
        rows_removed INT UNSIGNED NOT NULL DEFAULT 0,
        payout_total DECIMAL(12,2) NULL,
        payout_references TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_vacation_import_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
        INDEX idx_vacation_import_property (property_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $importColumns = array_map('strval', db()->query('SHOW COLUMNS FROM vacation_income_imports')->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array('rows_removed', $importColumns, true)) {
        db()->exec('ALTER TABLE vacation_income_imports ADD rows_removed INT UNSIGNED NOT NULL DEFAULT 0 AFTER rows_skipped');
    }
}

function vacation_income_platforms(): array
{
    return ['airbnb'=>'Airbnb', 'vrbo'=>'Vrbo', 'direct'=>'Direct booking', 'other'=>'Other'];
}

function vacation_income_statuses(): array
{
    return ['upcoming'=>'Upcoming', 'paid'=>'Paid', 'cancelled'=>'Cancelled', 'refunded'=>'Refunded'];
}

function vacation_income_is_action(string $action): bool
{
    return in_array($action, ['save_income', 'delete_income', 'import_income_csv'], true);
}

function vacation_income_post_money(string $key): ?string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if ($value === '') return null;
    if (!is_numeric($value)) throw new RuntimeException('Enter valid dollar amounts.');
    return number_format((float) $value, 2, '.', '');
}

function normalize_vacation_income_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;
    foreach (['!Y-m-d', '!m/d/Y', '!m/d/y', '!n/j/Y', '!n/j/y'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) return $date->format('Y-m-d');
    }
    return null;
}

function vacation_income_post_date(string $key): ?string
{
    return normalize_vacation_income_date((string) ($_POST[$key] ?? ''));
}

function vacation_income_csv_header(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)) ?? '');
}

function vacation_income_csv_pick(array $row, array $aliases): ?string
{
    foreach ($aliases as $alias) {
        $key = vacation_income_csv_header($alias);
        if (array_key_exists($key, $row) && trim((string) $row[$key]) !== '') return trim((string) $row[$key]);
    }
    return null;
}

function vacation_income_csv_money(?string $value): ?float
{
    if ($value === null || trim($value) === '') return null;
    $negative = str_contains($value, '(') && str_contains($value, ')');
    $clean = preg_replace('/[^0-9.\-]/', '', $value) ?? '';
    if ($clean === '' || !is_numeric($clean)) return null;
    $amount = (float) $clean;
    return $negative ? -abs($amount) : $amount;
}

function vacation_income_handle_post(int $propertyId, string $action): array
{
    $platforms = vacation_income_platforms();
    $statuses = vacation_income_statuses();

    if ($action === 'save_income') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $platform = (string) ($_POST['platform'] ?? 'airbnb');
        $status = (string) ($_POST['status'] ?? 'paid');
        if (!isset($platforms[$platform]) || !isset($statuses[$status])) throw new RuntimeException('Choose a valid platform and status.');
        $nightsInput = trim((string) ($_POST['nights'] ?? ''));
        $nights = $nightsInput === '' ? null : filter_var($nightsInput, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        if ($nightsInput !== '' && $nights === false) throw new RuntimeException('Nights must be a whole number.');
        $values = [
            $platform,
            substr(trim((string) ($_POST['reservation_code'] ?? '')), 0, 100) ?: null,
            substr(trim((string) ($_POST['guest_name'] ?? '')), 0, 120) ?: null,
            vacation_income_post_date('booking_date'),
            vacation_income_post_date('check_in'),
            vacation_income_post_date('check_out'),
            $nights,
            vacation_income_post_money('gross_rent'),
            vacation_income_post_money('cleaning_fee'),
            vacation_income_post_money('taxes_collected'),
            vacation_income_post_money('platform_fee'),
            vacation_income_post_money('adjustments'),
            vacation_income_post_money('net_payout'),
            vacation_income_post_date('payout_date'),
            $status,
            trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];
        if ($values[12] === null) throw new RuntimeException('Net payout is required.');
        if ($id) {
            $values[] = $id;
            $values[] = $propertyId;
            db()->prepare('UPDATE vacation_rental_income SET platform=?,reservation_code=?,guest_name=?,booking_date=?,check_in=?,check_out=?,nights=?,gross_rent=?,cleaning_fee=?,taxes_collected=?,platform_fee=?,adjustments=?,net_payout=?,payout_date=?,status=?,notes=?,source_type="manual",source_hash=NULL WHERE id=? AND property_id=?')->execute($values);
        } else {
            array_unshift($values, $propertyId);
            db()->prepare('INSERT INTO vacation_rental_income(property_id,platform,reservation_code,guest_name,booking_date,check_in,check_out,nights,gross_rent,cleaning_fee,taxes_collected,platform_fee,adjustments,net_payout,payout_date,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($values);
        }
        return ['saved'=>1];
    }

    if ($action === 'delete_income') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        db()->prepare('DELETE FROM vacation_rental_income WHERE id=? AND property_id=?')->execute([$id, $propertyId]);
        return ['saved'=>1];
    }

    if ($action !== 'import_income_csv') throw new RuntimeException('Choose a valid income action.');
    $platform = (string) ($_POST['platform'] ?? 'airbnb');
    if (!in_array($platform, ['airbnb', 'vrbo'], true)) throw new RuntimeException('Choose Airbnb or Vrbo for the import.');
    $file = $_FILES['income_csv'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 5 * 1024 * 1024 || strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv') throw new RuntimeException('Choose a CSV file smaller than 5 MB.');
    $temporaryFile = (string) ($file['tmp_name'] ?? '');
    if ($temporaryFile === '' || !is_uploaded_file($temporaryFile)) throw new RuntimeException('The CSV upload could not be verified.');
    $handle = fopen($temporaryFile, 'rb');
    if (!$handle) throw new RuntimeException('The CSV file could not be opened.');
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        throw new RuntimeException('The CSV file has no header row.');
    }
    $headers = array_map(fn($header)=>vacation_income_csv_header((string) $header), $headers);
    $isPendingSnapshotFormat = in_array('booking status', $headers, true) && !in_array('paid out', $headers, true);
    $findByKey = db()->prepare('SELECT id,source_hash,status FROM vacation_rental_income WHERE property_id=? AND platform=? AND source_key=? LIMIT 1');
    $findLegacy = db()->prepare('SELECT id,source_hash,status FROM vacation_rental_income WHERE property_id=? AND platform=? AND reservation_code=? ORDER BY id LIMIT 1');
    $insert = db()->prepare('INSERT INTO vacation_rental_income(property_id,platform,reservation_code,guest_name,booking_date,check_in,check_out,nights,gross_rent,cleaning_fee,taxes_collected,platform_fee,adjustments,net_payout,payout_date,currency,status,notes,source_type,source_key,source_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $update = db()->prepare('UPDATE vacation_rental_income SET reservation_code=?,guest_name=?,booking_date=?,check_in=?,check_out=?,nights=?,gross_rent=?,cleaning_fee=?,taxes_collected=?,platform_fee=?,adjustments=?,net_payout=?,payout_date=?,currency=?,status=?,notes=?,source_type="csv",source_key=?,source_hash=? WHERE id=? AND property_id=?');
    $added = 0;
    $updated = 0;
    $unchanged = 0;
    $skipped = 0;
    $rowsTotal = 0;
    $payoutTotal = 0.0;
    $payoutReferences = [];
    $reservationRows = 0;
    $pendingRows = 0;
    $pendingSourceKeys = [];
    $hasPayoutRows = false;
    $removed = 0;
    db()->beginTransaction();
    try {
    while (($cells = fgetcsv($handle)) !== false) {
        if (!array_filter($cells, fn($value)=>trim((string) $value) !== '')) continue;
        $rowsTotal++;
        $cells = array_pad($cells, count($headers), null);
        $row = array_combine($headers, array_slice($cells, 0, count($headers)));
        if (!$row) { $skipped++; continue; }
        $type = strtolower((string) (vacation_income_csv_pick($row, ['type','transaction type','record type']) ?? ''));
        if (str_contains($type, 'payout') || str_contains($type, 'transfer')) {
            $hasPayoutRows = true;
            $payoutAmount = vacation_income_csv_money(vacation_income_csv_pick($row, ['paid out','payout','net payout','amount']));
            if ($payoutAmount !== null) $payoutTotal += $payoutAmount;
            $payoutReference = vacation_income_csv_pick($row, ['reference code','reference','payout id','transaction id']);
            if ($payoutReference !== null) $payoutReferences[] = $payoutReference;
            $skipped++;
            continue;
        }
        if ($type !== '' && !str_contains($type, 'reservation') && !str_contains($type, 'booking')) {
            $skipped++;
            continue;
        }
        $code = vacation_income_csv_pick($row, ['confirmation code','reservation code','confirmation','booking id','reservation id']);
        if ($code !== null) $code = strtoupper(substr($code, 0, 100));
        $guest = vacation_income_csv_pick($row, ['guest name','guest','traveler name','traveller name']);
        $booking = normalize_vacation_income_date((string) (vacation_income_csv_pick($row, ['booking date','booked date','reservation date']) ?? ''));
        $checkIn = normalize_vacation_income_date((string) (vacation_income_csv_pick($row, ['start date','check in','check-in','arrival date','arrival']) ?? ''));
        $checkOut = normalize_vacation_income_date((string) (vacation_income_csv_pick($row, ['end date','check out','check-out','departure date','departure']) ?? ''));
        $nightsValue = vacation_income_csv_pick($row, ['nights','nights booked','number of nights']);
        $nights = $nightsValue !== null && is_numeric($nightsValue) ? max(0, (int) $nightsValue) : null;
        if ($nights === null && $checkIn && $checkOut) $nights = max(0, (new DateTimeImmutable($checkIn))->diff(new DateTimeImmutable($checkOut))->days);
        $grossTotal = vacation_income_csv_money(vacation_income_csv_pick($row, ['gross earnings','gross rent','rental amount','accommodation fare','gross amount']));
        $cleaning = vacation_income_csv_money(vacation_income_csv_pick($row, ['cleaning fee','cleaning fees']));
        $gross = $grossTotal;
        if ($platform === 'airbnb' && $grossTotal !== null && $cleaning !== null) $gross = $grossTotal - $cleaning;
        $taxes = vacation_income_csv_money(vacation_income_csv_pick($row, ['airbnb remitted tax','taxes withheld','occupancy taxes','tax collected','taxes collected']));
        $fee = vacation_income_csv_money(vacation_income_csv_pick($row, ['host service fee','service fee','platform fee','commission']));
        if ($fee !== null) $fee = abs($fee);
        $adjustments = vacation_income_csv_money(vacation_income_csv_pick($row, ['adjustments','adjustment','refund','resolution adjustment']));
        $net = vacation_income_csv_money(vacation_income_csv_pick($row, ['net payout','paid out','payout','net amount','amount']));
        if ($net === null && $gross !== null) $net = $gross + ($cleaning ?? 0) + ($adjustments ?? 0) - ($fee ?? 0);
        $payout = normalize_vacation_income_date((string) (vacation_income_csv_pick($row, ['payout date','date','transaction date','payment date']) ?? ''));
        $currency = strtoupper(substr((string) (vacation_income_csv_pick($row, ['currency','currency code']) ?? 'USD'), 0, 3));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) $currency = 'USD';
        if ($net === null || ($payout === null && $checkIn === null)) { $skipped++; continue; }
        $bookingStatus = strtolower((string) (vacation_income_csv_pick($row, ['booking status','reservation status','status']) ?? ''));
        $status = str_contains($bookingStatus, 'cancel') ? 'cancelled' : (str_contains($bookingStatus, 'refund') ? 'refunded' : ((str_contains($bookingStatus, 'confirm') || ($checkIn && $checkIn > date('Y-m-d'))) ? 'upcoming' : 'paid'));
        if ($status === 'upcoming') $payout = null;
        $listing = vacation_income_csv_pick($row, ['listing','property','listing name']);
        $notes = $listing ? 'Listing: ' . substr($listing, 0, 220) : null;
        if ($code !== null) {
            $sourceKey = 'reservation:' . $code;
        } elseif ($checkIn !== null && ($guest !== null || $listing !== null)) {
            $sourceKey = 'stay:' . hash('sha256', implode('|', [$guest ?? '', $listing ?? '', $checkIn, $checkOut ?? '']));
        } else {
            $skipped++;
            continue;
        }
        $reservationRows++;
        if ($status === 'upcoming') {
            $pendingRows++;
            $pendingSourceKeys[] = $sourceKey;
        }
        $record = [$code,$guest,$booking,$checkIn,$checkOut,$nights,$gross,$cleaning,$taxes,$fee,$adjustments,$net,$payout,$currency,$status,$notes,$sourceKey];
        $sourceHash = hash('sha256', $propertyId . '|' . $platform . '|' . json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $findByKey->execute([$propertyId,$platform,$sourceKey]);
        $existing = $findByKey->fetch();
        if (!$existing && $code !== null) {
            $findLegacy->execute([$propertyId,$platform,$code]);
            $existing = $findLegacy->fetch();
        }
        if ($existing) {
            if ($status === 'upcoming' && in_array((string) ($existing['status'] ?? ''), ['paid','refunded'], true)) {
                $unchanged++;
                continue;
            }
            if (hash_equals((string) ($existing['source_hash'] ?? ''), $sourceHash)) {
                $unchanged++;
                continue;
            }
            $update->execute([...$record,$sourceHash,(int) $existing['id'],$propertyId]);
            $updated++;
        } else {
            $insert->execute([$propertyId,$platform,$code,$guest,$booking,$checkIn,$checkOut,$nights,$gross,$cleaning,$taxes,$fee,$adjustments,$net,$payout,$currency,$status,$notes,'csv',$sourceKey,$sourceHash]);
            $added++;
        }
    }
    if ($isPendingSnapshotFormat && !$hasPayoutRows && $pendingRows === $reservationRows) {
        $pendingSourceKeys = array_values(array_unique($pendingSourceKeys));
        $placeholders = implode(',', array_fill(0, count($pendingSourceKeys), '?'));
        $removeSql = "UPDATE vacation_rental_income SET status='cancelled', source_hash=NULL WHERE property_id=? AND platform=? AND source_type='csv' AND status='upcoming' AND check_in>=CURDATE() AND source_key IS NOT NULL";
        $removeValues = [$propertyId, $platform];
        if ($pendingSourceKeys) {
            $removeSql .= " AND source_key NOT IN ({$placeholders})";
            $removeValues = [...$removeValues, ...$pendingSourceKeys];
        }
        $removeStmt = db()->prepare($removeSql);
        $removeStmt->execute($removeValues);
        $removed = $removeStmt->rowCount();
    }
    fclose($handle);
    $handle = null;
    $fileHash = hash_file('sha256', $temporaryFile) ?: hash('sha256', (string) microtime(true));
    $fileName = substr(basename((string) ($file['name'] ?? 'income.csv')), 0, 190);
    db()->prepare('INSERT INTO vacation_income_imports(property_id,platform,file_name,file_hash,rows_total,rows_added,rows_updated,rows_unchanged,rows_skipped,rows_removed,payout_total,payout_references) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$propertyId,$platform,$fileName,$fileHash,$rowsTotal,$added,$updated,$unchanged,$skipped,$removed,$payoutReferences ? $payoutTotal : null,$payoutReferences ? implode(', ', array_unique($payoutReferences)) : null]);
    db()->commit();
    } catch (Throwable $exception) {
        if (is_resource($handle)) fclose($handle);
        if (db()->inTransaction()) db()->rollBack();
        throw $exception;
    }
    return ['added'=>$added, 'updated'=>$updated, 'unchanged'=>$unchanged, 'skipped'=>$skipped, 'removed'=>$removed];
}

function vacation_income_load(int $propertyId, string $monthStart, float $operatingCosts): array
{
    $monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));
    $stmt = db()->prepare("SELECT * FROM vacation_rental_income WHERE property_id=? AND status IN ('paid','refunded') AND ((payout_date>=? AND payout_date<?) OR (payout_date IS NULL AND check_in>=? AND check_in<?)) ORDER BY COALESCE(payout_date,check_in) DESC,id DESC");
    $stmt->execute([$propertyId,$monthStart,$monthEnd,$monthStart,$monthEnd]);
    $rows = $stmt->fetchAll();
    $gross = array_sum(array_map(fn($row)=>(float) ($row['gross_rent'] ?? 0) + (float) ($row['cleaning_fee'] ?? 0), $rows));
    $fees = array_sum(array_map(fn($row)=>(float) ($row['platform_fee'] ?? 0), $rows));
    $net = array_sum(array_map(fn($row)=>(float) ($row['net_payout'] ?? 0), $rows));
    $nights = array_sum(array_map(fn($row)=>(int) ($row['nights'] ?? 0), $rows));
    $upcomingStmt = db()->prepare("SELECT * FROM vacation_rental_income WHERE property_id=? AND status='upcoming' AND check_in>=? AND check_in<? ORDER BY check_in,id");
    $upcomingStmt->execute([$propertyId,$monthStart,$monthEnd]);
    $upcomingRows = $upcomingStmt->fetchAll();
    $upcomingNet = array_sum(array_map(fn($row)=>(float) ($row['net_payout'] ?? 0), $upcomingRows));
    return ['rows'=>$rows, 'gross'=>$gross, 'fees'=>$fees, 'net'=>$net, 'nights'=>$nights, 'operating_costs'=>$operatingCosts, 'cash_flow'=>$net-$operatingCosts, 'upcoming_rows'=>$upcomingRows, 'upcoming_net'=>$upcomingNet];
}

function vacation_income_monthly_comparison(int $propertyId, string $selectedMonth, int $months = 6): array
{
    $selected = new DateTimeImmutable($selectedMonth . '-01');
    $first = $selected->modify('-' . max(0, $months - 1) . ' months');
    $end = $selected->modify('+1 month');
    $incomeStmt = db()->prepare("SELECT DATE_FORMAT(COALESCE(payout_date,check_in),'%Y-%m') AS report_month,
            SUM(COALESCE(gross_rent,0)+COALESCE(cleaning_fee,0)) AS gross,
            SUM(COALESCE(platform_fee,0)) AS fees,
            SUM(COALESCE(net_payout,0)) AS net,
            SUM(COALESCE(nights,0)) AS nights,
            COUNT(*) AS reservations
        FROM vacation_rental_income
        WHERE property_id=? AND status IN ('paid','refunded') AND COALESCE(payout_date,check_in)>=? AND COALESCE(payout_date,check_in)<?
        GROUP BY report_month");
    $incomeStmt->execute([$propertyId,$first->format('Y-m-d'),$end->format('Y-m-d')]);
    $income = [];
    foreach ($incomeStmt->fetchAll() as $row) $income[(string) $row['report_month']] = $row;

    $expenseStmt = db()->prepare("SELECT DATE_FORMAT(expense_month,'%Y-%m') AS report_month, SUM(COALESCE(amount,0)) AS expenses
        FROM airbnb_monthly_expenses
        WHERE property_id=? AND expense_month>=? AND expense_month<?
        GROUP BY report_month");
    $expenseStmt->execute([$propertyId,$first->format('Y-m-d'),$end->format('Y-m-d')]);
    $expenses = [];
    foreach ($expenseStmt->fetchAll() as $row) $expenses[(string) $row['report_month']] = (float) $row['expenses'];

    $rows = [];
    for ($index = 0; $index < $months; $index++) {
        $month = $selected->modify('-' . $index . ' months');
        $key = $month->format('Y-m');
        $source = $income[$key] ?? [];
        $net = (float) ($source['net'] ?? 0);
        $cost = (float) ($expenses[$key] ?? 0);
        $rows[] = [
            'month'=>$key,
            'label'=>$month->format('F Y'),
            'gross'=>(float) ($source['gross'] ?? 0),
            'fees'=>(float) ($source['fees'] ?? 0),
            'net'=>$net,
            'expenses'=>$cost,
            'cash_flow'=>$net-$cost,
            'nights'=>(int) ($source['nights'] ?? 0),
            'reservations'=>(int) ($source['reservations'] ?? 0),
        ];
    }
    return $rows;
}

function vacation_income_import_history(int $propertyId, int $limit = 8): array
{
    $limit = max(1, min(25, $limit));
    $stmt = db()->prepare("SELECT * FROM vacation_income_imports WHERE property_id=? ORDER BY id DESC LIMIT {$limit}");
    $stmt->execute([$propertyId]);
    return $stmt->fetchAll();
}

function vacation_income_display_money(float $amount): string
{
    return ($amount < 0 ? '-$' : '$') . number_format(abs($amount), 2);
}
