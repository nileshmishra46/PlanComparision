# SQL Server Execution Plan Comparator

An interactive, premium web-based diagnostic tool designed to parse, compare, and tune SQL Server `.sqlplan` execution plans. It identifies query bottlenecks, detects **Parameter Sniffing** (cardinality mismatches), and generates optimized T-SQL remediation scripts.

---

## 📸 Screenshots

 <img width="1877" height="1071" alt="image" src="https://github.com/user-attachments/assets/a99703f7-c947-42d7-bf3d-91203173832d" />
  <img width="1877" height="1072" alt="image" src="https://github.com/user-attachments/assets/339b5677-48da-4362-bc50-40fb0e601dec" />
 


---

## 🚀 Key Features

*   **Side-by-Side Plan Comparison:** Compare physical and logical execution operators side-by-side.
*   **Metric Delta Extraction:** Tracks differences in Subtree Costs, Degree of Parallelism (DOP), and Memory Grants.
*   **Warning Diagnostics:** Detects plan-affecting converts (implicit conversions), missing join predicates, and TempDB spills.
*   **Parameter Sniffing Detection:** Evaluates estimated vs. actual row counts across all plan operators. Mismatches greater than 10x flag a warning, and mismatches exceeding 50x trigger automated query-hint recommendations.
*   **Smart SQL Script Generator:** Automatically generates custom tuning scripts providing:
    *   **In-Depth Missing Indexes:** Generates covering index scripts with options for `INCLUDE` columns (Option A for lookup speed) or Key Columns only (Option B to minimize write/storage overhead).
    *   **Statistics Updates:** Generates full-scan statistics updates.
    *   **Parameter Sniffing Fixes:** Generates query rewrites using `OPTION (RECOMPILE)` or `OPTION (OPTIMIZE FOR UNKNOWN)`.

---

## 📦 Distribution & Running the App

The project supports three distinct runtime environments:

### 1. Standalone Portable Executable (No Dependencies)
Run the self-contained, standalone Windows app:
*   Launch **`ExecutionPlanComparator_Standalone.exe`**.
*   It packages its own embedded lightweight PHP runtime and all project source files. 
*   On launch, it extracts the app to a secure, temporary workspace, boots the server on `http://localhost:8000`, opens your browser, and cleans up completely upon exit.

### 2. Desktop Launcher (Requires System PHP)
Run using **`PlanComparisionLauncher.exe`**:
*   Starts the server using your local system PHP runtime (or a local `php/` folder) and loads the interface in your default browser.

### 3. Native PHP Development Server
Run directly from your command-line interface:
```bash
php -S localhost:8000
```
Open your web browser and navigate to **`http://localhost:8000`**.

---

## 🔬 Sample Plans & Testing

Mock plans are provided in the [`tests/mock_plans/`](tests/mock_plans) directory:
*   **Classic Query Regression:** Compare `good_plan.sqlplan` against `bad_plan.sqlplan` (Table Scans, TempDB spills, and missing indexes).
*   **Parameter Sniffing Mismatch:** Compare `good_sniffing_plan.sqlplan` against `bad_sniffing_plan.sqlplan` to view cardinality discrepancies and recompile hints.

To verify code correctness, run the automated test suite:
```bash
php tests/test_parser.php
```

---

## 🛠️ Project Structure

```
├── assets/                    # Styling and frontend scripts
│   ├── css/style.css
│   └── js/app.js
├── src/                       # PHP Core Parser & Comparison Engine
│   ├── PlanComparer.php       # Plan and operator differences logic
│   ├── PlanParser.php         # XML .sqlplan extraction
│   ├── RecommendationEngine.php # Diagnostic tuning recommendation engine
│   └── SqlFileGenerator.php   # T-SQL remediation script assembler
├── templates/                 # UI HTML templates
│   └── report_template.php
├── tests/                     # Test suite and test plan XML files
│   ├── mock_plans/            # Sample .sqlplan files
│   └── test_parser.php        # Parser & Recommendation tests
├── Launcher.cs                # Source code for local C# launcher
├── StandaloneLauncher.cs      # Source code for self-contained launcher
├── PlanComparisionLauncher.exe# Local desktop launcher EXE
└── ExecutionPlanComparator_Standalone.exe # Portable standalone application EXE
```

---

## 👨‍💻 Author

**Nilesh Mishra**
*   **LinkedIn:** [linkedin.com/in/nileshmishra46](https://www.linkedin.com/in/nileshmishra46)
*   **GitHub:** [@nileshmishra46](https://github.com/nileshmishra46)
