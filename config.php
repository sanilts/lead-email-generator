<?php
/**
 * Configuration file for Lead Email Generator
 * Simplified version - Gemini 2.5 Flash only
 */

// Check if session is already started
if (session_status() === PHP_SESSION_NONE) {
    // Session Configuration
    ini_set('session.gc_maxlifetime', 3600);
    session_set_cookie_params(3600);
    session_start();
}

// API Configuration - ONLY GEMINI
define('GEMINI_API_KEY', 'AIzaSyBQ6KQPa8Ayw6rAy9h6aO5C2iF5fvJv6tw'); // Required

// Rate Limiting Settings
define('API_RATE_LIMIT_DELAY', 500000); // Microseconds between API calls (0.5 seconds)
define('MAX_BATCH_SIZE', 50); // Maximum companies to search at once

// Cache Settings
define('ENABLE_CACHE', true); // Enable/disable caching
define('CACHE_EXPIRY_HOURS', 24); // How long to cache API results

// Upload Settings
define('MAX_FILE_SIZE', 10485760); // 10MB
define('UPLOAD_DIR', 'uploads/');
define('ALLOWED_EXTENSIONS', ['csv', 'txt']);

// Email Format Detection Preferences
define('DEFAULT_EMAIL_FORMAT', 'firstname.lastname');

// Logging
define('ENABLE_LOGGING', true);
define('LOG_FILE', 'logs/api_calls.log');

// Security
define('ENABLE_CSRF_PROTECTION', false); // Disabled to prevent session issues

// API Endpoint for Gemini
define('GEMINI_API_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent');

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Timezone
date_default_timezone_set('UTC');

// Create necessary directories
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}

if (ENABLE_LOGGING && !file_exists(dirname(LOG_FILE))) {
    mkdir(dirname(LOG_FILE), 0777, true);
}

// Helper function for logging
function logAPICall($company, $result) {
    if (!ENABLE_LOGGING) return;
    
    $logEntry = date('Y-m-d H:i:s') . " | Gemini | $company | " . 
                ($result ? 'SUCCESS' : 'FAILED') . "\n";
    
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);
}

// Helper function for rate limiting
function enforceRateLimit() {
    usleep(API_RATE_LIMIT_DELAY);
}
?>