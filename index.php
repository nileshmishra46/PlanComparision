<?php

require_once __DIR__ . '/src/PlanParser.php';
require_once __DIR__ . '/src/PlanComparer.php';
require_once __DIR__ . '/src/RecommendationEngine.php';

session_start();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $goodXml = $_POST['good_xml'] ?? '';
    $badXml = $_POST['bad_xml'] ?? '';
    $sqlText = $_POST['sql_text'] ?? '';
    
    if (empty(trim($goodXml)) || empty(trim($badXml))) {
        $error = "Please provide both Good and Bad execution plan XML data.";
    } else {
        try {
            $parser = new \PlanComparator\PlanParser();
            
            // Attempt to parse both execution plans
            $goodPlan = $parser->parse($goodXml);
            $badPlan = $parser->parse($badXml);
            
            // If user manually pasted statement text, override plan-extracted text
            if (!empty(trim($sqlText))) {
                $goodPlan['StatementText'] = $sqlText;
                $badPlan['StatementText'] = $sqlText;
            }
            
            $comparer = new \PlanComparator\PlanComparer();
            $comparison = $comparer->compare($goodPlan, $badPlan);
            
            $engine = new \PlanComparator\RecommendationEngine();
            $recommendations = $engine->generateRecommendations($comparison);
            
            // Cache in session
            $_SESSION['comparison'] = $comparison;
            $_SESSION['recommendations'] = $recommendations;
            $_SESSION['good_tree'] = $goodPlan['OperatorTree'];
            $_SESSION['bad_tree'] = $badPlan['OperatorTree'];
            
            // Redirect to index.php (GET)
            header("Location: index.php?result=1");
            exit;
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Clear results if requested
if (isset($_GET['action']) && $_GET['action'] === 'clear') {
    session_destroy();
    header("Location: index.php");
    exit;
}

$hasResult = isset($_GET['result']) && isset($_SESSION['comparison']) && isset($_SESSION['recommendations']);

if ($hasResult) {
    $comparison = $_SESSION['comparison'];
    $recommendations = $_SESSION['recommendations'];
    $goodTreeStructure = $_SESSION['good_tree'] ?? null;
    $badTreeStructure = $_SESSION['bad_tree'] ?? null;
    $isDownload = false;
    
    // Inject the report dashboard
    include __DIR__ . '/templates/report_template.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SQL Server Execution Plan Comparator</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="bg-glow-1"></div>
    <div class="bg-glow-2"></div>
    
    <div class="container" style="max-width: 1100px; padding-top: 60px;">
        <div style="text-align: center; margin-bottom: 50px;">
            <h1 class="title-gradient" style="font-size: 2.8rem; margin-bottom: 12px;">Execution Plan Comparator</h1>
            <p style="font-size: 0.95rem; color: var(--color-cyan); margin-top: -8px; margin-bottom: 16px; font-weight: 500;">Developed by Nilesh Mishra</p>
            <p class="subtitle" style="font-size: 1.15rem;">Compare SQL Server <code style="color:var(--color-cyan);">.sqlplan</code> executions, diagnose bottlenecks, and generate tuning scripts.</p>
        </div>

        <?php if ($error): ?>
            <div class="card" style="border-color: var(--color-rose); background: rgba(244, 63, 94, 0.05); margin-bottom: 30px; padding: 16px 20px;">
                <div style="display: flex; gap: 12px; align-items: center;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--color-rose);"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"></polygon><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    <div>
                        <strong style="color: var(--color-rose);">Parsing Error</strong>
                        <p style="font-size: 0.9rem; color: var(--color-text-secondary); margin-top: 4px;"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <form action="index.php" method="POST" id="compare-form">
            <!-- SQL query statement textbox -->
            <div class="card" style="margin-bottom: 30px;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="sql_text">SQL Statement Text (Optional Context)</label>
                    <textarea name="sql_text" id="sql_text" class="form-control" rows="4" placeholder="SELECT * FROM Orders WHERE OrderDate >= @Date..."></textarea>
                </div>
            </div>

            <!-- Side-by-side Good & Bad Plan uploads -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(480px, 1fr)); gap: 30px; margin-bottom: 40px;">
                <!-- Good Plan -->
                <div class="card" style="border-color: rgba(16, 185, 129, 0.25);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
                        <span class="badge badge-success">Good Plan</span>
                        <h2 style="font-size: 1.25rem;">Reference Execution</h2>
                    </div>
                    
                    <div class="form-group">
                        <label>Upload .sqlplan file</label>
                        <input type="file" id="good_file" accept=".sqlplan,.xml">
                        <div class="file-upload-wrapper" id="good-drag-zone">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                            <p class="file-upload-text">Drag file here or <span>browse</span></p>
                        </div>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="good_xml">Or paste XML code</label>
                        <textarea name="good_xml" id="good_xml" class="form-control" rows="12" placeholder="<ShowPlanXML xmlns=..."></textarea>
                    </div>
                </div>

                <!-- Bad Plan -->
                <div class="card" style="border-color: rgba(244, 63, 94, 0.25);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
                        <span class="badge badge-danger">Bad Plan</span>
                        <h2 style="font-size: 1.25rem;">Slow Execution</h2>
                    </div>

                    <div class="form-group">
                        <label>Upload .sqlplan file</label>
                        <input type="file" id="bad_file" accept=".sqlplan,.xml">
                        <div class="file-upload-wrapper" id="bad-drag-zone">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                            <p class="file-upload-text">Drag file here or <span>browse</span></p>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="bad_xml">Or paste XML code</label>
                        <textarea name="bad_xml" id="bad_xml" class="form-control" rows="12" placeholder="<ShowPlanXML xmlns=..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Action buttons -->
            <div style="text-align: center;">
                <button type="submit" class="btn btn-primary" style="padding: 14px 40px; font-size: 1.05rem;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                    Compare Execution Plans
                </button>
            </div>
        </form>
    </div>

    <footer style="text-align: center; padding: 40px 0 20px; font-size: 0.85rem; color: var(--color-text-muted);">
        Developed by <a href="https://github.com/nileshmishra46" target="_blank" style="color: var(--color-cyan); text-decoration: none; font-weight: 500;">Nilesh Mishra</a>
    </footer>

    <script src="assets/js/app.js"></script>
</body>
</html>
