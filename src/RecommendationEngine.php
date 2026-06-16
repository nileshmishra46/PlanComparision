<?php

namespace PlanComparator;

class RecommendationEngine {
    /**
     * Generates a list of recommendations based on the comparison results.
     * 
     * @param array $comparison
     * @return array
     */
    public function generateRecommendations(array $comparison): array {
        $recommendations = [];

        // 1. Process Missing Indexes (High Priority)
        $this->processMissingIndexes($comparison['MissingIndexes']['Bad'], $recommendations);

        // 2. Process Plan-Level Warnings (Implicit Conversions, Missing Stats, No Join Predicate)
        $this->processPlanWarnings($comparison['PlanLevel']['Warnings']['Bad'], $recommendations);

        // 3. Process Operator Diffs (Scan vs Seek, TempDB spills, Cardinality issues)
        $this->processOperatorDiffs($comparison['OperatorDiffs'], $recommendations, $comparison['PlanLevel']['StatementText']['Bad'] ?? '');

        // Sort by impact: High -> Medium -> Low
        usort($recommendations, function ($a, $b) {
            $weights = ['HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1];
            return $weights[$b['Impact']] <=> $weights[$a['Impact']];
        });

        return $recommendations;
    }

    /**
     * Translates parsed missing indexes into SQL CREATE INDEX recommendations.
     */
    private function processMissingIndexes(array $missingIndexes, array &$recommendations): void {
        foreach ($missingIndexes as $idx) {
            $db = $idx['Database'] ? "[{$idx['Database']}]." : "";
            $schema = $idx['Schema'] ? "[{$idx['Schema']}]." : "[dbo].";
            $table = "[{$idx['Table']}]";
            $fullTable = $db . $schema . $table;
            
            $impact = $idx['Impact'];
            $priority = $impact > 70 ? 'HIGH' : ($impact > 40 ? 'MEDIUM' : 'LOW');
            
            // Build index column definitions
            $keyCols = [];
            foreach ($idx['EqualityColumns'] as $col) {
                $keyCols[] = "[{$col}]";
            }
            foreach ($idx['InequalityColumns'] as $col) {
                $keyCols[] = "[{$col}]";
            }
            
            if (empty($keyCols)) continue; // Can't build index without key columns

            $includeCols = [];
            foreach ($idx['IncludeColumns'] as $col) {
                $includeCols[] = "[{$col}]";
            }

            // Create a descriptive index name
            $flatKeyCols = array_map(function($c) { return str_replace(['[', ']'], '', $c); }, $keyCols);
            $colSuffix = implode('_', array_slice($flatKeyCols, 0, 3));
            $cleanTableName = str_replace(['[', ']'], '', $idx['Table']);
            $indexName = "IX_{$cleanTableName}_{$colSuffix}";
            if (strlen($indexName) > 120) {
                $indexName = substr($indexName, 0, 115) . "_" . rand(100, 999);
            }

            $sql = "-- Missing Index Recommendation (Estimated Impact: {$impact}%)\n";
            if (!empty($includeCols)) {
                $sql .= "-- Option A: Covering Index with INCLUDE columns (optimal query performance)\n";
                $sql .= "IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'{$fullTable}') AND name = N'{$indexName}')\n";
                $sql .= "BEGIN\n";
                $sql .= "    CREATE NONCLUSTERED INDEX [{$indexName}]\n";
                $sql .= "    ON {$fullTable} (" . implode(', ', $keyCols) . ")\n";
                $sql .= "    INCLUDE (" . implode(', ', $includeCols) . ")\n";
                $sql .= "    WITH (ONLINE = ON, DATA_COMPRESSION = PAGE);\n";
                $sql .= "END\nGO\n\n";

                $sql .= "-- Option B: Key Columns only (reduces index storage and write overhead)\n";
                $indexNameKeysOnly = $indexName . "_KeysOnly";
                $sql .= "IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'{$fullTable}') AND name = N'{$indexNameKeysOnly}')\n";
                $sql .= "BEGIN\n";
                $sql .= "    CREATE NONCLUSTERED INDEX [{$indexNameKeysOnly}]\n";
                $sql .= "    ON {$fullTable} (" . implode(', ', $keyCols) . ")\n";
                $sql .= "    WITH (ONLINE = ON, DATA_COMPRESSION = PAGE);\n";
                $sql .= "END\nGO\n";
            } else {
                $sql .= "IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'{$fullTable}') AND name = N'{$indexName}')\n";
                $sql .= "BEGIN\n";
                $sql .= "    CREATE NONCLUSTERED INDEX [{$indexName}]\n";
                $sql .= "    ON {$fullTable} (" . implode(', ', $keyCols) . ")\n";
                $sql .= "    WITH (ONLINE = ON, DATA_COMPRESSION = PAGE);\n";
                $sql .= "END\nGO\n";
            }

            $recommendations[] = [
                'Title' => "Create Missing Index on {$schema}{$table}",
                'Type' => 'index',
                'Impact' => $priority,
                'Description' => "The query optimizer identified a missing index on table {$fullTable} with an estimated execution cost reduction of <strong>{$impact}%</strong>. This index covers the equality and inequality predicates in your filter or join conditions, which will allow the database engine to perform an Index Seek rather than a Clustered Index/Table Scan.",
                'SQL' => $sql,
                'Source' => "Statement Missing Index Suggestion"
            ];
        }
    }

    /**
     * Translates plan-level warnings into query design recommendations.
     */
    private function processPlanWarnings(array $warnings, array &$recommendations): void {
        foreach ($warnings as $warn) {
            if ($warn['Type'] === 'NoJoinPredicate') {
                $sql = "-- Check join criteria for cross products\n";
                $sql .= "-- Ensure all table associations have ON clauses matching keys.\n";

                $recommendations[] = [
                    'Title' => "Fix Missing Join Predicate",
                    'Type' => 'query_design',
                    'Impact' => 'HIGH',
                    'Description' => "A query warning 'No Join Predicate' was found. This indicates that one or more tables are joined without specifying how they relate, leading to a Cartesian Product (combining every row from Table A with every row from Table B). This uses massive CPU and memory resources.",
                    'SQL' => $sql,
                    'Source' => "Plan Warning"
                ];
            }

            if ($warn['Type'] === 'ColumnsWithNoStatistics') {
                $sql = "-- Update missing statistics with a full scan\n";
                foreach ($warn['Details'] as $colPath) {
                    $parts = explode('.', $colPath);
                    if (count($parts) >= 2) {
                        $table = "[{$parts[0]}].[{$parts[1]}]";
                        $sql .= "UPDATE STATISTICS {$table} WITH FULLSCAN, ALL;\n";
                    }
                }
                $sql .= "GO\n";

                $recommendations[] = [
                    'Title' => "Update Missing Statistics",
                    'Type' => 'statistics',
                    'Impact' => 'HIGH',
                    'Description' => "The query optimizer reports missing statistics for one or more columns: <code>" . implode(', ', $warn['Details']) . "</code>. Without accurate statistics, the optimizer cannot estimate row counts properly, which leads to poor join algorithms (e.g. Hash Join instead of Nested Loops) and bloated memory grants.",
                    'SQL' => $sql,
                    'Source' => "Plan Warning"
                ];
            }

            if ($warn['Type'] === 'ImplicitConversion') {
                $sql = "-- Implicit conversion fix:\n";
                $sql .= "-- 1. Ensure application parameters use matching types (e.g., pass AnsiString for VARCHAR columns, or String for NVARCHAR).\n";
                $sql .= "-- 2. E.g. in ADO.NET: command.Parameters.Add('@param', SqlDbType.VarChar).Value = '...'\n";
                $sql .= "-- 3. In Entity Framework: builder.Property(e => e.Code).IsUnicode(false);\n";

                $recommendations[] = [
                    'Title' => "Resolve Implicit Data Type Conversions",
                    'Type' => 'datatype',
                    'Impact' => 'MEDIUM',
                    'Description' => "An implicit conversion warning (<code>PlanAffectingConvert</code>) was detected. This occurs when you compare two columns or a column and a variable/parameter of different data types (e.g., comparing a <code>VARCHAR</code> column to an <code>NVARCHAR</code> parameter). SQL Server is forced to convert the column values for *every row* in the table, preventing the use of an Index Seek.",
                    'SQL' => $sql,
                    'Source' => "Plan Warning"
                ];
            }

            if ($warn['Type'] === 'MemoryGrantWarning') {
                $sql = "-- Memory grant fix suggestions:\n";
                $sql .= "-- 1. Update statistics on tables to get accurate row counts.\n";
                $sql .= "-- 2. Optimize indexing to reduce sorting / hash match requirements.\n";
                $sql .= "-- 3. Use OPTION (RECOMPILE) if query suffers from parameter sniffing.\n";

                $kind = $warn['Details']['Kind'] ?? 'Excessive/Insufficient';
                $recommendations[] = [
                    'Title' => "Tune Memory Grant ({$kind})",
                    'Type' => 'memory',
                    'Impact' => 'MEDIUM',
                    'Description' => "The query optimizer issued a Memory Grant Warning (<code>{$kind}</code>). This means either too much memory was reserved (wasting database resources) or too little was reserved, causing the query execution engine to spill data to the TempDB database, slow down, and compete for disk IO.",
                    'SQL' => $sql,
                    'Source' => "Plan Warning"
                ];
            }
        }
    }

    /**
     * Translates operator level differences into diagnostic suggestions.
     */
    private function processOperatorDiffs(array $diffs, array &$recommendations, string $queryText = ''): void {
        foreach ($diffs as $nodeId => $diff) {
            $bad = $diff['Bad'];
            $good = $diff['Good'];

            if (!$bad) continue; // Focus suggestions on issues present in the Bad plan

            // A: Scan vs Seek
            $scans = ['Index Scan', 'Clustered Index Scan', 'Table Scan'];
            $seeks = ['Index Seek', 'Clustered Index Seek'];
            if (in_array($bad['PhysicalOp'], $scans) && $good && in_array($good['PhysicalOp'], $seeks)) {
                $fullTable = ($bad['SchemaName'] ? "[{$bad['SchemaName']}]." : "") . "[{$bad['TableName']}]";
                
                $sql = "-- Optimize index coverage to convert Scan to Seek\n";
                $sql .= "-- Table: {$fullTable}\n";
                if (!empty($bad['Predicates'])) {
                    $sql .= "-- Detected filters/joins: " . implode(', ', $bad['Predicates']) . "\n";
                }
                $sql .= "-- Verify if a covering index exists or build one:\n";
                $sql .= "-- CREATE NONCLUSTERED INDEX IX_{$bad['TableName']}_SeekCover ON {$fullTable} (...)\n";

                $recommendations[] = [
                    'Title' => "Convert Scan to Seek on {$bad['TableName']} (Node {$nodeId})",
                    'Type' => 'index',
                    'Impact' => 'HIGH',
                    'Description' => "The bad plan uses a <strong>{$bad['PhysicalOp']}</strong> on table <code>{$fullTable}</code>, whereas the good plan uses an <strong>{$good['PhysicalOp']}</strong>. Scanning an entire table or index takes linear time and gets progressively slower as the table grows, whereas a Seek has logarithmic complexity.",
                    'SQL' => $sql,
                    'Source' => "Operator Mismatch (Node {$nodeId})"
                ];
            }

            // B: TempDB Spill
            if ($bad['SpillToTempDb']) {
                $fullTable = "";
                if ($bad['TableName']) {
                    $fullTable = " on " . ($bad['SchemaName'] ? "[{$bad['SchemaName']}]." : "") . "[{$bad['TableName']}]";
                }
                
                $sql = "-- Resolve TempDB Spill\n";
                if ($bad['TableName']) {
                    $sql .= "UPDATE STATISTICS " . ($bad['SchemaName'] ? "[{$bad['SchemaName']}]." : "") . "[{$bad['TableName']}] WITH FULLSCAN;\nGO\n";
                } else {
                    $sql .= "-- Update stats on the source tables of this join/sort query:\n";
                    $sql .= "-- UPDATE STATISTICS [Schema].[Table] WITH FULLSCAN;\n";
                }

                $recommendations[] = [
                    'Title' => "Eliminate TempDB Spill in {$bad['PhysicalOp']} (Node {$nodeId})",
                    'Type' => 'memory',
                    'Impact' => 'HIGH',
                    'Description' => "The <strong>{$bad['PhysicalOp']}</strong> operator{$fullTable} spilled data to the physical disk (TempDB database) because the allocated memory grant was insufficient. Spilling to disk can make a query run 10x-100x slower. This is usually caused by outdated statistics causing the optimizer to underestimate row counts.",
                    'SQL' => $sql,
                    'Source' => "Operator Warning (Node {$nodeId})"
                ];
            }

            // C: Cardinality Mismatch / Parameter Sniffing
            if ($bad['ActualRows'] !== null && $bad['EstimatedRows'] > 0) {
                $est = $bad['EstimatedRows'];
                $act = $bad['ActualRows'];
                $ratio = max($act / $est, $est / $act);
                
                if ($ratio > 50) { // Large deviation
                    $fullTable = $bad['TableName'] ? ($bad['SchemaName'] ? "[{$bad['SchemaName']}]." : "") . "[{$bad['TableName']}]" : "join operator";
                    
                    $sql = "-- Parameter Sniffing or Outdated Statistics Fix:\n";
                    if ($bad['TableName']) {
                        $sql .= "-- 1. Refresh statistics on table:\n";
                        $sql .= "UPDATE STATISTICS " . ($bad['SchemaName'] ? "[{$bad['SchemaName']}]." : "") . "[{$bad['TableName']}] WITH FULLSCAN;\nGO\n";
                    }
                    
                    $sql .= "-- 2. Parameter Sniffing query hints:\n";
                    $cleanQuery = trim($queryText);
                    if (!empty($cleanQuery)) {
                        $cleanQuery = rtrim($cleanQuery, ';');
                        $sql .= "-- Option A: Force query recompilation on every run (ideal for highly volatile parameters):\n";
                        $sql .= "{$cleanQuery} OPTION (RECOMPILE);\n\n";
                        $sql .= "-- Option B: Optimize for average statistics (standard workaround for sniffing):\n";
                        $sql .= "{$cleanQuery} OPTION (OPTIMIZE FOR UNKNOWN);\n";
                    } else {
                        $sql .= "-- Append query hints to your query:\n";
                        $sql .= "-- Option A: OPTION (RECOMPILE);\n";
                        $sql .= "-- Option B: OPTION (OPTIMIZE FOR UNKNOWN);\n";
                    }

                    $recommendations[] = [
                        'Title' => "Fix Cardinality Mismatch on {$bad['PhysicalOp']} (Node {$nodeId})",
                        'Type' => 'query_hint',
                        'Impact' => 'MEDIUM',
                        'Description' => "There is a severe discrepancy between the estimated number of rows (<strong>" . number_format($est) . "</strong>) and actual rows processed (<strong>" . number_format($act) . "</strong>) in the bad plan. This is a " . round($ratio) . "x estimation error! This mismatch causes SQL Server to compile an execution plan optimized for a small data set but run it against a large one, causing suboptimal joins or index scans.",
                        'SQL' => $sql,
                        'Source' => "Cardinality Verification (Node {$nodeId})"
                    ];
                }
            }
        }
    }
}
