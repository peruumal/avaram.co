<?php
declare(strict_types=1);

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '/wordpress/wp-admin/';
if ($query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
exit;
