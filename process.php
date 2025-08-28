<?php
/**
 * Lead Email Generator - Debug Version
 * Enhanced error reporting to identify issues
 */

// Enable detailed error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Start output buffering to catch any errors
ob_start();

try {
    require_once 'config.php';
} catch (Exception $e) {
    die(json_encode(['success' => false, 'error' => 'Config file error: ' . $e->getMessage()]));
}

class LeadProcessor {
    private $geminiKey;
    private $millionVerifierKey;
    private $csvData = [];
    private $companies = [];
    
    public function __construct() {
        $this->geminiKey = GEMINI_API_KEY;
        $this->millionVerifierKey = defined('MILLIONVERIFIER_API_KEY') ? MILLIONVERIFIER_API_KEY : '';
    }
    
    /**
     * Test Gemini API connection with detailed debugging
     */
    public function testGeminiConnection() {
        $result = [
            'configured' => false,
            'working' => false,
            'key_length' => strlen($this->geminiKey),
            'key_preview' => substr($this->geminiKey, 0, 10) . '...',
            'endpoint' => GEMINI_API_ENDPOINT,
            'error' => null,
            'curl_info' => null,
            'response' => null
        ];
        
        if (empty($this->geminiKey) || $this->geminiKey === 'YOUR_API_KEY') {
            $result['error'] = 'API key not configured';
            return $result;
        }
        
        $result['configured'] = true;
        
        $testUrl = GEMINI_API_ENDPOINT . '?key=' . $this->geminiKey;
        $testData = [
            'contents' => [['parts' => [['text' => 'Test']]]],
            'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 10]
        ];
        
        $ch = curl_init($testUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($testData),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_VERBOSE => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);
        
        $result['curl_info'] = [
            'http_code' => $httpCode,
            'total_time' => $curlInfo['total_time'],
            'url' => substr($curlInfo['url'], 0, 50) . '...'
        ];
        
        if ($curlError) {
            $result['error'] = 'CURL Error: ' . $curlError;
        } elseif ($httpCode == 200) {
            $result['working'] = true;
            $result['response'] = 'Success';
        } else {
            $result['error'] = 'HTTP Error: ' . $httpCode;
            if ($response) {
                $decoded = json_decode($response, true);
                if (isset($decoded['error'])) {
                    $result['error'] .= ' - ' . json_encode($decoded['error']);
                } else {
                    $result['response'] = substr($response, 0, 200);
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Test MillionVerifier API connection
     */
    public function testMillionVerifierConnection() {
        $result = [
            'configured' => false,
            'working' => false,
            'credits' => 0,
            'key_length' => strlen($this->millionVerifierKey),
            'key_preview' => substr($this->millionVerifierKey, 0, 10) . '...',
            'error' => null
        ];
        
        if (empty($this->millionVerifierKey) || $this->millionVerifierKey === 'YOUR_MILLIONVERIFIER_API_KEY') {
            $result['error'] = 'API key not configured';
            return $result;
        }
        
        $result['configured'] = true;
        
        // Test with credits endpoint
        $url = 'https://api.millionverifier.com/api/v3/credits?api=' . $this->millionVerifierKey;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            $result['error'] = 'CURL Error: ' . $curlError;
        } elseif ($httpCode == 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['credits'])) {
                $result['working'] = true;
                $result['credits'] = $data['credits'];
            } else {
                $result['error'] = 'Invalid response format';
            }
        } else {
            $result['error'] = 'HTTP Error: ' . $httpCode;
            if ($response) {
                $decoded = json_decode($response, true);
                if ($decoded) {
                    $result['error'] .= ' - ' . json_encode($decoded);
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Search for company domain and email format using Gemini
     */
    public function searchWithGemini($companyName) {
        if (empty($this->geminiKey)) {
            return $this->generateFallback($companyName);
        }
        
        $url = GEMINI_API_ENDPOINT . '?key=' . $this->geminiKey;
        
        $prompt = "For the company '$companyName', determine:
1. The official website domain (format: example.com, no http/www)
2. Their typical employee email format

Return ONLY this JSON:
{\"domain\": \"example.com\", \"format\": \"firstname.lastname\", \"confidence\": 85}

Format options: firstname.lastname, firstname, f.lastname, firstname.l, firstnamelastname, lastname.firstname, lastname, fl, firstname_lastname";
        
        $data = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 100,
                'responseMimeType' => 'application/json'
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $this->log($companyName, $httpCode == 200, "HTTP $httpCode" . ($error ? " - $error" : ""));
        
        if ($httpCode == 200 && $response) {
            $result = json_decode($response, true);
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $parsed = json_decode($result['candidates'][0]['content']['parts'][0]['text'], true);
                if ($parsed && isset($parsed['domain'])) {
                    return [
                        'domain' => $this->cleanDomain($parsed['domain']),
                        'format' => $parsed['format'] ?? 'firstname.lastname',
                        'confidence' => $parsed['confidence'] ?? 70,
                        'source' => 'gemini'
                    ];
                }
            }
        }
        
        return $this->generateFallback($companyName);
    }
    
    /**
     * Generate fallback domain
     */
    private function generateFallback($companyName) {
        $clean = strtolower(preg_replace('/[^a-z0-9]/i', '', $companyName));
        $clean = substr($clean, 0, 20);
        
        return [
            'domain' => ($clean ?: 'example') . '.com',
            'format' => 'firstname.lastname',
            'confidence' => 30,
            'source' => 'fallback'
        ];
    }
    
    /**
     * Clean domain format
     */
    private function cleanDomain($domain) {
        $domain = strtolower(trim($domain));
        $domain = str_replace(['http://', 'https://', 'www.'], '', $domain);
        $domain = rtrim($domain, '/');
        return $domain;
    }
    
    /**
     * Parse CSV file
     */
    public function parseCSV($file) {
        if (!file_exists($file)) {
            throw new Exception("File not found: $file");
        }
        
        $handle = fopen($file, 'r');
        if (!$handle) {
            throw new Exception("Cannot open file: $file");
        }
        
        $headers = fgetcsv($handle);
        if (!$headers) {
            throw new Exception("Cannot read CSV headers");
        }
        
        $this->csvData = [];
        $this->companies = [];
        
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) === count($headers)) {
                $record = array_combine($headers, $row);
                $this->csvData[] = $record;
                
                // Group by company
                $companyName = $this->findCompanyName($record);
                if ($companyName) {
                    if (!isset($this->companies[$companyName])) {
                        $this->companies[$companyName] = [];
                    }
                    $this->companies[$companyName][] = $record;
                }
            }
        }
        
        fclose($handle);
        return true;
    }
    
    /**
     * Find company name in record
     */
    private function findCompanyName($record) {
        $fields = ['company_name', 'Company', 'company', 'CompanyName', 'Organization', 'organisation'];
        foreach ($fields as $field) {
            if (isset($record[$field]) && !empty(trim($record[$field]))) {
                return trim($record[$field]);
            }
        }
        return null;
    }
    
    /**
     * Find first name in record
     */
    private function findFirstName($record) {
        $fields = ['firstName', 'FirstName', 'first_name', 'fname', 'First Name', 'First', 'given_name'];
        foreach ($fields as $field) {
            if (isset($record[$field]) && !empty(trim($record[$field]))) {
                return trim($record[$field]);
            }
        }
        return '';
    }
    
    /**
     * Find last name in record
     */
    private function findLastName($record) {
        $fields = ['lastName', 'LastName', 'last_name', 'lname', 'Last Name', 'Last', 'surname', 'family_name'];
        foreach ($fields as $field) {
            if (isset($record[$field]) && !empty(trim($record[$field]))) {
                return trim($record[$field]);
            }
        }
        return '';
    }
    
    /**
     * Get statistics
     */
    public function getStats() {
        return [
            'total_leads' => count($this->csvData),
            'unique_companies' => count($this->companies),
            'companies' => $this->companies
        ];
    }
    
    /**
     * Transliterate special characters
     */
    private function transliterate($text) {
        $map = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u',
            'ç' => 'c', 'ñ' => 'n'
        ];
        $text = strtr($text, $map);
        return preg_replace('/[^a-z0-9]/i', '', $text);
    }
    
    /**
     * Generate email address
     */
    public function generateEmail($firstName, $lastName, $domain, $format) {
        $firstName = strtolower($this->transliterate($firstName));
        $lastName = strtolower($this->transliterate($lastName));
        
        if (empty($firstName) || empty($lastName) || empty($domain)) {
            return '';
        }
        
        switch($format) {
            case 'firstname.lastname':
                return "$firstName.$lastName@$domain";
            case 'firstname':
                return "$firstName@$domain";
            case 'lastname':
                return "$lastName@$domain";
            case 'f.lastname':
                return $firstName[0] . ".$lastName@$domain";
            case 'firstname.l':
                return "$firstName." . $lastName[0] . "@$domain";
            case 'firstnamelastname':
                return "$firstName$lastName@$domain";
            case 'lastname.firstname':
                return "$lastName.$firstName@$domain";
            case 'fl':
                return $firstName[0] . $lastName[0] . "@$domain";
            case 'firstname_lastname':
                return "{$firstName}_{$lastName}@$domain";
            default:
                return "$firstName.$lastName@$domain";
        }
    }
    
    /**
     * Process all leads
     */
    public function processLeads($mappings, $excluded = []) {
        $processed = [];
        
        foreach ($this->companies as $companyName => $leads) {
            if (in_array($companyName, $excluded)) {
                continue;
            }
            
            $domain = $mappings[$companyName]['domain'] ?? '';
            $format = $mappings[$companyName]['format'] ?? 'firstname.lastname';
            
            foreach ($leads as $lead) {
                $firstName = $this->findFirstName($lead);
                $lastName = $this->findLastName($lead);
                
                $processed[] = [
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'company' => $companyName,
                    'email' => $this->generateEmail($firstName, $lastName, $domain, $format)
                ];
            }
        }
        
        return $processed;
    }
    
    /**
     * Log API calls
     */
    private function log($company, $success, $details = '') {
        if (ENABLE_LOGGING) {
            $entry = date('Y-m-d H:i:s') . " | $company | " . ($success ? 'SUCCESS' : 'FAILED');
            if ($details) {
                $entry .= " | $details";
            }
            $entry .= "\n";
            @file_put_contents(LOG_DIR . 'api_calls.log', $entry, FILE_APPEND);
        }
    }
}

// Helper functions
function saveData($data) {
    $file = TEMP_DIR . 'data_' . session_id() . '_' . time() . '.json';
    file_put_contents($file, json_encode($data));
    $_SESSION['data_file'] = $file;
    return $file;
}

function loadData() {
    if (isset($_SESSION['processed_data'])) {
        return $_SESSION['processed_data'];
    }
    
    if (isset($_SESSION['data_file']) && file_exists($_SESSION['data_file'])) {
        return json_decode(file_get_contents($_SESSION['data_file']), true);
    }
    
    $files = glob(TEMP_DIR . 'data_' . session_id() . '_*.json');
    if ($files) {
        usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
        return json_decode(file_get_contents($files[0]), true);
    }
    
    return null;
}

// Clear any output buffer
ob_clean();

// API HANDLERS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        $processor = new LeadProcessor();
        
        switch($action) {
            case 'check_api':
            case 'debug_api':
                // Test both APIs with detailed debugging
                $geminiTest = $processor->testGeminiConnection();
                $mvTest = $processor->testMillionVerifierConnection();
                
                echo json_encode([
                    'success' => true,
                    'gemini' => $geminiTest,
                    'millionverifier' => $mvTest,
                    'php_version' => PHP_VERSION,
                    'curl_enabled' => function_exists('curl_init'),
                    'session_id' => session_id(),
                    'config_exists' => file_exists('config.php'),
                    'directories' => [
                        'upload' => is_dir(UPLOAD_DIR) && is_writable(UPLOAD_DIR),
                        'temp' => is_dir(TEMP_DIR) && is_writable(TEMP_DIR),
                        'log' => is_dir(LOG_DIR) && is_writable(LOG_DIR)
                    ]
                ]);
                break;
                
            case 'upload':
                if (!isset($_FILES['csv'])) {
                    throw new Exception("No file uploaded");
                }
                
                if ($_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Upload error code: " . $_FILES['csv']['error']);
                }
                
                $uploadFile = UPLOAD_DIR . time() . '_' . basename($_FILES['csv']['name']);
                
                if (!move_uploaded_file($_FILES['csv']['tmp_name'], $uploadFile)) {
                    throw new Exception("Failed to move uploaded file");
                }
                
                $processor->parseCSV($uploadFile);
                $_SESSION['csv_file'] = $uploadFile;
                
                $stats = $processor->getStats();
                $companies = [];
                foreach ($stats['companies'] as $name => $leads) {
                    $companies[] = ['name' => $name, 'lead_count' => count($leads)];
                }
                
                echo json_encode([
                    'success' => true,
                    'stats' => $stats,
                    'companies' => $companies,
                    'file_saved' => $uploadFile
                ]);
                break;
                
            case 'search_online':
                $company = $_POST['company'] ?? '';
                if (empty($company)) {
                    throw new Exception("Company name is required");
                }
                
                $result = $processor->searchWithGemini($company);
                usleep(API_RATE_LIMIT);
                echo json_encode(array_merge(['success' => true], $result));
                break;
                
            case 'generate':
                if (!isset($_SESSION['csv_file'])) {
                    throw new Exception("No CSV file in session");
                }
                
                if (!file_exists($_SESSION['csv_file'])) {
                    throw new Exception("CSV file not found: " . $_SESSION['csv_file']);
                }
                
                $processor->parseCSV($_SESSION['csv_file']);
                
                $mappings = json_decode($_POST['mappings'] ?? '{}', true);
                $excluded = json_decode($_POST['excluded'] ?? '[]', true);
                
                $processed = $processor->processLeads($mappings, $excluded);
                
                // Save data
                saveData($processed);
                if (count($processed) < 1000) {
                    $_SESSION['processed_data'] = $processed;
                }
                
                $emailCount = count(array_filter($processed, function($r) { return !empty($r['email']); }));
                
                echo json_encode([
                    'success' => true,
                    'preview' => array_slice($processed, 0, 10),
                    'total' => count($processed),
                    'stats' => ['emails_generated' => $emailCount]
                ]);
                break;
                
            case 'download_check':
                $data = loadData();
                echo json_encode([
                    'success' => $data !== null,
                    'download_ready' => $data !== null,
                    'record_count' => $data ? count($data) : 0
                ]);
                break;
                
            default:
                throw new Exception("Unknown action: $action");
        }
    } catch (Exception $e) {
        http_response_code(200); // Set 200 to ensure JSON is received
        echo json_encode([
            'success' => false, 
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }
    exit;
}

// DOWNLOAD HANDLER
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'download') {
    $data = loadData();
    
    if ($data) {
        while (ob_get_level()) ob_end_clean();
        
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="leads_' . date('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM for Excel
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['First Name', 'Last Name', 'Company', 'Email']);
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['firstName'] ?? '',
                $row['lastName'] ?? '',
                $row['company'] ?? '',
                $row['email'] ?? ''
            ]);
        }
        
        fclose($output);
    } else {
        echo '<html><body style="font-family:Arial;text-align:center;padding:50px;">
              <h2>No data available</h2>
              <p>Please generate emails first</p>
              <a href="index.html" style="color:#667eea;">← Back</a>
              </body></html>';
    }
    exit;
}

// If accessed directly, show debug info
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html');
    echo '<html><body style="font-family:Arial;padding:20px;">';
    echo '<h2>Lead Email Generator - Debug Info</h2>';
    echo '<pre>';
    echo "PHP Version: " . PHP_VERSION . "\n";
    echo "cURL Enabled: " . (function_exists('curl_init') ? 'Yes' : 'No') . "\n";
    echo "Session ID: " . session_id() . "\n";
    echo "Config File: " . (file_exists('config.php') ? 'Found' : 'Not Found') . "\n";
    echo "\nDirectories:\n";
    echo "- Upload: " . (is_dir(UPLOAD_DIR) ? 'Exists' : 'Missing') . " - " . (is_writable(UPLOAD_DIR) ? 'Writable' : 'Not Writable') . "\n";
    echo "- Temp: " . (is_dir(TEMP_DIR) ? 'Exists' : 'Missing') . " - " . (is_writable(TEMP_DIR) ? 'Writable' : 'Not Writable') . "\n";
    echo "- Log: " . (is_dir(LOG_DIR) ? 'Exists' : 'Missing') . " - " . (is_writable(LOG_DIR) ? 'Writable' : 'Not Writable') . "\n";
    echo '</pre>';
    echo '<p><a href="index.html">← Back to Application</a></p>';
    echo '</body></html>';
}
?>