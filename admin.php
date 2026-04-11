<?php
session_start();

$defaultAdminKey = 'avaram.co2026';
$expectedAdminKey = getenv('AVARAM_CO_ADMIN_KEY') ?: $defaultAdminKey;
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        $adminKey = trim((string) ($_POST['admin_key'] ?? ''));
        if ($adminKey === $expectedAdminKey) {
            $_SESSION['avaram_co_admin_auth'] = true;
            $_SESSION['avaram_co_admin_since'] = date(DATE_ATOM);
            $message = 'Login successful.';
        } else {
            $error = 'Invalid admin key.';
        }
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        session_start();
        $message = 'Logged out successfully.';
    }
}

$isAuthed = !empty($_SESSION['avaram_co_admin_auth']);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin | avaram.co Apartment Hotel</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <main class="container">
        <nav class="nav" aria-label="Main navigation">
            <a href="index.php">Home</a>
            <a href="calendar.php">Availability calendar</a>
            <a href="photos.php">Photos</a>
            <a href="admin.php" class="active">Admin</a>
        </nav>

        <h1>Admin Tools</h1>
        <p>Use this page to access secure updates for availability, per-day pricing, and photos sync.</p>

        <?php if ($message !== ''): ?>
            <p class="notice success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if (!$isAuthed): ?>
            <section class="card admin-panel">
                <h2>Login</h2>
                <form method="post" class="availability-form">
                    <input type="hidden" name="action" value="login">
                    <div class="form-row">
                        <label for="admin_key">Admin Key</label>
                        <input type="password" id="admin_key" name="admin_key" placeholder="Enter admin key" required>
                    </div>
                    <button type="submit" class="btn">Sign In</button>
                    <p class="muted">Default key: avaram.co2026. Recommended: set AVARAM_CO_ADMIN_KEY in your server
                        environment.</p>
                </form>
            </section>
        <?php else: ?>
            <section class="grid">
                <article class="card">
                    <h2>Availability + Pricing Editor</h2>
                    <p>Update booking status and per-day price by date range for each unit.</p>
                    <a class="btn" href="calendar.php">Open Calendar Admin</a>
                </article>
                <article class="card">
                    <h2>Photos Album Sync</h2>
                    <p>Pull latest images from Photos app album to website gallery.</p>
                    <a class="btn" href="sync-photos.php">Open Sync Page</a>
                </article>
                <article class="card">
                    <h2>Web Performance</h2>
                    <p>Review image size, asset load, and runtime metrics.</p>
                    <a class="btn" href="web-performance.php">Open Performance Page</a>
                </article>
                <article class="card">
                    <h2>Booking Log</h2>
                    <p>Create bookings with auto price calculation and search by date range.</p>
                    <a class="btn" href="booking-log.php">Open Booking Log</a>
                </article>
            </section>

            <section class="card">
                <h2>Session</h2>
                <p>Signed in since:
                    <?= htmlspecialchars((string) ($_SESSION['avaram_co_admin_since'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <form method="post">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="btn btn-secondary">Logout</button>
                </form>
            </section>
        <?php endif; ?>
    </main>
</body>

</html>