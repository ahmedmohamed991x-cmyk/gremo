<?php

// Copy this file to backend/config.php and fill the values
return [
    // Absolute or relative path to the downloaded service account JSON
    'credentials_path' => __DIR__ . '/../config/service-account.json',

    // Default spreadsheet id (optional). You can also pass in request body
    'spreadsheet_id' => '',

    // Default range to read (A1 notation). Example: 'Responses!A:Z'
    'range' => 'A:Z',
];

