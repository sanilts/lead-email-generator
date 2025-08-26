<?php
/**
 * Lead Email Generator - Gemini 2.5 Flash Only Version
 * Simplified to use only Google's Gemini AI for all detection
 */

// Include configuration first
require_once __DIR__ . '/config.php';

// Session is already started in config.php, don't start again
// Just verify it's active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class LeadEmailGenerator {
    private $csvData = [];
    private $companies = [];
    private $processedData = [];
    private $excludedCompanies = [];
    private $apiCache = [];
    private $geminiApiKey;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->geminiApiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    }
    
    /**
     * Comprehensive transliteration map
     */
    private $transliterationMap = [
        // German
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
        
        // French
        'à' => 'a', 'â' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e',
        'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o',
        'À' => 'A', 'Â' => 'A', 'Ç' => 'C', 'È' => 'E', 'É' => 'E',
        
        // Spanish/Italian/Portuguese
        'á' => 'a', 'í' => 'i', 'ñ' => 'n', 'ó' => 'o', 'ú' => 'u',
        'ã' => 'a', 'õ' => 'o', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        
        // Scandinavian
        'å' => 'aa', 'æ' => 'ae', 'ø' => 'oe',
        
        // Polish/Czech
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
        'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'č' => 'c', 'š' => 's', 'ž' => 'z'
    ];
    
    /**
     * Enhanced Gemini search for company domain and email format
     */
    public function searchCompanyWithGemini($companyName) {
        if (empty($this->geminiApiKey)) {
            return [
                'domain' => $this->generateFallbackDomain($companyName),
                'format' => 'firstname.lastname',
                'confidence' => 20,
                'source' => 'local'
            ];
        }
        
        // Check cache first
        $cacheKey = md5($companyName);
        if (isset($this->apiCache[$cacheKey])) {
            return $this->apiCache[$cacheKey];
        }
        
        $url = GEMINI_API_ENDPOINT . '?key=' . $this->geminiApiKey;
        
        // Enhanced prompt for better results
        $prompt = "You are an expert at finding company websites and email formats. For the company '{$companyName}', provide:

1. The official website domain (just the domain like example.com, without http/https/www)
2. The email format they typically use for employees

Analyze the company name and industry context:
- If it's a tech company, they often use firstname@ or firstname.lastname@
- German companies (GmbH, AG) typically use firstname.lastname@
- Startups often use firstname@
- Large corporations use firstname.lastname@ or f.lastname@
- If company has .com, .org, .net in name, preserve it

Return your answer in exactly this JSON format:
{
  \"domain\": \"example.com\",
  \"format\": \"firstname.lastname\",
  \"confidence\": 85
}

Where format must be one of: firstname.lastname, f.lastname, firstname.l, firstnamelastname, lastname.firstname, firstname, lastname, fl, firstname_lastname

Confidence should be:
- 90-100 if you're certain about the domain
- 70-89 if you're fairly confident
- 50-69 if it's an educated guess
- 30-49 if you're unsure

If you cannot find the domain, make your best guess based on the company name.";
        
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'topK' => 20,
                'topP' => 0.8,
                'maxOutputTokens' => 200,
                'responseMimeType' => 'application/json'
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        logAPICall($companyName, $httpCode == 200);
        
        if ($httpCode == 200 && $response) {
            $result = json_decode($response, true);
            
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $jsonText = $result['candidates'][0]['content']['parts'][0]['text'];
                
                // Try to parse the JSON response
                $parsedData = json_decode($jsonText, true);
                
                if ($parsedData && isset($parsedData['domain'])) {
                    $domain = $this->cleanDomain($parsedData['domain']);
                    $format = $this->validateFormat($parsedData['format'] ?? 'firstname.lastname');
                    $confidence = min(100, max(0, intval($parsedData['confidence'] ?? 70)));
                    
                    $result = [
                        'domain' => $domain,
                        'format' => $format,
                        'confidence' => $confidence,
                        'source' => 'gemini'
                    ];
                    
                    $this->apiCache[$cacheKey] = $result;
                    return $result;
                }
            }
        }
        
        // Fallback if API fails
        return [
            'domain' => $this->generateFallbackDomain($companyName),
            'format' => $this->detectFormatFromName($companyName),
            'confidence' => 30,
            'source' => 'fallback'
        ];
    }
    
    /**
     * Clean and validate domain
     */
    private function cleanDomain($domain) {
        $domain = strtolower(trim($domain));
        $domain = str_replace(['http://', 'https://', 'www.'], '', $domain);
        $domain = rtrim($domain, '/');
        
        // Validate domain format
        if (!preg_match('/^[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,}$/i', $domain)) {
            // Try to fix common issues
            $domain = preg_replace('/[^a-z0-9\.\-]/i', '', $domain);
            if (!strpos($domain, '.')) {
                $domain .= '.com';
            }
        }
        
        return $domain;
    }
    
    /**
     * Validate email format
     */
    private function validateFormat($format) {
        $validFormats = [
            'firstname.lastname', 'f.lastname', 'firstname.l', 
            'firstnamelastname', 'lastname.firstname', 'firstname', 
            'lastname', 'fl', 'firstname_lastname'
        ];
        
        return in_array($format, $validFormats) ? $format : 'firstname.lastname';
    }
    
    /**
     * Generate fallback domain from company name
     */
    private function generateFallbackDomain($companyName) {
        $cleanName = strtolower(trim($companyName));
        
        // Remove common suffixes
        $suffixes = ['inc', 'incorporated', 'llc', 'ltd', 'limited', 'corp', 'corporation', 
                     'gmbh', 'ag', 'sa', 'sas', 'spa', 'bv', 'plc', 'pty', 'pvt'];
        
        foreach ($suffixes as $suffix) {
            $cleanName = preg_replace('/\b' . $suffix . '\b\.?/i', '', $cleanName);
        }
        
        // Clean and simplify
        $cleanName = preg_replace('/[^a-z0-9\s]/i', '', $cleanName);
        $cleanName = preg_replace('/\s+/', '', $cleanName);
        
        if (empty($cleanName)) {
            return 'example.com';
        }
        
        // Take first word or first 20 characters
        if (strlen($cleanName) > 20) {
            $words = explode(' ', trim($companyName));
            $cleanName = preg_replace('/[^a-z0-9]/i', '', strtolower($words[0]));
        }
        
        return $cleanName . '.com';
    }
    
    /**
     * Detect email format from company name/type
     */
    private function detectFormatFromName($companyName) {
        $lowerName = strtolower($companyName);
        
        // German companies
        if (preg_match('/\b(gmbh|ag)\b/i', $lowerName)) {
            return 'firstname.lastname';
        }
        
        // Tech/Startup indicators
        if (preg_match('/\b(tech|digital|app|software|startup|labs?)\b/i', $lowerName)) {
            return 'firstname';
        }
        
        // Professional services
        if (preg_match('/\b(consulting|law|legal|financial|bank|insurance)\b/i', $lowerName)) {
            return 'f.lastname';
        }
        
        // Default
        return 'firstname.lastname';
    }
    
    /**
     * Simplified search function
     */
    public function searchCompanyOnline($companyName) {
        return $this->searchCompanyWithGemini($companyName);
    }
    
    /**
     * Transliterate text to ASCII
     */
    public function transliterate($text) {
        $text = strtr($text, $this->transliterationMap);
        $text = preg_replace('/[^\x00-\x7F]/', '', $text);
        return $text;
    }
    
    /**
     * Parse CSV file
     */
    public function parseCSV($filePath) {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }
        
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception("Cannot open file: $filePath");
        }
        
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            throw new Exception("Invalid CSV file format");
        }
        
        $headers = array_map('trim', $headers);
        
        $this->csvData = [];
        $this->companies = [];
        
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) === count($headers)) {
                $record = array_combine($headers, $row);
                if ($record) {
                    $this->csvData[] = $record;
                    
                    $companyName = $this->getCompanyName($record);
                    if ($companyName) {
                        if (!isset($this->companies[$companyName])) {
                            $this->companies[$companyName] = [];
                        }
                        $this->companies[$companyName][] = $record;
                    }
                }
            }
        }
        
        fclose($handle);
        return true;
    }
    
    /**
     * Get company name from record
     */
    private function getCompanyName($record) {
        $possibleFields = ['company_name', 'Company', 'company', 'CompanyName'];
        foreach ($possibleFields as $field) {
            if (isset($record[$field]) && !empty(trim($record[$field]))) {
                return trim($record[$field]);
            }
        }
        return null;
    }
    
    /**
     * Get first name from record
     */
    private function getFirstName($record) {
        $possibleFields = ['firstName', 'FirstName', 'first_name', 'fname'];
        foreach ($possibleFields as $field) {
            if (isset($record[$field]) && !empty(trim($record[$field]))) {
                return trim($record[$field]);
            }
        }
        return '';
    }
    
    /**
     * Get last name from record
     */
    private function getLastName($record) {
        $possibleFields = ['lastName', 'LastName', 'last_name', 'lname'];
        foreach ($possibleFields as $field) {
            if (isset($record[$field]) && !empty(trim($record[$field]))) {
                return trim($record[$field]);
            }
        }
        return '';
    }
    
    /**
     * Generate email based on format
     */
    public function generateEmail($firstName, $lastName, $domain, $format) {
        $firstName = strtolower($this->transliterate($firstName));
        $lastName = strtolower($this->transliterate($lastName));
        
        $firstName = preg_replace('/[^a-z]/', '', $firstName);
        $lastName = preg_replace('/[^a-z]/', '', $lastName);
        
        if (empty($firstName) || empty($lastName) || empty($domain)) {
            return '';
        }
        
        switch($format) {
            case 'firstname.lastname':
                return "{$firstName}.{$lastName}@{$domain}";
            case 'f.lastname':
                return "{$firstName[0]}.{$lastName}@{$domain}";
            case 'firstname.l':
                return "{$firstName}.{$lastName[0]}@{$domain}";
            case 'firstnamelastname':
                return "{$firstName}{$lastName}@{$domain}";
            case 'lastname.firstname':
                return "{$lastName}.{$firstName}@{$domain}";
            case 'firstname':
                return "{$firstName}@{$domain}";
            case 'lastname':
                return "{$lastName}@{$domain}";
            case 'fl':
                return "{$firstName[0]}{$lastName[0]}@{$domain}";
            case 'firstname_lastname':
                return "{$firstName}_{$lastName}@{$domain}";
            default:
                return "{$firstName}.{$lastName}@{$domain}";
        }
    }
    
    /**
     * Process leads
     */
    public function processLeads($domainMappings, $excludedCompanies = []) {
        $this->processedData = [];
        $this->excludedCompanies = $excludedCompanies;
        
        foreach ($this->companies as $companyName => $leads) {
            if (in_array($companyName, $excludedCompanies)) {
                continue;
            }
            
            $domain = isset($domainMappings[$companyName]['domain']) 
                ? $domainMappings[$companyName]['domain'] : '';
            $format = isset($domainMappings[$companyName]['format']) 
                ? $domainMappings[$companyName]['format'] : 'firstname.lastname';
            
            foreach ($leads as $lead) {
                $firstName = $this->getFirstName($lead);
                $lastName = $this->getLastName($lead);
                
                $email = $this->generateEmail($firstName, $lastName, $domain, $format);
                
                $this->processedData[] = [
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'company' => $companyName,
                    'email' => $email
                ];
            }
        }
        
        return $this->processedData;
    }
    
    /**
     * Export to CSV
     */
    public function exportToCSV($filename = null) {
        if (!$filename) {
            $filename = 'enhanced_leads_' . date('Y-m-d') . '.csv';
        }
        
        // Clear any previous output
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        
        $output = fopen('php://output', 'w');
        
        fputcsv($output, ['First Name', 'Last Name', 'Company', 'Email']);
        
        foreach ($this->processedData as $row) {
            fputcsv($output, [
                $row['firstName'],
                $row['lastName'],
                $row['company'],
                $row['email']
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Get statistics
     */
    public function getStats() {
        $totalLeads = count($this->csvData);
        $uniqueCompanies = count($this->companies);
        $excludedCount = count($this->excludedCompanies);
        $processedCompanies = $uniqueCompanies - $excludedCount;
        
        $emailsGenerated = 0;
        if (!empty($this->processedData)) {
            $emailsGenerated = count(array_filter($this->processedData, function($row) {
                return !empty($row['email']);
            }));
        }
        
        return [
            'total_leads' => $totalLeads,
            'unique_companies' => $uniqueCompanies,
            'processed_companies' => $processedCompanies,
            'excluded_companies' => $excludedCount,
            'emails_generated' => $emailsGenerated
        ];
    }
    
    /**
     * Get company list
     */
    public function getCompanies() {
        $result = [];
        foreach ($this->companies as $name => $leads) {
            $result[] = [
                'name' => $name,
                'lead_count' => count($leads)
            ];
        }
        return $result;
    }
}

// API Endpoint Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $generator = new LeadEmailGenerator();
        
        // Handle API status check
        if (isset($_POST['action']) && $_POST['action'] === 'check_api') {
            $geminiConfigured = !empty(GEMINI_API_KEY) && GEMINI_API_KEY !== 'YOUR_GEMINI_API_KEY_HERE';
            
            echo json_encode([
                'success' => true,
                'api_available' => $geminiConfigured,
                'api_working' => $geminiConfigured,
                'apis' => [
                    'gemini' => $geminiConfigured,
                    'hunter' => false,
                    'clearbit' => false
                ]
            ]);
            exit;
        }
        
        // Handle file upload
        if (isset($_FILES['csv']) && isset($_POST['action']) && $_POST['action'] === 'upload') {
            $uploadDir = UPLOAD_DIR;
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $uploadFile = $uploadDir . 'upload_' . time() . '_' . basename($_FILES['csv']['name']);
            
            if (move_uploaded_file($_FILES['csv']['tmp_name'], $uploadFile)) {
                $generator->parseCSV($uploadFile);
                
                $_SESSION['csv_file'] = $uploadFile;
                $_SESSION['generator'] = serialize($generator);
                
                echo json_encode([
                    'success' => true,
                    'stats' => $generator->getStats(),
                    'companies' => $generator->getCompanies()
                ]);
            } else {
                throw new Exception('File upload failed');
            }
        }
        
        // Handle online search for single company
        if (isset($_POST['action']) && $_POST['action'] === 'search_online' && isset($_POST['company'])) {
            if (isset($_SESSION['generator'])) {
                $generator = unserialize($_SESSION['generator']);
                $companyName = $_POST['company'];
                
                $result = $generator->searchCompanyOnline($companyName);
                
                echo json_encode([
                    'success' => true,
                    'domain' => $result['domain'],
                    'format' => $result['format'],
                    'confidence' => $result['confidence'],
                    'source' => $result['source']
                ]);
            } else {
                throw new Exception('No CSV file uploaded');
            }
        }
        
        // Handle batch online search
        if (isset($_POST['action']) && $_POST['action'] === 'search_all_online') {
            if (isset($_SESSION['generator'])) {
                $generator = unserialize($_SESSION['generator']);
                $companies = $generator->getCompanies();
                
                $results = [];
                foreach ($companies as $company) {
                    $searchResult = $generator->searchCompanyOnline($company['name']);
                    $results[$company['name']] = $searchResult;
                    
                    // Rate limiting
                    enforceRateLimit();
                }
                
                echo json_encode([
                    'success' => true,
                    'results' => $results
                ]);
            } else {
                throw new Exception('No CSV file uploaded');
            }
        }
        
        // Handle email generation
        if (isset($_POST['action']) && $_POST['action'] === 'generate' && isset($_POST['mappings'])) {
            if (isset($_SESSION['csv_file']) && file_exists($_SESSION['csv_file'])) {
                $generator->parseCSV($_SESSION['csv_file']);
                
                $mappings = json_decode($_POST['mappings'], true);
                $excludedCompanies = isset($_POST['excluded']) ? 
                    json_decode($_POST['excluded'], true) : [];
                
                $generator->processLeads($mappings, $excludedCompanies);
                
                // Store processed data in session (handle large data)
                $_SESSION['processed_data'] = $generator->processedData;
                
                // Also save to a temporary file as backup
                $tempFile = UPLOAD_DIR . 'processed_' . session_id() . '.json';
                file_put_contents($tempFile, json_encode($generator->processedData));
                $_SESSION['processed_file'] = $tempFile;
                
                echo json_encode([
                    'success' => true,
                    'preview' => array_slice($generator->processedData, 0, 10),
                    'total' => count($generator->processedData),
                    'stats' => $generator->getStats()
                ]);
            } else {
                throw new Exception('No CSV file uploaded or file missing');
            }
        }
        
        // Handle download request check via POST
        if (isset($_POST['action']) && $_POST['action'] === 'download_check') {
            $processedData = null;
            $dataFound = false;
            
            // Debug session
            error_log("Session ID: " . session_id());
            error_log("Session processed_data exists: " . (isset($_SESSION['processed_data']) ? 'yes' : 'no'));
            error_log("Session processed_file exists: " . (isset($_SESSION['processed_file']) ? 'yes' : 'no'));
            
            // Try to get data from session first
            if (isset($_SESSION['processed_data']) && !empty($_SESSION['processed_data'])) {
                $processedData = $_SESSION['processed_data'];
                $dataFound = true;
                error_log("Found data in session: " . count($processedData) . " records");
            } 
            // Try to get from temp file as backup
            elseif (isset($_SESSION['processed_file']) && file_exists($_SESSION['processed_file'])) {
                $jsonData = file_get_contents($_SESSION['processed_file']);
                $processedData = json_decode($jsonData, true);
                if ($processedData) {
                    // Restore to session
                    $_SESSION['processed_data'] = $processedData;
                    $dataFound = true;
                    error_log("Found data in file: " . count($processedData) . " records");
                }
            }
            // Try to find any processed file for this session
            else {
                $possibleFile = UPLOAD_DIR . 'processed_' . session_id() . '.json';
                if (file_exists($possibleFile)) {
                    $jsonData = file_get_contents($possibleFile);
                    $processedData = json_decode($jsonData, true);
                    if ($processedData) {
                        $_SESSION['processed_data'] = $processedData;
                        $_SESSION['processed_file'] = $possibleFile;
                        $dataFound = true;
                        error_log("Found data in fallback file: " . count($processedData) . " records");
                    }
                }
            }
            
            if ($dataFound && !empty($processedData)) {
                echo json_encode([
                    'success' => true,
                    'download_ready' => true,
                    'record_count' => count($processedData),
                    'session_id' => session_id()
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'No processed data available',
                    'session_id' => session_id(),
                    'debug' => [
                        'session_has_data' => isset($_SESSION['processed_data']),
                        'session_has_file' => isset($_SESSION['processed_file']),
                        'file_exists' => isset($_SESSION['processed_file']) ? file_exists($_SESSION['processed_file']) : false
                    ]
                ]);
            }
            exit;
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

// Handle download (GET request)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'download') {
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $processedData = null;
    
    // Try to get data from session first
    if (isset($_SESSION['processed_data']) && !empty($_SESSION['processed_data'])) {
        $processedData = $_SESSION['processed_data'];
    } 
    // Try to get from temp file as backup
    elseif (isset($_SESSION['processed_file']) && file_exists($_SESSION['processed_file'])) {
        $jsonData = file_get_contents($_SESSION['processed_file']);
        $processedData = json_decode($jsonData, true);
    }
    
    if ($processedData && !empty($processedData)) {
        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="enhanced_leads_' . date('Y-m-d_His') . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Add BOM for Excel UTF-8 compatibility
        echo "\xEF\xBB\xBF";
        
        // Open output stream
        $output = fopen('php://output', 'w');
        
        // Write headers
        fputcsv($output, ['First Name', 'Last Name', 'Company', 'Email']);
        
        // Write data
        foreach ($processedData as $row) {
            fputcsv($output, [
                $row['firstName'] ?? '',
                $row['lastName'] ?? '',
                $row['company'] ?? '',
                $row['email'] ?? ''
            ]);
        }
        
        fclose($output);
        
        // Clean up temp file if exists
        if (isset($_SESSION['processed_file']) && file_exists($_SESSION['processed_file'])) {
            @unlink($_SESSION['processed_file']);
        }
        
        exit;
    } else {
        // Return error page
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html>
<html>
<head>
    <title>Download Error</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; }
        .btn { background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="error">
        <h2>⚠️ No Data Available for Download</h2>
        <p>The processed data is no longer available. This can happen if:</p>
        <ul>
            <li>Your session has expired</li>
            <li>You haven\'t generated emails yet</li>
            <li>The browser was closed or refreshed</li>
        </ul>
        <p>Please go back and generate the emails again.</p>
        <a href="index.html" class="btn">← Go Back</a>
    </div>
</body>
</html>';
        exit;
    }
}

// Command line usage
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $generator = new LeadEmailGenerator();
    $generator->parseCSV($argv[1]);
    
    echo "Searching for company domains using Gemini AI...\n";
    $companies = $generator->getCompanies();
    $mappings = [];
    
    foreach ($companies as $company) {
        $result = $generator->searchCompanyOnline($company['name']);
        echo "{$company['name']}: {$result['domain']} ({$result['confidence']}% confidence)\n";
        
        $mappings[$company['name']] = [
            'domain' => $result['domain'],
            'format' => $result['format']
        ];
        
        enforceRateLimit();
    }
    
    $generator->processLeads($mappings);
    $generator->exportToCSV('output.csv');
    
    echo "Processing complete. Stats:\n";
    print_r($generator->getStats());
}
?>