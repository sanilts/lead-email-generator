<?php
/**
 * Lead Email Generator - Configuration
 * Simplified version using only Gemini 2.5 Flash
 */

// Session Configuration - MUST BE FIRST
if (session_status() === PHP_SESSION_NONE) {
    // Set session parameters
    ini_set('session.use_cookies', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.gc_maxlifetime', 7200); // 2 hours
    
    // Start session
    session_start();
}

// Gemini API Configuration
define('GEMINI_API_KEY', 'AIzaSyBQ6KQPa8Ayw6rAy9h6aO5C2iF5fvJv6tw'); // Replace with your API key

// API Endpoint - Try these in order if one doesn't work:
// 1. gemini-1.5-flash (most stable)
define('GEMINI_API_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent');

// Alternative endpoints to try if above doesn't work:
// define('GEMINI_API_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent');
// define('GEMINI_API_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent');
// define('GEMINI_API_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent');

// Directory Configuration
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('TEMP_DIR', __DIR__ . '/temp/');
define('LOG_DIR', __DIR__ . '/logs/');

// Settings
define('API_RATE_LIMIT', 500000); // Microseconds between API calls (0.5 seconds)
define('MAX_FILE_SIZE', 10485760); // 10MB
define('ENABLE_LOGGING', true);

// PHP Configuration
ini_set('max_execution_time', 300);
ini_set('max_input_time', 300);
ini_set('memory_limit', '256M');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Timezone
date_default_timezone_set('UTC');

// Create necessary directories
$directories = [UPLOAD_DIR, TEMP_DIR, LOG_DIR];
foreach ($directories as $dir) {
    if (!file_exists($dir)) {
        @mkdir($dir, 0777, true);
        // Add .htaccess for security
        @file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
}

// Clean old temp files (older than 2 hours)
function cleanOldFiles() {
    if (is_dir(TEMP_DIR)) {
        $files = glob(TEMP_DIR . '*.json');
        foreach ($files as $file) {
            if (filemtime($file) < time() - 7200) {
                @unlink($file);
            }
        }
    }
}

// Run cleanup occasionally
if (rand(1, 20) == 1) {
    cleanOldFiles();
}
?>