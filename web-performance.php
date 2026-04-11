<?php
session_start();

if (empty($_SESSION['avaram_co_admin_auth'])) {
    header('Location: admin.php');
    exit;
}

$startedAt = microtime(true);
$rootDir = __DIR__;
$availabilityFile = $rootDir . '/data/availability.json';
$albumDir = $rootDir . '/assets/album';

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float) $bytes;
    $index = 0;

    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return number_format($value, 2) . ' ' . $units[$index];
}

function folderStats(string $path): array
{
    if (!is_dir($path)) {
        return ['count' => 0, 'size' => 0, 'largest' => []];
    }

    $count = 0;
    $size = 0;
    $largest = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $count++;
        $fileSize = (int) $fileInfo->getSize();
        $size += $fileSize;
        $largest[] = [
            'name' => $fileInfo->getFilename(),
            'size' => $fileSize,
        ];
    }

    usort($largest, static fn($a, $b) => $b['size'] <=> $a['size']);
    $largest = array_slice($largest, 0, 8);

    return ['count' => $count, 'size' => $size, 'largest' => $largest];
}

$albumStats = folderStats($albumDir);
$assetsStats = folderStats($rootDir . '/assets');

$availabilityEntries = [];
$statusCounts = [
    'available' => 0,
    'booked' => 0,
    'blocked' => 0,
];

if (is_file($availabilityFile)) {
    $raw = @file_get_contents($availabilityFile);
    $decoded = json_decode((string) $raw, true);
    if (is_array($decoded) && isset($decoded['entries']) && is_array($decoded['entries'])) {
        $availabilityEntries = $decoded['entries'];
        foreach ($availabilityEntries as $status) {
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }
    }
}

$totalAvailabilityEntries = count($availabilityEntries);
$memoryUsage = memory_get_usage(true);
$peakMemoryUsage = memory_get_peak_usage(true);
$phpVersion = PHP_VERSION;
$serverSoftware = (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown');
$pageBuildMs = (microtime(true) - $startedAt) * 1000;

$tips = [
    'Convert PNG gallery photos to WebP where possible to reduce transfer size.',
    'Keep hero and gallery images below 250 KB each for faster mobile loading.',
    'Enable gzip or brotli compression in your local server config.',
    'Use browser caching headers for assets under assets/album and assets/css.',
    'Avoid very large photo dimensions if mobile-first speed is priority.',
];

if ((string) ($_GET['export'] ?? '') === 'csv') {
    $filename = 'web-performance-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fputcsv($out, ['Metric', 'Value']);
        fputcsv($out, ['Generated At', date(DATE_ATOM)]);
        fputcsv($out, ['Album Images Count', (string) $albumStats['count']]);
        fputcsv($out, ['Album Images Total Size (bytes)', (string) $albumStats['size']]);
        fputcsv($out, ['All Assets Count', (string) $assetsStats['count']]);
        fputcsv($out, ['All Assets Total Size (bytes)', (string) $assetsStats['size']]);
        fputcsv($out, ['Availability Entries', (string) $totalAvailabilityEntries]);
        fputcsv($out, ['Available Count', (string) $statusCounts['available']]);
        fputcsv($out, ['Booked Count', (string) $statusCounts['booked']]);
        fputcsv($out, ['Blocked Count', (string) $statusCounts['blocked']]);
        fputcsv($out, ['PHP Version', $phpVersion]);
        fputcsv($out, ['Server Software', $serverSoftware]);
        fputcsv($out, ['Current Memory Usage (bytes)', (string) $memoryUsage]);
        fputcsv($out, ['Peak Memory Usage (bytes)', (string) $peakMemoryUsage]);
        fputcsv($out, ['Page Build Time (ms)', number_format($pageBuildMs, 2, '.', '')]);

        fputcsv($out, []);
        fputcsv($out, ['Largest Album Files', 'Size (bytes)']);
        foreach ($albumStats['largest'] as $item) {
            fputcsv($out, [(string) $item['name'], (string) $item['size']]);
        }

        fclose($out);
    }
    exit;
}

if ((string) ($_GET['export'] ?? '') === 'json') {
    $filename = 'web-performance-' . date('Ymd-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $payload = [
        'generated_at' => date(DATE_ATOM),
        'album_images_count' => (int) $albumStats['count'],
        'album_images_total_size_bytes' => (int) $albumStats['size'],
        'all_assets_count' => (int) $assetsStats['count'],
        'all_assets_total_size_bytes' => (int) $assetsStats['size'],
        'availability_entries' => (int) $totalAvailabilityEntries,
        'status_counts' => [
            'available' => (int) $statusCounts['available'],
            'booked' => (int) $statusCounts['booked'],
            'blocked' => (int) $statusCounts['blocked'],
        ],
        'php_version' => (string) $phpVersion,
        'server_software' => (string) $serverSoftware,
        'current_memory_usage_bytes' => (int) $memoryUsage,
        'peak_memory_usage_bytes' => (int) $peakMemoryUsage,
        'page_build_time_ms' => (float) number_format($pageBuildMs, 2, '.', ''),
        'largest_album_files' => $albumStats['largest'],
    ];

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Web Performance | avaram.co Admin</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <main class="container">
        <nav class="nav" aria-label="Main navigation">
            <a href="index.php">Home</a>
            <a href="admin.php">Admin</a>
            <a href="calendar.php">Calendar</a>
            <a href="sync-photos.php">Sync Photos</a>
            <a href="web-performance.php" class="active">Web Performance</a>
        </nav>

        <h1>Web Performance Dashboard</h1>
        <p>Admin-only snapshot of image weight, availability data size, and PHP runtime stats.</p>
        <p class="cta-row">
            <a class="btn" href="web-performance.php?export=csv">Download CSV Report</a>
            <a class="btn btn-secondary" href="web-performance.php?export=json">Download JSON Report</a>
            <a class="btn btn-secondary" href="admin.php">Back to Admin</a>
        </p>

        <section class="metric-grid">
            <article class="card metric-card">
                <h2>Album Images</h2>
                <p class="metric-value"><?= htmlspecialchars((string) $albumStats['count'], ENT_QUOTES, 'UTF-8') ?></p>
                <p>Total size: <?= htmlspecialchars(formatBytes((int) $albumStats['size']), ENT_QUOTES, 'UTF-8') ?></p>
            </article>
            <article class="card metric-card">
                <h2>All Assets</h2>
                <p class="metric-value"><?= htmlspecialchars((string) $assetsStats['count'], ENT_QUOTES, 'UTF-8') ?></p>
                <p>Total size: <?= htmlspecialchars(formatBytes((int) $assetsStats['size']), ENT_QUOTES, 'UTF-8') ?></p>
            </article>
            <article class="card metric-card">
                <h2>Availability Entries</h2>
                <p class="metric-value"><?= htmlspecialchars((string) $totalAvailabilityEntries, ENT_QUOTES, 'UTF-8') ?>
                </p>
                <p>Booked: <?= htmlspecialchars((string) $statusCounts['booked'], ENT_QUOTES, 'UTF-8') ?> | Blocked:
                    <?= htmlspecialchars((string) $statusCounts['blocked'], ENT_QUOTES, 'UTF-8') ?>
                </p>
            </article>
            <article class="card metric-card">
                <h2>PHP Runtime</h2>
                <p class="metric-value"><?= htmlspecialchars($phpVersion, ENT_QUOTES, 'UTF-8') ?></p>
                <p>Build time: <?= htmlspecialchars(number_format($pageBuildMs, 2), ENT_QUOTES, 'UTF-8') ?> ms</p>
            </article>
        </section>

        <section class="card">
            <h2>Largest Album Files</h2>
            <?php if (!empty($albumStats['largest'])): ?>
                <table class="metric-table" aria-label="Largest album files">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($albumStats['largest'] as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $item['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(formatBytes((int) $item['size']), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No album files found in assets/album.</p>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Server Snapshot</h2>
            <ul class="clean-list">
                <li>Server software: <?= htmlspecialchars($serverSoftware, ENT_QUOTES, 'UTF-8') ?></li>
                <li>Current memory usage: <?= htmlspecialchars(formatBytes((int) $memoryUsage), ENT_QUOTES, 'UTF-8') ?>
                </li>
                <li>Peak memory usage: <?= htmlspecialchars(formatBytes((int) $peakMemoryUsage), ENT_QUOTES, 'UTF-8') ?>
                </li>
                <li>Report generated at: <?= htmlspecialchars(date(DATE_ATOM), ENT_QUOTES, 'UTF-8') ?></li>
            </ul>
        </section>

        <section class="card">
            <h2>Performance Recommendations</h2>
            <ul class="clean-list">
                <?php foreach ($tips as $tip): ?>
                    <li><?= htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    </main>
</body>

</html>