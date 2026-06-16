<?php

namespace PlanComparator;

use DOMDocument;
use DOMXPath;
use DOMElement;
use Exception;

class PlanParser {
    /**
     * Parses a .sqlplan XML string or file path and returns a structured array of metrics and operator tree.
     * 
     * @param string $xmlContent
     * @return array
     * @throws Exception
     */
    public function parse(string $xmlContent): array {
        if (empty(trim($xmlContent))) {
            throw new Exception("Execution plan XML content is empty.");
        }

        // Clean any leading/trailing whitespace and check XML validity
        $xmlContent = trim($xmlContent);
        
        // Suppress XML errors and load DOM
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        
        if (!$dom->loadXML($xmlContent)) {
            $errors = libxml_get_errors();
            $errorMsg = "Invalid XML format.";
            if (!empty($errors)) {
                $errorMsg .= " Details: " . $errors[0]->message;
            }
            libxml_clear_errors();
            throw new Exception($errorMsg);
        }
        libxml_clear_errors();

        // Create DOMXPath and register the showplan namespace
        $xpath = new DOMXPath($dom);
        $ns = $dom->documentElement->namespaceURI;
        if ($ns) {
            $xpath->registerNamespace('s', $ns);
        } else {
            // Default showplan namespace if none is declared
            $xpath->registerNamespace('s', 'http://schemas.microsoft.com/sqlserver/2004/07/showplan');
        }

        // Extract Statement Information
        $statementNode = $xpath->query('//s:StmtSimple')->item(0);
        if (!$statementNode) {
            // Check for other statement types if StmtSimple is not found (e.g. StmtUseDb, StmtCond)
            $statementNode = $xpath->query('//s:Batch/s:Statements/*')->item(0);
        }

        $statementText = "";
        $statementCost = 0.0;
        $statementRows = 0.0;
        $optmLevel = "UNKNOWN";

        if ($statementNode) {
            $statementText = $statementNode->getAttribute('StatementText');
            $statementCost = (double)$statementNode->getAttribute('StatementSubTreeCost');
            $statementRows = (double)$statementNode->getAttribute('StatementEstRows');
            $optmLevel = $statementNode->getAttribute('StatementOptmLevel');
        }

        // Extract QueryPlan level info
        $queryPlanNode = $xpath->query('//s:QueryPlan')->item(0);
        $dop = 1;
        $memoryGrant = [];
        
        if ($queryPlanNode) {
            $dop = (int)$queryPlanNode->getAttribute('DegreeOfParallelism');
            if ($dop === 0) $dop = 1; // Default to 1 if DOP attribute is 0 or missing

            // Memory Grant Info
            $memNode = $xpath->query('./s:MemoryGrantInfo', $queryPlanNode)->item(0);
            if ($memNode) {
                $memoryGrant = [
                    'SerialRequiredMemory' => (double)$memNode->getAttribute('SerialRequiredMemory'),
                    'SerialDesiredMemory' => (double)$memNode->getAttribute('SerialDesiredMemory'),
                    'GrantedMemory' => $memNode->hasAttribute('GrantedMemory') ? (double)$memNode->getAttribute('GrantedMemory') : null,
                    'MaxUsedMemory' => $memNode->hasAttribute('MaxUsedMemory') ? (double)$memNode->getAttribute('MaxUsedMemory') : null,
                    'RequestedMemory' => $memNode->hasAttribute('RequestedMemory') ? (double)$memNode->getAttribute('RequestedMemory') : null,
                ];
            }
        }

        // Extract Statement-Level Warnings
        $warnings = $this->parseStatementWarnings($xpath);

        // Extract Missing Index recommendations
        $missingIndexes = $this->parseMissingIndexes($xpath);

        // Parse Root operator tree
        $rootRelOpNode = $xpath->query('//s:QueryPlan/s:RelOp')->item(0);
        $operatorTree = null;
        $flatOperators = [];
        
        if ($rootRelOpNode) {
            $operatorTree = $this->parseRelOpNode($rootRelOpNode, $xpath, $flatOperators);
        }

        return [
            'StatementText' => $statementText,
            'SubtreeCost' => $statementCost,
            'EstimatedRows' => $statementRows,
            'OptimizationLevel' => $optmLevel,
            'DOP' => $dop,
            'MemoryGrant' => $memoryGrant,
            'Warnings' => $warnings,
            'MissingIndexes' => $missingIndexes,
            'OperatorTree' => $operatorTree,
            'FlatOperators' => $flatOperators
        ];
    }

    /**
     * Parses a RelOp element recursively.
     */
    private function parseRelOpNode(DOMElement $node, DOMXPath $xpath, array &$flatOperators): array {
        $nodeId = (int)$node->getAttribute('NodeId');
        
        $op = [
            'NodeId' => $nodeId,
            'PhysicalOp' => $node->getAttribute('PhysicalOp'),
            'LogicalOp' => $node->getAttribute('LogicalOp'),
            'EstimatedRows' => (double)$node->getAttribute('EstimatedRows'),
            'EstimatedIO' => (double)$node->getAttribute('EstimatedIO'),
            'EstimatedCPU' => (double)$node->getAttribute('EstimatedCPU'),
            'EstimatedTotalSubtreeCost' => (double)$node->getAttribute('EstimatedTotalSubtreeCost'),
            'Parallel' => ($node->getAttribute('Parallel') === '1' || $node->getAttribute('Parallel') === 'true'),
            'Warnings' => [],
            'ActualRows' => null,
            'ActualLoops' => null,
            'ActualRowsRead' => null,
            'DatabaseName' => null,
            'SchemaName' => null,
            'TableName' => null,
            'IndexName' => null,
            'SeekPredicates' => [],
            'Predicates' => [],
            'SpillToTempDb' => false,
            'Children' => []
        ];

        // Object Details (Database, Schema, Table, Index)
        $objectNodes = $xpath->query('.//s:Object', $node);
        foreach ($objectNodes as $objectNode) {
            $parentRelOp = $xpath->query('ancestor::s:RelOp[1]', $objectNode)->item(0);
            if ($parentRelOp && $parentRelOp->isSameNode($node)) {
                $op['DatabaseName'] = str_replace(['[', ']'], '', $objectNode->getAttribute('Database'));
                $op['SchemaName'] = str_replace(['[', ']'], '', $objectNode->getAttribute('Schema'));
                $op['TableName'] = str_replace(['[', ']'], '', $objectNode->getAttribute('Table'));
                $op['IndexName'] = str_replace(['[', ']'], '', $objectNode->getAttribute('Index'));
                break;
            }
        }

        // Warnings at Operator Level
        $warningNodes = $xpath->query('./s:Warnings', $node);
        if ($warningNodes->length > 0) {
            $warningNode = $warningNodes->item(0);
            foreach ($warningNode->attributes as $attr) {
                if ($attr->value === 'true' || $attr->value === '1') {
                    $op['Warnings'][] = $attr->name;
                    if ($attr->name === 'SpillToTempDb') {
                        $op['SpillToTempDb'] = true;
                    }
                }
            }
            // Check for SpillToTempDb element
            $spillNodes = $xpath->query('./s:Warnings/s:SpillToTempDb', $node);
            if ($spillNodes->length > 0) {
                $op['SpillToTempDb'] = true;
                $op['Warnings'][] = 'SpillToTempDb';
            }
        }

        // Actual RunTime stats (if actual plan - directly under current RelOp)
        $runtimeNodes = $xpath->query('./s:RunTimeInformation/s:RunTimeRanges/s:RunTimeInformation', $node);
        if ($runtimeNodes->length > 0) {
            $actualRows = 0.0;
            $actualLoops = 0.0;
            $actualRowsRead = 0.0;
            foreach ($runtimeNodes as $rtNode) {
                $actualRows += (double)$rtNode->getAttribute('ActualRows');
                $actualLoops += (double)$rtNode->getAttribute('ActualLoops');
                if ($rtNode->hasAttribute('ActualRowsRead')) {
                    $actualRowsRead += (double)$rtNode->getAttribute('ActualRowsRead');
                }
            }
            $op['ActualRows'] = $actualRows;
            $op['ActualLoops'] = $actualLoops;
            $op['ActualRowsRead'] = $actualRowsRead > 0 ? $actualRowsRead : $actualRows;
        }

        // Extract Predicates (ensuring they belong to this RelOp)
        $predNodes = $xpath->query('.//s:Predicate', $node);
        foreach ($predNodes as $predNode) {
            $parentRelOp = $xpath->query('ancestor::s:RelOp[1]', $predNode)->item(0);
            if ($parentRelOp && $parentRelOp->isSameNode($node)) {
                $cleaned = $this->cleanPredicateText($predNode->textContent);
                if (!empty($cleaned) && !in_array($cleaned, $op['Predicates'])) {
                    $op['Predicates'][] = $cleaned;
                }
            }
        }

        // Extract Seek Predicates (ensuring they belong to this RelOp)
        $seekNodes = $xpath->query('.//s:SeekPredicateNew', $node);
        if ($seekNodes->length === 0) {
            $seekNodes = $xpath->query('.//s:SeekPredicates', $node);
        }
        foreach ($seekNodes as $seekNode) {
            $parentRelOp = $xpath->query('ancestor::s:RelOp[1]', $seekNode)->item(0);
            if ($parentRelOp && $parentRelOp->isSameNode($node)) {
                $cleaned = $this->cleanPredicateText($seekNode->textContent);
                if (!empty($cleaned) && !in_array($cleaned, $op['SeekPredicates'])) {
                    $op['SeekPredicates'][] = $cleaned;
                }
            }
        }

        // Parse children of this RelOp
        // Children are direct child RelOps of this RelOp (we don't want descendant's children)
        $childRelOps = $xpath->query('.//s:RelOp', $node);
        foreach ($childRelOps as $child) {
            $parentRelOp = $xpath->query('ancestor::s:RelOp[1]', $child)->item(0);
            if ($parentRelOp && $parentRelOp->isSameNode($node)) {
                $op['Children'][] = $this->parseRelOpNode($child, $xpath, $flatOperators);
            }
        }

        // Add to flat list (keyed by NodeId)
        $flatOperators[$nodeId] = [
            'NodeId' => $op['NodeId'],
            'PhysicalOp' => $op['PhysicalOp'],
            'LogicalOp' => $op['LogicalOp'],
            'EstimatedRows' => $op['EstimatedRows'],
            'EstimatedIO' => $op['EstimatedIO'],
            'EstimatedCPU' => $op['EstimatedCPU'],
            'EstimatedTotalSubtreeCost' => $op['EstimatedTotalSubtreeCost'],
            'Parallel' => $op['Parallel'],
            'Warnings' => $op['Warnings'],
            'ActualRows' => $op['ActualRows'],
            'ActualLoops' => $op['ActualLoops'],
            'ActualRowsRead' => $op['ActualRowsRead'],
            'DatabaseName' => $op['DatabaseName'],
            'SchemaName' => $op['SchemaName'],
            'TableName' => $op['TableName'],
            'IndexName' => $op['IndexName'],
            'SeekPredicates' => $op['SeekPredicates'],
            'Predicates' => $op['Predicates'],
            'SpillToTempDb' => $op['SpillToTempDb'],
        ];

        return $op;
    }

    /**
     * Parses statement-level warnings.
     */
    private function parseStatementWarnings(DOMXPath $xpath): array {
        $warnings = [];
        $warningNodes = $xpath->query('//s:QueryPlan/s:Warnings');
        if ($warningNodes->length > 0) {
            $warningNode = $warningNodes->item(0);

            // No Join Predicate
            if ($xpath->query('./s:NoJoinPredicate', $warningNode)->length > 0) {
                $warnings[] = [
                    'Type' => 'NoJoinPredicate',
                    'Message' => 'A query warning was detected: "No Join Predicate". This can result in a Cartesian product (cross join) and severe performance degradation.'
                ];
            }

            // Columns with No Statistics
            $noStatsNodes = $xpath->query('./s:ColumnsWithNoStatistics/s:ColumnReference', $warningNode);
            if ($noStatsNodes->length > 0) {
                $columns = [];
                foreach ($noStatsNodes as $node) {
                    $db = str_replace(['[', ']'], '', $node->getAttribute('Database'));
                    $schema = str_replace(['[', ']'], '', $node->getAttribute('Schema'));
                    $table = str_replace(['[', ']'], '', $node->getAttribute('Table'));
                    $col = str_replace(['[', ']'], '', $node->getAttribute('Column'));
                    $columns[] = "$schema.$table.$col";
                }
                $warnings[] = [
                    'Type' => 'ColumnsWithNoStatistics',
                    'Message' => 'Columns with missing statistics: ' . implode(', ', array_unique($columns)),
                    'Details' => array_unique($columns)
                ];
            }

            // Implicit Conversion (PlanAffectingConvert)
            $convertNodes = $xpath->query('./s:PlanAffectingConvert', $warningNode);
            if ($convertNodes->length > 0) {
                $details = [];
                foreach ($convertNodes as $node) {
                    $expr = $node->getAttribute('Expression');
                    $issue = $node->getAttribute('ConvertIssue');
                    $details[] = "$issue for expression: $expr";
                }
                $warnings[] = [
                    'Type' => 'ImplicitConversion',
                    'Message' => 'Implicit data type conversion is preventing index usage or optimal seek paths.',
                    'Details' => $details
                ];
            }

            // Memory Grant Warning
            $memWarningNodes = $xpath->query('./s:MemoryGrantWarning', $warningNode);
            if ($memWarningNodes->length > 0) {
                foreach ($memWarningNodes as $node) {
                    $kind = $node->getAttribute('GrantWarningKind');
                    $requested = $node->getAttribute('RequestedMemory');
                    $used = $node->getAttribute('MaxUsedMemory');
                    $warnings[] = [
                        'Type' => 'MemoryGrantWarning',
                        'Message' => "Memory grant warning: $kind. (Requested: " . round($requested/1024, 2) . " MB, Max Used: " . round($used/1024, 2) . " MB)",
                        'Details' => ['Kind' => $kind, 'RequestedKB' => $requested, 'MaxUsedKB' => $used]
                    ];
                }
            }
        }
        return $warnings;
    }

    /**
     * Parses missing index recommendations.
     */
    private function parseMissingIndexes(DOMXPath $xpath): array {
        $missingIndexes = [];
        $groups = $xpath->query('//s:MissingIndexGroup');
        foreach ($groups as $group) {
            $impact = (double)$group->getAttribute('Impact');
            $indexes = $xpath->query('./s:MissingIndex', $group);
            foreach ($indexes as $index) {
                $db = str_replace(['[', ']'], '', $index->getAttribute('Database'));
                $schema = str_replace(['[', ']'], '', $index->getAttribute('Schema'));
                $table = str_replace(['[', ']'], '', $index->getAttribute('Table'));
                
                $equality = [];
                $inequality = [];
                $include = [];
                
                $colGroups = $xpath->query('./s:ColumnGroup', $index);
                foreach ($colGroups as $colGroup) {
                    $usage = $colGroup->getAttribute('Usage');
                    $cols = $xpath->query('./s:Column', $colGroup);
                    foreach ($cols as $col) {
                        $colName = str_replace(['[', ']'], '', $col->getAttribute('Name'));
                        if ($usage === 'EQUALITY') {
                            $equality[] = $colName;
                        } elseif ($usage === 'INEQUALITY') {
                            $inequality[] = $colName;
                        } elseif ($usage === 'INCLUDE') {
                            $include[] = $colName;
                        }
                    }
                }
                
                $missingIndexes[] = [
                    'Impact' => $impact,
                    'Database' => $db,
                    'Schema' => $schema,
                    'Table' => $table,
                    'EqualityColumns' => $equality,
                    'InequalityColumns' => $inequality,
                    'IncludeColumns' => $include
                ];
            }
        }
        return $missingIndexes;
    }

    /**
     * Cleans up predicate strings.
     */
    private function cleanPredicateText(string $text): string {
        $text = preg_replace('/\s+/', ' ', $text);
        // Remove brackets representing scalar variables but keep basic format
        return trim($text);
    }
}
