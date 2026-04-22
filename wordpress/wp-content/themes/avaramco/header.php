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
                ['label' => 'Home', 'slugs' => ['home'], 'titles' => ['Home'], 'fallback' => home_url('/')],
                ['label' => 'About', 'slugs' => ['about'], 'titles' => ['About'], 'fallback' => home_url('/')],
                ['label' => 'Stay Options', 'slugs' => ['stay-options', 'services'], 'titles' => ['Stay Options', 'Services'], 'fallback' => home_url('/')],
                ['label' => 'Photos', 'slugs' => ['photos'], 'titles' => ['Photos'], 'fallback' => home_url('/')],
                ['label' => 'Availability calendar', 'slugs' => ['availability-calendar', 'calendar'], 'titles' => ['Availability Calendar'], 'fallback' => home_url('/')],
                ['label' => 'Directions', 'slugs' => ['directions'], 'titles' => ['Directions'], 'fallback' => home_url('/')],
                ['label' => 'Contact', 'slugs' => ['contact'], 'titles' => ['Contact'], 'fallback' => home_url('/')],
            ];

            foreach ($nav_pages as $item) {
                $page = null;

                foreach ($item['slugs'] as $slug) {
                    $page = get_page_by_path($slug);
                    if ($page instanceof WP_Post) {
                        break;
                    }
                }

                if (!$page instanceof WP_Post) {
                    foreach ($item['titles'] as $title) {
                        $page = get_page_by_title($title, OBJECT, 'page');
                        if ($page instanceof WP_Post) {
                            break;
                        }
                    }
                }

                $primarySlug = $item['slugs'][0] ?? 'home';
                $url = function_exists('avaramco_get_internal_url')
                    ? avaramco_get_internal_url($primarySlug)
                    : $item['fallback'];
                $isActive = $page instanceof WP_Post
                    ? is_page((int) $page->ID) || ($item['label'] === 'Home' && is_front_page())
                    : ($item['label'] === 'Home' && is_front_page());
                $active = $isActive ? ' class="active"' : '';

                printf('<a href="%s"%s>%s</a>', esc_url($url), $active, esc_html($item['label']));
            }
            ?>
            <a
                href="<?php echo esc_url(function_exists('avaramco_get_internal_url') ? avaramco_get_internal_url('admin') : home_url('/admin.php')); ?>">Admin</a>
        </nav>