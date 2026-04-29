        // Toggle sidebar
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            if (sidebar) {
                sidebar.classList.toggle('collapsed');
            }
            if (mainContent) {
                mainContent.classList.toggle('expanded');
            }
        }
        
        // Menu navigation with smooth transitions
        const menuItems = document.querySelectorAll('.menu-item');
        const contentSections = document.querySelectorAll('.content-section');
        
        function showSection(sectionId) {
            // Hide all content sections
            contentSections.forEach(section => {
                section.classList.remove('active');
                section.style.display = 'none';
            });
            
            // Show the target section
            const targetSection = document.getElementById(sectionId);
            if (targetSection) {
                targetSection.classList.add('active');
                targetSection.style.display = 'block';
                targetSection.style.opacity = '1';
                
                // Load section-specific dynamic data
                loadSectionData(sectionId);
            }
            
            // Update active menu item
            menuItems.forEach(item => {
                item.classList.remove('active');
            });
            
            const activeMenuItem = document.querySelector(`[data-section="${sectionId.replace('-section', '')}"]`);
            if (activeMenuItem) {
                activeMenuItem.classList.add('active');
            }
        }
        
        // Add click listeners to menu items
        menuItems.forEach(item => {
            item.addEventListener('click', function() {
                const sectionKey = this.getAttribute('data-section');
                
                if (sectionKey === 'logout') {
                    window.location.href = 'FSM.ESM.EMPLOYEE.html';
                    return;
                }
                
                const sectionId = sectionKey + '-section';
                showSection(sectionId);
            });
        });
        
        // Function to load section-specific data
        function loadSectionData(sectionId) {
            console.log('Loading data for section:', sectionId);
            
            switch(sectionId) {
                case 'home-section':
                    updateHomeCards();
                    break;
                case 'profile-section':
                    // Profile data is already loaded from session
                    console.log('Profile data loaded from session');
                    break;
                case 'leave-section':
                    fetchAndRenderLeaveHistory();
                    break;
                case 'timebook-section':
                    checkEmployeeSession();
                    break;
                case 'payroll-section':
                    // Payroll data is loaded in loadEmployeeDynamicData
                    console.log('Payroll data already loaded');
                    break;
                case 'attendance-section':
                    loadAttendanceData();
                    break;
                case 'tickets-section':
                    // Load tickets data if needed
                    console.log('Tickets section loaded');
                    break;
                default:
                    console.log('No specific data loading for section:', sectionId);
            }
        }

        // Modal functions
        window.showLeaveRequests = function() {
            document.getElementById('leaveRequestsModal').style.display = 'flex';
            fetchAndRenderLeaveRequestsModal();
        };
        
        window.showLeaveForm = function() {
            document.getElementById('leaveFormModal').style.display = 'flex';
        };
        
        window.showApprovedRequests = function() {
            document.getElementById('approvedRequestsModal').style.display = 'flex';
            fetchAndRenderApprovedRequestsModal();
        };
        
        window.showRejectedRequests = function() {
            document.getElementById('rejectedRequestsModal').style.display = 'flex';
            fetchAndRenderRejectedRequestsModal();
        };
        
        window.showTodaysTasks = function() {
            document.getElementById('todaysTasksModal').style.display = 'flex';
            fetchAndRenderTodaysTasksModal();
        };
        
        window.showFinishedTasks = function() {
            document.getElementById('finishedTasksModal').style.display = 'flex';
            fetchAndRenderFinishedTasksModal();
        };
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Handle leave application form submission
        const leaveApplicationForm = document.getElementById('leaveApplicationForm');
        if (leaveApplicationForm) {
            leaveApplicationForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const leaveType = document.getElementById('leaveType').value;
            const fromDate = document.getElementById('fromDate').value;
            const toDate = document.getElementById('toDate').value;
            const leaveReason = document.getElementById('leaveReason').value;

            if (!leaveType || !fromDate || !toDate || !leaveReason) {
                document.getElementById('leaveNotice').textContent = 'Please fill all required fields.';
                document.getElementById('leaveNotice').style.color = 'red';
                document.getElementById('leaveNotice').style.display = 'block';
                return;
            }

            // Send request to PHP backend
            fetch('submit_leave.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',  // include session
                body: JSON.stringify({
                    leave_type: leaveType,
                    start_date: fromDate,
                    end_date: toDate,
                    reason: leaveReason
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showNotice('Leave applied successfully!', 'green');
                    setTimeout(() => {
                        document.getElementById('leaveApplicationForm').reset();
                        closeModal('leaveFormModal');
                        document.getElementById('leaveNotice').style.display = 'none';
                    }, 2000);
                } else {
                    showNotice(data.message || 'Failed to submit leave request.', 'red');
                }
            })
            .catch(err => {
                console.error('Error:', err);
                showNotice('Server error occurred.', 'red');
            });
        });

        function showNotice(message, color) {
            const notice = document.getElementById('leaveNotice');
            notice.textContent = message;
            notice.style.color = color;
            notice.style.display = 'block';
        }

        // Close modal when clicking outside of it
        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                const modals = document.querySelectorAll('.modal-overlay');
                modals.forEach(modal => {
                    modal.style.display = 'none';
                });
            }
        });

        //Handling logout
        // Moved to initializeEventListeners() function

        //Handling Ticket Management
        // Moved to initializeEventListeners() function
            
        // Photo Upload Handling
        document.addEventListener('DOMContentLoaded', function() {
            const changePhotoBtn = document.getElementById('changePhotoBtn');
            const photoUpload = document.getElementById('photoUpload');
            
            if (!changePhotoBtn || !photoUpload) {
                console.error('Photo upload elements not found!');
                return;
            }
            
            changePhotoBtn.addEventListener('click', () => {
                photoUpload.click();
            });
            
            photoUpload.addEventListener('change', function(e) {
                if (!this.files.length) return;
                
                const formData = new FormData();
                formData.append('photo', this.files[0]);
                
                fetch('profile_handler.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Server error: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        document.getElementById('profileImage').src = data.newPhoto;
                        alert('Photo updated successfully!');
                        refreshUserInfo(); // Refresh user info in header and sidebar
                    } else {
                        throw new Error(data.error || 'Unknown error');
                    }
                })
                .catch(error => {
                    console.error('Upload error:', error);
                    alert('Error: ' + error.message);
                });
            });
        });

        // Password Change Handling
       document.addEventListener('DOMContentLoaded', function () {
    const updateBtn = document.getElementById('updatePasswordBtn');
    if (updateBtn) {
        updateBtn.addEventListener('click', function() {
            const currentPassword = document.getElementById('currentPassword').value;
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            
            if (!currentPassword || !newPassword || !confirmPassword) {
                alert('Please fill all fields');
                return;
            }
            
            if (newPassword !== confirmPassword) {
                alert('New passwords do not match');
                return;
            }
            
            fetch('profile_handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    currentPassword: currentPassword,
                    newPassword: newPassword,
                    confirmPassword: confirmPassword
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Password updated successfully!');
                    // Clear fields
                    document.getElementById('currentPassword').value = '';
                    document.getElementById('newPassword').value = '';
                    document.getElementById('confirmPassword').value = '';
                    refreshUserInfo(); // Refresh user info in header
                } else {
                    alert(data.error || 'Password change failed');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Network error occurred');
            });
        });

  //Fetch session info and update profile 
  document.addEventListener('DOMContentLoaded', function() {
    console.log('=== LOADING EMPLOYEE SESSION ===');
    fetch('get_employee_session_info.php', { credentials: 'include' })
        .then(res => {
            console.log('Session response status:', res.status);
            return res.json();
        })
        .then(data => {
            console.log('Session data received:', data);
            if (data.success) {
                console.log('Session successful, updating UI...');
                // Update sidebar user info
                document.getElementById('sidebarUserNameSpan').textContent = data.full_name;
                
                // Update profile section
                document.getElementById('profileFullName').textContent = data.full_name;
                document.getElementById('profileEmployeeCode').textContent = data.employee_code;
                document.getElementById('profilePosition').textContent = data.position;
                document.getElementById('profileDepartment').textContent = data.department;
                document.getElementById('profileEmail').textContent = data.email;
                document.getElementById('profileHireDate').textContent = data.hire_date;
                document.getElementById('profilePhone').textContent = data.phone;
                document.getElementById('profileStatus').textContent = data.status;
                document.getElementById('profileRole').textContent = data.role;
                
                // Update top-right user info
                document.getElementById('headerFullName').textContent = data.full_name;
                document.getElementById('headerRole').textContent = data.position;
                
                // Update profile images
                if (data.profile_photo) {
                    document.getElementById('profileImage').src = data.profile_photo;
                    document.getElementById('headerProfileImage').src = data.profile_photo;
                }
                
                console.log('UI updated successfully');
                
                // Load dynamic data after session is established
                loadEmployeeDynamicData(data.employee_id);
                
                // Initialize all event listeners safely
                initializeEventListeners();
            } else {
                console.error('Session failed:', data.message);
                window.location.href = 'FSM.ESM.EMPLOYEE.html';
            }
        })
        .catch(error => {
            console.error('Session fetch error:', error);
            window.location.href = 'FSM.ESM.EMPLOYEE.html';
        });
});

document.addEventListener('DOMContentLoaded', function () {
    // Employee ID will be loaded from session, not hardcoded
    initPayrollFilters();
    
    // Payroll data will be loaded after session is established
    // in the loadEmployeeDynamicData function
});

function initPayrollFilters() {
    const yearSelect = document.getElementById('payrollFilterYear');
    const currentYear = new Date().getFullYear();

    for (let year = currentYear; year >= currentYear - 5; year--) {
        const option = document.createElement('option');
        option.value = year;
        option.textContent = year;
        yearSelect.appendChild(option);
    }
}

function loadPayrollData(employeeId) {
    fetch(`get_payroll_data.php?employee_id=${employeeId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayPayrollData(data.payroll);
                calculatePayrollSummary(data.payroll);
            } else {
                showPayrollError(data.message);
            }
        })
        .catch(() => showPayrollError("Error loading payroll data"));
}

function filterPayrollData(employeeId) {
    const month = document.getElementById('payrollFilterMonth').value;
    const year = document.getElementById('payrollFilterYear').value;

    fetch(`get_payroll_data.php?employee_id=${employeeId}&month=${month}&year=${year}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayPayrollData(data.payroll);
                calculatePayrollSummary(data.payroll);
            } else {
                showPayrollError(data.message);
            }
        })
        .catch(() => showPayrollError("Error filtering payroll data"));
}

function displayPayrollData(payrollData) {
    const tbody = document.querySelector('#payrollTable tbody');
    tbody.innerHTML = '';

    if (payrollData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;">No payroll records found</td></tr>';
        document.getElementById('payrollSummary').style.display = 'none';
        return;
    }

    payrollData.forEach(payroll => {
        const row = document.createElement('tr');
        const formatCurrency = (value) => '₦' + parseFloat(value).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');

        row.innerHTML = `
            <td>${payroll.pay_period}</td>
            <td>${formatCurrency(payroll.basic_salary)}</td>
            <td>${formatCurrency(payroll.allowances)}</td>
            <td>${formatCurrency(payroll.deductions)}</td>
            <td>${formatCurrency(payroll.net_salary)}</td>
            <td>${new Date(payroll.payment_date).toLocaleDateString()}</td>
            <td><span class="status-badge ${payroll.status === 'processed' ? 'status-approved' : 'status-pending'}">
                ${payroll.status.charAt(0).toUpperCase() + payroll.status.slice(1)}</span></td>
            <td>
                <button class="btn btn-primary btn-sm view-payslip" data-id="${payroll.payroll_id}">
                    <i class="fas fa-file-invoice"></i> View
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });

    document.querySelectorAll('.view-payslip').forEach(button => {
        button.addEventListener('click', function () {
            const payrollId = this.getAttribute('data-id');
            viewPayslip(payrollId);
        });
    });

    document.getElementById('payrollSummary').style.display = 'block';
}

function calculatePayrollSummary(payrollData) {
    let totalBasic = 0, totalAllowances = 0, totalDeductions = 0, totalNet = 0;

    payrollData.forEach(p => {
        totalBasic += parseFloat(p.basic_salary);
        totalAllowances += parseFloat(p.allowances);
        totalDeductions += parseFloat(p.deductions);
        totalNet += parseFloat(p.net_salary);
    });

    const formatCurrency = val => '₦' + val.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');

    document.getElementById('totalBasic').textContent = formatCurrency(totalBasic);
    document.getElementById('totalAllowances').textContent = formatCurrency(totalAllowances);
    document.getElementById('totalDeductions').textContent = formatCurrency(totalDeductions);
    document.getElementById('totalNet').textContent = formatCurrency(totalNet);
}

function showPayrollError(message) {
    document.getElementById('payrollTable').innerHTML =
        `<tr><td colspan="8" style="text-align: center; padding: 20px;">${message}</td></tr>`;
}

function viewPayslip(payrollId) {
    // Create a new window or tab to display the payslip
    const payslipWindow = window.open('', '_blank');
    
    // Show loading message
    payslipWindow.document.write('<h2>Loading payslip...</h2>');
    
    // Fetch the payslip PDF from the server
    fetch(`generate_payslip.php?payroll_id=${payrollId}`, {
        credentials: 'include' // Include session cookies if needed
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Failed to fetch payslip');
        }
        return response.blob();
    })
    .then(blob => {
        // Create object URL for the PDF blob
        const pdfUrl = URL.createObjectURL(blob);
        
        // Display PDF in the new window
        payslipWindow.location.href = pdfUrl;
        
        // Alternatively, force download:
        // const a = document.createElement('a');
        // a.href = pdfUrl;
        // a.download = `payslip_${payrollId}.pdf`;
        // document.body.appendChild(a);
        // a.click();
        // document.body.removeChild(a);
    })
    .catch(error => {
        payslipWindow.document.write(`
            <h2>Error Loading Payslip</h2>
            <p>${error.message}</p>
            <button onclick="window.location.reload()">Try Again</button>
        `);
        console.error('Payslip error:', error);
    });
}








        // Enhanced Attendance System
        document.addEventListener('DOMContentLoaded', function() {
            // Update clock in real-time
            function updateClock() {
                const now = new Date();
                const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                const dateString = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
                
                document.getElementById('liveClock').textContent = timeString;
                document.getElementById('currentDate').textContent = dateString;
                
                return now;
            }
            
            document.addEventListener('DOMContentLoaded', function () {
                const presentBtn = document.getElementById('presentBtn');
                const presentLateBtn = document.getElementById('presentLateBtn');
                const lateBtn = document.getElementById('lateBtn');
                const absentBtn = document.getElementById('absentBtn');
                const checkOutBtn = document.getElementById('checkOutBtn');
            
                if (presentBtn) presentBtn.addEventListener('click', () => markAttendance('present'));
                if (presentLateBtn) presentLateBtn.addEventListener('click', () => markAttendance('present_late'));
                if (lateBtn) lateBtn.addEventListener('click', () => markAttendance('late'));
                if (absentBtn) absentBtn.addEventListener('click', () => markAttendance('absent'));
                if (checkOutBtn) checkOutBtn.addEventListener('click', checkOut);
            });

            // Update time remaining for check-in
            function updateTimeRemaining(now) {
                const hours = now.getHours();
                const minutes = now.getMinutes();
                const totalMinutes = hours * 60 + minutes;
                
                // Present cutoff time (8:30 AM)
                const presentCutoff = 8 * 60 + 30;
                // Present/Late cutoff time (10:00 AM)
                const presentLateCutoff = 10 * 60;
                // Auto-absent time (12:00 PM)
                const autoAbsentTime = 12 * 60;
                
                let message = '';
                
                if (totalMinutes < 7 * 60 + 45) {
                    message = `Too early to check in. Present check-in available at 7:45 AM`;
                } else if (totalMinutes <= presentCutoff) {
                    const remaining = presentCutoff - totalMinutes;
                    message = `${remaining} minutes remaining for Present check-in`;
                } else if (totalMinutes <= presentLateCutoff) {
                    const remaining = presentLateCutoff - totalMinutes;
                    message = `${remaining} minutes remaining for Present/Late check-in`;
                } else if (totalMinutes < autoAbsentTime) {
                    message = `Only Late check-in available until 12:00 PM`;
                } else {
                    message = `Auto-absent after 12:00 PM without check-in`;
                }
                
                document.getElementById('timeRemaining').textContent = message;
            }
            
            // Check time and enable/disable buttons accordingly
            window.updateButtonAvailability = function(now) {
                const hours = now.getHours();
                const minutes = now.getMinutes();
                const totalMinutes = hours * 60 + minutes;
                
                // Time thresholds in minutes since midnight
                const presentStart = 7 * 60 + 45;  // 7:45 AM
                const presentEnd = 8 * 60 + 30;    // 8:30 AM
                const presentLateStart = 8 * 60 + 31; // 8:31 AM
                const presentLateEnd = 10 * 60;    // 10:00 AM
                const autoAbsentTime = 12 * 60;    // 12:00 PM
                
                const presentBtn = document.getElementById('presentBtn');
                const presentLateBtn = document.getElementById('presentLateBtn');
                const lateBtn = document.getElementById('lateBtn');
                const absentBtn = document.getElementById('absentBtn');
                
                // Reset all buttons
                presentBtn.disabled = true;
                presentLateBtn.disabled = true;
                lateBtn.disabled = true;
                absentBtn.disabled = true;
                
                // Check if already checked in today
                fetch('attendance_handler.php?action=get_today_status')
                    .then(response => response.json())
                    .then(data => {
                        if (data.checkedIn) {
                            // If already checked in, disable all check-in buttons
                            presentBtn.disabled = true;
                            presentLateBtn.disabled = true;
                            lateBtn.disabled = true;
                            absentBtn.disabled = true;
                            
                            // Enable check-out if not already checked out
                            if (!data.checkedOut) {
                                const checkoutDisabled = (hours >= 16 && minutes > 15); // After 4:15 PM
                                document.getElementById('checkOutBtn').disabled = checkoutDisabled;
                            }
                        } else {
                            // Not checked in yet - determine which buttons to enable
                            if (totalMinutes >= presentStart && totalMinutes <= presentEnd) {
                                presentBtn.disabled = false;
                            } else if (totalMinutes >= presentLateStart && totalMinutes <= presentLateEnd) {
                                presentLateBtn.disabled = false;
                            } else if (totalMinutes > presentLateEnd && totalMinutes < autoAbsentTime) {
                                lateBtn.disabled = false;
                            } else if (totalMinutes >= autoAbsentTime) {
                                // Auto-mark as absent if after 12:00 PM
                                markAutoAbsent();
                            }
                            
                            // Allow manual absent marking anytime before auto-absent
                            if (totalMinutes < autoAbsentTime) {
                                absentBtn.disabled = false;
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error checking attendance status:', error);
                    });
            }
            
            function markAutoAbsent() {
                fetch('attendance_handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'auto_absent'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('todayStatusText').textContent = 'ABSENT (Auto)';
                        document.getElementById('attendanceMessage').textContent = 'You were automatically marked as absent for not checking in before 12:00 PM';
                        document.getElementById('attendanceMessage').style.color = 'red';
                        disableAllButtons();
                    }
                })
                .catch(error => {
                    console.error('Error marking auto absent:', error);
                });
            }
            
            // Initialize clock and button states
            function initAttendanceSystem() {
                const now = updateClock();
                updateTimeRemaining(now);
                updateButtonAvailability(now);
                
                // Update every minute to check for status changes
                setInterval(() => {
                    const now = updateClock();
                    updateTimeRemaining(now);
                    updateButtonAvailability(now);
                }, 60000); // Update every minute
            }
            
            // Check today's attendance status
            function checkTodayAttendance() {
                fetch('attendance_handler.php?action=get_today_status')
                    .then(response => response.json())
                    .then(data => {
                        if (data.checkedIn) {
                            document.getElementById('todayStatusText').textContent = data.status.toUpperCase();
                            document.getElementById('checkInTime').innerHTML = `<strong>Check-in:</strong> ${data.check_in_time}`;
                            
                            if (data.checkedOut) {
                                document.getElementById('checkOutTime').innerHTML = `<strong>Check-out:</strong> ${data.check_out_time}`;
                                disableAllButtons();
                            } else {
                                const now = new Date();
                                const hours = now.getHours();
                                const minutes = now.getMinutes();
                                const checkoutDisabled = (hours >= 16 && minutes > 15); // After 4:15 PM
                                document.getElementById('checkOutBtn').disabled = checkoutDisabled;
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching attendance status:', error);
                    });
            }
            
            // Attendance buttons functionality
            // Moved to initializeEventListeners() function
            
            function checkOut() {
                const now = new Date();
                const hours = now.getHours();
                const minutes = now.getMinutes();
                
                // Check if after 4:15 PM
                if (hours >= 16 && minutes > 15) {
                    document.getElementById('attendanceMessage').textContent = 'Too late to check out (after 4:15 PM)';
                    document.getElementById('attendanceMessage').style.color = 'red';
                    return;
                }
                
                fetch('attendance_handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'check_out'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('attendanceMessage').textContent = 'Checked out successfully!';
                        document.getElementById('attendanceMessage').style.color = 'green';
                        document.getElementById('checkOutTime').innerHTML = `<strong>Check-out:</strong> ${data.check_out_time}`;
                        disableAllButtons();
                        
                        // Update Time Book section
                        updateTimeBookSection();
                    } else {
                        document.getElementById('attendanceMessage').textContent = data.message || 'Failed to check out';
                        document.getElementById('attendanceMessage').style.color = 'red';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('attendanceMessage').textContent = 'An error occurred while checking out';
                    document.getElementById('attendanceMessage').style.color = 'red';
                });
            }
            document.addEventListener('DOMContentLoaded', function() {
                const employeeCodeInput = document.getElementById('employeeCode');
                const attendanceBtns = [
                    document.getElementById('presentBtn'),
                    document.getElementById('presentLateBtn'),
                    document.getElementById('lateBtn'),
                    document.getElementById('absentBtn')
                ];
                let employeeCodeValid = false;
            
                // Disable all attendance buttons initially
                attendanceBtns.forEach(btn => btn.disabled = true);
            
                employeeCodeInput.addEventListener('input', function() {
                    const code = employeeCodeInput.value.trim();
                    // Validate format - Updated to match actual employee code format: YYYY/EMP/XXXX
                    const regex = /^\d{4}\/EMP\/\d+$/;
                    if (!regex.test(code)) {
                        attendanceBtns.forEach(btn => btn.disabled = true);
                        document.getElementById('attendanceMessage').textContent = 'Invalid employee code format.';
                        document.getElementById('attendanceMessage').style.color = 'red';
                        employeeCodeValid = false;
                        return;
                    }
                    // Check with backend
                    fetch('validate_employee_code.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ employee_code: code })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.valid) {
                            document.getElementById('attendanceMessage').textContent = '';
                            employeeCodeValid = true;
                            // Enable only the correct button(s) based on time
                            updateButtonAvailability(new Date());
                        } else {
                            attendanceBtns.forEach(btn => btn.disabled = true);
                            document.getElementById('attendanceMessage').textContent = 'Employee code not found in system.';
                            document.getElementById('attendanceMessage').style.color = 'red';
                            employeeCodeValid = false;
                        }
                    })
                    .catch(() => {
                        attendanceBtns.forEach(btn => btn.disabled = true);
                        document.getElementById('attendanceMessage').textContent = 'Server error during validation.';
                        document.getElementById('attendanceMessage').style.color = 'red';
                        employeeCodeValid = false;
                    });
                });
            
                // Patch updateButtonAvailability to only enable if code is valid
                const origUpdateButtonAvailability = updateButtonAvailability;
                window.updateButtonAvailability = function(now) {
                    if (!employeeCodeValid) {
                        attendanceBtns.forEach(btn => btn.disabled = true);
                        return;
                    }
                    origUpdateButtonAvailability(now);
                };
            });
            
            function checkEmployeeSession() {
                fetch('employee_auth.php?action=check_session', { credentials: 'include' })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.logged_in) {
                            console.log('Employee logged in:', data.employee_name);
                            // Update welcome message
                            const welcomeElement = document.querySelector('.sidebar-header p');
                            if (welcomeElement) {
                                welcomeElement.textContent = `Welcome, ${data.employee_name}`;
                            }
                        } else {
                            console.log('Employee not logged in - redirecting to login');
                            // Redirect to employee login page
                            window.location.href = 'FSM.ESM.EMPLOYEE.html';
                        }
                    })
                    .catch(error => {
                        console.error('Error checking session:', error);
                        // Redirect to login on error
                        window.location.href = 'FSM.ESM.EMPLOYEE.html';
                    });
            }
            
            function updateTimeBookSection() {
                console.log('updateTimeBookSection() called'); // Debug log
                
                // Get employee session info first
                fetch('get_employee_session_info.php', { credentials: 'include' })
                    .then(res => res.json())
                    .then(sessionData => {
                        console.log('Session data:', JSON.stringify(sessionData, null, 2)); // Debug log
                        
                        if (sessionData.success && sessionData.employee_code) {
                            // Valid session - get time book data for this employee
                            console.log('Valid session found for employee:', sessionData.employee_code);
                            return fetch('attendance_handler.php?action=get_time_book', { credentials: 'include' });
                        } else {
                            // No valid session - redirect to login or show error
                            console.log('No valid session - employee needs to login');
                            const tbody = document.getElementById('timeBookTableBody');
                            if (tbody) {
                                tbody.innerHTML = '<tr><td colspan="5">Please login to view your attendance records.</td></tr>';
                            }
                            throw new Error('No valid session');
                        }
                    })
                    .then(res => res.json())
                    .then(data => {
                        console.log('Time Book data received:', JSON.stringify(data, null, 2)); // Debug log
                        if (data.success) {
                            const tbody = document.getElementById('timeBookTableBody');
                            if (!tbody) return;
                            
                            tbody.innerHTML = '';
                            
                            if (data.records && data.records.length > 0) {
                                data.records.forEach(record => {
                                    const tr = document.createElement('tr');
                                    const date = new Date(record.date);
                                    const formattedDate = date.toLocaleDateString('en-US', { 
                                        day: '2-digit', 
                                        month: 'short', 
                                        year: 'numeric' 
                                    });
                                    
                                    const checkInTime = record.check_in_time ? 
                                        new Date('2000-01-01 ' + record.check_in_time).toLocaleTimeString('en-US', { 
                                            hour: '2-digit', 
                                            minute: '2-digit',
                                            hour12: true 
                                        }) : '-';
                                    
                                    const checkOutTime = record.check_out_time ? 
                                        new Date('2000-01-01 ' + record.check_out_time).toLocaleTimeString('en-US', { 
                                            hour: '2-digit', 
                                            minute: '2-digit',
                                            hour12: true 
                                        }) : '-';
                                    
                                    const totalHours = record.total_hours ? 
                                        parseFloat(record.total_hours).toFixed(2) : '-';
                                    
                                    const statusClass = getStatusClass(record.status);
                                    const statusText = getStatusText(record.status);
                                    
                                    tr.innerHTML = `
                                        <td>${formattedDate}</td>
                                        <td>${checkInTime}</td>
                                        <td>${checkOutTime}</td>
                                        <td>${totalHours}</td>
                                        <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                                    `;
                                    tbody.appendChild(tr);
                                });
                            } else {
                                tbody.innerHTML = '<tr><td colspan="5">No attendance records found.</td></tr>';
                            }
                        } else {
                            console.error('Failed to load Time Book data:', data.message);
                            const tbody = document.getElementById('timeBookTableBody');
                            if (tbody) {
                                tbody.innerHTML = '<tr><td colspan="5">Error loading attendance data. Please refresh the page.</td></tr>';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error loading Time Book data:', error);
                        const tbody = document.getElementById('timeBookTableBody');
                        if (tbody) {
                            tbody.innerHTML = '<tr><td colspan="5">Error loading attendance data. Please refresh the page.</td></tr>';
                        }
                    });
            }
            
            function getStatusClass(status) {
                switch (status) {
                    case 'present': return 'status-approved';
                    case 'present_late': return 'status-approved';
                    case 'late': return 'status-pending';
                    case 'absent': return 'status-rejected';
                    default: return 'status-approved';
                }
            }
            
            function getStatusText(status) {
                switch (status) {
                    case 'present': return 'Present';
                    case 'present_late': return 'Present Late';
                    case 'late': return 'Late';
                    case 'absent': return 'Absent';
                    default: return status;
                }
            }
            
            function disableAttendanceButtons() {
                document.getElementById('presentBtn').disabled = true;
                document.getElementById('presentLateBtn').disabled = true;
                document.getElementById('lateBtn').disabled = true;
                document.getElementById('absentBtn').disabled = true;
            }
            
            function disableAllButtons() {
                disableAttendanceButtons();
                document.getElementById('checkOutBtn').disabled = true;
            }
            
                    // Check if employee is logged in
        checkEmployeeSession();
        
        // Initialize the system
        initAttendanceSystem();
        checkTodayAttendance();
        updateTimeBookSection(); // Load Time Book data
        });

        
        let employeeCode = null;

        const employeeCodeInput = document.getElementById('employeeCode');
        const attendanceBtns = document.querySelectorAll('.attendance-btn');
        
        employeeCodeInput.addEventListener('input', function () {
            const code = employeeCodeInput.value.trim();
            const regex = /^\d{4}\/EMP\/\d+$/;
        
            document.getElementById('employeeCodeLoading').style.display = 'block';
        
            if (!regex.test(code)) {
                attendanceBtns.forEach(btn => btn.disabled = true);
                document.getElementById('attendanceMessage').textContent = 'Invalid employee code format.';
                document.getElementById('attendanceMessage').style.color = 'red';
                employeeCode = null;
                document.getElementById('employeeCodeLoading').style.display = 'none';
                return;
            }
        
            fetch('validate_employee_code.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ employee_code: code })
            })
            .then(res => {
                console.log('Response status:', res.status);
                console.log('Response headers:', res.headers);
                return res.text().then(text => {
                    console.log('Raw response text:', text);
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON parse error:', e);
                        throw new Error('Invalid JSON response: ' + text);
                    }
                });
            })
            .then(data => {
                console.log('Parsed response data:', data);
                document.getElementById('employeeCodeLoading').style.display = 'none';
        
                if (data.valid) {
                    document.getElementById('attendanceMessage').textContent = 'Code accepted. You may check in.';
                    document.getElementById('attendanceMessage').style.color = 'green';
                    employeeCode = data.employee_code; // ✅ store employee_code
                    // Only call updateButtonAvailability if it exists
                    if (typeof window.updateButtonAvailability === 'function') {
                        window.updateButtonAvailability(new Date());
                    }
                } else {
                    attendanceBtns.forEach(btn => btn.disabled = true);
                    document.getElementById('attendanceMessage').textContent = 'Employee code not found.';
                    document.getElementById('attendanceMessage').style.color = 'red';
                    employeeCode = null;
                }
            })
            .catch((error) => {
                console.error('Fetch error:', error);
                document.getElementById('employeeCodeLoading').style.display = 'none';
                attendanceBtns.forEach(btn => btn.disabled = true);
                document.getElementById('attendanceMessage').textContent = 'Server error during validation.';
                document.getElementById('attendanceMessage').style.color = 'red';
                employeeCode = null;
            });
        });


        document.addEventListener('DOMContentLoaded', function() {
    const leaveTypeSelect = document.getElementById('leaveType');
    const fromDateInput = document.getElementById('fromDate');
    const toDateInput = document.getElementById('toDate');
    const leaveNotice = document.getElementById('leaveNotice');

    function getDaysDiff(start, end) {
        const startDate = new Date(start);
        const endDate = new Date(end);
        return Math.floor((endDate - startDate) / (1000 * 60 * 60 * 24)) + 1;
    }

    leaveTypeSelect.addEventListener('change', function() {
        const selected = leaveTypeSelect.options[leaveTypeSelect.selectedIndex];
        const maxDays = parseInt(selected.getAttribute('data-max'), 10);

        fromDateInput.value = '';
        toDateInput.value = '';
        fromDateInput.removeAttribute('max');
        toDateInput.removeAttribute('max');
        leaveNotice.style.display = 'none';

        if (maxDays) {
            fromDateInput.onchange = function() {
                if (fromDateInput.value) {
                    const maxDate = new Date(fromDateInput.value);
                    maxDate.setDate(maxDate.getDate() + maxDays - 1);
                    toDateInput.min = fromDateInput.value;
                    toDateInput.max = maxDate.toISOString().split('T')[0];
                }
            };
            toDateInput.onchange = function() {
                if (fromDateInput.value && toDateInput.value) {
                    const days = getDaysDiff(fromDateInput.value, toDateInput.value);
                    if (days > maxDays) {
                        leaveNotice.textContent = `You cannot exceed ${maxDays} days for this leave type.`;
                        leaveNotice.style.color = 'red';
                        leaveNotice.style.display = 'block';
                        toDateInput.value = '';
                    } else {
                        leaveNotice.style.display = 'none';
                    }
                }
            };
        }
    });

    // On form submit, check for minimum/maximum days
    document.getElementById('leaveApplicationForm').addEventListener('submit', function(e) {
        const selected = leaveTypeSelect.options[leaveTypeSelect.selectedIndex];
        const maxDays = parseInt(selected.getAttribute('data-max'), 10);
        const from = fromDateInput.value;
        const to = toDateInput.value;
        if (from && to && maxDays) {
            const days = getDaysDiff(from, to);
            if (days > maxDays) {
                e.preventDefault();
                leaveNotice.textContent = `You cannot exceed ${maxDays} days for this leave type.`;
                leaveNotice.style.color = 'red';
                leaveNotice.style.display = 'block';
                return false;
            }
            if (selected.value === 'Emergency' && days < 1) {
                e.preventDefault();
                leaveNotice.textContent = `Emergency leave must be at least 1 day.`;
                leaveNotice.style.color = 'red';
                leaveNotice.style.display = 'block';
                return false;
            }
        }
    });
});
        
         // Notification System Implementation
         const bell = document.getElementById('notificationBell');
         const dropdown = document.getElementById('notificationDropdown');
         const blurBg = document.getElementById('blurBg');
         const dot = document.getElementById('notificationDot');
         const notificationList = document.getElementById('notificationList');
         const markAllReadBtn = document.getElementById('markAllRead');
 
         // State management
         let notifications = [];
         let unreadCount = 0;
 
                 // Fetch notifications from server
        async function fetchNotifications() {
            try {
                const response = await fetch('get_notifications.php?read=all', { 
                    credentials: 'include' 
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (data.success) {
                    // Transform database notifications to frontend format
                    notifications = data.notifications.map(notification => ({
                        id: notification.id,
                        message: notification.message,
                        time: formatTimeAgo(notification.created_at),
                        read: notification.is_read == 1,
                        type: notification.type
                    }));
                    
                    // Calculate unread count
                    unreadCount = notifications.filter(n => !n.read).length;
                    
                    // Update UI
                    updateNotificationUI();
                    
                    // Show dot if there are unread notifications
                    if (dot) dot.style.display = unreadCount > 0 ? 'block' : 'none';
                } else {
                    console.error('Failed to fetch notifications:', data.message);
                    // Fallback to empty notifications
                    notifications = [];
                    unreadCount = 0;
                    updateNotificationUI();
                    if (dot) dot.style.display = 'none';
                }
            } catch (error) {
                console.error('Error fetching notifications:', error);
                // Fallback to empty notifications
                notifications = [];
                unreadCount = 0;
                updateNotificationUI();
                if (dot) dot.style.display = 'none';
            }
        }
        
        // Helper function to format time ago
        function formatTimeAgo(createdAt) {
            const now = new Date();
            const created = new Date(createdAt);
            const diffMs = now - created;
            const diffMins = Math.floor(diffMs / (1000 * 60));
            const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
            const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
            
            if (diffMins < 1) return 'Just now';
            if (diffMins < 60) return `${diffMins} min ago`;
            if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
            if (diffDays < 7) return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;
            return created.toLocaleDateString();
        }
 
         // Update the notification UI
         function updateNotificationUI() {
             notificationList.innerHTML = '';
             
             if (notifications.length === 0) {
                 notificationList.innerHTML = '<div class="dropdown-empty">No notifications found.</div>';
                 dot.style.display = 'none';
             } else {
                 notifications.forEach(notification => {
                     const item = document.createElement('div');
                     item.className = `dropdown-item ${notification.read ? '' : 'unread'}`;
                     item.dataset.id = notification.id;
                     item.innerHTML = `
                         <div class="notification-message">${notification.message}</div>
                         <div class="notification-time">${notification.time}</div>
                     `;
                     
                                         // Mark as read when clicked
                    item.addEventListener('click', async function() {
                        if (!notification.read) {
                            try {
                                const response = await fetch('mark_notification_read.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    credentials: 'include',
                                    body: JSON.stringify({ id: notification.id })
                                });
                                
                                if (response.ok) {
                                    const data = await response.json();
                                    if (data.success) {
                                        notification.read = true;
                                        unreadCount--;
                                        item.classList.remove('unread');
                                        if (dot) dot.style.display = unreadCount > 0 ? 'block' : 'none';
                                    }
                                }
                            } catch (error) {
                                console.error('Error marking notification as read:', error);
                            }
                        }
                    });
                     
                     notificationList.appendChild(item);
                 });
             }
         }
 
                 // Mark all notifications as read
        async function markAllAsRead() {
            try {
                const response = await fetch('mark_notifications_read.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include'
                });
                
                if (response.ok) {
                    const data = await response.json();
                    if (data.success) {
                        // Update local state
                        notifications.forEach(notification => {
                            notification.read = true;
                        });
                        unreadCount = 0;
                        updateNotificationUI();
                        if (dot) dot.style.display = 'none';
                    }
                }
            } catch (error) {
                console.error('Error marking notifications as read:', error);
            }
        }
 
         // Toggle notification dropdown
         function toggleDropdown() {
             const isOpen = dropdown.style.display === 'block';
             dropdown.style.display = isOpen ? 'none' : 'block';
             blurBg.style.display = isOpen ? 'none' : 'block';
             
             // Add active class for animation
             bell.classList.add('active');
             setTimeout(() => bell.classList.remove('active'), 300);
             
             // Mark all as read when dropdown is opened
             if (!isOpen) {
                 markAllAsRead();
             }
         }
 
         // Event listeners
         bell.addEventListener('click', function(e) {
             e.stopPropagation();
             toggleDropdown();
         });
 
                 if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                markAllAsRead();
            });
        }

        if (blurBg) {
            blurBg.addEventListener('click', function() {
                dropdown.style.display = 'none';
                blurBg.style.display = 'none';
            });
        }
 
                 // Hide dropdown when clicking outside or pressing ESC
        document.addEventListener('click', function(e) {
            if (dropdown && blurBg && !dropdown.contains(e.target) && e.target !== bell) {
                dropdown.style.display = 'none';
                blurBg.style.display = 'none';
            }
        });

        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && dropdown && blurBg) {
                dropdown.style.display = 'none';
                blurBg.style.display = 'none';
            }
        });
 
                 // Load notifications from server
        fetchNotifications(); // Initial load
        
        // Refresh notifications periodically (every 30 seconds)
        setInterval(() => {
            fetchNotifications();
        }, 30000);

        // --- Dynamic Leave History Section ---
        function fetchAndRenderLeaveHistory() {
            fetch('fetch_employee_leaves.php', { credentials: 'include' })
                .then(res => res.json())
                .then(data => {
                    const tbody = document.querySelector('#leave-history-section .data-table tbody');
                    if (tbody) {
                        tbody.innerHTML = '';
                        if (data.success && Array.isArray(data.leaves)) {
                            data.leaves.forEach(leave => {
                                const tr = document.createElement('tr');
                                tr.innerHTML = `
                                    <td>${leave.type}</td>
                                    <td>${leave.start_date}</td>
                                    <td>${leave.end_date}</td>
                                    <td>${leave.days}</td>
                                    <td>${leave.reason}</td>
                                    <td><span class="status-badge status-${leave.status.toLowerCase()}">${leave.status}</span></td>
                                `;
                                tbody.appendChild(tr);
                            });
                        } else {
                            tbody.innerHTML = '<tr><td colspan="6">No leave history found.</td></tr>';
                        }
                    }
                });
        }
        // --- Dynamic Leave Requests Modal ---
        function fetchAndRenderLeaveRequestsModal() {
            fetch('fetch_employee_leaves.php', { credentials: 'include' })
                .then(res => res.json())
                .then(data => {
                    const tbody = document.querySelector('#leaveRequestsModal .data-table tbody');
                    if (tbody) {
                        tbody.innerHTML = '';
                        if (data.success && Array.isArray(data.leaves)) {
                            data.leaves.forEach(leave => {
                                const tr = document.createElement('tr');
                                tr.innerHTML = `
                                    <td>${leave.type}</td>
                                    <td>${leave.start_date}</td>
                                    <td>${leave.end_date}</td>
                                    <td>${leave.days}</td>
                                    <td>${leave.reason}</td>
                                    <td><span class="status-badge status-${leave.status.toLowerCase()}">${leave.status}</span></td>
                                `;
                                tbody.appendChild(tr);
                            });
                        } else {
                            tbody.innerHTML = '<tr><td colspan="6">No leave requests found.</td></tr>';
                        }
                    }
                });
        }
        // --- Dynamic Approved Requests Modal ---
        function fetchAndRenderApprovedRequestsModal() {
            fetch('fetch_approved_leaves.php', { credentials: 'include' })
                .then(res => res.json())
                .then(data => {
                    const tbody = document.querySelector('#approvedRequestsModal .data-table tbody');
                    if (tbody) {
                        tbody.innerHTML = '';
                        if (data.success && Array.isArray(data.leaves)) {
                            data.leaves.forEach(leave => {
                                const tr = document.createElement('tr');
                                tr.innerHTML = `
                                    <td>${leave.type}</td>
                                    <td>${leave.start_date}</td>
                                    <td>${leave.end_date}</td>
                                    <td>${leave.days}</td>
                                    <td>${leave.reason}</td>
                                    <td>${leave.approved_on || ''}</td>
                                `;
                                tbody.appendChild(tr);
                            });
                        } else {
                            tbody.innerHTML = '<tr><td colspan="6">No approved requests found.</td></tr>';
                        }
                    }
                });
        }
        // --- Dynamic Rejected Requests Modal ---
        function fetchAndRenderRejectedRequestsModal() {
            fetch('fetch_rejected_leaves.php', { credentials: 'include' })
                .then(res => res.json())
                .then(data => {
                    const tbody = document.querySelector('#rejectedRequestsModal .data-table tbody');
                    if (tbody) {
                        tbody.innerHTML = '';
                        if (data.success && Array.isArray(data.leaves)) {
                            data.leaves.forEach(leave => {
                                const tr = document.createElement('tr');
                                tr.innerHTML = `
                                    <td>${leave.type}</td>
                                    <td>${leave.start_date}</td>
                                    <td>${leave.end_date}</td>
                                    <td>${leave.days}</td>
                                    <td>${leave.reason}</td>
                                    <td>${leave.rejected_on || ''}</td>
                                    <td>${leave.rejection_reason || ''}</td>
                                `;
                                tbody.appendChild(tr);
                            });
                        } else {
                            tbody.innerHTML = '<tr><td colspan="7">No rejected requests found.</td></tr>';
                        }
                    }
                });
        }
        // --- Dynamic Today's Tasks Modal ---
        function fetchAndRenderTodaysTasksModal() {
            fetch('fetch_todays_tasks.php', { credentials: 'include' })
                .then(res => res.json())
                .then(data => {
                    const tbody = document.querySelector('#todaysTasksModal .data-table tbody');
                    if (tbody) {
                        tbody.innerHTML = '';
                        if (data.success && Array.isArray(data.tasks)) {
                            data.tasks.forEach(task => {
                                const tr = document.createElement('tr');
                                tr.innerHTML = `
                                    <td>${task.task}</td>
                                    <td>${task.project}</td>
                                    <td>${task.priority}</td>
                                    <td>${task.due_time}</td>
                                    <td><span class="status-badge status-${task.status.toLowerCase()}">${task.status}</span></td>
                                `;
                                tbody.appendChild(tr);
                            });
                        } else {
                            tbody.innerHTML = '<tr><td colspan="5">No tasks for today.</td></tr>';
                        }
                    }
                });
        }
        // --- Dynamic Finished Tasks Modal ---
        function fetchAndRenderFinishedTasksModal() {
            fetch('fetch_finished_tasks.php', { credentials: 'include' })
                .then(res => res.json())
                .then(data => {
                    const tbody = document.querySelector('#finishedTasksModal .data-table tbody');
                    if (tbody) {
                        tbody.innerHTML = '';
                        if (data.success && Array.isArray(data.tasks)) {
                            data.tasks.forEach(task => {
                                const tr = document.createElement('tr');
                                tr.innerHTML = `
                                    <td>${task.task}</td>
                                    <td>${task.project}</td>
                                    <td>${task.completed_on}</td>
                                    <td><span class="status-badge status-${task.status.toLowerCase()}">${task.status}</span></td>
                                    <td><a href="${task.proof_url || '#'}">View</a></td>
                                `;
                                tbody.appendChild(tr);
                            });
                        } else {
                            tbody.innerHTML = '<tr><td colspan="5">No finished tasks found.</td></tr>';
                        }
                    }
                });
        }
        // --- Call these functions on modal open or section load ---
        function showLeaveRequests() {
            document.getElementById('leaveRequestsModal').style.display = 'flex';
            fetchAndRenderLeaveRequestsModal();
        }
        function showApprovedRequests() {
            document.getElementById('approvedRequestsModal').style.display = 'flex';
            fetchAndRenderApprovedRequestsModal();
        }
        function showRejectedRequests() {
            document.getElementById('rejectedRequestsModal').style.display = 'flex';
            fetchAndRenderRejectedRequestsModal();
        }
        function showTodaysTasks() {
            document.getElementById('todaysTasksModal').style.display = 'flex';
            fetchAndRenderTodaysTasksModal();
        }
        function showFinishedTasks() {
            document.getElementById('finishedTasksModal').style.display = 'flex';
            fetchAndRenderFinishedTasksModal();
        }
        // Fetch leave history on page load
        fetchAndRenderLeaveHistory();
        
        // --- Dynamic Home Cards Update Script ---
        console.log('=== DYNAMIC HOME CARDS UPDATE SCRIPT STARTED ===');
        
        // Function to update home cards with retry logic
        function updateHomeCards(retryCount = 0) {
            const maxRetries = 3;
            console.log(`Attempting to update home cards (attempt ${retryCount + 1}/${maxRetries + 1})`);
            
            // Only show loading state if this is a retry (not initial load)
            const cardIds = ['cardMyLeaveRequests', 'cardApprovedRequests', 'cardRejectedRequests', 'cardTodaysTasks', 'cardFinishedTasks'];
            if (retryCount > 0) {
                cardIds.forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = 'Loading...';
                });
            }
            
            fetch('get_employee_data.php', { 
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                }
            })
            .then(res => {
                console.log('Response status:', res.status);
                console.log('Response headers:', res.headers);
                if (!res.ok) {
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                return res.json();
            })
            .then(data => {
                console.log('=== API RESPONSE RECEIVED ===');
                console.log('Full response:', JSON.stringify(data, null, 2));
                
                if (data.success && data.counts) {
                    console.log('Counts data:', data.counts);
                    
                    const updates = [
                        { id: 'cardMyLeaveRequests', value: data.counts.pending_leave },
                        { id: 'cardApprovedRequests', value: data.counts.approved_leave },
                        { id: 'cardRejectedRequests', value: data.counts.rejected_leave },
                        { id: 'cardTodaysTasks', value: data.counts.pending_tasks },
                        { id: 'cardFinishedTasks', value: data.counts.completed_tasks }
                    ];
                    
                    updates.forEach(update => {
                        const el = document.getElementById(update.id);
                        if (el) {
                            el.textContent = update.value;
                            console.log(`✅ Updated ${update.id} to ${update.value}`);
                        } else {
                            console.error(`❌ Element not found: ${update.id}`);
                        }
                    });
                    
                    console.log('=== ALL CARDS UPDATED SUCCESSFULLY ===');
                } else {
                    console.error('❌ API response not successful or missing counts');
                    console.error('Response:', data);
                    
                    // Set error state
                    cardIds.forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.textContent = 'Error';
                    });
                    
                    // Retry if we haven't exceeded max retries
                    if (retryCount < maxRetries) {
                        console.log(`Retrying in 2 seconds... (${retryCount + 1}/${maxRetries})`);
                        setTimeout(() => updateHomeCards(retryCount + 1), 2000);
                    }
                }
            })
            .catch(err => {
                console.error('❌ Error fetching employee data:', err);
                console.error('Error details:', err.message);
                
                // Set error state
                cardIds.forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = 'Error';
                });
                
                // Retry if we haven't exceeded max retries
                if (retryCount < maxRetries) {
                    console.log(`Retrying in 2 seconds... (${retryCount + 1}/${maxRetries})`);
                    setTimeout(() => updateHomeCards(retryCount + 1), 2000);
                }
            });
        }
        
        // Test function to check if elements exist
        function testElements() {
            console.log('=== TESTING ELEMENT EXISTENCE ===');
            const cardIds = ['cardMyLeaveRequests', 'cardApprovedRequests', 'cardRejectedRequests', 'cardTodaysTasks', 'cardFinishedTasks'];
            cardIds.forEach(id => {
                const el = document.getElementById(id);
                console.log(`${id}: ${el ? '✅ Found' : '❌ Not found'}`);
            });
        }
        
        // Initialize when DOM is ready - only call once
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                console.log('=== DOM CONTENT LOADED ===');
                testElements();
                updateHomeCards();
            });
        } else {
            console.log('=== DOM ALREADY LOADED ===');
            testElements();
            updateHomeCards();
        }

        // Function to load all dynamic employee data
        function loadEmployeeDynamicData(employeeId) {
            console.log('=== LOADING EMPLOYEE DYNAMIC DATA ===');
            console.log('Employee ID:', employeeId);
            
            // Load home cards data
            updateHomeCards();
            
            // Load payroll data
            loadPayrollData(employeeId);
            
            // Load attendance data
            loadAttendanceData();
            
            // Load leave history
            fetchAndRenderLeaveHistory();
            
            // Load notifications
            fetchNotifications();
            
            // Add payroll filter event listeners
            const payrollFilterMonth = document.getElementById('payrollFilterMonth');
            const payrollFilterYear = document.getElementById('payrollFilterYear');
            
            if (payrollFilterMonth) {
                payrollFilterMonth.addEventListener('change', () => filterPayrollData(employeeId));
                console.log('✅ Payroll month filter listener added');
            } else {
                console.log('⚠️ Payroll month filter element not found');
            }
            
            if (payrollFilterYear) {
                payrollFilterYear.addEventListener('change', () => filterPayrollData(employeeId));
                console.log('✅ Payroll year filter listener added');
            } else {
                console.log('⚠️ Payroll year filter element not found');
            }
            
            console.log('All dynamic data loading initiated');
        }

        // Function to load attendance data
        function loadAttendanceData() {
            console.log('Loading attendance data...');
            fetch('get_attendance_data.php', { credentials: 'include' })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        console.log('Attendance data loaded successfully');
                        // Update attendance-related elements if they exist
                        updateAttendanceDisplay(data);
                    } else {
                        console.error('Failed to load attendance data:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error loading attendance data:', error);
                });
        }

        // Function to update attendance display
        function updateAttendanceDisplay(data) {
            // Update attendance cards or elements if they exist
            const attendanceElements = document.querySelectorAll('[id*="attendance"]');
            attendanceElements.forEach(element => {
                console.log('Found attendance element:', element.id);
            });
        }

        // Utility function to safely add event listeners
        function safeAddEventListener(elementId, eventType, handler) {
            const element = document.getElementById(elementId);
            if (element) {
                element.addEventListener(eventType, handler);
                console.log(`✅ Event listener added for ${elementId}`);
                return true;
            } else {
                console.log(`⚠️ Element ${elementId} not found, skipping event listener`);
                return false;
            }
        }

        // Function to safely add all event listeners
        function initializeEventListeners() {
            console.log('=== INITIALIZING EVENT LISTENERS ===');
            
            // Logout button
            safeAddEventListener('logoutBtn', 'click', function () {
                sessionStorage.clear();
                localStorage.clear();
                window.location.href = 'FSM.ESM.FRONT.1.html';
            });
            
            // Ticket management link
            safeAddEventListener('ticketManagementLink', 'click', function () {
                window.location.href = 'Ticket_Management_System.html';
            });
            
            // Leave application form
            safeAddEventListener('leaveApplicationForm', 'submit', function(e) {
                e.preventDefault();
                // Leave form submission logic here
            });
            
            // Attendance buttons
            safeAddEventListener('presentBtn', 'click', () => markAttendance('present'));
            safeAddEventListener('presentLateBtn', 'click', () => markAttendance('present_late'));
            safeAddEventListener('lateBtn', 'click', () => markAttendance('late'));
            safeAddEventListener('absentBtn', 'click', () => markAttendance('absent'));
            safeAddEventListener('checkOutBtn', 'click', checkOut);
            
            console.log('=== EVENT LISTENERS INITIALIZED ===');
        }

        // Simple sidebar navigation - handled by onclick handlers in HTML
        
        // Simple showSection function like Admin Dashboard
        function showSection(sectionName) {
            console.log('Showing section:', sectionName);
            
            // Hide all sections
            document.querySelectorAll('.content-section').forEach(section => {
                section.style.display = 'none';
            });
            
            // Show the selected section
            const targetSection = document.getElementById(sectionName + '-section');
            if (targetSection) {
                targetSection.style.display = 'block';
                
                // Update active menu item
                document.querySelectorAll('.menu-item').forEach(item => {
                    item.classList.remove('active');
                });
                
                const activeMenuItem = document.querySelector(`[data-section="${sectionName}"]`);
                if (activeMenuItem) {
                    activeMenuItem.classList.add('active');
                }
            }
        }
        
        function markAttendance(status) {
            const reason = document.getElementById('attendanceReason').value;
            const employeeCode = document.getElementById('employeeCode').value;
            
            if ((status === 'late' || status === 'present_late' || status === 'absent') && !reason.trim()) {
                document.getElementById('attendanceMessage').textContent = 'Please provide a reason for being late or absent';
                document.getElementById('attendanceMessage').style.color = 'red';
                return;
            }
            
            fetch('attendance_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'check_in',
                    status: status,
                    reason: reason,
                    employee_code: employeeCode
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('attendanceMessage').textContent = 'Attendance marked successfully!';
                    document.getElementById('attendanceMessage').style.color = 'green';
                    document.getElementById('todayStatusText').textContent = status.replace('_', ' ').toUpperCase();
                    document.getElementById('checkInTime').innerHTML = `<strong>Check-in:</strong> ${data.check_in_time}`;
                    document.getElementById('checkOutBtn').disabled = false;
                    disableAttendanceButtons();
                    
                    // Update Time Book section
                    updateTimeBookSection();
                } else {
                    document.getElementById('attendanceMessage').textContent = data.message || 'Failed to mark attendance';
                    document.getElementById('attendanceMessage').style.color = 'red';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('attendanceMessage').textContent = 'An error occurred while marking attendance';
                document.getElementById('attendanceMessage').style.color = 'red';
            });
        }

        // Add fallback for default-avatar.jpg
        window.addEventListener('DOMContentLoaded', function() {
            var imgs = document.querySelectorAll('img#profileImage, img#headerProfileImage');
            imgs.forEach(function(img) {
                img.onerror = function() {
                    this.onerror = null;
                    this.src = 'https://ui-avatars.com/api/?name=User&background=ddd&color=555';
                };
            });
        });

        // Sidebar navigation logic
        const menuItems = document.querySelectorAll('.menu-item');
        const contentSections = document.querySelectorAll('.content-section');

        // Initialize sidebar functionality when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            console.log('=== INITIALIZING SIDEBAR FUNCTIONALITY ===');
            
            // Get sidebar elements
            const dashboard = document.querySelector('.dashboard');
            const sidebar = document.querySelector('.sidebar');
            const toggleBtn = document.getElementById('sidebarCollapse');
            const menuItems = document.querySelectorAll('.menu-item');
            const contentSections = document.querySelectorAll('.content-section');
            
            // Sidebar toggle functionality
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    console.log('Toggle Sidebar button clicked!');
                    dashboard.classList.toggle('sidebar-collapsed');
                    if (window.innerWidth <= 900) {
                        sidebar.classList.toggle('open');
                    }
                });
            }
            
            // Section switching functionality
            function showSection(sectionId) {
                console.log('Showing section:', sectionId);
                
                // Hide all content sections
                contentSections.forEach(section => {
                    section.classList.remove('active');
                    section.style.display = 'none';
                });
                
                // Show the target section
                const targetSection = document.getElementById(sectionId);
                if (targetSection) {
                    targetSection.classList.add('active');
                    targetSection.style.display = 'block';
                }
                
                // Update active menu item
                menuItems.forEach(item => {
                    item.classList.remove('active');
                });
                
                const activeMenuItem = document.querySelector(`[data-section="${sectionId.replace('-section', '')}"]`);
                if (activeMenuItem) {
                    activeMenuItem.classList.add('active');
                }
                
                // Load section-specific data
                loadSectionData(sectionId);
            }
            
            // Add click listeners to menu items
            menuItems.forEach(item => {
                item.addEventListener('click', function() {
                    const sectionKey = this.getAttribute('data-section');
                    console.log('Menu item clicked:', sectionKey);
                    
                    if (sectionKey === 'logout') {
                        window.location.href = 'FSM.ESM.EMPLOYEE.html';
                        return;
                    }
                    
                    const sectionId = sectionKey + '-section';
                    showSection(sectionId);
                });
            });
            
            // Show home-section by default
            showSection('home-section');
            
            // Responsive: close sidebar on click outside (mobile)
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 900 && sidebar.classList.contains('open')) {
                    if (!sidebar.contains(e.target) && e.target !== toggleBtn) {
                        sidebar.classList.remove('open');
                    }
                }
            });
            
            console.log('=== SIDEBAR FUNCTIONALITY INITIALIZED ===');
        });
        
        // Close any remaining open functions
        });
        });
