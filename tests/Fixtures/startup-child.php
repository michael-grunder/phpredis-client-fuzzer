<?php

declare(strict_types=1);

// No Redis connection or real crash: exit 139 exercises crash capture.
$ini = php_ini_loaded_file();
echo json_encode([
    'precision' => ini_get('precision'),
    'serialize_precision' => ini_get('serialize_precision'),
    'include_path' => ini_get('include_path'),
    'memory_limit' => ini_get('memory_limit'),
    'ini_contents' => $ini === false ? null : file_get_contents($ini),
], JSON_THROW_ON_ERROR), "\n";
exit((int) ($argv[1] ?? 0));
