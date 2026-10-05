<?php
// LabSentinel Monitor - Integrated PHP & MySQL Dashboard
// php -S 0.0.0.0:8000 -t /home/exam/Desktop

// 1. API Endpoint: Status Polling
if (isset($_GET['api']) &&$_GET['api'] === 'status') {
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
    $result = mysqli_query($conn, $sql);$workstations = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $clean_ip_string = explode('/',$row['ip_address'])[0];
            $safe_ip = preg_replace('/[^0-9.]/', '',$clean_ip_string);
            
            $ping_file = '/tmp/labsentinel_pings/'.$safe_ip;
            if (file_exists($ping_file)) {
                $row['c2_elapsed'] = time() - (int)file_get_contents($ping_file);
            } else {
                $row['c2_elapsed'] = null;
            }
            $workstations[] =$row;
        }
    }
    mysqli_close($conn);
    echo json_encode($workstations);
    exit;
}

// 2. API Endpoint: Clear Session
if (isset($_GET['api']) &&$_GET['api'] === 'clear') {
    header('Content-Type: application/json');
    $conn = mysqli_connect('localhost', 'exam', 'exam', 'Labguard');
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => mysqli_connect_error()]);
        exit;
    }
    $truncateUsb = mysqli_query($conn, "TRUNCATE TABLE usb");
    $truncateInternet = mysqli_query($conn, "TRUNCATE TABLE internet");
    
    $logFile = '/home/exam/Desktop/Server/Logs.log';
    if (file_exists($logFile)) {
        file_put_contents($logFile, '');
    }
    
    if ($truncateUsb &&$truncateInternet) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    mysqli_close($conn);
    exit;
}

// 3. API Endpoint: Check Pending Commands (File-Based C2)
if (isset($_GET['api']) &&$_GET['api'] === 'check_cmd') {
    header('Content-Type: text/plain');
    $client_ip =$_SERVER['REMOTE_ADDR'];
    $safe_ip = preg_replace('/[^0-9.]/', '',$client_ip);
    
    if (!is_dir('/tmp/labsentinel_pings')) {
        mkdir('/tmp/labsentinel_pings', 0777, true);
    }
    file_put_contents('/tmp/labsentinel_pings/'.$safe_ip, time());
    
    $cmd_file = '/tmp/labsentinel_cmds/'.$safe_ip.'.txt';
    if (file_exists($cmd_file)) {
        echo file_get_contents($cmd_file);
        unlink($cmd_file);
    }
    exit;
}

// 4. API Endpoint: Queue a Command
if (isset($_GET['api']) &&$_GET['api'] === 'queue_cmd' && isset($_GET['ip']) && isset($_GET['cmd'])) {
    header('Content-Type: application/json');
    $target_ip = preg_replace('/[^0-9.]/', '',$_GET['ip']);
    $requested_cmd =$_GET['cmd'];
    
    if (!$target_ip) {
        echo json_encode(['success' => false, 'error' => 'Invalid IP']);
        exit;
    }
    
    if ($requested_cmd === 'warn_user') {
        if (!is_dir('/tmp/labsentinel_cmds')) {
            mkdir('/tmp/labsentinel_cmds', 0777, true);
        }
        $cmd_file = '/tmp/labsentinel_cmds/'.$target_ip.'.txt';
        $custom_msg = (isset($_GET['msg']) && trim($_GET['msg']) !== '') ? trim($_GET['msg']) : "Unauthorized activity detected. Please return to your exam.";
        $safe_msg = escapeshellarg($custom_msg);$bash_cmd = 'sudo -u ubuntu DISPLAY=:0 DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/1000/bus XDG_RUNTIME_DIR=/run/user/1000 XAUTHORITY=/home/ubuntu/.Xauthority zenity --question --title="Exam Proctor Warning" --width=400 --text='.$safe_msg;
        file_put_contents($cmd_file,$bash_cmd);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid command']);
    }
    exit;
}

// 5. API Endpoint: New Tab Log Viewer (Maintains dark SOC theme for logs)
if (isset($_GET['api']) && $_GET['api'] === 'logs' && isset($_GET['ip'])) {
    $requestedIp = preg_replace('/[^0-9.]/', '',$_GET['ip']);
    $logFile = '/home/exam/Desktop/Server/Logs.log';$filteredLogs = [];
    
    if (file_exists($logFile)) {
        $handle = fopen($logFile, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (strpos($line, "[ReqIP: ".$requestedIp."]") !== false) {
                    $filteredLogs[] = trim($line);
                }
            }
            fclose($handle);
        }
    } else {
        $filteredLogs[] = "[-] System Error: Master log file not found at ".$logFile;
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Logs Captured From: <?= htmlspecialchars($requestedIp) ?></title>
<link href="https://fonts.googleapis.com/css?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<style>
    :root {
        --bg-deep: #090d16;
        --bg-surface: #111827;
        --border-subtle: #1f293d;
        --accent-indigo: #6366f1;
        --text-main: #f3f4f6;
        --text-muted: #9ca3af;
    }
    body { background: var(--bg-deep); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; margin: 0; padding: 0; display: flex; flex-direction: column; height: 100vh; }
    .audit-header { background: var(--bg-surface); padding: 20px 32px; border-bottom: 1px solid var(--border-subtle); display: flex; align-items: center; justify-content: space-between; box-shadow: 0 4px 20px rgba(0,0,0,0.4); }
    .audit-title { font-size: 1.25rem; font-weight: 700; color: #ffffff; font-family: 'JetBrains Mono', monospace; display: flex; align-items: center; gap: 10px; }
    .audit-title span { color: var(--accent-indigo); background: rgba(99, 102, 241, 0.1); padding: 4px 10px; border-radius: 6px; border: 1px solid rgba(99, 102, 241, 0.2); }
    .controls { background: #0d1322; padding: 16px 32px; border-bottom: 1px solid var(--border-subtle); display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .filter-label { color: var(--text-muted); font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-right: 12px; }
    
    .log-filter-btn { border-radius: 8px; padding: 8px 16px; font-size: 0.75rem; font-family: 'JetBrains Mono', monospace; cursor: pointer; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid var(--border-subtle); background: var(--bg-surface); color: var(--text-muted); font-weight: 600; text-transform: uppercase; }
    .log-filter-btn:hover { opacity: 0.9; color: #fff; }
    .log-filter-btn.active { color: #ffffff; box-shadow: 0 0 15px rgba(0,0,0,0.5); font-weight: 700; }

    .filter-all { border-color: #475569; } .filter-all.active { background: #475569; border-color: #64748b; }
    .filter-network { border-color: rgba(59, 130, 246, 0.4); color: #60a5fa; } .filter-network.active { background: #3b82f6; border-color: #3b82f6; color: #fff; }
    .filter-global { border-color: rgba(245, 158, 11, 0.4); color: #fbbf24; } .filter-global.active { background: #f59e0b; border-color: #f59e0b; color: #fff; }
    .filter-isolated { border-color: rgba(239, 68, 68, 0.4); color: #f87171; } .filter-isolated.active { background: #ef4444; border-color: #ef4444; color: #fff; }
    .filter-auth { border-color: rgba(168, 85, 247, 0.4); color: #c084fc; } .filter-auth.active { background: #a855f7; border-color: #a855f7; color: #fff; }
    .filter-usb { border-color: rgba(168, 85, 247, 0.4); color: #c084fc; } .filter-usb.active { background: #a855f7; border-color: #a855f7; color: #fff; }
    .filter-usb-att { border-color: rgba(245, 158, 11, 0.4); color: #fbbf24; } .filter-usb-att.active { background: #f59e0b; border-color: #f59e0b; color: #fff; }
    .filter-blocked { border-color: rgba(239, 68, 68, 0.4); color: #f87171; } .filter-blocked.active { background: #ef4444; border-color: #ef4444; color: #fff; }
    .filter-authorized { border-color: rgba(16, 185, 129, 0.4); color: #34d399; } .filter-authorized.active { background: #10b981; border-color: #10b981; color: #fff; }
    .filter-exit { border-color: rgba(239, 68, 68, 0.4); color: #f87171; } .filter-exit.active { background: #ef4444; border-color: #ef4444; color: #fff; }

    .table-container { padding: 24px 32px; overflow-y: auto; flex-grow: 1; }
    .soc-table { width: 100%; border-collapse: separate; border-spacing: 0; text-align: left; background: var(--bg-surface); border-radius: 12px; border: 1px solid var(--border-subtle); overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
    .soc-table th { background: #161f33; color: var(--text-muted); font-weight: 700; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 1px; padding: 16px 20px; border-bottom: 1px solid var(--border-subtle); position: sticky; top: 0; z-index: 50; }
    .soc-table td { padding: 14px 20px; border-bottom: 1px solid var(--border-subtle); color: #e5e7eb; font-size: 0.85rem; font-family: 'JetBrains Mono', monospace; }
    .soc-table tr:last-child td { border-bottom: none; }
    .soc-table tr:hover td { background: rgba(255, 255, 255, 0.02); }
    
    .empty-state { text-align: center; padding: 60px; color: var(--text-muted); font-family: 'JetBrains Mono', monospace; font-size: 0.9rem; }
    .badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 6px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
    .badge-network { background: rgba(59, 130, 246, 0.1); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.2); }
    .badge-usb { background: rgba(168, 85, 247, 0.1); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.2); }
    .badge-success { background: rgba(16, 185, 129, 0.1); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.2); }
    .badge-danger { background: rgba(239, 68, 68, 0.1); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.2); }
    .badge-warning { background: rgba(245, 158, 11, 0.1); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.2); }
    .badge-neutral { background: rgba(51, 65, 85, 0.4); color: #cbd5e1; border: 1px solid rgba(71, 85, 105, 0.4); }
    .pwd-text { color: #f87171; font-style: italic; background: rgba(239, 68, 68, 0.1); padding: 2px 6px; border-radius: 4px; }
    .action-text { font-weight: 700; color: #94a3b8; }
    .vendor-name { font-weight: 700; color: #f3f4f6; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 0.85rem; margin-bottom: 2px; }
    .vendor-id { font-size: 0.7rem; color: var(--text-muted); }
</style>
</head>
<body>
<div class="audit-header">
    <div class="audit-title">Logs Captured From: <span><?= htmlspecialchars($requestedIp) ?></span></div>
</div>
<div class="controls">
    <span class="filter-label">Filter Stream:</span>
    <button class="log-filter-btn filter-all active" onclick="applyLogFilter('all', this)">All</button>
    <button class="log-filter-btn filter-network" onclick="applyLogFilter('network', this)">Network</button>
    <button class="log-filter-btn filter-global" onclick="applyLogFilter('global_attempted', this)">Global Attempted</button>
    <button class="log-filter-btn filter-isolated" onclick="applyLogFilter('isolated', this)">Isolated</button>
    <button class="log-filter-btn filter-auth" onclick="applyLogFilter('authenticated', this)">Authenticated</button>
    <button class="log-filter-btn filter-usb" onclick="applyLogFilter('usb', this)">USB</button>
    <button class="log-filter-btn filter-usb-att" onclick="applyLogFilter('usb_attempt', this)">USB Attempt</button>
    <button class="log-filter-btn filter-blocked" onclick="applyLogFilter('blocked', this)">Blocked</button>
    <button class="log-filter-btn filter-authorized" onclick="applyLogFilter('authorized', this)">Authorized</button>
    <button class="log-filter-btn filter-exit" onclick="applyLogFilter('LAB_EXIT', this)">LAB_EXIT</button>
</div>
<div class="table-container" id="table-container">
    <table class="soc-table">
        <thead>
            <tr>
                <th>Timestamp</th>
                <th>Module</th>
                <th>Action</th>
                <th>Status / Context</th>
                <th>Device Vendor & ID</th>
            </tr>
        </thead>
        <tbody id="log-table-body"></tbody>
    </table>
</div>
<script>
const rawLogs = <?= json_encode($filteredLogs) ?>;
const tbody = document.getElementById('log-table-body');
const container = document.getElementById('table-container');
const usbVendors = {
    "0951": "Kingston", "0781": "SanDisk", "04e8": "Samsung", "05dc": "Lexar",
    "13fe": "Phison / Toshiba", "090c": "Silicon Motion", "1e68": "Trek Technology",
    "1516": "CompUSA", "0c76": "JMTek", "1f75": "Innostor", "0ea0": "Ours Technology",
    "058f": "Alcor Micro", "125f": "A-DATA", "1005": "Apacer", "0b05": "ASUS",
    "1b1c": "Corsair", "03f0": "HP", "abcd": "Generic Brand", "ffff": "Spoofed / Custom"
};

function parseLogLine(line) {
    if(line.startsWith('[-]')) { return { error: line }; }
    const tsMatch = line.match(/^\[(.*?)\]/);
    const timestamp = tsMatch ? tsMatch[1] : '-';
    let parsedData = { timestamp: timestamp, module: '-', action: '-', status: '-', password: '', device: '-' };
    if (line.includes('] ip=')) {
        const kvString = line.substring(line.indexOf('] ip=') + 5);
        const pairs = kvString.split(', ');
        pairs.forEach(pair => {
            const [key, value] = pair.split('=');
            if (key && value !== undefined) { parsedData[key.trim()] = value.trim(); }
        });
    }
    return parsedData;
}

function getModuleBadge(moduleStr) {
    if (moduleStr === 'network') return '<span class="badge badge-network">Network</span>';
    if (moduleStr === 'usb') return '<span class="badge badge-usb">USB</span>';
    return `<span class="badge badge-neutral">${moduleStr}</span>`;
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
    if (!deviceId || deviceId === '-') { return '<span style="opacity: 0.3;">N/A</span>'; }
    const vid = deviceId.split(':')[0].toLowerCase();
    const vendorName = usbVendors[vid] || "Unknown Vendor";
    return `<div class="vendor-name">${vendorName}</div><div class="vendor-id">ID: ${deviceId}</div>`;
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
        html += `<tr>
            <td style="color: var(--text-muted);">${obj.timestamp}</td>
            <td>${getModuleBadge(obj.module)}</td>
            <td class="action-text">${obj.action.toUpperCase()}</td>
            <td>${getStatusBadge(obj.action, obj.status, obj.password)}</td>
            <td>${getDeviceDisplay(obj.device)}</td>
        </tr>`;
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
<link href="https://fonts.googleapis.com/css?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Syne:wght@700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<style>
    :root {
        --bg-main: #f4f6f9;
        --surface: #ffffff;
        --text-primary: #1f2937;
        --text-secondary: #6b7280;
        --border-color: #e5e7eb;
        --primary: #4f46e5;
        --accent-glow: rgba(79, 70, 229, 0.15);
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
    .brand-header { display: flex; align-items: center; gap: 12px; }
    
    .brand-icon { width: 38px; height: 38px; background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%); color: #fff; border-radius: 10px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px var(--accent-glow); position: relative; overflow: hidden; }
    .brand-icon::after { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent); animation: shine 4s infinite; }
    @keyframes shine { 0% { left: -100%; } 20% { left: 100%; } 100% { left: 100%; } }
    
    .brand-title-group { display: flex; flex-direction: column; }
    h2 { margin: 0; color: var(--text-primary); font-family: 'Syne', sans-serif; font-weight: 800; font-size: 1.4rem; letter-spacing: -0.03em; }
    .system-status-badge { font-size: 0.75rem; font-family: 'JetBrains Mono', monospace; background: #e0e7ff; color: #4338ca; padding: 6px 12px; border-radius: 20px; font-weight: 700; border: 1px solid #c7d2fe; display: flex; align-items: center; gap: 6px; }
    .system-status-badge::before { content: ''; width: 8px; height: 8px; background: #4f46e5; border-radius: 50%; display: inline-block; box-shadow: 0 0 8px #4f46e5; animation: pulse-dot 2s infinite; }
    @keyframes pulse-dot { 0% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(0.85); } 100% { opacity: 1; transform: scale(1); } }
    
    /* Metrics Grid & Cards with Integrated Status Indicators (Pulsing Dots) */
    .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
    .metric-card { background: var(--surface); border-radius: 14px; padding: 18px 20px; border: 1px solid; box-shadow: 0 4px 12px rgba(0,0,0,0.04); display: flex; flex-direction: column; gap: 8px; position: relative; overflow: hidden; transition: transform 0.2s; }
    .metric-card:hover { transform: translateY(-2px); }
    
    .metric-header-row { display: flex; justify-content: space-between; align-items: center; }
    .metric-title { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; font-family: 'Plus Jakarta Sans', sans-serif; }
    
    /* Live Pulsing Dot Indicator Style */
    .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; position: relative; }
    .status-dot::after { content: ''; position: absolute; top: -2px; left: -2px; right: -2px; bottom: -2px; border-radius: 50%; animation: live-pulse 1.8s infinite; opacity: 0.7; }
    @keyframes live-pulse { 0% { transform: scale(1); opacity: 0.8; } 50% { transform: scale(1.8); opacity: 0; } 100% { transform: scale(1); opacity: 0; } }

    .metric-value { font-size: 1.8rem; font-weight: 800; font-family: 'JetBrains Mono', monospace; line-height: 1; color: #111827; }

    /* Color Codes & Dot Colors for Metric Cards */
    .card-total { border-color: rgba(99, 102, 241, 0.3); }
    .card-total .metric-title { color: #6366f1; }
    .card-total .status-dot { background-color: #6366f1; }
    .card-total .status-dot::after { background-color: #6366f1; }

    .card-online { border-color: rgba(16, 185, 129, 0.3); }
    .card-online .metric-title { color: #059669; }
    .card-online .status-dot { background-color: #10b981; }
    .card-online .status-dot::after { background-color: #10b981; }

    .card-isolated { border-color: rgba(245, 158, 11, 0.3); }
    .card-isolated .metric-title { color: #d97706; }
    .card-isolated .status-dot { background-color: #f59e0b; }
    .card-isolated .status-dot::after { background-color: #f59e0b; }

    .card-offline { border-color: rgba(239, 68, 68, 0.3); }
    .card-offline .metric-title { color: #dc2626; }
    .card-offline .status-dot { background-color: #ef4444; }
    .card-offline .status-dot::after { background-color: #ef4444; }

    .card-alerts { border-color: rgba(244, 63, 94, 0.3); }
    .card-alerts .metric-title { color: #e11d48; }
    .card-alerts .status-dot { background-color: #f43f5e; }
    .card-alerts .status-dot::after { background-color: #f43f5e; }

    .toolbar { display: flex; justify-content: space-between; align-items: center; background: var(--surface); padding: 12px 20px; border-radius: 14px; border: 1px solid var(--border-color); margin-bottom: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); flex-wrap: wrap; gap: 16px; }
    .toolbar-left { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    .toolbar-right { display: flex; align-items: center; width: 100%; max-width: 300px; }
    .search-input, .filter-select { border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; font-size: 0.9rem; font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f9fafb; color: #1f2937; outline: none; transition: all 0.2s ease; width: 100%; }
    .search-input:focus, .filter-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15); background-color: #fff; }
    
    .clear-btn { background-color: #fef2f2; border: 1px solid #fecaca; color: #dc2626; border-radius: 10px; padding: 10px 16px; font-size: 0.9rem; font-weight: 700; font-family: 'Plus Jakarta Sans', sans-serif; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; gap: 6px; }
    .clear-btn:hover { background-color: #fee2e2; border-color: #f87171; }

    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
    .workstation-card { background: var(--surface); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; box-shadow: 0 8px 24px rgba(0,0,0,0.04); transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; }
    .workstation-card:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(0,0,0,0.08); border-color: #cbd5e1; }
    
    .device-ip-header { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px dashed var(--border-color); display: flex; justify-content: space-between; align-items: center; }
    .ip-display { font-family: 'JetBrains Mono', monospace; letter-spacing: -0.5px; }
    .ip-prefix { color: var(--text-secondary); font-weight: 600; } 
    .ip-host { color: #4f46e5; font-weight: 700; background: #e0e7ff; padding: 2px 6px; border-radius: 4px; }
    
    .c2-countdown { display: none; }

    .log-btn { background: #f3f4f6; border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 8px; padding: 6px 12px; font-size: 0.75rem; font-weight: 700; font-family: 'Plus Jakarta Sans', sans-serif; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
    .log-btn:hover { background: #e5e7eb; color: #111827; border-color: #d1d5db; }

    .module-box { padding: 12px 14px; border-radius: 12px; color: #ffffff; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; box-shadow: inset 0 1px 0 rgba(255,255,255,0.2); }
    .module-box:last-child { margin-bottom: 0; }
    .module-left { display: flex; align-items: center; gap: 12px; }
    .module-icon { width: 20px; height: 20px; flex-shrink: 0; fill: currentColor; }
    .module-details { display: flex; flex-direction: column; line-height: 1.3; }
    .module-status-text { font-size: 0.85rem; font-weight: 700; font-family: 'JetBrains Mono', monospace; text-transform: uppercase; letter-spacing: 0.4px; }
    .module-subtext { font-size: 0.72rem; opacity: 0.9; margin-top: 1px; font-family: 'JetBrains Mono', monospace; }
    .module-time { font-size: 0.75rem; font-family: 'JetBrains Mono', monospace; opacity: 0.95; font-weight: 600; background: rgba(0,0,0,0.15); padding: 3px 6px; border-radius: 6px; }

    .status-green { background-color: #059669 !important; } 
    .status-yellow { background-color: #d97706 !important; } 
    .status-red { background-color: #dc2626 !important; }
    
    .empty-state { grid-column: 1/-1; text-align: center; padding: 50px 20px; background: var(--surface); border: 1px dashed var(--border-color); border-radius: 16px; color: var(--text-secondary); font-weight: 500; }
</style>
</head>
<body>
<div class="top-bar">
    <div class="brand-header">
        <div class="brand-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                <circle cx="12" cy="11" r="3"/>
            </svg>
        </div>
        <div class="brand-title-group">
            <h2>LabSentinel</h2>
            <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 500;">Real-time Telemetry Monitor</div>
        </div>
    </div>
    <div class="system-status-badge">
        <span id="timer-text">NEXT UPDATE: --s</span>
    </div>
</div>

<div class="metrics-grid">
    <div class="metric-card card-total">
        <div class="metric-header-row">
            <span class="metric-title">Total</span>
            <span class="status-dot"></span>
        </div>
        <span class="metric-value" id="sum-total">0</span>
    </div>
    <div class="metric-card card-online">
        <div class="metric-header-row">
            <span class="metric-title">Online</span>
            <span class="status-dot"></span>
        </div>
        <span class="metric-value" id="sum-online">0</span>
    </div>
    <div class="metric-card card-isolated">
        <div class="metric-header-row">
            <span class="metric-title">Isolated</span>
            <span class="status-dot"></span>
        </div>
        <span class="metric-value" id="sum-isolated">0</span>
    </div>
    <div class="metric-card card-offline">
        <div class="metric-header-row">
            <span class="metric-title">Offline</span>
            <span class="status-dot"></span>
        </div>
        <span class="metric-value" id="sum-offline">0</span>
    </div>
    <div class="metric-card card-alerts">
        <div class="metric-header-row">
            <span class="metric-title">USB Alerts</span>
            <span class="status-dot"></span>
        </div>
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
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
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
    return `<svg class="module-icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2 .9 2 2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>`;
}

function getUSBIconSVG() {
    return `<svg class="module-icon" viewBox="0 0 24 24"><path d="M15 7v4h1v2h-3V5h2l-3-4-3 4h2v8H8v-2h1V7H6v6c0 1.1.9 2 2 2h3v3H9v2h6v-2h-2v-3h3c1.1 0 2-.9 2-2V7h-2z"/></svg>`;
}

function updateDashboard(workstations) {
    cachedWorkstations = workstations;
    cachedWorkstations.sort((a,b)=>ipToNum(a.ip_address)-ipToNum(b.ip_address));
    
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
        grid.innerHTML = '<div class="empty-state">No matching workstations found matching your criteria.</div>';
        return;
    }
    let gridHTML = '';
    workstations.forEach(ws => {
        const netClass = getStatusClass(ws.internet_status);
        const usbClass = getStatusClass(ws.usb_status);
        const formattedIP = formatIPAddress(ws.ip_address);
        const cleanIP = extractCleanIP(ws.ip_address);
        const c2Remaining = ws.c2_elapsed !== null ? 60 - ws.c2_elapsed : 'idle';

        gridHTML += `
        <div class="workstation-card">
            <div class="device-ip-header">
                <div>
                    <span class="ip-display">${formattedIP}</span>
                    <span class="c2-countdown" data-remaining="${c2Remaining}"></span>
                </div>
                <div>
                    <a class="log-btn" href="?api=logs&ip=${cleanIP}" target="_blank">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                        View Logs
                    </a>
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
        </div>`;
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
    
    setInterval(() => {
        currentCountdown--;
        if (currentCountdown <= 0) {
            pollTelemetry();
            currentCountdown = POLLING_INTERVAL_SECONDS;
        }
        updateTimerDisplay();
        
        document.querySelectorAll('.c2-countdown').forEach(el => {
            let remAttr = el.getAttribute('data-remaining');
            if (remAttr !== 'idle') {
                let rem = parseInt(remAttr);
                rem--;
                el.setAttribute('data-remaining', rem);
            }
        });
    }, 1000);
};
</script>
</body>
</html>
