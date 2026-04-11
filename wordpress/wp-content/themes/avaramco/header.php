<!doctype html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
    <main class="container">
        <nav class="nav" aria-label="Main navigation">
            <?php
            $nav_pages = [
                'home' => 'Home',
                'about' => 'About',
                'services' => 'Stay Options',
                'photos' => 'Photos',
                'calendar' => 'Availability calendar',
                'directions' => 'Directions',
                'contact' => 'Contact',
            ];
            foreach ($nav_pages as $slug => $label) {
                $page = get_page_by_path($slug);
                $url = $page ? get_permalink($page) : '#';
                $active = is_page($slug) ? ' class="active"' : '';
                printf(
                    '<a href="%s"%s>%s</a>',
                    esc_url($url),
                    $active,
                    esc_html($label)
                );
            }
            ?>
            <a href="/admin.php">Admin</a>
        </nav>