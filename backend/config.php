<?php

// Copy this file to backend/config.php and fill the values
return [
    // Absolute or relative path to the downloaded service account JSON
    'credentials_path' => dirname(__DIR__) . '/config/service-account.json',

    // Default spreadsheet id (optional). You can also pass in request body
    'spreadsheet_id' => '19BmPnYe0EiOUHDsrQlDRSuCREHm2A5JZ4eHe3GQEa9s',

    // Default range to read (A1 notation). Use wide range to include all columns
    'range' => 'A:ZZZ',
];

