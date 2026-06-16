using System;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Windows.Forms;

namespace PlanComparatorLauncher
{
    public class LauncherForm : Form
    {
        private Process phpProcess;
        private Label lblStatus;
        private TextBox txtLog;
        private Button btnBrowser;
        private Button btnStop;
        private string phpPath = "php";

        [STAThread]
        public static void Main()
        {
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            Application.Run(new LauncherForm());
        }

        public LauncherForm()
        {
            this.Text = "SQL Server Execution Plan Comparator Launcher";
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
            lblTitle.Text = "Execution Plan Comparator";
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
            lblStatus.Text = "Checking PHP installation...";
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
            if (FindPhp())
            {
                StartServer();
            }
        }

        private bool FindPhp()
        {
            // 1. Check local php folder
            string localPhp = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, @"php\php.exe");
            if (File.Exists(localPhp))
            {
                phpPath = localPhp;
                Log("Found local PHP runtime at: " + phpPath);
                return true;
            }

            // 2. Check if php is in system path
            try
            {
                ProcessStartInfo psi = new ProcessStartInfo("php", "-v");
                psi.UseShellExecute = false;
                psi.CreateNoWindow = true;
                psi.RedirectStandardOutput = true;
                using (Process p = Process.Start(psi))
                {
                    p.WaitForExit();
                    if (p.ExitCode == 0)
                    {
                        phpPath = "php";
                        Log("Found system PHP on PATH");
                        return true;
                    }
                }
            }
            catch {}

            lblStatus.Text = "Error: PHP runtime not found.";
            lblStatus.ForeColor = Color.FromArgb(244, 63, 94); // Red/Rose
            Log("Error: PHP was not found on your system.");
            Log("To make this application portable, please create a 'php' folder inside the application directory and put the contents of a Windows PHP release (php.exe, etc.) in it.");
            MessageBox.Show("PHP runtime not found!\n\nPlease install PHP and add it to your system PATH, or download a portable zip from php.net, extract it to a folder named 'php' inside this application directory, and restart the launcher.", "PHP Not Found", MessageBoxButtons.OK, MessageBoxIcon.Error);
            return false;
        }

        private void StartServer()
        {
            try
            {
                Log("Starting PHP server on port 8000...");
                phpProcess = new Process();
                phpProcess.StartInfo.FileName = phpPath;
                phpProcess.StartInfo.Arguments = "-S localhost:8000";
                phpProcess.StartInfo.WorkingDirectory = AppDomain.CurrentDomain.BaseDirectory;
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
            if (phpProcess != null && !phpProcess.HasExited)
            {
                try
                {
                    phpProcess.Kill();
                }
                catch {}
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
