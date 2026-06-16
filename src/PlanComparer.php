<?php

namespace PlanComparator;

class PlanComparer {
    /**
     * Compares the parsed Good and Bad plans.
     * 
     * @param array $goodPlan
     * @param array $badPlan
     * @return array
     */
    public function compare(array $goodPlan, array $badPlan): array {
        $comparison = [
            'PlanLevel' => $this->comparePlanLevel($goodPlan, $badPlan),
            'OperatorDiffs' => $this->compareOperators($goodPlan['FlatOperators'], $badPlan['FlatOperators']),
            'MissingIndexes' => [
                'Good' => $goodPlan['MissingIndexes'],
                'Bad' => $badPlan['MissingIndexes']
            ]
        ];

        return $comparison;
    }

    /**
     * Compare plan-level metrics.
     */
    private function comparePlanLevel(array $good, array $bad): array {
        $costGood = $good['SubtreeCost'];
        $costBad = $bad['SubtreeCost'];
        $costDelta = $costBad - $costGood;
        $costFactor = $costGood > 0 ? ($costBad / $costGood) : 0;

        // DOP comparison
        $dopGood = $good['DOP'];
        $dopBad = $bad['DOP'];

        // Memory Grant comparison
        $memGood = $good['MemoryGrant'];
        $memBad = $bad['MemoryGrant'];

        // Warnings
        $warningsGood = $good['Warnings'];
        $warningsBad = $bad['Warnings'];

        return [
            'Cost' => [
                'Good' => $costGood,
                'Bad' => $costBad,
                'Delta' => $costDelta,
                'Factor' => $costFactor,
                'ImprovementPercent' => $costBad > 0 ? round(($costDelta / $costBad) * 100, 2) : 0
            ],
            'DOP' => [
                'Good' => $dopGood,
                'Bad' => $dopBad,
                'IsDifferent' => $dopGood !== $dopBad
            ],
            'MemoryGrant' => [
                'Good' => $memGood,
                'Bad' => $memBad,
                'IsDifferent' => $this->isMemoryGrantDifferent($memGood, $memBad)
            ],
            'Warnings' => [
                'Good' => $warningsGood,
                'Bad' => $warningsBad,
                'NewWarningsInBad' => $this->findNewWarnings($warningsGood, $warningsBad)
            ],
            'StatementText' => [
                'Good' => $good['StatementText'],
                'Bad' => $bad['StatementText']
            ]
        ];
    }

    /**
     * Compares flat operators from both plans side-by-side using NodeId.
     */
    private function compareOperators(array $goodOps, array $badOps): array {
        $diffs = [];
        $allNodeIds = array_unique(array_merge(array_keys($goodOps), array_keys($badOps)));
        sort($allNodeIds);

        foreach ($allNodeIds as $nodeId) {
            $goodOp = $goodOps[$nodeId] ?? null;
            $badOp = $badOps[$nodeId] ?? null;

            $diffs[$nodeId] = [
                'NodeId' => $nodeId,
                'Good' => $goodOp,
                'Bad' => $badOp,
                'Changes' => [],
                'IsDifferent' => false,
                'Severity' => 'info', // info, warning, danger
            ];

            if ($goodOp && $badOp) {
                $changes = [];
                $severity = 'info';

                // 1. Physical Operator change (e.g. Index Scan to Index Seek)
                if ($goodOp['PhysicalOp'] !== $badOp['PhysicalOp']) {
                    $changes[] = [
                        'Field' => 'PhysicalOp',
                        'Message' => "Operator changed: \"{$badOp['PhysicalOp']}\" in bad plan vs \"{$goodOp['PhysicalOp']}\" in good plan.",
                        'BadValue' => $badOp['PhysicalOp'],
                        'GoodValue' => $goodOp['PhysicalOp'],
                        'Type' => $this->isOperatorImprovement($goodOp['PhysicalOp'], $badOp['PhysicalOp']) ? 'improvement' : 'regression'
                    ];
                    if ($severity !== 'danger') $severity = 'warning';
                }

                // 2. Subtree Cost check
                $costGood = $goodOp['EstimatedTotalSubtreeCost'];
                $costBad = $badOp['EstimatedTotalSubtreeCost'];
                if (abs($costBad - $costGood) > 0.01) {
                    $ratio = $costGood > 0 ? ($costBad / $costGood) : 999;
                    if ($ratio > 2) {
                        $changes[] = [
                            'Field' => 'Cost',
                            'Message' => "Cost increased by " . round($ratio, 1) . "x in the bad plan (" . round($costBad, 4) . " vs " . round($costGood, 4) . ").",
                            'BadValue' => $costBad,
                            'GoodValue' => $costGood,
                            'Type' => 'regression'
                        ];
                        $severity = 'danger';
                    }
                }

                // 3. Estimate vs Actual Row Count (Cardinality check)
                if ($badOp['ActualRows'] !== null) {
                    $est = $badOp['EstimatedRows'];
                    $act = $badOp['ActualRows'];
                    if ($act > 0 && $est > 0) {
                        $ratio = max($act / $est, $est / $act);
                        if ($ratio > 10) {
                            $changes[] = [
                                'Field' => 'Cardinality',
                                'Message' => "Significant cardinality mismatch in bad plan: Estimated " . number_format($est) . " rows but actual was " . number_format($act) . " rows (" . round($ratio, 1) . "x discrepancy). Suggests parameter sniffing or outdated statistics.",
                                'BadValue' => "Est: $est, Act: $act",
                                'GoodValue' => "Est: {$goodOp['EstimatedRows']}, Act: " . ($goodOp['ActualRows'] ?? 'N/A'),
                                'Type' => 'regression'
                            ];
                            $severity = 'danger';
                        }
                    }
                }

                // 4. Spill to TempDB warning
                if ($badOp['SpillToTempDb'] && !$goodOp['SpillToTempDb']) {
                    $changes[] = [
                        'Field' => 'SpillToTempDb',
                        'Message' => "Bad plan operator spilled data to TempDB (Sort/Hash spill). Good plan did not spill.",
                        'BadValue' => 'Spilled',
                        'GoodValue' => 'No Spill',
                        'Type' => 'regression'
                    ];
                    $severity = 'danger';
                }

                // 5. Parallelism check
                if ($badOp['Parallel'] !== $goodOp['Parallel']) {
                    $changes[] = [
                        'Field' => 'Parallelism',
                        'Message' => "Parallelism toggle: Bad plan operator is " . ($badOp['Parallel'] ? 'Parallel' : 'Serial') . " vs Good plan is " . ($goodOp['Parallel'] ? 'Parallel' : 'Serial') . ".",
                        'BadValue' => $badOp['Parallel'] ? 'Parallel' : 'Serial',
                        'GoodValue' => $goodOp['Parallel'] ? 'Parallel' : 'Serial',
                        'Type' => 'info'
                    ];
                }

                if (!empty($changes)) {
                    $diffs[$nodeId]['Changes'] = $changes;
                    $diffs[$nodeId]['IsDifferent'] = true;
                    $diffs[$nodeId]['Severity'] = $severity;
                }
            } else {
                // Node exists in only one plan
                $diffs[$nodeId]['IsDifferent'] = true;
                $diffs[$nodeId]['Severity'] = 'warning';
                if ($badOp) {
                    $diffs[$nodeId]['Changes'][] = [
                        'Field' => 'Structure',
                        'Message' => "Operator {$badOp['PhysicalOp']} exists only in the bad plan's structure.",
                        'BadValue' => $badOp['PhysicalOp'],
                        'GoodValue' => 'None (Diverged)',
                        'Type' => 'regression'
                    ];
                } else {
                    $diffs[$nodeId]['Changes'][] = [
                        'Field' => 'Structure',
                        'Message' => "Operator {$goodOp['PhysicalOp']} exists only in the good plan's structure.",
                        'BadValue' => 'None (Diverged)',
                        'GoodValue' => $goodOp['PhysicalOp'],
                        'Type' => 'improvement'
                    ];
                }
            }
        }

        return $diffs;
    }

    /**
     * Determines if a physical operator change represents an improvement.
     */
    private function isOperatorImprovement(string $goodOp, string $badOp): bool {
        $scans = ['Index Scan', 'Clustered Index Scan', 'Table Scan'];
        $seeks = ['Index Seek', 'Clustered Index Seek'];

        // If bad plan uses a Scan and good plan uses a Seek
        if (in_array($badOp, $scans) && in_array($goodOp, $seeks)) {
            return true;
        }

        // If bad plan uses Hash Join and good plan uses Nested Loops (usually positive for low-row queries)
        if ($badOp === 'Hash Match' && $goodOp === 'Nested Loops') {
            return true;
        }

        return false;
    }

    /**
     * Compares memory grant objects.
     */
    private function isMemoryGrantDifferent(?array $good, ?array $bad): bool {
        if (empty($good) || empty($bad)) return !empty($good) || !empty($bad);
        return $good['GrantedMemory'] !== $bad['GrantedMemory'] || 
               $good['MaxUsedMemory'] !== $bad['MaxUsedMemory'] ||
               $good['SerialDesiredMemory'] !== $bad['SerialDesiredMemory'];
    }

    /**
     * Finds warnings present in the bad plan but not in the good plan.
     */
    private function findNewWarnings(array $good, array $bad): array {
        $goodTypes = array_column($good, 'Type');
        $newWarnings = [];
        foreach ($bad as $warn) {
            if (!in_array($warn['Type'], $goodTypes)) {
                $newWarnings[] = $warn;
            }
        }
        return $newWarnings;
    }
}
