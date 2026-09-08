<?php
// LabSentinel Monitor - Integrated PHP & MySQL Dashboard
// php -S 0.0.0.0:8000 -t /home/exam/Desktop

if (isset($_GET['api']) && $_GET['api'] === 'status') {
    header('Content-Type: application/json');
    
    $conn = mysqli_connect('localhost', 'exam', 'exam', 'Labguard');
    if (!$conn) {
        echo json_encode(['error' => mysqli_connect_error()]);
        exit;
    }
    
    // UPDATED: u.device instead of u.devid to match your MySQL table schema
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
            $workstations[] = $row;
        }
    }
    
    mysqli_close($conn);
    echo json_encode($workstations);
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

        .dashboard-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .brand-header {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
            color: #fff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 20px var(--accent-glow);
            position: relative;
            overflow: hidden;
        }

        .brand-icon::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shine 4s infinite;
        }

        @keyframes shine {
            0% { left: -100%; }
            20% { left: 100%; }
            100% { left: 100%; }
        }

        .brand-title-group {
            display: flex;
            flex-direction: column;
        }

        h2 { 
            margin: 0; 
            color: var(--text-primary); 
            font-family: 'Syne', sans-serif;
            font-weight: 800; 
            font-size: 1.6rem;
            letter-spacing: -0.03em; 
            background: linear-gradient(135deg, #0f172a 30%, #4f46e5 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .system-status-badge {
            font-size: 0.75rem;
            font-family: 'JetBrains Mono', monospace;
            background: #eef2ff;
            color: #4338ca;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 700;
            border: 1px solid #c7d2fe;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .system-status-badge::before {
            content: '';
            width: 8px;
            height: 8px;
            background: #4f46e5;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 8px #4f46e5;
            animation: pulse-dot 2s infinite;
        }

        @keyframes pulse-dot {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
            100% { opacity: 1; transform: scale(1); }
        }
        
        .dashboard-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            flex-wrap: wrap; 
            gap: 16px; 
            margin-bottom: 28px; 
        }

        .summary-container { 
            background: var(--surface); 
            padding: 16px 20px; 
            border-radius: 14px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.02); 
            border: 1px solid var(--border-color);
            display: flex; 
            align-items: center; 
            flex-wrap: wrap; 
            gap: 20px; 
            flex-grow: 1; 
        }
        
        .controls-container { 
            background: var(--surface); 
            padding: 12px 18px; 
            border-radius: 14px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.04); 
            border: 1px solid var(--border-color);
            display: flex; 
            align-items: center; 
            gap: 12px; 
            flex-wrap: wrap; 
        }

        .search-input { 
            border: 1px solid var(--border-color); 
            border-radius: 10px; 
            padding: 10px 14px; 
            font-size: 0.9em; 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            outline: none; 
            transition: all 0.2s ease;
            width: 210px;
        }
        .search-input:focus, .filter-select:focus { 
            border-color: var(--primary); 
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12); 
        }
        
        .filter-select { 
            border: 1px solid var(--border-color); 
            border-radius: 10px; 
            padding: 10px 14px; 
            font-size: 0.9em; 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: var(--surface); 
            outline: none; 
            cursor: pointer; 
            transition: all 0.2s ease; 
        }

        .summary-item { font-size: 0.9em; font-weight: 600; color: var(--text-secondary); display: flex; align-items: center; gap: 6px; }
        .summary-item span { font-weight: 700; padding: 3px 8px; border-radius: 6px; font-family: 'JetBrains Mono', monospace; font-size: 0.85em; }
        
        .badge-total { background: #f1f5f9; color: var(--text-primary); }
        .badge-green { background: var(--green-bg); color: var(--green-text); }
        .badge-yellow { background: var(--yellow-bg); color: var(--yellow-text); }
        .badge-red { background: var(--red-bg); color: var(--red-text); }
        
        .grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); 
            gap: 20px; 
        }
        
        .workstation-card { 
            background: var(--surface); 
            border: 1px solid var(--border-color); 
            border-radius: 16px; 
            padding: 20px; 
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02), 0 2px 4px -2px rgba(0,0,0,0.02); 
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); 
            position: relative;
            overflow: hidden;
        }
        
        .workstation-card:hover { 
            transform: translateY(-3px); 
            box-shadow: 0 12px 24px -6px rgba(15, 23, 42, 0.08); 
            border-color: #cbd5e1;
        }
        
        .device-ip-header { 
            font-size: 1.1em; 
            font-weight: 700; 
            color: var(--text-primary); 
            margin-bottom: 14px; 
            padding-bottom: 10px; 
            border-bottom: 1px dashed var(--border-color); 
            text-align: center; 
            font-family: 'JetBrains Mono', monospace; 
            letter-spacing: -0.5px; 
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .ip-prefix { color: var(--text-secondary); font-weight: 600; }
        .ip-host { color: var(--primary); font-weight: 700; background: #eef2ff; padding: 2px 6px; border-radius: 4px; }

        .module-box { 
            padding: 12px 14px; 
            border-radius: 12px; 
            color: #ffffff; 
            margin-bottom: 12px; 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.2); 
            transition: filter 0.2s;
        }
        .module-box:last-child { margin-bottom: 0; }
        
        .module-left { display: flex; align-items: center; gap: 12px; }
        .module-icon { width: 22px; height: 22px; flex-shrink: 0; fill: currentColor; }
        
        .module-details { display: flex; flex-direction: column; line-height: 1.3; }
        .module-status-text { font-size: 0.88em; font-weight: 700; font-family: 'JetBrains Mono', monospace; text-transform: uppercase; letter-spacing: 0.4px; }
        .module-subtext { font-size: 0.76em; opacity: 0.92; margin-top: 1px; font-family: 'JetBrains Mono', monospace; }
        
        .module-time { font-size: 0.78em; font-family: 'JetBrains Mono', monospace; opacity: 0.95; font-weight: 600; text-align: right; background: rgba(0,0,0,0.12); padding: 3px 6px; border-radius: 6px; }

        .status-green { background-color: #10b981 !important; }
        .status-yellow { background-color: #f59e0b !important; }
        .status-red { background-color: #ef4444 !important; }

        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 50px 20px;
            background: var(--surface);
            border: 1px dashed var(--border-color);
            border-radius: 16px;
            color: var(--text-secondary);
            font-weight: 500;
        }
    </style>
</head>
<body>

    <div class="dashboard-top">
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
        <div class="system-status-badge">LIVE POLLING ACTIVE (5s)</div>
    </div>

    <div class="dashboard-header">
        <div class="summary-container">
            <div class="summary-item">Total Workstations: <span id="sum-total" class="badge-total">0</span></div>
            <div class="summary-item">Online: <span id="sum-online" class="badge-green">0</span></div>
            <div class="summary-item">Isolated: <span id="sum-isolated" class="badge-yellow">0</span></div>
            <div class="summary-item">Offline: <span id="sum-offline" class="badge-red">0</span></div>
            <div class="summary-item">Active USB Alerts: <span id="sum-alerts" class="badge-yellow">0</span></div>
        </div>
        <div class="controls-container">
            <select id="filter-select" class="filter-select" onchange="filterWorkstations()">
                <option value="all">All Workstations</option>
                <option value="online">Online Only</option>
                <option value="isolated">Isolated Only</option>
                <option value="offline">Offline Only</option>
                <option value="usb-alerts">Active USB Alerts</option>
            </select>
            <input type="text" id="search-input" class="search-input" placeholder="Search IP or device..." onkeyup="filterWorkstations()">
        </div>
    </div>

    <div id="workstation-grid" class="grid"></div>

    <script>
        let cachedWorkstations = [];

        // UPDATED: Standardized color status mapping
        function getStatusClass(status) {
            switch(status.toLowerCase()) {
                case 'online': 
                case 'idle': 
                case 'no-usb': 
                    return 'status-green';
                case 'isolated': 
                case 'blocked': 
                case 'block': 
                    return 'status-yellow';
                case 'offline': 
                case 'authorized': 
                case 'authorised': 
                    return 'status-red';
                default: 
                    return 'status-green';
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
            grid.innerHTML = '';

            if (workstations.length === 0) {
                grid.innerHTML = `<div class="empty-state">No matching workstations found matching your criteria.</div>`;
                return;
            }

            workstations.forEach(ws => {
                const netClass = getStatusClass(ws.internet_status);
                const usbClass = getStatusClass(ws.usb_status);
                const formattedIP = formatIPAddress(ws.ip_address);
                
                let card = document.createElement('div');
                card.className = 'workstation-card';
                card.innerHTML = `
                    <div class="device-ip-header">
                        <span>${formattedIP}</span>
                        <span style="font-size: 0.7em; color: var(--text-secondary); font-weight: normal;">Subnet /24</span>
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
                `;
                grid.appendChild(card);
            });
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
            setInterval(pollTelemetry, 5000);
        };
    </script>
</body>
</html>
