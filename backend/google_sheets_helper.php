<?php

// Lightweight helper to fetch rows from Google Sheets using a Service Account

function gsheet_fetch_rows(array $cfg): array {
    $vendor = dirname(__DIR__) . '/vendor/autoload.php';
    if (!file_exists($vendor)) {
        throw new Exception('Composer vendor/autoload.php not found. Run composer install to add google/apiclient.');
    }
    require_once $vendor;

    $credentialsPath = $cfg['credentials_path'] ?? (dirname(__DIR__) . '/config/service-account.json');
    if (!file_exists($credentialsPath)) {
        throw new Exception('Google service account credentials not found at ' . $credentialsPath);
    }

    $spreadsheetId = $cfg['spreadsheet_id'] ?? '';
    // Use a wide range to ensure we fetch all columns
    $range = $cfg['range'] ?? 'A:ZZZ';
    if (!$spreadsheetId) {
        throw new Exception('spreadsheet_id is required (config or request body).');
    }

    // Google Client
    $client = new Google\Client();
    $client->setApplicationName('Scholarship CRM');
    $client->setAuthConfig($credentialsPath);
    $client->setScopes([Google\Service\Sheets::SPREADSHEETS_READONLY]);
    
    // Fix SSL certificate issues on Windows
    $client->setHttpClient(new GuzzleHttp\Client([
        'verify' => false, // Disable SSL verification for development
        'timeout' => 30
    ]));

    $service = new Google\Service\Sheets($client);
    $response = $service->spreadsheets_values->get($spreadsheetId, $range);
    $values = $response->getValues() ?: [];
    if (count($values) === 0) {
        return [];
    }

    // First row is headers
    $headers = array_map(function($h) { return trim((string)$h); }, $values[0]);
    $rows = [];
    for ($i = 1; $i < count($values); $i++) {
        $rowValues = $values[$i];
        $assoc = [];
        foreach ($headers as $idx => $key) {
            $assoc[$key] = isset($rowValues[$idx]) ? $rowValues[$idx] : '';
        }
        $rows[] = $assoc;
    }

    return $rows;
}

// Helper function to map Arabic Google Form columns to system fields
function mapArabicColumns($rows) {
    $mappedRows = [];
    
    foreach ($rows as $row) {
        $mapped = [];
        
        // Map Arabic column names to system fields
        $mapped['fullName'] = $row['اسمك رباعي او خماسي زي شهادة الميلاد؟'] ?? '';
        $mapped['email'] = $row['ايميلك الشخصي @gmail.com لازم تكون عارف الباسورد بتاعه ومعاك دايما 📌'] ?? '';
        $mapped['phone'] = $row['رقم هاتفك'] ?? '';
        $mapped['scholarshipType'] = mapScholarshipType($row['هتقدم بشهادة ايه؟'] ?? '');
        $mapped['university'] = ''; // Not in your form
        $mapped['specialization'] = ''; // Not in your form
        $mapped['notes'] = $row['اسم محافظتك؟'] ?? ''; // Use governorate as notes
        
        // Add custom fields for other important data
        $mapped['governorate'] = $row['اسم محافظتك؟'] ?? '';
        $mapped['birthDate'] = $row['تاريخ ميلادك كما هو في شهادة الميلاد؟'] ?? '';
        $mapped['gender'] = $row['النوع؟'] ?? '';
        $mapped['nationalId'] = $row['رقم بطاقتك او الرقم القومي في شهادة الميلاد؟'] ?? '';
        $mapped['birthCertificateImage'] = $row['صورة واضحة لشهادة الميلاد او البطاقه الشخصية من الامام ولو امكن الاثنين ( ينصح باستخدام Cam Scanner في التصوير 👾)'] ?? '';
        $mapped['personalImage'] = $row['صورة شخصية لك بخلفية بيضاء واضحة! ( ينصح باستخدام Cam Scanner في التصوير 👾)'] ?? '';
        $mapped['postalCode'] = $row['الرمز البريدي'] ?? '';
        $mapped['alternativePhone'] = $row['رقم هاتف اخر ان وجد ( رقم والدك او والدتك )'] ?? '';
        $mapped['socialMediaLink'] = $row['رابط حسابك علي فيسبوك او اي منصه .'] ?? '';
        
        // Parent information
        $mapped['motherName'] = $row['اسم والدتك كما هو في البطاقه الشخصية .'] ?? '';
        $mapped['motherBirthDate'] = $row['تاريخ ميلاد والدتك'] ?? '';
        $mapped['motherJob'] = $row['والدتك بتشتغل ايه؟ ( يمكنك كتابة ربة منزل )'] ?? '';
        $mapped['motherSalary'] = $row['والدتك بتقبض كام فالشهر ؟'] ?? '';
        $mapped['motherContact'] = $row['ايميل والدتك ورقم هاتفها'] ?? '';
        
        $mapped['fatherName'] = $row['اسم والدك كما في البطاقة الشخصية'] ?? '';
        $mapped['fatherBirthDate'] = $row['تاريخ ميلاد والدك'] ?? '';
        $mapped['fatherJob'] = $row['والدك بيشتغل ايه؟'] ?? '';
        $mapped['fatherSalary'] = $row['كم راتب والدك في الشهر ( تقريبا )'] ?? '';
        $mapped['fatherContact'] = $row['ايميل والدك ورقم هاتفه'] ?? '';
        
        // Siblings and other info
        $mapped['siblingsInfo'] = $row['اسماء اخواتك وتاريخ ميلاد كل منهم ووظيفتهم وراتبهم وايميلهم ورقم هاتفهم كما في المثال التالي ( وظيفتهم اختياري )'] ?? '';
        $mapped['bloodType'] = $row['فصيلة دم الام او الاب؟'] ?? '';
        
        $mappedRows[] = $mapped;
    }
    
    return $mappedRows;
}

// Helper function to map scholarship type from Arabic to system values
function mapScholarshipType($arabicType) {
    $type = trim($arabicType);
    
    if (strpos($type, 'اعدادي') !== false) {
        return 'منحة الديانة الثانوية';
    } elseif (strpos($type, 'ثانوي') !== false) {
        return 'منحة الديانة الثانوية';
    } elseif (strpos($type, 'بكالوريوس') !== false) {
        return 'بكالوريوس';
    } elseif (strpos($type, 'ماجستير') !== false) {
        return 'ماجستير';
    } elseif (strpos($type, 'دكتوراه') !== false) {
        return 'دكتوراه';
    } else {
        return 'منحة الديانة الثانوية'; // Default for middle school
    }
}

