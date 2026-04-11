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

function avaramco_rewrite_legacy_content_urls(string $content): string
{
    $legacyBases = [
        'http://avaramco.local/wordpress',
        'https://avaramco.local/wordpress',
        'http://192.168.0.101.nip.io/wordpress',
        'https://192.168.0.101.nip.io/wordpress',
    ];

    return str_replace($legacyBases, home_url(), $content);
}
add_filter('the_content', 'avaramco_rewrite_legacy_content_urls', 20);

function avaramco_rewrite_home_page_links(string $content): string
{
    $pageSlugs = [
        'about' => 'about',
        'services' => 'services',
        'photos' => 'photos',
        'calendar' => 'calendar',
        'directions' => 'directions',
        'contact' => 'contact',
    ];

    $linkMap = [];
    foreach ($pageSlugs as $source => $slug) {
        $page = get_page_by_path($slug);
        $targetUrl = $page instanceof WP_Post ? get_permalink($page) : home_url('/' . $slug . '/');
        $linkMap['href="' . $source . '"'] = 'href="' . esc_url($targetUrl) . '"';
    }

    $linkMap['href="admin"'] = 'href="' . esc_url(home_url('/admin.php')) . '"';

    return str_replace(array_keys($linkMap), array_values($linkMap), $content);
}
add_filter('the_content', 'avaramco_rewrite_home_page_links', 21);
