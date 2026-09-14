<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

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
        source_hash CHAR(64) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_vacation_income_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
        UNIQUE KEY uq_vacation_income_source (source_hash),
        INDEX idx_vacation_income_month (property_id, payout_date),
        INDEX idx_vacation_income_stay (property_id, check_in)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function income_money(string $key): ?string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if ($value === '') return null;
    if (!is_numeric($value)) throw new RuntimeException('Enter valid dollar amounts.');
    return number_format((float) $value, 2, '.', '');
}

function income_date(string $key): ?string
{
    return normalize_income_date((string) ($_POST[$key] ?? ''));
}

function normalize_income_date(string $value): ?string
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

function csv_header_key(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)) ?? '');
}

function csv_pick(array $row, array $aliases): ?string
{
    foreach ($aliases as $alias) {
        $key = csv_header_key($alias);
        if (array_key_exists($key, $row) && trim((string) $row[$key]) !== '') return trim((string) $row[$key]);
    }
    return null;
}

function csv_money(?string $value): ?float
{
    if ($value === null || trim($value) === '') return null;
    $negative = str_contains($value, '(') && str_contains($value, ')');
    $clean = preg_replace('/[^0-9.\-]/', '', $value) ?? '';
    if ($clean === '' || !is_numeric($clean)) return null;
    $amount = (float) $clean;
    return $negative ? -abs($amount) : $amount;
}

function display_money(float $amount): string
{
    return ($amount < 0 ? '-$' : '$') . number_format(abs($amount), 2);
}

ensure_vacation_income_schema();
$propertyId = filter_input(INPUT_GET, 'property_id', FILTER_VALIDATE_INT) ?: 0;
if (!$propertyId) $propertyId = (int) db()->query("SELECT id FROM properties WHERE property_type='airbnb' ORDER BY id LIMIT 1")->fetchColumn();
$stmt = db()->prepare("SELECT * FROM properties WHERE id=? AND property_type='airbnb'");
$stmt->execute([$propertyId]);
$property = $stmt->fetch();
if (!$property) { http_response_code(404); exit('Vacation rental property not found.'); }

$selectedMonth = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth)) $selectedMonth = date('Y-m');
$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));
$platforms = ['airbnb'=>'Airbnb','vrbo'=>'Vrbo','direct'=>'Direct booking','other'=>'Other'];
$statuses = ['upcoming'=>'Upcoming','paid'=>'Paid','cancelled'=>'Cancelled','refunded'=>'Refunded'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_income') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
            $platform = (string) ($_POST['platform'] ?? 'airbnb');
            $status = (string) ($_POST['status'] ?? 'paid');
            if (!isset($platforms[$platform]) || !isset($statuses[$status])) throw new RuntimeException('Choose a valid platform and status.');
            $nightsInput = trim((string) ($_POST['nights'] ?? ''));
            $nights = $nightsInput === '' ? null : filter_var($nightsInput, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
            if ($nightsInput !== '' && $nights === false) throw new RuntimeException('Nights must be a whole number.');
            $values = [
                $platform, substr(trim((string)($_POST['reservation_code']??'')),0,100)?:null,
                substr(trim((string)($_POST['guest_name']??'')),0,120)?:null, income_date('booking_date'),
                income_date('check_in'), income_date('check_out'), $nights, income_money('gross_rent'),
                income_money('cleaning_fee'), income_money('taxes_collected'), income_money('platform_fee'),
                income_money('adjustments'), income_money('net_payout'), income_date('payout_date'),
                $status, trim((string)($_POST['notes']??''))?:null,
            ];
            if ($values[12] === null) throw new RuntimeException('Net payout is required.');
            if ($id) {
                $values[]=$id; $values[]=$propertyId;
                db()->prepare('UPDATE vacation_rental_income SET platform=?,reservation_code=?,guest_name=?,booking_date=?,check_in=?,check_out=?,nights=?,gross_rent=?,cleaning_fee=?,taxes_collected=?,platform_fee=?,adjustments=?,net_payout=?,payout_date=?,status=?,notes=?,source_type="manual",source_hash=NULL WHERE id=? AND property_id=?')->execute($values);
            } else {
                array_unshift($values,$propertyId);
                db()->prepare('INSERT INTO vacation_rental_income(property_id,platform,reservation_code,guest_name,booking_date,check_in,check_out,nights,gross_rent,cleaning_fee,taxes_collected,platform_fee,adjustments,net_payout,payout_date,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($values);
            }
        } elseif ($action === 'delete_income') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
            db()->prepare('DELETE FROM vacation_rental_income WHERE id=? AND property_id=?')->execute([$id,$propertyId]);
        } elseif ($action === 'import_csv') {
            $platform = (string) ($_POST['platform'] ?? 'airbnb');
            if (!in_array($platform, ['airbnb', 'vrbo'], true)) throw new RuntimeException('Choose Airbnb or Vrbo for the import.');
            $file = $_FILES['income_csv'] ?? null;
            if (!$file || ($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || ($file['size']??0)>5*1024*1024 || strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION))!=='csv') throw new RuntimeException('Choose a CSV file smaller than 5 MB.');
            $temporaryFile = (string) ($file['tmp_name'] ?? '');
            if ($temporaryFile === '' || !is_uploaded_file($temporaryFile)) throw new RuntimeException('The CSV upload could not be verified.');
            $handle = fopen($temporaryFile,'rb');
            if (!$handle) throw new RuntimeException('The CSV file could not be opened.');
            $headers = fgetcsv($handle);
            if (!$headers) { fclose($handle); throw new RuntimeException('The CSV file has no header row.'); }
            $headers = array_map(fn($h)=>csv_header_key((string)$h),$headers);
            $insert = db()->prepare('INSERT IGNORE INTO vacation_rental_income(property_id,platform,reservation_code,guest_name,booking_date,check_in,check_out,nights,gross_rent,cleaning_fee,taxes_collected,platform_fee,adjustments,net_payout,payout_date,currency,status,source_type,source_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $imported=0; $skipped=0;
            while (($cells=fgetcsv($handle))!==false) {
                if (!array_filter($cells,fn($v)=>trim((string)$v)!=='')) continue;
                $cells=array_pad($cells,count($headers),null); $row=array_combine($headers,array_slice($cells,0,count($headers)));
                if (!$row) { $skipped++; continue; }
                $code=csv_pick($row,['confirmation code','reservation code','confirmation','booking id','reservation id','reference']);
                $guest=csv_pick($row,['guest name','guest','traveler name','traveller name']);
                $booking=normalize_income_date((string)(csv_pick($row,['booking date','booked date','reservation date'])??''));
                $checkIn=normalize_income_date((string)(csv_pick($row,['start date','check in','check-in','arrival date','arrival'])??''));
                $checkOut=normalize_income_date((string)(csv_pick($row,['end date','check out','check-out','departure date','departure'])??''));
                $nightsValue=csv_pick($row,['nights','nights booked','number of nights']);
                $nights=$nightsValue!==null&&is_numeric($nightsValue)?max(0,(int)$nightsValue):null;
                if ($nights===null&&$checkIn&&$checkOut) $nights=max(0,(new DateTimeImmutable($checkIn))->diff(new DateTimeImmutable($checkOut))->days);
                $gross=csv_money(csv_pick($row,['gross earnings','gross rent','rental amount','accommodation fare','gross amount']));
                $cleaning=csv_money(csv_pick($row,['cleaning fee','cleaning fees']));
                $taxes=csv_money(csv_pick($row,['taxes withheld','occupancy taxes','tax collected','taxes collected']));
                $fee=csv_money(csv_pick($row,['host service fee','service fee','platform fee','commission']));
                if ($fee!==null) $fee=abs($fee);
                $adjustments=csv_money(csv_pick($row,['adjustments','adjustment','refund','resolution adjustment']));
                $net=csv_money(csv_pick($row,['net payout','paid out','payout','net amount','amount']));
                if ($net===null&&$gross!==null) $net=$gross+($cleaning??0)+($adjustments??0)-($fee??0);
                $payout=normalize_income_date((string)(csv_pick($row,['payout date','date','transaction date','payment date'])??''));
                $currency=strtoupper(substr((string)(csv_pick($row,['currency','currency code'])??'USD'),0,3));
                if (!preg_match('/^[A-Z]{3}$/', $currency)) $currency='USD';
                if ($net===null||($payout===null&&$checkIn===null)) { $skipped++; continue; }
                $sourceHash=hash('sha256',$propertyId.'|'.$platform.'|'.json_encode($row,JSON_UNESCAPED_UNICODE));
                $insert->execute([$propertyId,$platform,$code,$guest,$booking,$checkIn,$checkOut,$nights,$gross,$cleaning,$taxes,$fee,$adjustments,$net,$payout,$currency,'paid','csv',$sourceHash]);
                $imported += $insert->rowCount() ? 1 : 0;
                $skipped += $insert->rowCount() ? 0 : 1;
            }
            fclose($handle);
            header('Location: '.base_url('admin/vacation-income.php?property_id='.$propertyId.'&month='.$selectedMonth.'&imported='.$imported.'&skipped='.$skipped)); exit;
        } else {
            throw new RuntimeException('Choose a valid action.');
        }
        header('Location: '.base_url('admin/vacation-income.php?property_id='.$propertyId.'&month='.$selectedMonth.'&saved=1')); exit;
    } catch (Throwable $exception) {
        error_log('Vacation income save failed: '.$exception->getMessage());
        $error=$exception instanceof RuntimeException?$exception->getMessage():'The income entry could not be saved.';
    }
}

$stmt=db()->prepare('SELECT * FROM vacation_rental_income WHERE property_id=? AND ((payout_date>=? AND payout_date<?) OR (payout_date IS NULL AND check_in>=? AND check_in<?)) ORDER BY COALESCE(payout_date,check_in) DESC,id DESC');
$stmt->execute([$propertyId,$monthStart,$monthEnd,$monthStart,$monthEnd]); $incomeRows=$stmt->fetchAll();
$grossTotal=array_sum(array_map(fn($r)=>(float)($r['gross_rent']??0)+(float)($r['cleaning_fee']??0),$incomeRows));
$feeTotal=array_sum(array_map(fn($r)=>(float)($r['platform_fee']??0),$incomeRows));
$netTotal=array_sum(array_map(fn($r)=>(float)($r['net_payout']??0),$incomeRows));
$nightTotal=array_sum(array_map(fn($r)=>(int)($r['nights']??0),$incomeRows));
$operatingCosts=0.0;
try { $stmt=db()->prepare('SELECT COALESCE(SUM(amount),0) FROM airbnb_monthly_expenses WHERE property_id=? AND expense_month=?'); $stmt->execute([$propertyId,$monthStart]); $operatingCosts=(float)$stmt->fetchColumn(); } catch(Throwable) {}
$cashFlow=$netTotal-$operatingCosts;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vacation rental income | <?=e($property['title'])?></title><link rel="stylesheet" href="<?=e(base_url('assets/css/admin.css?v=20260911-2'))?>"><link rel="stylesheet" href="<?=e(base_url('assets/css/vacation-income.css?v=20260914-1'))?>"></head><body>
<header class="admin-header"><a class="logo-home" href="<?=e(base_url())?>"><img src="<?=e(base_url('assets/img/mavrei-logo-transparent.png?v=20260910-3'))?>" alt="Maverick"></a><nav><a href="<?=e(base_url('admin/'))?>">Properties</a><a href="<?=e(base_url('admin/airbnb.php?property_id='.$propertyId))?>">Costs</a><a href="<?=e(base_url('admin/property.php?id='.$propertyId))?>">Property details</a><a href="<?=e(base_url())?>">View map</a></nav></header>
<main class="income-wrap"><div class="income-title"><div><p class="eyebrow">Vacation rental tracker</p><h1>Income</h1><p><?=e($property['title'])?> · Airbnb / Vrbo / Direct</p></div><form method="get"><input type="hidden" name="property_id" value="<?=$propertyId?>"><label>Reporting month<input type="month" name="month" value="<?=e($selectedMonth)?>" onchange="this.form.submit()"></label></form></div>
<?php if(isset($_GET['saved'])):?><p class="alert success">Income entry saved.</p><?php endif;?><?php if(isset($_GET['imported'])):?><p class="alert success"><?= (int)$_GET['imported'] ?> rows imported; <?= (int)($_GET['skipped']??0) ?> duplicate or unrecognized rows skipped.</p><?php endif;?><?php if($error):?><p class="alert error"><?=e($error)?></p><?php endif;?>
<section class="income-metrics"><article><span>Gross booking income</span><strong><?=display_money($grossTotal)?></strong></article><article><span>Platform fees</span><strong><?=display_money($feeTotal)?></strong></article><article><span>Net payouts</span><strong><?=display_money($netTotal)?></strong></article><article><span>Operating costs</span><strong><?=display_money($operatingCosts)?></strong></article><article class="<?=$cashFlow<0?'negative':'positive'?>"><span>Cash flow</span><strong><?=display_money($cashFlow)?></strong><small><?= $nightTotal ?> booked night<?= $nightTotal===1?'':'s' ?><?= $nightTotal&&$grossTotal?' · '.display_money($grossTotal/$nightTotal).' average':'' ?></small></article></section>
<section class="income-tools"><details><summary>Add income manually</summary><form method="post" class="income-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_income"><?php include APP_ROOT.'/app/vacation-income-fields.php'; ?><button>Save income</button></form></details>
<details><summary>Import Airbnb or Vrbo CSV</summary><form method="post" enctype="multipart/form-data" class="import-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="import_csv"><label>Platform<select name="platform"><option value="airbnb">Airbnb</option><option value="vrbo">Vrbo</option></select></label><label>CSV report<input type="file" name="income_csv" accept=".csv,text/csv" required></label><p>The importer recognizes common earnings, reservation, fee, date, and payout columns. Duplicate rows are ignored. Compare the imported total with the platform report before relying on it.</p><button>Import report</button></form></details></section>
<section class="income-ledger"><div class="ledger-title"><div><p class="eyebrow"><?=e(date('F Y',strtotime($monthStart)))?></p><h2>Income transactions</h2></div><span><?=count($incomeRows)?> entries</span></div><div class="income-table"><div class="income-row income-head"><span>Date</span><span>Platform</span><span>Reservation</span><span>Stay</span><span>Gross</span><span>Fees</span><span>Net</span><span></span></div>
<?php foreach($incomeRows as $row):?><div class="income-row"><span><?=e($row['payout_date']?:$row['check_in']?:'—')?></span><span><b class="platform <?=e($row['platform'])?>"><?=e($platforms[$row['platform']]??ucfirst($row['platform']))?></b></span><span><strong><?=e($row['reservation_code']?:'Manual entry')?></strong><small><?=e($row['guest_name']?:'')?></small></span><span><?=e($row['check_in']?:'—')?><?php if($row['nights']!==null):?><small><?= (int)$row['nights'] ?> nights</small><?php endif;?></span><span><?=display_money((float)($row['gross_rent']??0)+(float)($row['cleaning_fee']??0))?></span><span><?=display_money((float)($row['platform_fee']??0))?></span><span><strong><?=display_money((float)($row['net_payout']??0))?></strong></span><details class="income-edit"><summary>Edit</summary><form method="post" class="income-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_income"><input type="hidden" name="id" value="<?=$row['id']?>"><?php $income=$row; include APP_ROOT.'/app/vacation-income-fields.php'; unset($income); ?><button>Save changes</button></form><form method="post" class="income-delete" onsubmit="return confirm('Delete this income entry?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_income"><input type="hidden" name="id" value="<?=$row['id']?>"><button>Delete</button></form></details></div><?php endforeach;?><?php if(!$incomeRows):?><p class="empty-income">No income recorded for this month.</p><?php endif;?></div></section>
</main></body></html>
