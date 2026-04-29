#!/bin/bash

# Auto-Attendance Cron Job Setup Script
# This script helps set up automatic attendance processing

echo "=== Auto-Attendance Cron Job Setup ==="
echo ""

# Get the current directory
CURRENT_DIR=$(pwd)
PHP_PATH=$(which php)

echo "Current directory: $CURRENT_DIR"
echo "PHP path: $PHP_PATH"
echo ""

# Create the cron job command
CRON_COMMAND="0 12 * * * cd $CURRENT_DIR && $PHP_PATH process_auto_attendance.php >> auto_attendance.log 2>&1"

echo "Cron job command to be added:"
echo "$CRON_COMMAND"
echo ""

echo "To set up the cron job, run the following command:"
echo "crontab -e"
echo ""
echo "Then add this line to your crontab:"
echo "$CRON_COMMAND"
echo ""

echo "This will run the auto-attendance processor every day at 12:00 PM."
echo ""

# Check if crontab is available
if command -v crontab &> /dev/null; then
    echo "Crontab is available on this system."
    echo ""
    echo "Would you like to add the cron job automatically? (y/n)"
    read -r response
    
    if [[ "$response" =~ ^[Yy]$ ]]; then
        # Add to crontab
        (crontab -l 2>/dev/null; echo "$CRON_COMMAND") | crontab -
        echo "Cron job has been added successfully!"
        echo ""
        echo "Current crontab entries:"
        crontab -l
    else
        echo "Cron job was not added automatically."
        echo "Please add it manually using 'crontab -e'"
    fi
else
    echo "Crontab is not available on this system."
    echo "Please set up the cron job manually or use your system's task scheduler."
fi

echo ""
echo "=== Setup Complete ==="
echo ""
echo "You can also run the auto-attendance processor manually:"
echo "php process_auto_attendance.php"
echo ""
echo "To view the logs:"
echo "tail -f auto_attendance.log" 