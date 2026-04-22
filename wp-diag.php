<?php
// Temporary diagnostic - DELETE AFTER USE
if (!defined('ABSPATH')) {
    require_once __DIR__ . '/wordpress/wp-load.php';
}
global $wpdb;
$prefix = $wpdb->prefix;
$dbname = DB_NAME;
$dbuser = DB_USER;
$dbhost = DB_HOST;
$siteurl = get_option('siteurl');
$home    = get_option('home');
$users   = $wpdb->get_results("SELECT ID, user_login, CHAR_LENGTH(user_pass) AS hash_len, LEFT(user_pass,12) AS hash_prefix FROM {$wpdb->users}");
echo "<pre>\n";
echo "DB_NAME=$dbname\nDB_USER=$dbuser\nDB_HOST=$dbhost\n";
echo "table_prefix=$prefix\nsiteurl=$siteurl\nhome=$home\n\nUsers:\n";
foreach ($users as $u) {
    echo "  ID={$u->ID} login={$u->user_login} hash_prefix={$u->hash_prefix} hash_len={$u->hash_len}\n";
}
echo "</pre>";
