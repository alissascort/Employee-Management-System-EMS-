-- Update staff_profiles table to properly reference employees table
-- This will create a proper foreign key relationship and remove duplicate fields

-- Step 1: Add employee_id foreign key column
ALTER TABLE staff_profiles ADD COLUMN employee_id INT(11) AFTER id;

-- Step 2: Add foreign key constraint
ALTER TABLE staff_profiles ADD CONSTRAINT fk_staff_profiles_employee 
FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE;

-- Step 3: Keep all fields as admin needs to fill them in the form
-- (Admin may provide different/additional information than employee self-registration)

-- Step 4: Update existing records to link with employees table
-- This will populate the employee_id field based on matching employee_code
UPDATE staff_profiles sp 
JOIN employees e ON sp.employee_code = e.employee_code 
SET sp.employee_id = e.employee_id 
WHERE sp.employee_id IS NULL;

-- Step 5: Make employee_id NOT NULL after populating it
ALTER TABLE staff_profiles MODIFY COLUMN employee_id INT(11) NOT NULL;

-- Step 6: Add index for better performance
ALTER TABLE staff_profiles ADD INDEX idx_employee_id (employee_id);

-- Final structure should be:
-- id, employee_id, employee_code, firstname, lastname, email, department, role,
-- address, country, state, city, date_of_birth, registration_date, profile_photo, status
