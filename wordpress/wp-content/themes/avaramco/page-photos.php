<?php
get_header();

// Get album directory - calculate relative to this theme file location
$themeDir = dirname(__FILE__);
$albumDir = dirname($themeDir, 4) . '/assets/album';

// List of supported image extensions
$allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];
$photos = [];

// Scan album directory for images
if (is_dir($albumDir)) {
    $files = @scandir($albumDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $filePath = $albumDir . '/' . $file;
        if (!is_file($filePath)) {
            continue;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            continue;
        }

        $photos[] = $file;
    }
}

// Sort photos naturally by filename
natcasesort($photos);
$photos = array_values($photos);
?>

<h1>Property Photos</h1>
<p>Photos imported from your Photos app album, Alagappanagar.</p>

<section class="photo-grid">
    <?php if (!empty($photos)): ?>
        <?php foreach ($photos as $photo): ?>
            <?php
            $urlFile = rawurlencode($photo);
            $albumImageUrl = avaramco_get_album_image_url($photo);
            ?>
            <figure class="photo-card">
                <img src="<?php echo esc_url($albumImageUrl); ?>"
                    alt="<?php echo esc_attr('avaram.co property photo ' . $photo); ?>" loading="lazy" class="album-photo">
                <figcaption>
                    <?php echo esc_html($photo); ?>
                </figcaption>
            </figure>
        <?php endforeach; ?>
    <?php else: ?>
        <p style="color: #999; font-style: italic; margin: 2rem 0;">No album images found. Showing placeholder gallery.</p>
        <figure class="photo-card">
            <img src="<?php echo esc_url(home_url('/assets/images/exterior-neem.svg')); ?>"
                alt="Exterior view with neem trees" style="background: #f0f0f0;">
            <figcaption>Calm exterior and greenery.</figcaption>
        </figure>
        <figure class="photo-card">
            <img src="<?php echo esc_url(home_url('/assets/images/three-bhk.svg')); ?>" alt="3BHK apartment interior"
                style="background: #f0f0f0;">
            <figcaption>Spacious 3BHK apartment.</figcaption>
        </figure>
        <figure class="photo-card">
            <img src="<?php echo esc_url(home_url('/assets/images/two-bhk.svg')); ?>" alt="2BHK apartment interior"
                style="background: #f0f0f0;">
            <figcaption>Comfortable 2BHK unit.</figcaption>
        </figure>
        <figure class="photo-card">
            <img src="<?php echo esc_url(home_url('/assets/images/private-room.svg')); ?>"
                alt="Private room with attached bath" style="background: #f0f0f0;">
            <figcaption>Private room with attached bath.</figcaption>
        </figure>
        <figure class="photo-card">
            <img src="<?php echo esc_url(home_url('/assets/images/living-area.svg')); ?>"
                alt="Clean living area with natural light" style="background: #f0f0f0;">
            <figcaption>Bright and tidy common area.</figcaption>
        </figure>
    <?php endif; ?>
</section>

<?php
get_footer();
