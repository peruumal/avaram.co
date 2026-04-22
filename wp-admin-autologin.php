<?php
// Temporary admin autologin helper. Remove after use.
require_once __DIR__ . '/wordpress/wp-load.php';

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$expectedToken = 'avaram-admin-login-20260422';
$username = 'admin';

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

wp_clear_auth_cookie();
wp_set_current_user($user->ID);
wp_set_auth_cookie($user->ID, false, is_ssl());

wp_safe_redirect(admin_url());
exit;