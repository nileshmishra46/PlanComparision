<?php
/**
 * Execution Plan Comparison Report Template
 * 
 * Variables expected:
 * @var array $comparison
 * @var array $recommendations
 * @var bool $isDownload
 * @var string $goodXml (optional)
 * @var string $badXml (optional)
 */

$planLevel = $comparison['PlanLevel'];
$cost = $planLevel['Cost'];
$dop = $planLevel['DOP'];
$mem = $planLevel['MemoryGrant'];
$warnings = $planLevel['Warnings'];

// Helper to format values
function fmtCost($val) {
    return number_format($val, 4);
}

function fmtRows($val) {
    if ($val === null) return 'N/A';
    if ($val >= 1000000) return number_format($val / 1000000, 2) . 'M';
    if ($val >= 1000) return number_format($val / 1000, 2) . 'K';
    return number_format($val);
}

function fmtMemory($kb) {
    if ($kb === null || $kb === '') return 'N/A';
    return number_format($kb / 1024, 2) . ' MB';
}

// Recursive function to render plan tree
function renderPlanTree(array $node): string {
    $hasChildren = !empty($node['Children']);
    $classes = ['tree-node'];
    if ($node['SpillToTempDb']) {
        $classes[] = 'node-spill';
    }
    
    $html = '<li>';
    $html .= '<div class="' . implode(' ', $classes) . '" title="Logical Op: ' . htmlspecialchars($node['LogicalOp']) . '">';
    
    $html .= '<span class="tree-node-title">';
    if ($hasChildren) {
        $html .= '<span class="collapsible-toggle">-</span> ';
    }
    $html .= '#' . $node['NodeId'] . ' <strong>' . htmlspecialchars($node['PhysicalOp']) . '</strong>';
    if ($node['TableName']) {
        $html .= ' <span class="operator-object">[' . htmlspecialchars($node['TableName']) . ']</span>';
    }
    $html .= '</span>';
    
    $html .= '<span class="tree-node-cost">Est. Cost: ' . fmtCost($node['EstimatedTotalSubtreeCost']) . '</span>';
    
    $html .= '</div>';
    
    if ($hasChildren) {
        $html .= '<ul>';
        foreach ($node['Children'] as $child) {
            $html .= renderPlanTree($child);
        }
        $html .= '</ul>';
    }
    $html .= '</li>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SQL Server Execution Plan Comparison Report</title>
    
    <?php if (!empty($isDownload)): ?>
        <style>
            <?php echo file_get_contents(__DIR__ . '/../assets/css/style.css'); ?>
        </style>
    <?php else: ?>
        <link rel="stylesheet" href="assets/css/style.css">
    <?php endif; ?>
</head>
<body>
    <div class="bg-glow-1"></div>
    <div class="bg-glow-2"></div>
    
    <div class="container">
        <header>
            <div>
                <h1>Execution Plan Comparison Report</h1>
                <p style="font-size: 0.95rem; color: var(--color-cyan); margin-top: 4px; margin-bottom: 4px; font-weight: 500;">Developed by Nilesh Mishra</p>
                <p class="subtitle">Detailed analysis comparing Good vs Bad SQL execution paths</p>
            </div>
            <div class="no-print" style="display: flex; gap: 12px;">
                <a href="index.php" class="btn btn-secondary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    Compare New Plans
                </a>
                <a href="download.php?type=sql" class="btn btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Download SQL Fixes
                </a>
                <a href="download.php?type=html" class="btn btn-secondary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Download HTML Report
                </a>
                <button onclick="window.print()" class="btn btn-secondary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                    Print / Save PDF
                </button>
            </div>
        </header>

        <!-- Statement Context -->
        <?php if (!empty($planLevel['StatementText']['Bad'])): ?>
        <div class="card" style="margin-bottom: 30px;">
            <h3>SQL Query Statement</h3>
            <pre class="rec-code" style="border-radius: 8px; margin-top: 12px; margin-bottom: 0; font-size: 0.85rem; max-height: 180px; overflow-y: auto;"><?php echo htmlspecialchars(trim($planLevel['StatementText']['Bad'])); ?></pre>
        </div>
        <?php endif; ?>

        <!-- Tabs Navigation -->
        <div class="tabs-header no-print">
            <button class="tab-btn active" data-tab="tab-dashboard">Executive Dashboard</button>
            <button class="tab-btn" data-tab="tab-diff">Side-by-Side Operators</button>
            <button class="tab-btn" data-tab="tab-trees">Visual Tree Diffs</button>
            <button class="tab-btn" data-tab="tab-recommendations">Tuning Recommendations (<?php echo count($recommendations); ?>)</button>
        </div>

        <!-- Panel 1: Dashboard -->
        <div id="tab-dashboard" class="tab-panel active">
            <!-- Metrics Grid -->
            <div class="grid-metrics">
                <div class="metric-card">
                    <div class="metric-val <?php echo $cost['Delta'] > 0 ? 'metric-val-bad' : ''; ?>">
                        <?php echo fmtCost($cost['Bad']); ?>
                    </div>
                    <div class="metric-label">Bad Plan Subtree Cost</div>
                </div>
                
                <div class="metric-card" style="border-color: rgba(16, 185, 129, 0.4);">
                    <div class="metric-val metric-val-good">
                        <?php echo fmtCost($cost['Good']); ?>
                    </div>
                    <div class="metric-label">Good Plan Subtree Cost</div>
                </div>

                <div class="metric-card" style="background: var(--gradient-glow); border-color: rgba(6, 182, 212, 0.3);">
                    <div class="metric-val" style="color: var(--color-cyan);">
                        <?php echo $cost['ImprovementPercent'] > 0 ? $cost['ImprovementPercent'] . '%' : 'N/A'; ?>
                    </div>
                    <div class="metric-label">Estimated Improvement</div>
                </div>

                <div class="metric-card">
                    <div class="metric-val">
                        <?php echo $dop['Bad'] . ' vs ' . $dop['Good']; ?>
                    </div>
                    <div class="metric-label">DOP (Bad vs Good)</div>
                </div>
            </div>

            <!-- Memory and Warnings Breakdown -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(48%, 1fr)); gap: 24px; margin-bottom: 30px;">
                <!-- Memory Grants Card -->
                <div class="card">
                    <h2>Memory Grant Analysis</h2>
                    <div style="margin-top: 16px;">
                        <table class="table-diff" style="border: none;">
                            <thead>
                                <tr style="border-bottom: 1px solid var(--border-card);">
                                    <th style="background: none; padding-left: 0;">Metric (KB)</th>
                                    <th style="background: none;">Bad Plan</th>
                                    <th style="background: none;">Good Plan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="padding-left: 0; font-weight: 500;">Required Memory</td>
                                    <td><?php echo fmtMemory($mem['Bad']['SerialRequiredMemory'] ?? null); ?></td>
                                    <td><?php echo fmtMemory($mem['Good']['SerialRequiredMemory'] ?? null); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding-left: 0; font-weight: 500;">Desired Memory</td>
                                    <td><?php echo fmtMemory($mem['Bad']['SerialDesiredMemory'] ?? null); ?></td>
                                    <td><?php echo fmtMemory($mem['Good']['SerialDesiredMemory'] ?? null); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding-left: 0; font-weight: 500;">Granted Memory</td>
                                    <td><?php echo fmtMemory($mem['Bad']['GrantedMemory'] ?? null); ?></td>
                                    <td><?php echo fmtMemory($mem['Good']['GrantedMemory'] ?? null); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding-left: 0; font-weight: 500;">Max Used Memory</td>
                                    <td><?php echo fmtMemory($mem['Bad']['MaxUsedMemory'] ?? null); ?></td>
                                    <td><?php echo fmtMemory($mem['Good']['MaxUsedMemory'] ?? null); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Warnings Card -->
                <div class="card">
                    <h2>Plan Warnings & Alerts</h2>
                    <div style="margin-top: 16px;">
                        <?php if (empty($warnings['Bad'])): ?>
                            <div class="badge badge-success" style="padding: 10px 14px; display: block; text-align: center;">No Warnings detected in Bad Plan</div>
                        <?php else: ?>
                            <ul style="list-style: none;">
                                <?php foreach ($warnings['Bad'] as $warn): ?>
                                    <li style="margin-bottom: 12px; padding: 12px; background: rgba(244, 63, 94, 0.05); border: 1px solid rgba(244, 63, 94, 0.2); border-radius: 8px;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                                            <span class="badge badge-danger"><?php echo htmlspecialchars($warn['Type']); ?></span>
                                        </div>
                                        <div style="font-size: 0.85rem; color: #fca5a5;"><?php echo htmlspecialchars($warn['Message']); ?></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Panel 2: Side by Side Operators -->
        <div id="tab-diff" class="tab-panel">
            <div class="card">
                <h2>Operator-Level Comparison</h2>
                <p class="subtitle" style="margin-bottom: 20px;">Operator-by-operator side alignment by NodeId. Key changes highlighted below.</p>
                
                <div class="table-diff-container">
                    <table class="table-diff">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Node ID</th>
                                <th style="width: 46%;">Bad Plan Operator</th>
                                <th style="width: 46%;">Good Plan Operator</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($comparison['OperatorDiffs'] as $nodeId => $diff): 
                                $rowClass = $diff['IsDifferent'] ? 'diff-row-changed' : '';
                                $bad = $diff['Bad'];
                                $good = $diff['Good'];
                            ?>
                                <tr class="<?php echo $rowClass; ?>">
                                    <td style="font-weight: bold; font-family: var(--font-mono); text-align: center;">#<?php echo $nodeId; ?></td>
                                    
                                    <!-- Bad Plan Operator -->
                                    <td class="<?php echo $bad ? 'diff-cell-bad' : ''; ?>">
                                        <?php if ($bad): ?>
                                            <div class="operator-title">
                                                <span><?php echo htmlspecialchars($bad['PhysicalOp']); ?></span>
                                                <span class="badge badge-info" style="font-size:0.65rem;"><?php echo htmlspecialchars($bad['LogicalOp']); ?></span>
                                            </div>
                                            <?php if ($bad['TableName']): ?>
                                                <div class="operator-object">
                                                    [<?php echo htmlspecialchars($bad['DatabaseName'] ?? ''); ?>].[<?php echo htmlspecialchars($bad['SchemaName'] ?? ''); ?>].[<?php echo htmlspecialchars($bad['TableName']); ?>]
                                                    <?php if ($bad['IndexName']): ?>
                                                        <br><span style="color:var(--color-cyan);">Index: [<?php echo htmlspecialchars($bad['IndexName']); ?>]</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="operator-meta">
                                                Est. Rows: <strong><?php echo fmtRows($bad['EstimatedRows']); ?></strong>
                                                <?php if ($bad['ActualRows'] !== null): ?>
                                                    | Act. Rows: <strong><?php echo fmtRows($bad['ActualRows']); ?></strong>
                                                <?php endif; ?>
                                                | Subtree Cost: <strong><?php echo fmtCost($bad['EstimatedTotalSubtreeCost']); ?></strong>
                                            </div>
                                            
                                            <!-- Operator Warnings -->
                                            <?php if ($bad['SpillToTempDb'] || !empty($bad['Warnings'])): ?>
                                                <div class="operator-warnings">
                                                    <?php if ($bad['SpillToTempDb']): ?>
                                                        <span class="badge badge-danger">SPILL TO TEMPDB</span>
                                                    <?php endif; ?>
                                                    <?php foreach ($bad['Warnings'] as $w): if ($w !== 'SpillToTempDb'): ?>
                                                        <span class="badge badge-warning"><?php echo htmlspecialchars($w); ?></span>
                                                    <?php endif; endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: var(--color-text-muted); font-style: italic;">No equivalent operator in Bad Plan.</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Good Plan Operator -->
                                    <td class="<?php echo $good ? 'diff-cell-good' : ''; ?>">
                                        <?php if ($good): ?>
                                            <div class="operator-title">
                                                <span><?php echo htmlspecialchars($good['PhysicalOp']); ?></span>
                                                <span class="badge badge-info" style="font-size:0.65rem;"><?php echo htmlspecialchars($good['LogicalOp']); ?></span>
                                            </div>
                                            <?php if ($good['TableName']): ?>
                                                <div class="operator-object">
                                                    [<?php echo htmlspecialchars($good['DatabaseName'] ?? ''); ?>].[<?php echo htmlspecialchars($good['SchemaName'] ?? ''); ?>].[<?php echo htmlspecialchars($good['TableName']); ?>]
                                                    <?php if ($good['IndexName']): ?>
                                                        <br><span style="color:var(--color-emerald);">Index: [<?php echo htmlspecialchars($good['IndexName']); ?>]</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="operator-meta">
                                                Est. Rows: <strong><?php echo fmtRows($good['EstimatedRows']); ?></strong>
                                                <?php if ($good['ActualRows'] !== null): ?>
                                                    | Act. Rows: <strong><?php echo fmtRows($good['ActualRows']); ?></strong>
                                                <?php endif; ?>
                                                | Subtree Cost: <strong><?php echo fmtCost($good['EstimatedTotalSubtreeCost']); ?></strong>
                                            </div>
                                            
                                            <!-- Operator Warnings -->
                                            <?php if ($good['SpillToTempDb'] || !empty($good['Warnings'])): ?>
                                                <div class="operator-warnings">
                                                    <?php if ($good['SpillToTempDb']): ?>
                                                        <span class="badge badge-danger">SPILL TO TEMPDB</span>
                                                    <?php endif; ?>
                                                    <?php foreach ($good['Warnings'] as $w): if ($w !== 'SpillToTempDb'): ?>
                                                        <span class="badge badge-warning"><?php echo htmlspecialchars($w); ?></span>
                                                    <?php endif; endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: var(--color-text-muted); font-style: italic;">No equivalent operator in Good Plan.</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                
                                <!-- Operator Diagnostic Messages -->
                                <?php if ($diff['IsDifferent'] && !empty($diff['Changes'])): ?>
                                <tr class="<?php echo $rowClass; ?>" style="border-bottom: 2px solid var(--border-card);">
                                    <td></td>
                                    <td colspan="2" style="padding-top: 0; padding-bottom: 16px;">
                                        <div style="display: flex; flex-direction: column; gap: 6px;">
                                            <?php foreach ($diff['Changes'] as $change): ?>
                                                <div class="diff-change-alert" style="<?php echo $change['Type'] === 'improvement' ? 'background:rgba(16,185,129,0.06); border-color:rgba(16,185,129,0.2); color:#a7f3d0;' : ''; ?>">
                                                    <strong>[<?php echo strtoupper($change['Field']); ?>]</strong> <?php echo $change['Message']; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Panel 3: Visual Trees -->
        <div id="tab-trees" class="tab-panel">
            <div class="card">
                <h2>Visual Pipeline Divergence</h2>
                <p class="subtitle" style="margin-bottom: 20px;">Hierarchical post-order traversal trees. Click parent nodes (+/-) to expand/collapse operators.</p>
                
                <div class="tree-container">
                    <!-- Bad Tree -->
                    <div class="tree-column">
                        <h3 style="color: var(--color-rose); margin-bottom: 12px; text-align: center;">Bad Execution Plan Tree</h3>
                        <div class="card" style="padding: 16px; background: rgba(10,14,23,0.5);">
                            <?php if (!empty($comparison['OperatorDiffs'][0]['Bad'])): ?>
                                <ul class="tree-root">
                                    <?php 
                                        // Retrieve recursive bad tree structure from first index or statement operator tree
                                        // Wait, our parser outputs 'OperatorTree' containing the recursive parsed hierarchy!
                                        // Let's pass that to our renderPlanTree helper.
                                        // But wait, what if OperatorTree is empty? Let's check.
                                        $badTree = $comparison['OperatorDiffs'][0]['Bad']; 
                                        // Wait, the comparison array doesn't directly have OperatorTree. But we parsed it in compare.php!
                                        // Let's make sure our caller provides it in $comparison or we reconstruct it.
                                        // Ah! In PlanParser we return 'OperatorTree' inside the results array. Let's make sure our compare.php stores the trees.
                                        // Let's look at compare.php variables or how we pass them. We can save the raw parsed results in $_SESSION['good_parsed'] and $_SESSION['bad_parsed']!
                                        // If we pass $goodPlan['OperatorTree'] and $badPlan['OperatorTree'] to the template, it is extremely easy!
                                        // Let's check: Yes! We can pass them as variables. Let's check if the caller passed $goodTree and $badTree.
                                        // Let's check the global or expected variables. Let's define them in the template scope:
                                    ?>
                                    <?php if (!empty($badTreeStructure)): ?>
                                        <?php echo renderPlanTree($badTreeStructure); ?>
                                    <?php else: ?>
                                        <p style="color:var(--color-text-muted); text-align:center;">Tree structure not available.</p>
                                    <?php endif; ?>
                                </ul>
                            <?php else: ?>
                                <p style="color: var(--color-text-muted); text-align: center;">No Bad Plan parsed.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Good Tree -->
                    <div class="tree-column">
                        <h3 style="color: var(--color-emerald); margin-bottom: 12px; text-align: center;">Good Execution Plan Tree</h3>
                        <div class="card" style="padding: 16px; background: rgba(10,14,23,0.5);">
                            <?php if (!empty($comparison['OperatorDiffs'][0]['Good'])): ?>
                                <ul class="tree-root">
                                    <?php if (!empty($goodTreeStructure)): ?>
                                        <?php echo renderPlanTree($goodTreeStructure); ?>
                                    <?php else: ?>
                                        <p style="color:var(--color-text-muted); text-align:center;">Tree structure not available.</p>
                                    <?php endif; ?>
                                </ul>
                            <?php else: ?>
                                <p style="color: var(--color-text-muted); text-align: center;">No Good Plan parsed.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Panel 4: Tuning Recommendations -->
        <div id="tab-recommendations" class="tab-panel">
            <div class="card">
                <h2>Tuning Recommendations & Action Plan</h2>
                <p class="subtitle" style="margin-bottom: 24px;">Rule-based performance fixes compiled from discrepancies found in the execution plans.</p>

                <?php if (empty($recommendations)): ?>
                    <div style="text-align: center; padding: 40px; color: var(--color-text-secondary);">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--color-emerald); margin-bottom: 16px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <h3>Perfect Alignment!</h3>
                        <p style="margin-top: 8px;">No major performance regression warnings or index deficiencies were detected.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recommendations as $i => $rec): ?>
                        <div class="rec-card rec-impact-<?php echo $rec['Impact']; ?>">
                            <div class="rec-header">
                                <h3 style="color: var(--color-text-primary); font-size: 1.1rem;"><?php echo htmlspecialchars($rec['Title']); ?></h3>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <span class="badge <?php 
                                        echo $rec['Impact'] === 'HIGH' ? 'badge-danger' : ($rec['Impact'] === 'MEDIUM' ? 'badge-warning' : 'badge-info'); 
                                    ?>"><?php echo $rec['Impact']; ?> Impact</span>
                                    <span class="badge badge-info" style="font-size: 0.68rem;"><?php echo htmlspecialchars($rec['Type']); ?></span>
                                </div>
                            </div>
                            
                            <div class="rec-body">
                                <p><?php echo $rec['Description']; ?></p>
                            </div>
                            
                            <?php if (!empty($rec['SQL'])): ?>
                                <div class="rec-code-header no-print">
                                    <span style="font-family: var(--font-mono); color: var(--color-text-secondary);">Suggested SQL Fix Script</span>
                                    <button class="btn btn-secondary" style="padding: 4px 10px; font-size: 0.75rem;" onclick="copyToClipboard(this, 'code-rec-<?php echo $i; ?>')">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                        Copy Code
                                    </button>
                                </div>
                                <pre id="code-rec-<?php echo $i; ?>" class="rec-code"><?php echo htmlspecialchars($rec['SQL']); ?></pre>
                            <?php endif; ?>
                            
                            <div style="font-size: 0.75rem; color: var(--color-text-muted); text-align: right;">
                                Triggered by: <strong><?php echo htmlspecialchars($rec['Source']); ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <footer class="no-print" style="text-align: center; padding: 40px 0 20px; font-size: 0.85rem; color: var(--color-text-muted);">
        Developed by <a href="https://github.com/nileshmishra46" target="_blank" style="color: var(--color-cyan); text-decoration: none; font-weight: 500;">Nilesh Mishra</a>
    </footer>

    <!-- Client Script -->
    <?php if (!empty($isDownload)): ?>
        <script>
            <?php echo file_get_contents(__DIR__ . '/../assets/js/app.js'); ?>
        </script>
    <?php else: ?>
        <script src="assets/js/app.js"></script>
    <?php endif; ?>
</body>
</html>
