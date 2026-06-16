<?php

require_once __DIR__ . '/../src/PlanParser.php';
require_once __DIR__ . '/../src/PlanComparer.php';
require_once __DIR__ . '/../src/RecommendationEngine.php';
require_once __DIR__ . '/../src/SqlFileGenerator.php';

use PlanComparator\PlanParser;
use PlanComparator\PlanComparer;
use PlanComparator\RecommendationEngine;
use PlanComparator\SqlFileGenerator;

$goodXml = file_get_contents(__DIR__ . '/../tests/mock_plans/good_plan_v2.sqlplan');
$badXml = file_get_contents(__DIR__ . '/../tests/mock_plans/bad_plan_v2.sqlplan');

$parser = new PlanParser();

echo "--- Parsing New Good Plan ---\n";
$goodPlan = $parser->parse($goodXml);
print_r($goodPlan);

echo "\n--- Parsing New Bad Plan ---\n";
$badPlan = $parser->parse($badXml);
print_r($badPlan);

echo "\n--- Comparing Plans ---\n";
$comparer = new PlanComparer();
$comparison = $comparer->compare($goodPlan, $badPlan);
print_r($comparison);

echo "\n--- Generating Recommendations ---\n";
$recEngine = new RecommendationEngine();
$recs = $recEngine->generateRecommendations($comparison);
print_r($recs);

echo "\n--- Generating SQL Script ---\n";
$sqlGen = new SqlFileGenerator();
$sqlText = $sqlGen->generate($comparison, $recs);
echo $sqlText . "\n";
