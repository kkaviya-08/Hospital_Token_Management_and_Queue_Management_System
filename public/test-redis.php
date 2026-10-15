<?php

require_once '../config/redis.php';

$redis->set('hospital:test', 'Redis Connected Successfully');

$message = $redis->get('hospital:test');

?>

<!DOCTYPE html>
<html>
<head>
    <title>Redis Connection Test</title>
</head>

<body>

    <h1>Hospital Queue Management System</h1>

    <h2><?php echo $message; ?></h2>

</body>
</html>