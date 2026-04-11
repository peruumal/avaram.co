<?php
$wordpressHome = '/wordpress/';

header('Location: ' . $wordpressHome, true, 302);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta http-equiv="refresh" content="0;url=/wordpress/">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Redirecting...</title>
</head>

<body>
    <p><a href="/wordpress/">Continue to the home page</a></p>
</body>

</html>