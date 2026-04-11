<?php
session_start();

$isAdminAuthed = !empty($_SESSION['avaram_co_admin_auth']);
$availabilityFile = __DIR__ . '/data/availability.json';
$bookingsFile = __DIR__ . '/data/bookings.json';

$adminUnitLabels = [
    '3bhk' => '3BHK',
    '2bhk-a' => '2BHK - A',
    '2bhk-b' => '2BHK - B',
    '2bhk-c' => '2BHK - C',
    'room' => 'Private Room + Bath',
];

$message = '';
$error = '';
$searchError = '';
$searchResults = [];
$editBooking = null;

if (!file_exists($availabilityFile)) {
    if (!is_dir(dirname($availabilityFile))) {
        mkdir(dirname($availabilityFile), 0775, true);
    }
    file_put_contents(
        $availabilityFile,
        json_encode(['updated_at' => date(DATE_ATOM), 'entries' => [], 'prices' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

if (!file_exists($bookingsFile)) {
    if (!is_dir(dirname($bookingsFile))) {
        mkdir(dirname($bookingsFile), 0775, true);
    }
    file_put_contents(
        $bookingsFile,
        json_encode(['updated_at' => date(DATE_ATOM), 'bookings' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

$availabilityRaw = @file_get_contents($availabilityFile);
$availabilityData = json_decode((string) $availabilityRaw, true);
if (!is_array($availabilityData)) {
    $availabilityData = ['updated_at' => date(DATE_ATOM), 'entries' => [], 'prices' => []];
}
if (!isset($availabilityData['entries']) || !is_array($availabilityData['entries'])) {
    $availabilityData['entries'] = [];
}
if (!isset($availabilityData['prices']) || !is_array($availabilityData['prices'])) {
    $availabilityData['prices'] = [];
}

$bookingsRaw = @file_get_contents($bookingsFile);
$bookingsData = json_decode((string) $bookingsRaw, true);
if (!is_array($bookingsData)) {
    $bookingsData = ['updated_at' => date(DATE_ATOM), 'bookings' => []];
}
if (!isset($bookingsData['bookings']) || !is_array($bookingsData['bookings'])) {
    $bookingsData['bookings'] = [];
}
if (!isset($bookingsData['history']) || !is_array($bookingsData['history'])) {
    $bookingsData['history'] = [];
}

$entries = $availabilityData['entries'];
$prices = $availabilityData['prices'];
$bookings = $bookingsData['bookings'];

function getActorLabel(): string
{
    $ip = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    if (strpos($ip, ',') !== false) {
        $parts = explode(',', $ip);
        $ip = trim((string) ($parts[0] ?? 'unknown'));
    }

    return 'admin@' . $ip;
}

function appendAuditEvent(array &$bookingsData, string $action, string $bookingId, array $details = []): void
{
    if (!isset($bookingsData['history']) || !is_array($bookingsData['history'])) {
        $bookingsData['history'] = [];
    }

    $bookingsData['history'][] = [
        'event_id' => 'EV-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 6),
        'booking_id' => $bookingId,
        'action' => $action,
        'actor' => getActorLabel(),
        'details' => $details,
        'created_at' => date(DATE_ATOM),
    ];

    if (count($bookingsData['history']) > 300) {
        $bookingsData['history'] = array_slice($bookingsData['history'], -300);
    }
}

function validateDateInput(string $date): ?DateTimeImmutable
{
    $obj = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$obj || $obj->format('Y-m-d') !== $date) {
        return null;
    }

    return $obj;
}

function calculateRangePrice(
    array $entries,
    array $prices,
    string $unit,
    DateTimeImmutable $start,
    DateTimeImmutable $end,
    ?array $ignoreBooking = null
): array {
    $cursor = $start;
    $total = 0.0;
    $days = 0;

    while ($cursor <= $end) {
        $dateKey = $cursor->format('Y-m-d');
        $entryKey = $unit . '|' . $dateKey;
        $status = $entries[$entryKey] ?? 'available';

        if (is_array($ignoreBooking)) {
            $ignoreUnit = (string) ($ignoreBooking['unit'] ?? '');
            $ignoreStart = validateDateInput((string) ($ignoreBooking['start_date'] ?? ''));
            $ignoreEnd = validateDateInput((string) ($ignoreBooking['end_date'] ?? ''));
            if (
                $ignoreUnit === $unit
                && $ignoreStart instanceof DateTimeImmutable
                && $ignoreEnd instanceof DateTimeImmutable
                && $cursor >= $ignoreStart
                && $cursor <= $ignoreEnd
                && $status === 'booked'
            ) {
                $status = 'available';
            }
        }

        if (!in_array($status, ['available', 'booked', 'blocked'], true)) {
            $status = 'available';
        }

        if ($status !== 'available') {
            return [
                'ok' => false,
                'message' => 'Selected range includes non-available date: ' . $dateKey,
            ];
        }

        if (!isset($prices[$entryKey]) || !is_numeric($prices[$entryKey])) {
            return [
                'ok' => false,
                'message' => 'Price is not set for date: ' . $dateKey,
            ];
        }

        $total += (float) $prices[$entryKey];
        $days++;
        $cursor = $cursor->modify('+1 day');
    }

    return [
        'ok' => true,
        'days' => $days,
        'total' => round($total, 2),
    ];
}

function rangesOverlap(DateTimeImmutable $aStart, DateTimeImmutable $aEnd, DateTimeImmutable $bStart, DateTimeImmutable $bEnd): bool
{
    return $aStart <= $bEnd && $aEnd >= $bStart;
}

function findBookingIndexById(array $bookings, string $bookingId): ?int
{
    foreach ($bookings as $index => $booking) {
        if ((string) ($booking['id'] ?? '') === $bookingId) {
            return (int) $index;
        }
    }

    return null;
}

function setRangeStatus(array &$entries, string $unit, DateTimeImmutable $start, DateTimeImmutable $end, string $status): void
{
    $cursor = $start;
    while ($cursor <= $end) {
        $entries[$unit . '|' . $cursor->format('Y-m-d')] = $status;
        $cursor = $cursor->modify('+1 day');
    }
}

if ($isAdminAuthed && isset($_GET['edit_id'])) {
    $requestedEditId = trim((string) $_GET['edit_id']);
    if ($requestedEditId !== '') {
        $requestedEditIndex = findBookingIndexById($bookings, $requestedEditId);
        if ($requestedEditIndex !== null) {
            $candidateBooking = $bookings[$requestedEditIndex];
            if ((string) ($candidateBooking['status'] ?? 'active') === 'active') {
                $editBooking = $candidateBooking;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!$isAdminAuthed) {
        $error = 'Login required. Please sign in on the Admin page.';
    } elseif ($action === 'save_booking') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $unit = (string) ($_POST['unit'] ?? '');
        $startDate = (string) ($_POST['start_date'] ?? '');
        $endDate = (string) ($_POST['end_date'] ?? '');

        $today = new DateTimeImmutable(date('Y-m-d'));
        $startObj = validateDateInput($startDate);
        $endObj = validateDateInput($endDate);

        if ($name === '') {
            $error = 'Name is required.';
        } elseif ($phone === '' || !preg_match('/^[0-9+\-\s]{7,20}$/', $phone)) {
            $error = 'Enter a valid mobile phone number.';
        } elseif (!isset($adminUnitLabels[$unit])) {
            $error = 'Select a valid unit.';
        } elseif (!$startObj || !$endObj) {
            $error = 'Use valid start and end dates.';
        } elseif ($startObj > $endObj) {
            $error = 'Start date must be before or equal to end date.';
        } elseif ($startObj < $today) {
            $error = 'Booking start date must be today or later.';
        } else {
            $priceResult = calculateRangePrice($entries, $prices, $unit, $startObj, $endObj);

            if (!$priceResult['ok']) {
                $error = $priceResult['message'];
            } else {
                $bookingId = 'BK-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
                $createdAt = date(DATE_ATOM);

                $bookings[] = [
                    'id' => $bookingId,
                    'name' => $name,
                    'phone' => $phone,
                    'unit' => $unit,
                    'start_date' => $startObj->format('Y-m-d'),
                    'end_date' => $endObj->format('Y-m-d'),
                    'days' => $priceResult['days'],
                    'total_price' => $priceResult['total'],
                    'status' => 'active',
                    'created_at' => $createdAt,
                ];

                appendAuditEvent($bookingsData, 'created', $bookingId, [
                    'name' => $name,
                    'phone' => $phone,
                    'unit' => $unit,
                    'start_date' => $startObj->format('Y-m-d'),
                    'end_date' => $endObj->format('Y-m-d'),
                    'days' => $priceResult['days'],
                    'total_price' => $priceResult['total'],
                ]);

                // Lock the booked dates immediately after creating the booking.
                $cursor = $startObj;
                while ($cursor <= $endObj) {
                    $entryKey = $unit . '|' . $cursor->format('Y-m-d');
                    $entries[$entryKey] = 'booked';
                    $cursor = $cursor->modify('+1 day');
                }

                $availabilityData['entries'] = $entries;
                $availabilityData['updated_at'] = date(DATE_ATOM);
                file_put_contents(
                    $availabilityFile,
                    json_encode($availabilityData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    LOCK_EX
                );

                $bookingsData['bookings'] = $bookings;
                $bookingsData['updated_at'] = date(DATE_ATOM);
                file_put_contents(
                    $bookingsFile,
                    json_encode($bookingsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    LOCK_EX
                );

                $message = 'Booking logged successfully. Total price: INR ' . number_format((float) $priceResult['total'], 2);
            }
        }
    } elseif ($action === 'update_booking') {
        $bookingId = trim((string) ($_POST['booking_id'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $unit = (string) ($_POST['unit'] ?? '');
        $startDate = (string) ($_POST['start_date'] ?? '');
        $endDate = (string) ($_POST['end_date'] ?? '');

        $bookingIndex = findBookingIndexById($bookings, $bookingId);
        $existingBooking = $bookingIndex !== null ? $bookings[$bookingIndex] : null;

        $today = new DateTimeImmutable(date('Y-m-d'));
        $startObj = validateDateInput($startDate);
        $endObj = validateDateInput($endDate);

        if ($bookingIndex === null || !is_array($existingBooking)) {
            $error = 'Booking not found for update.';
        } elseif ((string) ($existingBooking['status'] ?? 'active') !== 'active') {
            $error = 'Canceled booking cannot be updated.';
        } elseif ($name === '') {
            $error = 'Name is required.';
        } elseif ($phone === '' || !preg_match('/^[0-9+\-\s]{7,20}$/', $phone)) {
            $error = 'Enter a valid mobile phone number.';
        } elseif (!isset($adminUnitLabels[$unit])) {
            $error = 'Select a valid unit.';
        } elseif (!$startObj || !$endObj) {
            $error = 'Use valid start and end dates.';
        } elseif ($startObj > $endObj) {
            $error = 'Start date must be before or equal to end date.';
        } elseif ($startObj < $today) {
            $error = 'Booking start date must be today or later.';
        } else {
            $priceResult = calculateRangePrice($entries, $prices, $unit, $startObj, $endObj, $existingBooking);

            if (!$priceResult['ok']) {
                $error = $priceResult['message'];
            } else {
                $oldUnit = (string) ($existingBooking['unit'] ?? '');
                $oldStart = validateDateInput((string) ($existingBooking['start_date'] ?? ''));
                $oldEnd = validateDateInput((string) ($existingBooking['end_date'] ?? ''));

                if ($oldStart instanceof DateTimeImmutable && $oldEnd instanceof DateTimeImmutable && isset($adminUnitLabels[$oldUnit])) {
                    // Release previous range before locking the updated range.
                    setRangeStatus($entries, $oldUnit, $oldStart, $oldEnd, 'available');
                }
                setRangeStatus($entries, $unit, $startObj, $endObj, 'booked');

                $bookings[$bookingIndex] = [
                    'id' => $bookingId,
                    'name' => $name,
                    'phone' => $phone,
                    'unit' => $unit,
                    'start_date' => $startObj->format('Y-m-d'),
                    'end_date' => $endObj->format('Y-m-d'),
                    'days' => $priceResult['days'],
                    'total_price' => $priceResult['total'],
                    'status' => 'active',
                    'created_at' => (string) ($existingBooking['created_at'] ?? date(DATE_ATOM)),
                    'updated_at' => date(DATE_ATOM),
                ];

                appendAuditEvent($bookingsData, 'updated', $bookingId, [
                    'name' => $name,
                    'phone' => $phone,
                    'unit' => $unit,
                    'start_date' => $startObj->format('Y-m-d'),
                    'end_date' => $endObj->format('Y-m-d'),
                    'days' => $priceResult['days'],
                    'total_price' => $priceResult['total'],
                ]);

                $availabilityData['entries'] = $entries;
                $availabilityData['updated_at'] = date(DATE_ATOM);
                file_put_contents(
                    $availabilityFile,
                    json_encode($availabilityData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    LOCK_EX
                );

                $bookingsData['bookings'] = $bookings;
                $bookingsData['updated_at'] = date(DATE_ATOM);
                file_put_contents(
                    $bookingsFile,
                    json_encode($bookingsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    LOCK_EX
                );

                $editBooking = $bookings[$bookingIndex];
                $message = 'Booking updated successfully. Total price: INR ' . number_format((float) $priceResult['total'], 2);
            }
        }
    } elseif ($action === 'cancel_booking') {
        $bookingId = trim((string) ($_POST['booking_id'] ?? ''));
        $bookingIndex = findBookingIndexById($bookings, $bookingId);
        if ($bookingIndex === null) {
            $error = 'Booking not found for cancellation.';
        } else {
            $existingBooking = $bookings[$bookingIndex];
            if ((string) ($existingBooking['status'] ?? 'active') !== 'active') {
                $error = 'Booking is already canceled.';
            } else {
                $oldUnit = (string) ($existingBooking['unit'] ?? '');
                $oldStart = validateDateInput((string) ($existingBooking['start_date'] ?? ''));
                $oldEnd = validateDateInput((string) ($existingBooking['end_date'] ?? ''));

                if ($oldStart instanceof DateTimeImmutable && $oldEnd instanceof DateTimeImmutable && isset($adminUnitLabels[$oldUnit])) {
                    // Release dates only when they are currently marked booked.
                    $cursor = $oldStart;
                    while ($cursor <= $oldEnd) {
                        $dateKey = $cursor->format('Y-m-d');
                        $entryKey = $oldUnit . '|' . $dateKey;
                        if (($entries[$entryKey] ?? 'available') === 'booked') {
                            $entries[$entryKey] = 'available';
                        }
                        $cursor = $cursor->modify('+1 day');
                    }
                }

                $bookings[$bookingIndex]['status'] = 'canceled';
                $bookings[$bookingIndex]['canceled_at'] = date(DATE_ATOM);
                $bookings[$bookingIndex]['updated_at'] = date(DATE_ATOM);

                appendAuditEvent($bookingsData, 'canceled', $bookingId, [
                    'name' => (string) ($existingBooking['name'] ?? ''),
                    'phone' => (string) ($existingBooking['phone'] ?? ''),
                    'unit' => (string) ($existingBooking['unit'] ?? ''),
                    'start_date' => (string) ($existingBooking['start_date'] ?? ''),
                    'end_date' => (string) ($existingBooking['end_date'] ?? ''),
                    'total_price' => (float) ($existingBooking['total_price'] ?? 0),
                ]);

                $availabilityData['entries'] = $entries;
                $availabilityData['updated_at'] = date(DATE_ATOM);
                file_put_contents(
                    $availabilityFile,
                    json_encode($availabilityData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    LOCK_EX
                );

                $bookingsData['bookings'] = $bookings;
                $bookingsData['updated_at'] = date(DATE_ATOM);
                file_put_contents(
                    $bookingsFile,
                    json_encode($bookingsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    LOCK_EX
                );

                if (is_array($editBooking) && (string) ($editBooking['id'] ?? '') === $bookingId) {
                    $editBooking = null;
                }

                $message = 'Booking canceled and dates released successfully.';
            }
        }
    } elseif ($action === 'search_bookings') {
        $searchStart = (string) ($_POST['search_start'] ?? '');
        $searchEnd = (string) ($_POST['search_end'] ?? '');

        $searchStartObj = validateDateInput($searchStart);
        $searchEndObj = validateDateInput($searchEnd);

        if (!$searchStartObj || !$searchEndObj) {
            $searchError = 'Use valid search start and end dates.';
        } elseif ($searchStartObj > $searchEndObj) {
            $searchError = 'Search start date must be before or equal to end date.';
        } else {
            foreach ($bookings as $booking) {
                $bookingStart = validateDateInput((string) ($booking['start_date'] ?? ''));
                $bookingEnd = validateDateInput((string) ($booking['end_date'] ?? ''));
                if (!$bookingStart || !$bookingEnd) {
                    continue;
                }

                if (rangesOverlap($bookingStart, $bookingEnd, $searchStartObj, $searchEndObj)) {
                    $searchResults[] = $booking;
                }
            }

            usort($searchResults, static function (array $a, array $b): int {
                return strcmp((string) ($a['start_date'] ?? ''), (string) ($b['start_date'] ?? ''));
            });
        }
    }
}

$recentBookings = $bookings;
usort($recentBookings, static function (array $a, array $b): int {
    return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
});
$recentBookings = array_slice($recentBookings, 0, 10);

$recentHistory = $bookingsData['history'];
usort($recentHistory, static function (array $a, array $b): int {
    return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
});
$recentHistory = array_slice($recentHistory, 0, 20);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking Log | avaram.co Apartment Hotel</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <main class="container">
        <nav class="nav" aria-label="Main navigation">
            <a href="index.php">Home</a>
            <a href="calendar.php">Availability calendar</a>
            <a href="booking-log.php" class="active">Booking Log</a>
            <a href="photos.php">Photos</a>
            <a href="admin.php">Admin</a>
        </nav>

        <h1>Booking Logging</h1>
        <p>Create booking logs with auto price calculation from per-day rates in the availability calendar.</p>

        <?php if ($message !== ''): ?>
            <p class="notice success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if (!$isAdminAuthed): ?>
            <section class="card">
                <h2>Login Required</h2>
                <p>Open the admin page and login first to create or retrieve bookings.</p>
                <a class="btn" href="admin.php">Go to Admin Login</a>
            </section>
        <?php else: ?>
            <?php if (is_array($editBooking)): ?>
                <section class="card">
                    <h2>Edit Booking</h2>
                    <form method="post" class="availability-form">
                        <input type="hidden" name="action" value="update_booking">
                        <input type="hidden" name="booking_id"
                            value="<?= htmlspecialchars((string) ($editBooking['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="form-row">
                            <label for="edit_name">Guest Name</label>
                            <input type="text" id="edit_name" name="name"
                                value="<?= htmlspecialchars((string) ($editBooking['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                required>
                        </div>
                        <div class="form-row">
                            <label for="edit_phone">Mobile Phone Number</label>
                            <input type="text" id="edit_phone" name="phone"
                                value="<?= htmlspecialchars((string) ($editBooking['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                required>
                        </div>
                        <div class="form-row">
                            <label for="edit_unit">Unit</label>
                            <select id="edit_unit" name="unit" required>
                                <?php foreach ($adminUnitLabels as $unitKey => $unitName): ?>
                                    <option value="<?= htmlspecialchars($unitKey, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($editBooking['unit'] ?? '') === $unitKey ? ' selected' : '' ?>>
                                        <?= htmlspecialchars($unitName, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row inline-fields">
                            <div>
                                <label for="edit_start_date">Start Date</label>
                                <input type="date" id="edit_start_date" name="start_date"
                                    min="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>"
                                    value="<?= htmlspecialchars((string) ($editBooking['start_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                    required>
                            </div>
                            <div>
                                <label for="edit_end_date">End Date</label>
                                <input type="date" id="edit_end_date" name="end_date"
                                    min="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>"
                                    value="<?= htmlspecialchars((string) ($editBooking['end_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                    required>
                            </div>
                        </div>
                        <p class="muted">Price will be recalculated automatically from current daily prices for available days.
                        </p>
                        <p class="cta-row">
                            <button type="submit" class="btn">Update Booking</button>
                            <a class="btn btn-secondary" href="booking-log.php">Close Edit</a>
                        </p>
                    </form>
                </section>
            <?php endif; ?>

            <section class="card">
                <h2>Log New Booking</h2>
                <form method="post" class="availability-form">
                    <input type="hidden" name="action" value="save_booking">
                    <div class="form-row">
                        <label for="name">Guest Name</label>
                        <input type="text" id="name" name="name" required>
                    </div>
                    <div class="form-row">
                        <label for="phone">Mobile Phone Number</label>
                        <input type="text" id="phone" name="phone" placeholder="e.g. +91 9876543210" required>
                    </div>
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
                    <div class="form-row inline-fields">
                        <div>
                            <label for="start_date">Start Date</label>
                            <input type="date" id="start_date" name="start_date"
                                min="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div>
                            <label for="end_date">End Date</label>
                            <input type="date" id="end_date" name="end_date"
                                min="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                    </div>
                    <p class="muted">Price is auto-calculated from daily prices in the selected range. Booking can only be
                        logged when all days are available.</p>
                    <button type="submit" class="btn">Save Booking</button>
                </form>
            </section>

            <section class="card">
                <h2>Retrieve Bookings By Date Range</h2>
                <form method="post" class="availability-form">
                    <input type="hidden" name="action" value="search_bookings">
                    <div class="form-row inline-fields">
                        <div>
                            <label for="search_start">From Date</label>
                            <input type="date" id="search_start" name="search_start" required>
                        </div>
                        <div>
                            <label for="search_end">To Date</label>
                            <input type="date" id="search_end" name="search_end" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary">Search Bookings</button>
                </form>
                <?php if ($searchError !== ''): ?>
                    <p class="notice error"><?= htmlspecialchars($searchError, ENT_QUOTES, 'UTF-8') ?></p>
                <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'search_bookings'): ?>
                    <?php if (empty($searchResults)): ?>
                        <p class="muted">No bookings found for the selected date range.</p>
                    <?php else: ?>
                        <table class="metric-table">
                            <thead>
                                <tr>
                                    <th>Booking ID</th>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th>Unit</th>
                                    <th>Date Range</th>
                                    <th>Price (INR)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($searchResults as $booking): ?>
                                    <?php $bookingStatus = (string) ($booking['status'] ?? 'active'); ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($booking['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($booking['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($booking['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($adminUnitLabels[(string) ($booking['unit'] ?? '')] ?? (string) ($booking['unit'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($booking['start_date'] ?? '') . ' to ' . (string) ($booking['end_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td><?= htmlspecialchars(number_format((float) ($booking['total_price'] ?? 0), 2), ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td><?= htmlspecialchars(ucfirst($bookingStatus), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="card">
                <h2>Recent Bookings</h2>
                <?php if (empty($recentBookings)): ?>
                    <p class="muted">No bookings logged yet.</p>
                <?php else: ?>
                    <table class="metric-table">
                        <thead>
                            <tr>
                                <th>Booking ID</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Unit</th>
                                <th>Date Range</th>
                                <th>Price (INR)</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentBookings as $booking): ?>
                                <?php $bookingStatus = (string) ($booking['status'] ?? 'active'); ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($booking['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($booking['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($booking['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($adminUnitLabels[(string) ($booking['unit'] ?? '')] ?? (string) ($booking['unit'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($booking['start_date'] ?? '') . ' to ' . (string) ($booking['end_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td><?= htmlspecialchars(number_format((float) ($booking['total_price'] ?? 0), 2), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td><?= htmlspecialchars(ucfirst($bookingStatus), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if ($bookingStatus === 'active'): ?>
                                            <p class="cta-row">
                                                <a class="btn btn-secondary"
                                                    href="booking-log.php?edit_id=<?= htmlspecialchars((string) ($booking['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">Edit</a>
                                            </p>
                                            <form method="post" onsubmit="return confirm('Cancel this booking and release dates?');">
                                                <input type="hidden" name="action" value="cancel_booking">
                                                <input type="hidden" name="booking_id"
                                                    value="<?= htmlspecialchars((string) ($booking['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="btn">Cancel</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted">No actions</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="card">
                <h2>Audit Trail</h2>
                <?php if (empty($recentHistory)): ?>
                    <p class="muted">No audit events yet.</p>
                <?php else: ?>
                    <table class="metric-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Booking ID</th>
                                <th>Action</th>
                                <th>Actor</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentHistory as $event): ?>
                                <?php
                                $detailParts = [];
                                if (is_array($event['details'] ?? null)) {
                                    foreach ($event['details'] as $key => $value) {
                                        if (is_scalar($value)) {
                                            $detailParts[] = (string) $key . ': ' . (string) $value;
                                        }
                                    }
                                }
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($event['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($event['booking_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(ucfirst((string) ($event['action'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($event['actor'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(implode(' | ', $detailParts), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>

</html>