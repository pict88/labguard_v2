<?php
// LabSentinel Monitor - Integrated PHP & MySQL Dashboard
// php -S 0.0.0.0:8000 -t /home/exam/Desktop

// 1. API Endpoint: Status Polling
if (isset($_GET['api']) && $_GET['api'] === 'status') {
    header('Content-Type: application/json');
    
    $conn = mysqli_connect('localhost', 'exam', 'exam', 'Labguard');
    if (!$conn) {
        echo json_encode(['error' => mysqli_connect_error()]);
        exit;
    }
    
    $sql = "
        SELECT 
            i.ip AS ip_address, 
            i.status AS internet_status, 
            i.time AS internet_time, 
            COALESCE(u.device, 'None') AS usb_dev_id, 
            COALESCE(u.status, 'idle') AS usb_status, 
            COALESCE(u.time, i.time) AS usb_time
        FROM internet i
        LEFT JOIN usb u ON i.ip = u.ip
    ";
    
    $result = mysqli_query($conn, $sql);
    $workstations = [];
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            // Strip the subnet mask (/24) BEFORE sanitizing the IP
            $clean_ip_string = explode('/', $row['ip_address'])[0];
            $safe_ip = preg_replace('/[^0-9.]/', '', $clean_ip_string);
            
            $ping_file = '/tmp/labsentinel_pings/' . $safe_ip;
            
            // Calculate how many seconds ago the C2 cron job pinged the server
            if (file_exists($ping_file)) {
                $row['c2_elapsed'] = time() - (int)file_get_contents($ping_file);
            } else {
                $row['c2_elapsed'] = null;
            }
            
            $workstations[] = $row;
        }
    }
    
    mysqli_close($conn);
    echo json_encode($workstations);
    exit;
}

// 2. API Endpoint: Clear Session
if (isset($_GET['api']) && $_GET['api'] === 'clear') {
    header('Content-Type: application/json');
    
    $conn = mysqli_connect('localhost', 'exam', 'exam', 'Labguard');
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => mysqli_connect_error()]);
        exit;
    }
    
    $truncateUsb = mysqli_query($conn, "TRUNCATE TABLE usb");
    $truncateInternet = mysqli_query($conn, "TRUNCATE TABLE internet");
    
    if ($truncateUsb && $truncateInternet) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    
    mysqli_close($conn);
    exit;
}

// 3. API Endpoint: Check Pending Commands (File-Based C2)
if (isset($_GET['api']) && $_GET['api'] === 'check_cmd') {
    header('Content-Type: text/plain'); 
    
    $client_ip = $_SERVER['REMOTE_ADDR'];
    $safe_ip = preg_replace('/[^0-9.]/', '', $client_ip); // Sanitize IP
    
    // Record the exact time this endpoint checked in for C2 instructions
    if (!is_dir('/tmp/labsentinel_pings')) {
        mkdir('/tmp/labsentinel_pings', 0777, true);
    }
    file_put_contents('/tmp/labsentinel_pings/' . $safe_ip, time());

    $cmd_file = '/tmp/labsentinel_cmds/' . $safe_ip . '.txt';

    if (file_exists($cmd_file)) {
        // Read the command, send it to the endpoint, and delete the file
        echo file_get_contents($cmd_file);
        unlink($cmd_file); 
    }
    
    exit;
}

// 4. API Endpoint: Queue a Command (Triggered by dashboard buttons)
if (isset($_GET['api']) && $_GET['api'] === 'queue_cmd' && isset($_GET['ip']) && isset($_GET['cmd'])) {
    header('Content-Type: application/json');
    
    $target_ip = preg_replace('/[^0-9.]/', '', $_GET['ip']); // Sanitize IP
    $requested_cmd = $_GET['cmd'];
    
    if (!$target_ip) {
        echo json_encode(['success' => false, 'error' => 'Invalid IP']);
        exit;
    }

    if ($requested_cmd === 'warn_user') {
        if (!is_dir('/tmp/labsentinel_cmds')) {
            mkdir('/tmp/labsentinel_cmds', 0777, true);
        }
        $cmd_file = '/tmp/labsentinel_cmds/' . $target_ip . '.txt';
        
        // Grab custom message, default if empty
        $custom_msg = (isset($_GET['msg']) && trim($_GET['msg']) !== '') ? trim($_GET['msg']) : "Unauthorized activity detected. Please return to your exam.";
        
        // CRITICAL: Escape the input so bash treats it purely as a string parameter
        $safe_msg = escapeshellarg($custom_msg);
        
        // Construct the safe bash command with full GUI environment variables for cron
        $bash_cmd = 'sudo -u ubuntu DISPLAY=:0 DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/1000/bus XDG_RUNTIME_DIR=/run/user/1000 XAUTHORITY=/home/ubuntu/.Xauthority zenity --question --title="Exam Proctor Warning" --width=400 --text=' . $safe_msg;
        
        file_put_contents($cmd_file, $bash_cmd);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid command']);
    }
    exit;
}

// 5. API Endpoint: New Tab Log Viewer (Data Table Version)
if (isset($_GET['api']) && $_GET['api'] === 'logs' && isset($_GET['ip'])) {
    $requestedIp = preg_replace('/[^0-9.]/', '', $_GET['ip']); // Sanitize IP input
    $logFile = '/home/kali/Desktop/SERVER/Logs.log';
    $filteredLogs = [];

    if (file_exists($logFile)) {
        $handle = fopen($logFile, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (strpos($line, "[ReqIP: " . $requestedIp . "]") !== false) {
                    $filteredLogs[] = trim($line);
                }
            }
            fclose($handle);
        }
    } else {
        $filteredLogs[] = "[-] System Error: Master log file not found at " . $logFile;
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Audit Trail: <?= htmlspecialchars($requestedIp) ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
        <style>
            body { background: #0f172a; color: #a5b4fc; font-family: 'Plus Jakarta Sans', sans-serif; margin: 0; padding: 0; display: flex; flex-direction: column; height: 100vh; }
            .header { background: #1e293b; padding: 16px 24px; border-bottom: 1px solid #334155; display: flex; align-items: center; justify-content: space-between; }
            .title { font-size: 1.2rem; font-weight: 700; color: #fff; font-family: 'JetBrains Mono', monospace; }
            .title span { color: #4f46e5; }
            
            .controls { background: #1e293b; padding: 12px 24px; border-bottom: 1px solid #334155; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
            .filter-label { color: #94a3b8; font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-right: 8px; }
            
            /* FILTER BUTTON THEMES */
            .log-filter-btn { border-radius: 6px; padding: 6px 14px; font-size: 0.8rem; font-family: 'JetBrains Mono', monospace; cursor: pointer; transition: 0.2s; border: 1px solid transparent; }
            
            .btn-all { background: rgba(245, 158, 11, 0.1); color: #fbbf24; border-color: rgba(245, 158, 11, 0.3); }
            .btn-all:hover { background: rgba(245, 158, 11, 0.2); }
            .btn-all.active { background: #f59e0b; color: #1e293b; border-color: #f59e0b; font-weight: 700; }

            .btn-exit { background: rgba(239, 68, 68, 0.1); color: #fca5a5; border-color: rgba(239, 68, 68, 0.3); }
            .btn-exit:hover { background: rgba(239, 68, 68, 0.2); }
            .btn-exit.active { background: #ef4444; color: #ffffff; border-color: #ef4444; }

            .btn-net { background: rgba(59, 130, 246, 0.1); color: #93c5fd; border-color: rgba(59, 130, 246, 0.3); }
            .btn-net:hover { background: rgba(59, 130, 246, 0.2); }
            .btn-net.active { background: #3b82f6; color: #ffffff; border-color: #3b82f6; }

            .btn-usb { background: rgba(168, 85, 247, 0.1); color: #d8b4fe; border-color: rgba(168, 85, 247, 0.3); }
            .btn-usb:hover { background: rgba(168, 85, 247, 0.2); }
            .btn-usb.active { background: #a855f7; color: #ffffff; border-color: #a855f7; }
            
            .table-container { padding: 0 24px 24px 24px; overflow-y: auto; flex-grow: 1; }
            .soc-table { width: 100%; border-collapse: collapse; text-align: left; }
            .soc-table th { background: #1e293b; color: #94a3b8; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; padding: 16px; border-bottom: 2px solid #334155; position: sticky; top: 0; z-index: 50; }
            .soc-table td { padding: 12px 16px; border-bottom: 1px solid #1e293b; color: #cbd5e1; font-size: 0.85rem; font-family: 'JetBrains Mono', monospace; }
            .soc-table tr:hover { background: #162032; }
            
            .empty-state { text-align: center; padding: 40px; color: #64748b; font-family: 'JetBrains Mono', monospace; }

            .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
            .badge-network { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }
            .badge-usb { background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); }
            
            .badge-success { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
            .badge-danger { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
            .badge-warning { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
            .badge-neutral { background: #334155; color: #cbd5e1; border: 1px solid #475569; }
            
            .pwd-text { color: #f87171; font-style: italic; background: rgba(239, 68, 68, 0.1); padding: 0 4px; border-radius: 3px; }
            .action-text { font-weight: 700; color: #94a3b8; }
            
            .vendor-name { font-weight: 700; color: #e2e8f0; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 0.9rem; margin-bottom: 2px;}
            .vendor-id { font-size: 0.75rem; color: #64748b; }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="title">Audit Trail: <span><?= htmlspecialchars($requestedIp) ?></span></div>
        </div>
        <div class="controls">
            <span class="filter-label">Quick Filter:</span>
            
            <button class="log-filter-btn btn-all active" onclick="applyLogFilter('all', this)">All</button>
            
            <button class="log-filter-btn btn-net" onclick="applyLogFilter('network', this)">NETWORK</button>
            <button class="log-filter-btn btn-net" onclick="applyLogFilter('global_attempted', this)">global_attempted</button>
            <button class="log-filter-btn btn-net" onclick="applyLogFilter('isolated', this)">isolated</button>
            <button class="log-filter-btn btn-net" onclick="applyLogFilter('authenticated', this)">authenticated</button>
            
            <button class="log-filter-btn btn-usb" onclick="applyLogFilter('usb', this)">USB</button>
            <button class="log-filter-btn btn-usb" onclick="applyLogFilter('usb_attempt', this)">usb_attempt</button>
            <button class="log-filter-btn btn-usb" onclick="applyLogFilter('blocked', this)">blocked</button>
            <button class="log-filter-btn btn-usb" onclick="applyLogFilter('authorized', this)">authorized</button>
            
            <button class="log-filter-btn btn-exit" onclick="applyLogFilter('LAB_EXIT', this)">LAB_EXIT</button>
        </div>
        
        <div class="table-container" id="table-container">
            <table class="soc-table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Module</th>
                        <th>Action</th>
                        <th>Details (Status / Password)</th>
                        <th>Device Vendor & ID</th>
                    </tr>
                </thead>
                <tbody id="log-table-body">
                    <!-- Data injected via JS -->
                </tbody>
            </table>
        </div>

        <script>
            const rawLogs = <?= json_encode($filteredLogs) ?>;
            const tbody = document.getElementById('log-table-body');
            const container = document.getElementById('table-container');

            const usbVendors = {
                "0951": "Kingston",
                "0781": "SanDisk",
                "04e8": "Samsung",
                "05dc": "Lexar",
                "13fe": "Phison / Toshiba",
                "090c": "Silicon Motion",
                "1e68": "Trek Technology",
                "1516": "CompUSA",
                "0c76": "JMTek",
                "1f75": "Innostor",
                "0ea0": "Ours Technology",
                "058f": "Alcor Micro",
                "125f": "A-DATA",
                "1005": "Apacer",
                "0b05": "ASUS",
                "1b1c": "Corsair",
                "03f0": "HP",
                "abcd": "Generic Brand (abcd)",
                "ffff": "Spoofed / Custom (ffff)" 
            };
            
            function parseLogLine(line) {
                if(line.startsWith('[-]')) {
                    return { error: line }; 
                }

                const tsMatch = line.match(/^\[(.*?)\]/);
                const timestamp = tsMatch ? tsMatch[1] : '-';
                
                let parsedData = { timestamp: timestamp, module: '-', action: '-', status: '-', password: '', device: '-' };
                
                if (line.includes('] ip=')) {
                    const kvString = line.substring(line.indexOf('] ip=') + 5);
                    const pairs = kvString.split(', ');
                    
                    pairs.forEach(pair => {
                        const [key, value] = pair.split('=');
                        if (key && value !== undefined) {
                            parsedData[key.trim()] = value.trim();
                        }
                    });
                }
                return parsedData;
            }

            function getModuleBadge(moduleStr) {
                if (moduleStr === 'network') return '<span class="badge badge-network"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg> NETWORK</span>';
                if (moduleStr === 'usb') return '<span class="badge badge-usb"><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M15 7v4h1v2h-3V5h2l-3-4-3 4h2v8H8v-2h1V7H6v6c0 1.1.9 2 2 2h3v3H9v2h6v-2h-2v-3h3c1.1 0 2-.9 2-2V7h-2z"/></svg> USB</span>';
                return moduleStr;
            }

            function getStatusBadge(action, status, password) {
                if (action === 'auth') {
                    const pwdDisplay = password ? password : 'NO_INPUT';
                    return `<span class="badge badge-neutral">AUTH ATTEMPT: <span class="pwd-text">${pwdDisplay}</span></span>`;
                }
                
                const s = status.toLowerCase();
                if (s === 'authorized' || s === 'authenticated') return `<span class="badge badge-success">${status}</span>`;
                if (s === 'blocked' || s === 'isolated' || s === 'lab_exit') return `<span class="badge badge-danger">${status}</span>`;
                if (s === 'global_attempted' || s === 'usb_attempt') return `<span class="badge badge-warning">${status}</span>`;
                
                return `<span class="badge badge-neutral">${status}</span>`;
            }

            function getDeviceDisplay(deviceId) {
                if (!deviceId || deviceId === '-') {
                    return '<span style="opacity: 0.3;">N/A</span>';
                }
                
                const vid = deviceId.split(':')[0].toLowerCase();
                const vendorName = usbVendors[vid] || "Unknown Vendor";
                
                return `
                    <div class="vendor-name">${vendorName}</div>
                    <div class="vendor-id">ID: ${deviceId}</div>
                `;
            }

            function renderTable(linesToRender) {
                if (linesToRender.length === 0 || (linesToRender.length === 1 && linesToRender[0].startsWith('[-]'))) {
                    const msg = linesToRender.length > 0 ? linesToRender[0] : "[-] No logs match the filter criteria.";
                    tbody.innerHTML = `<tr><td colspan="5" class="empty-state">${msg}</td></tr>`;
                    return;
                }

                let html = '';
                linesToRender.forEach(line => {
                    const obj = parseLogLine(line);
                    if(obj.error) return;

                    html += `
                        <tr>
                            <td style="color: #64748b;">${obj.timestamp}</td>
                            <td>${getModuleBadge(obj.module)}</td>
                            <td class="action-text">${obj.action.toUpperCase()}</td>
                            <td>${getStatusBadge(obj.action, obj.status, obj.password)}</td>
                            <td>${getDeviceDisplay(obj.device)}</td>
                        </tr>
                    `;
                });

                tbody.innerHTML = html;
                container.scrollTop = container.scrollHeight; 
            }

            function applyLogFilter(keyword, btnElement) {
                document.querySelectorAll('.log-filter-btn').forEach(btn => btn.classList.remove('active'));
                btnElement.classList.add('active');

                if (keyword === 'all') {
                    renderTable(rawLogs);
                } else {
                    const filtered = rawLogs.filter(line => line.includes(keyword));
                    renderTable(filtered);
                }
            }
            
            applyLogFilter('all', document.querySelector('.log-filter-btn.active'));
        </script>
    </body>
    </html>
    <?php
    exit; 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LabSentinel Infrastructure Monitor</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Syne:wght@700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        /* MAIN DASHBOARD CSS */
        :root {
            --bg-main: #f8fafc;
            --surface: #ffffff;
            --text-primary: #0f172a;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --accent-glow: rgba(79, 70, 229, 0.25);
            --green-bg: #d1fae5;
            --green-text: #065f46;
            --yellow-bg: #fef3c7;
            --yellow-text: #92400e;
            --red-bg: #fee2e2;
            --red-text: #991b1b;
        }

        body { 
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; 
            background: var(--bg-main); 
            color: var(--text-primary); 
            padding: 24px; 
            margin: 0; 
            -webkit-font-smoothing: antialiased;
        }

        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .brand-header { display: flex; align-items: center; gap: 14px; }
        .brand-icon { width: 44px; height: 44px; background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%); color: #fff; border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 8px 20px var(--accent-glow); position: relative; overflow: hidden; }
        .brand-icon::after { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent); animation: shine 4s infinite; }
        @keyframes shine { 0% { left: -100%; } 20% { left: 100%; } 100% { left: 100%; } }
        .brand-title-group { display: flex; flex-direction: column; }
        h2 { margin: 0; color: var(--text-primary); font-family: 'Syne', sans-serif; font-weight: 800; font-size: 1.6rem; letter-spacing: -0.03em; background: linear-gradient(135deg, #0f172a 30%, #4f46e5 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        
        .system-status-badge { font-size: 0.75rem; font-family: 'JetBrains Mono', monospace; background: #eef2ff; color: #4338ca; padding: 6px 12px; border-radius: 20px; font-weight: 700; border: 1px solid #c7d2fe; display: flex; align-items: center; gap: 6px; min-width: 140px; }
        .system-status-badge::before { content: ''; width: 8px; height: 8px; background: #4f46e5; border-radius: 50%; display: inline-block; box-shadow: 0 0 8px #4f46e5; animation: pulse-dot 2s infinite; }
        @keyframes pulse-dot { 0% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(0.85); } 100% { opacity: 1; transform: scale(1); } }

        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .metric-card { padding: 20px; border-radius: 14px; border: 1px solid; box-shadow: 0 1px 3px rgba(0,0,0,0.04); display: flex; flex-direction: column; gap: 8px; transition: transform 0.2s; }
        .metric-card:hover { transform: translateY(-2px); }
        .metric-title { font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .metric-value { font-size: 2rem; font-weight: 800; font-family: 'JetBrains Mono', monospace; line-height: 1; }
        
        .card-total { background-color: #eef2ff; border-color: #c7d2fe; }
        .card-total .metric-title { color: #4338ca; }
        .card-total .metric-value { color: #3730a3; }

        .card-online { background-color: #ecfdf5; border-color: #a7f3d0; }
        .card-online .metric-title { color: #047857; }
        .card-online .metric-value { color: #065f46; }

        .card-isolated { background-color: #fffbeb; border-color: #fde68a; }
        .card-isolated .metric-title { color: #b45309; }
        .card-isolated .metric-value { color: #92400e; }

        .card-offline { background-color: #fef2f2; border-color: #fecaca; }
        .card-offline .metric-title { color: #b91c1c; }
        .card-offline .metric-value { color: #991b1b; }

        .card-alerts { background-color: #fff1f2; border-color: #fecdd3; }
        .card-alerts .metric-title { color: #be123c; }
        .card-alerts .metric-value { color: #e11d48; }

        .toolbar { display: flex; justify-content: space-between; align-items: center; background: var(--surface); padding: 12px 20px; border-radius: 14px; border: 1px solid var(--border-color); margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); flex-wrap: wrap; gap: 16px; }
        .toolbar-left { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .toolbar-right { display: flex; align-items: center; width: 100%; max-width: 300px; }
        .search-input { border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; font-size: 0.9em; font-family: 'Plus Jakarta Sans', sans-serif; outline: none; transition: all 0.2s ease; width: 100%; }
        .search-input:focus, .filter-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12); }
        .filter-select { border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; font-size: 0.9em; font-family: 'Plus Jakarta Sans', sans-serif; background-color: var(--surface); outline: none; cursor: pointer; transition: all 0.2s ease; }
        .clear-btn { background-color: #fef2f2; border: 1px solid #fca5a5; color: var(--red-text); border-radius: 10px; padding: 10px 16px; font-size: 0.9em; font-weight: 700; font-family: 'Plus Jakarta Sans', sans-serif; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; gap: 6px; }
        .clear-btn:hover { background-color: var(--red-bg); border-color: #ef4444; }

        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
        .workstation-card { background: var(--surface); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02), 0 2px 4px -2px rgba(0,0,0,0.02); transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; }
        .workstation-card:hover { transform: translateY(-3px); box-shadow: 0 12px 24px -6px rgba(15, 23, 42, 0.08); border-color: #cbd5e1; }
        .device-ip-header { font-size: 1.1em; font-weight: 700; color: var(--text-primary); margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px dashed var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .ip-display { font-family: 'JetBrains Mono', monospace; letter-spacing: -0.5px; display: block; }
        .ip-prefix { color: var(--text-secondary); font-weight: 600; } .ip-host { color: var(--primary); font-weight: 700; background: #eef2ff; padding: 2px 6px; border-radius: 4px; }
        
        /* C2 TIMER STYLES */
        .c2-countdown { font-size: 0.75rem; font-family: 'JetBrains Mono', monospace; color: #94a3b8; margin-top: 4px; font-weight: 700; display: block; transition: color 0.3s ease;}
        .c2-active { color: #10b981; }
        .c2-overdue { color: #f59e0b; animation: pulse-text 2s infinite; }
        @keyframes pulse-text { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }

        .log-btn { background: #f1f5f9; border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 8px; padding: 6px 10px; font-size: 0.75rem; font-weight: 700; font-family: 'Plus Jakarta Sans', sans-serif; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 4px; text-decoration: none; }
        .log-btn:hover { background: #e2e8f0; color: var(--primary); border-color: #cbd5e1; }

        .module-box { padding: 12px 14px; border-radius: 12px; color: #ffffff; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; box-shadow: inset 0 1px 0 rgba(255,255,255,0.2); transition: filter 0.2s; }
        .module-box:last-child { margin-bottom: 0; }
        .module-left { display: flex; align-items: center; gap: 12px; }
        .module-icon { width: 22px; height: 22px; flex-shrink: 0; fill: currentColor; }
        .module-details { display: flex; flex-direction: column; line-height: 1.3; }
        .module-status-text { font-size: 0.88em; font-weight: 700; font-family: 'JetBrains Mono', monospace; text-transform: uppercase; letter-spacing: 0.4px; }
        .module-subtext { font-size: 0.76em; opacity: 0.92; margin-top: 1px; font-family: 'JetBrains Mono', monospace; }
        .module-time { font-size: 0.78em; font-family: 'JetBrains Mono', monospace; opacity: 0.95; font-weight: 600; text-align: right; background: rgba(0,0,0,0.12); padding: 3px 6px; border-radius: 6px; }

        .status-green { background-color: #10b981 !important; } .status-yellow { background-color: #f59e0b !important; } .status-red { background-color: #ef4444 !important; }
        .empty-state { grid-column: 1 / -1; text-align: center; padding: 50px 20px; background: var(--surface); border: 1px dashed var(--border-color); border-radius: 16px; color: var(--text-secondary); font-weight: 500; }
    </style>
</head>
<body>

    <div class="top-bar">
        <div class="brand-header">
            <div class="brand-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    <circle cx="12" cy="11" r="3"/>
                </svg>
            </div>
            <div class="brand-title-group">
                <h2>LabSentinel</h2>
                <div style="font-size: 0.8em; color: var(--text-secondary); font-weight: 500;">Real-time Workstation & Peripherals Telemetry</div>
            </div>
        </div>
        <div class="system-status-badge">
            <span id="timer-text">NEXT UPDATE: --s</span>
        </div>
    </div>

    <div class="metrics-grid">
        <div class="metric-card card-total">
            <span class="metric-title">Total</span>
            <span class="metric-value" id="sum-total">0</span>
        </div>
        <div class="metric-card card-online">
            <span class="metric-title">Online</span>
            <span class="metric-value" id="sum-online">0</span>
        </div>
        <div class="metric-card card-isolated">
            <span class="metric-title">Isolated</span>
            <span class="metric-value" id="sum-isolated">0</span>
        </div>
        <div class="metric-card card-offline">
            <span class="metric-title">Offline</span>
            <span class="metric-value" id="sum-offline">0</span>
        </div>
        <div class="metric-card card-alerts">
            <span class="metric-title">USB Alerts</span>
            <span class="metric-value" id="sum-alerts">0</span>
        </div>
    </div>

    <div class="toolbar">
        <div class="toolbar-left">
            <select id="filter-select" class="filter-select" onchange="filterWorkstations()">
                <option value="all">All Workstations</option>
                <option value="online">Online Only</option>
                <option value="isolated">Isolated Only</option>
                <option value="offline">Offline Only</option>
                <option value="usb-alerts">Active USB Alerts</option>
            </select>
            
            <button class="clear-btn" onclick="clearSession()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                Clear Session
            </button>
        </div>
        
        <div class="toolbar-right">
            <input type="text" id="search-input" class="search-input" placeholder="Search IP or device..." onkeyup="filterWorkstations()">
        </div>
    </div>

    <div id="workstation-grid" class="grid"></div>

    <script>
        const POLLING_INTERVAL_SECONDS = 5; 
        let cachedWorkstations = [];
        let currentCountdown = POLLING_INTERVAL_SECONDS;

        function getStatusClass(status) {
            switch(status.toLowerCase()) {
                case 'online': case 'idle': case 'no-usb': return 'status-green';
                case 'isolated': case 'blocked': case 'block': return 'status-yellow';
                case 'offline': case 'authorized': case 'authorised': case 'authenticated': return 'status-red';
                default: return 'status-green';
            }
        }

        function formatIPAddress(fullIP) {
            let cleanIP = fullIP.split('/')[0].trim();
            let parts = cleanIP.split('.');
            if (parts.length === 4) {
                let prefix = parts.slice(0, 3).join('.');
                let host = parts[3];
                return `<span class="ip-prefix">${prefix}.</span><span class="ip-host">${host}</span>`;
            }
            return cleanIP;
        }

        function extractCleanIP(fullIP) {
            return fullIP.split('/')[0].trim();
        }

        function ipToNum(ip) {
            return ip.split('/')[0].trim().split('.').reduce((acc, octet) => ((acc << 8) + parseInt(octet, 10)), 0) >>> 0;
        }

        function getInternetIconSVG() {
            return `<svg class="module-icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>`;
        }

        function getUSBIconSVG() {
            return `<svg class="module-icon" viewBox="0 0 24 24"><path d="M15 7v4h1v2h-3V5h2l-3-4-3 4h2v8H8v-2h1V7H6v6c0 1.1.9 2 2 2h3v3H9v2h6v-2h-2v-3h3c1.1 0 2-.9 2-2V7h-2z"/></svg>`;
        }

        function updateDashboard(workstations) {
            cachedWorkstations = workstations;
            cachedWorkstations.sort((a, b) => ipToNum(a.ip_address) - ipToNum(b.ip_address));

            let total = cachedWorkstations.length;
            let online = 0, isolated = 0, offline = 0, alerts = 0;

            cachedWorkstations.forEach(ws => {
                if (ws.internet_status === 'online') online++;
                if (ws.internet_status === 'isolated') isolated++;
                if (ws.internet_status === 'offline') offline++;
                if (ws.usb_status !== 'idle' && ws.usb_status !== 'no-usb') alerts++;
            });

            document.getElementById('sum-total').innerText = total;
            document.getElementById('sum-online').innerText = online;
            document.getElementById('sum-isolated').innerText = isolated;
            document.getElementById('sum-offline').innerText = offline;
            document.getElementById('sum-alerts').innerText = alerts;

            filterWorkstations();
        }

        function renderGrid(workstations) {
            const grid = document.getElementById('workstation-grid');

            if (workstations.length === 0) {
                grid.innerHTML = `<div class="empty-state">No matching workstations found matching your criteria.</div>`;
                return;
            }

            let gridHTML = '';

            workstations.forEach(ws => {
                const netClass = getStatusClass(ws.internet_status);
                const usbClass = getStatusClass(ws.usb_status);
                const formattedIP = formatIPAddress(ws.ip_address);
                const cleanIP = extractCleanIP(ws.ip_address);
                
                // Calculate starting value for the C2 countdown
                const c2Remaining = ws.c2_elapsed !== null ? 60 - ws.c2_elapsed : 'idle';
                
                gridHTML += `
                    <div class="workstation-card">
                        <div class="device-ip-header">
                            <div>
                                <span class="ip-display">${formattedIP}</span>
                                <span class="c2-countdown" data-remaining="${c2Remaining}">C2: --</span>
                            </div>
                            <div style="display: flex; gap: 6px;">
                                <button class="log-btn" style="color: #b45309; border-color: #fcd34d; background: #fffbeb;" onclick="promptAndWarn('${cleanIP}')">Warn Student</button>
                                <button class="log-btn" onclick="window.open('?api=logs&ip=${cleanIP}', '_blank')">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                                </button>
                            </div>
                        </div>
                        
                        <div class="module-box ${netClass}">
                            <div class="module-left">
                                ${getInternetIconSVG()}
                                <div class="module-details">
                                    <span class="module-status-text">${ws.internet_status}</span>
                                    <span class="module-subtext">Network Status</span>
                                </div>
                            </div>
                            <div class="module-time">${ws.internet_time}</div>
                        </div>

                        <div class="module-box ${usbClass}">
                            <div class="module-left">
                                ${getUSBIconSVG()}
                                <div class="module-details">
                                    <span class="module-status-text">${ws.usb_status}</span>
                                    <span class="module-subtext">ID: ${ws.usb_dev_id}</span>
                                </div>
                            </div>
                            <div class="module-time">${ws.usb_time}</div>
                        </div>
                    </div>
                `;
            });

            grid.innerHTML = gridHTML;
        }

        function filterWorkstations() {
            const selectedFilter = document.getElementById('filter-select').value;
            const searchQuery = document.getElementById('search-input').value.toLowerCase().trim();

            const filtered = cachedWorkstations.filter(ws => {
                let matchesCategory = true;
                if (selectedFilter === 'online') {
                    matchesCategory = (ws.internet_status === 'online');
                } else if (selectedFilter === 'isolated') {
                    matchesCategory = (ws.internet_status === 'isolated');
                } else if (selectedFilter === 'offline') {
                    matchesCategory = (ws.internet_status === 'offline');
                } else if (selectedFilter === 'usb-alerts') {
                    matchesCategory = (ws.usb_status !== 'idle' && ws.usb_status !== 'no-usb');
                }

                let matchesSearch = true;
                if (searchQuery) {
                    matchesSearch = ws.ip_address.toLowerCase().includes(searchQuery) ||
                                    ws.internet_status.toLowerCase().includes(searchQuery) ||
                                    ws.usb_dev_id.toLowerCase().includes(searchQuery) ||
                                    ws.usb_status.toLowerCase().includes(searchQuery);
                }

                return matchesCategory && matchesSearch;
            });

            renderGrid(filtered);
        }

        function promptAndWarn(ip) {
            const defaultMsg = "Unauthorized activity detected. Please return to your exam.";
            const msg = prompt(`Enter warning message to display on ${ip}:`, defaultMsg);
            
            if (msg !== null) {
                const safeUrlMsg = encodeURIComponent(msg);
                
                fetch(`?api=queue_cmd&ip=${ip}&cmd=warn_user&msg=${safeUrlMsg}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            alert(`Warning queued for ${ip}. It will display on their screen shortly.`);
                        } else {
                            alert('Error: ' + data.error);
                        }
                    })
                    .catch(err => console.error('Error queuing warning:', err));
            }
        }

        function clearSession() {
            if (confirm("Are you sure you want to clear the current session? This will wipe the active dashboard state.")) {
                fetch('?api=clear')
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            pollTelemetry();
                            currentCountdown = POLLING_INTERVAL_SECONDS;
                            updateTimerDisplay();
                        } else {
                            alert("Database Error: " + data.error);
                        }
                    })
                    .catch(err => console.error('Error clearing session:', err));
            }
        }

        function updateTimerDisplay() {
            document.getElementById('timer-text').innerText = `NEXT UPDATE: ${currentCountdown}s`;
        }

        function pollTelemetry() {
            fetch('?api=status')
                .then(res => res.json())
                .then(data => {
                    if (!data.error) updateDashboard(data);
                })
                .catch(err => console.error('Error fetching telemetry:', err));
        }

        window.onload = function() {
            pollTelemetry();
            updateTimerDisplay();
            
            // Master Timing Loop
            setInterval(() => {
                // 1. Dashboard Polling Countdown
                currentCountdown--;
                if (currentCountdown <= 0) {
                    pollTelemetry();
                    currentCountdown = POLLING_INTERVAL_SECONDS; 
                }
                updateTimerDisplay();
                
                // 2. C2 Heartbeat UI Updater
                document.querySelectorAll('.c2-countdown').forEach(el => {
                    let remAttr = el.getAttribute('data-remaining');
                    if (remAttr === 'idle') {
                        el.innerText = 'C2: IDLE (No Exam Flag)';
                        el.className = 'c2-countdown';
                    } else {
                        let rem = parseInt(remAttr);
                        rem--; // Decrease by 1 second visually
                        el.setAttribute('data-remaining', rem); // Store it back
                        
                        if (rem > 0) {
                            el.innerText = `C2 NEXT: ${rem}s`;
                            el.className = 'c2-countdown c2-active';
                        } else {
                            el.innerText = `C2: PENDING SYNC...`;
                            el.className = 'c2-countdown c2-overdue';
                        }
                    }
                });
            }, 1000);
        };
    </script>
</body>
</html>
