<?php
$albumDir = __DIR__ . '/assets/album';
$allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];
$photos = [];

if (is_dir($albumDir)) {
    $files = scandir($albumDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            continue;
        }

        $photos[] = $file;
    }
}

natcasesort($photos);
$photos = array_values($photos);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Photos | avaram.co Apartment Hotel</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <main class="container">
        <nav class="nav" aria-label="Main navigation">
            <a href="index.php">Home</a>
            <a href="about.php">About</a>
            <a href="services.php">Stay Options</a>
            <a href="photos.php" class="active">Photos</a>
            <a href="calendar.php">Calendar</a>
            <a href="directions.php">Directions</a>
            <a href="contact.php">Contact</a>
            <a href="admin.php">Admin</a>
        </nav>

        <h1>Property Photos</h1>
        <p>Photos imported from your Photos app album, Alagappanagar.</p>

        <section class="photo-grid">
            <?php if (!empty($photos)): ?>
                <?php foreach ($photos as $photo): ?>
                    <?php
                    $safeFile = htmlspecialchars($photo, ENT_QUOTES, 'UTF-8');
                    $urlFile = rawurlencode($photo);
                    ?>
                    <figure class="photo-card">
                        <img src="assets/album/<?= $urlFile ?>" alt="avaram.co property photo <?= $safeFile ?>">
                        <figcaption>
                            <?= $safeFile ?>
                        </figcaption>
                    </figure>
                <?php endforeach; ?>
            <?php else: ?>
                <figure class="photo-card">
                    <img src="assets/images/exterior-neem.svg" alt="Exterior view with neem trees">
                    <figcaption>Calm exterior and greenery.</figcaption>
                </figure>
                <figure class="photo-card">
                    <img src="assets/images/three-bhk.svg" alt="3BHK apartment interior">
                    <figcaption>Spacious 3BHK apartment.</figcaption>
                </figure>
                <figure class="photo-card">
                    <img src="assets/images/two-bhk.svg" alt="2BHK apartment interior">
                    <figcaption>Comfortable 2BHK unit.</figcaption>
                </figure>
                <figure class="photo-card">
                    <img src="assets/images/private-room.svg" alt="Private room with attached bath">
                    <figcaption>Private room with attached bath.</figcaption>
                </figure>
                <figure class="photo-card">
                    <img src="assets/images/living-area.svg" alt="Clean living area with natural light">
                    <figcaption>Bright and tidy common area.</figcaption>
                </figure>
            <?php endif; ?>
        </section>
    </main>
</body>

</html>