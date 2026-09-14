<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$file = $_GET['file'] ?? '';
if (!$file) {
    echo "data: Error: No file specified\n\n";
    exit;
}

$file_escaped = escapeshellarg($file);
// We use a temporary file to capture the output of the python script in real-time
$log_file = "/tmp/live_process_" . md5($file) . ".log";

// Launch the process and redirect stdout to the log file
shell_exec("python3 /home/mauricio/.openclaw/workspace/english_app/process_lessons.py $file_escaped > $log_file 2>&1 &");

// Tail the log file and stream it to the browser
$handle = fopen($log_file, 'r');
while (true) {
    $line = fgets($handle);
    if ($line !== false) {
        echo "data: " . trim($line) . "\n\n";
        ob_flush();
        flush();
    } else {
        // Check if the process is still running
        $pid = shell_exec("pgrep -f 'process_lessons.py $file_escaped'");
        if (!$pid) {
            echo "data: --- Process Finished ---\n\n";
            break;
        }
        usleep(500000); // Sleep for 0.5s to avoid CPU spike
    }
}
fclose($handle);
unlink($log_file);
?>