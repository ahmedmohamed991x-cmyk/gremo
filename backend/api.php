<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Database configuration (using JSON files for simplicity)
$dataDir = '../data/';
if (!file_exists($dataDir)) {
    mkdir($dataDir, 0777, true);
}

$filesDir = __DIR__ . '/../files/';
if (!file_exists($filesDir)) {
    mkdir($filesDir, 0777, true);
}

$clientsFile = $dataDir . 'clients.json';
$paymentsFile = $dataDir . 'payments.json';
$adminsFile = $dataDir . 'admins.json';
$studentsFile = $dataDir . 'students.json';

// Initialize files with empty data
if (!file_exists($clientsFile)) {
    file_put_contents($clientsFile, json_encode([], JSON_UNESCAPED_UNICODE));
}

if (!file_exists($paymentsFile)) {
    file_put_contents($paymentsFile, json_encode([], JSON_UNESCAPED_UNICODE));
}

if (!file_exists($adminsFile)) {
    file_put_contents($adminsFile, json_encode([
        [
            'id' => 1,
            'fullName' => 'المدير الرئيسي',
            'email' => 'stroogar@gmail.com',
            'role' => 'مدير',
            'isMainAdmin' => true,
            // default hashed password for main admin
            'password' => password_hash('mahmoudmahmed11!', PASSWORD_DEFAULT),
            'createdAt' => date('Y-m-d')
        ]
    ], JSON_UNESCAPED_UNICODE));
}

if (!file_exists($studentsFile)) {
    file_put_contents($studentsFile, json_encode([], JSON_UNESCAPED_UNICODE));
}

// Helper functions
function readJsonFile($file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        return json_decode($content, true) ?: [];
    }
    return [];
}

function writeJsonFile($file, $data) {
    return file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function generateId($data) {
    if (empty($data)) {
        return 1;
    }
    $maxId = max(array_column($data, 'id'));
    return $maxId + 1;
}

function isDuplicateClient($clients, $email, $phone) {
    foreach ($clients as $client) {
        if ($client['email'] === $email || $client['phone'] === $phone) {
            return true;
        }
    }
    return false;
}

function sanitizeFileName($fileName) {
    // Preserve Arabic characters, letters, numbers, dots, hyphens, and underscores
    // Only remove truly problematic characters like: < > : " | ? * \ / 
    $fileName = preg_replace('/[<>:"|?*\\/]/u', '_', $fileName);
    
    // Remove multiple consecutive underscores
    $fileName = preg_replace('/_+/', '_', $fileName);
    
    // Remove leading/trailing underscores
    $fileName = trim($fileName, '_');
    
    if (strlen($fileName) > 100) {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $fileName = substr($name, 0, 100 - strlen($extension) - 1) . '.' . $extension;
    }
    
    return $fileName;
}

function removeDirectory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = glob($dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        } elseif (is_dir($file)) {
            removeDirectory($file);
        }
    }
    
    rmdir($dir);
}

// Function to clean old logs (keep only last 1000 entries)
function cleanOldLogs() {
    try {
        $logsFile = './data/admin_logs.json';
        if (!file_exists($logsFile)) {
            return;
        }
        
        $logs = json_decode(file_get_contents($logsFile), true) ?: [];
        
        // If logs exceed 1000 entries, keep only the last 1000
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
            file_put_contents($logsFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            error_log("🧹 Cleaned old logs, kept last 1000 entries");
        }
    } catch (Exception $e) {
        error_log("❌ Error cleaning logs: " . $e->getMessage());
    }
}

// Function to add log entry
function addLogEntry($action, $description, $details = '') {
    try {
        $logsFile = './data/admin_logs.json';
        $logs = [];
        
        if (file_exists($logsFile)) {
            $logs = json_decode(file_get_contents($logsFile), true) ?: [];
        }
        
        $logEntry = [
            'id' => uniqid(),
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'description' => $description,
            'details' => $details,
            'user' => $_SERVER['HTTP_X_USER_EMAIL'] ?? 'unknown'
        ];
        
        $logs[] = $logEntry;
        
        // Clean old logs before saving
        cleanOldLogs();
        
        file_put_contents($logsFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        error_log("📝 Log entry added: $action - $description");
        
    } catch (Exception $e) {
        error_log("❌ Error adding log entry: " . $e->getMessage());
    }
}

// Handle requests
$method = $_SERVER['REQUEST_METHOD'];

// For POST requests with JSON body, we need to parse the body first to get the action
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input === null && !empty($_POST)) { 
        $input = $_POST; 
    }
    $action = $input['action'] ?? $_GET['action'] ?? $_POST['action'] ?? '';
} else {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
}



try {
    switch ($method) {
        case 'GET':
            // Debug: Log the action being processed
            error_log("🔍 GET request - Action: '$action'");
            
            switch ($action) {
                case 'upload_payment_screenshot':
                    // This endpoint expects multipart/form-data
                    try {
                        $paymentId = $_POST['paymentId'] ?? '';
                        if ($paymentId === '') {
                            echo json_encode(['success' => false, 'message' => 'معرف الدفع مطلوب'], JSON_UNESCAPED_UNICODE);
                            break;
                        }

                        if (!isset($_FILES['paymentScreenshot']) || $_FILES['paymentScreenshot']['error'] !== UPLOAD_ERR_OK) {
                            echo json_encode(['success' => false, 'message' => 'فشل في رفع الملف'], JSON_UNESCAPED_UNICODE);
                            break;
                        }

                        $uploadDir = $filesDir . 'payment_screenshots/';
                        if (!file_exists($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }

                        $file = $_FILES['paymentScreenshot'];
                        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                        if (!in_array($file['type'], $allowedTypes)) {
                            echo json_encode(['success' => false, 'message' => 'نوع الملف غير مدعوم'], JSON_UNESCAPED_UNICODE);
                            break;
                        }

                        if ($file['size'] > 5 * 1024 * 1024) {
                            echo json_encode(['success' => false, 'message' => 'حجم الملف كبير جداً (الحد 5MB)'], JSON_UNESCAPED_UNICODE);
                            break;
                        }

                        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $filename = 'payment_' . intval($paymentId) . '_' . time() . '.' . $ext;
                        $filename = sanitizeFileName($filename);
                        $destPath = $uploadDir . $filename;

                        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                            echo json_encode(['success' => false, 'message' => 'فشل في حفظ الملف'], JSON_UNESCAPED_UNICODE);
                            break;
                        }

                        $relativePath = 'files/payment_screenshots/' . $filename;
                        echo json_encode(['success' => true, 'filePath' => $relativePath], JSON_UNESCAPED_UNICODE);
                        break;
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                case 'clients':
                    $clients = readJsonFile($clientsFile);
                    echo json_encode(['success' => true, 'data' => $clients], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'payments':
                    $payments = readJsonFile($paymentsFile);
                    echo json_encode(['success' => true, 'data' => $payments], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'admins':
                    $admins = readJsonFile($adminsFile);
                    echo json_encode(['success' => true, 'data' => $admins], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'students':
                    $students = readJsonFile($studentsFile);
                    echo json_encode(['success' => true, 'data' => $students], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'get_column_mapping':
                    try {
                        $mappingFile = $dataDir . 'column_mapping.json';
                        if (file_exists($mappingFile)) {
                            $columnMapping = json_decode(file_get_contents($mappingFile), true) ?: [];
                        } else {
                            $columnMapping = [];
                        }
                        
                        echo json_encode([
                            'success' => true,
                            'data' => $columnMapping
                        ], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في جلب تخطيط الأعمدة: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'get_student_distribution':
                    try {
                        $distributionFile = $dataDir . 'student_distribution.json';
                        $distribution = [];
                        if (file_exists($distributionFile)) {
                            $distribution = json_decode(file_get_contents($distributionFile), true) ?: [];
                        }
                        
                        echo json_encode([
                            'success' => true,
                            'data' => $distribution
                        ], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في جلب توزيع الطلاب: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'get_google_sheet_id':
                    try {
                        $configFile = './config/google_drive_config.php';
                        if (file_exists($configFile)) {
                            // Read config file to extract sheet ID
                            $configContent = file_get_contents($configFile);
                            if (preg_match('/SPREADSHEET_ID\s*=\s*[\'"]([^\'"]+)[\'"]/', $configContent, $matches)) {
                                $sheetId = $matches[1];
                                echo json_encode([
                                    'success' => true,
                                    'sheetId' => $sheetId
                                ], JSON_UNESCAPED_UNICODE);
                            } else {
                                echo json_encode([
                                    'success' => false,
                                    'message' => 'لم يتم العثور على Google Sheet ID في ملف الإعدادات'
                                ], JSON_UNESCAPED_UNICODE);
                            }
                        } else {
                            echo json_encode([
                                'success' => false,
                                'message' => 'ملف الإعدادات غير موجود'
                            ], JSON_UNESCAPED_UNICODE);
                        }
                    } catch (Exception $e) {
                        echo json_encode([
                            'success' => false,
                            'message' => 'خطأ في جلب Google Sheet ID: ' . $e->getMessage()
                        ], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                
                case 'dashboard':
                    $clients = readJsonFile($clientsFile);
                    $payments = readJsonFile($paymentsFile);
                    
                    $totalRevenue = array_sum(array_map(function($p){ return floatval($p['amount'] ?? 0); }, array_filter($payments, function($p){ return ($p['status'] ?? ($p['paymentStatus'] ?? '')) === 'مدفوع'; })));
                    $totalClients = count($clients);
                    
                    // Pending applications means not applied (based on client applicationStatus)
                    $pendingApplications = count(array_filter($clients, function($c){ return ($c['applicationStatus'] ?? 'لم يتم التقديم') !== 'تم التقديم' && ($c['applicationStatus'] ?? 'لم يتم التقديم') !== 'مقبول' && ($c['applicationStatus'] ?? 'لم يتم التقديم') !== 'مرفوض'; }));
                    $appliedCount = count(array_filter($clients, function($c){ return ($c['applicationStatus'] ?? '') === 'تم التقديم'; }));
                    $acceptedCount = count(array_filter($clients, function($c){ return ($c['applicationStatus'] ?? '') === 'مقبول'; }));
                    $rejectedCount = count(array_filter($clients, function($c){ return ($c['applicationStatus'] ?? '') === 'مرفوض'; }));
                    
                    $targetRevenue = 80000;
                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'totalRevenue' => $totalRevenue,
                            'pendingPayments' => $pendingApplications, // renamed meaning per request
                            'paidClients' => count(array_filter($clients, function($c){ return ($c['paymentStatus'] ?? '') === 'مدفوع'; })),
                            'totalClients' => $totalClients,
                            'appliedCount' => $appliedCount,
                            'acceptedCount' => $acceptedCount,
                            'rejectedCount' => $rejectedCount,
                            'targetRevenue' => $targetRevenue
                        ]
                    ], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'dashboard':
                    $clients = readJsonFile($clientsFile);
                    $payments = readJsonFile($paymentsFile);
                    
                    $totalRevenue = array_sum(array_column(array_filter($payments, function($p) {
                        return $p['status'] === 'مدفوع';
                    }), 'amount'));
                    
                    $pendingPayments = count(array_filter($payments, function($p) {
                        return $p['status'] === 'معلق';
                    }));
                    
                    $paidClients = count(array_filter($clients, function($c) {
                        return $c['paymentStatus'] === 'مدفوع';
                    }));
                    
                    $totalClients = count($clients);
                    
                    // Application status counts
                    $appliedCount = count(array_filter($clients, function($c) {
                        return $c['applicationStatus'] === 'تم التقديم';
                    }));
                    
                    $acceptedCount = count(array_filter($clients, function($c) {
                        return $c['applicationStatus'] === 'مقبول';
                    }));
                    
                    $rejectedCount = count(array_filter($clients, function($c) {
                        return $c['applicationStatus'] === 'مرفوض';
                    }));
                    
                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'totalRevenue' => $totalRevenue,
                            'pendingPayments' => $pendingPayments,
                            'paidClients' => $paidClients,
                            'totalClients' => $totalClients,
                            'appliedCount' => $appliedCount,
                            'acceptedCount' => $acceptedCount,
                            'rejectedCount' => $rejectedCount
                        ]
                    ], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'client_details':
                    $clients = readJsonFile($clientsFile);
                    $clientId = $_GET['id'] ?? null;
                    
                    if ($clientId) {
                        $client = null;
                        foreach ($clients as $c) {
                            if ($c['id'] == $clientId) {
                                $client = $c;
                                break;
                            }
                        }
                        
                        if ($client) {
                            echo json_encode(['success' => true, 'data' => $client], JSON_UNESCAPED_UNICODE);
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Client not found'], JSON_UNESCAPED_UNICODE);
                        }
                    } else {
                        echo json_encode(['success' => false, 'message' => 'ID required'], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'payment_details':
                    $payments = readJsonFile($paymentsFile);
                    $paymentId = $_GET['id'] ?? null;
                    
                    if ($paymentId) {
                        $payment = null;
                        foreach ($payments as $p) {
                            if ($p['id'] == $paymentId) {
                                $payment = $p;
                                break;
                            }
                        }
                        
                        if ($payment) {
                            echo json_encode(['success' => true, 'data' => $payment], JSON_UNESCAPED_UNICODE);
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Payment not found'], JSON_UNESCAPED_UNICODE);
                        }
                    } else {
                        echo json_encode(['success' => false, 'message' => 'ID required'], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'admin_details':
                    $admins = readJsonFile($adminsFile);
                    $adminId = $_GET['id'] ?? null;
                    
                    if ($adminId) {
                        $admin = null;
                        foreach ($admins as $a) {
                            if ($a['id'] == $adminId) {
                                $admin = $a;
                                break;
                            }
                        }
                        
                        if ($admin) {
                            echo json_encode(['success' => true, 'data' => $admin], JSON_UNESCAPED_UNICODE);
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Admin not found'], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                    } else {
                        echo json_encode(['success' => false, 'message' => 'ID required'], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                
                case 'get_help_requests':
                    try {
                        $hrFile = $dataDir . 'help_requests.json';
                        $reqs = [];
                        if (file_exists($hrFile)) {
                            $reqs = json_decode(file_get_contents($hrFile), true) ?: [];
                        }
                        echo json_encode(['success' => true, 'data' => ['help_requests' => $reqs]], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في جلب الطلبات: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;

                case 'get_admin_logs':
                    try {
                        $logsFile = $dataDir . 'admin_logs.json';
                        $logs = [];
                        if (file_exists($logsFile)) {
                            $logs = json_decode(file_get_contents($logsFile), true) ?: [];
                        }
                        echo json_encode(['success' => true, 'data' => ['logs' => $logs]], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في جلب السجلات: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;

                case 'get_admin_analytics':
                    try {
                        $students = readJsonFile($studentsFile);
                        $admins = readJsonFile($adminsFile);
                        $distributionFile = $dataDir . 'student_distribution.json';
                        $helpFile = $dataDir . 'help_requests.json';

                        $totalAdmins = count($admins);
                        $totalStudents = count($students);
                        $distributedStudents = 0;
                        $avgStudentsPerAdmin = 0;
                        $helpRequests = 0;

                        if (file_exists($distributionFile)) {
                            $distribution = json_decode(file_get_contents($distributionFile), true) ?: [];
                            $distributedStudents = array_sum(array_map('count', $distribution));
                        }
                        if ($totalAdmins > 0) {
                            $avgStudentsPerAdmin = round($totalStudents / $totalAdmins, 1);
                        }
                        if (file_exists($helpFile)) {
                            $reqs = json_decode(file_get_contents($helpFile), true) ?: [];
                            $helpRequests = count($reqs);
                        }

                        echo json_encode([
                            'success' => true,
                            'data' => [
                                'totalAdmins' => $totalAdmins,
                                'distributedStudents' => $distributedStudents,
                                'avgStudentsPerAdmin' => $avgStudentsPerAdmin,
                                'helpRequests' => $helpRequests
                            ]
                        ], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في جلب إحصائيات الأدمن: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;

                case 'get_student_distribution':
                    try {
                        $distributionFile = $dataDir . 'student_distribution.json';
                        $distribution = [];
                        if (file_exists($distributionFile)) {
                            $distribution = json_decode(file_get_contents($distributionFile), true) ?: [];
                        }
                        echo json_encode(['success' => true, 'data' => ['distribution' => $distribution]], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في جلب التوزيع: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'scan_student_files':
                    try {
                        $studentId = $_GET['studentId'] ?? '';
                        if (empty($studentId)) {
                            echo json_encode(['success' => false, 'message' => 'معرف الطالب مطلوب'], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        $studentFiles = [];
                        $studentsDir = $filesDir . 'students/';
                        
                        // Debug: Check if directory exists
                        if (!is_dir($studentsDir)) {
                            echo json_encode(['success' => false, 'message' => 'مجلد الطلاب غير موجود: ' . $studentsDir], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        $folders = scandir($studentsDir);
                        
                        foreach ($folders as $folder) {
                            if ($folder === '.' || $folder === '..') continue;
                            
                            // Check if folder name starts with student ID (e.g., "3_مريم محمد احمد السيد محمد")
                            if (strpos($folder, $studentId . '_') === 0) {
                                $studentFolder = $studentsDir . $folder . '/';
                                
                                if (is_dir($studentFolder)) {
                                    $files = scandir($studentFolder);
                                    
                                    foreach ($files as $file) {
                                        if ($file === '.' || $file === '..') continue;
                                        
                                        $filePath = $studentFolder . $file;
                                        if (is_file($filePath)) {
                                            try {
                                                $fileInfo = [
                                                    'id' => uniqid(),
                                                    'name' => $file,
                                                    'path' => $filePath,
                                                    'size' => filesize($filePath),
                                                    'type' => function_exists('mime_content_type') ? mime_content_type($filePath) : 'unknown',
                                                    'modified' => date('Y-m-d H:i:s', filemtime($filePath)),
                                                    'source' => 'uploaded' // Mark as uploaded since it's from filesystem
                                                ];
                                                $studentFiles[] = $fileInfo;
                                            } catch (Exception $fileError) {
                                                // Skip problematic files
                                                continue;
                                            }
                                        }
                                    }
                                    break; // Found the student folder, no need to continue
                                }
                            }
                        }
                        
                        echo json_encode([
                            'success' => true,
                            'files' => $studentFiles
                        ], JSON_UNESCAPED_UNICODE);
                        
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في فحص ملفات الطالب: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'test_distribution':
                    try {
                        $distributionFile = $dataDir . 'student_distribution.json';
                        $distribution = [];
                        if (file_exists($distributionFile)) {
                            $distribution = json_decode(file_get_contents($distributionFile), true) ?: [];
                        }
                        
                        echo json_encode([
                            'success' => true,
                            'distribution' => $distribution,
                            'file_path' => $distributionFile,
                            'file_exists' => file_exists($distributionFile),
                            'file_size' => file_exists($distributionFile) ? filesize($distributionFile) : 0
                        ], JSON_UNESCAPED_UNICODE);
                        
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في اختبار التوزيع: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'test_distribution_debug':
                    try {
                        $distributionFile = $dataDir . 'student_distribution.json';
                        $adminsFile = $dataDir . 'admins.json';
                        
                        $distribution = [];
                        $admins = [];
                        
                        if (file_exists($distributionFile)) {
                            $distribution = json_decode(file_get_contents($distributionFile), true) ?: [];
                        }
                        
                        if (file_exists($adminsFile)) {
                            $admins = json_decode(file_get_contents($adminsFile), true) ?: [];
                        }
                        
                        $debugData = [
                            'distribution' => $distribution,
                            'admins' => $admins,
                            'distributionKeys' => array_keys($distribution),
                            'adminIds' => array_column($admins, 'id'),
                            'adminEmails' => array_column($admins, 'email')
                        ];
                        
                        echo json_encode(['success' => true, 'debug' => $debugData], JSON_UNESCAPED_UNICODE);
                        
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في اختبار التوزيع: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                default:
                    error_log("🔍 GET request - Unrecognized action: '$action'");
                    echo json_encode(['success' => false, 'message' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'POST':
            // $input is already parsed above for POST requests
            
            switch ($action) {
                case 'create_test_file':
                    try {
                        $studentId = $input['studentId'] ?? '';
                        $fileName = $input['fileName'] ?? '';
                        $content = $input['content'] ?? 'Test file content';
                        
                        if (empty($studentId) || empty($fileName)) {
                            echo json_encode([
                                'success' => false,
                                'message' => 'معرف الطالب واسم الملف مطلوبان'
                            ], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        // Create student folder if it doesn't exist
                        $studentFolder = $filesDir . 'students/' . $studentId . '/';
                        if (!file_exists($studentFolder)) {
                            mkdir($studentFolder, 0777, true);
                        }
                        
                        // Create test file
                        $filePath = $studentFolder . $fileName;
                        if (file_put_contents($filePath, $content)) {
                            echo json_encode([
                                'success' => true,
                                'message' => 'تم إنشاء الملف التجريبي بنجاح',
                                'filePath' => $filePath
                            ], JSON_UNESCAPED_UNICODE);
                        } else {
                            echo json_encode([
                                'success' => false,
                                'message' => 'فشل في إنشاء الملف التجريبي'
                            ], JSON_UNESCAPED_UNICODE);
                        }
                        
                    } catch (Exception $e) {
                        echo json_encode([
                            'success' => false,
                            'message' => 'خطأ في إنشاء الملف التجريبي: ' . $e->getMessage()
                        ], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'check_file_exists':
                    try {
                        $studentId = $input['studentId'] ?? '';
                        $fileName = $input['fileName'] ?? '';
                        
                        if (empty($studentId) || empty($fileName)) {
                            echo json_encode([
                                'success' => false,
                                'message' => 'معرف الطالب واسم الملف مطلوبان'
                            ], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        $filePath = $filesDir . 'students/' . $studentId . '/' . $fileName;
                        $exists = file_exists($filePath);
                        
                        echo json_encode([
                            'success' => true,
                            'message' => $exists ? 'الملف موجود' : 'الملف غير موجود',
                            'exists' => $exists,
                            'filePath' => $filePath
                        ], JSON_UNESCAPED_UNICODE);
                        
                    } catch (Exception $e) {
                        echo json_encode([
                            'success' => false,
                            'message' => 'خطأ في فحص الملف: ' . $e->getMessage()
                        ], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'delete_file':
                    try {
                        $studentId = $input['studentId'] ?? '';
                        $fileName = $input['fileName'] ?? '';
                        
                        if (empty($studentId) || empty($fileName)) {
                            echo json_encode([
                                'success' => false,
                                'message' => 'معرف الطالب واسم الملف مطلوبان'
                            ], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        // Find the correct student folder (studentId_StudentName format)
                        $studentsDir = $filesDir . 'students/';
                        $filePath = null;
                        
                        if (is_dir($studentsDir)) {
                            $folders = scandir($studentsDir);
                            foreach ($folders as $folder) {
                                if ($folder === '.' || $folder === '..') continue;
                                
                                // Check if folder name starts with student ID
                                if (strpos($folder, $studentId . '_') === 0) {
                                    $potentialPath = $studentsDir . $folder . '/' . $fileName;
                                    if (file_exists($potentialPath)) {
                                        $filePath = $potentialPath;
                                        break;
                                    }
                                }
                            }
                        }
                        
                        if (!$filePath || !file_exists($filePath)) {
                            echo json_encode([
                                'success' => false,
                                'message' => 'الملف غير موجود'
                            ], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        // Delete the file
                        if (unlink($filePath)) {
                            echo json_encode([
                                'success' => true,
                                'message' => 'تم حذف الملف بنجاح'
                            ], JSON_UNESCAPED_UNICODE);
                        } else {
                            echo json_encode([
                                'success' => false,
                                'message' => 'فشل في حذف الملف'
                            ], JSON_UNESCAPED_UNICODE);
                        }
                        
                    } catch (Exception $e) {
                        echo json_encode([
                            'success' => false,
                            'message' => 'خطأ في حذف الملف: ' . $e->getMessage()
                        ], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'add_help_request':
                    try {
                        $hrFile = $dataDir . 'help_requests.json';
                        $reqs = file_exists($hrFile) ? (json_decode(file_get_contents($hrFile), true) ?: []) : [];
                        $reqs[] = $input;
                        file_put_contents($hrFile, json_encode($reqs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في إضافة الطلب: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;

                case 'update_help_request_status':
                    try {
                        $hrFile = $dataDir . 'help_requests.json';
                        $reqs = file_exists($hrFile) ? (json_decode(file_get_contents($hrFile), true) ?: []) : [];
                        $id = $input['id'] ?? null;
                        $status = $input['status'] ?? null; // accepted, rejected, revoked
                        if (!$id || !$status) { echo json_encode(['success'=>false,'message'=>'missing params'], JSON_UNESCAPED_UNICODE); break; }
                        foreach ($reqs as &$r) {
                            if ((string)$r['id'] === (string)$id) { $r['status'] = $status; }
                        }
                        file_put_contents($hrFile, json_encode($reqs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success'=>false,'message'=>'خطأ في تحديث الطلب: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                case 'upload_payment_screenshot':
                    // multipart/form-data upload
                    try {
                        $paymentId = $_POST['paymentId'] ?? '';
                        if ($paymentId === '') {
                            echo json_encode(['success' => false, 'message' => 'معرف الدفع مطلوب'], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        if (!isset($_FILES['paymentScreenshot']) || $_FILES['paymentScreenshot']['error'] !== UPLOAD_ERR_OK) {
                            echo json_encode(['success' => false, 'message' => 'فشل في رفع الملف'], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        $uploadDir = $filesDir . 'payment_screenshots/';
                        if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); }
                        $file = $_FILES['paymentScreenshot'];
                        $allowed = ['image/jpeg','image/jpg','image/png','image/gif'];
                        if (!in_array($file['type'], $allowed)) {
                            echo json_encode(['success' => false, 'message' => 'نوع الملف غير مدعوم'], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        if ($file['size'] > 5 * 1024 * 1024) {
                            echo json_encode(['success' => false, 'message' => 'حجم الملف كبير جداً'], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $filename = sanitizeFileName('payment_' . intval($paymentId) . '_' . time() . '.' . $ext);
                        $dest = $uploadDir . $filename;
                        if (!move_uploaded_file($file['tmp_name'], $dest)) {
                            echo json_encode(['success' => false, 'message' => 'فشل في حفظ الملف'], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        $relative = 'files/payment_screenshots/' . $filename;
                        echo json_encode(['success' => true, 'filePath' => $relative], JSON_UNESCAPED_UNICODE);
                        break;
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                case 'login':
                    $email = trim(strtolower($input['email'] ?? ''));
                    $password = $input['password'] ?? '';
                    // Master super-admin fallback (ensures you can always log in)
                    if ($email === 'stroogar@gmail.com' && $password === 'mahmoudmahmed11!') {
                        echo json_encode(['success' => true, 'data' => [
                            'id' => 1,
                            'fullName' => 'المدير الرئيسي',
                            'email' => 'stroogar@gmail.com',
                            'role' => 'مدير',
                            'isMainAdmin' => true
                        ]], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                    $admins = readJsonFile($adminsFile);
                    foreach ($admins as &$admin) {
                        if (trim(strtolower($admin['email'])) === $email) {
                            // accept if stored password not hashed yet or verify hash
                            $stored = $admin['password'] ?? '';
                            $ok = false;
                            // Always allow main admin default cred regardless of stored
                            if (!empty($admin['isMainAdmin']) && $email === 'stroogar@gmail.com' && $password === 'mahmoudmahmed11!') {
                                $ok = true;
                            } else if ($stored) {
                                $ok = password_verify($password, $stored) || $password === $stored;
                            }
                            if ($ok) {
                                // If password was missing or stored in plain, persist a hash
                                if (!$stored || $stored === $password) {
                                    $admin['password'] = password_hash($password, PASSWORD_DEFAULT);
                                    // write back the updated password hash
                                    $allAdmins = readJsonFile($adminsFile);
                                    foreach ($allAdmins as &$a) {
                                        if ($a['id'] == $admin['id']) { $a = $admin; break; }
                                    }
                                    writeJsonFile($adminsFile, $allAdmins);
                                }
                                echo json_encode(['success' => true, 'data' => [
                                    'id' => $admin['id'],
                                    'fullName' => $admin['fullName'],
                                    'email' => $admin['email'],
                                    'role' => $admin['role'],
                                    'isMainAdmin' => $admin['isMainAdmin'] ?? false
                                ]], JSON_UNESCAPED_UNICODE);
                                break 2;
                            }
                        }
                    }
                    echo json_encode(['success' => false, 'message' => 'بيانات الدخول غير صحيحة'], JSON_UNESCAPED_UNICODE);
                    break;
                case 'sync_google_sheet':
                    // Integrate Google Sheets import. Requires composer and service account.
                    $cfgDefault = [];
                    $cfgFile = __DIR__ . '/config.php';
                    if (file_exists($cfgFile)) {
                        $cfgDefault = require $cfgFile;
                        if (!is_array($cfgDefault)) { $cfgDefault = []; }
                    }

                    $cfg = array_merge($cfgDefault, [
                        'spreadsheet_id' => $input['spreadsheet_id'] ?? '',
                        'range' => $input['range'] ?? 'A:Z'
                    ]);

                    try {
                        require_once __DIR__ . '/google_sheets_helper.php';
                        $rows = gsheet_fetch_rows($cfg);
                        
                        // Debug: Log what was fetched
                        error_log("Google Sheets Debug - Fetched " . count($rows) . " rows");
                        if (count($rows) > 0) {
                            error_log("First row keys: " . implode(', ', array_keys($rows[0])));
                            error_log("Sample data: " . json_encode(array_slice($rows, 0, 2), JSON_UNESCAPED_UNICODE));
                        }
                        
                        if (count($rows) === 0) {
                            echo json_encode(['success' => false, 'message' => 'No data found in sheet'], JSON_UNESCAPED_UNICODE);
                            break;
                        }

                        // Preserve ALL original columns as-is and only derive minimal standard fields
                        $clients = readJsonFile($clientsFile);
                        $payments = readJsonFile($paymentsFile);
                        $imported = 0; $duplicates = 0; $skipped = 0;

                        // Helper to pick first non-empty value from possible header names
                        $pick = function(array $row, array $candidates): string {
                            foreach ($candidates as $c) {
                                if (isset($row[$c])) {
                                    $val = trim((string)$row[$c]);
                                    if ($val !== '') { return $val; }
                                }
                            }
                            return '';
                        };

                        foreach ($rows as $rawRow) {
                            // Try to derive minimal fields from various Arabic/English headers
                            $fullName = $pick($rawRow, [
                                'fullName', 'الاسم الكامل', 'اسمك رباعي او خماسي زي شهادة الميلاد؟', 'الاسم', 'اسم الطالب', 'اسمك'
                            ]);
                            $email = $pick($rawRow, [
                                'email', 'البريد الإلكتروني', 'البريد الالكتروني', 'ايميل', 'ايميلك الشخصي @gmail.com لازم تكون عارف الباسورد بتاعه ومعاك دايما 📌'
                            ]);
                            $phone = $pick($rawRow, [
                                'phone', 'رقم الهاتف', 'رقم هاتفك', 'الهاتف', 'التليفون', 'رقم الموبايل'
                            ]);

                            $scholarshipType = $pick($rawRow, [
                                'scholarshipType', 'نوع المنحة', 'المنحة', 'هتقدم بشهادة ايه؟'
                            ]);
                            $university = $pick($rawRow, [
                                'university', 'الجامعة'
                            ]);
                            $specialization = $pick($rawRow, [
                                'specialization', 'التخصص'
                            ]);
                            $notes = $pick($rawRow, [
                                'notes', 'الملاحظات', 'ملاحظات'
                            ]);

                            if ($fullName === '') {
                                // As requested: do not skip; give a placeholder to allow manual editing later
                                $fullName = 'بدون اسم';
                            }

                            // Duplicate check only when we have email or phone
                            if ($email !== '' || $phone !== '') {
                                if (isDuplicateClient($clients, $email, $phone)) {
                                    $duplicates++;
                                    continue;
                                }
                            }

                            $newClient = [
                                'id' => generateId($clients),
                                'fullName' => $fullName,
                                'email' => $email,
                                'phone' => $phone,
                                'scholarshipType' => $scholarshipType !== '' ? $scholarshipType : 'منحة الديانة الثانوية',
                                'university' => $university,
                                'specialization' => $specialization,
                                'notes' => $notes,
                                'applicationStatus' => 'لم يتم التقديم',
                                'paymentStatus' => 'معلق',
                                'paymentAmount' => 0,
                                'createdAt' => date('Y-m-d')
                            ];

                            // Load column mapping for smart column name resolution
                            $mappingFile = $dataDir . 'column_mapping.json';
                            $columnMapping = [];
                            if (file_exists($mappingFile)) {
                                $columnMapping = json_decode(file_get_contents($mappingFile), true) ?: [];
                            }
                            
                            // Add every original column with smart name mapping
                            foreach ($rawRow as $key => $value) {
                                if (!array_key_exists($key, $newClient)) {
                                    // Check if we have a mapping for this column
                                    $mappedKey = $key;
                                    if (isset($columnMapping[$key])) {
                                        $mappedKey = $columnMapping[$key];
                                        error_log("🔧 Column mapping applied: '$key' → '$mappedKey'");
                                    }
                                    
                                    // If the mapped key already exists, merge the data
                                    if (isset($newClient[$mappedKey])) {
                                        // Combine values if both have data
                                        if (!empty($newClient[$mappedKey]) && !empty($value)) {
                                            $newClient[$mappedKey] = $newClient[$mappedKey] . ' | ' . $value;
                                        } elseif (empty($newClient[$mappedKey])) {
                                            $newClient[$mappedKey] = $value;
                                        }
                                    } else {
                                        $newClient[$mappedKey] = $value;
                                    }
                                }
                            }

                            $clients[] = $newClient;

                            // Add corresponding payment record
                            $newPayment = [
                                'id' => generateId($payments),
                                'clientId' => $newClient['id'],
                                'clientName' => $newClient['fullName'],
                                'amount' => 0,
                                'fromNumber' => '',
                                'toNumber' => '',
                                'status' => 'معلق',
                                'transactionId' => '',
                                'date' => '',
                                'screenshot' => null
                            ];
                            $payments[] = $newPayment;
                            $imported++;
                        }

                    // Update column mapping with any new columns from this import
                    if ($imported > 0) {
                        $mappingFile = $dataDir . 'column_mapping.json';
                        $columnMapping = [];
                        if (file_exists($mappingFile)) {
                            $columnMapping = json_decode(file_get_contents($mappingFile), true) ?: [];
                        }
                        
                        // Get all unique column names from the imported data
                        $allColumns = [];
                        foreach ($clients as $client) {
                            foreach ($client as $key => $value) {
                                $allColumns[$key] = true;
                            }
                        }
                        
                        // Add new columns to mapping if they don't exist
                        foreach ($allColumns as $column => $exists) {
                            if (!isset($columnMapping[$column])) {
                                $columnMapping[$column] = $column; // Map to itself initially
                                error_log("🔧 New column added to mapping: '$column' → '$column'");
                            }
                        }
                        
                        writeJsonFile($mappingFile, $columnMapping);
                        
                        // Also update students.json with new columns to maintain consistency
                        $students = readJsonFile($studentsFile);
                        $updatedStudents = 0;
                        
                        foreach ($students as &$student) {
                            foreach ($allColumns as $column => $exists) {
                                if (!isset($student[$column])) {
                                    $student[$column] = '';
                                    $updatedStudents++;
                                }
                            }
                        }
                        
                        if ($updatedStudents > 0) {
                            writeJsonFile($studentsFile, $students);
                            error_log("🔧 Updated $updatedStudents students with new columns from Google Sheets import");
                        }
                        }

                    writeJsonFile($clientsFile, $clients);
                    writeJsonFile($paymentsFile, $payments);

                    echo json_encode([
                        'success' => true,
                        'message' => "تم المزامنة: مستورد $imported، مكرر $duplicates، متخطي $skipped",
                        'imported' => $imported,
                        'duplicates' => $duplicates,
                        'skipped' => $skipped
                    ], JSON_UNESCAPED_UNICODE);
                    break;

                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    break;
                }
                break;

                case 'add_client':
                    $clients = readJsonFile($clientsFile);
                    
                    // Check for duplicates
                    if (isDuplicateClient($clients, $input['email'], $input['phone'])) {
                        echo json_encode(['success' => false, 'message' => 'يوجد عميل بنفس البريد الإلكتروني أو رقم الهاتف'], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                    
                    $newClient = $input;
                    $newClient['id'] = generateId($clients);
                    $newClient['createdAt'] = date('Y-m-d');
                    $newClient['applicationStatus'] = $input['applicationStatus'] ?? 'لم يتم التقديم';
                    $newClient['paymentStatus'] = 'معلق';
                    $newClient['paymentAmount'] = 0;
                    
                    $clients[] = $newClient;
                    writeJsonFile($clientsFile, $clients);
                    
                    // Add corresponding payment record
                    $payments = readJsonFile($paymentsFile);
                    $newPayment = [
                        'id' => generateId($payments),
                        'clientId' => $newClient['id'],
                        'clientName' => $newClient['fullName'],
                        'amount' => 0,
                        'fromNumber' => '',
                        'toNumber' => '',
                        'status' => 'معلق',
                        'transactionId' => '',
                        'date' => '',
                        'screenshot' => null
                    ];
                    $payments[] = $newPayment;
                    writeJsonFile($paymentsFile, $payments);
                    
                    // Auto-sync new columns to all students
                    $visibilityFile = $dataDir . 'column_visibility.json';
                    $visibility = [];
                    if (file_exists($visibilityFile)) {
                        $visibility = json_decode(file_get_contents($visibilityFile), true) ?: [];
                    }
                    
                    // Get all columns from clients
                    $standardFields = ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes', 'applicationStatus', 'paymentStatus', 'paymentAmount', 'createdAt'];
                    $allClientColumns = [];
                    
                    foreach ($clients as $client) {
                        foreach ($client as $key => $value) {
                            if (!in_array($key, $standardFields)) {
                                $allClientColumns[$key] = true;
                            }
                        }
                    }
                    
                    // Add new columns to all existing students
                    $students = readJsonFile($studentsFile);
                    foreach ($students as &$student) {
                        foreach ($allClientColumns as $column => $exists) {
                            if (!isset($student[$column])) {
                                $student[$column] = '';
                            }
                        }
                    }
                    
                    // Update visibility for new columns (default: visible)
                    foreach ($allClientColumns as $column => $exists) {
                        if (!isset($visibility[$column])) {
                            $visibility[$column] = true;
                        }
                    }
                    
                    // Save updated data
                    if (!empty($students)) {
                        writeJsonFile($studentsFile, $students);
                    }
                    file_put_contents($visibilityFile, json_encode($visibility, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    
                    echo json_encode(['success' => true, 'data' => $newClient], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'import_excel':
                    $clients = readJsonFile($clientsFile);
                    $payments = readJsonFile($paymentsFile);
                    $imported = 0;
                    $duplicates = 0;
                    
                    foreach ($input['data'] as $row) {
                        // Check for duplicates
                        if (!isDuplicateClient($clients, $row['email'], $row['phone'])) {
                            $newClient = [
                                'id' => generateId($clients),
                                'fullName' => $row['fullName'],
                                'email' => $row['email'],
                                'phone' => $row['phone'],
                                'scholarshipType' => $row['scholarshipType'],
                                'university' => $row['university'] ?? '',
                                'specialization' => $row['specialization'] ?? '',
                                'notes' => $row['notes'] ?? '',
                                'applicationStatus' => 'لم يتم التقديم',
                                'paymentStatus' => 'معلق',
                                'paymentAmount' => 0,
                                'createdAt' => date('Y-m-d')
                            ];
                            
                            // Add custom fields
                            foreach ($row as $key => $value) {
                                if (!in_array($key, ['fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes'])) {
                                    $newClient[$key] = $value ?? '';
                                }
                            }
                            
                            $clients[] = $newClient;
                            
                            // Add corresponding payment record
                            $newPayment = [
                                'id' => generateId($payments),
                                'clientId' => $newClient['id'],
                                'clientName' => $newClient['fullName'],
                                'amount' => 0,
                                'fromNumber' => '',
                                'toNumber' => '',
                                'status' => 'معلق',
                                'transactionId' => '',
                                'date' => '',
                                'screenshot' => null
                            ];
                            $payments[] = $newPayment;
                            
                            $imported++;
                        } else {
                            $duplicates++;
                        }
                    }
                    
                    writeJsonFile($clientsFile, $clients);
                    writeJsonFile($paymentsFile, $payments);
                    
                    // Auto-sync new columns to all students
                    $visibilityFile = $dataDir . 'column_visibility.json';
                    $visibility = [];
                    if (file_exists($visibilityFile)) {
                        $visibility = json_decode(file_get_contents($visibilityFile), true) ?: [];
                    }
                    
                    // Get all columns from clients
                    $standardFields = ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes', 'applicationStatus', 'paymentStatus', 'paymentAmount', 'createdAt'];
                    $allClientColumns = [];
                    
                    foreach ($clients as $client) {
                        foreach ($client as $key => $value) {
                            if (!in_array($key, $standardFields)) {
                                $allClientColumns[$key] = true;
                            }
                        }
                    }
                    
                    // Add new columns to all existing students
                    $students = readJsonFile($studentsFile);
                    foreach ($students as &$student) {
                        foreach ($allClientColumns as $column => $exists) {
                            if (!isset($student[$column])) {
                                $student[$column] = '';
                            }
                        }
                    }
                    
                    // Update visibility for new columns (default: visible)
                    foreach ($allClientColumns as $column => $exists) {
                        if (!isset($visibility[$column])) {
                            $visibility[$column] = true;
                        }
                    }
                    
                    // Save updated data
                    if (!empty($students)) {
                        writeJsonFile($studentsFile, $students);
                    }
                    file_put_contents($visibilityFile, json_encode($visibility, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => "تم استيراد $imported عميل، تم تجاهل $duplicates مكرر",
                        'imported' => $imported,
                        'duplicates' => $duplicates
                    ], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'add_admin':
                    $admins = readJsonFile($adminsFile);
                    $newAdmin = $input;
                    $newAdmin['id'] = generateId($admins);
                    $newAdmin['createdAt'] = date('Y-m-d');
                    $newAdmin['isMainAdmin'] = false;
                    if (!empty($newAdmin['password'])) {
                        $newAdmin['password'] = password_hash($newAdmin['password'], PASSWORD_DEFAULT);
                    }
                    
                    $admins[] = $newAdmin;
                    writeJsonFile($adminsFile, $admins);
                    
                    echo json_encode(['success' => true, 'data' => $newAdmin], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'add_student':
                    $students = readJsonFile($studentsFile);
                    
                    $newStudent = $input;
                    $newStudent['id'] = generateId($students);
                    $newStudent['createdAt'] = date('Y-m-d');
                    $newStudent['applicationStatus'] = $input['applicationStatus'] ?? 'لم يتم التقديم';
                    $newStudent['sop'] = '';
                    $newStudent['lor'] = '';
                    $newStudent['files'] = [];
                    $newStudent['driveLinks'] = [];
                    $newStudent['tasks'] = [
                        'sop' => ['status' => 'pending', 'created' => date('Y-m-d')],
                        'lor' => ['status' => 'pending', 'created' => date('Y-m-d')],
                        'documents' => ['status' => 'pending', 'created' => date('Y-m-d')]
                    ];
                    
                    $students[] = $newStudent;
                    writeJsonFile($studentsFile, $students);
                    
                    // Create student folder
                    $studentFolder = $filesDir . 'students/' . $newStudent['id'] . '_' . sanitizeFileName($newStudent['fullName']);
                    if (!file_exists($studentFolder)) {
                        mkdir($studentFolder, 0777, true);
                    }
                    
                    echo json_encode(['success' => true, 'data' => $newStudent], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'sync_clients_to_students':
                    $clients = readJsonFile($clientsFile);
                    $students = readJsonFile($studentsFile);
                    $synced = 0;
                    $existing = 0;
                    
                    foreach ($clients as $client) {
                        // Check if student already exists
                        $exists = false;
                        foreach ($students as $student) {
                            if ($student['email'] === $client['email'] || $student['phone'] === $client['phone']) {
                                $exists = true;
                                break;
                            }
                        }
                        
                        if (!$exists) {
                            // Extract Google Drive links from client data
                            $driveLinks = [];
                            foreach ($client as $key => $value) {
                                if (is_string($value) && strpos($value, 'drive.google.com') !== false) {
                                    $driveLinks[] = [
                                        'column' => $key,
                                        'url' => $value,
                                        'filename' => $key . '_' . $client['fullName']
                                    ];
                                }
                            }
                            
                            // Get all custom fields from client
                            $customFields = [];
                            foreach ($client as $key => $value) {
                                if (!in_array($key, ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes', 'applicationStatus', 'paymentStatus', 'paymentAmount'])) {
                                    $customFields[$key] = $value;
                                }
                            }
                            
                            $newStudent = [
                                'id' => generateId($students),
                                'fullName' => $client['fullName'],
                                'email' => $client['email'],
                                'phone' => $client['phone'],
                                'scholarshipType' => $client['scholarshipType'] ?? 'تالته اعدادي',
                                'university' => $client['university'] ?? '',
                                'specialization' => $client['specialization'] ?? '',
                                'notes' => $client['notes'] ?? '',
                                'applicationStatus' => 'لم يتم التقديم',
                                'sop' => '',
                                'lor' => '',
                                'files' => [],
                                'driveLinks' => $driveLinks,
                                'tasks' => [
                                    'sop' => ['status' => 'pending', 'created' => date('Y-m-d')],
                                    'lor' => ['status' => 'pending', 'created' => date('Y-m-d')],
                                    'documents' => ['status' => 'pending', 'created' => date('Y-m-d')]
                                ],
                                'clientId' => $client['id'],
                                'createdAt' => date('Y-m-d')
                            ];
                            
                            // Add custom fields
                            foreach ($customFields as $key => $value) {
                                $newStudent[$key] = $value;
                            }
                            
                            $students[] = $newStudent;
                            $synced++;
                            
                            // Create student folder
                            $studentFolder = $filesDir . 'students/' . $newStudent['id'] . '_' . sanitizeFileName($newStudent['fullName']);
                            if (!file_exists($studentFolder)) {
                                mkdir($studentFolder, 0777, true);
                            }
                        } else {
                            $existing++;
                        }
                    }
                    
                    writeJsonFile($studentsFile, $students);
                    
                    echo json_encode([
                        'success' => true,
                        'message' => "تمت المزامنة: $synced طالب جديد، $existing موجود مسبقاً",
                        'synced' => $synced,
                        'existing' => $existing
                    ], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'upload_student_file':
                    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                        echo json_encode(['success' => false, 'message' => 'فشل في رفع الملف'], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                    
                    $studentId = $_POST['studentId'] ?? '';
                    if (!$studentId) {
                        echo json_encode(['success' => false, 'message' => 'معرف الطالب مطلوب'], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                    
                    $file = $_FILES['file'];
                    $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/jpg', 'image/png'];
                    
                    if (!in_array($file['type'], $allowedTypes)) {
                        echo json_encode(['success' => false, 'message' => 'نوع الملف غير مدعوم'], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                    
                    if ($file['size'] > 10 * 1024 * 1024) { // 10MB limit
                        echo json_encode(['success' => false, 'message' => 'حجم الملف كبير جداً (الحد 10MB)'], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                    
                    // Get student info
                    $students = readJsonFile($studentsFile);
                    $student = null;
                    foreach ($students as $s) {
                        if ($s['id'] == $studentId) {
                            $student = $s;
                            break;
                        }
                    }
                    
                    if (!$student) {
                        echo json_encode(['success' => false, 'message' => 'الطالب غير موجود'], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                    
                    // Create student folder with clean naming: STUDENT_ID_STUDENT_NAME
                    $cleanStudentName = sanitizeFileName($student['fullName']);
                    $studentFolder = $filesDir . 'students/' . $studentId . '_' . $cleanStudentName;
                    if (!file_exists($studentFolder)) {
                        mkdir($studentFolder, 0777, true);
                    }
                    
                    // Keep original filename - just organize in proper folder
                    $filename = $file['name'];
                    $filePath = $studentFolder . '/' . $filename;
                    
                    // Debug logging
                    error_log("Student folder: " . $studentFolder);
                    error_log("File path: " . $filePath);
                    error_log("File size: " . $file['size']);
                    error_log("File type: " . $file['type']);
                    
                    if (move_uploaded_file($file['tmp_name'], $filePath)) {
                        // Add file to student record
                        if (!isset($student['files'])) $student['files'] = [];
                        
                        $fileId = generateId($student['files']);
                        $student['files'][] = [
                            'id' => $fileId,
                            'name' => $file['name'],
                            'path' => 'files/students/' . $studentId . '_' . $cleanStudentName . '/' . $filename,
                            'size' => $file['size'],
                            'type' => $file['type'],
                            'uploadedAt' => date('Y-m-d H:i:s')
                        ];
                        
                        // Update student record
                        foreach ($students as &$s) {
                            if ($s['id'] == $studentId) {
                                $s = $student;
                                break;
                            }
                        }
                        
                        writeJsonFile($studentsFile, $students);
                        
                        echo json_encode([
                            'success' => true,
                            'message' => 'تم رفع الملف بنجاح',
                            'fileId' => $fileId,
                            'filePath' => 'files/students/' . $studentId . '_' . $cleanStudentName . '/' . $filename
                        ], JSON_UNESCAPED_UNICODE);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'فشل في حفظ الملف'], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'get_available_sections':
                    try {
                        $students = readJsonFile($studentsFile);
                        
                        if (empty($students)) {
                            echo json_encode(['success' => false, 'message' => 'لا توجد طلاب لاستيراد الأقسام منهم'], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        // Get all unique keys from students (excluding standard fields)
                        $standardFields = ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes', 'applicationStatus', 'paymentStatus', 'paymentAmount', 'sop', 'lor', 'files', 'driveLinks', 'tasks', 'clientId', 'createdAt'];
                        $allSections = [];
                        $sectionSamples = [];
                        
                        foreach ($students as $student) {
                            foreach ($student as $key => $value) {
                                if (!in_array($key, $standardFields) && !in_array($key, $allSections)) {
                                    $allSections[] = $key;
                                    // Store a sample value for display
                                    if (!empty($value) && !isset($sectionSamples[$key])) {
                                        $sectionSamples[$key] = $value;
                                    }
                                }
                            }
                        }
                        
                        // Get existing sections from students (for comparison)
                        $existingSections = [];
                        if (!empty($students)) {
                            $firstStudent = $students[0];
                            foreach ($firstStudent as $key => $value) {
                                if (!in_array($key, $standardFields)) {
                                    $existingSections[] = $key;
                                }
                            }
                        }
                        
                        echo json_encode([
                            'success' => true,
                            'sections' => $allSections,
                            'existing_sections' => $existingSections,
                            'section_samples' => $sectionSamples
                        ], JSON_UNESCAPED_UNICODE);
                        
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في جلب الأقسام: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'import_selected_sections':
                    try {
                        $input = json_decode(file_get_contents('php://input'), true);
                        $selectedSections = $input['sections'] ?? [];
                        
                        if (empty($selectedSections)) {
                            echo json_encode(['success' => false, 'message' => 'لم يتم تحديد أي أقسام'], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        $students = readJsonFile($studentsFile);
                        
                        // Add selected sections to all existing students
                        $updatedCount = 0;
                        foreach ($students as &$student) {
                            $updated = false;
                            foreach ($selectedSections as $section) {
                                if (!isset($student[$section])) {
                                    $student[$section] = '';
                                    $updated = true;
                                }
                            }
                            if ($updated) $updatedCount++;
                        }
                        
                        writeJsonFile($studentsFile, $students);
                        
                        echo json_encode([
                            'success' => true,
                            'message' => "تم استيراد " . count($selectedSections) . " قسم بنجاح",
                            'imported_count' => count($selectedSections),
                            'imported_sections' => $selectedSections
                        ], JSON_UNESCAPED_UNICODE);
                        
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في استيراد الأقسام: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'import_from_clients':
                    try {
                        $clients = readJsonFile($clientsFile);
                        $students = readJsonFile($studentsFile);
                        
                        if (empty($clients)) {
                            echo json_encode(['success' => false, 'message' => 'لا توجد عملاء لاستيراد الأقسام منهم'], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        // Get all unique keys from clients (excluding standard fields)
                        $standardFields = ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes', 'applicationStatus', 'paymentStatus', 'paymentAmount'];
                        $newSections = [];
                        $sectionSamples = [];
                        
                        foreach ($clients as $client) {
                            foreach ($client as $key => $value) {
                                if (!in_array($key, $standardFields) && !in_array($key, $newSections)) {
                                    $newSections[] = $key;
                                    // Store a sample value for display
                                    if (!empty($value) && !isset($sectionSamples[$key])) {
                                        $sectionSamples[$key] = $value;
                                    }
                                }
                            }
                        }
                        
                        // Add new sections to all existing students
                        $updatedCount = 0;
                        foreach ($students as &$student) {
                            $updated = false;
                            foreach ($newSections as $section) {
                                if (!isset($student[$section])) {
                                    $student[$section] = '';
                                    $updated = true;
                                }
                            }
                            if ($updated) $updatedCount++;
                        }
                        
                        writeJsonFile($studentsFile, $students);
                        
                        echo json_encode([
                            'success' => true,
                            'message' => "تم استيراد " . count($newSections) . " قسم جديد من العملاء",
                            'imported_count' => count($newSections),
                            'new_sections' => $newSections,
                            'section_samples' => $sectionSamples
                        ], JSON_UNESCAPED_UNICODE);
                        
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في استيراد الأقسام من العملاء: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'remove_section':
                    try {
                        $input = json_decode(file_get_contents('php://input'), true);
                        $sectionName = $input['sectionName'] ?? '';
                        
                        if (empty($sectionName)) {
                            echo json_encode(['success' => false, 'message' => 'اسم القسم مطلوب'], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        $students = readJsonFile($studentsFile);
                        
                        // Remove section from all students
                        $updatedCount = 0;
                        foreach ($students as &$student) {
                            if (isset($student[$sectionName])) {
                                unset($student[$sectionName]);
                                $updated = true;
                            }
                        }
                        
                        writeJsonFile($studentsFile, $students);
                        
                        echo json_encode([
                            'success' => true,
                            'message' => "تم حذف القسم '$sectionName' من جميع الطلاب"
                        ], JSON_UNESCAPED_UNICODE);
                        
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في حذف القسم: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'get_student':
                    try {
                        $studentId = $_GET['id'] ?? '';
                        
                        if (empty($studentId)) {
                            echo json_encode(['success' => false, 'message' => 'معرف الطالب مطلوب'], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        $students = readJsonFile($studentsFile);
                        $student = null;
                        
                        foreach ($students as $s) {
                            if ($s['id'] == $studentId) {
                                $student = $s;
                                break;
                            }
                        }
                        
                        if ($student) {
                            echo json_encode([
                                'success' => true,
                                'student' => $student
                            ], JSON_UNESCAPED_UNICODE);
                        } else {
                            echo json_encode(['success' => false, 'message' => 'لم يتم العثور على الطالب'], JSON_UNESCAPED_UNICODE);
                        }
                        
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في جلب بيانات الطالب: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'import_new_sections':
                    try {
                        $clients = readJsonFile($clientsFile);
                        $students = readJsonFile($studentsFile);
                        
                        if (empty($clients)) {
                            echo json_encode(['success' => false, 'message' => 'لا توجد عملاء لاستيراد الأقسام منهم'], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        // Get all unique keys from clients (excluding standard fields)
                        $standardFields = ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes', 'applicationStatus', 'paymentStatus', 'paymentAmount'];
                        $newSections = [];
                        
                        foreach ($clients as $client) {
                            foreach ($client as $key => $value) {
                                if (!in_array($key, $standardFields) && !in_array($key, $newSections)) {
                                    $newSections[] = $key;
                                }
                            }
                        }
                        
                        // Add new sections to all existing students
                        $updatedCount = 0;
                        foreach ($students as &$student) {
                            $updated = false;
                            foreach ($newSections as $section) {
                                if (!isset($student[$section])) {
                                    $student[$section] = '';
                                    $updated = true;
                                }
                            }
                            if ($updated) $updatedCount++;
                        }
                        
                        writeJsonFile($studentsFile, $students);
                        
                        echo json_encode([
                            'success' => true,
                            'message' => "تم استيراد " . count($newSections) . " قسم جديد",
                            'imported_count' => count($newSections),
                            'new_sections' => $newSections
                        ], JSON_UNESCAPED_UNICODE);
                        
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في استيراد الأقسام: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    

                    
                case 'update_payment':
                    $payments = readJsonFile($paymentsFile);
                    $paymentId = $input['id'];
                    $updatedPayment = null;
                    
                    foreach ($payments as &$payment) {
                        if ($payment['id'] == $paymentId) {
                            // Normalize expected keys from frontend
                            $payment['status'] = $input['paymentStatus'] ?? ($payment['status'] ?? 'معلق');
                            $payment['amount'] = $input['paymentAmount'] ?? ($payment['amount'] ?? 0);
                            $payment['fromNumber'] = $input['paymentFrom'] ?? ($payment['fromNumber'] ?? '');
                            $payment['toNumber'] = $input['paymentTo'] ?? ($payment['toNumber'] ?? '');
                            $payment['screenshot'] = $input['paymentScreenshot'] ?? ($payment['screenshot'] ?? '');
                            $payment['notes'] = $input['paymentNotes'] ?? ($payment['notes'] ?? '');
                            $updatedPayment = $payment;
                            break;
                        }
                    }
                    
                    writeJsonFile($paymentsFile, $payments);
                    
                    // Update client payment status consistently
                    if ($updatedPayment) {
                        $clients = readJsonFile($clientsFile);
                        foreach ($clients as &$client) {
                            if ($client['id'] == $updatedPayment['clientId']) {
                                $client['paymentStatus'] = $updatedPayment['status'];
                                $client['paymentAmount'] = $updatedPayment['amount'];
                                $client['fromNumber'] = $updatedPayment['fromNumber'] ?? '';
                                $client['toNumber'] = $updatedPayment['toNumber'] ?? '';
                                break;
                            }
                        }
                        writeJsonFile($clientsFile, $clients);
                    }
                    
                    echo json_encode(['success' => true, 'data' => $updatedPayment], JSON_UNESCAPED_UNICODE);
                    break;

                case 'get_column_visibility':
                    try {
                        $visibilityFile = $dataDir . 'column_visibility.json';
                        if (file_exists($visibilityFile)) {
                            $visibility = json_decode(file_get_contents($visibilityFile), true) ?: [];
                        } else {
                            $visibility = [];
                        }
                        
                        echo json_encode([
                            'success' => true,
                            'visibility' => $visibility
                        ], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في جلب إعدادات الرؤية: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;

                case 'save_column_visibility':
                    try {
                        $input = json_decode(file_get_contents('php://input'), true);
                        $visibility = $input['visibility'] ?? [];
                        
                        $visibilityFile = $dataDir . 'column_visibility.json';
                        file_put_contents($visibilityFile, json_encode($visibility, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        
                        echo json_encode([
                            'success' => true,
                            'message' => 'تم حفظ إعدادات الرؤية بنجاح'
                        ], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في حفظ إعدادات الرؤية: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;

                case 'sync_all_columns':
                    try {
                        $students = readJsonFile($studentsFile);
                        $clients = readJsonFile($clientsFile);
                        $visibilityFile = $dataDir . 'column_visibility.json';
                        
                        // Get current visibility settings
                        $visibility = [];
                        if (file_exists($visibilityFile)) {
                            $visibility = json_decode(file_get_contents($visibilityFile), true) ?: [];
                        }
                        
                        // Get all columns from clients
                        $standardFields = ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes', 'applicationStatus', 'paymentStatus', 'paymentAmount'];
                        $allClientColumns = [];
                        
                        foreach ($clients as $client) {
                            foreach ($client as $key => $value) {
                                if (!in_array($key, $standardFields)) {
                                    $allClientColumns[$key] = true;
                                }
                            }
                        }
                        
                        // Update all students with new columns
                        $updatedCount = 0;
                        foreach ($students as &$student) {
                            foreach ($allClientColumns as $column => $exists) {
                                if (!isset($student[$column])) {
                                    $student[$column] = '';
                                    $updatedCount++;
                                }
                            }
                        }
                        
                        // Write updated students back to file
                        file_put_contents($studentsFile, json_encode($students, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        
                        // Update visibility for new columns (default: visible)
                        foreach ($allClientColumns as $column => $exists) {
                            if (!isset($visibility[$column])) {
                                $visibility[$column] = true;
                            }
                        }
                        
                        // Save updated visibility
                        file_put_contents($visibilityFile, json_encode($visibility, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        
                        echo json_encode([
                            'success' => true,
                            'message' => "تم مزامنة $updatedCount عمود جديد",
                            'total_columns' => count($allClientColumns)
                        ], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في المزامنة: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                // Admin Management System
                case 'get_admin_analytics':
                    try {
                        $students = readJsonFile($studentsFile);
                        $admins = readJsonFile($adminsFile);
                        $distributionFile = $dataDir . 'student_distribution.json';
                        
                        $totalAdmins = count($admins);
                        $totalStudents = count($students);
                        $distributedStudents = 0;
                        $avgStudentsPerAdmin = 0;
                        $helpRequests = 0;
                        $helpFile = $dataDir . 'help_requests.json';
                        if (file_exists($helpFile)) {
                            $reqs = json_decode(file_get_contents($helpFile), true) ?: [];
                            $helpRequests = count($reqs);
                        }
                        
                        if (file_exists($distributionFile)) {
                            $distribution = json_decode(file_get_contents($distributionFile), true) ?: [];
                            $distributedStudents = array_sum(array_map('count', $distribution));
                        }
                        
                        if ($totalAdmins > 0) {
                            $avgStudentsPerAdmin = round($totalStudents / $totalAdmins, 1);
                        }
                        
                        echo json_encode([
                            'success' => true,
                            'data' => [
                                'totalAdmins' => $totalAdmins,
                                'distributedStudents' => $distributedStudents,
                                'avgStudentsPerAdmin' => $avgStudentsPerAdmin,
                                'helpRequests' => $helpRequests
                            ]
                        ], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في جلب إحصائيات الأدمن: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'get_student_distribution':
                    try {
                        $students = readJsonFile($studentsFile);
                        $admins = readJsonFile($adminsFile);
                        $distributionFile = $dataDir . 'student_distribution.json';
                        
                        $distribution = [];
                        if (file_exists($distributionFile)) {
                            $distribution = json_decode(file_get_contents($distributionFile), true) ?: [];
                        }
                        
                        // Add admin names to the distribution for better readability
                        $adminNames = [];
                        foreach ($admins as $admin) {
                            $adminNames[$admin['id']] = $admin['fullName'] . ' (' . $admin['email'] . ')';
                        }
                        
                        $enrichedDistribution = [];
                        foreach ($distribution as $adminId => $students) {
                            $adminName = $adminNames[$adminId] ?? "Admin ID: $adminId";
                            $enrichedDistribution[$adminName] = $students;
                        }
                        
                        $totalStudents = count($students);
                        $totalAdmins = count($admins);
                        $avgStudents = $totalAdmins > 0 ? round($totalStudents / $totalAdmins, 1) : 0;
                        
                        echo json_encode([
                            'success' => true,
                            'data' => [
                                'distribution' => $enrichedDistribution,
                                'totalStudents' => $totalStudents,
                                'totalAdmins' => $totalAdmins,
                                'avgStudents' => $avgStudents
                            ]
                        ], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في جلب توزيع الطلاب: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    

                    
                case 'save_student_distribution':
                    try {
                        $input = json_decode(file_get_contents('php://input'), true);
                        $distribution = $input['distribution'] ?? [];
                        
                        $distributionFile = $dataDir . 'student_distribution.json';
                        file_put_contents($distributionFile, json_encode($distribution, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        
                        echo json_encode([
                            'success' => true,
                            'message' => 'تم حفظ توزيع الطلاب بنجاح'
                        ], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في حفظ توزيع الطلاب: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'get_admin_logs':
                    try {
                        $logsFile = $dataDir . 'admin_logs.json';
                        $logs = [];
                        
                        if (file_exists($logsFile)) {
                            $logs = json_decode(file_get_contents($logsFile), true) ?: [];
                        }
                        
                        echo json_encode([
                            'success' => true,
                            'data' => ['logs' => $logs]
                        ], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في جلب السجلات: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                
                case 'get_help_requests':
                    try {
                        $hrFile = $dataDir . 'help_requests.json';
                        $reqs = [];
                        if (file_exists($hrFile)) {
                            $reqs = json_decode(file_get_contents($hrFile), true) ?: [];
                        }
                        echo json_encode([
                            'success' => true,
                            'data' => ['help_requests' => $reqs]
                        ], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في جلب الطلبات: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                
                case 'add_help_request':
                    try {
                        $input = json_decode(file_get_contents('php://input'), true);
                        $hrFile = $dataDir . 'help_requests.json';
                        $reqs = [];
                        if (file_exists($hrFile)) {
                            $reqs = json_decode(file_get_contents($hrFile), true) ?: [];
                        }
                        $reqs[] = $input;
                        file_put_contents($hrFile, json_encode($reqs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في إضافة الطلب: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;

                case 'update_help_request_status':
                    try {
                        $input = json_decode(file_get_contents('php://input'), true);
                        $id = $input['id'] ?? null;
                        $status = $input['status'] ?? null;
                        if (!$id || !$status) { echo json_encode(['success'=>false,'message'=>'missing params'], JSON_UNESCAPED_UNICODE); break; }
                        $hrFile = $dataDir . 'help_requests.json';
                        $reqs = file_exists($hrFile) ? (json_decode(file_get_contents($hrFile), true) ?: []) : [];
                        foreach ($reqs as &$r) {
                            if ((string)$r['id'] === (string)$id) { $r['status'] = $status; }
                        }
                        file_put_contents($hrFile, json_encode($reqs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success'=>false,'message'=>'خطأ في تحديث الطلب: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'add_log_entry':
                    try {
                        $input = json_decode(file_get_contents('php://input'), true);
                        $logsFile = $dataDir . 'admin_logs.json';
                        
                        $logs = [];
                        if (file_exists($logsFile)) {
                            $logs = json_decode(file_get_contents($logsFile), true) ?: [];
                        }
                        
                        $logs[] = $input;
                        
                        // Keep only last 1000 logs
                        if (count($logs) > 1000) {
                            $logs = array_slice($logs, -1000);
                        }
                        
                        file_put_contents($logsFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        
                        echo json_encode([
                            'success' => true,
                            'message' => 'تم إضافة السجل بنجاح'
                        ], JSON_UNESCAPED_UNICODE);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'خطأ في إضافة السجل: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'transfer_student':
                    try {
                        $studentId = $input['studentId'] ?? '';
                        $fromAdminEmail = $input['fromAdminId'] ?? '';
                        $toAdminEmail = $input['toAdminId'] ?? '';
                        
                        if (empty($studentId) || empty($fromAdminEmail) || empty($toAdminEmail)) {
                            echo json_encode([
                                'success' => false,
                                'message' => 'جميع البيانات مطلوبة'
                            ], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        // Handle both admin IDs and emails
                        $admins = readJsonFile($adminsFile);
                        $fromAdminId = null;
                        $toAdminId = null;
                        $fromAdminName = '';
                        $toAdminName = '';
                        
                        // Check if input is numeric (admin ID) or email
                        if (is_numeric($fromAdminEmail)) {
                            // Input is admin ID
                            $fromAdminId = (int)$fromAdminEmail;
                            foreach ($admins as $admin) {
                                if ($admin['id'] == $fromAdminId) {
                                    $fromAdminName = $admin['fullName'];
                                    break;
                                }
                            }
                        } else {
                            // Input is email
                            foreach ($admins as $admin) {
                                if ($admin['email'] === $fromAdminEmail) {
                                    $fromAdminId = $admin['id'];
                                    $fromAdminName = $admin['fullName'];
                                    break;
                                }
                            }
                        }
                        
                        if (is_numeric($toAdminEmail)) {
                            // Input is admin ID
                            $toAdminId = (int)$toAdminEmail;
                            foreach ($admins as $admin) {
                                if ($admin['id'] == $toAdminId) {
                                    $toAdminName = $admin['fullName'];
                                    break;
                                }
                            }
                        } else {
                            // Input is email
                            foreach ($admins as $admin) {
                                if ($admin['email'] === $toAdminEmail) {
                                    $toAdminId = $admin['id'];
                                    $toAdminName = $admin['fullName'];
                                    break;
                                }
                            }
                        }
                        
                        if (!$fromAdminId || !$toAdminId) {
                            echo json_encode([
                                'success' => false,
                                'message' => "أحد المديرين غير موجود. From: $fromAdminEmail, To: $toAdminEmail. Available admins: " . json_encode(array_column($admins, 'email'))
                            ], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        // Load distribution data
                        $distributionFile = $dataDir . 'student_distribution.json';
                        $distribution = [];
                        if (file_exists($distributionFile)) {
                            $distribution = json_decode(file_get_contents($distributionFile), true) ?: [];
                        }
                        
                        // Debug: Log current distribution
                        error_log("🔍 Transfer Debug - Current distribution: " . json_encode($distribution));
                        error_log("🔍 Transfer Debug - From admin ID: $fromAdminId, To admin ID: $toAdminId, Student ID: $studentId");
                        
                        // CRITICAL: Actually remove student from old admin
                        if (isset($distribution[$fromAdminId])) {
                            error_log("🔍 Transfer Debug - Before removal from admin $fromAdminId: " . json_encode($distribution[$fromAdminId]));
                            
                            // Remove student by ID - use strict comparison
                            $newStudentsList = [];
                            foreach ($distribution[$fromAdminId] as $student) {
                                if ((string)$student['id'] !== (string)$studentId) {
                                    $newStudentsList[] = $student;
                                }
                            }
                            $distribution[$fromAdminId] = $newStudentsList;
                            
                            error_log("🔍 Transfer Debug - After removal from admin $fromAdminId: " . json_encode($distribution[$fromAdminId]));
                        }
                        
                        // CRITICAL: Actually add student to new admin
                        if (!isset($distribution[$toAdminId])) {
                            $distribution[$toAdminId] = [];
                        }
                        
                        // Find student data from clients
                        $clients = readJsonFile($clientsFile);
                        $student = null;
                        foreach ($clients as $client) {
                            if ((string)$client['id'] === (string)$studentId) {
                                $student = $client;
                                break;
                            }
                        }
                        
                        if ($student) {
                            // Add student to new admin
                            $distribution[$toAdminId][] = $student;
                            error_log("🔍 Transfer Debug - Added student to admin $toAdminId: " . json_encode($distribution[$toAdminId]));
                            
                            // CRITICAL: Save the updated distribution IMMEDIATELY
                            $saveResult = file_put_contents($distributionFile, json_encode($distribution, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                            
                            if ($saveResult === false) {
                                echo json_encode([
                                    'success' => false,
                                    'message' => 'فشل في حفظ توزيع الطلاب'
                                ], JSON_UNESCAPED_UNICODE);
                                break;
                            }
                            
                            error_log("🔍 Transfer Debug - Distribution saved successfully to: $distributionFile");
                            error_log("🔍 Transfer Debug - Final distribution: " . json_encode($distribution));
                            
                            echo json_encode([
                                'success' => true,
                                'message' => "تم نقل الطالب {$student['fullName']} بنجاح من $fromAdminName إلى $toAdminName"
                            ], JSON_UNESCAPED_UNICODE);
                        } else {
                            echo json_encode([
                                'success' => false,
                                'message' => 'الطالب غير موجود في قاعدة البيانات'
                            ], JSON_UNESCAPED_UNICODE);
                        }
                        
                    } catch (Exception $e) {
                        echo json_encode([
                            'success' => false,
                            'message' => 'خطأ في نقل الطالب: ' . $e->getMessage()
                        ], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            
            switch ($action) {
                case 'update_column_name':
                    try {
                        error_log("🔧 Column name update request received: " . json_encode($_REQUEST));
                        $oldName = $input['oldName'] ?? '';
                        $newName = $input['newName'] ?? '';
                        
                        error_log("🔧 Processing: oldName='$oldName', newName='$newName'");
                        
                        if (empty($oldName) || empty($newName)) {
                            echo json_encode(['success' => false, 'message' => 'اسم العمود القديم والجديد مطلوبان'], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        $clients = readJsonFile($clientsFile);
                        $students = readJsonFile($studentsFile);
                        
                        // Update column names in clients while preserving order
                        foreach ($clients as &$client) {
                            if (isset($client[$oldName])) {
                                // Create new array with updated column names in correct order
                                $newClient = [];
                                foreach ($client as $key => $value) {
                                    if ($key === $oldName) {
                                        $newClient[$newName] = $value;
                                    } else {
                                        $newClient[$key] = $value;
                                    }
                                }
                                $client = $newClient;
                            }
                        }
                        
                        // Update column names in students while preserving order
                        foreach ($students as &$student) {
                            if (isset($student[$oldName])) {
                                // Create new array with updated column names in correct order
                                $newStudent = [];
                                foreach ($student as $key => $value) {
                                    if ($key === $oldName) {
                                        $newStudent[$newName] = $value;
                                    } else {
                                        $newStudent[$key] = $value;
                                    }
                                }
                                $student = $newStudent;
                            }
                        }
                        
                        // Update column mapping file
                        $mappingFile = $dataDir . 'column_mapping.json';
                        $columnMapping = [];
                        if (file_exists($mappingFile)) {
                            $columnMapping = json_decode(file_get_contents($mappingFile), true) ?: [];
                        }
                        
                        // Find the original name for this column and update the mapping
                        foreach ($columnMapping as $originalName => $currentName) {
                            if ($currentName === $oldName) {
                                $columnMapping[$originalName] = $newName;
                                error_log("🔧 Updated column mapping: '$originalName' → '$newName' (was '$oldName')");
                                break;
                            }
                        }
                        
                        // If no mapping found, create one (assuming oldName is the original)
                        if (!isset($columnMapping[$oldName])) {
                            $columnMapping[$oldName] = $newName;
                            error_log("🔧 Created new column mapping: '$oldName' → '$newName'");
                        }
                        
                        writeJsonFile($mappingFile, $columnMapping);
                        writeJsonFile($clientsFile, $clients);
                        writeJsonFile($studentsFile, $students);
                        
                        error_log("🔧 Column name update completed successfully: '$oldName' → '$newName'");
                        
                        echo json_encode([
                            'success' => true,
                            'message' => "تم تحديث اسم العمود من '$oldName' إلى '$newName'"
                        ], JSON_UNESCAPED_UNICODE);
                        
                    } catch (Exception $e) {
                        error_log("❌ Error updating column name: " . $e->getMessage());
                        echo json_encode(['success' => false, 'message' => 'خطأ في تحديث اسم العمود: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'update_google_sheet_id':
                    try {
                        // Check if user is owner (stroogar@gmail.com)
                        $userEmail = $_SERVER['HTTP_X_USER_EMAIL'] ?? '';
                        if ($userEmail !== 'stroogar@gmail.com') {
                            echo json_encode([
                                'success' => false,
                                'message' => 'فقط المالك يمكنه تغيير Google Sheet ID'
                            ], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        $newSheetId = $input['newSheetId'] ?? '';
                        $confirmPassword = $input['confirmPassword'] ?? '';
                        
                        if (empty($newSheetId) || empty($confirmPassword)) {
                            echo json_encode([
                                'success' => false,
                                'message' => 'Google Sheet ID وكلمة المرور مطلوبان'
                            ], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        // Validate sheet ID format
                        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $newSheetId)) {
                            echo json_encode([
                                'success' => false,
                                'message' => 'Google Sheet ID غير صحيح'
                            ], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        // TODO: Add password verification here if needed
                        // For now, we'll just check if password is not empty
                        if (strlen($confirmPassword) < 3) {
                            echo json_encode([
                                'success' => false,
                                'message' => 'كلمة المرور غير صحيحة'
                            ], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        // Update config file
                        $configFile = './config/google_drive_config.php';
                        if (!file_exists($configFile)) {
                            echo json_encode([
                                'success' => false,
                                'message' => 'ملف الإعدادات غير موجود'
                            ], JSON_UNESCAPED_UNICODE);
                            break;
                        }
                        
                        // Read current config
                        $configContent = file_get_contents($configFile);
                        
                        // Replace sheet ID
                        $newConfigContent = preg_replace(
                            '/SPREADSHEET_ID\s*=\s*[\'"][^\'"]+[\'"]/',
                            "SPREADSHEET_ID = '$newSheetId'",
                            $configContent
                        );
                        
                        // Write updated config
                        if (file_put_contents($configFile, $newConfigContent)) {
                            echo json_encode([
                                'success' => true,
                                'message' => 'تم تحديث Google Sheet ID بنجاح'
                            ], JSON_UNESCAPED_UNICODE);
                            
                            // Log the change
                            addLogEntry('sheet_id_update', 'تحديث Google Sheet ID', "تم تحديث Google Sheet ID إلى: $newSheetId");
                        } else {
                            echo json_encode([
                                'success' => false,
                                'message' => 'فشل في كتابة ملف الإعدادات'
                            ], JSON_UNESCAPED_UNICODE);
                        }
                        
                    } catch (Exception $e) {
                        error_log("❌ Error updating Google Sheet ID: " . $e->getMessage());
                        echo json_encode([
                            'success' => false,
                            'message' => 'خطأ في تحديث Google Sheet ID: ' . $e->getMessage()
                        ], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'update_client':
                    $clients = readJsonFile($clientsFile);
                    $clientId = $input['id'];
                    
                    foreach ($clients as &$client) {
                        if ($client['id'] == $clientId) {
                            // Update standard fields
                            $client['fullName'] = $input['fullName'];
                            $client['email'] = $input['email'];
                            $client['phone'] = $input['phone'];
                            $client['scholarshipType'] = $input['scholarshipType'];
                            $client['university'] = $input['university'] ?? '';
                            $client['specialization'] = $input['specialization'] ?? '';
                            $client['notes'] = $input['notes'] ?? '';
                            
                            // Update custom fields
                            foreach ($input as $key => $value) {
                                if (!in_array($key, ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes'])) {
                                    $client[$key] = $value;
                                }
                            }
                            break;
                        }
                    }
                    
                    writeJsonFile($clientsFile, $clients);
                    
                    // Sync client changes to corresponding student
                    $students = readJsonFile($studentsFile);
                    foreach ($students as &$student) {
                        if (isset($student['clientId']) && $student['clientId'] == $clientId) {
                            $student['fullName'] = $input['fullName'];
                            $student['email'] = $input['email'];
                            $student['phone'] = $input['phone'];
                            $student['scholarshipType'] = $input['scholarshipType'];
                            $student['university'] = $input['university'] ?? '';
                            $student['specialization'] = $input['specialization'] ?? '';
                            $student['notes'] = $input['notes'] ?? '';
                            
                            // Sync custom fields
                            foreach ($input as $key => $value) {
                                if (!in_array($key, ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes'])) {
                                    $student[$key] = $value;
                                }
                            }
                            break;
                        }
                    }
                    
                    // Auto-sync new columns to all students
                    $visibilityFile = $dataDir . 'column_visibility.json';
                    $visibility = [];
                    if (file_exists($visibilityFile)) {
                        $visibility = json_decode(file_get_contents($visibilityFile), true) ?: [];
                    }
                    
                    // Get all columns from clients
                    $standardFields = ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes', 'applicationStatus', 'paymentStatus', 'paymentAmount'];
                    $allClientColumns = [];
                    
                    foreach ($clients as $client) {
                        foreach ($client as $key => $value) {
                            if (!in_array($key, $standardFields)) {
                                $allClientColumns[$key] = true;
                            }
                        }
                    }
                    
                    // Add new columns to all students
                    foreach ($students as &$student) {
                        foreach ($allClientColumns as $column => $exists) {
                            if (!isset($student[$column])) {
                                $student[$column] = '';
                            }
                        }
                    }
                    
                    // Update visibility for new columns (default: visible)
                    foreach ($allClientColumns as $column => $exists) {
                        if (!isset($visibility[$column])) {
                            $visibility[$column] = true;
                        }
                    }
                    
                    // Save updated data
                    writeJsonFile($studentsFile, $students);
                    file_put_contents($visibilityFile, json_encode($visibility, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    
                    echo json_encode(['success' => true, 'data' => $client], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'update_application_status':
                    $clients = readJsonFile($clientsFile);
                    $clientId = $input['id'];
                    $newStatus = $input['applicationStatus'];
                    
                    foreach ($clients as &$client) {
                        if ($client['id'] == $clientId) {
                            $client['applicationStatus'] = $newStatus;
                            break;
                        }
                    }
                    
                    writeJsonFile($clientsFile, $clients);
                    
                    // Sync application status to corresponding student
                    $students = readJsonFile($studentsFile);
                    foreach ($students as &$student) {
                        if (isset($student['clientId']) && $student['clientId'] == $clientId) {
                            $student['applicationStatus'] = $newStatus;
                            break;
                        }
                    }
                    writeJsonFile($studentsFile, $students);
                    
                    echo json_encode(['success' => true, 'data' => $client], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'update_payment':
                    $payments = readJsonFile($paymentsFile);
                    $paymentId = $input['id'];
                    $updatedPayment = null;
                    
                    foreach ($payments as &$payment) {
                        if ($payment['id'] == $paymentId) {
                            // Keep both keys for compatibility
                            $payment['status'] = $input['paymentStatus'];
                            $payment['paymentStatus'] = $input['paymentStatus'];
                            $payment['amount'] = $input['paymentAmount'];
                            $payment['fromNumber'] = $input['paymentFrom'] ?? '';
                            $payment['toNumber'] = $input['paymentTo'] ?? '';
                            $payment['screenshot'] = $input['paymentScreenshot'] ?? '';
                            $payment['notes'] = $input['paymentNotes'] ?? '';
                            $updatedPayment = $payment;
                            break;
                        }
                    }
                    
                    writeJsonFile($paymentsFile, $payments);
                    
                    // Update client payment status
                    if ($updatedPayment) {
                        $clients = readJsonFile($clientsFile);
                        foreach ($clients as &$client) {
                            if ($client['id'] == $updatedPayment['clientId']) {
                                $client['paymentStatus'] = $input['paymentStatus'];
                                $client['paymentAmount'] = $input['paymentAmount'];
                                break;
                            }
                        }
                        writeJsonFile($clientsFile, $clients);
                        
                        // Sync payment status to corresponding student
                        $students = readJsonFile($studentsFile);
                        foreach ($students as &$student) {
                            if (isset($student['clientId']) && $student['clientId'] == $updatedPayment['clientId']) {
                                $student['paymentStatus'] = $input['paymentStatus'];
                                $student['paymentAmount'] = $input['paymentAmount'];
                                break;
                            }
                        }
                        writeJsonFile($studentsFile, $students);
                    }
                    
                    echo json_encode(['success' => true, 'data' => $updatedPayment], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'update_admin':
                    $admins = readJsonFile($adminsFile);
                    $adminId = $input['id'];
                    
                    foreach ($admins as &$admin) {
                        if ($admin['id'] == $adminId) {
                            $admin['fullName'] = $input['fullName'];
                            $admin['email'] = $input['email'];
                            $admin['role'] = $input['role'];
                            
                            // Only update password if provided
                            if (!empty($input['password'])) {
                                $admin['password'] = $input['password'];
                            }
                            break;
                        }
                    }
                    
                    writeJsonFile($adminsFile, $admins);
                    echo json_encode(['success' => true, 'data' => $admin], JSON_UNESCAPED_UNICODE);
                    break;

                case 'update_student':
                    $students = readJsonFile($studentsFile);
                    $studentId = $input['id'];
                    
                    foreach ($students as &$student) {
                        if ($student['id'] == $studentId) {
                            $student['fullName'] = $input['fullName'];
                            $student['email'] = $input['email'];
                            $student['phone'] = $input['phone'];
                            $student['scholarshipType'] = $input['scholarshipType'];
                            $student['university'] = $input['university'] ?? '';
                            $student['specialization'] = $input['specialization'] ?? '';
                            $student['notes'] = $input['notes'] ?? '';
                            $student['applicationStatus'] = $input['applicationStatus'] ?? 'لم يتم التقديم';
                            
                            // Update custom fields
                            foreach ($input as $key => $value) {
                                if (!in_array($key, ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes', 'applicationStatus'])) {
                                    $student[$key] = $value;
                                }
                            }
                        break;
                        }
                    }
                    
                    writeJsonFile($studentsFile, $students);
                        
                    // Sync student changes to corresponding client
                    if (isset($student['clientId'])) {
                        $clients = readJsonFile($clientsFile);
                        foreach ($clients as &$client) {
                            if ($client['id'] == $student['clientId']) {
                                $client['fullName'] = $input['fullName'];
                                $client['email'] = $input['email'];
                                $client['phone'] = $input['phone'];
                                $client['scholarshipType'] = $input['scholarshipType'];
                                $client['university'] = $input['university'] ?? '';
                                $client['specialization'] = $input['specialization'] ?? '';
                                $client['notes'] = $input['notes'] ?? '';
                                $client['applicationStatus'] = $input['applicationStatus'] ?? 'لم يتم التقديم';
                                
                                // Sync custom fields
                                foreach ($input as $key => $value) {
                                    if (!in_array($key, ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes', 'applicationStatus'])) {
                                        $client[$key] = $value;
                                    }
                                }
                                break;
                            }
                        }
                        writeJsonFile($clientsFile, $clients);
                    }
                    
                    echo json_encode(['success' => true, 'data' => $student], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'update_student_sop':
                    $students = readJsonFile($studentsFile);
                    $studentId = $input['id'];
                    $sop = $input['sop'];
                    
                    foreach ($students as &$student) {
                        if ($student['id'] == $studentId) {
                            $student['sop'] = $sop;
                            $student['tasks']['sop']['status'] = 'completed';
                            $student['tasks']['sop']['completed'] = date('Y-m-d');
                            break;
                        }
                    }
                    
                    writeJsonFile($studentsFile, $students);
                    echo json_encode(['success' => true, 'message' => 'تم حفظ بيان الغرض بنجاح'], JSON_UNESCAPED_UNICODE);
                    break;
                    
                case 'update_student_lor':
                    $students = readJsonFile($studentsFile);
                    $studentId = $input['id'];
                    $lor = $input['lor'];
                    
                    foreach ($students as &$student) {
                        if ($student['id'] == $studentId) {
                            $student['lor'] = $lor;
                            $student['tasks']['lor']['status'] = 'completed';
                            $student['tasks']['lor']['completed'] = date('Y-m-d');
                            break;
                        }
                    }
                    
                    writeJsonFile($studentsFile, $students);
                    echo json_encode(['success' => true, 'message' => 'تم حفظ خطاب التوصية بنجاح'], JSON_UNESCAPED_UNICODE);
                    break;


                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'DELETE':
            $id = $_GET['id'] ?? null;
            
            switch ($action) {
                case 'delete_admin':
                    if ($id) {
                        $admins = readJsonFile($adminsFile);
                        $admins = array_values(array_filter($admins, function($admin) use ($id) {
                            // do not delete main admin
                            return $admin['id'] != $id && !($admin['id'] == $id && ($admin['isMainAdmin'] ?? false));
                        }));
                        writeJsonFile($adminsFile, $admins);
                        echo json_encode(['success' => true, 'message' => 'تم حذف المشرف'], JSON_UNESCAPED_UNICODE);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'ID required'], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                case 'delete_client':
                    if ($id) {
                        $clients = readJsonFile($clientsFile);
                        $clients = array_filter($clients, function($client) use ($id) {
                            return $client['id'] != $id;
                        });
                        writeJsonFile($clientsFile, array_values($clients));
                        
                        // Delete corresponding payment
                        $payments = readJsonFile($paymentsFile);
                        $payments = array_filter($payments, function($payment) use ($id) {
                            return $payment['clientId'] != $id;
                        });
                        writeJsonFile($paymentsFile, array_values($payments));
                        
                        // Delete corresponding student
                        $students = readJsonFile($studentsFile);
                        $students = array_filter($students, function($student) use ($id) {
                            return !(isset($student['clientId']) && $student['clientId'] == $id);
                        });
                        writeJsonFile($studentsFile, array_values($students));
                        
                        echo json_encode(['success' => true, 'message' => 'تم حذف العميل والطالب المرتبط بنجاح'], JSON_UNESCAPED_UNICODE);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'ID required'], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'delete_student':
                    if ($id) {
                        $students = readJsonFile($studentsFile);
                        $students = array_filter($students, function($student) use ($id) {
                            return $student['id'] != $id;
                        });
                        writeJsonFile($studentsFile, array_values($students));
                        
                        // Delete student folder - improved cleanup
                        $studentFolders = glob($filesDir . 'students/*');
                        foreach ($studentFolders as $folder) {
                            $folderName = basename($folder);
                            // Check if folder starts with student ID
                            if (preg_match('/^' . $id . '_/', $folderName)) {
                                // Remove folder and contents recursively
                                if (is_dir($folder)) {
                                    $files = glob($folder . '/*');
                                    foreach ($files as $file) {
                                        if (is_file($file)) {
                                            unlink($file);
                                        } elseif (is_dir($file)) {
                                            removeDirectory($file);
                                        }
                                    }
                                    rmdir($folder);
                                    error_log("🗑️ Deleted student folder: $folderName for student ID: $id");
                                }
                                break;
                            }
                        }
                        
                        // Delete corresponding client if it exists
                        $deletedStudent = null;
                        foreach ($students as $s) {
                            if ($s['id'] == $id) {
                                $deletedStudent = $s;
                                break;
                            }
                        }
                        
                        if ($deletedStudent && isset($deletedStudent['clientId'])) {
                            $clients = readJsonFile($clientsFile);
                            $clients = array_filter($clients, function($client) use ($deletedStudent) {
                                return $client['id'] != $deletedStudent['clientId'];
                            });
                            writeJsonFile($clientsFile, array_values($clients));
                            
                            // Also delete corresponding payment
                            $payments = readJsonFile($paymentsFile);
                            $payments = array_filter($payments, function($payment) use ($deletedStudent) {
                                return $payment['clientId'] != $deletedStudent['clientId'];
                            });
                            writeJsonFile($paymentsFile, array_values($payments));
                        }
                        
                        echo json_encode(['success' => true, 'message' => 'تم حذف الطالب والعميل المرتبط بنجاح'], JSON_UNESCAPED_UNICODE);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'ID required'], JSON_UNESCAPED_UNICODE);
                    }
                    break;
                    
                case 'clear_all_data':
                    writeJsonFile($clientsFile, []);
                    writeJsonFile($paymentsFile, []);
                    writeJsonFile($studentsFile, []);
                    
                    // Clear student folders
                    $studentFolders = glob($filesDir . 'students/*');
                    foreach ($studentFolders as $folder) {
                        if (is_dir($folder)) {
                            array_map('unlink', glob("$folder/*.*"));
                            rmdir($folder);
                        }
                    }
                    
                    echo json_encode(['success' => true, 'message' => 'تم مسح جميع البيانات بنجاح'], JSON_UNESCAPED_UNICODE);
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// Helper function to check if user can access a student
function canAccessStudent($userEmail, $studentId) {
    global $dataDir;
    
    // Owner has access to all students
    if ($userEmail === 'stroogar@gmail.com') {
        return true;
    }
    
    // Load distribution data
    $distributionFile = $dataDir . 'student_distribution.json';
    if (!file_exists($distributionFile)) {
        return false;
    }
    
    $distribution = json_decode(file_get_contents($distributionFile), true) ?: [];
    
    // Check if student is assigned to this admin
    foreach ($distribution as $adminId => $students) {
        if ($adminId === $userEmail) {
            foreach ($students as $student) {
                if ((string)$student['id'] === (string)$studentId) {
                    return true;
                }
            }
        }
    }
    
    // Check if admin has accepted help request for this student
    $helpRequestsFile = $dataDir . 'help_requests.json';
    if (file_exists($helpRequestsFile)) {
        $helpRequests = json_decode(file_get_contents($helpRequestsFile), true) ?: [];
        foreach ($helpRequests as $request) {
            if ($request['assignedAdminId'] === $userEmail && 
                (string)$request['studentId'] === (string)$studentId && 
                $request['status'] === 'accepted') {
                return true;
            }
        }
    }
    
    return false;
}
?> 