<?php
// Temporary one-time password reset helper. Delete after use.
require_once __DIR__ . '/wordpress/wp-load.php';

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$expectedToken = 'avaram-reset-20260422';
$username = 'admin';
$newPassword = 'Avaram!2026#Reset';

if ($token !== $expectedToken) {
	http_response_code(403);
	echo 'forbidden';
	exit;
}

$user = get_user_by('login', $username);
if (!$user) {
	http_response_code(404);
	echo 'missing-user';
	exit;
}

wp_set_password($newPassword, $user->ID);
$user = get_user_by('id', $user->ID);

echo '<pre>';
echo 'user=' . esc_html($user->user_login) . "\n";
echo 'id=' . (int) $user->ID . "\n";
echo 'password=' . esc_html($newPassword) . "\n";
echo 'hash_prefix=' . esc_html(substr($user->user_pass, 0, 12)) . "\n";
echo 'hash_len=' . (int) strlen($user->user_pass) . "\n";
echo 'verify=' . (wp_check_password($newPassword, $user->user_pass, $user->ID) ? 'ok' : 'fail') . "\n";
echo '</pre>';