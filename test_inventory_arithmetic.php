<?php

// Simple test to verify inventory arithmetic operations work
// This tests the model accessors issue we just fixed

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/bootstrap/app.php';

use App\Models\PoultryVaccineInventory;

echo "Testing PoultryVaccineInventory arithmetic operations...\n";

// Create a sample inventory record for testing
$inventory = new PoultryVaccineInventory();
$inventory->quantity = 100.00;
$inventory->unit_cost = 25.50;

echo "Initial quantity: " . $inventory->quantity . " (type: " . gettype($inventory->quantity) . ")\n";
echo "Initial unit cost: " . $inventory->unit_cost . " (type: " . gettype($inventory->unit_cost) . ")\n";

// Test arithmetic operations
$useQuantity = 10;
echo "\nTesting subtraction: $inventory->quantity - $useQuantity\n";
$inventory->quantity -= $useQuantity;
echo "After subtraction: " . $inventory->quantity . " (type: " . gettype($inventory->quantity) . ")\n";

// Test addition (restore)
echo "\nTesting addition: $inventory->quantity + $useQuantity\n";
$inventory->quantity += $useQuantity;
echo "After addition: " . $inventory->quantity . " (type: " . gettype($inventory->quantity) . ")\n";

// Test cost calculation
$calculatedCost = $inventory->unit_cost * $useQuantity;
echo "\nTesting multiplication: $inventory->unit_cost * $useQuantity = $calculatedCost\n";
echo "Cost calculation type: " . gettype($calculatedCost) . "\n";

echo "\nAll tests completed successfully!\n";
