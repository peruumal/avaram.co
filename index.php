<?php
declare(strict_types=1);

$host = $_SERVER['HTTP_HOST'] ?? '';
$isProductionHost = $host === 'avaram.co' || $host === 'www.avaram.co';

if ($isProductionHost) {
    define('WP_USE_THEMES', true);
    // On production WP lives at public_html root; locally it's in /wordpress/
    $wpBlogHeader = file_exists(__DIR__ . '/wp-blog-header.php')
        ? __DIR__ . '/wp-blog-header.php'
        : __DIR__ . '/wordpress/wp-blog-header.php';
    require $wpBlogHeader;
    exit;
}

$wordpressHome = '/wordpress/';
header('Location: ' . $wordpressHome, true, 302);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta http-equiv="refresh" content="0;url=/wordpress/">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Redirecting...</title>
</head>

<body>
    <p><a href="/wordpress/">Continue to the home page</a></p>
</body>

</html>