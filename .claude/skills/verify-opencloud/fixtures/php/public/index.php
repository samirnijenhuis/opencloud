<?php

require __DIR__.'/../lib.php';

$report = verify_report();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>opencloud verify: php</title>
</head>
<body>
    <h1>opencloud verify: php</h1>
    <pre><?= htmlspecialchars(json_encode($report, JSON_PRETTY_PRINT)) ?></pre>
</body>
</html>
