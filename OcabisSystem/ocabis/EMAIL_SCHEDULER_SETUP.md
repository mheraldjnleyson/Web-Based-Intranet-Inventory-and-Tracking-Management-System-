# OCABIS Email Scheduler Setup Guide

## Overview
The OCABIS Email Scheduler automatically sends email notifications for:
- **Due Date Reminders**: Sent 1-3 days before items are due
- **Overdue Notifications**: Sent when items are past their due date

## Files Created
- `email_scheduler.php` - Main scheduler script
- `run_email_scheduler.bat` - Windows batch file to run the scheduler
- `email_notifications.php` - Updated with new email functions

## Setup Instructions

### Method 1: Windows Task Scheduler (Recommended)

1. **Open Task Scheduler**
   - Press `Win + R`, type `taskschd.msc`, press Enter

2. **Create Basic Task**
   - Click "Create Basic Task..." in the right panel
   - Name: "OCABIS Email Scheduler"
   - Description: "Sends due date reminders and overdue notifications"

3. **Set Trigger**
   - Choose "Daily"
   - Set start time (e.g., 9:00 AM)
   - Set to run every 1 day

4. **Set Action**
   - Choose "Start a program"
   - Program/script: `C:\xampp\htdocs\ocabisFrontend\ocabis\run_email_scheduler.bat`
   - Start in: `C:\xampp\htdocs\ocabisFrontend\ocabis`

5. **Configure Settings**
   - Check "Run whether user is logged on or not"
   - Check "Run with highest privileges"
   - Check "Hidden" (optional)

### Method 2: Manual Testing

1. **Test the scheduler manually**
   ```cmd
   cd C:\xampp\htdocs\ocabisFrontend\ocabis
   php email_scheduler.php
   ```

2. **Run the batch file**
   - Double-click `run_email_scheduler.bat`

### Method 3: Cron Job (Linux/Mac)

Add to crontab (`crontab -e`):
```bash
# Run daily at 9:00 AM
0 9 * * * /usr/bin/php /path/to/ocabisFrontend/ocabis/email_scheduler.php
```

## Email Configuration

The email system uses Gmail SMTP with the following settings:
- **SMTP Server**: smtp.gmail.com
- **Port**: 587
- **Security**: TLS
- **From**: capstone12025@gmail.com
- **App Password**: ehsp zlyl vkuc xtvd

## Email Types

### Due Date Reminders
- **When**: 1-3 days before due date
- **Subject**: "Due Date Reminder - OCABIS"
- **Content**: Friendly reminder with item details and days remaining
- **Urgency**: Color-coded based on days remaining

### Overdue Notifications
- **When**: Daily for items past due date
- **Subject**: "URGENT: Overdue Item - OCABIS"
- **Content**: Urgent notice with consequences and action required
- **Status Update**: Automatically updates item status to "overdue"

## Database Changes

The system now uses `borrower_email` instead of `borrower_contact`:
- **Field**: `borrower_email` (required)
- **Validation**: Email format validation
- **Storage**: VARCHAR(255) NOT NULL

## Testing

1. **Create test borrow records** with due dates 1-3 days in the future
2. **Run the scheduler** manually to test
3. **Check email delivery** and formatting
4. **Verify database updates** for overdue items

## Monitoring

Check the following for monitoring:
- **PHP Error Logs**: Check for email sending errors
- **Database**: Monitor `borrow_history` table for status updates
- **Email Delivery**: Verify emails are received by borrowers

## Troubleshooting

### Common Issues
1. **SMTP Authentication Failed**
   - Verify Gmail app password is correct
   - Check Gmail security settings

2. **Database Connection Failed**
   - Ensure XAMPP is running
   - Verify database credentials

3. **Emails Not Sending**
   - Check PHP error logs
   - Verify email addresses are valid
   - Test SMTP connection manually

### Log Files
- **PHP Error Log**: Check XAMPP error logs
- **Scheduler Log**: Output from `email_scheduler.php`
- **Email Logs**: Check Gmail sent items

## Security Notes

- **App Password**: Keep Gmail app password secure
- **File Permissions**: Ensure scheduler files are not publicly accessible
- **Database Access**: Use secure database credentials
- **Email Content**: No sensitive information in email content

## Maintenance

- **Regular Testing**: Test scheduler monthly
- **Email Monitoring**: Monitor email delivery rates
- **Database Cleanup**: Archive old borrow records periodically
- **Log Rotation**: Clean up old log files regularly

