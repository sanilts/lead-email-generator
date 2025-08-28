<?php
/**
 * Simple Log Viewer for Debugging
 * View the last 50 lines of PHP error log
 */

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Debug Logs - Lead Email Generator</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            line-height: 1.5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            color: #569cd6;
            border-bottom: 2px solid #569cd6;
            padding-bottom: 10px;
        }
        .log-section {
            background: #2d2d2d;
            border: 1px solid #3e3e3e;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
            overflow-x: auto;
        }
        .log-line {
            margin: 5px 0;
            padding: 5px;
            border-left: 3px solid transparent;
        }
        .log-line:hover {
            background: #3e3e3e;
        }
        .error {
            color: #f48771;
            border-left-color: #f48771;
        }
        .warning {
            color: #dcdcaa;
            border-left-color: #dcdcaa;
        }
        .info {
            color: #9cdcfe;
            border-left-color: #9cdcfe;
        }
        .success {
            color: #4ec9b0;
            border-left-color: #4ec9b0;
        }
        .timestamp {
            color: #808080;
            margin-right: 10px;
        }
        .controls {
            margin-bottom: 20px;
        }
        button {
            background: #569cd6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin-right: 10px;
        }
        button:hover {
            background: #4a8ac9;
        }
        .clear-btn {
            background: #f48771;
        }
        .clear-btn:hover {
            background: #e37760;
        }
        pre {
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Debug Logs</h1>
        
        <div class="controls">
            <button onclick="location.reload()">🔄 Refresh</button>
            <button onclick="clearLogs()" class="clear-btn">🗑️ Clear Logs</button>
            <button onclick="window.location.href='index.html'">← Back to App</button>
        </div>

        <?php
        // Check API log file
        $apiLogFile = __DIR__ . '/logs/api_calls.log';
        if (file_exists($apiLogFile)) {
            echo '<div class="log-section">';
            echo '<h2>API Calls Log</h2>';
            
            $lines = file($apiLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_slice($lines, -50); // Last 50 lines
            
            foreach (array_reverse($lines) as $line) {
                $class = 'info';
                if (strpos($line, 'FAILED') !== false || strpos($line, 'Error') !== false) {
                    $class = 'error';
                } elseif (strpos($line, 'SUCCESS') !== false) {
                    $class = 'success';
                }
                
                // Extract timestamp if present
                if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(.*)/', $line, $matches)) {
                    echo '<div class="log-line ' . $class . '">';
                    echo '<span class="timestamp">' . htmlspecialchars($matches[1]) . '</span>';
                    echo htmlspecialchars($matches[2]);
                    echo '</div>';
                } else {
                    echo '<div class="log-line ' . $class . '">' . htmlspecialchars($line) . '</div>';
                }
            }
            
            echo '</div>';
        } else {
            echo '<div class="log-section"><p>No API log file found at: ' . htmlspecialchars($apiLogFile) . '</p></div>';
        }
        
        // Check PHP error log
        $errorLog = ini_get('error_log');
        if ($errorLog && file_exists($errorLog)) {
            echo '<div class="log-section">';
            echo '<h2>PHP Error Log (Last 50 lines)</h2>';
            
            $lines = [];
            $handle = fopen($errorLog, 'r');
            if ($handle) {
                while (!feof($handle)) {
                    $lines[] = fgets($handle);
                    if (count($lines) > 50) {
                        array_shift($lines);
                    }
                }
                fclose($handle);
            }
            
            foreach (array_reverse($lines) as $line) {
                if (empty(trim($line))) continue;
                
                $class = 'info';
                if (stripos($line, 'error') !== false) {
                    $class = 'error';
                } elseif (stripos($line, 'warning') !== false) {
                    $class = 'warning';
                } elseif (stripos($line, 'notice') !== false) {
                    $class = 'info';
                }
                
                // Highlight our verification logs
                if (stripos($line, 'verif') !== false || stripos($line, 'millionverifier') !== false) {
                    $class = 'success';
                }
                
                echo '<div class="log-line ' . $class . '">' . htmlspecialchars($line) . '</div>';
            }
            
            echo '</div>';
        } else {
            echo '<div class="log-section"><p>PHP error log not found or not accessible.</p></div>';
        }
        
        // Show current configuration
        echo '<div class="log-section">';
        echo '<h2>Current Configuration</h2>';
        echo '<pre>';
        
        if (file_exists('config.php')) {
            require_once 'config.php';
            echo "ENABLE_EMAIL_VERIFICATION: " . (ENABLE_EMAIL_VERIFICATION ? 'TRUE' : 'FALSE') . "\n";
            echo "VERIFICATION_BATCH_SIZE: " . VERIFICATION_BATCH_SIZE . "\n";
            echo "GEMINI_API_KEY: " . (empty(GEMINI_API_KEY) ? 'NOT SET' : substr(GEMINI_API_KEY, 0, 10) . '...') . "\n";
            echo "MILLIONVERIFIER_API_KEY: " . (empty(MILLIONVERIFIER_API_KEY) ? 'NOT SET' : substr(MILLIONVERIFIER_API_KEY, 0, 10) . '...') . "\n";
            echo "ENABLE_LOGGING: " . (ENABLE_LOGGING ? 'TRUE' : 'FALSE') . "\n";
        } else {
            echo "config.php not found!";
        }
        
        echo '</pre>';
        echo '</div>';
        ?>
        
        <script>
            function clearLogs() {
                if (confirm('Clear all log files?')) {
                    // You would need to implement a PHP endpoint to clear logs
                    alert('Log clearing not implemented. Delete log files manually from /logs/ directory.');
                }
            }
        </script>
    </div>
</body>
</html>