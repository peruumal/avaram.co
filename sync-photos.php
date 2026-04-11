<?php
session_start();

if (empty($_SESSION['avaram_co_admin_auth'])) {
    header('Location: admin.php');
    exit;
}

$albumName = (string) ($_POST['album_name'] ?? 'Alagappanagar ');
$albumName = trim($albumName) === '' ? 'Alagappanagar ' : $albumName;
$message = '';
$error = '';
$syncOutput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'sync') {
    $cleanAlbum = preg_replace('/[^[:alnum:] \-_,.&()]/u', '', $albumName) ?: 'Alagappanagar ';
    $targetDir = __DIR__ . '/assets/album';

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }

    $appleExportPath = addslashes($targetDir . '/');

    $lines = [
        'set exportPath to POSIX file "' . $appleExportPath . '"',
        'tell application "Photos"',
        'set targetAlbum to album "' . addslashes($cleanAlbum) . '"',
        'set mediaList to (get media items of targetAlbum)',
        'if (count of mediaList) is 0 then error "Album has no media items."',
        'export mediaList to exportPath with using originals',
        'end tell',
    ];

    $parts = [];
    foreach ($lines as $line) {
        $parts[] = '-e ' . escapeshellarg($line);
    }
    $command = 'osascript ' . implode(' ', $parts) . ' 2>&1';

    $result = shell_exec($command);
    $syncOutput = is_string($result) ? trim($result) : '';

    $count = 0;
    $files = @scandir($targetDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        if (is_file($targetDir . '/' . $file)) {
            $count++;
        }
    }

    if ($count > 0) {
        $message = 'Album sync completed. Total files available: ' . $count . '.';
    } else {
        $error = 'No files were synced. Check album name and Photos app permissions.';
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Photos Sync | avaram.co Admin</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <main class="container">
        <nav class="nav" aria-label="Main navigation">
            <a href="index.php">Home</a>
            <a href="photos.php">Photos</a>
            <a href="calendar.php">Calendar</a>
            <a href="admin.php">Admin</a>
            <a href="sync-photos.php" class="active">Sync Photos</a>
        </nav>

        <h1>Sync Photos Album</h1>
        <p>Import images from macOS Photos directly into website gallery folder.</p>

        <?php if ($message !== ''): ?>
            <p class="notice success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <section class="card admin-panel">
            <h2>Run Album Sync</h2>
            <form method="post" class="availability-form">
                <input type="hidden" name="action" value="sync">
                <div class="form-row">
                    <label for="album_name">Photos Album Name</label>
                    <input id="album_name" name="album_name"
                        value="<?= htmlspecialchars($albumName, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <button type="submit" class="btn">Sync Now</button>
                <p class="muted">Current target folder: assets/album</p>
            </form>
        </section>

        <?php if ($syncOutput !== ''): ?>
            <section class="card">
                <h2>Sync Output</h2>
                <pre class="terminal-output"><?= htmlspecialchars($syncOutput, ENT_QUOTES, 'UTF-8') ?></pre>
            </section>
        <?php endif; ?>
    </main>
</body>

</html>