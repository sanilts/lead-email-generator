<?php
/**
 * API Test Script - Verify your setup is working correctly
 * Run this file to test API connections before processing leads
 */

require_once 'config.php';
require_once 'process.php';

echo "====================================\n";
echo "Lead Email Generator - API Test Tool\n";
echo "====================================\n\n";

// Test companies for verification
$testCompanies = [
    'Microsoft Corporation',
    'Google Inc',
    'Apple Inc',
    'Tesla Motors',
    'Amazon Web Services'
];

// Initialize generator
$generator = new LeadEmailGenerator();

// Check API keys
echo "1. Checking API Configuration...\n";
echo "--------------------------------\n";

$geminiConfigured = !empty(GEMINI_API_KEY) && GEMINI_API_KEY !== 'YOUR_GEMINI_API_KEY_HERE';
$hunterConfigured = !empty(HUNTER_API_KEY);
$clearbitConfigured = !empty(CLEARBIT_API_KEY);

echo "✓ Gemini API: " . ($geminiConfigured ? "Configured" : "Not configured") . "\n";
echo "✓ Hunter.io API: " . ($hunterConfigured ? "Configured" : "Not configured") . "\n";
echo "✓ Clearbit API: " . ($clearbitConfigured ? "Configured" : "Not configured") . "\n\n";

if (!$geminiConfigured && !$hunterConfigured && !$clearbitConfigured) {
    echo "⚠️  WARNING: No APIs configured. Please add at least one API key to config.php\n";
    echo "Recommended: Add your Gemini API key (free) from https://makersuite.google.com/app/apikey\n\n";
}

// Test Gemini API
if ($geminiConfigured) {
    echo "2. Testing Gemini 2.5 Flash API...\n";
    echo "-----------------------------------\n";
    
    $testCompany = 'Microsoft Corporation';
    echo "Testing with: $testCompany\n";
    
    $domain = $generator->searchDomainWithGemini($testCompany);
    if ($domain) {
        echo "✅ SUCCESS: Found domain: $domain\n";
        
        $format = $generator->searchEmailFormatWithGemini($testCompany, $domain);
        echo "✅ Email format: $format\n";
    } else {
        echo "❌ FAILED: Could not retrieve domain. Check your API key.\n";
    }
    echo "\n";
}

// Test Hunter.io API
if ($hunterConfigured) {
    echo "3. Testing Hunter.io API...\n";
    echo "---------------------------\n";
    
    $testCompany = 'Google';
    echo "Testing with: $testCompany\n";
    
    $result = $generator->searchWithHunter($testCompany);
    if ($result) {
        echo "✅ SUCCESS: Found domain: {$result['domain']}\n";
        echo "✅ Email format: {$result['format']}\n";
        echo "✅ Confidence: {$result['confidence']}%\n";
    } else {
        echo "❌ FAILED: Could not retrieve data. Check your API key.\n";
    }
    echo "\n";
}

// Test Clearbit API
if ($clearbitConfigured) {
    echo "4. Testing Clearbit API...\n";
    echo "--------------------------\n";
    
    $testCompany = 'Apple Inc';
    echo "Testing with: $testCompany\n";
    
    $result = $generator->searchWithClearbit($testCompany);
    if ($result) {
        echo "✅ SUCCESS: Found domain: {$result['domain']}\n";
        echo "✅ Confidence: {$result['confidence']}%\n";
    } else {
        echo "❌ FAILED: Could not retrieve data. Check your API key.\n";
    }
    echo "\n";
}

// Test full search pipeline
echo "5. Testing Complete Search Pipeline...\n";
echo "--------------------------------------\n";

foreach ($testCompanies as $company) {
    echo "\nSearching: $company\n";
    $result = $generator->searchCompanyOnline($company);
    
    if ($result['domain']) {
        echo "  ✓ Domain: {$result['domain']}\n";
        echo "  ✓ Format: {$result['format']}\n";
        echo "  ✓ Confidence: {$result['confidence']}%\n";
        echo "  ✓ Source: {$result['source']}\n";
    } else {
        echo "  ✗ No domain found\n";
    }
    
    // Rate limiting
    sleep(1);
}

// Test email generation
echo "\n6. Testing Email Generation...\n";
echo "-------------------------------\n";

$testCases = [
    ['firstName' => 'John', 'lastName' => 'Smith', 'domain' => 'example.com'],
    ['firstName' => 'María', 'lastName' => 'García', 'domain' => 'company.es'],
    ['firstName' => 'François', 'lastName' => 'Müller', 'domain' => 'firma.de'],
];

foreach ($testCases as $test) {
    $formats = [
        'firstname.lastname',
        'f.lastname',
        'firstname',
        'firstnamelastname'
    ];
    
    echo "\nName: {$test['firstName']} {$test['lastName']} @ {$test['domain']}\n";
    
    foreach ($formats as $format) {
        $email = $generator->generateEmail(
            $test['firstName'],
            $test['lastName'],
            $test['domain'],
            $format
        );
        echo "  $format: $email\n";
    }
}

// Performance test
echo "\n7. Performance Test...\n";
echo "----------------------\n";

$startTime = microtime(true);
$testCompany = 'Tesla Inc';

echo "Testing search speed for: $testCompany\n";
$result = $generator->searchCompanyOnline($testCompany);
$endTime = microtime(true);

$duration = round($endTime - $startTime, 2);
echo "Search completed in: {$duration} seconds\n";

if ($result['domain']) {
    echo "Result: {$result['domain']} (Source: {$result['source']})\n";
}

// Summary
echo "\n====================================\n";
echo "TEST SUMMARY\n";
echo "====================================\n";

$apiCount = ($geminiConfigured ? 1 : 0) + ($hunterConfigured ? 1 : 0) + ($clearbitConfigured ? 1 : 0);

echo "APIs Configured: $apiCount\n";
echo "APIs Tested: $apiCount\n";

if ($apiCount > 0) {
    echo "\n✅ Your setup is ready to process leads!\n";
    echo "Upload your CSV file through the web interface to begin.\n";
} else {
    echo "\n⚠️  Please configure at least one API in config.php\n";
    echo "Minimum requirement: Gemini API key (free)\n";
    echo "Get your key at: https://makersuite.google.com/app/apikey\n";
}

echo "\n";

// Optional: Test with actual CSV file
if (isset($argv[1])) {
    $csvFile = $argv[1];
    if (file_exists($csvFile)) {
        echo "\n8. Testing with CSV file: $csvFile\n";
        echo "------------------------------------\n";
        
        try {
            $generator->parseCSV($csvFile);
            $stats = $generator->getStats();
            
            echo "✓ CSV parsed successfully\n";
            echo "  Total leads: {$stats['total_leads']}\n";
            echo "  Unique companies: {$stats['unique_companies']}\n";
            
            // Test first 3 companies
            $companies = $generator->getCompanies();
            $testCount = min(3, count($companies));
            
            echo "\nTesting first $testCount companies:\n";
            for ($i = 0; $i < $testCount; $i++) {
                $company = $companies[$i];
                echo "\n  {$company['name']} ({$company['lead_count']} leads)\n";
                
                $result = $generator->searchCompanyOnline($company['name']);
                echo "    Domain: {$result['domain']}\n";
                echo "    Confidence: {$result['confidence']}%\n";
                
                sleep(1);
            }
        } catch (Exception $e) {
            echo "❌ Error parsing CSV: " . $e->getMessage() . "\n";
        }
    } else {
        echo "❌ CSV file not found: $csvFile\n";
    }
}

echo "\n=== Test Complete ===\n";
?>