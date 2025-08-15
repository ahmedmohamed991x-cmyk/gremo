<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class FolderManager {
    private $basePath;
    private $studentsPath;

    public function __construct() {
        $this->basePath = dirname(__DIR__);
        $this->studentsPath = $this->basePath . '/files/students/';
        $this->ensureDirectoriesExist();
    }

    private function ensureDirectoriesExist() {
        $directories = [
            $this->basePath . '/files',
            $this->studentsPath
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    public function handleRequest() {
        $action = $_GET['action'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'];

        try {
            switch ($action) {
                case 'create':
                    if ($method !== 'POST') throw new Exception('Method not allowed');
                    return $this->createStudentFolder();
                case 'delete':
                    if ($method !== 'DELETE') throw new Exception('Method not allowed');
                    $studentId = $_GET['studentId'] ?? null;
                    if (!$studentId) throw new Exception('Student ID is required');
                    return $this->deleteStudentFolder($studentId);
                case 'list':
                    if ($method !== 'GET') throw new Exception('Method not allowed');
                    return $this->listStudentFolders();
                case 'getStructure':
                    if ($method !== 'GET') throw new Exception('Method not allowed');
                    $studentId = $_GET['studentId'] ?? null;
                    if (!$studentId) throw new Exception('Student ID is required');
                    return $this->getFolderStructure($studentId);
                case 'uploadFile':
                    if ($method !== 'POST') throw new Exception('Method not allowed');
                    return $this->uploadFile();
                case 'deleteFile':
                    if ($method !== 'DELETE') throw new Exception('Method not allowed');
                    return $this->deleteFile();
                case 'getFileInfo':
                    if ($method !== 'GET') throw new Exception('Method not allowed');
                    return $this->getFileInfo();
                default:
                    throw new Exception('Invalid action');
            }
        } catch (Exception $e) {
            http_response_code(400);
            return ['error' => $e->getMessage()];
        }
    }

    private function createStudentFolder() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['studentId']) || !isset($input['studentName'])) {
                throw new Exception('Student ID and name are required');
            }

            $studentId = $input['studentId'];
            $studentName = $input['studentName'];
            $folderName = $input['folderName'] ?? "Student_{$studentId}_" . str_replace(' ', '_', $studentName);

            // Clean folder name
            $folderName = $this->cleanFolderName($folderName);
            $folderPath = $this->studentsPath . $folderName;

            if (is_dir($folderPath)) {
                throw new Exception('Student folder already exists');
            }

            // Create main folder
            if (!mkdir($folderPath, 0755, true)) {
                throw new Exception('Failed to create student folder');
            }

            // Create subdirectories
            $subdirs = [
                'documents' => 'Student uploaded documents',
                'statements' => 'Statement of Purpose files',
                'recommendations' => 'Letter of Recommendation files',
                'applications' => 'Application forms and files',
                'certificates' => 'Academic certificates and transcripts',
                'photos' => 'Student photos and identification',
                'other' => 'Other supporting documents'
            ];

            foreach ($subdirs as $subdir => $description) {
                $subdirPath = $folderPath . '/' . $subdir;
                if (!mkdir($subdirPath, 0755, true)) {
                    throw new Exception("Failed to create subdirectory: {$subdir}");
                }
            }

            // Create README file
            $readmeContent = $this->createReadmeContent($studentId, $studentName, $subdirs);
            $readmePath = $folderPath . '/README.txt';
            
            if (file_put_contents($readmePath, $readmeContent) === false) {
                throw new Exception('Failed to create README file');
            }

            // Create folder structure log
            $this->logFolderCreation($studentId, $folderPath, $subdirs);

            return [
                'success' => true,
                'message' => 'Student folder created successfully',
                'data' => [
                    'folderPath' => $folderPath,
                    'folderName' => $folderName,
                    'subdirectories' => array_keys($subdirs),
                    'readmePath' => $readmePath
                ]
            ];

        } catch (Exception $e) {
            error_log("Error creating student folder: " . $e->getMessage());
            throw new Exception('Failed to create student folder: ' . $e->getMessage());
        }
    }

    private function deleteStudentFolder($studentId) {
        try {
            $folders = glob($this->studentsPath . "Student_{$studentId}_*");
            
            if (empty($folders)) {
                throw new Exception('Student folder not found');
            }

            $deletedFolders = [];
            foreach ($folders as $folder) {
                if (is_dir($folder)) {
                    if ($this->deleteDirectory($folder)) {
                        $deletedFolders[] = $folder;
                    }
                }
            }

            if (empty($deletedFolders)) {
                throw new Exception('Failed to delete student folders');
            }

            return [
                'success' => true,
                'message' => 'Student folder(s) deleted successfully',
                'data' => [
                    'deletedFolders' => $deletedFolders,
                    'studentId' => $studentId
                ]
            ];

        } catch (Exception $e) {
            error_log("Error deleting student folder: " . $e->getMessage());
            throw new Exception('Failed to delete student folder: ' . $e->getMessage());
        }
    }

    private function listStudentFolders() {
        try {
            $folders = glob($this->studentsPath . 'Student_*', GLOB_ONLYDIR);
            $folderList = [];

            foreach ($folders as $folder) {
                $folderInfo = $this->getFolderInfo($folder);
                if ($folderInfo) {
                    $folderList[] = $folderInfo;
                }
            }

            // Sort by creation date (newest first)
            usort($folderList, function($a, $b) {
                return strtotime($b['createdAt']) - strtotime($a['createdAt']);
            });

            return [
                'success' => true,
                'data' => [
                    'totalFolders' => count($folderList),
                    'folders' => $folderList
                ]
            ];

        } catch (Exception $e) {
            error_log("Error listing student folders: " . $e->getMessage());
            throw new Exception('Failed to list student folders: ' . $e->getMessage());
        }
    }

    private function getFolderStructure($studentId) {
        try {
            $folders = glob($this->studentsPath . "Student_{$studentId}_*");
            
            if (empty($folders)) {
                throw new Exception('Student folder not found');
            }

            $folderPath = $folders[0];
            $structure = $this->scanDirectory($folderPath);

            return [
                'success' => true,
                'data' => [
                    'studentId' => $studentId,
                    'folderPath' => $folderPath,
                    'structure' => $structure
                ]
            ];

        } catch (Exception $e) {
            error_log("Error getting folder structure: " . $e->getMessage());
            throw new Exception('Failed to get folder structure: ' . $e->getMessage());
        }
    }

    private function uploadFile() {
        try {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No file uploaded or upload error occurred');
            }

            $file = $_FILES['file'];
            $studentId = $_POST['studentId'] ?? null;
            $category = $_POST['category'] ?? 'other';
            $description = $_POST['description'] ?? '';

            if (!$studentId) {
                throw new Exception('Student ID is required');
            }

            // Validate file
            $this->validateUploadedFile($file);

            // Get student folder
            $studentFolder = $this->getStudentFolderPath($studentId);
            if (!$studentFolder) {
                throw new Exception('Student folder not found');
            }

            // Determine target directory
            $targetDir = $studentFolder . '/' . $category;
            if (!is_dir($targetDir)) {
                throw new Exception('Invalid category specified');
            }

            // Generate unique filename
            $fileName = $this->generateUniqueFileName($file['name'], $targetDir);
            $filePath = $targetDir . '/' . $fileName;

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new Exception('Failed to save uploaded file');
            }

            // Create file metadata
            $fileInfo = [
                'name' => $fileName,
                'originalName' => $file['name'],
                'size' => $file['size'],
                'type' => $file['type'],
                'category' => $category,
                'description' => $description,
                'uploadedAt' => date('Y-m-d H:i:s'),
                'path' => $filePath
            ];

            // Log file upload
            $this->logFileUpload($studentId, $fileInfo);

            return [
                'success' => true,
                'message' => 'File uploaded successfully',
                'data' => $fileInfo
            ];

        } catch (Exception $e) {
            error_log("Error uploading file: " . $e->getMessage());
            throw new Exception('Failed to upload file: ' . $e->getMessage());
        }
    }

    private function deleteFile() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['filePath'])) {
                throw new Exception('File path is required');
            }

            $filePath = $input['filePath'];
            
            // Security check: ensure file is within students directory
            if (!$this->isPathSafe($filePath)) {
                throw new Exception('Invalid file path');
            }

            if (!file_exists($filePath)) {
                throw new Exception('File not found');
            }

            if (!unlink($filePath)) {
                throw new Exception('Failed to delete file');
            }

            return [
                'success' => true,
                'message' => 'File deleted successfully',
                'data' => ['deletedPath' => $filePath]
            ];

        } catch (Exception $e) {
            error_log("Error deleting file: " . $e->getMessage());
            throw new Exception('Failed to delete file: ' . $e->getMessage());
        }
    }

    private function getFileInfo() {
        try {
            $filePath = $_GET['filePath'] ?? null;
            
            if (!$filePath) {
                throw new Exception('File path is required');
            }

            // Security check: ensure file is within students directory
            if (!$this->isPathSafe($filePath)) {
                throw new Exception('Invalid file path');
            }

            if (!file_exists($filePath)) {
                throw new Exception('File not found');
            }

            $fileInfo = [
                'name' => basename($filePath),
                'path' => $filePath,
                'size' => filesize($filePath),
                'type' => mime_content_type($filePath),
                'modifiedAt' => date('Y-m-d H:i:s', filemtime($filePath)),
                'isReadable' => is_readable($filePath),
                'isWritable' => is_writable($filePath)
            ];

            return [
                'success' => true,
                'data' => $fileInfo
            ];

        } catch (Exception $e) {
            error_log("Error getting file info: " . $e->getMessage());
            throw new Exception('Failed to get file info: ' . $e->getMessage());
        }
    }

    // Helper methods
    private function cleanFolderName($name) {
        return preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $name);
    }

    private function createReadmeContent($studentId, $studentName, $subdirs) {
        $content = "Student Application Folder\n";
        $content .= "========================\n\n";
        $content .= "Student ID: {$studentId}\n";
        $content .= "Student Name: {$studentName}\n";
        $content .= "Created: " . date('Y-m-d H:i:s') . "\n\n";
        $content .= "Directory Structure:\n";
        $content .= "==================\n\n";

        foreach ($subdirs as $dir => $description) {
            $content .= "- {$dir}/: {$description}\n";
        }

        $content .= "\nFile Naming Convention:\n";
        $content .= "======================\n";
        $content .= "Files should be named descriptively with the following format:\n";
        $content .= "DocumentType_Description_Date.ext\n\n";
        $content .= "Examples:\n";
        $content .= "- HighSchool_Transcript_2024-01-15.pdf\n";
        $content .= "- English_Test_Results_2024-01-10.pdf\n";
        $content .= "- Statement_Of_Purpose_2024-01-20.docx\n\n";
        $content .= "Notes:\n";
        $content .= "======\n";
        $content .= "- Keep file names short but descriptive\n";
        $content .= "- Use underscores instead of spaces\n";
        $content .= "- Include dates in YYYY-MM-DD format\n";
        $content .= "- Avoid special characters except underscores and hyphens\n";

        return $content;
    }

    private function logFolderCreation($studentId, $folderPath, $subdirs) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => 'folder_created',
            'studentId' => $studentId,
            'folderPath' => $folderPath,
            'subdirectories' => array_keys($subdirs)
        ];

        $logFile = $this->basePath . '/logs/folder_operations.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function deleteDirectory($dir) {
        if (!is_dir($dir)) return false;
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }

    private function getFolderInfo($folderPath) {
        try {
            $folderName = basename($folderPath);
            
            // Extract student ID from folder name
            if (preg_match('/Student_(\d+)_(.+)/', $folderName, $matches)) {
                $studentId = $matches[1];
                $studentName = str_replace('_', ' ', $matches[2]);
            } else {
                return null;
            }

            $stats = $this->calculateFolderStats($folderPath);
            
            return [
                'folderName' => $folderName,
                'folderPath' => $folderPath,
                'studentId' => $studentId,
                'studentName' => $studentName,
                'createdAt' => date('Y-m-d H:i:s', filectime($folderPath)),
                'stats' => $stats
            ];
        } catch (Exception $e) {
            error_log("Error getting folder info: " . $e->getMessage());
            return null;
        }
    }

    private function calculateFolderStats($folderPath) {
        $stats = [
            'totalFiles' => 0,
            'totalSize' => 0,
            'categories' => []
        ];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folderPath, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $stats['totalFiles']++;
                    $stats['totalSize'] += $file->getSize();
                    
                    $relativePath = str_replace($folderPath . '/', '', $file->getPath());
                    $category = explode('/', $relativePath)[0];
                    
                    if (!isset($stats['categories'][$category])) {
                        $stats['categories'][$category] = [
                            'count' => 0,
                            'size' => 0
                        ];
                    }
                    
                    $stats['categories'][$category]['count']++;
                    $stats['categories'][$category]['size'] += $file->getSize();
                }
            }
        } catch (Exception $e) {
            error_log("Error calculating folder stats: " . $e->getMessage());
        }

        return $stats;
    }

    private function scanDirectory($path, $level = 0) {
        $structure = [];
        
        try {
            $items = scandir($path);
            
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                
                $itemPath = $path . '/' . $item;
                $itemInfo = [
                    'name' => $item,
                    'type' => is_dir($itemPath) ? 'directory' : 'file',
                    'path' => $itemPath
                ];
                
                if (is_file($itemPath)) {
                    $itemInfo['size'] = filesize($itemPath);
                    $itemInfo['modifiedAt'] = date('Y-m-d H:i:s', filemtime($itemPath));
                } elseif (is_dir($itemPath) && $level < 3) { // Limit recursion depth
                    $itemInfo['children'] = $this->scanDirectory($itemPath, $level + 1);
                }
                
                $structure[] = $itemInfo;
            }
        } catch (Exception $e) {
            error_log("Error scanning directory: " . $e->getMessage());
        }

        return $structure;
    }

    private function validateUploadedFile($file) {
        $maxSize = 50 * 1024 * 1024; // 50MB
        $allowedTypes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/jpeg',
            'image/png',
            'image/gif',
            'text/plain'
        ];

        if ($file['size'] > $maxSize) {
            throw new Exception('File size exceeds maximum limit of 50MB');
        }

        if (!in_array($file['type'], $allowedTypes)) {
            throw new Exception('File type not allowed');
        }
    }

    private function getStudentFolderPath($studentId) {
        $folders = glob($this->studentsPath . "Student_{$studentId}_*");
        return empty($folders) ? null : $folders[0];
    }

    private function generateUniqueFileName($originalName, $targetDir) {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        
        $counter = 1;
        $fileName = $baseName . '.' . $extension;
        
        while (file_exists($targetDir . '/' . $fileName)) {
            $fileName = $baseName . '_' . $counter . '.' . $extension;
            $counter++;
        }
        
        return $fileName;
    }

    private function logFileUpload($studentId, $fileInfo) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => 'file_uploaded',
            'studentId' => $studentId,
            'fileInfo' => $fileInfo
        ];

        $logFile = $this->basePath . '/logs/file_operations.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function isPathSafe($filePath) {
        $realPath = realpath($filePath);
        $studentsPath = realpath($this->studentsPath);
        
        return $realPath && $studentsPath && strpos($realPath, $studentsPath) === 0;
    }
}

// Handle the request
try {
    $folderManager = new FolderManager();
    $result = $folderManager->handleRequest();
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
