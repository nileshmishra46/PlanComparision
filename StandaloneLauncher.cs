using System;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Reflection;
using System.Windows.Forms;
using System.Collections.Generic;

namespace PlanComparatorLauncher
{
    public class LauncherForm : Form
    {
        private Process phpProcess;
        private Label lblStatus;
        private TextBox txtLog;
        private Button btnBrowser;
        private Button btnStop;
        private string tempDir;
        private string phpPath;

        private static readonly Dictionary<string, string> filesToExtract = new Dictionary<string, string>
        {
            { "php_exe", "php.exe" },
            { "php8ts_dll", "php8ts.dll" },
            { "index_php", "index.php" },
            { "download_php", "download.php" },
            { "src_PlanComparer_php", "src/PlanComparer.php" },
            { "src_PlanParser_php", "src/PlanParser.php" },
            { "src_RecommendationEngine_php", "src/RecommendationEngine.php" },
            { "src_SqlFileGenerator_php", "src/SqlFileGenerator.php" },
            { "templates_report_template_php", "templates/report_template.php" },
            { "assets_css_style_css", "assets/css/style.css" },
            { "assets_js_app_js", "assets/js/app.js" },
            { "tests_mock_plans_good_plan_sqlplan", "tests/mock_plans/good_plan.sqlplan" },
            { "tests_mock_plans_bad_plan_sqlplan", "tests/mock_plans/bad_plan.sqlplan" },
            { "tests_mock_plans_good_plan_v2_sqlplan", "tests/mock_plans/good_plan_v2.sqlplan" },
            { "tests_mock_plans_bad_plan_v2_sqlplan", "tests/mock_plans/bad_plan_v2.sqlplan" },
            { "tests_mock_plans_good_sniffing_plan_sqlplan", "tests/mock_plans/good_sniffing_plan.sqlplan" },
            { "tests_mock_plans_bad_sniffing_plan_sqlplan", "tests/mock_plans/bad_sniffing_plan.sqlplan" },
            { "tests_test_parser_php", "tests/test_parser.php" }
        };

        [STAThread]
        public static void Main()
        {
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            Application.Run(new LauncherForm());
        }

        public LauncherForm()
        {
            this.Text = "SQL Server Execution Plan Comparator (Portable)";
            this.Size = new Size(750, 500);
            this.StartPosition = FormStartPosition.CenterScreen;
            this.BackColor = Color.FromArgb(15, 23, 42); // Slate-900
            this.ForeColor = Color.FromArgb(248, 250, 252); // Slate-50
            this.Font = new Font("Segoe UI", 9.5F);
            this.MinimumSize = new Size(600, 400);

            InitializeComponents();
        }

        private void InitializeComponents()
        {
            // Panel for Header
            Panel headerPanel = new Panel();
            headerPanel.Dock = DockStyle.Top;
            headerPanel.Height = 80;
            headerPanel.BackColor = Color.FromArgb(30, 41, 59); // Slate-800
            this.Controls.Add(headerPanel);

            // Title Label
            Label lblTitle = new Label();
            lblTitle.Text = "Execution Plan Comparator (Standalone)";
            lblTitle.Font = new Font("Segoe UI", 16F, FontStyle.Bold);
            lblTitle.ForeColor = Color.FromArgb(6, 182, 212); // Cyan-500
            lblTitle.Location = new Point(15, 12);
            lblTitle.AutoSize = true;
            headerPanel.Controls.Add(lblTitle);

            // Developer Label
            Label lblDev = new Label();
            lblDev.Text = "Developed by Nilesh Mishra";
            lblDev.Font = new Font("Segoe UI", 9F, FontStyle.Italic);
            lblDev.ForeColor = Color.FromArgb(148, 163, 184); // Slate-400
            lblDev.Location = new Point(16, 46);
            lblDev.AutoSize = true;
            headerPanel.Controls.Add(lblDev);

            // Status Panel / Info
            Panel statusPanel = new Panel();
            statusPanel.Dock = DockStyle.Top;
            statusPanel.Height = 50;
            this.Controls.Add(statusPanel);

            lblStatus = new Label();
            lblStatus.Text = "Extracting embedded application files...";
            lblStatus.Font = new Font("Segoe UI", 10F, FontStyle.Regular);
            lblStatus.Location = new Point(15, 15);
            lblStatus.AutoSize = true;
            statusPanel.Controls.Add(lblStatus);

            // Log Textbox
            txtLog = new TextBox();
            txtLog.Multiline = true;
            txtLog.ReadOnly = true;
            txtLog.ScrollBars = ScrollBars.Vertical;
            txtLog.BackColor = Color.FromArgb(2, 6, 23); // Slate-950
            txtLog.ForeColor = Color.FromArgb(148, 163, 184); // Slate-400
            txtLog.Font = new Font("Consolas", 9F);
            txtLog.Dock = DockStyle.Fill;
            this.Controls.Add(txtLog);

            // Bottom Buttons Panel
            Panel btnPanel = new Panel();
            btnPanel.Dock = DockStyle.Bottom;
            btnPanel.Height = 60;
            btnPanel.BackColor = Color.FromArgb(30, 41, 59); // Slate-800
            this.Controls.Add(btnPanel);

            btnBrowser = new Button();
            btnBrowser.Text = "Open in Browser";
            btnBrowser.FlatStyle = FlatStyle.Flat;
            btnBrowser.FlatAppearance.BorderSize = 1;
            btnBrowser.FlatAppearance.BorderColor = Color.FromArgb(6, 182, 212);
            btnBrowser.ForeColor = Color.FromArgb(6, 182, 212);
            btnBrowser.Size = new Size(150, 32);
            btnBrowser.Location = new Point(15, 14);
            btnBrowser.Click += OpenBrowser;
            btnBrowser.Enabled = false;
            btnPanel.Controls.Add(btnBrowser);

            btnStop = new Button();
            btnStop.Text = "Stop & Exit";
            btnStop.FlatStyle = FlatStyle.Flat;
            btnStop.FlatAppearance.BorderSize = 1;
            btnStop.FlatAppearance.BorderColor = Color.FromArgb(244, 63, 94);
            btnStop.ForeColor = Color.FromArgb(244, 63, 94);
            btnStop.Size = new Size(120, 32);
            btnStop.Location = new Point(600, 14);
            btnStop.Anchor = AnchorStyles.Right | AnchorStyles.Top;
            btnStop.Click += StopAndExit;
            btnPanel.Controls.Add(btnStop);

            this.Load += Form_Load;
            this.FormClosing += Form_FormClosing;
        }

        private void Form_Load(object sender, EventArgs e)
        {
            if (ExtractAllFiles())
            {
                StartServer();
            }
        }

        private bool ExtractAllFiles()
        {
            try
            {
                // Create a unique temporary directory
                string uniqueId = Guid.NewGuid().ToString("N").Substring(0, 8);
                tempDir = Path.Combine(Path.GetTempPath(), "SQLPlanComparator_" + uniqueId);
                Directory.CreateDirectory(tempDir);
                Log("Created temporary workspace: " + tempDir);

                Assembly assembly = Assembly.GetExecutingAssembly();

                foreach (var kvp in filesToExtract)
                {
                    string resourceName = kvp.Key;
                    string destPath = Path.Combine(tempDir, kvp.Value.Replace('/', Path.DirectorySeparatorChar));

                    // Ensure subdirectory exists
                    string destDir = Path.GetDirectoryName(destPath);
                    if (!Directory.Exists(destDir))
                    {
                        Directory.CreateDirectory(destDir);
                    }

                    // Extract resource
                    using (Stream stream = assembly.GetManifestResourceStream(resourceName))
                    {
                        if (stream == null)
                        {
                            throw new Exception("Resource not found in assembly: " + resourceName);
                        }

                        using (FileStream fs = new FileStream(destPath, FileMode.Create, FileAccess.Write))
                        {
                            byte[] buffer = new byte[8192];
                            int read;
                            while ((read = stream.Read(buffer, 0, buffer.Length)) > 0)
                            {
                                fs.Write(buffer, 0, read);
                            }
                        }
                    }
                }

                phpPath = Path.Combine(tempDir, "php.exe");
                Log("All application files extracted successfully.");
                return true;
            }
            catch (Exception ex)
            {
                lblStatus.Text = "Extraction failed.";
                lblStatus.ForeColor = Color.FromArgb(244, 63, 94);
                Log("Error during extraction: " + ex.Message);
                MessageBox.Show("Failed to extract application files:\n\n" + ex.Message, "Extraction Error", MessageBoxButtons.OK, MessageBoxIcon.Error);
                return false;
            }
        }

        private void StartServer()
        {
            try
            {
                Log("Starting PHP server on port 8000...");
                phpProcess = new Process();
                phpProcess.StartInfo.FileName = phpPath;
                phpProcess.StartInfo.Arguments = "-S localhost:8000";
                phpProcess.StartInfo.WorkingDirectory = tempDir;
                phpProcess.StartInfo.UseShellExecute = false;
                phpProcess.StartInfo.CreateNoWindow = true;
                phpProcess.StartInfo.RedirectStandardOutput = true;
                phpProcess.StartInfo.RedirectStandardError = true;

                phpProcess.OutputDataReceived += (s, ev) => Log(ev.Data);
                phpProcess.ErrorDataReceived += (s, ev) => Log(ev.Data);

                phpProcess.Start();
                phpProcess.BeginOutputReadLine();
                phpProcess.BeginErrorReadLine();

                lblStatus.Text = "Server running at http://localhost:8000";
                lblStatus.ForeColor = Color.FromArgb(16, 185, 129); // Green/Emerald
                btnBrowser.Enabled = true;

                Log("PHP Server started successfully.");
                Log("--------------------------------------------------");

                // Automatically launch browser
                OpenBrowser(null, null);
            }
            catch (Exception ex)
            {
                lblStatus.Text = "Failed to start PHP server.";
                lblStatus.ForeColor = Color.FromArgb(244, 63, 94);
                Log("Exception while starting server: " + ex.Message);
            }
        }

        private void OpenBrowser(object sender, EventArgs e)
        {
            try
            {
                Process.Start(new ProcessStartInfo("cmd", "/c start http://localhost:8000") { CreateNoWindow = true });
            }
            catch (Exception ex)
            {
                Log("Failed to open browser: " + ex.Message);
            }
        }

        private void StopAndExit(object sender, EventArgs e)
        {
            this.Close();
        }

        private void Form_FormClosing(object sender, FormClosingEventArgs e)
        {
            Log("Stopping PHP server...");
            if (phpProcess != null && !phpProcess.HasExited)
            {
                try
                {
                    phpProcess.Kill();
                    phpProcess.WaitForExit(1000);
                }
                catch {}
            }

            Log("Cleaning up temporary workspace...");
            if (!string.IsNullOrEmpty(tempDir) && Directory.Exists(tempDir))
            {
                for (int i = 0; i < 5; i++)
                {
                    try
                    {
                        Directory.Delete(tempDir, true);
                        break;
                    }
                    catch
                    {
                        System.Threading.Thread.Sleep(200); // Wait in case files are locked
                    }
                }
            }
        }

        private void Log(string text)
        {
            if (string.IsNullOrEmpty(text)) return;
            if (txtLog.InvokeRequired)
            {
                txtLog.Invoke(new Action<string>(Log), text);
                return;
            }
            txtLog.AppendText(text + Environment.NewLine);
        }
    }
}
