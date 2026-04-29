# Employee Code Validation Standard

## 📋 Format Specification

**Standard Format:** `YYYY/EMP/XXXX`

- **YYYY:** 4-digit year (e.g., 2025)
- **EMP:** Literal string "EMP"
- **XXXX:** 4-digit sequential number (e.g., 0001, 0002, 9999)

**Examples:**
- ✅ `2025/EMP/0001`
- ✅ `2025/EMP/1234`
- ✅ `2024/EMP/9999`
- ❌ `2025/EMP/123` (too few digits)
- ❌ `2025/EMP/12345` (too many digits)
- ❌ `2025/emp/0001` (wrong case)
- ❌ `2025/EMP/0001/` (extra slash)

## 🔧 Implementation

### Frontend Validation (JavaScript)
```javascript
const employeeCodePattern = /^[0-9]{4}\/EMP\/[0-9]{4}$/;
if (!employeeCodePattern.test(employeeCode)) {
    // Show error message
}
```

### Backend Validation (PHP)
```php
$employeeCodePattern = '/^[0-9]{4}\/EMP\/[0-9]{4}$/';
if (!preg_match($employeeCodePattern, $employeeCode)) {
    // Return error response
}
```

## 📍 Files Updated

1. **`validate_employee_code.php`** - Added format validation
2. **`employee_auth.php`** - Added format validation
3. **`FSM.ESM.EMPLOYEE.dashboard.html`** - Updated regex pattern
4. **`FSM.ESM.EMPLOYEE.html`** - Already using correct pattern

## 🎯 Benefits

- **Consistency:** Same validation across all components
- **Security:** Prevents invalid format attacks
- **User Experience:** Clear error messages with examples
- **Maintainability:** Single source of truth for format

## ⚠️ Important Notes

- All employee codes are generated with exactly 4 digits after "EMP"
- Validation is now consistent across login, attendance, and all other systems
- Error messages include helpful examples for users 