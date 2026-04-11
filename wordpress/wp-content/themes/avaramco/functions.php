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
    $linkMap = [
        'href="about"' => 'href="' . home_url('/?page_id=6') . '"',
        'href="services"' => 'href="' . home_url('/?page_id=10') . '"',
        'href="photos"' => 'href="' . home_url('/?page_id=11') . '"',
        'href="calendar"' => 'href="' . home_url('/?page_id=12') . '"',
        'href="directions"' => 'href="' . home_url('/?page_id=8') . '"',
        'href="contact"' => 'href="' . home_url('/?page_id=7') . '"',
        'href="admin"' => 'href="' . home_url('/admin.php') . '"',
    ];

    return str_replace(array_keys($linkMap), array_values($linkMap), $content);
}
add_filter('the_content', 'avaramco_rewrite_home_page_links', 21);
