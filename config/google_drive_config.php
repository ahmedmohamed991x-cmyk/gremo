<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Google_Client;
use Google_Service_Drive;

class GoogleDriveService {
    private $credentialsPath;
    private $applicationName;
    private $scopes;
    
    public function __construct() {
        // Set your Google Cloud project credentials path
        $this->credentialsPath = __DIR__ . '/service-account.json';
        
        // Application name for Google API
        $this->applicationName = 'Student Application Manager';
        
        // API scopes
        $this->scopes = [Google_Service_Drive::DRIVE_READONLY];
    }
    
    public function getService() {
        try {
            if (!file_exists($this->credentialsPath)) {
                error_log("Google Drive credentials file not found at: " . $this->credentialsPath);
                return null;
            }
            
            $client = new Google_Client();
                    $client->setAuthConfig($this->credentialsPath);
        $client->setApplicationName($this->applicationName);
        $client->setScopes($this->scopes);
        
        // Fix SSL certificate issues
        $client->setHttpClient(new GuzzleHttp\Client([
            'verify' => false, // Disable SSL verification for development
            'timeout' => 30
        ]));
        
        $service = new Google_Service_Drive($client);
        return $service;
            
        } catch (Exception $e) {
            error_log("Error initializing Google Drive service: " . $e->getMessage());
            return null;
        }
    }
    
    public function testConnection() {
        try {
            $service = $this->getService();
            if (!$service) {
                return false;
            }
            
            // Try to list files to test connection
            $service->files->listFiles(['pageSize' => 1]);
            return true;
            
        } catch (Exception $e) {
            error_log("Google Drive connection test failed: " . $e->getMessage());
            return false;
        }
    }
}
?>
