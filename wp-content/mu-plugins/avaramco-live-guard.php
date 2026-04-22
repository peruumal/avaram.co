<?php
declare(strict_types=1);

/**
 * Plugin Name: Avaramco Live Guard
 * Description: Enforces canonical live URLs and front-page behavior regardless of active theme.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('AVARAMCO_LOCK_FRONT_PAGE')) {
    define('AVARAMCO_LOCK_FRONT_PAGE', true);
}

function avaramco_is_local_host(string $host): bool
{
    if ($host === '') {
        return false;
    }

    return strpos($host, 'avaramco.local') !== false
        || strpos($host, 'localhost') !== false
        || strpos($host, '127.0.0.1') !== false
        || strpos($host, 'nip.io') !== false
        || strpos($host, '192.168.') === 0;
}

function avaramco_request_site_base(): ?string
{
    $rawHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $host = strtolower(preg_replace('/:\\d+$/', '', $rawHost));
    if ($host === '' || avaramco_is_local_host($host)) {
        return null;
    }

    $forwardedProto = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    $isHttps = $forwardedProto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $isHttps ? 'https' : 'http';

    return $scheme . '://' . $host;
}

function avaramco_filter_option_home($preOption)
{
    $siteBase = avaramco_request_site_base();
    return $siteBase ?? $preOption;
}
add_filter('pre_option_home', 'avaramco_filter_option_home');

function avaramco_filter_option_siteurl($preOption)
{
    $siteBase = avaramco_request_site_base();
    if ($siteBase === null) {
        return $preOption;
    }

    return $siteBase . '/wordpress';
}
add_filter('pre_option_siteurl', 'avaramco_filter_option_siteurl');

function avaramco_mu_rewrite_legacy_urls(string $content): string
{
    if ($content === '' || is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return $content;
    }

    $siteBase = avaramco_request_site_base();
    if ($siteBase === null || $content === '') {
        return $content;
    }

    // Use positive lookahead to ensure we match complete URLs without partially breaking paths
    // Matches: www.avaramco.local, avaramco.local, localhost, 127.0.0.1, 192.168.x.x, and *.nip.io variants
    $content = preg_replace(
        '/https?:\/\/(?:(?:www\.)?avaramco\.local|localhost|127\.0\.0\.1|(?:192\.168\.\d{1,3}\.\d{1,3})(?:\.nip\.io)?)(?::\d+)?(?=[\/\s"\'<>])/i',
        $siteBase,
        $content
    );

    return $content;
}
add_filter('the_content', 'avaramco_mu_rewrite_legacy_urls', 9);

function avaramco_mu_resolve_home_page_id(): int
{
    static $resolvedId = null;

    if (is_int($resolvedId)) {
        return $resolvedId;
    }

    $homePage = null;

    $slugCandidates = ['home', 'homepage', 'front-page'];
    foreach ($slugCandidates as $slug) {
        $homePage = get_page_by_path($slug, OBJECT, 'page');
        if ($homePage instanceof WP_Post) {
            break;
        }
    }

    if (!$homePage instanceof WP_Post) {
        $titleCandidates = ['Home', 'Homepage', 'Home Page'];
        foreach ($titleCandidates as $title) {
            $homePage = get_page_by_title($title, OBJECT, 'page');
            if ($homePage instanceof WP_Post) {
                break;
            }
        }
    }

    if (!$homePage instanceof WP_Post) {
        $templateCandidates = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'private', 'draft'],
            'posts_per_page' => 1,
            'meta_key' => '_wp_page_template',
            'meta_value' => 'page-home.php',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        if (!empty($templateCandidates) && $templateCandidates[0] instanceof WP_Post) {
            $homePage = $templateCandidates[0];
        }
    }

    $resolvedId = $homePage instanceof WP_Post ? (int) $homePage->ID : 0;
    return $resolvedId;
}

function avaramco_mu_pre_option_show_on_front($preOption)
{
    if (!AVARAMCO_LOCK_FRONT_PAGE) {
        return $preOption;
    }

    $siteBase = avaramco_request_site_base();
    if ($siteBase === null) {
        return $preOption;
    }

    return avaramco_mu_resolve_home_page_id() > 0 ? 'page' : $preOption;
}
add_filter('pre_option_show_on_front', 'avaramco_mu_pre_option_show_on_front');

function avaramco_mu_pre_option_page_on_front($preOption)
{
    if (!AVARAMCO_LOCK_FRONT_PAGE) {
        return $preOption;
    }

    $siteBase = avaramco_request_site_base();
    if ($siteBase === null) {
        return $preOption;
    }

    $homePageId = avaramco_mu_resolve_home_page_id();
    return $homePageId > 0 ? (string) $homePageId : $preOption;
}
add_filter('pre_option_page_on_front', 'avaramco_mu_pre_option_page_on_front');

function avaramco_mu_pre_option_page_for_posts($preOption)
{
    if (!AVARAMCO_LOCK_FRONT_PAGE) {
        return $preOption;
    }

    $siteBase = avaramco_request_site_base();
    if ($siteBase === null) {
        return $preOption;
    }

    return '0';
}
add_filter('pre_option_page_for_posts', 'avaramco_mu_pre_option_page_for_posts');

function avaramco_mu_force_rest_index_url($url, $path, $blogId, $scheme)
{
    $route = '/' . ltrim((string) $path, '/');
    $base = get_site_url($blogId, '/index.php', $scheme === 'rest' ? null : $scheme);
    return add_query_arg('rest_route', $route, $base);
}
add_filter('rest_url', 'avaramco_mu_force_rest_index_url', 10, 4);

function avaramco_mu_get_internal_url(string $slug): string
{
    $root = home_url('/');
    $fallbackMap = [
        'about' => home_url('/about.php'),
        'services' => home_url('/services.php'),
        'stay-options' => home_url('/services.php'),
        'photos' => home_url('/photos.php'),
        'calendar' => home_url('/calendar.php'),
        'availability-calendar' => home_url('/calendar.php'),
        'directions' => home_url('/directions.php'),
        'contact' => home_url('/contact.php'),
        'pricing' => home_url('/pricing.php'),
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
        $titleCandidates = [ucwords(str_replace('-', ' ', $slug))];
        if ($slug === 'stay-options') {
            $titleCandidates[] = 'Services';
            $titleCandidates[] = 'Stay Options';
        }
        if ($slug === 'availability-calendar') {
            $titleCandidates[] = 'Availability Calendar';
            $titleCandidates[] = 'Calendar';
        }

        foreach ($titleCandidates as $title) {
            $page = get_page_by_title($title, OBJECT, 'page');
            if ($page instanceof WP_Post) {
                break;
            }
        }
    }

    if ($page instanceof WP_Post) {
        $resolvedPageId = (int) $page->ID;
        $frontPageId = (int) get_option('page_on_front');

        if ($slug !== 'home' && $frontPageId > 0 && $resolvedPageId === $frontPageId) {
            return $fallbackMap[$slug] ?? $root;
        }

        $pageBaseUrl = avaramco_request_site_base() === null ? site_url('/') : home_url('/');
        return $pageBaseUrl . '?page_id=' . $resolvedPageId;
    }

    return $fallbackMap[$slug] ?? $root;
}

function avaramco_mu_rewrite_internal_links(string $content): string
{
    if ($content === '' || is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return $content;
    }

    $siteBase = avaramco_request_site_base();
    if ($siteBase === null || $content === '') {
        return $content;
    }

    $linkableSlugs = [
        'about',
        'services',
        'stay-options',
        'photos',
        'calendar',
        'availability-calendar',
        'directions',
        'contact',
        'pricing',
        'admin',
    ];
    foreach ($linkableSlugs as $slug) {
        $targetUrl = esc_url(avaramco_mu_get_internal_url($slug));
        $quotedSlug = preg_quote($slug, '/');

        $pattern = '/href\\s*=\\s*(["\'])\\s*(?:https?:\\/\\/(?:(?:www\\.)?avaram\\.co|avaramco\\.local|localhost|127\\.0\\.0\\.1|(?:192\\.168\\.\\d{1,3}\\.\\d{1,3})(?:\\.nip\\.io)?)(?::\\d+)?(?:\\/wordpress)?\\/)?\\/?'
            . $quotedSlug
            . '(?:\\.php)?\\/?\\s*\\1/i';

        $content = preg_replace($pattern, 'href="' . $targetUrl . '"', $content);
    }

    return $content;
}
add_filter('the_content', 'avaramco_mu_rewrite_internal_links', 10);

function avaramco_mu_redirect_legacy_slug_requests(): void
{
    $siteBase = avaramco_request_site_base();
    if ($siteBase === null || is_admin() || !is_404()) {
        return;
    }

    $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $requestPath = trim($requestPath, '/');
    if ($requestPath === '' || strpos($requestPath, '/') !== false) {
        return;
    }

    if (!preg_match('/^[a-z0-9-]+(?:\.php)?$/i', $requestPath)) {
        return;
    }

    $slug = strtolower(preg_replace('/\.php$/i', '', $requestPath));
    $slugMap = [
        'about' => 'about',
        'services' => 'services',
        'stay-options' => 'stay-options',
        'photos' => 'photos',
        'calendar' => 'calendar',
        'availability-calendar' => 'availability-calendar',
        'directions' => 'directions',
        'contact' => 'contact',
        'pricing' => 'pricing',
        'admin' => 'admin',
    ];

    if (!isset($slugMap[$slug])) {
        return;
    }

    $targetUrl = avaramco_mu_get_internal_url($slugMap[$slug]);
    if ($targetUrl === '') {
        return;
    }

    wp_safe_redirect($targetUrl, 302);
    exit;
}
add_action('template_redirect', 'avaramco_mu_redirect_legacy_slug_requests', 1);

function avaramco_mu_force_front_page(): void
{
    if (!AVARAMCO_LOCK_FRONT_PAGE) {
        return;
    }

    $siteBase = avaramco_request_site_base();
    if ($siteBase === null) {
        return;
    }

    $homePageId = avaramco_mu_resolve_home_page_id();
    if ($homePageId <= 0) {
        return;
    }

    if (get_option('show_on_front') !== 'page') {
        update_option('show_on_front', 'page');
    }

    if ((int) get_option('page_on_front') !== $homePageId) {
        update_option('page_on_front', $homePageId);
    }

    if ((int) get_option('page_for_posts') !== 0) {
        update_option('page_for_posts', 0);
    }
}
add_action('init', 'avaramco_mu_force_front_page', 30);
