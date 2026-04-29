<?php
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="system_logs.csv"');
echo "timestamp,type,message\n";
echo "2024-06-29 09:00:00,auth,User login successful\n";
echo "2024-06-29 09:05:00,system,System check completed\n";
