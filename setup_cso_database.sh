#!/bin/bash

echo "Setting up CSO Database Tables..."
echo "Please enter your MySQL root password when prompted:"

# Run the SQL script
mysql -u root -p < create_cso_tables.sql

echo "CSO database tables setup completed!"
echo "You can now test the CSO dashboard functionality." 