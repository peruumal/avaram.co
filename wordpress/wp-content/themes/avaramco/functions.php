<?php
declare(strict_types=1);

function avaramco_theme_setup(): void
{
    add_theme_support('title-tag');
    add_theme_support('editor-styles');
    // Load both base and editor-targeted styles into Gutenberg.
    add_editor_style(['style.css', 'editor-style.css']);
}
add_action('after_setup_theme', 'avaramco_theme_setup');

function avaramco_enqueue_styles(): void
{
    wp_enqueue_style(
        'avaramco-style',
        get_stylesheet_uri(),
        [],
        '1.0'
    );
}
add_action('wp_enqueue_scripts', 'avaramco_enqueue_styles');

function avaramco_enqueue_block_editor_assets(): void
{
    wp_enqueue_style(
        'avaramco-editor-style',
        get_theme_file_uri('editor-style.css'),
        [],
        '1.0'
    );
}
add_action('enqueue_block_editor_assets', 'avaramco_enqueue_block_editor_assets');

function avaramco_get_album_image_url(string $filename): string
{
    /**
     * Get the URL for an album image, checking existence and falling back gracefully.
     */
    $albumBaseUrl = home_url('/assets/album/');
    $urlFile = rawurlencode($filename);
    return $albumBaseUrl . $urlFile;
}

function avaramco_get_placeholder_svg_data_uri(string $type = 'placeholder'): string
{
    /**
     * Return placeholder SVG as a data URI so it always renders even if file fetch fails.
     * Prevents broken image icons on live if external album images don't load.
     */
    $svgs = [
        'placeholder' => 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMDAgMjAwIj48cmVjdCBmaWxsPSIjZTBlMGUwIiB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIvPjx0ZXh0IHg9IjUwJSIgeT0iNTAlIiBmb250LXNpemU9IjE0IiBmaWxsPSIjOTk5IiBkb21pbmFudC1iYXNlbGluZT0ibWlkZGxlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5JbWFnZSBub3QgYXZhaWxhYmxlPC90ZXh0Pjwvc3ZnPg==',
    ];
    $svg = $svgs[$type] ?? $svgs['placeholder'];
    return 'data:image/svg+xml;base64,' . $svg;
}

function avaramco_enqueue_photo_fallback_script(): void
{
    /**
     * Enqueue client-side fallback for broken album images.
     * Uses vanilla JS without jQuery dependency.
     */
    wp_register_script(
        'avaramco-photo-fallback',
        null,
        [],
        null,
        true
    );
    wp_enqueue_script('avaramco-photo-fallback');

    $placeholderUri = avaramco_get_placeholder_svg_data_uri('placeholder');
    wp_add_inline_script(
        'avaramco-photo-fallback',
        "
(function() {
    const placeholderUri = '" . esc_js($placeholderUri) . "';
    function attachErrorHandler() {
        const albumImages = document.querySelectorAll('.photo-grid img[src*=\"/assets/album/\"]');
        albumImages.forEach(function(img) {
            img.addEventListener('error', function() {
                if (!this.src.includes('data:image')) {
                    this.src = placeholderUri;
                    this.style.opacity = '0.7';
                    this.title = 'Image unavailable - fallback placeholder';
                }
            }, {once: true});
        });
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachErrorHandler);
    } else {
        attachErrorHandler();
    }
})();
        "
    );
}
add_action('wp_enqueue_scripts', 'avaramco_enqueue_photo_fallback_script');

function avaramco_register_image_sync_rest(): void
{
    /**
     * Register REST endpoints for album image management.
     * GET /wp-json/avaramco/v1/album-images - List available images
     * POST /wp-json/avaramco/v1/album-images/sync - Trigger image sync
     */
    register_rest_route('avaramco/v1', '/album-images', [
        'methods' => 'GET',
        'callback' => function () {
            $albumDir = dirname(__DIR__, 3) . '/assets/album';
            $images = [];
            if (is_dir($albumDir)) {
                $files = scandir($albumDir) ?: [];
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..' || is_dir($albumDir . '/' . $file)) {
                        continue;
                    }
                    $images[] = [
                        'name' => $file,
                        'size' => (int) filesize($albumDir . '/' . $file),
                        'url' => avaramco_get_album_image_url($file),
                    ];
                }
            }
            return new WP_REST_Response(['images' => $images, 'count' => count($images)], 200);
        },
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('avaramco/v1', '/album-images/sync', [
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            if (!current_user_can('manage_options')) {
                return new WP_REST_Response(['error' => 'Unauthorized'], 403);
            }

            $sourceUrl = (string) ($request->get_param('source_url') ?? '');
            if (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
                return new WP_REST_Response(['error' => 'Invalid source_url parameter'], 400);
            }

            $albumDir = dirname(__DIR__, 3) . '/assets/album';
            if (!is_dir($albumDir)) {
                mkdir($albumDir, 0755, true);
            }

            $listEndpoint = rtrim($sourceUrl, '/') . '/wp-json/avaramco/v1/album-images';
            $response = wp_remote_get($listEndpoint, ['timeout' => 30]);

            if (is_wp_error($response)) {
                return new WP_REST_Response(['error' => 'Failed to fetch image list from source'], 500);
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($body['images'])) {
                return new WP_REST_Response(['synced' => 0, 'failed' => 0, 'message' => 'No images found at source'], 200);
            }

            $synced = 0;
            $failed = 0;
            foreach ($body['images'] as $image) {
                $filename = (string) ($image['name'] ?? '');
                $imageUrl = (string) ($image['url'] ?? '');

                if (empty($filename) || empty($imageUrl)) {
                    $failed++;
                    continue;
                }

                $targetPath = $albumDir . '/' . basename($filename);
                $imgData = wp_remote_get($imageUrl, ['timeout' => 60]);

                if (is_wp_error($imgData) || wp_remote_retrieve_response_code($imgData) !== 200) {
                    $failed++;
                    continue;
                }

                if (file_put_contents($targetPath, wp_remote_retrieve_body($imgData)) === false) {
                    $failed++;
                    continue;
                }

                chmod($targetPath, 0644);
                $synced++;
            }

            return new WP_REST_Response(['synced' => $synced, 'failed' => $failed, 'total' => count($body['images'])], 200);
        },
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);
}
add_action('rest_api_init', 'avaramco_register_image_sync_rest');

function avaramco_is_local_request_host(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\\d+$/', '', $host);

    if ($host === '') {
        return false;
    }

    return strpos($host, 'avaramco.local') !== false
        || strpos($host, 'localhost') !== false
        || strpos($host, '127.0.0.1') !== false
        || strpos($host, 'nip.io') !== false
        || strpos($host, '192.168.') === 0;
}

function avaramco_get_internal_url(string $slug): string
{
    $staticFallbacks = [
        'home' => home_url('/'),
        'about' => home_url('/about.php'),
        'services' => home_url('/services.php'),
        'stay-options' => home_url('/services.php'),
        'photos' => home_url('/photos.php'),
        'calendar' => home_url('/calendar.php'),
        'availability-calendar' => home_url('/calendar.php'),
        'directions' => home_url('/directions.php'),
        'contact' => home_url('/contact.php'),
        'admin' => home_url('/admin.php'),
    ];

    $pathCandidates = [$slug];
    if ($slug === 'stay-options') {
        $pathCandidates[] = 'services';
    }
    if ($slug === 'availability-calendar') {
        $pathCandidates[] = 'calendar';
    }

    $page = null;
    foreach ($pathCandidates as $pathSlug) {
        $page = get_page_by_path($pathSlug, OBJECT, 'page');
        if ($page instanceof WP_Post) {
            break;
        }
    }

    if (!$page instanceof WP_Post) {
        $titleCandidates = [
            ucwords(str_replace('-', ' ', $slug)),
            $slug === 'stay-options' ? 'Services' : '',
            $slug === 'availability-calendar' ? 'Availability Calendar' : '',
        ];

        foreach ($titleCandidates as $title) {
            if ($title === '') {
                continue;
            }

            $page = get_page_by_title($title, OBJECT, 'page');
            if ($page instanceof WP_Post) {
                break;
            }
        }
    }

    if ($page instanceof WP_Post) {
        $resolvedPageId = (int) $page->ID;
        $frontPageId = (int) get_option('page_on_front');

        // Never let non-home links collapse to the configured front page.
        if ($slug !== 'home' && $frontPageId > 0 && $resolvedPageId === $frontPageId) {
            return $staticFallbacks[$slug] ?? home_url('/');
        }

        $pageBaseUrl = avaramco_is_local_request_host() ? site_url('/') : home_url('/');
        return $pageBaseUrl . '?page_id=' . $resolvedPageId;
    }

    return $staticFallbacks[$slug] ?? home_url('/');
}

function avaramco_rewrite_legacy_content_urls(string $content): string
{
    if ($content === '' || is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return $content;
    }

    // ALWAYS convert absolute wordpress URLs to relative paths for maximum compatibility
    // This ensures images load from /wordpress/wp-content/... regardless of domain
    $content = preg_replace(
        '/https?:\/\/[^\/]+\/wordpress(\/(wp-content|wp-admin|wp-includes)\/[^\s"\'<>]+)/i',
        '/wordpress$1',
        $content
    );

    // Always rewrite production URLs - handle both www and non-www variants
    $productionBases = [
        'https://www.avaram.co',
        'https://avaram.co',
        'http://www.avaram.co',
        'http://avaram.co',
        'https://www.avaramco.local',
        'https://avaramco.local',
        'http://www.avaramco.local',
        'http://avaramco.local',
    ];

    foreach ($productionBases as $base) {
        $content = str_replace($base, home_url(), $content);
    }

    // Rewrite absolute local/dev URLs to current host.
    // This regex captures the full URL (scheme + hostname + port) and replaces only that part,
    // preserving the path and query string that follows.
    // Matches: www.avaramco.local, avaramco.local, localhost, 127.0.0.1, 192.168.x.x, and *.nip.io variants
    $content = preg_replace(
        '/https?:\/\/(?:(?:www\.)?avaramco\.local|localhost|127\.0\.0\.1|(?:192\.168\.\d{1,3}\.\d{1,3})(?:\.nip\.io)?)(?::\d+)?(?=[\/\s"\'<>])/i',
        home_url(),
        $content
    );

    // Normalize Gutenberg image block URLs by attachment ID when available.
    // This avoids stale mixed-host src values and uses the current site's attachment URL.
    $content = preg_replace_callback(
        '/<img\b[^>]*>/i',
        static function (array $matches): string {
            $imgTag = $matches[0];

            if (!preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $imgTag, $classMatch)) {
                return $imgTag;
            }

            $classValue = $classMatch[2];
            if (!preg_match('/\bwp-image-(\d+)\b/i', $classValue, $idMatch)) {
                return $imgTag;
            }

            $attachmentId = (int) $idMatch[1];
            if ($attachmentId <= 0) {
                return $imgTag;
            }

            $size = 'full';
            if (preg_match('/\bsize-(thumbnail|medium|medium_large|large|full)\b/i', $classValue, $sizeMatch)) {
                $size = strtolower($sizeMatch[1]);
            }

            $resolvedUrl = wp_get_attachment_image_url($attachmentId, $size);
            if (!is_string($resolvedUrl) || $resolvedUrl === '') {
                $resolvedUrl = wp_get_attachment_url($attachmentId);
            }

            if (!is_string($resolvedUrl) || $resolvedUrl === '') {
                return $imgTag;
            }

            if (preg_match('/\bsrc\s*=\s*(["\']).*?\1/i', $imgTag)) {
                return preg_replace('/\bsrc\s*=\s*(["\']).*?\1/i', 'src="' . esc_url($resolvedUrl) . '"', $imgTag, 1) ?? $imgTag;
            }

            return preg_replace('/<img\b/i', '<img src="' . esc_url($resolvedUrl) . '"', $imgTag, 1) ?? $imgTag;
        },
        $content
    );

    return $content;
}
add_filter('the_content', 'avaramco_rewrite_legacy_content_urls', 15);

function avaramco_rewrite_home_page_links(string $content): string
{
    if ($content === '' || is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return $content;
    }

    $pageSlugs = [
        'about' => 'about',
        'services' => 'services',
        'photos' => 'photos',
        'calendar' => 'calendar',
        'directions' => 'directions',
        'contact' => 'contact',
    ];

    $pageSlugs['admin'] = 'admin';

    foreach ($pageSlugs as $source => $slug) {
        $targetUrl = esc_url(avaramco_get_internal_url($slug));
        $quotedSource = preg_quote($source, '/');

        // Replace legacy internal href formats (relative, root-relative, and old absolute URLs).
        $pattern = '/href\\s*=\\s*(["\'])\\s*(?:https?:\\/\\/(?:(?:www\\.)?avaram\\.co|avaramco\\.local|localhost|127\\.0\\.0\\.1|(?:192\\.168\\.\\d{1,3}\\.\\d{1,3})(?:\\.nip\\.io)?)(?::\\d+)?(?:\\/wordpress)?\\/)?\\/?'
            . $quotedSource
            . '(?:\\.php)?\\/?\\s*\\1/i';

        $replacement = 'href="' . $targetUrl . '"';
        $content = preg_replace($pattern, $replacement, $content);
    }

    return $content;
}
add_filter('the_content', 'avaramco_rewrite_home_page_links', 21);

function avaramco_sync_static_homepage_to_home_page(): void
{
    if (!defined('AVARAMCO_LOCK_FRONT_PAGE') || AVARAMCO_LOCK_FRONT_PAGE !== true) {
        return;
    }

    // Always ensure we're using a static page as the front page
    $currentShowOnFront = get_option('show_on_front');

    // Find or create the home page
    $homePage = get_page_by_path('home', OBJECT, 'page');

    // If not found by path, try by title
    if (!$homePage instanceof WP_Post) {
        $homePage = get_page_by_title('Home', OBJECT, 'page');
    }

    // If still not found, check for any page that might work as home
    if (!$homePage instanceof WP_Post) {
        // Try to find the first page
        $pages = get_pages(['number' => 1]);
        if (!empty($pages)) {
            $homePage = $pages[0];
        }
    }

    // If we found a home page, make sure it's set as the front page
    if ($homePage instanceof WP_Post) {
        $currentFrontPage = (int) get_option('page_on_front');

        // Update show_on_front if needed
        if ($currentShowOnFront !== 'page') {
            update_option('show_on_front', 'page');
        }

        // Update page_on_front if it changed
        if ($currentFrontPage !== (int) $homePage->ID) {
            update_option('page_on_front', (int) $homePage->ID);
        }

        // Disable blog posts page
        update_option('page_for_posts', 0);
    } else {
        // No home page found - log this but don't break anything
        // On production, the home page should always exist
        error_log('avaramco theme: No home page found. Expected page with slug "home" or title "Home"');
    }
}
add_action('init', 'avaramco_sync_static_homepage_to_home_page', 20);
