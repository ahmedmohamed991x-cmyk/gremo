<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require __DIR__ . '/../vendor/autoload.php';

use Google_Client;
use Google_Service_Drive;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Function to sanitize filename while preserving Arabic characters
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

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['url']) || !isset($input['filename']) || !isset($input['studentId'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters'], JSON_UNESCAPED_UNICODE);
    exit;
}

$url = $input['url'];
$filename = $input['filename'];
$studentId = $input['studentId'];

try {
    // Extract file ID from Google Drive URL
    $fileId = '';
    if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        $fileId = $matches[1];
    } elseif (preg_match('/id=([a-zA-Z0-9_-]+)/', $url, $matches)) {
        $fileId = $matches[1];
    }
    
    if (!$fileId) {
        echo json_encode(['success' => false, 'message' => 'Invalid Google Drive URL'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Initialize Google Drive client using your working code
    $client = new Google_Client();
    $client->setAuthConfig(__DIR__ . '/../config/service-account.json');
    $client->addScope(Google_Service_Drive::DRIVE_READONLY);
    
    $service = new Google_Service_Drive($client);
    
    // Get file metadata
    $file = $service->files->get($fileId);
    $fileName = $file->getName();
    $mimeType = $file->getMimeType();
    
    // Determine file extension
    $extension = '';
    if (strpos($mimeType, 'pdf') !== false) $extension = '.pdf';
    elseif (strpos($mimeType, 'image') !== false) $extension = '.jpg';
    elseif (strpos($mimeType, 'document') !== false) $extension = '.docx';
    else $extension = '.pdf'; // Default
    
    // Get student info for proper naming
    $studentsFile = "../data/students.json";
    $students = [];
    if (file_exists($studentsFile)) {
        $students = json_decode(file_get_contents($studentsFile), true) ?: [];
    }
    
    $studentName = 'Unknown';
    foreach ($students as $student) {
        if ($student['id'] == $studentId) {
            $studentName = $student['fullName'];
            break;
        }
    }
    
    // Create student folder with clean naming: STUDENT_ID_STUDENT_NAME
    $cleanStudentName = sanitizeFileName($studentName);
    $studentFolder = "../files/students/{$studentId}_{$cleanStudentName}";
    if (!file_exists($studentFolder)) {
        mkdir($studentFolder, 0777, true);
    }
    
    // Download file content using your working method
    $response = $service->files->get($fileId, array('alt' => 'media'));
    $content = $response->getBody()->getContents();
    
    // Keep original filename - just organize in proper folder
    $finalFilename = $filename . $extension;
    $filePath = $studentFolder . '/' . $finalFilename;
    
    if (file_put_contents($filePath, $content)) {
        // Update student's files array in the database
        $studentsFile = "../data/students.json";
        if (file_exists($studentsFile)) {
            $students = json_decode(file_get_contents($studentsFile), true) ?: [];
            
            foreach ($students as &$student) {
                if ($student['id'] == $studentId) {
                    if (!isset($student['files'])) $student['files'] = [];
                    
                                        // Generate unique file ID
                    $uniqueFileId = uniqid();
                    $student['files'][] = [
                        'id' => $uniqueFileId,
                        'name' => $finalFilename,
                        'path' => 'files/students/' . $studentId . '_' . $cleanStudentName . '/' . $finalFilename,
                        'size' => strlen($content),
                        'type' => $mimeType,
                        'uploadedAt' => date('Y-m-d H:i:s'),
                        'source' => 'google_drive'
                    ];
                    
                    // Write back to file
                    file_put_contents($studentsFile, json_encode($students, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    break;
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'File downloaded successfully',
            'filePath' => $filePath,
            'filename' => $finalFilename,
            'fileId' => $uniqueFileId
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save file'], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
