<?php
// Simple verification script for ConfidenceCalculator
// Run with: php scripts/test_confidence_calculator.php

declare(strict_types=1);

$root = dirname(__DIR__);

// Simple autoloader for testing
spl_autoload_register(function (string $class) {
    $prefixes = [
        'App\\Services\\' => '/app/Services/',
        'App\\DTO\\' => '/app/DTO/',
    ];
    foreach ($prefixes as $prefix => $path) {
        if (strpos($class, $prefix) === 0) {
            $relative = substr($class, strlen($prefix));
            $file = dirname(__DIR__) . $path . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }
});

use App\Services\ConfidenceCalculator;

function test_assert(bool $condition, string $message): void {
    if ($condition) {
        echo "  PASS: {$message}\n";
    } else {
        echo "  FAIL: {$message}\n";
    }
}

echo "=== ConfidenceCalculator Tests ===\n\n";

$calculator = new ConfidenceCalculator();

// Test 1: Calculate with all fields
echo "Test 1: Calculate with all fields\n";
$fieldConfidences = [
    'contract_no' => 95,
    'amount' => 90,
    'signed_date' => 92,
    'customer_name' => 88,
    'signer_party' => 91,
    'contract_name' => 85,
    'signer_name' => 80,
    'phone' => 75,
    'effective_date' => 70,
    'expiry_date' => 68,
    'payment_type' => 95,
];
$overall = $calculator->calculate($fieldConfidences);
test_assert($overall > 85, "Overall > 85 (got {$overall})");
test_assert($overall < 95, "Overall < 95 (got {$overall})");

// Test 2: Calculate with missing fields
echo "\nTest 2: Calculate with missing fields\n";
$fieldConfidences = [
    'contract_no' => 90,
    'amount' => 85,
];
$overall = $calculator->calculate($fieldConfidences);
test_assert($overall > 80, "Overall > 80 (got {$overall})");

// Test 3: Calculate with empty array
echo "\nTest 3: Calculate with empty array\n";
$overall = $calculator->calculate([]);
test_assert($overall === 0.0, "Overall === 0.0 (got {$overall})");

// Test 4: Confidence meets target
echo "\nTest 4: Confidence meets target\n";
$fieldConfidences = [
    'contract_no' => 95,
    'amount' => 92,
    'signed_date' => 90,
    'customer_name' => 93,
    'signer_party' => 91,
];
test_assert($calculator->meetsTarget($fieldConfidences, 90.0), "Meets target 90.0");

// Test 5: Confidence below target
echo "\nTest 5: Confidence below target\n";
$fieldConfidences = [
    'contract_no' => 70,
    'amount' => 65,
    'signed_date' => 60,
];
test_assert(!$calculator->meetsTarget($fieldConfidences, 90.0), "Does not meet target 90.0");

// Test 6: Custom weights
echo "\nTest 6: Custom weights\n";
$customWeights = [
    'contract_no' => 2.0,
    'amount' => 2.0,
];
$calculator = new ConfidenceCalculator($customWeights);
$fieldConfidences = [
    'contract_no' => 100,
    'amount' => 100,
];
$overall = $calculator->calculate($fieldConfidences);
test_assert($overall === 100.0, "Overall === 100.0 (got {$overall})");

echo "\n=== All tests completed ===\n";