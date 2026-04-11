<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

get_header();

$storageFile = dirname(__DIR__, 4) . '/data/availability.json';
$isAdminAuthed = !empty($_SESSION['avaram_co_admin_auth']);

$adminUnitLabels = [
    '3bhk' => '3BHK',
    '2bhk-a' => '2BHK - A',
    '2bhk-b' => '2BHK - B',
    '2bhk-c' => '2BHK - C',
    'room' => 'Private Room + Bath',
];

$viewUnitLabels = [
    '3bhk' => '3BHK',
    '2bhk' => '2BHK',
    'room' => 'Private Room + Bath',
];

$twoBhkUnits = ['2bhk-a', '2bhk-b', '2bhk-c'];

$statusLabels = [
    'available' => 'Available',
    'booked' => 'Booked',
    'blocked' => 'Blocked',
];

if (!file_exists($storageFile)) {
    if (!is_dir(dirname($storageFile))) {
        mkdir(dirname($storageFile), 0775, true);
    }

    file_put_contents(
        $storageFile,
        json_encode(['updated_at' => date(DATE_ATOM), 'entries' => [], 'prices' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

$rawData = @file_get_contents($storageFile);
$data = json_decode((string) $rawData, true);
if (!is_array($data)) {
    $data = ['updated_at' => date(DATE_ATOM), 'entries' => [], 'prices' => []];
}
if (!isset($data['entries']) || !is_array($data['entries'])) {
    $data['entries'] = [];
}
if (!isset($data['prices']) || !is_array($data['prices'])) {
    $data['prices'] = [];
}

$entries = $data['entries'];
$prices = $data['prices'];
$adminMessage = '';
$adminError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_availability') {
    $unit = (string) ($_POST['unit'] ?? '');
    $status = (string) ($_POST['status'] ?? '');
    $startDate = (string) ($_POST['start_date'] ?? '');
    $endDate = (string) ($_POST['end_date'] ?? '');
    $priceInput = trim((string) ($_POST['price'] ?? ''));

    if (!$isAdminAuthed) {
        $adminError = 'Login required. Please sign in on the Admin page.';
    } elseif (!isset($adminUnitLabels[$unit])) {
        $adminError = 'Select a valid unit.';
    } elseif (!isset($statusLabels[$status])) {
        $adminError = 'Select a valid status.';
    } elseif ($priceInput === '' || !is_numeric($priceInput) || (float) $priceInput < 0) {
        $adminError = 'Enter a valid price (0 or greater).';
    } else {
        $startObj = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        $endObj = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
        $priceValue = round((float) $priceInput, 2);

        if (!$startObj || !$endObj || $startObj->format('Y-m-d') !== $startDate || $endObj->format('Y-m-d') !== $endDate) {
            $adminError = 'Use valid start and end dates.';
        } elseif ($startObj > $endObj) {
            $adminError = 'Start date must be before or equal to end date.';
        } else {
            $cursor = $startObj;
            while ($cursor <= $endObj) {
                $key = $unit . '|' . $cursor->format('Y-m-d');
                $entries[$key] = $status;
                $prices[$key] = $priceValue;
                $cursor = $cursor->modify('+1 day');
            }

            $data['entries'] = $entries;
            $data['prices'] = $prices;
            $data['updated_at'] = date(DATE_ATOM);
            file_put_contents(
                $storageFile,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );

            $adminMessage = 'Availability and pricing updated successfully.';
        }
    }
}

$monthParam = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}

$monthStart = DateTimeImmutable::createFromFormat('Y-m-d', $monthParam . '-01');
if (!$monthStart) {
    $monthStart = new DateTimeImmutable(date('Y-m-01'));
}
$monthLabel = $monthStart->format('F Y');
$prevMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');

$unitFilter = (string) ($_GET['unit'] ?? 'all');
if ($unitFilter !== 'all' && !isset($viewUnitLabels[$unitFilter])) {
    $unitFilter = 'all';
}

$unitsToRender = $unitFilter === 'all' ? $viewUnitLabels : [$unitFilter => $viewUnitLabels[$unitFilter]];

function avaramco_get_status_for_date(array $entries, string $unit, string $date): string
{
    $key = $unit . '|' . $date;
    $status = $entries[$key] ?? 'available';
    return in_array($status, ['available', 'booked', 'blocked'], true) ? $status : 'available';
}

function avaramco_get_display_status_for_date(array $entries, string $unit, string $date, array $twoBhkUnits): string
{
    if ($unit !== '2bhk') {
        return avaramco_get_status_for_date($entries, $unit, $date);
    }

    $statuses = [];
    foreach ($twoBhkUnits as $twoBhkUnit) {
        $statuses[] = avaramco_get_status_for_date($entries, $twoBhkUnit, $date);
    }

    if (in_array('available', $statuses, true)) {
        return 'available';
    }

    if (count(array_unique($statuses)) === 1 && $statuses[0] === 'blocked') {
        return 'blocked';
    }

    return 'booked';
}

function avaramco_get_price_for_date(array $prices, string $unit, string $date): ?float
{
    $key = $unit . '|' . $date;
    if (!isset($prices[$key]) || !is_numeric($prices[$key])) {
        return null;
    }

    return round((float) $prices[$key], 2);
}

function avaramco_get_display_price_for_date(array $prices, string $unit, string $date, array $twoBhkUnits): ?float
{
    if ($unit !== '2bhk') {
        return avaramco_get_price_for_date($prices, $unit, $date);
    }

    $values = [];
    foreach ($twoBhkUnits as $twoBhkUnit) {
        $price = avaramco_get_price_for_date($prices, $twoBhkUnit, $date);
        if ($price !== null) {
            $values[] = $price;
        }
    }

    if (empty($values)) {
        return null;
    }

    return min($values);
}

function avaramco_calculate_booking_estimate(
    array $entries,
    array $prices,
    string $unit,
    DateTimeImmutable $startObj,
    DateTimeImmutable $endObj,
    array $twoBhkUnits
): array {
    $cursor = $startObj;
    $days = 0;
    $subtotal = 0.0;

    while ($cursor <= $endObj) {
        $dateKey = $cursor->format('Y-m-d');
        $status = avaramco_get_display_status_for_date($entries, $unit, $dateKey, $twoBhkUnits);
        $price = avaramco_get_display_price_for_date($prices, $unit, $dateKey, $twoBhkUnits);

        if ($status !== 'available') {
            return [
                'ok' => false,
                'message' => 'Selected range includes non-available days (' . $dateKey . ').',
            ];
        }

        if ($price === null) {
            return [
                'ok' => false,
                'message' => 'Price is not set for ' . $dateKey . '.',
            ];
        }

        $subtotal += $price;
        $days++;
        $cursor = $cursor->modify('+1 day');
    }

    $discount = $days > 3 ? round($subtotal * 0.10, 2) : 0.0;
    $total = round($subtotal - $discount, 2);

    return [
        'ok' => true,
        'days' => $days,
        'subtotal' => round($subtotal, 2),
        'discount' => $discount,
        'total' => $total,
    ];
}

function avaramco_build_month_grid(DateTimeImmutable $monthStart): array
{
    $days = (int) $monthStart->format('t');
    $firstDow = (int) $monthStart->format('w');

    $weeks = [];
    $week = array_fill(0, 7, null);

    for ($i = 0; $i < $firstDow; $i++) {
        $week[$i] = null;
    }

    for ($day = 1; $day <= $days; $day++) {
        $date = $monthStart->setDate((int) $monthStart->format('Y'), (int) $monthStart->format('m'), $day);
        $dow = (int) $date->format('w');
        $week[$dow] = $date;

        if ($dow === 6) {
            $weeks[] = $week;
            $week = array_fill(0, 7, null);
        }
    }

    if (array_filter($week, static fn($v) => $v !== null)) {
        $weeks[] = $week;
    }

    return $weeks;
}

$monthGrid = avaramco_build_month_grid($monthStart);

$quoteUnit = (string) ($_GET['quote_unit'] ?? '');
$quoteStart = (string) ($_GET['quote_start'] ?? '');
$quoteEnd = (string) ($_GET['quote_end'] ?? '');
$quoteError = '';
$quoteSummary = null;

if ($quoteUnit !== '' || $quoteStart !== '' || $quoteEnd !== '') {
    if (!isset($viewUnitLabels[$quoteUnit])) {
        $quoteError = 'Select a valid unit for estimate.';
    } else {
        $quoteStartObj = DateTimeImmutable::createFromFormat('Y-m-d', $quoteStart);
        $quoteEndObj = DateTimeImmutable::createFromFormat('Y-m-d', $quoteEnd);

        if (!$quoteStartObj || !$quoteEndObj || $quoteStartObj->format('Y-m-d') !== $quoteStart || $quoteEndObj->format('Y-m-d') !== $quoteEnd) {
            $quoteError = 'Use valid estimate start and end dates.';
        } elseif ($quoteStartObj > $quoteEndObj) {
            $quoteError = 'Estimate start date must be before or equal to end date.';
        } else {
            $estimate = avaramco_calculate_booking_estimate($entries, $prices, $quoteUnit, $quoteStartObj, $quoteEndObj, $twoBhkUnits);
            if (!$estimate['ok']) {
                $quoteError = $estimate['message'];
            } else {
                $quoteSummary = $estimate;
            }
        }
    }
}
?>

<h1>Editable Availability Calendar</h1>
<p>Use the admin panel to mark date ranges and set per-day pricing per unit.</p>

<section class="card admin-panel">
    <h2>Admin Update Panel</h2>
    <?php if ($adminMessage !== ''): ?>
        <p class="notice success"><?php echo esc_html($adminMessage); ?></p>
    <?php endif; ?>
    <?php if ($adminError !== ''): ?>
        <p class="notice error"><?php echo esc_html($adminError); ?></p>
    <?php endif; ?>

    <?php if ($isAdminAuthed): ?>
        <form method="post" class="availability-form">
            <input type="hidden" name="action" value="save_availability">
            <div class="form-row">
                <label for="unit">Unit</label>
                <select id="unit" name="unit" required>
                    <?php foreach ($adminUnitLabels as $unitKey => $unitName): ?>
                        <option value="<?php echo esc_attr($unitKey); ?>"><?php echo esc_html($unitName); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="status">Status</label>
                <select id="status" name="status" required>
                    <?php foreach ($statusLabels as $statusKey => $statusName): ?>
                        <option value="<?php echo esc_attr($statusKey); ?>"><?php echo esc_html($statusName); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row inline-fields">
                <div>
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" required>
                </div>
                <div>
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" required>
                </div>
            </div>
            <div class="form-row">
                <label for="price">Price Per Day (INR)</label>
                <input type="number" id="price" name="price" min="0" step="0.01" placeholder="e.g. 3000" required>
            </div>
            <button type="submit" class="btn">Save Availability + Price</button>
        </form>
        <p class="cta-row">
            <a class="btn btn-secondary" href="/sync-photos.php">Sync Photos Album</a>
            <a class="btn btn-secondary" href="/web-performance.php">Web Performance</a>
            <a class="btn btn-secondary" href="/admin.php">Admin Dashboard</a>
        </p>
    <?php else: ?>
        <p class="muted">Login is required to edit availability.</p>
        <p class="cta-row">
            <a class="btn" href="/admin.php">Go to Admin Login</a>
        </p>
    <?php endif; ?>
</section>

<section class="calendar-toolbar card">
    <div class="toolbar-links">
        <a class="btn btn-secondary"
            href="<?php echo esc_url(add_query_arg(['month' => $prevMonth, 'unit' => $unitFilter])); ?>">Previous
            Month</a>
        <span class="month-title"><?php echo esc_html($monthLabel); ?></span>
        <a class="btn btn-secondary"
            href="<?php echo esc_url(add_query_arg(['month' => $nextMonth, 'unit' => $unitFilter])); ?>">Next Month</a>
    </div>
    <form method="get" class="filter-form">
        <input type="hidden" name="month" value="<?php echo esc_attr($monthParam); ?>">
        <label for="unit_filter">View Unit</label>
        <select id="unit_filter" name="unit">
            <option value="all" <?php selected($unitFilter, 'all'); ?>>All Units</option>
            <?php foreach ($viewUnitLabels as $unitKey => $unitName): ?>
                <option value="<?php echo esc_attr($unitKey); ?>" <?php selected($unitFilter, $unitKey); ?>>
                    <?php echo esc_html($unitName); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Apply</button>
    </form>
</section>

<section class="legend">
    <span class="chip available">Available</span>
    <span class="chip booked">Booked</span>
    <span class="chip blocked">Blocked</span>
    <span class="chip">Price shown only on available days</span>
    <span class="chip">10% discount for bookings longer than 3 days</span>
</section>

<section class="card">
    <h2>Booking Price Estimate</h2>
    <form method="get" class="availability-form">
        <input type="hidden" name="month" value="<?php echo esc_attr($monthParam); ?>">
        <input type="hidden" name="unit" value="<?php echo esc_attr($unitFilter); ?>">
        <div class="form-row">
            <label for="quote_unit">Unit</label>
            <select id="quote_unit" name="quote_unit" required>
                <?php foreach ($viewUnitLabels as $unitKey => $unitName): ?>
                    <option value="<?php echo esc_attr($unitKey); ?>" <?php selected($quoteUnit, $unitKey); ?>>
                        <?php echo esc_html($unitName); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row inline-fields">
            <div>
                <label for="quote_start">Start Date</label>
                <input type="date" id="quote_start" name="quote_start" value="<?php echo esc_attr($quoteStart); ?>"
                    required>
            </div>
            <div>
                <label for="quote_end">End Date</label>
                <input type="date" id="quote_end" name="quote_end" value="<?php echo esc_attr($quoteEnd); ?>" required>
            </div>
        </div>
        <button type="submit" class="btn btn-secondary">Estimate Total</button>
    </form>
    <?php if ($quoteError !== ''): ?>
        <p class="notice error"><?php echo esc_html($quoteError); ?></p>
    <?php elseif (is_array($quoteSummary)): ?>
        <p class="notice success">
            <?php
            echo esc_html(
                'Days: ' . $quoteSummary['days'] .
                ' | Subtotal: INR ' . number_format((float) $quoteSummary['subtotal'], 2) .
                ' | Discount: INR ' . number_format((float) $quoteSummary['discount'], 2) .
                ' | Total: INR ' . number_format((float) $quoteSummary['total'], 2)
            );
            ?>
        </p>
    <?php endif; ?>
</section>

<section class="calendar-wrap">
    <?php foreach ($unitsToRender as $unitKey => $unitName): ?>
        <article class="month-card">
            <h2><?php echo esc_html($unitName); ?> - <?php echo esc_html($monthLabel); ?></h2>
            <table class="calendar-table" aria-label="<?php echo esc_attr($unitName); ?> availability">
                <thead>
                    <tr>
                        <th>Sun</th>
                        <th>Mon</th>
                        <th>Tue</th>
                        <th>Wed</th>
                        <th>Thu</th>
                        <th>Fri</th>
                        <th>Sat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthGrid as $week): ?>
                        <tr>
                            <?php foreach ($week as $cell): ?>
                                <?php if ($cell instanceof DateTimeImmutable): ?>
                                    <?php
                                    $dateKey = $cell->format('Y-m-d');
                                    $status = avaramco_get_display_status_for_date($entries, $unitKey, $dateKey, $twoBhkUnits);
                                    $price = avaramco_get_display_price_for_date($prices, $unitKey, $dateKey, $twoBhkUnits);
                                    $title = $dateKey . ' - ' . ucfirst($status);
                                    if ($status === 'available' && $price !== null) {
                                        $title .= ' - INR ' . number_format($price, 2);
                                    }
                                    ?>
                                    <td class="<?php echo esc_attr($status); ?>" title="<?php echo esc_attr($title); ?>">
                                        <span class="date-number"><?php echo esc_html($cell->format('j')); ?></span>
                                        <?php if ($status === 'available' && $price !== null): ?>
                                            <span class="date-price">INR <?php echo esc_html(number_format($price, 0)); ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php else: ?>
                                    <td class="empty"></td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </article>
    <?php endforeach; ?>
</section>

<?php
get_footer();
