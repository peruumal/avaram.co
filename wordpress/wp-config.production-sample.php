<?php
/**
 * Production wp-config template for avaram.co.
 *
 * Copy this file to wp-config.php on the production server and replace the
 * placeholder values or wire them to environment variables.
 */

define('DB_NAME', getenv('WP_DB_NAME') ?: 'replace_with_database_name');
define('DB_USER', getenv('WP_DB_USER') ?: 'replace_with_database_user');
define('DB_PASSWORD', getenv('WP_DB_PASSWORD') ?: 'replace_with_database_password');
define('DB_HOST', getenv('WP_DB_HOST') ?: 'localhost');

define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

define('AUTH_KEY', getenv('WP_AUTH_KEY') ?: 'replace-with-unique-auth-key');
define('SECURE_AUTH_KEY', getenv('WP_SECURE_AUTH_KEY') ?: 'replace-with-unique-secure-auth-key');
define('LOGGED_IN_KEY', getenv('WP_LOGGED_IN_KEY') ?: 'replace-with-unique-logged-in-key');
define('NONCE_KEY', getenv('WP_NONCE_KEY') ?: 'replace-with-unique-nonce-key');
define('AUTH_SALT', getenv('WP_AUTH_SALT') ?: 'replace-with-unique-auth-salt');
define('SECURE_AUTH_SALT', getenv('WP_SECURE_AUTH_SALT') ?: 'replace-with-unique-secure-auth-salt');
define('LOGGED_IN_SALT', getenv('WP_LOGGED_IN_SALT') ?: 'replace-with-unique-logged-in-salt');
define('NONCE_SALT', getenv('WP_NONCE_SALT') ?: 'replace-with-unique-nonce-salt');

$table_prefix = getenv('WP_TABLE_PREFIX') ?: 'wp_';

$siteHost = getenv('WP_SITE_HOST') ?: 'avaram.co';
$siteScheme = getenv('WP_SITE_SCHEME') ?: 'https';
$siteBase = $siteScheme . '://' . $siteHost;

// WordPress core lives in /wordpress, but the public site should load from /. 
define('WP_HOME', $siteBase);
define('WP_SITEURL', $siteBase . '/wordpress');
define('FORCE_SSL_ADMIN', true);
define('DISALLOW_FILE_EDIT', true);

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';