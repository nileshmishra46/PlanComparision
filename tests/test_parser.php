<?php

require_once __DIR__ . '/../src/PlanParser.php';
require_once __DIR__ . '/../src/PlanComparer.php';
require_once __DIR__ . '/../src/RecommendationEngine.php';
require_once __DIR__ . '/../src/SqlFileGenerator.php';

use PlanComparator\PlanParser;
use PlanComparator\PlanComparer;
use PlanComparator\RecommendationEngine;
use PlanComparator\SqlFileGenerator;

function assertEqual($expected, $actual, $message) {
    if ($expected === $actual) {
        echo "[PASS] $message\n";
    } else {
        echo "[FAIL] $message\n";
        echo "       Expected: " . print_r($expected, true) . "\n";
        echo "       Actual:   " . print_r($actual, true) . "\n";
        exit(1);
    }
}

function assertContains($needle, array $haystack, $message) {
    if (in_array($needle, $haystack)) {
        echo "[PASS] $message\n";
    } else {
        echo "[FAIL] $message\n";
        echo "       Needle not found in array.\n";
        exit(1);
    }
}

echo "=== SQL Server Execution Plan Comparator - Verification Suite ===\n\n";

// 1. Load XML plans
$goodXml = file_get_contents(__DIR__ . '/mock_plans/good_plan.sqlplan');
$badXml = file_get_contents(__DIR__ . '/mock_plans/bad_plan.sqlplan');

$parser = new PlanParser();

// 2. Test Good Plan Parsing
echo "--- Testing Good Plan Parsing ---\n";
try {
    $goodPlan = $parser->parse($goodXml);
    assertEqual(0.0065, $goodPlan['SubtreeCost'], "Good plan cost should be 0.0065");
    assertEqual(1, $goodPlan['DOP'], "Good plan DOP should be 1");
    assertEqual("Nested Loops", $goodPlan['OperatorTree']['PhysicalOp'], "Good plan root should be Nested Loops");
    assertEqual(3, count($goodPlan['FlatOperators']), "Good plan should have exactly 3 operators");
    assertEqual("PK_Customers", $goodPlan['FlatOperators'][1]['IndexName'], "Good plan operator 1 index should be PK_Customers");
    assertEqual("IX_Orders_CustomerID", $goodPlan['FlatOperators'][2]['IndexName'], "Good plan operator 2 index should be IX_Orders_CustomerID");
} catch (Exception $e) {
    echo "[FAIL] Good Plan parsing failed: " . $e->getMessage() . "\n";
    exit(1);
}

// 3. Test Bad Plan Parsing
echo "\n--- Testing Bad Plan Parsing ---\n";
try {
    $badPlan = $parser->parse($badXml);
    assertEqual(5.256, $badPlan['SubtreeCost'], "Bad plan cost should be 5.256");
    assertEqual(4, $badPlan['DOP'], "Bad plan DOP should be 4");
    assertEqual("Hash Match", $badPlan['OperatorTree']['PhysicalOp'], "Bad plan root should be Hash Match");
    assertEqual(4, count($badPlan['FlatOperators']), "Bad plan should have exactly 4 operators");
    assertEqual("Sort", $badPlan['FlatOperators'][2]['PhysicalOp'], "Bad plan operator 2 should be Sort");
    assertEqual(true, $badPlan['FlatOperators'][2]['SpillToTempDb'], "Bad plan Sort operator should have TempDB Spill");
    
    // Test Warnings
    assertEqual(2, count($badPlan['Warnings']), "Bad plan should contain 2 statement-level warnings");
    assertEqual("ColumnsWithNoStatistics", $badPlan['Warnings'][0]['Type'], "First warning should be ColumnsWithNoStatistics");
    assertEqual("ImplicitConversion", $badPlan['Warnings'][1]['Type'], "Second warning should be ImplicitConversion");

    // Test Missing Index suggestions
    assertEqual(1, count($badPlan['MissingIndexes']), "Bad plan should have 1 missing index suggestion");
    assertEqual(85.4, $badPlan['MissingIndexes'][0]['Impact'], "Missing index impact should be 85.4%");
    assertEqual("Orders", $badPlan['MissingIndexes'][0]['Table'], "Missing index table should be Orders");
    assertEqual(['CustomerID'], $badPlan['MissingIndexes'][0]['EqualityColumns'], "Missing index key should be CustomerID");
    assertEqual(['OrderDate', 'ShipDate'], $badPlan['MissingIndexes'][0]['IncludeColumns'], "Missing index includes should be OrderDate, ShipDate");

    // Test actual runtime rows
    assertEqual(150000.0, $badPlan['FlatOperators'][3]['ActualRows'], "Table Scan actual rows should be 150000");
} catch (Exception $e) {
    echo "[FAIL] Bad Plan parsing failed: " . $e->getMessage() . "\n";
    exit(1);
}

// 4. Test Plan Comparison
echo "\n--- Testing Plan Comparison ---\n";
$comparer = new PlanComparer();
$comparison = $comparer->compare($goodPlan, $badPlan);

$costDiff = $comparison['PlanLevel']['Cost'];
assertEqual(99.88, round($costDiff['ImprovementPercent'], 2), "Estimated cost improvement should be 99.88%");
assertEqual(true, $comparison['PlanLevel']['DOP']['IsDifferent'], "DOP should be marked as different");
// Check new warnings in Bad plan (since good has 0 warnings, both are new)
assertEqual(2, count($comparison['PlanLevel']['Warnings']['NewWarningsInBad']), "Should identify 2 new warnings in Bad");

// Check operator diffs
$opDiffs = $comparison['OperatorDiffs'];
assertEqual(true, $opDiffs[0]['IsDifferent'], "Root operator (NodeId 0) should be marked different");
assertEqual("Nested Loops", $opDiffs[0]['Good']['PhysicalOp'], "Good root operator should be Nested Loops");
assertEqual("Hash Match", $opDiffs[0]['Bad']['PhysicalOp'], "Bad root operator should be Hash Match");

// Check cardinality mismatch warning at Table Scan (NodeId 3)
$tableScanDiff = $opDiffs[3];
assertEqual(true, $tableScanDiff['IsDifferent'], "Table Scan (NodeId 3) should be marked different (exists only in bad plan, or has diffs)");
// NodeId 3 exists in bad plan but not good plan (good plan has NodeId 0, 1, 2. Bad plan has NodeId 0, 1, 2, 3)
assertEqual(null, $tableScanDiff['Good'], "Good operator at NodeId 3 should be null");
assertEqual("Table Scan", $tableScanDiff['Bad']['PhysicalOp'], "Bad operator at NodeId 3 should be Table Scan");

// 5. Test Recommendation Engine
echo "\n--- Testing Recommendation Engine ---\n";
$recEngine = new RecommendationEngine();
$recs = $recEngine->generateRecommendations($comparison);

// Assert recommendations were generated
echo "Actual recommendations generated: " . count($recs) . "\n";
foreach ($recs as $r) {
    echo "  - [" . $r['Impact'] . "] " . $r['Title'] . " (Type: " . $r['Type'] . ")\n";
}
assertEqual(6, count($recs), "Should generate exactly 6 tuning recommendations");

// Assert categories
$types = array_column($recs, 'Type');
assertContains('index', $types, "Should contain an Index recommendation");
assertContains('statistics', $types, "Should contain a Statistics recommendation");
assertContains('datatype', $types, "Should contain an Implicit Conversion recommendation");
assertContains('memory', $types, "Should contain a Memory / Spill recommendation");
assertContains('query_hint', $types, "Should contain a Query Hint / Sniffing recommendation");

// 6. Test SQL Generator
echo "\n--- Testing SQL Code Generator ---\n";
$sqlGen = new SqlFileGenerator();
$sqlText = $sqlGen->generate($comparison, $recs);

assertContains("CREATE NONCLUSTERED INDEX [IX_Orders_CustomerID]", [str_contains($sqlText, "CREATE NONCLUSTERED INDEX [IX_Orders_CustomerID]") ? "CREATE NONCLUSTERED INDEX [IX_Orders_CustomerID]" : ""], "SQL file should contain CREATE INDEX statement");
assertContains("UPDATE STATISTICS [dbo].[Orders] WITH FULLSCAN", [str_contains($sqlText, "UPDATE STATISTICS [dbo].[Orders] WITH FULLSCAN") ? "UPDATE STATISTICS [dbo].[Orders] WITH FULLSCAN" : ""], "SQL file should contain UPDATE STATISTICS statement");
assertContains("OPTION (RECOMPILE)", [str_contains($sqlText, "OPTION (RECOMPILE)") ? "OPTION (RECOMPILE)" : ""], "SQL file should contain recompile option tips");

echo "\n=== ALL TESTS PASSED SUCCESSFULLY! ===\n";
