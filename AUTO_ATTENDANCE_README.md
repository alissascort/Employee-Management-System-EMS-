# Auto-Attendance System Documentation

## Overview

The Auto-Attendance System automatically marks employees as absent if they haven't checked in by 12:00 PM (noon) each day. This ensures that attendance records are complete and up-to-date for all employees.

## How It Works

### 1. Attendance Policy
- **Present**: Check-in between 7:45 AM - 8:30 AM
- **Present Late**: Check-in between 8:31 AM - 10:00 AM  
- **Late**: Check-in between 10:01 AM - 11:59 AM
- **Auto-Absent**: Automatically marked after 12:00 PM if no check-in

### 2. System Components

#### `attendance_handler.php`
- Handles individual employee attendance actions
- **NEW**: Added `auto_absent` case to handle automatic absent marking
- Supports manual attendance marking and auto-absent processing

#### `process_auto_attendance.php`
- **NEW**: Automated script that processes all employees
- Runs at 12:00 PM daily (via cron job)
- Marks employees as absent if they haven't checked in
- Logs all activities for audit purposes

#### `get_attendance_overview.php`
- **NEW**: Provides comprehensive attendance overview for admin dashboards
- Shows today's attendance status for all employees
- Includes department-wise breakdown and recent activity

#### `get_admin_dashboard.php`
- **UPDATED**: Now includes today's attendance statistics
- Shows counts for present, late, absent, and no-record employees

## Setup Instructions

### 1. Manual Setup
```bash
# Run the auto-attendance processor manually
php process_auto_attendance.php
```

### 2. Automated Setup (Recommended)
```bash
# Run the setup script
./setup_auto_attendance_cron.sh
```

This will:
- Create a cron job that runs daily at 12:00 PM
- Automatically process attendance for all employees
- Log all activities to `auto_attendance.log`

### 3. Manual Cron Job Setup
If you prefer to set up the cron job manually:

```bash
# Edit crontab
crontab -e

# Add this line:
0 12 * * * cd /path/to/your/FSM.ESM && php process_auto_attendance.php >> auto_attendance.log 2>&1
```

## Usage

### For Employees
- Employees can check in through the employee dashboard
- If they don't check in by 12:00 PM, they'll be automatically marked as absent
- The system shows real-time status updates

### For Admins
- View today's attendance overview at `/get_attendance_overview.php`
- Dashboard shows attendance statistics
- Can see who is present, late, absent, or has no record

### For System Administrators
- Monitor logs: `tail -f auto_attendance.log`
- Manual processing: `php process_auto_attendance.php`
- Check cron job status: `crontab -l`

## Files Created/Modified

### New Files
- `process_auto_attendance.php` - Automated attendance processor
- `get_attendance_overview.php` - Admin attendance overview
- `setup_auto_attendance_cron.sh` - Cron job setup script
- `auto_attendance.log` - System logs (created automatically)

### Modified Files
- `attendance_handler.php` - Added auto_absent case
- `get_admin_dashboard.php` - Added attendance statistics

## Troubleshooting

### Issue: Auto-absent not working
1. Check if cron job is running: `crontab -l`
2. Check logs: `tail -f auto_attendance.log`
3. Run manually: `php process_auto_attendance.php`

### Issue: Admin can't see attendance data
1. Ensure admin session is active
2. Check database connection
3. Verify employee records exist

### Issue: Employee not marked as absent
1. Check if employee has active status
2. Verify employee code format
3. Check database for existing attendance records

## Example Output

### Auto-Attendance Log
```
[2025-07-27 12:03:25] Starting auto-attendance processing...
[2025-07-27 12:03:25] Current time: 12:03:25 - Processing auto-absent for employees who haven't checked in
[2025-07-27 12:03:25] Found 1 employees without attendance records
[2025-07-27 12:03:25] Auto-absent recorded for Michael Wilson (2025/EMP/5363)
[2025-07-27 12:03:25] Auto-attendance processing completed:
[2025-07-27 12:03:25] - Processed: 1 employees
[2025-07-27 12:03:25] - Errors: 0 employees
[2025-07-27 12:03:25] Today's attendance summary:
[2025-07-27 12:03:25] - absent: 1 employees
[2025-07-27 12:03:25] Auto-attendance processing finished successfully.
```

### Admin Dashboard Response
```json
{
  "success": true,
  "stats": {
    "totalEmployees": 1,
    "departments": 1,
    "attendance": {
      "present": 0,
      "present_late": 0,
      "late": 0,
      "absent": 1,
      "no_record": 0
    },
    "leave": {
      "pending": 0,
      "approved": 0,
      "rejected": 0
    }
  }
}
```

## Security Notes

- All endpoints require proper authentication
- Admin endpoints check for admin privileges
- Employee endpoints check for employee privileges
- All database queries use prepared statements
- Session management is properly configured

## Support

For issues or questions:
1. Check the logs first: `tail -f auto_attendance.log`
2. Verify database connectivity
3. Ensure proper file permissions
4. Check cron job status if using automated processing 