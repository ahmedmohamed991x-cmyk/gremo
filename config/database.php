<?php
/**
 * Database Configuration and Connection Class
 * 
 * This file handles database connections and provides a simple interface
 * for database operations in the student management system.
 */

class Database {
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $charset;
    private $pdo;

    public function __construct() {
        // Database configuration
        $this->host = 'localhost';
        $this->dbname = 'student_management';
        $this->username = 'root';
        $this->password = '';
        $this->charset = 'utf8mb4';
        
        // Try to connect to database
        $this->connect();
    }

    /**
     * Establish database connection
     */
    private function connect() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset}"
            ];

            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
            
        } catch (PDOException $e) {
            // If database doesn't exist, try to create it
            if ($e->getCode() == 1049) {
                $this->createDatabase();
            } else {
                error_log("Database connection failed: " . $e->getMessage());
                throw new Exception("Database connection failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Create database if it doesn't exist
     */
    private function createDatabase() {
        try {
            // Connect without specifying database
            $dsn = "mysql:host={$this->host};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];

            $tempPdo = new PDO($dsn, $this->username, $this->password, $options);
            
            // Create database
            $sql = "CREATE DATABASE IF NOT EXISTS `{$this->dbname}` CHARACTER SET {$this->charset} COLLATE utf8mb4_unicode_ci";
            $tempPdo->exec($sql);
            
            // Close temporary connection
            $tempPdo = null;
            
            // Now connect to the created database
            $this->connect();
            
            // Create tables
            $this->createTables();
            
        } catch (PDOException $e) {
            error_log("Failed to create database: " . $e->getMessage());
            throw new Exception("Failed to create database: " . $e->getMessage());
        }
    }

    /**
     * Create necessary tables
     */
    private function createTables() {
        try {
            // Students table
            $sql = "CREATE TABLE IF NOT EXISTS `students` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `full_name` VARCHAR(255) NOT NULL,
                `email` VARCHAR(255) UNIQUE NOT NULL,
                `phone` VARCHAR(50) NOT NULL,
                `birth_date` DATE NOT NULL,
                `target_university` VARCHAR(255) NOT NULL,
                `major` VARCHAR(255) NOT NULL,
                `application_file` TEXT,
                `statement_of_purpose` TEXT,
                `letter_of_recommendation` TEXT,
                `status` ENUM('pending', 'completed', 'issue') DEFAULT 'pending',
                `progress` INT DEFAULT 0,
                `notes` TEXT,
                `documents` JSON,
                `tasks` JSON,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `completed_at` TIMESTAMP NULL,
                `skipped_at` TIMESTAMP NULL,
                INDEX `idx_email` (`email`),
                INDEX `idx_status` (`status`),
                INDEX `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $this->pdo->exec($sql);

            // Student files table for tracking downloaded files
            $sql = "CREATE TABLE IF NOT EXISTS `student_files` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `student_id` INT NOT NULL,
                `file_name` VARCHAR(255) NOT NULL,
                `file_path` VARCHAR(500) NOT NULL,
                `file_type` VARCHAR(100),
                `file_size` BIGINT,
                `category` VARCHAR(100) DEFAULT 'other',
                `description` TEXT,
                `source_url` TEXT,
                `downloaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `status` ENUM('active', 'deleted') DEFAULT 'active',
                FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
                INDEX `idx_student_id` (`student_id`),
                INDEX `idx_category` (`category`),
                INDEX `idx_downloaded_at` (`downloaded_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $this->pdo->exec($sql);

            // Student tasks table for detailed task tracking
            $sql = "CREATE TABLE IF NOT EXISTS `student_tasks` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `student_id` INT NOT NULL,
                `task_name` VARCHAR(255) NOT NULL,
                `task_description` TEXT,
                `task_type` ENUM('document', 'form', 'review', 'other') DEFAULT 'other',
                `status` ENUM('pending', 'in_progress', 'completed', 'skipped') DEFAULT 'pending',
                `priority` ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
                `due_date` DATE,
                `completed_at` TIMESTAMP NULL,
                `assigned_to` VARCHAR(100),
                `notes` TEXT,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
                INDEX `idx_student_id` (`student_id`),
                INDEX `idx_status` (`status`),
                INDEX `idx_priority` (`priority`),
                INDEX `idx_due_date` (`due_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $this->pdo->exec($sql);

            // Application history table for tracking application status changes
            $sql = "CREATE TABLE IF NOT EXISTS `application_history` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `student_id` INT NOT NULL,
                `action` VARCHAR(100) NOT NULL,
                `old_status` VARCHAR(50),
                `new_status` VARCHAR(50),
                `description` TEXT,
                `performed_by` VARCHAR(100),
                `performed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
                INDEX `idx_student_id` (`student_id`),
                INDEX `idx_action` (`action`),
                INDEX `idx_performed_at` (`performed_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $this->pdo->exec($sql);

            // System settings table
            $sql = "CREATE TABLE IF NOT EXISTS `system_settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `setting_key` VARCHAR(100) UNIQUE NOT NULL,
                `setting_value` TEXT,
                `setting_description` TEXT,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $this->pdo->exec($sql);

            // Insert default system settings
            $this->insertDefaultSettings();

        } catch (PDOException $e) {
            error_log("Failed to create tables: " . $e->getMessage());
            throw new Exception("Failed to create tables: " . $e->getMessage());
        }
    }

    /**
     * Insert default system settings
     */
    private function insertDefaultSettings() {
        try {
            $settings = [
                ['max_file_size', '52428800', 'Maximum file upload size in bytes (50MB)'],
                ['allowed_file_types', 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,txt', 'Allowed file types for uploads'],
                ['student_folder_template', 'Student_{id}_{name}', 'Template for student folder naming'],
                ['auto_download_drive_files', 'true', 'Automatically download files from Google Drive links'],
                ['file_retention_days', '365', 'Number of days to retain student files'],
                ['backup_enabled', 'true', 'Enable automatic database backups'],
                ['notification_email', 'admin@example.com', 'Email for system notifications']
            ];

            $sql = "INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `setting_description`) VALUES (?, ?, ?)";
            $stmt = $this->pdo->prepare($sql);

            foreach ($settings as $setting) {
                $stmt->execute($setting);
            }

        } catch (PDOException $e) {
            error_log("Failed to insert default settings: " . $e->getMessage());
        }
    }

    /**
     * Get database connection
     */
    public function getConnection() {
        return $this->pdo;
    }

    /**
     * Test database connection
     */
    public function testConnection() {
        try {
            $stmt = $this->pdo->query("SELECT 1");
            return $stmt->fetch();
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get database information
     */
    public function getDatabaseInfo() {
        try {
            $info = [
                'database_name' => $this->dbname,
                'server_version' => $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
                'client_version' => $this->pdo->getAttribute(PDO::ATTR_CLIENT_VERSION),
                'connection_status' => $this->pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS),
                'driver_name' => $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)
            ];

            // Get table information
            $stmt = $this->pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $info['tables'] = $tables;

            return $info;
        } catch (PDOException $e) {
            error_log("Failed to get database info: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get table structure
     */
    public function getTableStructure($tableName) {
        try {
            $sql = "DESCRIBE `{$tableName}`";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get table structure: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Backup database
     */
    public function backupDatabase($backupPath = null) {
        try {
            if (!$backupPath) {
                $backupPath = dirname(__DIR__) . '/backups/';
            }

            if (!is_dir($backupPath)) {
                mkdir($backupPath, 0755, true);
            }

            $timestamp = date('Y-m-d_H-i-s');
            $filename = "backup_{$this->dbname}_{$timestamp}.sql";
            $filepath = $backupPath . $filename;

            // Use mysqldump if available
            $command = "mysqldump --host={$this->host} --user={$this->username}";
            if ($this->password) {
                $command .= " --password={$this->password}";
            }
            $command .= " --single-transaction --routines --triggers {$this->dbname} > {$filepath}";

            $output = [];
            $returnVar = 0;
            exec($command, $output, $returnVar);

            if ($returnVar === 0 && file_exists($filepath)) {
                return [
                    'success' => true,
                    'message' => 'Database backup created successfully',
                    'filepath' => $filepath,
                    'filesize' => filesize($filepath)
                ];
            } else {
                throw new Exception('Failed to create database backup');
            }

        } catch (Exception $e) {
            error_log("Database backup failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Database backup failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Close database connection
     */
    public function closeConnection() {
        $this->pdo = null;
    }

    /**
     * Destructor to ensure connection is closed
     */
    public function __destruct() {
        $this->closeConnection();
    }
}

// Helper function to get database instance
function getDatabase() {
    static $db = null;
    
    if ($db === null) {
        $db = new Database();
    }
    
    return $db;
}

// Helper function to test database connection
function testDatabaseConnection() {
    try {
        $db = getDatabase();
        return $db->testConnection();
    } catch (Exception $e) {
        return false;
    }
}

// Helper function to get database info
function getDatabaseInfo() {
    try {
        $db = getDatabase();
        return $db->getDatabaseInfo();
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
?>
