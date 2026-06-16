<?php

require_once __DIR__ . '/src/SqlFileGenerator.php';

session_start();

if (!isset($_SESSION['comparison']) || !isset($_SESSION['recommendations'])) {
    header('Location: index.php');
    exit;
}

$comparison = $_SESSION['comparison'];
$recommendations = $_SESSION['recommendations'];
$goodTreeStructure = $_SESSION['good_tree'] ?? null;
$badTreeStructure = $_SESSION['bad_tree'] ?? null;

$type = $_GET['type'] ?? '';

if ($type === 'sql') {
    $generator = new \PlanComparator\SqlFileGenerator();
    $sqlContent = $generator->generate($comparison, $recommendations);
    
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="tuning_fixes_' . date('Ymd_His') . '.sql"');
    header('Content-Length: ' . strlen($sqlContent));
    echo $sqlContent;
    exit;
} elseif ($type === 'html') {
    // Renders the template in download mode (meaning assets will be inlined)
    $isDownload = true;
    
    ob_start();
    include __DIR__ . '/templates/report_template.php';
    $htmlContent = ob_get_clean();
    
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="execution_plan_comparison_' . date('Ymd_His') . '.html"');
    header('Content-Length: ' . strlen($htmlContent));
    echo $htmlContent;
    exit;
} else {
    header('Location: index.php');
    exit;
}
