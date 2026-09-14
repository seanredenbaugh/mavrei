<?php $income = $income ?? []; ?>
<label>Platform<select name="platform"><?php foreach ($platforms as $value => $label): ?><option value="<?= e($value) ?>" <?= ($income['platform'] ?? 'airbnb') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<label>Status<select name="status"><?php foreach ($statuses as $value => $label): ?><option value="<?= e($value) ?>" <?= ($income['status'] ?? 'paid') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<label>Reservation code<input name="reservation_code" maxlength="100" value="<?= e($income['reservation_code'] ?? '') ?>"></label>
<label>Guest name<input name="guest_name" maxlength="120" value="<?= e($income['guest_name'] ?? '') ?>"></label>
<label>Booking date<input type="date" name="booking_date" value="<?= e($income['booking_date'] ?? '') ?>"></label>
<label>Check-in<input type="date" name="check_in" value="<?= e($income['check_in'] ?? '') ?>"></label>
<label>Check-out<input type="date" name="check_out" value="<?= e($income['check_out'] ?? '') ?>"></label>
<label>Nights<input type="number" min="0" step="1" name="nights" value="<?= e((string) ($income['nights'] ?? '')) ?>"></label>
<label>Gross rent<input type="number" step="0.01" name="gross_rent" value="<?= e((string) ($income['gross_rent'] ?? '')) ?>"></label>
<label>Cleaning fee<input type="number" step="0.01" name="cleaning_fee" value="<?= e((string) ($income['cleaning_fee'] ?? '')) ?>"></label>
<label>Taxes collected<input type="number" step="0.01" name="taxes_collected" value="<?= e((string) ($income['taxes_collected'] ?? '')) ?>"></label>
<label>Platform fee<input type="number" step="0.01" name="platform_fee" value="<?= e((string) ($income['platform_fee'] ?? '')) ?>"></label>
<label>Adjustments<input type="number" step="0.01" name="adjustments" value="<?= e((string) ($income['adjustments'] ?? '')) ?>"></label>
<label>Net payout<input type="number" step="0.01" name="net_payout" value="<?= e((string) ($income['net_payout'] ?? '')) ?>" required></label>
<label>Payout date<input type="date" name="payout_date" value="<?= e($income['payout_date'] ?? '') ?>"></label>
<label class="wide">Notes<input name="notes" value="<?= e($income['notes'] ?? '') ?>"></label>
