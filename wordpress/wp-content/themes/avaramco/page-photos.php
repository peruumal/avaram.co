<?php
get_header();

$albumDir = dirname(__DIR__, 4) . '/assets/album';
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

<h1>Property Photos</h1>
<p>Photos imported from your Photos app album, Alagappanagar.</p>

<section class="photo-grid">
    <?php if (!empty($photos)): ?>
        <?php foreach ($photos as $photo): ?>
            <?php
            $urlFile = rawurlencode($photo);
            ?>
            <figure class="photo-card">
                <img src="<?php echo esc_url('/assets/album/' . $urlFile); ?>"
                    alt="<?php echo esc_attr('avaram.co property photo ' . $photo); ?>">
                <figcaption>
                    <?php echo esc_html($photo); ?>
                </figcaption>
            </figure>
        <?php endforeach; ?>
    <?php else: ?>
        <figure class="photo-card">
            <img src="<?php echo esc_url('/assets/images/exterior-neem.svg'); ?>" alt="Exterior view with neem trees">
            <figcaption>Calm exterior and greenery.</figcaption>
        </figure>
        <figure class="photo-card">
            <img src="<?php echo esc_url('/assets/images/three-bhk.svg'); ?>" alt="3BHK apartment interior">
            <figcaption>Spacious 3BHK apartment.</figcaption>
        </figure>
        <figure class="photo-card">
            <img src="<?php echo esc_url('/assets/images/two-bhk.svg'); ?>" alt="2BHK apartment interior">
            <figcaption>Comfortable 2BHK unit.</figcaption>
        </figure>
        <figure class="photo-card">
            <img src="<?php echo esc_url('/assets/images/private-room.svg'); ?>" alt="Private room with attached bath">
            <figcaption>Private room with attached bath.</figcaption>
        </figure>
        <figure class="photo-card">
            <img src="<?php echo esc_url('/assets/images/living-area.svg'); ?>" alt="Clean living area with natural light">
            <figcaption>Bright and tidy common area.</figcaption>
        </figure>
    <?php endif; ?>
</section>

<?php
get_footer();
