#!/bin/bash
# Kill any existing PHP servers on port 8082
pkill -f "php -S .*:8082" 2>/dev/null
sleep 1

# Start the PHP built-in web server with public/ as the document root
cd "$(dirname "$0")"
php -S 0.0.0.0:8082 -t public public/index.php > /tmp/php_server.log 2>&1 &
PHP_PID=$!

# Wait for the server to start
sleep 2

# Check if the server is now running
if kill -0 $PHP_PID 2>/dev/null; then
    echo "PHP server started successfully on http://localhost:8082 (PID: $PHP_PID)"
    cat /tmp/php_server.log
else
    echo "Failed to start PHP server. Error log:"
    cat /tmp/php_server.log
fi

# Keep the script's output stream open so the exec session doesn't end immediately
wait $PHP_PID
