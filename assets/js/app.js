document.addEventListener('DOMContentLoaded', () => {
    // 1. Tab Switching Logic
    const tabs = document.querySelectorAll('.tab-btn');
    const panels = document.querySelectorAll('.tab-panel');

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.tab;
            
            tabs.forEach(t => t.classList.remove('active'));
            panels.forEach(p => p.classList.remove('active'));
            
            tab.classList.add('active');
            const targetPanel = document.getElementById(target);
            if (targetPanel) {
                targetPanel.classList.add('active');
            }
        });
    });

    // 2. Drag & Drop Upload Helper for XML Inputs
    setupDragAndDrop('good_file', 'good_xml', 'good-drag-zone');
    setupDragAndDrop('bad_file', 'bad_xml', 'bad-drag-zone');

    function setupDragAndDrop(fileInputId, textareaId, zoneId) {
        const fileInput = document.getElementById(fileInputId);
        const textarea = document.getElementById(textareaId);
        const zone = document.getElementById(zoneId);
        
        if (!fileInput || !textarea || !zone) return;

        // Click triggers input click
        zone.addEventListener('click', () => fileInput.click());

        // File selected
        fileInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) handleFile(file, textarea, zone);
        });

        // Drag events
        ['dragenter', 'dragover'].forEach(eventName => {
            zone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                zone.style.borderColor = 'var(--color-cyan)';
                zone.style.background = 'rgba(6, 182, 212, 0.08)';
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            zone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                zone.style.borderColor = 'var(--border-card)';
                zone.style.background = 'rgba(255, 255, 255, 0.02)';
            }, false);
        });

        zone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            const file = dt.files[0];
            if (file) handleFile(file, textarea, zone);
        });
    }

    function handleFile(file, textarea, zone) {
        if (!file.name.endsWith('.sqlplan') && !file.name.endsWith('.xml') && !file.name.endsWith('.txt')) {
            showToast("Please upload a .sqlplan or XML file.", "error");
            return;
        }

        const reader = new FileReader();
        reader.onload = (e) => {
            textarea.value = e.target.result;
            // Update drop zone text
            const labelText = zone.querySelector('.file-upload-text');
            if (labelText) {
                labelText.innerHTML = `Loaded: <strong>${file.name}</strong> (${formatBytes(file.size)})`;
            }
            showToast(`Loaded ${file.name} successfully!`, "success");
        };
        reader.readAsText(file);
    }

    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // 3. Copy SQL Code to Clipboard
    window.copyToClipboard = function(btn, elementId) {
        const codeElement = document.getElementById(elementId);
        if (!codeElement) return;

        navigator.clipboard.writeText(codeElement.textContent)
            .then(() => {
                const originalText = btn.innerHTML;
                btn.innerHTML = `
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    Copied!
                `;
                btn.style.color = 'var(--color-emerald)';
                showToast("SQL fix script copied to clipboard!");
                
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.style.color = '';
                }, 2000);
            })
            .catch(err => {
                showToast("Failed to copy text: " + err, "error");
            });
    };

    // 4. Collapsible Tree Nodes
    const treeNodes = document.querySelectorAll('.tree-node');
    treeNodes.forEach(node => {
        const toggle = node.querySelector('.collapsible-toggle');
        if (toggle) {
            node.addEventListener('click', (e) => {
                // If they clicked the toggle or we want the whole node to toggle children
                e.stopPropagation();
                const nodeWrapper = node.parentElement;
                
                node.classList.toggle('tree-node-collapsed');
                if (node.classList.contains('tree-node-collapsed')) {
                    toggle.textContent = '+';
                } else {
                    toggle.textContent = '-';
                }
            });
        }
    });

    // 5. Toast System
    function showToast(message, type = "success") {
        let toast = document.getElementById('toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'toast';
            toast.className = 'toast-msg';
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        if (type === "error") {
            toast.style.background = 'var(--color-rose)';
        } else {
            toast.style.background = 'var(--color-emerald)';
        }

        toast.classList.add('show');

        setTimeout(() => {
            toast.classList.remove('show');
        }, 3000);
    }
});
