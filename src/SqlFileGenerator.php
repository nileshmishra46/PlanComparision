<?php

namespace PlanComparator;

class SqlFileGenerator {
    /**
     * Generates a downloadable SQL fix script.
     * 
     * @param array $comparison
     * @param array $recommendations
     * @return string
     */
    public function generate(array $comparison, array $recommendations): string {
        $costGood = $comparison['PlanLevel']['Cost']['Good'];
        $costBad = $comparison['PlanLevel']['Cost']['Bad'];
        $improvement = $comparison['PlanLevel']['Cost']['ImprovementPercent'];
        
        $sql = "/*******************************************************************************\n";
        $sql .= "  SQL Server Execution Plan Comparator - Tuning Script\n";
        $sql .= "  Generated on: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "  -----------------------------------------------------------------------------\n";
        $sql .= "  Plan Comparison Summary:\n";
        $sql .= "    - Good Plan Subtree Cost: " . round($costGood, 4) . "\n";
        $sql .= "    - Bad Plan Subtree Cost:  " . round($costBad, 4) . "\n";
        $sql .= "    - Estimated Cost Improvement: " . $improvement . "%\n";
        $sql .= "*******************************************************************************/\n\n";

        if (empty($recommendations)) {
            $sql .= "-- No automated SQL recommendations could be generated for this plan pair.\n";
            $sql .= "-- Please check implicit conversions or logical query design manual suggestions.\n";
            return $sql;
        }

        // Group recommendations by impact
        $grouped = ['HIGH' => [], 'MEDIUM' => [], 'LOW' => []];
        foreach ($recommendations as $rec) {
            $grouped[$rec['Impact']][] = $rec;
        }

        foreach (['HIGH', 'MEDIUM', 'LOW'] as $priority) {
            if (empty($grouped[$priority])) continue;

            $sql .= "/*******************************************************************************\n";
            $sql .= "  [" . $priority . " IMPACT] Recommendations\n";
            $sql .= "*******************************************************************************/\n\n";

            foreach ($grouped[$priority] as $rec) {
                $sql .= "-- =============================================================================\n";
                $sql .= "-- TITLE: " . $rec['Title'] . "\n";
                $sql .= "-- SOURCE: " . $rec['Source'] . "\n";
                $sql .= "-- DESCRIPTION:\n";
                // Strip HTML tags for clean SQL comments
                $desc = strip_tags($rec['Description']);
                $descLines = explode("\n", wordwrap($desc, 76));
                foreach ($descLines as $line) {
                    $sql .= "--   " . trim($line) . "\n";
                }
                $sql .= "-- =============================================================================\n";
                $sql .= $rec['SQL'];
                $sql .= "\n\n";
            }
        }

        return $sql;
    }
}
