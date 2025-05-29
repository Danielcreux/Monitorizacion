<?php
class ProcessMonitor {
    private $pid;
    private $processName;
    private $maxPoints = 60;
    private $history = [
        'timestamps' => [],
        'cpu' => [],
        'mem_percent' => [],
        'mem_mb' => [],
        'threads' => []
    ];
    private $startTime;
    private $prevCpuTime = 0;

    public function __construct($pid = null) {
        $this->pid = $pid;
        if ($this->pid) {
            $this->getProcessName();
        }
        $this->startTime = microtime(true);
    }

    public function getPid() {
        return $this->pid;
    }
    
    public function hasPid() {
        return $this->pid !== null;
    }
    
    public function getStartTime() {
        return $this->startTime;
    }
    
    public function getHistory() {
        return $this->history;
    }
    
    public function getProcessName() {
        if (!$this->pid) return;
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec("wmic process where \"ProcessId={$this->pid}\" get Name", $output);
            if (isset($output[1])) {
                $this->processName = trim($output[1]);
            }
        } else {
            $statusFile = "/proc/{$this->pid}/status";
            if (file_exists($statusFile)) {
                $status = file_get_contents($statusFile);
                if (preg_match('/Name:\s+(.+)/', $status, $matches)) {
                    $this->processName = trim($matches[1]);
                }
            }
        }
        
        $this->processName = $this->processName ?: "Unknown Process";
    }
    
    public function listProcesses() {
        $processes = [];
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec('wmic process get ProcessId,Name', $output);
            foreach ($output as $line) {
                if (preg_match('/^(.+?)\s+(\d+)$/', $line, $matches)) {
                    $processes[(int)$matches[2]] = trim($matches[1]);
                }
            }
        } else {
            $files = glob('/proc/[0-9]*/cmdline');
            foreach ($files as $file) {
                if (preg_match('/\/proc\/(\d+)\/cmdline/', $file, $matches)) {
                    $pid = (int)$matches[1];
                    $cmdline = file_get_contents($file);
                    $processes[$pid] = str_replace("\0", ' ', $cmdline) ?: "Unknown";
                }
            }
        }
        
        ksort($processes);
        return $processes;
    }

    public function renderProcessSelector() {
        $processes = $this->listProcesses();
        
        echo "<div class='process-list'>";
        echo "<h3><i class='fas fa-list'></i> Running processes:</h3>";
        echo "<form method='POST'>";
        echo "<select name='pid' class='process-select'>";
        echo "<option value=''>-- Select a Process --</option>";
        
        $count = 0;
        foreach ($processes as $pid => $name) {
            $selected = ($this->pid == $pid) ? 'selected' : '';
            echo "<option value='$pid' $selected>$pid - " . htmlspecialchars($name) . "</option>";
            if (++$count >= 20) break;
        }
        
        echo "</select>";
        echo "<button type='submit' class='btn btn-primary'>Monitor Process</button>";
        echo "</form>";
        echo "</div>";
    }

    private function getProcessInfo() {
        if (!$this->pid) return false;
        
        $time = microtime(true) - $this->startTime;
        $cpu = 0;
        $mem_mb = 0;
        $mem_percent = 0;
        $threads = 0;
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec("wmic process where \"ProcessId={$this->pid}\" get KernelModeTime,UserModeTime,WorkingSetSize,ThreadCount", $output);
            
            if (isset($output[1])) {
                $values = preg_split('/\s+/', trim($output[1]));
                $kernelTime = $values[0] / 10000000; // Convert to seconds
                $userTime = $values[1] / 10000000;   // Convert to seconds
                $cpuTime = $kernelTime + $userTime;
                
                if ($this->prevCpuTime > 0 && count($this->history['timestamps']) > 0) {
                    $lastTime = end($this->history['timestamps']);
                    $timeDiff = $time - $lastTime;
                    if ($timeDiff > 0) {
                        $cpu = ($cpuTime - $this->prevCpuTime) / $timeDiff;
                    }
                }
                $this->prevCpuTime = $cpuTime;
                
                $mem_mb = $values[2] / (1024 * 1024);
                $threads = (int)$values[3];
                
                // Memory percentage calculation
                exec('wmic ComputerSystem get TotalPhysicalMemory', $memoryOutput);
                $totalMemory = (float)trim($memoryOutput[1]);
                if ($totalMemory > 0) {
                    $mem_percent = ($mem_mb * 1024 * 1024) / $totalMemory * 100;
                }
            }
        } else {
            $statFile = "/proc/{$this->pid}/stat";
            if (file_exists($statFile)) {
                $stat = file_get_contents($statFile);
                $parts = explode(' ', $stat);
                $utime = $parts[13];
                $stime = $parts[14];
                $cpuTime = ($utime + $stime) / 100; // Convert jiffies to seconds
                
                if ($this->prevCpuTime > 0 && count($this->history['timestamps']) > 0) {
                    $lastTime = end($this->history['timestamps']);
                    $timeDiff = $time - $lastTime;
                    if ($timeDiff > 0) {
                        $cpu = ($cpuTime - $this->prevCpuTime) / $timeDiff;
                    }
                }
                $this->prevCpuTime = $cpuTime;
                
                // Memory calculation
                $statusFile = "/proc/{$this->pid}/status";
                if (file_exists($statusFile)) {
                    $status = file_get_contents($statusFile);
                    if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $matches)) {
                        $mem_mb = $matches[1] / 1024;
                    }
                }
                
                // Thread count
                if (preg_match('/Threads:\s+(\d+)/', $status, $matches)) {
                    $threads = (int)$matches[1];
                }
                
                // Memory percentage
                $meminfo = file_get_contents('/proc/meminfo');
                if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $matches)) {
                    $totalMemory = $matches[1] / 1024; // Convert to MB
                    if ($totalMemory > 0) {
                        $mem_percent = $mem_mb / $totalMemory * 100;
                    }
                }
            }
        }
        
        return [
            'time' => $time,
            'cpu' => $cpu * 100, // Convert to percentage
            'mem_mb' => $mem_mb,
            'mem_percent' => $mem_percent,
            'threads' => $threads
        ];
    }

    public function updateMetrics() {
        if (!$this->pid) return false;
        
        $info = $this->getProcessInfo();
        if (!$info) return false;
        
        // Add to history
        $this->history['timestamps'][] = $info['time'];
        $this->history['cpu'][] = $info['cpu'];
        $this->history['mem_percent'][] = $info['mem_percent'];
        $this->history['mem_mb'][] = $info['mem_mb'];
        $this->history['threads'][] = $info['threads'];
        
        // Trim history
        foreach ($this->history as &$values) {
            if (count($values) > $this->maxPoints) {
                array_shift($values);
            }
        }
        
        return true;
    }

    public function renderDashboard() {
        if (!$this->pid) return;
        
        echo "<div class='dashboard'>";
        echo "<h2><i class='fas fa-chart-line'></i> Process Resource Monitor: {$this->processName} (PID: {$this->pid})</h2>";
        
        // CPU Usage
        $this->renderMetric('CPU Usage', $this->history['cpu'], '%', 100, '#FF6B6B');
        
        // Memory Percentage
        $this->renderMetric('Memory Usage', $this->history['mem_percent'], '%', 100, '#4ECDC4');
        
        // Memory MB
        $max = max(10, max($this->history['mem_mb'] ?: [0]));
        $this->renderMetric('Memory Usage', $this->history['mem_mb'], 'MB', $max, '#1A535C');
        
        // Thread Count
        $max = max(5, max($this->history['threads'] ?: [0]));
        $this->renderMetric('Thread Count', $this->history['threads'], '', $max, '#FFD166');
        
        echo "</div>";
    }

    private function renderMetric($title, $history, $unit, $max, $color) {
        if (empty($history)) return;
        
        $current = end($history);
        $valueStr = is_float($current) ? number_format($current, 2) : $current;
        
        // Calculate bar width
        $barWidth = min(100, max(0, round(($current / $max) * 100)));
        
        echo "<div class='metric'>";
        echo "<div class='metric-header'>";
        echo "<h3><i class='fas fa-microchip'></i> $title</h3>";
        echo "<div class='metric-value'>$valueStr $unit</div>";
        echo "</div>";
        
        // Bar chart with value indicator
        echo "<div class='bar-container'>";
        echo "<div class='bar' style='width:{$barWidth}%; background: $color;'>";
        echo "<div class='bar-value'>{$barWidth}%</div>";
        echo "</div>";
        echo "</div>";
        
        // History graph with value labels
        echo "<div class='history-container'>";
        echo "<div class='history-header'>";
        echo "<span><i class='fas fa-arrow-down'></i> Min: " . (min($history) ? number_format(min($history), 2) : '0') . " $unit</span>";
        echo "<span><i class='fas fa-calculator'></i> Avg: " . number_format(array_sum($history) / count($history), 2) . " $unit</span>";
        echo "<span><i class='fas fa-arrow-up'></i> Max: " . number_format(max($history), 2) . " $unit</span>";
        echo "</div>";
        
        echo "<div class='history-graph'>";
        $minVal = min($history) ?: 0;
        $range = max(1, ($max - $minVal));
        
        foreach ($history as $value) {
            $height = min(100, max(0, round((($value - $minVal) / $range) * 100)));
            $tooltip = number_format($value, 2) . " $unit";
            echo "<div class='bar-column' style='height:{$height}%; background: $color;' title='$tooltip'></div>";
        }
        echo "</div>";
        echo "</div>";
        echo "</div>";
    }
}

// Start session to persist monitoring data
session_start();

// Initialize or retrieve monitor from session
if (isset($_SESSION['monitor'])) {
    $monitor = unserialize($_SESSION['monitor']);
} else {
    $monitor = new ProcessMonitor();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['pid']) && $_POST['pid']) {
        $monitor = new ProcessMonitor((int)$_POST['pid']);
    } elseif (isset($_POST['reset'])) {
        // Reset monitoring session
        session_destroy();
        session_start();
        $monitor = new ProcessMonitor();
    }
    
    if ($monitor->hasPid()) {
        $monitor->updateMetrics();
    }
    
    $_SESSION['monitor'] = serialize($monitor);
} elseif ($monitor->hasPid()) {
    $monitor->updateMetrics();
    $_SESSION['monitor'] = serialize($monitor);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Resource Monitor</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #1a2a6c, #2a4d69);
            color: #fff;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        header {
            text-align: center;
            padding: 20px 0;
            margin-bottom: 30px;
        }
        
        h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            background: linear-gradient(90deg, #4facfe, #00f2fe);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        
        .dashboard {
            background: rgba(25, 39, 52, 0.8);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            margin-bottom: 30px;
        }
        
        .dashboard h2 {
            text-align: center;
            margin-bottom: 25px;
            color: #4facfe;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }
        
        .metric {
            background: rgba(30, 45, 60, 0.7);
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .metric:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
        }
        
        .metric-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .metric h3 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .metric-value {
            font-size: 1.8rem;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.1);
            padding: 8px 15px;
            border-radius: 8px;
            min-width: 110px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .bar-container {
            width: 100%;
            height: 35px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 18px;
            overflow: hidden;
            position: relative;
            box-shadow: inset 0 2px 5px rgba(0,0,0,0.3);
        }
        
        .bar {
            height: 100%;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 0 15px;
            transition: width 0.8s ease;
            position: relative;
            overflow: visible;
        }
        
        .bar-value {
            color: white;
            font-weight: bold;
            text-shadow: 0 1px 2px rgba(0,0,0,0.5);
            font-size: 0.9rem;
        }
        
        .history-container {
            background: rgba(0, 0, 0, 0.15);
            border-radius: 10px;
            padding: 15px;
            margin-top: 10px;
        }
        
        .history-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.9rem;
            color: #89b3f5;
        }
        
        .history-header span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .history-graph {
            display: flex;
            align-items: flex-end;
            height: 120px;
            gap: 4px;
            background: rgba(0, 0, 0, 0.1);
            padding: 10px;
            border-radius: 8px;
        }
        
        .bar-column {
            flex: 1;
            border-radius: 4px 4px 0 0;
            transition: height 0.8s ease;
            position: relative;
            cursor: pointer;
        }
        
        .bar-column:hover::after {
            content: attr(title);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            white-space: nowrap;
            z-index: 10;
        }
        
        .process-list {
            background: rgba(25, 39, 52, 0.8);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            margin: 0 auto 40px;
            max-width: 600px;
        }
        
        .process-list h3 {
            text-align: center;
            margin-bottom: 25px;
            color: #4facfe;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .process-select {
            width: 100%;
            padding: 15px;
            border-radius: 10px;
            background: rgba(30, 45, 60, 0.7);
            color: #fff;
            border: 2px solid #4facfe;
            margin-bottom: 20px;
            font-size: 1.1rem;
            outline: none;
        }
        
        .process-select:focus {
            border-color: #00f2fe;
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.3);
        }
        
        /* Button Styling Fixes */
        .btn {
            display: inline-block;
            padding: 15px 25px;
            border-radius: 10px;
            font-size: 1.2rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            border: none;
            color: white;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(90deg, #4facfe, #00f2fe);
            width: 100%;
        }
        
        .btn-primary:hover {
            background: linear-gradient(90deg, #3fa1fd, #00e0f7);
            transform: translateY(-3px);
            box-shadow: 0 7px 15px rgba(0, 0, 0, 0.3);
        }
        
        .btn-refresh {
            background: linear-gradient(90deg, #FF6B6B, #FFD166);
            font-size: 1.3rem;
            padding: 12px 25px;
        }
        
        .btn-refresh:hover {
            background: linear-gradient(90deg, #ff5a5a, #ffc952);
            transform: translateY(-3px);
            box-shadow: 0 7px 15px rgba(0, 0, 0, 0.3);
        }
        
        .btn-back {
            background: linear-gradient(90deg, #6a11cb, #2575fc);
            font-size: 1.3rem;
            padding: 12px 25px;
        }
        
        .btn-back:hover {
            background: linear-gradient(90deg, #5d0fc4, #1f6dfa);
            transform: translateY(-3px);
            box-shadow: 0 7px 15px rgba(0, 0, 0, 0.3);
        }
        
        .btn-group {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
            margin: 30px auto;
            width: 300px;
        }
        
        footer {
            text-align: center;
            padding: 25px;
            color: #89b3f5;
            font-size: 1rem;
            margin-top: 20px;
        }
        
        @media (max-width: 768px) {
            .metrics {
                grid-template-columns: 1fr;
            }
            
            h1 {
                font-size: 2rem;
            }
            
            .metric-value {
                font-size: 1.5rem;
            }
            
            .history-header {
                flex-direction: column;
                gap: 5px;
                align-items: center;
            }
            
            .btn-group {
                width: 100%;
                max-width: 300px;
            }
        }
        
        .stats-container {
            display: flex;
            justify-content: space-around;
            margin-top: 20px;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 10px;
            padding: 15px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #00f2fe;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #89b3f5;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1><i class="fas fa-chart-line"></i> Process Resource Monitor</h1>
        </header>
        
        <main>
            <?php
            if ($monitor->hasPid()) {
                $monitor->renderDashboard();
                
                // Calculate monitoring duration
                $currentTime = microtime(true);
                $duration = $currentTime - $monitor->getStartTime();
                $hours = floor($duration / 3600);
                $minutes = floor(($duration % 3600) / 60);
                $seconds = floor($duration % 60);
                
                // Get history for stats
                $history = $monitor->getHistory();
                
                echo "<div class='stats-container'>";
                echo "<div class='stat-item'>";
                echo "<div class='stat-value'>{$monitor->getPid()}</div>";
                echo "<div class='stat-label'><i class='fas fa-fingerprint'></i> Process ID</div>";
                echo "</div>";
                
                echo "<div class='stat-item'>";
                echo "<div class='stat-value'>" . count($history['timestamps']) . "</div>";
                echo "<div class='stat-label'><i class='fas fa-database'></i> Data Points</div>";
                echo "</div>";
                
                echo "<div class='stat-item'>";
                echo "<div class='stat-value'>" . sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds) . "</div>";
                echo "<div class='stat-label'><i class='fas fa-clock'></i> Duration</div>";
                echo "</div>";
                echo "</div>";
                
                // Button group for refresh and back
                echo "<div class='btn-group'>";
                echo "<form method='POST'>";
                echo "<input type='hidden' name='refresh' value='1'>";
                echo "<button type='submit' class='btn btn-refresh'><i class='fas fa-sync-alt'></i> Refresh Data</button>";
                echo "</form>";
                
                echo "<form method='POST'>";
                echo "<input type='hidden' name='reset' value='1'>";
                echo "<button type='submit' class='btn btn-back'><i class='fas fa-arrow-left'></i> Back to Process Selection</button>";
                echo "</form>";
                echo "</div>";
            } else {
                $monitor->renderProcessSelector();
            }
            ?>
        </main>
        
        <footer>
            <p><i class="fas fa-sync-alt"></i> Real-time process monitoring tool | PHP implementation</p>
        </footer>
    </div>
    
    <script>
        // Auto-refresh every 3 seconds
        setInterval(() => {
            if (document.querySelector('.btn-refresh')) {
                document.querySelector('.btn-refresh').closest('form').submit();
            }
        }, 3000);
    </script>
</body>
</html>