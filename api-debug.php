<?php
/**
 * Lead Email Generator - Main Processing File
 * All backend logic using only Gemini 2.5 Flash
 */

require_once 'config.php';

class LeadProcessor {
    private $geminiKey;
    private $csvData = [];
    private $companies = [];
    
    public function __construct() {
        $this->geminiKey = GEMINI_API_KEY;
    }
    
    /**
     * Search for company domain and email format using Gemini
     */
    public function searchWithGemini($companyName) {
        if (empty($this->geminiKey)) {
            return $this->generateFallback($companyName);
        }
        
        // Try multiple endpoints if one fails
        $endpoints = [
            GEMINI_API_ENDPOINT,
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent',
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent',
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent'
        ];
        
        $endpoints = array_unique($endpoints); // Remove duplicates
        
        foreach ($endpoints as $endpoint) {
            $result = $this->callGeminiAPI($endpoint, $companyName);
            if ($result !== false) {
                return $result;
            }
        }
        
        // All endpoints failed, return fallback
        return $this->generateFallback($companyName);
    }
    
    /**
     * Call Gemini API with specific endpoint
     */
    private function callGeminiAPI($endpoint, $companyName) {
        $url = $endpoint . '?key=' . $this->geminiKey;
        
        // Single prompt for both domain and email format
        $prompt = "For the company '$companyName', determine:
1. The official website domain (format: example.com, no http/www)
2. Their typical employee email format

Common email formats:
- Tech companies: firstname@domain
- Corporations: firstname.lastname@domain
- German (GmbH): firstname.lastname@domain
- Startups: firstname@domain

Return ONLY this JSON:
{\"domain\": \"example.com\", \"format\": \"firstname.lastname\", \"confidence\": 85}

Format options: firstname.lastname, firstname, f.lastname, firstname.l, firstnamelastname, lastname.firstname, lastname, fl, firstname_lastname

Set confidence 90-100 if certain, 70-89 if confident, 50-69 if guessing, 30-49 if unsure.";
        
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
            CURLOPT_SSL_VERIFYHOST => false,  // Added for SSL issues
            CURLOPT_TIMEOUT => 30,  // Increased timeout
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true  // Follow redirects
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
        
        // Log error for debugging
        if ($error) {
            error_log("Gemini API Error for $endpoint: $error");
        } elseif ($httpCode != 200) {
            error_log("Gemini API HTTP $httpCode for $endpoint");
        }
        
        return false;  // Signal to try next endpoint
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
            throw new Exception("File not found");
        }
        
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);
        
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
        $fields = ['company_name', 'Company', 'company', 'CompanyName'];
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
        $fields = ['firstName', 'FirstName', 'first_name', 'fname', 'First Name'];
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
        $fields = ['lastName', 'LastName', 'last_name', 'lname', 'Last Name'];
        foreach ($fields as $field) {
            if (isset($record[$field]) && !empty(trim($record[$field]))) {
                return trim($record[$field]);
            }
        }
        return '';
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

// Helper functions for data persistence
function saveData($data) {
    $file = TEMP_DIR . 'data_' . session_id() . '_' . time() . '.json';
    file_put_contents($file, json_encode($data));
    $_SESSION['data_file'] = $file;
    return $file;
}

function loadData() {
    // Try session
    if (isset($_SESSION['processed_data'])) {
        return $_SESSION['processed_data'];
    }
    
    // Try file
    if (isset($_SESSION['data_file']) && file_exists($_SESSION['data_file'])) {
        return json_decode(file_get_contents($_SESSION['data_file']), true);
    }
    
    // Look for recent files
    $files = glob(TEMP_DIR . 'data_' . session_id() . '_*.json');
    if ($files) {
        usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
        return json_decode(file_get_contents($files[0]), true);
    }
    
    return null;
}

// API HANDLERS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        $processor = new LeadProcessor();
        
        switch($action) {
            case 'check_api':
                $configured = !empty(GEMINI_API_KEY) && GEMINI_API_KEY !== 'YOUR_API_KEY';
                
                // Test actual API connection if configured
                if ($configured) {
                    $testUrl = GEMINI_API_ENDPOINT . '?key=' . GEMINI_API_KEY;
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
                        CURLOPT_TIMEOUT => 10
                    ]);
                    
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $error = curl_error($ch);
                    curl_close($ch);
                    
                    $working = ($httpCode == 200);
                    
                    echo json_encode([
                        'success' => true,
                        'api_available' => $working,
                        'http_code' => $httpCode,
                        'error' => $error ?: null
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'api_available' => false,
                        'error' => 'API key not configured'
                    ]);
                }
                break;
                
            case 'upload':
                if (isset($_FILES['csv'])) {
                    $uploadFile = UPLOAD_DIR . time() . '_' . basename($_FILES['csv']['name']);
                    if (move_uploaded_file($_FILES['csv']['tmp_name'], $uploadFile)) {
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
                            'companies' => $companies
                        ]);
                    }
                }
                break;
                
            case 'search_online':
                $company = $_POST['company'] ?? '';
                $result = $processor->searchWithGemini($company);
                usleep(API_RATE_LIMIT);
                echo json_encode(array_merge(['success' => true], $result));
                break;
                
            case 'generate':
                if (isset($_SESSION['csv_file'])) {
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
                }
                break;
                
            case 'download_check':
                $data = loadData();
                echo json_encode([
                    'success' => $data !== null,
                    'download_ready' => $data !== null,
                    'record_count' => $data ? count($data) : 0
                ]);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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
?>