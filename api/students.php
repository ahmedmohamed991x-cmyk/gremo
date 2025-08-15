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

require_once '../config/database.php';
require_once '../vendor/autoload.php';

class StudentAPI {
    private $db;
    private $pdo;

    public function __construct() {
        $this->db = new Database();
        $this->pdo = $this->db->getConnection();
        $this->createTableIfNotExists();
    }

    private function createTableIfNotExists() {
        $sql = "CREATE TABLE IF NOT EXISTS students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            phone VARCHAR(50) NOT NULL,
            birth_date DATE NOT NULL,
            target_university VARCHAR(255) NOT NULL,
            major VARCHAR(255) NOT NULL,
            application_file TEXT,
            statement_of_purpose TEXT,
            letter_of_recommendation TEXT,
            status ENUM('pending', 'completed', 'issue') DEFAULT 'pending',
            progress INT DEFAULT 0,
            notes TEXT,
            documents JSON,
            tasks JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            skipped_at TIMESTAMP NULL
        )";
        
        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            error_log("Error creating students table: " . $e->getMessage());
        }
    }

    public function handleRequest() {
        $action = $_GET['action'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'];

        try {
            switch ($action) {
                case 'getAll':
                    return $this->getAllStudents();
                case 'getById':
                    $id = $_GET['id'] ?? null;
                    if (!$id) throw new Exception('Student ID is required');
                    return $this->getStudentById($id);
                case 'create':
                    if ($method !== 'POST') throw new Exception('Method not allowed');
                    return $this->createStudent();
                case 'update':
                    if ($method !== 'PUT') throw new Exception('Method not allowed');
                    return $this->updateStudent();
                case 'delete':
                    if ($method !== 'DELETE') throw new Exception('Method not allowed');
                    $id = $_GET['id'] ?? null;
                    if (!$id) throw new Exception('Student ID is required');
                    return $this->deleteStudent($id);
                case 'updateStatus':
                    if ($method !== 'PUT') throw new Exception('Method not allowed');
                    return $this->updateStudentStatus();
                default:
                    throw new Exception('Invalid action');
            }
        } catch (Exception $e) {
            http_response_code(400);
            return ['error' => $e->getMessage()];
        }
    }

    private function getAllStudents() {
        try {
            $sql = "SELECT * FROM students ORDER BY created_at DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode JSON fields
            foreach ($students as &$student) {
                if (isset($student['documents'])) {
                    $student['documents'] = json_decode($student['documents'], true) ?: [];
                }
                if (isset($student['tasks'])) {
                    $student['tasks'] = json_decode($student['tasks'], true) ?: [];
                }
            }
            
            return ['success' => true, 'data' => $students];
        } catch (PDOException $e) {
            error_log("Error getting students: " . $e->getMessage());
            throw new Exception('Failed to retrieve students');
        }
    }

    private function getStudentById($id) {
        try {
            $sql = "SELECT * FROM students WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$id]);
            
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$student) {
                throw new Exception('Student not found');
            }
            
            // Decode JSON fields
            if (isset($student['documents'])) {
                $student['documents'] = json_decode($student['documents'], true) ?: [];
            }
            if (isset($student['tasks'])) {
                $student['tasks'] = json_decode($student['tasks'], true) ?: [];
            }
            
            return ['success' => true, 'data' => $student];
        } catch (PDOException $e) {
            error_log("Error getting student by ID: " . $e->getMessage());
            throw new Exception('Failed to retrieve student');
        }
    }

    private function createStudent() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid input data');
            }
            
            // Validate required fields
            $requiredFields = ['fullName', 'email', 'phone', 'birthDate', 'targetUniversity', 'major'];
            foreach ($requiredFields as $field) {
                if (empty($input[$field])) {
                    throw new Exception("Field '$field' is required");
                }
            }
            
            // Check if email already exists
            $stmt = $this->pdo->prepare("SELECT id FROM students WHERE email = ?");
            $stmt->execute([$input['email']]);
            if ($stmt->fetch()) {
                throw new Exception('Email already exists');
            }
            
            $sql = "INSERT INTO students (
                full_name, email, phone, birth_date, target_university, major,
                application_file, statement_of_purpose, letter_of_recommendation,
                notes, documents, tasks, progress
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $input['fullName'],
                $input['email'],
                $input['phone'],
                $input['birthDate'],
                $input['targetUniversity'],
                $input['major'],
                $input['applicationFile'] ?? null,
                $input['statementOfPurpose'] ?? null,
                $input['letterOfRecommendation'] ?? null,
                $input['notes'] ?? null,
                json_encode($input['documents'] ?? []),
                json_encode($input['tasks'] ?? []),
                $input['progress'] ?? 0
            ]);
            
            $studentId = $this->pdo->lastInsertId();
            
            // Create student folder
            $this->createStudentFolder($studentId, $input['fullName']);
            
            // Download application file if provided
            if (!empty($input['applicationFile'])) {
                $this->downloadApplicationFile($input['applicationFile'], $studentId, $input['fullName']);
            }
            
            return [
                'success' => true,
                'message' => 'Student created successfully',
                'data' => ['id' => $studentId]
            ];
        } catch (PDOException $e) {
            error_log("Error creating student: " . $e->getMessage());
            throw new Exception('Failed to create student');
        }
    }

    private function updateStudent() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['id'])) {
                throw new Exception('Student ID is required');
            }
            
            $sql = "UPDATE students SET 
                full_name = ?, email = ?, phone = ?, birth_date = ?, 
                target_university = ?, major = ?, application_file = ?,
                statement_of_purpose = ?, letter_of_recommendation = ?,
                notes = ?, documents = ?, tasks = ?, progress = ?,
                updated_at = CURRENT_TIMESTAMP
                WHERE id = ?";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $input['fullName'],
                $input['email'],
                $input['phone'],
                $input['birthDate'],
                $input['targetUniversity'],
                $input['major'],
                $input['applicationFile'] ?? null,
                $input['statementOfPurpose'] ?? null,
                $input['letterOfRecommendation'] ?? null,
                $input['notes'] ?? null,
                json_encode($input['documents'] ?? []),
                json_encode($input['tasks'] ?? []),
                $input['progress'] ?? 0,
                $input['id']
            ]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Student not found or no changes made');
            }
            
            return [
                'success' => true,
                'message' => 'Student updated successfully'
            ];
        } catch (PDOException $e) {
            error_log("Error updating student: " . $e->getMessage());
            throw new Exception('Failed to update student');
        }
    }

    private function deleteStudent($id) {
        try {
            $sql = "DELETE FROM students WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Student not found');
            }
            
            // Delete student folder
            $this->deleteStudentFolder($id);
            
            return [
                'success' => true,
                'message' => 'Student deleted successfully'
            ];
        } catch (PDOException $e) {
            error_log("Error deleting student: " . $e->getMessage());
            throw new Exception('Failed to delete student');
        }
    }

    private function updateStudentStatus() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['id']) || !isset($input['status'])) {
                throw new Exception('Student ID and status are required');
            }
            
            $sql = "UPDATE students SET 
                status = ?, 
                progress = ?,
                updated_at = CURRENT_TIMESTAMP";
            
            $params = [$input['status'], $input['progress'] ?? 0];
            
            // Add timestamp based on status
            if ($input['status'] === 'completed') {
                $sql .= ", completed_at = CURRENT_TIMESTAMP";
            } elseif ($input['status'] === 'issue') {
                $sql .= ", skipped_at = CURRENT_TIMESTAMP";
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $input['id'];
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Student not found or no changes made');
            }
            
            return [
                'success' => true,
                'message' => 'Student status updated successfully'
            ];
        } catch (PDOException $e) {
            error_log("Error updating student status: " . $e->getMessage());
            throw new Exception('Failed to update student status');
        }
    }

    private function createStudentFolder($studentId, $studentName) {
        try {
            $folderName = "Student_{$studentId}_" . str_replace(' ', '_', $studentName);
            $folderPath = "../files/students/{$folderName}";
            
            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
                
                // Create subdirectories
                mkdir("{$folderPath}/documents", 0755, true);
                mkdir("{$folderPath}/statements", 0755, true);
                mkdir("{$folderPath}/recommendations", 0755, true);
                mkdir("{$folderPath}/applications", 0755, true);
                
                // Create README file
                $readmeContent = "Student Application Folder\n";
                $readmeContent .= "Student ID: {$studentId}\n";
                $readmeContent .= "Student Name: {$studentName}\n";
                $readmeContent .= "Created: " . date('Y-m-d H:i:s') . "\n\n";
                $readmeContent .= "Directory Structure:\n";
                $readmeContent .= "- documents/: Student uploaded documents\n";
                $readmeContent .= "- statements/: Statement of Purpose files\n";
                $readmeContent .= "- recommendations/: Letter of Recommendation files\n";
                $readmeContent .= "- applications/: Application forms and files\n";
                
                file_put_contents("{$folderPath}/README.txt", $readmeContent);
                
                return true;
            }
        } catch (Exception $e) {
            error_log("Error creating student folder: " . $e->getMessage());
            return false;
        }
    }

    private function deleteStudentFolder($studentId) {
        try {
            $studentsDir = "../files/students/";
            $folders = glob($studentsDir . "Student_{$studentId}_*");
            
            foreach ($folders as $folder) {
                if (is_dir($folder)) {
                    $this->deleteDirectory($folder);
                }
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Error deleting student folder: " . $e->getMessage());
            return false;
        }
    }

    private function deleteDirectory($dir) {
        if (!is_dir($dir)) return;
        
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

    private function downloadApplicationFile($fileUrl, $studentId, $studentName) {
        try {
            // This will be handled by the Google Drive API integration
            // For now, we'll just log the attempt
            error_log("Attempting to download application file for student {$studentId}: {$fileUrl}");
            
            // The actual download will be handled by download.php
            return true;
        } catch (Exception $e) {
            error_log("Error downloading application file: " . $e->getMessage());
            return false;
        }
    }
}

// Handle the request
try {
    $api = new StudentAPI();
    $result = $api->handleRequest();
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
