<?php
// Entry point for Wasmer deployment
// This file serves the main HTML application

// Set content type to HTML
header('Content-Type: text/html; charset=utf-8');

// Check if user is logged in
session_start();

// If user is not logged in, redirect to login page
if (!isset($_SESSION['user_id'])) {
    // Read and output login.html
    if (file_exists('login.html')) {
        readfile('login.html');
        exit;
    } else {
        echo '<h1>Login page not found</h1>';
        exit;
    }
}

// If user is logged in, serve the main application
if (file_exists('index.html')) {
    readfile('index.html');
} else {
    echo '<h1>Main application not found</h1>';
}
?>
