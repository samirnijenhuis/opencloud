<?php

require __DIR__.'/../lib.php';

header('Content-Type: application/json');
echo json_encode(verify_report());
