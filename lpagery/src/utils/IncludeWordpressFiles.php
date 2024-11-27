<?php
$files_to_include = array(ABSPATH . 'wp-config.php',
    ABSPATH . 'wp-includes/class-wpdb.php',
    ABSPATH . 'wp-admin/includes/taxonomy.php',
    ABSPATH . 'wp-admin/includes/image.php',);


function lpagery_include_if_exists($filepath)
{
    if (file_exists($filepath)) {
        error_log("Including file: " . $filepath);
        include_once($filepath);
    } else {
        error_log("File does not exist: " . $filepath);
    }
}

foreach ($files_to_include as $file) {
    lpagery_include_if_exists($file);
}