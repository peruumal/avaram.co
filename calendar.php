<?php
session_start();

$storageFile = __DIR__ . '/data/availability.json';
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

function getStatusForDate(array $entries, string $unit, string $date): string
{
    $key = $unit . '|' . $date;
    $status = $entries[$key] ?? 'available';
    return in_array($status, ['available', 'booked', 'blocked'], true) ? $status : 'available';
}

function getDisplayStatusForDate(array $entries, string $unit, string $date, array $twoBhkUnits): string
{
    if ($unit !== '2bhk') {
        return getStatusForDate($entries, $unit, $date);
    }

    $statuses = [];
    foreach ($twoBhkUnits as $twoBhkUnit) {
        $statuses[] = getStatusForDate($entries, $twoBhkUnit, $date);
    }

    if (in_array('available', $statuses, true)) {
        return 'available';
    }

    if (count(array_unique($statuses)) === 1 && $statuses[0] === 'blocked') {
        return 'blocked';
    }

    return 'booked';
}

function getPriceForDate(array $prices, string $unit, string $date): ?float
{
    $key = $unit . '|' . $date;
    if (!isset($prices[$key]) || !is_numeric($prices[$key])) {
        return null;
    }

    return round((float) $prices[$key], 2);
}

function getDisplayPriceForDate(array $prices, string $unit, string $date, array $twoBhkUnits): ?float
{
    if ($unit !== '2bhk') {
        return getPriceForDate($prices, $unit, $date);
    }

    $values = [];
    foreach ($twoBhkUnits as $twoBhkUnit) {
        $price = getPriceForDate($prices, $twoBhkUnit, $date);
        if ($price !== null) {
            $values[] = $price;
        }
    }

    if (empty($values)) {
        return null;
    }

    return min($values);
}

function calculateBookingEstimate(
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
        $status = getDisplayStatusForDate($entries, $unit, $dateKey, $twoBhkUnits);
        $price = getDisplayPriceForDate($prices, $unit, $dateKey, $twoBhkUnits);

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

function buildMonthGrid(DateTimeImmutable $monthStart): array
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

$monthGrid = buildMonthGrid($monthStart);

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
            $estimate = calculateBookingEstimate($entries, $prices, $quoteUnit, $quoteStartObj, $quoteEndObj, $twoBhkUnits);
            if (!$estimate['ok']) {
                $quoteError = $estimate['message'];
            } else {
                $quoteSummary = $estimate;
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Availability Calendar | avaram.co Apartment Hotel</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <main class="container">
        <nav class="nav" aria-label="Main navigation">
            <a href="index.php">Home</a>
            <a href="about.php">About</a>
            <a href="services.php">Stay Options</a>
            <a href="photos.php">Photos</a>
            <a href="calendar.php" class="active">Calendar</a>
            <a href="directions.php">Directions</a>
            <a href="contact.php">Contact</a>
            <a href="admin.php">Admin</a>
        </nav>

        <h1>Editable Availability Calendar</h1>
        <p>Use the admin panel to mark date ranges and set per-day pricing per unit.</p>

        <section class="card admin-panel">
            <h2>Admin Update Panel</h2>
            <?php if ($adminMessage !== ''): ?>
                <p class="notice success"><?= htmlspecialchars($adminMessage, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <?php if ($adminError !== ''): ?>
                <p class="notice error"><?= htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <?php if ($isAdminAuthed): ?>
                <form method="post" class="availability-form">
                    <input type="hidden" name="action" value="save_availability">
                    <div class="form-row">
                        <label for="unit">Unit</label>
                        <select id="unit" name="unit" required>
                            <?php foreach ($adminUnitLabels as $unitKey => $unitName): ?>
                                <option value="<?= htmlspecialchars($unitKey, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($unitName, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="status">Status</label>
                        <select id="status" name="status" required>
                            <?php foreach ($statusLabels as $statusKey => $statusName): ?>
                                <option value="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($statusName, ENT_QUOTES, 'UTF-8') ?>
                                </option>
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
                    <a class="btn btn-secondary" href="sync-photos.php">Sync Photos Album</a>
                    <a class="btn btn-secondary" href="web-performance.php">Web Performance</a>
                    <a class="btn btn-secondary" href="admin.php">Admin Dashboard</a>
                </p>
            <?php else: ?>
                <p class="muted">Login is required to edit availability.</p>
                <p class="cta-row">
                    <a class="btn" href="admin.php">Go to Admin Login</a>
                </p>
            <?php endif; ?>
        </section>

        <section class="calendar-toolbar card">
            <div class="toolbar-links">
                <a class="btn btn-secondary"
                    href="calendar.php?month=<?= htmlspecialchars($prevMonth, ENT_QUOTES, 'UTF-8') ?>&unit=<?= htmlspecialchars($unitFilter, ENT_QUOTES, 'UTF-8') ?>">Previous
                    Month</a>
                <span class="month-title"><?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></span>
                <a class="btn btn-secondary"
                    href="calendar.php?month=<?= htmlspecialchars($nextMonth, ENT_QUOTES, 'UTF-8') ?>&unit=<?= htmlspecialchars($unitFilter, ENT_QUOTES, 'UTF-8') ?>">Next
                    Month</a>
            </div>
            <form method="get" class="filter-form">
                <input type="hidden" name="month" value="<?= htmlspecialchars($monthParam, ENT_QUOTES, 'UTF-8') ?>">
                <label for="unit_filter">View Unit</label>
                <select id="unit_filter" name="unit">
                    <option value="all" <?= $unitFilter === 'all' ? ' selected' : '' ?>>All Units</option>
                    <?php foreach ($viewUnitLabels as $unitKey => $unitName): ?>
                        <option value="<?= htmlspecialchars($unitKey, ENT_QUOTES, 'UTF-8') ?>" <?= $unitFilter === $unitKey ? ' selected' : '' ?>><?= htmlspecialchars($unitName, ENT_QUOTES, 'UTF-8') ?></option>
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
                <input type="hidden" name="month" value="<?= htmlspecialchars($monthParam, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="unit" value="<?= htmlspecialchars($unitFilter, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-row">
                    <label for="quote_unit">Unit</label>
                    <select id="quote_unit" name="quote_unit" required>
                        <?php foreach ($viewUnitLabels as $unitKey => $unitName): ?>
                            <option value="<?= htmlspecialchars($unitKey, ENT_QUOTES, 'UTF-8') ?>" <?= $quoteUnit === $unitKey ? ' selected' : '' ?>><?= htmlspecialchars($unitName, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row inline-fields">
                    <div>
                        <label for="quote_start">Start Date</label>
                        <input type="date" id="quote_start" name="quote_start"
                            value="<?= htmlspecialchars($quoteStart, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div>
                        <label for="quote_end">End Date</label>
                        <input type="date" id="quote_end" name="quote_end"
                            value="<?= htmlspecialchars($quoteEnd, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-secondary">Estimate Total</button>
            </form>
            <?php if ($quoteError !== ''): ?>
                <p class="notice error"><?= htmlspecialchars($quoteError, ENT_QUOTES, 'UTF-8') ?></p>
            <?php elseif (is_array($quoteSummary)): ?>
                <p class="notice success">
                    <?= htmlspecialchars('Days: ' . $quoteSummary['days'] . ' | Subtotal: INR ' . number_format((float) $quoteSummary['subtotal'], 2) . ' | Discount: INR ' . number_format((float) $quoteSummary['discount'], 2) . ' | Total: INR ' . number_format((float) $quoteSummary['total'], 2), ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>
        </section>

        <section class="calendar-wrap">
            <?php foreach ($unitsToRender as $unitKey => $unitName): ?>
                <article class="month-card">
                    <h2><?= htmlspecialchars($unitName, ENT_QUOTES, 'UTF-8') ?> -
                        <?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?>
                    </h2>
                    <table class="calendar-table"
                        aria-label="<?= htmlspecialchars($unitName, ENT_QUOTES, 'UTF-8') ?> availability">
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
                                            $status = getDisplayStatusForDate($entries, $unitKey, $dateKey, $twoBhkUnits);
                                            $price = getDisplayPriceForDate($prices, $unitKey, $dateKey, $twoBhkUnits);
                                            $title = $dateKey . ' - ' . ucfirst($status);
                                            if ($status === 'available' && $price !== null) {
                                                $title .= ' - INR ' . number_format($price, 2);
                                            }
                                            ?>
                                            <td class="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                                                title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>">
                                                <span
                                                    class="date-number"><?= htmlspecialchars($cell->format('j'), ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php if ($status === 'available' && $price !== null): ?>
                                                    <span class="date-price">INR
                                                        <?= htmlspecialchars(number_format($price, 0), ENT_QUOTES, 'UTF-8') ?></span>
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
    </main>
</body>

</html>