<?php
/**
 * Gemini Prompt Optimizer
 * Test and improve Gemini responses for better domain detection
 */

require_once __DIR__ . '/config.php';

class GeminiOptimizer {
    private $apiKey;
    
    public function __construct() {
        $this->apiKey = GEMINI_API_KEY;
    }
    
    /**
     * Test different prompt strategies
     */
    public function testPromptStrategies($companyName) {
        $strategies = [
            'detailed' => $this->detailedPrompt($companyName),
            'simple' => $this->simplePrompt($companyName),
            'contextual' => $this->contextualPrompt($companyName),
            'structured' => $this->structuredPrompt($companyName)
        ];
        
        $results = [];
        foreach ($strategies as $name => $prompt) {
            echo "Testing strategy: $name\n";
            $result = $this->callGemini($prompt);
            $results[$name] = $this->parseResponse($result);
            echo "  Result: " . json_encode($results[$name]) . "\n\n";
            usleep(500000); // Rate limit
        }
        
        return $results;
    }
    
    /**
     * Detailed prompt with examples
     */
    private function detailedPrompt($company) {
        return "Find the official website domain for: $company

Examples of correct responses:
- Microsoft Corporation → microsoft.com
- Google Inc → google.com
- Tesla Motors → tesla.com
- Spotify Technology SA → spotify.com

Rules:
1. Return ONLY the domain (example.com format)
2. No http://, https://, or www.
3. Use the most common/official domain
4. If uncertain, use .com

For email format, common patterns are:
- Tech companies: firstname@domain
- Corporations: firstname.lastname@domain
- German (GmbH): firstname.lastname@domain

Return JSON: {\"domain\": \"...\", \"format\": \"...\", \"confidence\": 0-100}";
    }
    
    /**
     * Simple direct prompt
     */
    private function simplePrompt($company) {
        return "What is the website domain for $company? 
Return only: {\"domain\": \"example.com\", \"format\": \"firstname.lastname\", \"confidence\": 85}";
    }
    
    /**
     * Contextual prompt with reasoning
     */
    private function contextualPrompt($company) {
        return "As a domain expert, analyze '$company':
1. Identify the company type (tech/corporate/startup)
2. Determine likely domain extension (.com/.org/.net)
3. Find the actual domain if it's a known company
4. Guess intelligently if unknown

Return: {\"domain\": \"...\", \"format\": \"...\", \"confidence\": 0-100}";
    }
    
    /**
     * Structured prompt with clear format
     */
    private function structuredPrompt($company) {
        return "Task: Find domain for company
Company: $company
Output format: JSON only
Required fields: domain, format, confidence
Domain rules: lowercase, no www/http
Format options: firstname.lastname, firstname, f.lastname
Example output: {\"domain\":\"tesla.com\",\"format\":\"firstname\",\"confidence\":95}";
    }
    
    /**
     * Call Gemini API
     */
    private function callGemini($prompt) {
        $url = GEMINI_API_ENDPOINT . '?key=' . $this->apiKey;
        
        $data = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 100
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200) {
            return json_decode($response, true);
        }
        
        return null;
    }
    
    /**
     * Parse Gemini response
     */
    private function parseResponse($response) {
        if (!$response || !isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            return ['error' => 'No response'];
        }
        
        $text = $response['candidates'][0]['content']['parts'][0]['text'];
        
        // Try to parse as JSON
        $json = json_decode($text, true);
        if ($json) {
            return $json;
        }
        
        // Try to extract domain from text
        if (preg_match('/([a-z0-9\-]+\.[a-z]{2,})/i', $text, $matches)) {
            return ['domain' => $matches[1], 'format' => 'firstname.lastname', 'confidence' => 50];
        }
        
        return ['error' => 'Could not parse response', 'raw' => $text];
    }
}

// Run optimizer
if (php_sapi_name() === 'cli') {
    echo "=================================\n";
    echo "Gemini Prompt Optimization Tool\n";
    echo "=================================\n\n";
    
    if (empty(GEMINI_API_KEY) || GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE') {
        echo "❌ Please configure your Gemini API key in config.php\n";
        exit(1);
    }
    
    $optimizer = new GeminiOptimizer();
    
    // Test companies
    $testCompanies = [
        'Microsoft Corporation',
        'Local Bakery Shop',
        'Tech Startup Labs',
        'Johnson & Johnson',
        'Müller GmbH'
    ];
    
    $bestStrategy = [];
    
    foreach ($testCompanies as $company) {
        echo "\n🔍 Testing: $company\n";
        echo str_repeat('=', 50) . "\n";
        
        $results = $optimizer->testPromptStrategies($company);
        
        // Find best result
        $best = null;
        $bestScore = 0;
        
        foreach ($results as $strategy => $result) {
            if (isset($result['domain']) && isset($result['confidence'])) {
                $score = $result['confidence'];
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $strategy;
                }
            }
        }
        
        if ($best) {
            echo "✅ Best strategy: $best (confidence: $bestScore%)\n";
            $bestStrategy[$company] = $best;
        }
    }
    
    echo "\n📊 Summary:\n";
    echo str_repeat('=', 50) . "\n";
    
    $strategyCounts = array_count_values($bestStrategy);
    arsort($strategyCounts);
    
    foreach ($strategyCounts as $strategy => $count) {
        echo "$strategy: $count companies\n";
    }
    
    echo "\n💡 Recommendation: ";
    echo "Use '" . key($strategyCounts) . "' prompt strategy for best results\n\n";
}
?>