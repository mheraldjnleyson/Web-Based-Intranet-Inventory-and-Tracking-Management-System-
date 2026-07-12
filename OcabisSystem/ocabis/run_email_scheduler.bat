@echo off
REM OCABIS Email Scheduler Batch File
REM This file runs the email scheduler for due date reminders and overdue notifications
REM Run this file daily using Windows Task Scheduler

echo Starting OCABIS Email Scheduler...
echo Date: %date%
echo Time: %time%

REM Change to the correct directory
cd /d "C:\xampp\htdocs\ocabisFrontend\ocabis"

REM Run the PHP email scheduler
php email_scheduler.php

echo Email scheduler completed.
pause

