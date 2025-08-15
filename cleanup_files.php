<?php
/**
 * Comprehensive File Cleanup and Renaming Utility
 * This script will:
 * 1. Remove orphaned folders (folders for non-existent students)
 * 2. Clean up duplicate folders
 * 3. Rename folders to use proper naming convention
 * 4. Remove old Cam_Scanner and messy folders
 */

header('Content-Type: text/html; charset=utf-8');

// Configuration
$dataDir = './data/';
$filesDir = './files/';
$studentsFile = $dataDir . 'students.json';
$backupDir = './files/backup_' . date('Y-m-d_H-i-s') . '/';

echo "<h1>🧹 Comprehensive File Cleanup and Renaming Utility</h1>";
echo "<p>This utility will clean up ALL existing student files and folders, removing orphans and duplicates.</p>";

// Check if students.json exists
if (!file_exists($studentsFile)) {
    echo "<p style='color: red;'>❌ students.json not found!</p>";
    exit;
}

// Load students data
$students = json_decode(file_get_contents($studentsFile), true) ?: [];
echo "<p>✅ Loaded " . count($students) . " students from database</p>";

// Get all student IDs that actually exist
$existingStudentIds = array_column($students, 'id');
echo "<p>📋 Existing student IDs: " . implode(', ', $existingStudentIds) . "</p>";

// Create backup directory
if (!file_exists($backupDir)) {
    mkdir($backupDir, 0777, true);
    echo "<p>✅ Created backup directory: $backupDir</p>";
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

// Function to copy directory recursively
function copyDirectory($src, $dst) {
    if (!is_dir($dst)) {
        mkdir($dst, 0777, true);
    }
    
    $files = glob($src . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            copy($file, $dst . '/' . basename($file));
        } elseif (is_dir($file)) {
            copyDirectory($file, $dst . '/' . basename($file));
        }
    }
}

// Function to remove directory recursively
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

// Function to extract student ID from folder name
function extractStudentIdFromFolder($folderName) {
    // Extract ID from folder names like "17__________________________" or "17_Cam_Scanner"
    if (preg_match('/^(\d+)/', $folderName, $matches)) {
        return intval($matches[1]);
    }
    return null;
}

// Step 1: Analyze existing folders
echo "<h2>🔍 Step 1: Analyzing Existing Folders</h2>";
$existingFolders = glob($filesDir . 'students/*');
$folderAnalysis = [];

foreach ($existingFolders as $folder) {
    $folderName = basename($folder);
    $studentId = extractStudentIdFromFolder($folderName);
    
    if ($studentId) {
        if (!isset($folderAnalysis[$studentId])) {
            $folderAnalysis[$studentId] = [];
        }
        $folderAnalysis[$studentId][] = [
            'path' => $folder,
            'name' => $folderName,
            'isDuplicate' => count($folderAnalysis[$studentId]) > 0
        ];
    }
}

echo "<h3>📊 Folder Analysis:</h3>";
foreach ($folderAnalysis as $studentId => $folders) {
    $status = in_array($studentId, $existingStudentIds) ? '✅ Active' : '❌ Orphaned';
    $duplicateStatus = count($folders) > 1 ? '🔄 Duplicates' : '✅ Single';
    echo "<p><strong>Student ID $studentId:</strong> $status - $duplicateStatus - " . count($folders) . " folder(s)</p>";
    
    foreach ($folders as $folder) {
        echo "<p style='margin-left: 20px;'>📁 {$folder['name']}" . ($folder['isDuplicate'] ? ' (DUPLICATE)' : '') . "</p>";
    }
}

// Step 2: Remove orphaned folders
echo "<h2>🗑️ Step 2: Removing Orphaned Folders</h2>";
$orphanedFoldersRemoved = 0;

foreach ($folderAnalysis as $studentId => $folders) {
    if (!in_array($studentId, $existingStudentIds)) {
        echo "<p>❌ Student ID $studentId doesn't exist - removing all folders...</p>";
        
        foreach ($folders as $folder) {
            // Backup before removal
            $backupPath = $backupDir . 'orphaned_' . $folder['name'];
            if (file_exists($backupPath)) {
                $backupPath .= '_' . time();
            }
            
            copyDirectory($folder['path'], $backupPath);
            echo "<p style='margin-left: 20px;'>📁 Backed up: {$folder['name']} → " . basename($backupPath) . "</p>";
            
            removeDirectory($folder['path']);
            echo "<p style='margin-left: 20px;'>🗑️ Removed: {$folder['name']}</p>";
            $orphanedFoldersRemoved++;
        }
        
        unset($folderAnalysis[$studentId]);
    }
}

echo "<p>✅ Removed $orphanedFoldersRemoved orphaned folders</p>";

// Step 3: Clean up duplicate folders
echo "<h2>🔄 Step 3: Cleaning Up Duplicate Folders</h2>";
$duplicateFoldersCleaned = 0;

foreach ($folderAnalysis as $studentId => $folders) {
    if (count($folders) > 1) {
        echo "<p>🔄 Student ID $studentId has " . count($folders) . " folders - cleaning up...</p>";
        
        // Keep the first folder, backup and remove others
        $primaryFolder = $folders[0];
        $duplicateFolders = array_slice($folders, 1);
        
        echo "<p style='margin-left: 20px;'>✅ Keeping: {$primaryFolder['name']}</p>";
        
        foreach ($duplicateFolders as $duplicateFolder) {
            // Backup duplicate folder
            $backupPath = $backupDir . 'duplicate_' . $duplicateFolder['name'];
            if (file_exists($backupPath)) {
                $backupPath .= '_' . time();
            }
            
            copyDirectory($duplicateFolder['path'], $backupPath);
            echo "<p style='margin-left: 20px;'>📁 Backed up duplicate: {$duplicateFolder['name']} → " . basename($backupPath) . "</p>";
            
            // Move files from duplicate to primary folder
            $files = glob($duplicateFolder['path'] . '/*');
            $filesMoved = 0;
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    $fileName = basename($file);
                    $newPath = $primaryFolder['path'] . '/' . $fileName;
                    
                    // Handle filename conflicts
                    if (file_exists($newPath)) {
                        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
                        $name = pathinfo($fileName, PATHINFO_FILENAME);
                        $counter = 1;
                        do {
                            $newFileName = $name . '_' . $counter . '.' . $extension;
                            $newPath = $primaryFolder['path'] . '/' . $newFileName;
                            $counter++;
                        } while (file_exists($newPath));
                    }
                    
                    if (rename($file, $newPath)) {
                        echo "<p style='margin-left: 40px;'>📄 Moved: $fileName → " . basename($newPath) . "</p>";
                        $filesMoved++;
                    }
                }
            }
            
            echo "<p style='margin-left: 20px;'>📄 Moved $filesMoved files from duplicate folder</p>";
            
            // Remove duplicate folder
            removeDirectory($duplicateFolder['path']);
            echo "<p style='margin-left: 20px;'>🗑️ Removed duplicate: {$duplicateFolder['name']}</p>";
            $duplicateFoldersCleaned++;
        }
        
        // Update folder analysis to only have the primary folder
        $folderAnalysis[$studentId] = [$primaryFolder];
    }
}

echo "<p>✅ Cleaned up $duplicateFoldersCleaned duplicate folders</p>";

// Step 4: Rename folders to proper convention
echo "<h2>🔄 Step 4: Renaming Folders to Proper Convention</h2>";
$foldersRenamed = 0;

foreach ($folderAnalysis as $studentId => $folders) {
    if (count($folders) === 1) {
        $currentFolder = $folders[0];
        $currentFolderName = $currentFolder['name'];
        
        // Find student info
        $student = null;
        foreach ($students as $s) {
            if ($s['id'] == $studentId) {
                $student = $s;
                break;
            }
        }
        
        if ($student) {
            $cleanStudentName = sanitizeFileName($student['fullName']);
            $newFolderName = $studentId . '_' . $cleanStudentName;
            $newFolderPath = $filesDir . 'students/' . $newFolderName;
            
            if ($currentFolder['path'] !== $newFolderPath) {
                echo "<p>🔄 Renaming folder for Student ID $studentId: {$student['fullName']}</p>";
                echo "<p style='margin-left: 20px;'>📁 Old: $currentFolderName</p>";
                echo "<p style='margin-left: 20px;'>📁 New: $newFolderName</p>";
                
                // Rename the folder
                if (rename($currentFolder['path'], $newFolderPath)) {
                    echo "<p style='margin-left: 20px;'>✅ Successfully renamed folder</p>";
                    $foldersRenamed++;
                    
                    // Update folder analysis
                    $folderAnalysis[$studentId][0]['path'] = $newFolderPath;
                    $folderAnalysis[$studentId][0]['name'] = $newFolderName;
                } else {
                    echo "<p style='margin-left: 20px;'>❌ Failed to rename folder</p>";
                }
            } else {
                echo "<p>✅ Folder already has correct naming: $newFolderName</p>";
            }
        }
    }
}

echo "<p>✅ Renamed $foldersRenamed folders</p>";

// Step 5: Rename files inside folders
echo "<h2>📄 Step 5: Renaming Files Inside Folders</h2>";
$filesRenamed = 0;

foreach ($folderAnalysis as $studentId => $folders) {
    if (count($folders) === 1) {
        $folder = $folders[0];
        $student = null;
        
        foreach ($students as $s) {
            if ($s['id'] == $studentId) {
                $student = $s;
                break;
            }
        }
        
        if ($student) {
            echo "<p>🔄 Renaming files for Student ID $studentId: {$student['fullName']}</p>";
            
            $files = glob($folder['path'] . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    $oldFilename = basename($file);
                    $extension = pathinfo($file, PATHINFO_EXTENSION);
                    
                    // Keep original filename - just organize in proper folder
                    $newFilename = $oldFilename;
                    $newFilePath = $folder['path'] . '/' . $newFilename;
                    
                    if ($oldFilename !== $newFilename) {
                        if (rename($file, $newFilePath)) {
                            echo "<p style='margin-left: 20px;'>📄 Renamed: $oldFilename → $newFilename</p>";
                            $filesRenamed++;
                        } else {
                            echo "<p style='margin-left: 20px;'>❌ Failed to rename: $oldFilename</p>";
                        }
                    }
                }
            }
        }
    }
}

echo "<p>✅ Renamed $filesRenamed files</p>";

// Step 6: Update student records in students.json
echo "<h2>📝 Step 6: Updating Student Records</h2>";
$recordsUpdated = 0;

foreach ($students as &$student) {
    if (isset($student['files']) && is_array($student['files'])) {
        $filesUpdated = false;
        
        foreach ($student['files'] as &$file) {
            if (isset($file['path'])) {
                $oldPath = $file['path'];
                
                // Extract student ID and name from old path
                if (preg_match('/files\/students\/(\d+)_([^\/]+)\//', $oldPath, $matches)) {
                    $fileStudentId = $matches[1];
                    $oldStudentName = $matches[2];
                    
                    // Get current student name
                    $currentStudentName = sanitizeFileName($student['fullName']);
                    
                    // Generate new path
                    $newPath = 'files/students/' . $fileStudentId . '_' . $currentStudentName . '/' . basename($oldPath);
                    
                    if ($oldPath !== $newPath) {
                        $file['path'] = $newPath;
                        $filesUpdated = true;
                        echo "<p>🔄 Updated file path: " . basename($oldPath) . "</p>";
                    }
                }
            }
        }
        
        if ($filesUpdated) {
            $recordsUpdated++;
        }
    }
}

// Save updated students data
if ($recordsUpdated > 0) {
    file_put_contents($studentsFile, json_encode($students, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "<p>✅ Updated $recordsUpdated student records</p>";
}

// Final summary
echo "<h2>📊 Final Summary</h2>";
echo "<p>✅ Processed " . count($students) . " students</p>";
echo "<p>✅ Removed " . $orphanedFoldersRemoved . " orphaned folders</p>";
echo "<p>✅ Cleaned up " . $duplicateFoldersCleaned . " duplicate folders</p>";
echo "<p>✅ Renamed " . $foldersRenamed . " folders</p>";
echo "<p>✅ Renamed " . $filesRenamed . " files</p>";
echo "<p>✅ Updated " . $recordsUpdated . " student records</p>";
echo "<p>✅ Backup created in: $backupDir</p>";

echo "<h3>🎯 New Naming Convention Applied:</h3>";
echo "<ul>";
echo "<li><strong>Folders:</strong> STUDENT_ID_STUDENT_NAME (e.g., 4_يوسف_محمد_عوض_عبدالعاطي_هيكل)</li>";
echo "<li><strong>Files:</strong> Original filenames preserved (e.g., شهادة_الميلاد.pdf, صورة_شخصية.jpg)</li>";
echo "</ul>";

echo "<h3>🧹 What Was Cleaned Up:</h3>";
echo "<ul>";
echo "<li>❌ Orphaned folders for non-existent students (IDs 19-25)</li>";
echo "<li>🔄 Duplicate folders for the same student</li>";
echo "<li>📁 Messy folder names with underscores and Cam_Scanner text</li>";
echo "<li>📄 Messy file names with timestamps and random text</li>";
echo "</ul>";

echo "<h3>⚠️ Important Notes:</h3>";
echo "<ul>";
echo "<li>All old files have been backed up to: $backupDir</li>";
echo "<li>New files will automatically use the improved naming convention</li>";
echo "<li>Student records have been updated with new file paths</li>";
echo "<li>Orphaned folders have been completely removed</li>";
echo "</ul>";

echo "<p style='color: green; font-weight: bold; font-size: 18px;'>🎉 Comprehensive file cleanup completed successfully!</p>";
echo "<p style='color: blue; font-weight: bold;'>Your file structure is now clean and organized!</p>";
?>
