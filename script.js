// Global Data Storage
const OWNER_EMAIL = 'stroogar@gmail.com';
let currentUser = {
    id: null,
    username: null,
    role: null, // 'admin' or 'manager'
    assignedStudents: []
};

let adminLogs = [];
let studentDistribution = {};
let helpRequests = [];
let clients = [];
let admins = [];
let payments = [];

// API Base URL
const API_BASE = 'backend/api.php';

// Check login status on page load
document.addEventListener('DOMContentLoaded', function() {
    checkLoginStatus();
    if (isLoggedIn()) {
        initializeNavigation();
        loadDataFromAPI();
        initRealtime();
        setupEventListeners();
        updateUserInfo();
        // checkUserRole will be called after data is loaded
    }
});

// No-op realtime stub (disabled for now)
function initRealtime() {}

// Login Functions
function checkLoginStatus() {
    if (!isLoggedIn()) {
        window.location.href = 'login.html';
    }
}

function isLoggedIn() {
    return localStorage.getItem('isLoggedIn') === 'true';
}

function updateUserInfo() {
    const userEmail = localStorage.getItem('currentUser');
    const isMainAdmin = localStorage.getItem('isMainAdmin') === 'true';
    
    console.log('🔄 Updating user info...');
    console.log('User email:', userEmail);
    console.log('Is main admin:', isMainAdmin);
    
    const userInfoElement = document.getElementById('currentUser');
    if (userInfoElement) {
        userInfoElement.textContent = userEmail || 'المدير الرئيسي';
        console.log('✅ Updated user info element');
    } else {
        console.log('❌ User info element not found');
    }
    
    // Show/hide add admin button based on user role
    const addAdminBtn = document.getElementById('addAdminBtn');
    if (addAdminBtn) {
        addAdminBtn.style.display = isMainAdmin ? 'flex' : 'none';
        console.log('✅ Updated add admin button display:', addAdminBtn.style.display);
    } else {
        console.log('❌ Add admin button not found');
    }
    
    console.log('🎉 User info update completed');
}

function handleLogout() {
    localStorage.removeItem('isLoggedIn');
    localStorage.removeItem('currentUser');
    window.location.href = 'login.html';
}

// Navigation
function initializeNavigation() {
    const navItems = document.querySelectorAll('.nav-item');
    
    navItems.forEach(item => {
        item.addEventListener('click', function() {
            const targetPage = this.getAttribute('data-page');
            showPage(targetPage);
            
            // Update active state
            navItems.forEach(nav => nav.classList.remove('active'));
            this.classList.add('active');
        });
    });
}

function showPage(pageId) {
    const pages = document.querySelectorAll('.page');
    pages.forEach(page => page.classList.remove('active'));
    
    const targetPage = document.getElementById(pageId);
    if (targetPage) {
        targetPage.classList.add('active');
    }
}

// API Functions
async function loadDataFromAPI() {
    try {
        await Promise.all([
            loadClientsFromAPI(),
            loadPaymentsFromAPI(),
            loadAdminsFromAPI(),
            loadDashboardFromAPI(),
            loadHelpRequestsFromAPI(),
            loadStudentDistributionFromAPI() // Add this line to load distribution on page load
        ]);
        
        // After data is loaded, check user role and permissions
        // Ensure user context is loaded first
        await updateUserInfo();
        checkUserRole();
        // Re-render client/student lists to apply owner privileges before button rendering
        if (typeof loadClients === 'function') loadClients();
        if (typeof renderStudents === 'function') renderStudents();
        // Update inbox badge
        updateHelpBadge();
    } catch (error) {
        console.error('Error loading data:', error);
        showNotification('خطأ في تحميل البيانات', 'error');
    }
}

async function loadClientsFromAPI() {
    const response = await fetch(`${API_BASE}?action=clients`);
    const result = await response.json();
    if (result.success) {
        clients = result.data;
        loadClients();
        // Reconcile distribution when clients change
        reconcileDistribution();
    }
}

async function loadPaymentsFromAPI() {
    const response = await fetch(`${API_BASE}?action=payments`);
    const result = await response.json();
    if (result.success) {
        payments = result.data;
        loadPayments();
    }
}

async function loadAdminsFromAPI() {
    try {
        console.log('🔄 Loading admins from API...');
        const response = await fetch(`${API_BASE}?action=admins`);
        const result = await response.json();
        
        console.log('📡 Admins API response:', result);
        
        if (result.success) {
            admins = result.data;
            console.log('✅ Admins loaded successfully:', admins);
            loadAdmins();
            // Reconcile distribution when admins change
            reconcileDistribution();
        } else {
            console.error('❌ Failed to load admins:', result.message);
        }
    } catch (error) {
        console.error('❌ Error loading admins:', error);
    }
}

async function loadDashboardFromAPI() {
    const response = await fetch(`${API_BASE}?action=dashboard`);
    const result = await response.json();
    if (result.success) {
        updateAnalytics(result.data);
    }
}

async function loadHelpRequestsFromAPI() {
    try {
        const res = await fetch(`${API_BASE}?action=get_help_requests`);
        const json = await res.json();
        if (json.success) {
            helpRequests = json.data.help_requests || [];
            updateHelpBadge();
        }
    } catch (e) {
        console.error('Failed to load help requests', e);
    }
}

// Dashboard Functions
function updateAnalytics(data) {
    // Update main analytics cards
    const totalRevenue = Number(data.totalRevenue || 0);
    const target = Number(data.targetRevenue || 80000);
    const progress = Math.min(100, Math.round((totalRevenue / target) * 100));
    document.querySelector('.analytics-card:nth-child(1) .amount').textContent = `${totalRevenue.toLocaleString()} ج.م`;
    const revChangeEl = document.querySelector('.analytics-card:nth-child(1) .change');
    if (revChangeEl) revChangeEl.textContent = `الهدف: ${target.toLocaleString()} ج.م (${progress}%)`;
    document.querySelector('.analytics-card:nth-child(2) .amount').textContent = data.pendingPayments; // now pending applications
    document.querySelector('.analytics-card:nth-child(3) .amount').textContent = data.appliedCount;
    document.querySelector('.analytics-card:nth-child(4) .amount').textContent = data.totalClients;
    
    // Update status cards
    document.querySelector('.status-card.accepted .count').textContent = data.acceptedCount;
    document.querySelector('.status-card.rejected .count').textContent = data.rejectedCount;
    
    // Update payments summary
    document.querySelector('.payments-summary .summary-card:nth-child(1) .amount').textContent = `${totalRevenue.toLocaleString()} ج.م`;
    document.querySelector('.payments-summary .summary-card:nth-child(2) .amount').textContent = `${data.pendingPayments} طلب`;
    document.querySelector('.payments-summary .summary-card:nth-child(3) .amount').textContent = data.totalClients;
}

function updateHelpBadge() {
    const assigned = (studentDistribution && studentDistribution[currentUser.id]) ? studentDistribution[currentUser.id] : [];
    const assignedIds = new Set(assigned.map(s=>Number(s.id)));
    const unread = (helpRequests || []).filter(r => {
        const targeted = Number(r.assignedAdminId) === Number(currentUser.id);
        const assignedMatch = assignedIds.has(Number(r.studentId));
        const isUnread = !r.readBy || !r.readBy.includes(currentUser.id);
        const isActive = r.status === 'pending';
        return (targeted || assignedMatch) && r.requesterId !== currentUser.id && isUnread && isActive;
    });
    const badge = document.getElementById('helpUnreadBadge');
    if (badge) {
        if (unread.length > 0) { badge.style.display='inline-block'; badge.textContent = unread.length; } else { badge.style.display='none'; }
    }
}

// Clients Functions
function loadClients() {
    const clientsGrid = document.getElementById('clientsGrid');
    if (!clientsGrid) return;
    
    clientsGrid.innerHTML = '';
    
    if (clients.length === 0) {
        clientsGrid.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <h3>لا توجد عملاء</h3>
                <p>ابدأ بإضافة عميل جديد أو استيراد البيانات من Excel</p>
            </div>
        `;
        return;
    }
    
    clients.forEach(client => {
        const clientCard = createClientCard(client);
        clientsGrid.appendChild(clientCard);
    });
}

function createClientCard(client) {
    const card = document.createElement('div');
    card.className = 'client-card';
    card.dataset.clientId = client.id;
    card.dataset.studentId = client.id; // For global access control
    
    const statusClass = getApplicationStatusClass(client.applicationStatus);
    
    const isAssigned = (currentUser && currentUser.username === OWNER_EMAIL) ? true : canAccessStudent(client.id);
    if (!isAssigned) card.classList.add('locked');
    
    card.innerHTML = `
        <div class="card-header">
            <h3>${client.fullName}</h3>
            <span class="status-badge ${statusClass}">${client.applicationStatus}</span>
        </div>
        <div class="card-content">
            <p><i class="fas fa-envelope"></i> ${client.email}</p>
            <p><i class="fas fa-phone"></i> ${client.phone}</p>
            <p><i class="fas fa-graduation-cap"></i> ${client.scholarshipType}</p>
            <p><i class="fas fa-university"></i> ${client.university}</p>
            <p><i class="fas fa-book"></i> ${client.specialization}</p>
            ${client.notes ? `<p><i class="fas fa-sticky-note"></i> ${client.notes}</p>` : ''}
        </div>
        <div class="client-actions">
            <button class="action-btn view-btn" ${(!isAssigned && currentUser.username!==OWNER_EMAIL) ? 'disabled' : ''} onclick="viewClient(${client.id})">
                <i class="fas fa-eye"></i> عرض
            </button>
            <button class="action-btn edit-btn" ${(!isAssigned && currentUser.username!==OWNER_EMAIL) ? 'disabled' : ''} onclick="editClient(${client.id})">
                <i class="fas fa-edit"></i> تعديل
            </button>
            <button class="action-btn status-btn" ${(!isAssigned && currentUser.username!==OWNER_EMAIL) ? 'disabled' : ''} onclick="openApplicationStatusModal(${client.id})">
                <i class="fas fa-flag"></i> الحالة
            </button>
            <button class="action-btn delete-btn" ${(!isAssigned && currentUser.username!==OWNER_EMAIL) ? 'disabled' : ''} onclick="deleteClient(${client.id})">
                <i class="fas fa-trash"></i> حذف
            </button>
        </div>
    `;
    
    return card;
}

function getApplicationStatusClass(status) {
    switch (status) {
        case 'لم يتم التقديم': return 'not-applied';
        case 'تم التقديم': return 'applied';
        case 'مقبول': return 'accepted';
        case 'مرفوض': return 'rejected';
        default: return 'not-applied';
    }
}

// Payments Functions
function loadPayments() {
    const paymentsGrid = document.getElementById('paymentsGrid');
    if (!paymentsGrid) return;
    
    paymentsGrid.innerHTML = '';
    
    if (payments.length === 0) {
        paymentsGrid.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-credit-card"></i>
                <h3>لا توجد مدفوعات</h3>
                <p>ستظهر المدفوعات هنا عند إضافة العملاء</p>
            </div>
        `;
        return;
    }
    
    payments.forEach(payment => {
        const paymentCard = createPaymentCard(payment);
        paymentsGrid.appendChild(paymentCard);
    });
}

function createPaymentCard(payment) {
    const card = document.createElement('div');
    card.className = 'payment-card';
    
    const statusClass = payment.status === 'مدفوع' ? 'paid' : 'pending';
    
    card.innerHTML = `
        <div class="card-header">
            <h3>${payment.clientName}</h3>
            <span class="status-badge ${statusClass}">${payment.status}</span>
        </div>
        <div class="card-content">
            <p><i class="fas fa-money-bill"></i> المبلغ: ${payment.amount} ج.م</p>
            ${payment.fromNumber ? `<p><i class="fas fa-arrow-right"></i> من: ${payment.fromNumber}</p>` : ''}
            ${payment.toNumber ? `<p><i class="fas fa-arrow-left"></i> إلى: ${payment.toNumber}</p>` : ''}
            
            ${payment.date ? `<p><i class="fas fa-calendar"></i> التاريخ: ${payment.date}</p>` : ''}
        </div>
        <div class="payment-actions">
            <button class="action-btn view-btn" onclick="viewPayment(${payment.id})">
                <i class="fas fa-eye"></i> عرض
            </button>
            <button class="action-btn edit-btn" onclick="editPayment(${payment.id})">
                <i class="fas fa-edit"></i> تعديل
            </button>
        </div>
    `;
    
    return card;
}

// Admins Functions
function loadAdmins() {
    console.log('🔄 Loading admins to grid...');
    console.log('Admins array:', admins);
    
    const adminsGrid = document.getElementById('adminsGrid');
    if (!adminsGrid) {
        console.log('❌ Admins grid not found!');
        return;
    }
    
    console.log('✅ Admins grid found, clearing and populating...');
    adminsGrid.innerHTML = '';
    
    if (admins.length === 0) {
        console.log('⚠️ No admins to display');
        adminsGrid.innerHTML = '<p>لا يوجد مشرفين</p>';
        return;
    }
    
    admins.forEach((admin, index) => {
        console.log(`Creating admin card ${index + 1}:`, admin);
        const adminCard = createAdminCard(admin);
        adminsGrid.appendChild(adminCard);
    });
    
    console.log(`✅ Loaded ${admins.length} admin cards`);
}

async function deleteAdmin(adminId) {
    if (!confirm('هل أنت متأكد من حذف هذا المشرف؟')) return;
    try {
        const response = await fetch(`${API_BASE}?action=delete_admin&id=${adminId}`, { method: 'DELETE' });
        const result = await response.json();
        if (result.success) {
            await loadAdminsFromAPI();
            showNotification('تم حذف المشرف بنجاح', 'success');
        } else {
            showNotification('فشل في حذف المشرف', 'error');
        }
    } catch (e) {
        showNotification('خطأ في حذف المشرف', 'error');
    }
}

function createAdminCard(admin) {
    const card = document.createElement('div');
    card.className = 'admin-card';
    
    card.innerHTML = `
        <div class="card-header">
            <h3>${admin.fullName}</h3>
            <span class="role-badge">${admin.role}</span>
        </div>
        <div class="card-content">
            <p><i class="fas fa-envelope"></i> ${admin.email}</p>
            <p><i class="fas fa-calendar"></i> تاريخ الإضافة: ${admin.createdAt}</p>
            ${admin.isMainAdmin ? '<p><i class="fas fa-crown"></i> المدير الرئيسي</p>' : ''}
        </div>
        <div class="admin-actions">
            <button class="action-btn view-btn" onclick="viewAdminDetails(${admin.id})">
                <i class="fas fa-info-circle"></i> عرض التفاصيل
            </button>
            ${!admin.isMainAdmin ? `
                <button class="action-btn edit-btn" onclick="editAdmin(${admin.id})">
                    <i class="fas fa-edit"></i> تعديل
                </button>
                <button class="action-btn delete-btn" onclick="deleteAdmin(${admin.id})">
                    <i class="fas fa-trash"></i> حذف
                </button>
            ` : ''}
        </div>
    `;
    
    return card;
}

// Modal Functions
function openAddClientModal() {
    const modal = document.getElementById('addClientModal');
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function openExcelImportModal() {
    const modal = document.getElementById('excelImportModal');
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function openAddAdminModal() {
    const modal = document.getElementById('addAdminModal');
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function openApplicationStatusModal(clientId) {
    document.getElementById('statusClientId').value = clientId;
    const modal = document.getElementById('applicationStatusModal');
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.classList.remove('show');
    document.body.style.overflow = 'auto';
}

function closeAllModals() {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.classList.remove('show');
    });
    document.body.style.overflow = 'auto';
}

// View Functions
async function viewClient(clientId) {
    // Treat clientId as studentId for access
    if (!canAccessStudent(clientId)) { showNotification('لا تملك صلاحية لعرض هذا الطالب', 'error'); return; }
    try {
        const response = await fetch(`${API_BASE}?action=client_details&id=${clientId}`);
        const result = await response.json();
        
        if (result.success) {
            const client = result.data;
            const content = document.getElementById('clientDetailsContent');
            
            // Generate custom fields HTML
            let customFieldsHtml = '';
            const standardFields = ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes', 'applicationStatus', 'paymentStatus', 'paymentAmount', 'createdAt'];
            
            Object.keys(client).forEach(key => {
                if (!standardFields.includes(key) && client[key]) {
                    customFieldsHtml += `
                        <div class="detail-row">
                            <span class="detail-label" data-original-name="${key}">${key}:</span>
                            <span class="detail-value">${client[key]}</span>
                        </div>
                    `;
                }
            });
            
            content.innerHTML = `
                <div class="client-details">
                    <div class="detail-row">
                        <span class="detail-label" data-original-name="fullName">الاسم الكامل:</span>
                        <span class="detail-value">${client.fullName}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label" data-original-name="email">البريد الإلكتروني:</span>
                        <span class="detail-value">${client.email}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label" data-original-name="phone">رقم الهاتف:</span>
                        <span class="detail-value">${client.phone}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label" data-original-name="scholarshipType">نوع المنحة:</span>
                        <span class="detail-value">${client.scholarshipType}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label" data-original-name="university">الجامعة:</span>
                        <span class="detail-value">${client.university}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label" data-original-name="specialization">التخصص:</span>
                        <span class="detail-value">${client.specialization}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">حالة الطلب:</span>
                        <span class="detail-value">
                            <span class="status-badge ${getApplicationStatusClass(client.applicationStatus)}">
                                ${client.applicationStatus}
                            </span>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">حالة الدفع:</span>
                        <span class="detail-value">
                            <span class="status-badge ${client.paymentStatus === 'مدفوع' ? 'accepted' : 'not-applied'}">
                                ${client.paymentStatus}
                            </span>
                        </span>
                    </div>
                    ${client.notes ? `
                        <div class="detail-row">
                            <span class="detail-label">ملاحظات:</span>
                            <span class="detail-value">${client.notes}</span>
                        </div>
                    ` : ''}
                    ${customFieldsHtml}
                    <div class="detail-row">
                        <span class="detail-label">تاريخ الإضافة:</span>
                        <span class="detail-value">${client.createdAt}</span>
                    </div>
                </div>
            `;
            
            const modal = document.getElementById('clientDetailsModal');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    } catch (error) {
        console.error('Error viewing client:', error);
        showNotification('خطأ في عرض تفاصيل العميل', 'error');
    }
}

async function viewPayment(paymentId) {
    try {
        const response = await fetch(`${API_BASE}?action=payment_details&id=${paymentId}`);
        const result = await response.json();
        
        if (result.success) {
            const payment = result.data;
            const content = document.getElementById('paymentDetailsContent');
            
            content.innerHTML = `
                <div class="payment-details">
                    <div class="payment-amount">${payment.amount} ج.م</div>
                    <div class="payment-status ${payment.status === 'مدفوع' ? 'paid' : 'pending'}">
                        <strong>${payment.status}</strong>
                    </div>
                    ${payment.screenshot ? `
                    <div class="detail-row">
                        <span class="detail-label">صورة الدفع:</span>
                        <span class="detail-value">
                            <a href="${payment.screenshot}" target="_blank">فتح في تبويب</a>
                            <div class="image-preview" style="margin-top:8px"><img src="${payment.screenshot}" alt="صورة الدفع" style="max-width:220px;border-radius:8px;border:1px solid #e2e8f0"/></div>
                        </span>
                    </div>` : ''}
                    <div class="detail-row">
                        <span class="detail-label">اسم العميل:</span>
                        <span class="detail-value">${payment.clientName}</span>
                    </div>
                    ${payment.fromNumber ? `
                        <div class="detail-row">
                            <span class="detail-label">رقم المرسل:</span>
                            <span class="detail-value">${payment.fromNumber}</span>
                        </div>
                    ` : ''}
                    ${payment.toNumber ? `
                        <div class="detail-row">
                            <span class="detail-label">رقم المستلم:</span>
                            <span class="detail-value">${payment.toNumber}</span>
                        </div>
                    ` : ''}
                    ${payment.date ? `
                        <div class="detail-row">
                            <span class="detail-label">تاريخ الدفع:</span>
                            <span class="detail-value">${payment.date}</span>
                        </div>
                    ` : ''}
                    ${payment.transactionId ? `
                        <div class="detail-row">
                            <span class="detail-label">رقم المعاملة:</span>
                            <span class="detail-value">${payment.transactionId}</span>
                        </div>
                    ` : ''}
                </div>
            `;
            
            const modal = document.getElementById('paymentDetailsModal');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    } catch (error) {
        console.error('Error viewing payment:', error);
        showNotification('خطأ في عرض تفاصيل الدفع', 'error');
    }
}

function viewAdmin(adminId) {
    const admin = admins.find(a => a.id == adminId);
    if (admin) {
        showNotification(`عرض تفاصيل المشرف: ${admin.fullName}`, 'info');
    }
}

// Edit Functions
async function editClient(clientId) {
    if (!canAccessStudent(clientId)) { showNotification('لا تملك صلاحية لتعديل هذا الطالب', 'error'); return; }
    try {
        const response = await fetch(`${API_BASE}?action=client_details&id=${clientId}`);
        const result = await response.json();
        
        if (result.success) {
            const client = result.data;
            
            // Populate the edit form
            document.getElementById('editClientId').value = client.id;
            document.getElementById('editFullName').value = client.fullName;
            document.getElementById('editEmail').value = client.email;
            document.getElementById('editPhone').value = client.phone;
            document.getElementById('editScholarshipType').value = client.scholarshipType;
            document.getElementById('editUniversity').value = client.university || '';
            document.getElementById('editSpecialization').value = client.specialization || '';
            document.getElementById('editNotes').value = client.notes || '';
            
            // Add custom fields to edit form
            const editForm = document.getElementById('editClientForm');
            const standardFields = ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes', 'applicationStatus', 'paymentStatus', 'paymentAmount', 'createdAt'];
            
            // Remove existing custom field inputs
            const existingCustomInputs = editForm.querySelectorAll('.custom-field-input');
            existingCustomInputs.forEach(input => input.remove());
            
            // Add custom fields BEFORE the action buttons to keep actions at the very end
            const formActions = editForm.querySelector('.form-actions');
            Object.keys(client).forEach(key => {
                if (!standardFields.includes(key) && client[key]) {
                    const customFieldHtml = `
                        <div class="form-group custom-field-input">
                            <label>${key}</label>
                            <input type="text" name="custom_${key}" value="${client[key] || ''}">
                        </div>
                    `;
                    if (formActions) {
                        formActions.insertAdjacentHTML('beforebegin', customFieldHtml);
                    } else {
                        editForm.insertAdjacentHTML('beforeend', customFieldHtml);
                    }
                }
            });
            
            // Show the modal
            const modal = document.getElementById('editClientModal');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    } catch (error) {
        console.error('Error loading client for edit:', error);
        showNotification('خطأ في تحميل بيانات العميل', 'error');
    }
}

async function editPayment(paymentId) {
    try {
        const response = await fetch(`${API_BASE}?action=payment_details&id=${paymentId}`);
        const result = await response.json();
        
        if (result.success) {
            const payment = result.data;
            
            // Populate the edit form
            document.getElementById('editPaymentId').value = payment.id;
            document.getElementById('editClientName').value = payment.clientName;
            document.getElementById('editPaymentStatus').value = payment.paymentStatus || payment.status || 'معلق';
            document.getElementById('editPaymentAmount').value = (payment.paymentAmount ?? payment.amount) || '';
            document.getElementById('editPaymentFrom').value = payment.paymentFrom || payment.fromNumber || '';
            document.getElementById('editPaymentTo').value = payment.paymentTo || payment.toNumber || '';
            // screenshot now handled via upload; no prefill field
            document.getElementById('editPaymentNotes').value = payment.paymentNotes || payment.notes || '';
            
            // Show the modal
            const modal = document.getElementById('editPaymentModal');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    } catch (error) {
        console.error('Error loading payment for edit:', error);
        showNotification('خطأ في تحميل بيانات الدفع', 'error');
    }
}

async function editAdmin(adminId) {
    try {
        const response = await fetch(`${API_BASE}?action=admin_details&id=${adminId}`);
        const result = await response.json();
        
        if (result.success) {
            const admin = result.data;
            
            // Populate the edit form
            document.getElementById('editAdminId').value = admin.id;
            document.getElementById('editAdminFullName').value = admin.fullName;
            document.getElementById('editAdminEmail').value = admin.email;
            document.getElementById('editAdminPassword').value = ''; // Don't show current password
            document.getElementById('editAdminRole').value = admin.role;
            
            // Show the modal
            const modal = document.getElementById('editAdminModal');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    } catch (error) {
        console.error('Error loading admin for edit:', error);
        showNotification('خطأ في تحميل بيانات المشرف', 'error');
    }
}

// Delete Functions
async function deleteClient(clientId) {
    if (!canAccessStudent(clientId)) { showNotification('لا تملك صلاحية لحذف هذا الطالب', 'error'); return; }
    if (confirm('هل أنت متأكد من حذف هذا الطالب؟')) {
        try {
            const response = await fetch(`${API_BASE}?action=delete_client&id=${clientId}`, {
                method: 'DELETE'
            });
            const result = await response.json();
            
            if (result.success) {
                await loadDataFromAPI();
                showNotification('تم حذف العميل بنجاح', 'success');
            } else {
                showNotification('خطأ في حذف العميل', 'error');
            }
        } catch (error) {
            console.error('Error deleting client:', error);
            showNotification('خطأ في حذف العميل', 'error');
        }
    }
}

// Excel Import Functions
function handleExcelImport(e) {
    e.preventDefault();
    
    const fileInput = e.target.querySelector('input[type="file"]');
    const file = fileInput.files[0];
    
    if (!file) {
        showNotification('يرجى اختيار ملف Excel', 'error');
        return;
    }
    
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const sheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[sheetName];
            const jsonData = XLSX.utils.sheet_to_json(worksheet);
            
            if (jsonData.length === 0) {
                showNotification('الملف فارغ أو لا يحتوي على بيانات صحيحة', 'error');
                return;
            }
            
            // Show column mapping modal
            showColumnMappingModal(jsonData);
            
        } catch (error) {
            console.error('Error reading Excel file:', error);
            showNotification('خطأ في قراءة ملف Excel', 'error');
        }
    };
    
    reader.readAsArrayBuffer(file);
}

function showColumnMappingModal(data) {
    // Get all unique column names from the data
    const allColumns = new Set();
    data.forEach(row => {
        Object.keys(row).forEach(key => allColumns.add(key));
    });
    
    const columns = Array.from(allColumns);
    window.excelColumns = columns; // Store globally for custom fields
    
    // Create mapping modal content
    const modalContent = `
        <div class="modal-header">
            <h2>تخطيط أعمدة البيانات</h2>
            <button class="close-btn" onclick="closeModal('columnMappingModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="column-mapping-content">
            <p>يرجى تحديد الأعمدة المطلوبة من ملف Excel:</p>
            <div class="column-mapping-form">
                <div class="form-group">
                    <label>الاسم الكامل *</label>
                    <select id="fullNameColumn" required>
                        <option value="">اختر العمود</option>
                        ${columns.map(col => `<option value="${col}">${col}</option>`).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label>البريد الإلكتروني *</label>
                    <select id="emailColumn" required>
                        <option value="">اختر العمود</option>
                        ${columns.map(col => `<option value="${col}">${col}</option>`).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label>رقم الهاتف *</label>
                    <select id="phoneColumn" required>
                        <option value="">اختر العمود</option>
                        ${columns.map(col => `<option value="${col}">${col}</option>`).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label>نوع المنحة</label>
                    <select id="scholarshipTypeColumn">
                        <option value="">اختر العمود</option>
                        ${columns.map(col => `<option value="${col}">${col}</option>`).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label>الجامعة</label>
                    <select id="universityColumn">
                        <option value="">اختر العمود</option>
                        ${columns.map(col => `<option value="${col}">${col}</option>`).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label>التخصص</label>
                    <select id="specializationColumn">
                        <option value="">اختر العمود</option>
                        ${columns.map(col => `<option value="${col}">${col}</option>`).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label>ملاحظات</label>
                    <select id="notesColumn">
                        <option value="">اختر العمود</option>
                        ${columns.map(col => `<option value="${col}">${col}</option>`).join('')}
                    </select>
                </div>
                
                <!-- Custom Fields Section -->
                <div class="custom-fields-section">
                    <h3>الحقول المخصصة</h3>
                    <p>يمكنك إضافة حقول مخصصة من ملف Excel:</p>
                    <div id="customFieldsContainer">
                        <!-- Custom fields will be added here -->
                    </div>
                    <button type="button" onclick="addCustomField()" class="add-custom-field-btn">
                        <i class="fas fa-plus"></i> إضافة حقل مخصص
                    </button>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" onclick="closeModal('columnMappingModal')" class="cancel-btn">إلغاء</button>
                <button type="button" onclick="processColumnMapping(${JSON.stringify(data).replace(/"/g, '&quot;')})" class="submit-btn">استيراد البيانات</button>
            </div>
        </div>
    `;
    
    // Create or update modal
    let modal = document.getElementById('columnMappingModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'columnMappingModal';
        modal.className = 'modal';
        document.body.appendChild(modal);
    }
    
    modal.innerHTML = `
        <div class="modal-content">
            ${modalContent}
        </div>
    `;
    
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
    
    // Initialize custom fields
    window.customFieldsCount = 0;
    addCustomField();
}

// Custom Field Functions
function addCustomField() {
    const container = document.getElementById('customFieldsContainer');
    const fieldId = `customField_${window.customFieldsCount}`;
    
    const fieldHtml = `
        <div class="custom-field-row" id="${fieldId}">
            <div class="form-group">
                <label>اسم الحقل المخصص</label>
                <input type="text" id="${fieldId}_name" placeholder="مثال: صورة شهادة الميلاد" required>
            </div>
            <div class="form-group">
                <label>العمود من Excel</label>
                <select id="${fieldId}_column" required>
                    <option value="">اختر العمود</option>
                    ${Array.from(new Set(window.excelColumns || [])).map(col => 
                        `<option value="${col}">${col}</option>`
                    ).join('')}
                </select>
            </div>
            <button type="button" onclick="removeCustomField('${fieldId}')" class="remove-custom-field-btn">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', fieldHtml);
    window.customFieldsCount++;
}

function removeCustomField(fieldId) {
    const field = document.getElementById(fieldId);
    if (field) {
        field.remove();
    }
}

function processColumnMapping(data) {
    const mapping = {
        fullName: document.getElementById('fullNameColumn').value,
        email: document.getElementById('emailColumn').value,
        phone: document.getElementById('phoneColumn').value,
        scholarshipType: document.getElementById('scholarshipTypeColumn').value,
        university: document.getElementById('universityColumn').value,
        specialization: document.getElementById('specializationColumn').value,
        notes: document.getElementById('notesColumn').value
    };
    
    // Get custom fields mapping
    const customFields = {};
    const customFieldRows = document.querySelectorAll('.custom-field-row');
    customFieldRows.forEach(row => {
        const fieldName = row.querySelector('input[id$="_name"]').value.trim();
        const columnName = row.querySelector('select[id$="_column"]').value;
        if (fieldName && columnName) {
            customFields[fieldName] = columnName;
        }
    });
    
    // Validate required fields
    if (!mapping.fullName || !mapping.email || !mapping.phone) {
        showNotification('يرجى تحديد الأعمدة المطلوبة (الاسم، البريد الإلكتروني، رقم الهاتف)', 'error');
        return;
    }
    
    // Process data with mapping
    const processedData = data.map(row => {
        const baseData = {
            fullName: row[mapping.fullName] || '',
            email: row[mapping.email] || '',
            phone: row[mapping.phone] || '',
            scholarshipType: mapping.scholarshipType ? (row[mapping.scholarshipType] || 'بكالوريوس') : 'بكالوريوس',
            university: mapping.university ? (row[mapping.university] || '') : '',
            specialization: mapping.specialization ? (row[mapping.specialization] || '') : '',
            notes: mapping.notes ? (row[mapping.notes] || '') : ''
        };
        
        // Add custom fields
        Object.keys(customFields).forEach(fieldName => {
            baseData[fieldName] = row[customFields[fieldName]] || '';
        });
        
        return baseData;
    }).filter(row => row.fullName && row.email && row.phone);
    
    if (processedData.length === 0) {
        showNotification('لا توجد بيانات صحيحة في الملف', 'error');
        return;
    }
    
    closeModal('columnMappingModal');
    importExcelData(processedData);
}

async function importExcelData(data) {
    try {
        const response = await fetch(`${API_BASE}?action=import_excel`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ data })
        });
        
        const result = await response.json();
        
        if (result.success) {
            await loadDataFromAPI();
            closeModal('excelImportModal');
            showNotification(result.message, 'success');
        } else {
            showNotification(result.message || 'خطأ في استيراد البيانات', 'error');
        }
    } catch (error) {
        console.error('Error importing data:', error);
        showNotification('خطأ في استيراد البيانات', 'error');
    }
}

// Form Handlers
async function handleAddClient(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const newClient = {
        fullName: formData.get('fullName'),
        email: formData.get('email'),
        phone: formData.get('phone'),
        scholarshipType: formData.get('scholarshipType'),
        university: formData.get('university'),
        specialization: formData.get('specialization'),
        notes: formData.get('notes'),
        applicationStatus: formData.get('applicationStatus')
    };
    
    try {
        const response = await fetch(`${API_BASE}?action=add_client`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(newClient)
        });
        
        const result = await response.json();
        
        if (result.success) {
            await loadDataFromAPI();
            closeModal('addClientModal');
            e.target.reset();
            showNotification('تم إضافة العميل بنجاح', 'success');
        } else {
            showNotification(result.message || 'خطأ في إضافة العميل', 'error');
        }
    } catch (error) {
        console.error('Error adding client:', error);
        showNotification('خطأ في إضافة العميل', 'error');
    }
}

async function handleAddAdmin(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const newAdmin = {
        fullName: formData.get('fullName'),
        email: formData.get('email'),
        password: formData.get('password'),
        role: formData.get('role')
    };
    
    try {
        const response = await fetch(`${API_BASE}?action=add_admin`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(newAdmin)
        });
        
        const result = await response.json();
        
        if (result.success) {
            await loadDataFromAPI();
            closeModal('addAdminModal');
            e.target.reset();
            showNotification('تم إضافة المشرف بنجاح', 'success');
        } else {
            showNotification('خطأ في إضافة المشرف', 'error');
        }
    } catch (error) {
        console.error('Error adding admin:', error);
        showNotification('خطأ في إضافة المشرف', 'error');
    }
}

async function handleApplicationStatusUpdate(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const clientId = formData.get('clientId');
    const newStatus = formData.get('applicationStatus');
    
    try {
        const response = await fetch(`${API_BASE}?action=update_application_status`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: clientId,
                applicationStatus: newStatus
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            await loadDataFromAPI();
            closeModal('applicationStatusModal');
            showNotification('تم تحديث حالة الطلب بنجاح', 'success');
        } else {
            showNotification('خطأ في تحديث حالة الطلب', 'error');
        }
    } catch (error) {
        console.error('Error updating application status:', error);
        showNotification('خطأ في تحديث حالة الطلب', 'error');
    }
}

// Edit Form Handlers
async function handleEditClient(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const clientId = formData.get('clientId');
    
    const clientData = {
        id: clientId,
        fullName: formData.get('fullName'),
        email: formData.get('email'),
        phone: formData.get('phone'),
        scholarshipType: formData.get('scholarshipType'),
        university: formData.get('university'),
        specialization: formData.get('specialization'),
        notes: formData.get('notes')
    };
    
    // Add custom fields
    for (let [key, value] of formData.entries()) {
        if (key.startsWith('custom_')) {
            const fieldName = key.replace('custom_', '');
            clientData[fieldName] = value;
        }
    }
    
    try {
        const response = await fetch(`${API_BASE}?action=update_client`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(clientData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            await loadDataFromAPI();
            closeModal('editClientModal');
            showNotification('تم تحديث بيانات العميل بنجاح', 'success');
        } else {
            showNotification('خطأ في تحديث بيانات العميل', 'error');
        }
    } catch (error) {
        console.error('Error updating client:', error);
        showNotification('خطأ في تحديث بيانات العميل', 'error');
    }
}

async function handleEditPayment(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const paymentId = formData.get('paymentId');
    
    const formEl = document.getElementById('editPaymentForm');
    const paymentData = {
        id: paymentId,
        paymentStatus: formData.get('paymentStatus'),
        paymentAmount: parseFloat(formData.get('paymentAmount')) || 0,
        paymentFrom: formData.get('paymentFrom'),
        paymentTo: formData.get('paymentTo'),
        paymentScreenshot: formEl?.dataset?.screenshotPath || '',
        paymentNotes: formData.get('paymentNotes')
    };
    
    try {
        const response = await fetch(`${API_BASE}?action=update_payment`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(paymentData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            await loadDataFromAPI();
            closeModal('editPaymentModal');
            showNotification('تم تحديث بيانات الدفع بنجاح', 'success');
        } else {
            showNotification('خطأ في تحديث بيانات الدفع', 'error');
        }
    } catch (error) {
        console.error('Error updating payment:', error);
        showNotification('خطأ في تحديث بيانات الدفع', 'error');
    }
}

async function handleEditAdmin(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const adminId = formData.get('adminId');
    
    const adminData = {
        id: adminId,
        fullName: formData.get('fullName'),
        email: formData.get('email'),
        password: formData.get('password'), // Will be empty if not changed
        role: formData.get('role')
    };
    
    try {
        const response = await fetch(`${API_BASE}?action=update_admin`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(adminData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            await loadDataFromAPI();
            closeModal('editAdminModal');
            showNotification('تم تحديث بيانات المشرف بنجاح', 'success');
        } else {
            showNotification('خطأ في تحديث بيانات المشرف', 'error');
        }
    } catch (error) {
        console.error('Error updating admin:', error);
        showNotification('خطأ في تحديث بيانات المشرف', 'error');
    }
}

// Search Functions
function handleClientSearch(e) {
    const searchTerm = e.target.value.toLowerCase();
    const clientCards = document.querySelectorAll('.client-card');
    
    clientCards.forEach(card => {
        const clientName = card.querySelector('h3').textContent.toLowerCase();
        const clientEmail = card.querySelector('.card-content p:first-child').textContent.toLowerCase();
        
        if (clientName.includes(searchTerm) || clientEmail.includes(searchTerm)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

function handlePaymentSearch(e) {
    const searchTerm = e.target.value.toLowerCase();
    const paymentCards = document.querySelectorAll('.payment-card');
    
    paymentCards.forEach(card => {
        const clientName = card.querySelector('h3').textContent.toLowerCase();
        
        if (clientName.includes(searchTerm)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

// Event Listeners
function setupEventListeners() {
    // Form submissions
    document.getElementById('addClientForm').addEventListener('submit', handleAddClient);
    document.getElementById('addAdminForm').addEventListener('submit', handleAddAdmin);
    document.getElementById('applicationStatusForm').addEventListener('submit', handleApplicationStatusUpdate);
    document.getElementById('excelImportForm').addEventListener('submit', handleExcelImport);
    
    // Edit form submissions
    document.getElementById('editClientForm').addEventListener('submit', handleEditClient);
    document.getElementById('editPaymentForm').addEventListener('submit', handleEditPayment);
    const paymentScreenshotInput = document.getElementById('editPaymentScreenshotFile');
    if (paymentScreenshotInput) {
        paymentScreenshotInput.addEventListener('change', async (e) => {
            const file = e.target.files && e.target.files[0];
            if (!file) return;
            try {
                const form = document.getElementById('editPaymentForm');
                const paymentId = form.querySelector('#editPaymentId').value;
                const formData = new FormData();
                formData.append('action', 'upload_payment_screenshot');
                formData.append('paymentId', paymentId);
                formData.append('paymentScreenshot', file);
                const res = await fetch(`${API_BASE}`, {
                    method: 'POST',
                    body: formData
                });
                const result = await res.json();
                if (result.success) {
                    form.dataset.screenshotPath = result.filePath;
                    showNotification('تم رفع صورة الدفع بنجاح', 'success');
                } else {
                    showNotification('فشل رفع صورة الدفع: ' + (result.message || ''), 'error');
                }
            } catch (err) {
                showNotification('خطأ أثناء رفع الصورة', 'error');
            }
        });
    }
    document.getElementById('editAdminForm').addEventListener('submit', handleEditAdmin);
    
    // Search inputs
    document.getElementById('clientSearch').addEventListener('input', handleClientSearch);
    document.getElementById('paymentSearch').addEventListener('input', handlePaymentSearch);
    
    // Modal close buttons
    document.querySelectorAll('.close-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = this.closest('.modal');
            if (modal) {
                modal.classList.remove('show');
                document.body.style.overflow = 'auto';
            }
        });
    });
    
    // Close modals when clicking outside
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('show');
                document.body.style.overflow = 'auto';
            }
        });
    });
}

// Notification System
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <i class="fas ${getNotificationIcon(type)}"></i>
            <span>${message}</span>
        </div>
    `;
    
    // Add to page
    document.body.appendChild(notification);
    
    // Show notification
    setTimeout(() => {
        notification.classList.add('show');
    }, 100);
    
    // Remove after 3 seconds
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => {
            document.body.removeChild(notification);
        }, 300);
    }, 3000);
}

// Show warning notification
function showWarningNotification(message) {
    showNotification(message, 'warning');
}

function getNotificationIcon(type) {
    switch (type) {
        case 'success': return 'fa-check-circle';
        case 'error': return 'fa-exclamation-circle';
        case 'warning': return 'fa-exclamation-triangle';
        default: return 'fa-info-circle';
    }
}

// Toggle modal size
function toggleModalSize(modalId) {
    const modal = document.getElementById(modalId);
    const modalContent = modal.querySelector('.modal-content');
    modalContent.classList.toggle('expanded');
    
    const expandBtn = modal.querySelector('.expand-btn i');
    if (modalContent.classList.contains('expanded')) {
        expandBtn.className = 'fas fa-compress';
    } else {
        expandBtn.className = 'fas fa-expand';
    }
}

// Toggle column editing mode
let isColumnEditMode = false;
function toggleColumnEdit() {
    isColumnEditMode = !isColumnEditMode;
    const detailRows = document.querySelectorAll('#clientDetailsContent .detail-row');
    const editBtn = document.querySelector('.edit-columns-btn');
    
    detailRows.forEach(row => {
        const label = row.querySelector('.detail-label');
        if (isColumnEditMode) {
            label.classList.add('editable');
            label.contentEditable = true;
            // Set the original name when entering edit mode
            if (!label.dataset.originalName) {
                label.dataset.originalName = label.textContent.trim();
            }
            label.addEventListener('blur', saveColumnName);
            label.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.blur();
                }
            });
        } else {
            label.classList.remove('editable', 'editing');
            label.contentEditable = false;
            label.removeEventListener('blur', saveColumnName);
        }
    });
    
    if (isColumnEditMode) {
        editBtn.innerHTML = '<i class="fas fa-save"></i> حفظ الأعمدة';
        editBtn.style.background = '#28a745';
        editBtn.style.color = 'white';
    } else {
        editBtn.innerHTML = '<i class="fas fa-edit"></i> تعديل الأعمدة';
        editBtn.style.background = '#f8f9fa';
        editBtn.style.color = 'inherit';
    }
}

// Save column name changes
async function saveColumnName(event) {
    const label = event.target;
    const oldName = label.dataset.originalName || label.textContent.trim();
    const newName = label.textContent.trim();
    
    console.log('🔧 Column name update:', { oldName, newName, hasOriginalName: !!label.dataset.originalName });
    
    if (oldName !== newName && newName.length > 0) {
        try {
            console.log('📤 Sending update request...');
            const response = await fetch('/backend/api.php?action=update_column_name', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    oldName: oldName,
                    newName: newName
                })
            });
            
            const result = await response.json();
            console.log('📥 Response:', result);
            
            if (result.success) {
                label.dataset.originalName = newName;
                showNotification(`تم تحديث اسم العمود من "${oldName}" إلى "${newName}"`, 'success');
                
                // Update column name mapping for real-time sync
                updateColumnNameMapping(oldName, newName);
                
                // Reload data to reflect changes
                loadDataFromAPI();
            } else {
                label.textContent = oldName; // Revert on error
                showNotification('فشل في تحديث اسم العمود: ' + result.message, 'error');
            }
        } catch (error) {
            console.error('❌ Error updating column name:', error);
            label.textContent = oldName; // Revert on error
            showNotification('خطأ في تحديث اسم العمود', 'error');
        }
    } else {
        console.log('⚠️ No changes detected or invalid input');
    }
    
    label.classList.remove('editing');
}

// Update column name mapping for real-time sync
function updateColumnNameMapping(oldName, newName) {
    console.log('🔄 Updating column name mapping:', oldName, '→', newName);
    
    // Store mapping in localStorage for persistence
    const columnMappings = JSON.parse(localStorage.getItem('columnMappings') || '{}');
    columnMappings[oldName] = newName;
    localStorage.setItem('columnMappings', JSON.stringify(columnMappings));
    
    // Update all download sections across the application
    updateDownloadSectionColumnNames(oldName, newName);
}

// Update download section column names in real-time
function updateDownloadSectionColumnNames(oldName, newName) {
    console.log('🔄 Updating download sections with new column name:', oldName, '→', newName);
    
    // Update drive links in student details modal
    const driveLinksContainer = document.getElementById('driveLinks');
    if (driveLinksContainer) {
        const columnNames = driveLinksContainer.querySelectorAll('.column-name');
        columnNames.forEach(columnName => {
            if (columnName.textContent === oldName) {
                columnName.textContent = newName;
                console.log('✅ Updated column name in drive links:', oldName, '→', newName);
            }
        });
    }
    
    // Update any other places where column names are displayed
    const allColumnNames = document.querySelectorAll('[data-column-name]');
    allColumnNames.forEach(element => {
        if (element.textContent === oldName) {
            element.textContent = newName;
            console.log('✅ Updated column name in element:', oldName, '→', newName);
        }
    });
}

// Sync with Google Sheets
async function syncGoogleSheets() {
    try {
        showNotification('جاري المزامنة مع Google Sheets...', 'info');
        
        const response = await fetch('/backend/api.php?action=sync_google_sheet', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                spreadsheet_id: '19BmPnYe0EiOUHDsrQlDRSuCREHm2A5JZ4eHe3GQEa9s',
                range: 'A:ZZZ'
            })
        });
        
        const result = await response.json();
        if (result.success) {
            showNotification(result.message, 'success');
            loadDataFromAPI(); // Refresh the page data
            
            // Also sync clients to students
            await syncClientsToStudents();
        } else {
            showNotification('فشل في المزامنة: ' + result.message, 'error');
        }
    } catch (error) {
        showNotification('خطأ في المزامنة: ' + error.message, 'error');
    }
}

// Get current column mapping
async function getColumnMapping() {
    try {
        console.log('🔧 Fetching column mapping...');
        const response = await fetch('/backend/api.php?action=get_column_mapping');
        console.log('🔧 Response status:', response.status);
        
        const result = await response.json();
        console.log('🔧 Column mapping result:', result);
        
        if (result.success) {
            return result.data;
        } else {
            console.error('Failed to get column mapping:', result.message);
            return {};
        }
    } catch (error) {
        console.error('Error getting column mapping:', error);
        return {};
    }
}

// Display column mapping information
async function showColumnMapping() {
    console.log('🔧 Showing column mapping...');
    const mapping = await getColumnMapping();
    console.log('🔧 Mapping received:', mapping);
    
    if (Object.keys(mapping).length === 0) {
        showNotification('لا يوجد تخطيط أعمدة حالياً', 'info');
        return;
    }
    
    const mappingInfo = Object.entries(mapping)
        .map(([original, current]) => {
            const status = original === current ? '✅' : '🔄';
            return `${status} ${original} → ${current}`;
        })
        .join('\n');
    
    console.log('🔧 Mapping info formatted:', mappingInfo);
    
    // Create a modal to show the mapping
    const modal = document.createElement('div');
    modal.className = 'modal show';
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: center;
    `;
    
    modal.innerHTML = `
        <div class="modal-content" style="max-width: 600px; max-height: 80vh; overflow-y: auto;">
            <div class="modal-header">
                <h2>🗺️ تخطيط الأعمدة</h2>
                <button class="close-btn" onclick="this.closest('.modal').remove()">✕</button>
            </div>
            <div class="modal-body">
                <p>هذا يوضح كيف يتم تخطيط أعمدة Google Sheets مع الأعمدة الحالية:</p>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; font-family: monospace; white-space: pre-line;">
                    ${mappingInfo}
                </div>
                <p style="margin-top: 15px; font-size: 14px; color: #666;">
                    <strong>✅</strong> = نفس الاسم<br>
                    <strong>🔄</strong> = تم تغيير الاسم
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="this.closest('.modal').remove()">✕ إغلاق</button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
}



// ===== STUDENT MANAGEMENT FUNCTIONS =====

let students = [];
let currentStudent = null;

// Load students from API
async function loadStudents() {
    try {
        const response = await fetch('/backend/api.php?action=students');
        const result = await response.json();
        if (result.success) {
            students = result.data;
            
            // Filter students based on user role and access
            filterStudentsByAccess();
            
            // Auto-distribute students if no distribution exists or new students found
            if (Object.keys(studentDistribution).length === 0) {
                console.log('🔄 No distribution found, auto-distributing students...');
                setTimeout(() => {
                    autoDistributeStudents();
                }, 1000); // Wait for distribution data to load
            } else {
                // Check for new students and distribute them automatically
                checkAndDistributeNewStudents();
            }
            
            renderStudents();
        } else {
            showNotification('فشل في تحميل الطلاب: ' + result.message, 'error');
        }
    } catch (error) {
        showNotification('خطأ في تحميل الطلاب: ' + error.message, 'error');
    }
}

// Check and distribute new students automatically
function checkAndDistributeNewStudents() {
    console.log('🔍 Checking for new students to distribute...');
    
    if (!studentDistribution || Object.keys(studentDistribution).length === 0) {
        console.log('❌ No distribution exists, will auto-distribute');
        return;
    }
    
    // Get all currently distributed student IDs
    const distributedStudentIds = new Set();
    Object.values(studentDistribution).forEach(students => {
        students.forEach(student => {
            distributedStudentIds.add(student.id);
        });
    });
    
    // Find new students that are not distributed
    const newStudents = clients.filter(student => !distributedStudentIds.has(student.id));
    
    if (newStudents.length > 0) {
        console.log(`🆕 Found ${newStudents.length} new students to distribute:`, newStudents.map(s => s.fullName));
        
        // Distribute new students to admins with fewer students
        distributeNewStudents(newStudents);
    } else {
        console.log('✅ All students are already distributed');
    }
}

// Distribute new students to admins with fewer students
function distributeNewStudents(newStudents) {
    if (newStudents.length === 0) return;
    
    // Get all admins (including managers)
    let allAdmins = admins;
    
    if (allAdmins.length === 0) {
        console.log('❌ No admins available for distribution');
        return;
    }
    
    // Sort admins by number of students (ascending)
    const sortedAdmins = allAdmins.sort((a, b) => {
        const aCount = studentDistribution[a.id] ? studentDistribution[a.id].length : 0;
        const bCount = studentDistribution[b.id] ? studentDistribution[b.id].length : 0;
        return aCount - bCount;
    });
    
    console.log('📊 Admins sorted by student count:', sortedAdmins.map(a => ({
        name: a.fullName,
        count: studentDistribution[a.id] ? studentDistribution[a.id].length : 0
    })));
    
    // Distribute new students to admins with fewer students
    newStudents.forEach((student, index) => {
        const adminIndex = index % sortedAdmins.length;
        const adminId = sortedAdmins[adminIndex].id;
        
        if (!studentDistribution[adminId]) {
            studentDistribution[adminId] = [];
        }
        
        studentDistribution[adminId].push(student);
        console.log(`📚 New student ${student.fullName} assigned to admin ${sortedAdmins[adminIndex].fullName}`);
    });
    
    // Save the updated distribution
    saveDistribution();
    
    // Add log entry
    addLogEntry('student_assignment', 'توزيع تلقائي للطلاب الجدد', 
        `تم توزيع ${newStudents.length} طالب جديد تلقائياً`);
    
    showNotification(`تم توزيع ${newStudents.length} طالب جديد تلقائياً`, 'success');
}

// Filter students based on user role and access
function filterStudentsByAccess() {
    console.log('🔒 Filtering students by access...');
    console.log('Current user role:', currentUser.role);
    console.log('Current user ID:', currentUser.id);
    
    // All users can see all students, but with different access levels
    console.log('✅ All students visible for all users');
    
    // Load distribution data if not already loaded
    if (Object.keys(studentDistribution).length === 0) {
        console.log('📥 Loading distribution data for access control...');
        loadDistributionData();
    }
}

// Render students grid
function renderStudents() {
    const grid = document.getElementById('studentsGrid');
    if (!grid) return;
    
    grid.innerHTML = '';
    
    // Check if current user can access student (assigned or accepted helper)
    const isStudentAssigned = (studentId) => {
        return canAccessStudent(studentId);
    };
    
    students.forEach(student => {
        const isAssigned = isStudentAssigned(student.id);
        const card = document.createElement('div');
        card.className = `student-card ${isAssigned ? 'assigned' : 'locked'}`;
        
        // Add lock overlay for non-assigned students
        const lockOverlay = !isAssigned ? `
            <div class="student-lock-overlay">
                <i class="fas fa-lock"></i>
                <span>غير مسؤول</span>
            </div>
        ` : '';
        
        card.innerHTML = `
            ${lockOverlay}
            <div class="student-header">
                <h3>${student.fullName}</h3>
                <span class="status ${student.applicationStatus.replace(/\s+/g, '-')}">${student.applicationStatus}</span>
            </div>
            <div class="student-info">
                <p><strong>البريد الإلكتروني:</strong> ${student.email}</p>
                <p><strong>رقم الهاتف:</strong> ${student.phone}</p>
                <p><strong>نوع المنحة:</strong> ${student.scholarshipType}</p>
            </div>
            <div class="student-tasks">
                <div class="task ${student.tasks?.sop?.status || 'pending'}">
                    <i class="fas fa-file-alt"></i>
                    <span>بيان الغرض</span>
                </div>
                <div class="task ${student.tasks?.lor?.status || 'pending'}">
                    <i class="fas fa-envelope"></i>
                    <span>خطاب التوصية</span>
                </div>
                <div class="task ${student.tasks?.documents?.status || 'pending'}">
                    <i class="fas fa-folder"></i>
                    <span>الملفات</span>
                </div>
            </div>
            <div class="student-actions">
                <button class="btn-primary" onclick="openStudentDetails(${student.id})">
                    <i class="fas fa-eye"></i>
                    عرض التفاصيل
                </button>
                ${isAssigned ? `
                    ${currentUser.role === 'manager' ? `
                        <button class=\"btn-secondary\" onclick=\"editStudent(${student.id})\">\n                            <i class=\"fas fa-edit\"></i>\n                            تعديل\n                        </button>\n                        <button class=\"btn-danger\" onclick=\"deleteStudent(${student.id})\">\n                            <i class=\"fas fa-trash\"></i>\n                            حذف\n                        </button>
                    ` : `
                        <button class=\"btn-secondary\" onclick=\"editStudent(${student.id})\">\n                            <i class=\"fas fa-edit\"></i>\n                            تعديل\n                        </button>
                    `}
                ` : ``}
            </div>
        `;
        
        // Add drag functionality for owner only
        if (currentUser && currentUser.username === 'stroogar@gmail.com') {
            card.draggable = true;
            card.dataset.studentId = student.id;
            card.addEventListener('dragstart', handleDragStart);
            card.addEventListener('dragend', handleDragEnd);
        }
        
        grid.appendChild(card);
    });
}

// Global function to check if current user can access a student
function canAccessStudent(studentId) {
	console.log('🔍 Checking access for student:', studentId);
	console.log('🔍 Current user:', currentUser);
	console.log('🔍 Student distribution:', studentDistribution);
	console.log('🔍 Available distribution keys:', Object.keys(studentDistribution || {}));
	
	// Owner always has access
	if (currentUser && currentUser.username === 'stroogar@gmail.com') {
		console.log('✅ Owner access granted');
		return true;
	}
	
	const currentAdmin = admins.find(admin => admin.email === currentUser.username);
	if (currentAdmin && currentAdmin.isMainAdmin === true) {
		console.log('✅ Main admin access granted');
		return true;
	}

	// Accepted helper request grants access
	try {
		if (Array.isArray(helpRequests)) {
			const hasAccess = helpRequests.some(r => (
				String(r.studentId) === String(studentId) &&
				r.requesterId === currentUser.id &&
				r.status === 'accepted'
			));
			if (hasAccess) {
				console.log('✅ Helper access granted');
				return true;
			}
		}
	} catch(e) {}
	
	// Find admin by email to get the correct ID for distribution lookup
	const admin = admins.find(a => a.email === currentUser.username);
	if (!admin) {
		console.log('❌ Admin not found for user:', currentUser.username);
		console.log('🔍 Available admins:', admins);
		return false;
	}
	
	console.log('🔍 Admin found:', admin);
	console.log('🔍 Admin ID for distribution lookup:', admin.id);
	console.log('🔍 Admin ID type:', typeof admin.id);
	console.log('🔍 Student ID type:', typeof studentId);
	
	// Convert admin ID to string for distribution lookup (distribution uses string keys)
	const adminIdString = String(admin.id);
	console.log('🔍 Admin ID as string for distribution lookup:', adminIdString);
	
	if (!studentDistribution || !studentDistribution[adminIdString]) {
		console.log('❌ No distribution or no students for admin ID string:', adminIdString);
		console.log('🔍 Available distribution keys:', Object.keys(studentDistribution || {}));
		console.log('🔍 Distribution keys types:', Object.keys(studentDistribution || {}).map(k => typeof k));
		return false;
	}
	
	const adminStudents = studentDistribution[adminIdString];
	console.log('🔍 Students for this admin:', adminStudents);
	console.log('🔍 Looking for student ID:', studentId);
	
	const hasAccess = adminStudents.some(s => {
		const match = String(s.id) === String(studentId);
		console.log(`🔍 Checking student ${s.id} (${s.fullName}) against ${studentId}: ${match}`);
		return match;
	});
	
	console.log('🔍 Distribution check result:', hasAccess);
	return hasAccess;
}

// Global function to apply access control to any student element
function applyAccessControlToStudent(element, studentId) {
    if (!element) return;
    
    const canAccess = canAccessStudent(studentId);
    
    if (!canAccess) {
        // Add lock overlay
        if (!element.querySelector('.student-lock-overlay')) {
            const lockOverlay = document.createElement('div');
            lockOverlay.className = 'student-lock-overlay';
            lockOverlay.innerHTML = `
                <i class="fas fa-lock"></i>
                <span>غير مسؤول</span>
            `;
            element.appendChild(lockOverlay);
        }
        
        // Disable edit/delete buttons
        const editButtons = element.querySelectorAll('.btn-secondary, .btn-danger');
        editButtons.forEach(btn => {
            btn.disabled = true;
            btn.style.opacity = '0.5';
            btn.style.cursor = 'not-allowed';
        });
        
        // Change action buttons to "كن مساعداً"
        const actionButtons = element.querySelectorAll('.student-actions button:not(.btn-primary)');
        actionButtons.forEach(btn => {
            if (!btn.classList.contains('btn-primary')) {
                btn.className = 'btn-info';
                btn.innerHTML = '<i class="fas fa-handshake"></i> كن مساعداً';
                btn.onclick = () => requestHelp(studentId);
            }
        });
    }
}

// Global function to apply access control to all student elements on the page
function applyGlobalAccessControl() {
    console.log('🌐 Applying global access control...');
    
    // Apply to all student cards on the page
    const allStudentCards = document.querySelectorAll('.student-card, .client-card, [data-student-id]');
    allStudentCards.forEach(card => {
        const studentId = card.dataset.studentId || card.dataset.clientId;
        if (studentId) {
            applyAccessControlToStudent(card, studentId);
        }
    });
    
    // Apply to student tables
    const studentRows = document.querySelectorAll('tr[data-student-id], tr[data-client-id]');
    studentRows.forEach(row => {
        const studentId = row.dataset.studentId || row.dataset.clientId;
        if (studentId) {
            applyAccessControlToStudent(row, studentId);
        }
    });
    
    // Apply to student modals
    const studentModals = document.querySelectorAll('.modal[data-student-id]');
    studentModals.forEach(modal => {
        const studentId = modal.dataset.studentId;
        if (studentId) {
            applyAccessControlToStudent(modal, studentId);
        }
    });
}

// Call global access control after page loads and after any dynamic content changes
document.addEventListener('DOMContentLoaded', () => {
    // Initial application
    setTimeout(applyGlobalAccessControl, 2000);
    
    // Apply after any AJAX calls or dynamic content changes
    const observer = new MutationObserver(() => {
        setTimeout(applyGlobalAccessControl, 100);
    });
    
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
});

// Drag and drop functionality for student distribution
let draggedStudent = null;

function handleDragStart(e) {
    draggedStudent = e.target.dataset.studentId;
    e.target.style.opacity = '0.5';
    console.log('🔄 Drag started for student:', draggedStudent);
}

function handleDragEnd(e) {
    e.target.style.opacity = '1';
    draggedStudent = null;
    console.log('✅ Drag ended');
}

// Add drop zones to admin cards in distribution modal
function addDropZonesToAdmins() {
    const adminCards = document.querySelectorAll('.admin-card');
    adminCards.forEach(card => {
        card.addEventListener('dragover', handleDragOver);
        card.addEventListener('drop', handleDrop);
    });
}

function handleDragOver(e) {
    e.preventDefault();
    e.currentTarget.style.backgroundColor = '#f0f9ff';
    e.currentTarget.style.borderColor = '#3b82f6';
}

function handleDrop(e) {
    e.preventDefault();
    const adminCard = e.currentTarget;
    const adminId = adminCard.dataset.adminId;
    
    if (draggedStudent && adminId) {
        console.log(`📚 Moving student ${draggedStudent} to admin ${adminId}`);
        moveStudentToAdmin(draggedStudent, adminId);
    }
    
    // Reset styling
    adminCard.style.backgroundColor = '';
    adminCard.style.borderColor = '';
}

function moveStudentToAdmin(studentId, targetAdminId) {
    // Remove student from current admin
    let currentAdminId = null;
    for (const [adminId, students] of Object.entries(studentDistribution)) {
        if (students.some(s => s.id == studentId)) {
            currentAdminId = adminId;
            break;
        }
    }
    
    if (currentAdminId) {
        studentDistribution[currentAdminId] = studentDistribution[currentAdminId].filter(s => s.id != studentId);
        console.log(`📤 Removed student from admin ${currentAdminId}`);
    }
    
    // Add student to target admin
    if (!studentDistribution[targetAdminId]) {
        studentDistribution[targetAdminId] = [];
    }
    
    const student = clients.find(s => s.id == studentId);
    if (student) {
        studentDistribution[targetAdminId].push(student);
        console.log(`📥 Added student to admin ${targetAdminId}`);
        
        // Refresh the distribution display
        renderDistributionList();
        
        // Add log entry
        addLogEntry('student_assignment', 'نقل طالب', 
            `تم نقل الطالب ${student.fullName} إلى مشرف آخر`);
        
        showNotification('تم نقل الطالب بنجاح', 'success');
    }
}

// Open add student modal
function openAddStudentModal() {
    document.getElementById('addStudentModal').classList.add('show');
}

// Open student details modal
async function openStudentDetails(studentId) {
    // Refresh helper access state before checking
    await loadHelpRequestsFromAPI();
    if (!canAccessStudent(studentId)) { showNotification('لا تملك صلاحية لعرض هذا الطالب', 'error'); return; }
    const student = students.find(s => s.id == studentId);
    if (!student) return;
    
    currentStudent = student;
    
    // Populate modal with student data
    document.getElementById('studentName').textContent = student.fullName;
    document.getElementById('studentEmail').textContent = student.email;
    document.getElementById('studentPhone').textContent = student.phone;
    document.getElementById('studentScholarshipType').textContent = student.scholarshipType;
    document.getElementById('studentApplicationStatus').textContent = student.applicationStatus;
    
    // Set SOP and LOR display
    document.getElementById('sopDisplay').textContent = student.sop || '';
    document.getElementById('lorDisplay').textContent = student.lor || '';
    
    // Render custom fields
    renderCustomFields(student);
    
    // Render drive links
    renderDriveLinks(student.driveLinks || []);
    
    // Load files from filesystem for this student
    await loadStudentFilesFromFilesystem(studentId);
    
    // Start auto-refresh for files
    startFilesAutoRefresh();
    
    // Center the modal
    centerModal('studentDetailsModal');
}

// Render custom fields - simplified (show all)
function renderCustomFields(student) {
    const customFieldsContainer = document.getElementById('customFields');
    const customFieldsSection = document.getElementById('customFieldsSection');
    if (!customFieldsContainer || !customFieldsSection) return;
    
    // Get standard fields to exclude
    const standardFields = ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes', 'applicationStatus', 'paymentStatus', 'paymentAmount', 'sop', 'lor', 'files', 'driveLinks', 'tasks', 'clientId', 'createdAt'];
    
    // Find custom fields and preserve their original order
    const allKeys = Object.keys(student);
    const customFields = [];
    
    // First, add any custom fields that come after standard fields in the original order
    for (let i = 0; i < allKeys.length; i++) {
        const key = allKeys[i];
        if (!standardFields.includes(key)) {
            customFields.push(key);
        }
    }
    
    if (customFields.length === 0) {
        customFieldsSection.style.display = 'none';
        return;
    }
    
    customFieldsSection.style.display = 'block';
    customFieldsContainer.innerHTML = customFields.map(field => `
        <div class="info-item">
            <label><i class="fas fa-list"></i> ${field}:</label>
            <span class="info-value">${student[field] || 'غير محدد'}</span>
        </div>
    `).join('');
}

// Render drive links
function renderDriveLinks(driveLinks) {
    const container = document.getElementById('driveLinks');
    if (!container) return;
    
    container.innerHTML = '';
    
    driveLinks.forEach(link => {
        const linkElement = document.createElement('div');
        linkElement.className = 'drive-link';
        
        // Apply column name mapping if it exists
        const columnMappings = JSON.parse(localStorage.getItem('columnMappings') || '{}');
        const displayColumnName = columnMappings[link.column] || link.column;
        
        linkElement.innerHTML = `
            <div class="link-info">
                <span class="column-name" data-column-name="${link.column}">${displayColumnName}</span>
                <a href="${link.url}" target="_blank" class="link-url">${link.url}</a>
            </div>
            <button class="btn-primary" onclick="downloadFile('${link.url}', '${link.filename}')">
                <i class="fas fa-download"></i>
                تحميل
            </button>
        `;
        container.appendChild(linkElement);
    });
}

// Render files
function renderFiles(files) {
    console.log('Rendering files:', files);
    
    // Only update details modal files list since editFilesList was removed
    const detailsContainer = document.getElementById('filesList');
    
    if (detailsContainer) {
        detailsContainer.innerHTML = '';
        
        files.forEach(file => {
            const fileElement = document.createElement('div');
            fileElement.className = 'file-item';
            
            // Extract file information for better display
            const fileName = file.name || 'Unknown File';
            const fileSize = formatFileSize(file.size || 0);
            const fileType = file.type || 'Unknown';
            const uploadDate = file.uploadedAt ? new Date(file.uploadedAt).toLocaleDateString('ar-EG') : 'Unknown';
            const source = file.source || 'uploaded';
            
            fileElement.innerHTML = `
                <div class="file-info">
                    <div class="file-header">
                        <span class="file-name" title="${fileName}">${fileName}</span>
                        <span class="file-source ${source}">${source === 'google_drive' ? '📁 Google Drive' : '📤 Uploaded'}</span>
                    </div>
                    <div class="file-details">
                        <small class="file-size">📏 ${fileSize}</small>
                        <small class="file-type">📄 ${fileType}</small>
                        <small class="file-date">📅 ${uploadDate}</small>
                    </div>
                </div>
                <div class="file-actions">
                    <button class="btn btn-primary" onclick="console.log('🔍 VIEW BUTTON CLICKED:', {path: '${file.path}', name: '${file.name}', type: '${file.type}', source: '${source}'}); viewFile('${file.path}', '${file.name}', '${file.type}', '${source}')" title="عرض الملف">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-danger" onclick="deleteFile('${file.id}')" title="حذف الملف">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            detailsContainer.appendChild(fileElement);
        });
    }
}

// Format file size
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}



// Show image modal
function showImageModal(imagePath, imageName) {
    console.log('🖼️ Opening image modal:', { imagePath, imageName });
    
    const modal = document.createElement('div');
    modal.className = 'modal show';
    modal.id = 'imageModal';
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.8);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
    `;
    
    modal.innerHTML = `
        <div class="modal-content" style="
            background: white;
            border-radius: 8px;
            max-width: 90vw;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        ">
            <div class="modal-header" style="
                padding: 15px 20px;
                border-bottom: 1px solid #eee;
                display: flex;
                justify-content: space-between;
                align-items: center;
            ">
                <h3 style="margin: 0; color: #333;">${imageName}</h3>
                <button class="close-btn" onclick="closeImageModal()" style="
                    background: none;
                    border: none;
                    font-size: 24px;
                    cursor: pointer;
                    color: #666;
                    padding: 0;
                    width: 30px;
                    height: 30px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                ">×</button>
            </div>
            <div class="modal-body" style="
                padding: 20px;
                text-align: center;
                max-height: 70vh;
                overflow: auto;
            ">
                <img src="${imagePath}" alt="${imageName}" style="
                    max-width: 100%;
                    max-height: 100%;
                    object-fit: contain;
                    border-radius: 4px;
                " onerror="console.error('❌ Failed to load image:', this.src)">
            </img>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // Add click outside to close
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeImageModal();
        }
    });
    
    console.log('✅ Image modal created and added to DOM');
}

// Make functions globally accessible for testing
window.showImageModal = showImageModal;
window.closeImageModal = closeImageModal;
window.loadStudentDistributionFromAPI = loadStudentDistributionFromAPI;
window.applyGlobalAccessControl = applyGlobalAccessControl;
window.canAccessStudent = canAccessStudent;
window.viewFile = viewFile;

// Close image modal
function closeImageModal() {
    const modal = document.getElementById('imageModal');
    if (modal) {
        modal.remove();
    }
}

// Load student files from filesystem
async function loadStudentFilesFromFilesystem(studentId) {
    try {
        console.log('🔍 Loading files for student:', studentId);
        
        const response = await fetch(`backend/api.php?action=scan_student_files&studentId=${studentId}`);
        const result = await response.json();
        
        if (result.success) {
            console.log('✅ Files loaded from filesystem:', result.files);
            
            // Update currentStudent with files from filesystem
            if (currentStudent) {
                currentStudent.files = result.files;
                renderFiles(result.files);
            }
        } else {
            console.log('❌ Failed to load files:', result.message);
            // Set empty files array
            if (currentStudent) {
                currentStudent.files = [];
                renderFiles([]);
            }
        }
    } catch (error) {
        console.error('❌ Error loading files from filesystem:', error);
        // Set empty files array on error
        if (currentStudent) {
            currentStudent.files = [];
            renderFiles([]);
        }
    }
}

// Auto-refresh files display for open student details
let filesRefreshInterval = null;

// Load column name mappings on page load
function loadColumnNameMappings() {
    const columnMappings = JSON.parse(localStorage.getItem('columnMappings') || '{}');
    console.log('📋 Loaded column name mappings:', columnMappings);
    return columnMappings;
}

// Initialize column mappings when page loads
document.addEventListener('DOMContentLoaded', function() {
    loadColumnNameMappings();
});

function startFilesAutoRefresh() {
    // Clear any existing interval
    if (filesRefreshInterval) {
        clearInterval(filesRefreshInterval);
    }
    
    // Start auto-refresh every 2 seconds if details modal is open
    filesRefreshInterval = setInterval(async () => {
        const detailsModal = document.getElementById('studentDetailsModal');
        if (detailsModal && detailsModal.classList.contains('show') && currentStudent) {
            console.log('🔄 Auto-refreshing files for student:', currentStudent.id);
            
            // Refresh student data from server
            await refreshCurrentStudent();
            
            // Update files display from filesystem
            if (currentStudent) {
                await loadStudentFilesFromFilesystem(currentStudent.id);
                console.log('✅ Files auto-refreshed from filesystem');
            }
        } else {
            // Stop auto-refresh if modal is closed
            if (filesRefreshInterval) {
                clearInterval(filesRefreshInterval);
                filesRefreshInterval = null;
                console.log('⏹️ Stopped files auto-refresh (modal closed)');
            }
        }
    }, 2000); // Check every 2 seconds
    
    console.log('🚀 Started files auto-refresh');
}

function stopFilesAutoRefresh() {
    if (filesRefreshInterval) {
        clearInterval(filesRefreshInterval);
        filesRefreshInterval = null;
        console.log('⏹️ Stopped files auto-refresh');
    }
}

// Force refresh files display immediately
async function forceRefreshFiles() {
    if (!currentStudent) return;
    
    console.log('🚀 Force refreshing files display...');
    
    // Refresh student data from server
    await refreshCurrentStudent();
    
    // Update files display immediately from filesystem
    if (currentStudent) {
        await loadStudentFilesFromFilesystem(currentStudent.id);
        console.log('✅ Files force refreshed from filesystem');
    }
}

// Manual refresh files button (add this to the UI)
function manualRefreshFiles() {
    if (currentStudent) {
        loadStudentFilesFromFilesystem(currentStudent.id);
        showNotification('جاري تحديث الملفات...', 'info');
    }
}

// Edit current student from details modal
function editCurrentStudent() {
    if (currentStudent) {
        closeStudentModal();
        editStudent(currentStudent.id);
    }
}

// Download file from Google Drive
async function downloadFile(url, filename) {
    try {
        showNotification('جاري التحميل...', 'info');
        
        const response = await fetch('/api/download.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                url: url,
                filename: filename,
                studentId: currentStudent.id
            })
        });
        
        const result = await response.json();
        if (result.success) {
            showNotification('تم التحميل بنجاح', 'success');
            
            // Refresh the current student data from server
            await refreshCurrentStudent();
            
            // Force refresh the files display immediately from filesystem
            if (currentStudent) {
                console.log('🔧 Refreshing files display after download...');
                
                // If details modal is open, refresh the files display from filesystem
                const detailsModal = document.getElementById('studentDetailsModal');
                if (detailsModal && detailsModal.classList.contains('show')) {
                    console.log('🔧 Details modal is open, refreshing files from filesystem...');
                    await loadStudentFilesFromFilesystem(currentStudent.id);
                    
                    // Also refresh the custom fields to ensure everything is up to date
                    await renderCustomFields(currentStudent);
                }
                
                console.log('✅ Files updated after download from filesystem');
            }
        } else {
            showNotification('فشل في التحميل: ' + result.message, 'error');
        }
    } catch (error) {
        showNotification('خطأ في التحميل: ' + error.message, 'error');
    }
}

// Save SOP
async function saveSOP() {
    if (!currentStudent) return;
    
    const sopText = document.getElementById('editSOPText').value;
    
    try {
        const response = await fetch('/backend/api.php?action=update_student_sop', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                id: currentStudent.id,
                sop: sopText
            })
        });
        
        const result = await response.json();
        if (result.success) {
            showNotification('تم حفظ بيان الغرض بنجاح', 'success');
            currentStudent.sop = sopText;
            currentStudent.tasks.sop.status = 'completed';
            closeEditSOPModal();
            openStudentDetails(currentStudent.id); // Refresh the view
            renderStudents();
        } else {
            showNotification('فشل في حفظ بيان الغرض: ' + result.message, 'error');
        }
    } catch (error) {
        showNotification('خطأ في حفظ بيان الغرض: ' + error.message, 'error');
    }
}

// Save LOR
async function saveLOR() {
    if (!currentStudent) return;
    
    const lorText = document.getElementById('editLORText').value;
    
    try {
        const response = await fetch('/backend/api.php?action=update_student_lor', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                id: currentStudent.id,
                lor: lorText
            })
        });
        
        const result = await response.json();
        if (result.success) {
            showNotification('تم حفظ خطاب التوصية بنجاح', 'success');
            currentStudent.lor = lorText;
            currentStudent.tasks.lor.status = 'completed';
            closeEditLORModal();
            openStudentDetails(currentStudent.id); // Refresh the view
            renderStudents();
        } else {
            showNotification('فشل في حفظ خطاب التوصية: ' + result.message, 'error');
        }
    } catch (error) {
        showNotification('خطأ في حفظ خطاب التوصية: ' + error.message, 'error');
    }
}

// Download all files
async function downloadAllFiles() {
    if (!currentStudent || !currentStudent.driveLinks) return;
    
    showNotification('جاري تحميل جميع الملفات...', 'info');
    
    for (const link of currentStudent.driveLinks) {
        await downloadFile(link.url, link.filename);
        // Small delay between downloads
        await new Promise(resolve => setTimeout(resolve, 1000));
    }
    
    showNotification('تم تحميل جميع الملفات', 'success');
}

// Skip student
function skipStudent() {
    if (!currentStudent) return;
    
    // Move to next student
    const currentIndex = students.findIndex(s => s.id == currentStudent.id);
    const nextStudent = students[currentIndex + 1] || students[0];
    
    if (nextStudent) {
        closeModal('studentDetailsModal');
        setTimeout(() => {
            openStudentDetails(nextStudent.id);
        }, 300);
    }
}

// Show import sections modal
async function showImportSectionsModal() {
    try {
        // Get available sections
        const sectionsResponse = await fetch('/backend/api.php?action=get_available_sections', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        });
        
        const sectionsResult = await sectionsResponse.json();
        
        if (sectionsResult.success) {
            renderSectionsList(sectionsResult.sections, sectionsResult.existing_sections, sectionsResult.section_samples);
            document.getElementById('importSectionsModal').classList.add('show');
            centerModal('importSectionsModal');
        } else {
            showNotification('فشل في جلب البيانات: ' + sectionsResult.message, 'error');
        }
    } catch (error) {
        showNotification('خطأ في جلب البيانات: ' + error.message, 'error');
    }
}

// Import sections from clients.json
async function importFromClients() {
    try {
        const response = await fetch('/backend/api.php?action=import_from_clients', {
            method: 'POST'
        });
        
        const result = await response.json();
        if (result.success) {
            showNotification(result.message, 'success');
            // Refresh the modal with new sections
            showImportSectionsModal();
        } else {
            showNotification('فشل في استيراد الأقسام من العملاء: ' + result.message, 'error');
        }
    } catch (error) {
        showNotification('خطأ في استيراد الأقسام من العملاء: ' + error.message, 'error');
    }
}

// Remove section
async function removeSection(sectionName) {
    if (!confirm(`هل أنت متأكد من حذف القسم "${sectionName}" من جميع الطلاب؟`)) {
        return;
    }
    
    try {
        const response = await fetch('/backend/api.php?action=remove_section', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                sectionName: sectionName
            })
        });
        
        const result = await response.json();
        if (result.success) {
            showNotification(result.message, 'success');
            
            // Refresh the modal
            showImportSectionsModal();
            
            // Refresh students list to show changes
            loadStudents();
        } else {
            showNotification('فشل في حذف القسم: ' + result.message, 'error');
        }
    } catch (error) {
        showNotification('خطأ في حذف القسم: ' + result.message, 'error');
    }
}

// Refresh current student data from server
async function refreshCurrentStudent() {
    if (!currentStudent) return;
    
    try {
        console.log('🔄 Refreshing current student data...');
        const response = await fetch(`/backend/api.php?action=get_student&id=${currentStudent.id}`);
        const result = await response.json();
        
        if (result.success) {
            // Update current student with fresh data
            currentStudent = result.student;
            
            // Update global students array
            const studentIndex = students.findIndex(s => s.id === currentStudent.id);
            if (studentIndex !== -1) {
                students[studentIndex] = currentStudent;
            }
            
            console.log('✅ Current student refreshed:', currentStudent);
            
            // Immediately refresh UI if details modal is open
            const detailsModal = document.getElementById('studentDetailsModal');
            if (detailsModal && detailsModal.classList.contains('show')) {
                console.log('🔄 Refreshing UI after student data update...');
                renderFiles(currentStudent.files || []);
                await renderCustomFields(currentStudent);
            }
        }
    } catch (error) {
        console.error('❌ Error refreshing current student:', error);
    }
}

// Render sections list - simplified (delete only)
function renderSectionsList(allSections, existingSections, sectionSamples = {}) {
    const sectionsList = document.getElementById('sectionsList');
    if (!sectionsList) return;
    
    sectionsList.innerHTML = allSections.map(section => {
        const isExisting = existingSections.includes(section);
        const description = isExisting ? ' (موجود مسبقاً)' : ' (جديد)';
        const sampleValue = sectionSamples[section] ? ` - مثال: ${sectionSamples[section]}` : '';
        
        return `
            <div class="section-item">
                <div class="section-content">
                    <label>${section}</label>
                    <div class="section-description">${description}${sampleValue}</div>
                </div>
                <button class="remove-section-btn" onclick="removeSection('${section}')" title="حذف القسم">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
    }).join('');
}

// Import selected sections
async function importSelectedSections() {
    const selectedSections = Array.from(document.querySelectorAll('#sectionsList input[type="checkbox"]:checked'))
        .map(checkbox => checkbox.value);
    
    if (selectedSections.length === 0) {
        showWarningNotification('يرجى اختيار قسم واحد على الأقل');
        return;
    }
    
    try {
        showNotification('جاري استيراد الأقسام المحددة...', 'info');
        
        const response = await fetch('/backend/api.php?action=import_selected_sections', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                sections: selectedSections
            })
        });
        
        const result = await response.json();
        if (result.success) {
            showNotification(`تم استيراد ${result.imported_count} قسم بنجاح`, 'success');
            closeImportSectionsModal();
            
            // Refresh the student form to show new fields
            if (currentStudent) {
                openStudentDetails(currentStudent.id);
            }
            
            // Refresh students list
            loadStudents();
        } else {
            showNotification('فشل في استيراد الأقسام: ' + result.message, 'error');
        }
    } catch (error) {
        showNotification('خطأ في استيراد الأقسام: ' + error.message, 'error');
    }
}

// Close import sections modal
function closeImportSectionsModal() {
    document.getElementById('importSectionsModal').classList.remove('show');
}



// Sync all columns from clients
async function syncAllColumns() {
    try {
        showNotification('جاري المزامنة...', 'info');
        
        const response = await fetch('/backend/api.php?action=sync_all_columns', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        });
        
        const result = await response.json();
        if (result.success) {
            showNotification(result.message, 'success');
            // Refresh the modal
            showImportSectionsModal();
        } else {
            showNotification('فشل في المزامنة: ' + result.message, 'error');
        }
    } catch (error) {
        showNotification('خطأ في المزامنة: ' + error.message, 'error');
    }
}

// Add event listeners when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, setting up file upload listener');
    setupFileUploadListener();
});

// Setup file upload listener
function setupFileUploadListener() {
    const fileUpload = document.getElementById('fileUpload');
    if (fileUpload) {
        // Remove existing listeners first
        fileUpload.removeEventListener('change', handleFileUpload);
        fileUpload.addEventListener('change', handleFileUpload);
        console.log('File upload event listener added successfully');
    } else {
        console.log('File upload element not found, will retry when modal opens');
    }
}

// Setup file upload listener when edit modal opens
function setupEditModalFileUpload() {
    setTimeout(() => {
        const fileUpload = document.getElementById('fileUpload');
        if (fileUpload) {
            fileUpload.removeEventListener('change', handleFileUpload);
            fileUpload.addEventListener('change', handleFileUpload);
            console.log('File upload listener added to edit modal');
        }
    }, 100);
}

// Import new sections from clients.json (legacy function - kept for compatibility)
async function importNewSections() {
    try {
        showNotification('جاري استيراد الأقسام الجديدة...', 'info');
        
        const response = await fetch('/backend/api.php?action=import_new_sections', {
            method: 'POST'
        });
        
        const result = await response.json();
        if (result.success) {
            showNotification(`تم استيراد ${result.imported_count} قسم جديد`, 'success');
            // Refresh the student form to show new fields
            if (currentStudent) {
                openStudentDetails(currentStudent.id);
            }
        } else {
            showNotification('فشل في استيراد الأقسام: ' + result.message, 'error');
        }
    } catch (error) {
        showNotification('خطأ في استيراد الأقسام: ' + error.message, 'error');
    }
}

// Sync clients to students
async function syncClientsToStudents() {
    try {
        showNotification('جاري المزامنة مع العملاء...', 'info');
        
        const response = await fetch('/backend/api.php?action=sync_clients_to_students', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        });
        
        const result = await response.json();
        if (result.success) {
            showNotification(result.message, 'success');
            loadStudents(); // Refresh students list
        } else {
            showNotification('فشل في المزامنة: ' + result.message, 'error');
        }
    } catch (error) {
        showNotification('خطأ في المزامنة: ' + error.message, 'error');
    }
}

// Add student form submission
document.addEventListener('DOMContentLoaded', function() {
    const addStudentForm = document.getElementById('addStudentForm');
    if (addStudentForm) {
        addStudentForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const studentData = {};
            formData.forEach((value, key) => {
                studentData[key] = value;
            });
            
            try {
                const response = await fetch('/backend/api.php?action=add_student', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(studentData)
                });
                
                const result = await response.json();
                if (result.success) {
                    showNotification('تم إضافة الطالب بنجاح', 'success');
                    document.getElementById('addStudentModal').classList.remove('show');
                    this.reset();
                    loadStudents();
                } else {
                    showNotification('فشل في إضافة الطالب: ' + result.message, 'error');
                }
            } catch (error) {
                showNotification('خطأ في إضافة الطالب: ' + error.message, 'error');
            }
        });
    }
    
    // Edit student form submission
    const editStudentForm = document.getElementById('editStudentForm');
    if (editStudentForm) {
        editStudentForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const studentData = {};
            formData.forEach((value, key) => {
                studentData[key] = value;
            });
            
            try {
                const response = await fetch('/backend/api.php?action=update_student', {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(studentData)
                });
                
                const result = await response.json();
                if (result.success) {
                    // Add log entry
                    addLogEntry('status_change', 'تحديث بيانات الطالب', `تم تحديث بيانات الطالب: ${currentStudent.fullName}`);
                    
                    showNotification('تم تحديث الطالب بنجاح', 'success');
                    closeEditStudentModal();
                    loadStudents();
                    
                    // Also update the corresponding client if it exists
                    await syncStudentToClient(studentData);
                } else {
                    showNotification('فشل في تحديث الطالب: ' + result.message, 'error');
                }
            } catch (error) {
                showNotification('خطأ في تحديث الطالب: ' + error.message, 'error');
            }
        });
    }
});

// Sync student changes to client
async function syncStudentToClient(studentData) {
    try {
        const response = await fetch('/backend/api.php?action=update_client', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                id: studentData.clientId,
                fullName: studentData.fullName,
                email: studentData.email,
                phone: studentData.phone,
                scholarshipType: studentData.scholarshipType,
                university: studentData.university,
                specialization: studentData.specialization,
                notes: studentData.notes
            })
        });
        
        if (response.ok) {
            console.log('Client updated successfully');
        }
    } catch (error) {
        console.error('Error syncing to client:', error);
    }
}

// Load students when students page is shown
document.addEventListener('DOMContentLoaded', function() {
    const studentsNav = document.querySelector('[data-page="students"]');
    if (studentsNav) {
        studentsNav.addEventListener('click', function() {
            setTimeout(() => {
                loadStudents();
            }, 100);
        });
    }
    
    // File upload functionality
    const fileUpload = document.getElementById('fileUpload');
    if (fileUpload) {
        fileUpload.addEventListener('change', handleFileUpload);
    }
});

// Fix modal centering for student details
function centerModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('show');
    }
}

// Handle file upload
async function handleFileUpload(event) {
    if (!currentStudent || !canAccessStudent(currentStudent.id)) { showNotification('لا تملك صلاحية للرفع لهذا الطالب', 'error'); return; }
    console.log('File upload triggered', event.target.files);
    
    if (!currentStudent) {
        showNotification('يرجى اختيار طالب أولاً', 'error');
        return;
    }
    
    const files = event.target.files;
    if (files.length === 0) return;
    
    showNotification(`جاري رفع ${files.length} ملف...`, 'info');
    
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        await uploadFile(file);
    }
    
    // Clear the input
    event.target.value = '';
    showNotification('تم رفع الملفات بنجاح', 'success');
    
    // Refresh the current student data from server
    await refreshCurrentStudent();
    
    // Update the UI immediately without refresh
    if (currentStudent) {
        // Update files list in details modal if it's open
        const detailsModal = document.getElementById('studentDetailsModal');
        if (detailsModal && detailsModal.classList.contains('show')) {
            renderFiles(currentStudent.files || []);
            openStudentDetails(currentStudent.id);
        }
        
        // Also update the global students array
        const studentIndex = students.findIndex(s => s.id === currentStudent.id);
        if (studentIndex !== -1) {
            students[studentIndex] = currentStudent;
        }
        
        console.log('Files updated after upload:', currentStudent.files);
    }
}

// Upload individual file
async function uploadFile(file) {
    if (!currentStudent || !canAccessStudent(currentStudent.id)) { showNotification('لا تملك صلاحية للرفع لهذا الطالب', 'error'); return; }
    if (!currentStudent) {
        console.error('No current student selected');
        return;
    }
    
    const formData = new FormData();
    formData.append('file', file);
    formData.append('studentId', currentStudent.id);
    
    try {
        console.log('Starting upload for file:', file.name);
        console.log('File size:', file.size);
        console.log('File type:', file.type);
        console.log('Student ID:', currentStudent.id);
        
        const response = await fetch('/backend/api.php?action=upload_student_file', {
            method: 'POST',
            body: formData
        });
        
        console.log('Upload response status:', response.status);
        const result = await response.json();
        console.log('Upload result:', result);
        
        if (result.success) {
            console.log('Upload successful, adding file to student');
            
            // Add file to student's files array
            if (!currentStudent.files) currentStudent.files = [];
            const newFile = {
                id: result.fileId,
                name: file.name,
                path: result.filePath,
                size: file.size,
                type: file.type,
                uploadedAt: new Date().toISOString()
            };
            
            // Refresh files from filesystem after upload
            await loadStudentFilesFromFilesystem(currentStudent.id);
            
            // Add log entry
            addLogEntry('file_upload', 'رفع ملف', 
                `تم رفع ملف ${file.name} للطالب ${currentStudent.fullName}`);
            
            showNotification(`تم رفع الملف ${file.name} بنجاح`, 'success');
        } else {
            console.error('Upload failed:', result.message);
            showNotification('فشل في رفع الملف: ' + result.message, 'error');
        }
    } catch (error) {
        console.error('Error uploading file:', error);
        showNotification('خطأ في رفع الملف: ' + error.message, 'error');
    }
}

// Populate custom fields in edit form
function populateCustomFields(student) {
    const customFieldsContainer = document.getElementById('editCustomFields');
    if (!customFieldsContainer) return;
    
    // Get standard fields to exclude
    const standardFields = ['id', 'fullName', 'email', 'phone', 'scholarshipType', 'university', 'specialization', 'notes', 'applicationStatus', 'paymentStatus', 'paymentAmount', 'sop', 'lor', 'files', 'driveLinks', 'tasks', 'clientId', 'createdAt'];
    
    // Find custom fields and preserve their original order
    // We'll use a more reliable method to maintain column order
    const allKeys = Object.keys(student);
    const customFields = [];
    
    // First, add any custom fields that come after standard fields in the original order
    for (let i = 0; i < allKeys.length; i++) {
        const key = allKeys[i];
        if (!standardFields.includes(key)) {
            customFields.push(key);
        }
    }
    
    if (customFields.length === 0) {
        customFieldsContainer.innerHTML = '';
        return;
    }
    
    customFieldsContainer.innerHTML = customFields.map(field => `
        <div class="form-group">
            <label>${field}</label>
            <input type="text" name="${field}" id="edit${field.charAt(0).toUpperCase() + field.slice(1)}" value="${student[field] || ''}">
        </div>
    `).join('');
}

// Edit student function
function editStudent(studentId) {
    if (!canAccessStudent(studentId)) { showNotification('لا تملك صلاحية لتعديل هذا الطالب', 'error'); return; }
    const student = students.find(s => s.id == studentId);
    if (!student) return;
    
    // Set current student globally
    currentStudent = student;
    
    // Populate edit form
    document.getElementById('editStudentId').value = student.id;
    document.getElementById('editStudentFullName').value = student.fullName;
    document.getElementById('editStudentEmail').value = student.email;
    document.getElementById('editStudentPhone').value = student.phone;
    document.getElementById('editStudentScholarshipType').value = student.scholarshipType;
    document.getElementById('editStudentUniversity').value = student.university || '';
    document.getElementById('editStudentSpecialization').value = student.specialization || '';
    document.getElementById('editStudentApplicationStatus').value = student.applicationStatus;
    document.getElementById('editStudentNotes').value = student.notes || '';
    
    // Populate custom fields
    populateCustomFields(student);
    
    // No need to show files in edit modal since editFilesList was removed
    
    document.getElementById('editStudentModal').classList.add('show');
    
    // Setup file upload listener
    setupEditModalFileUpload();
}

// Edit SOP function
function editSOP() {
    if (!currentStudent) return;
    
    document.getElementById('editSOPText').value = currentStudent.sop || '';
    document.getElementById('editSOPModal').classList.add('show');
}

// Edit LOR function
function editLOR() {
    if (!currentStudent) return;
    
    document.getElementById('editLORText').value = currentStudent.lor || '';
    document.getElementById('editLORModal').classList.add('show');
}

// Close student modal
function closeStudentModal() {
    document.getElementById('studentDetailsModal').classList.remove('show');
    currentStudent = null;
    
    // Stop auto-refresh when modal is closed
    stopFilesAutoRefresh();
}

// Close edit student modal
function closeEditStudentModal() {
    document.getElementById('editStudentModal').classList.remove('show');
}

// Close edit SOP modal
function closeEditSOPModal() {
    document.getElementById('editSOPModal').classList.remove('show');
}

// Close edit LOR modal
function closeEditLORModal() {
    document.getElementById('editLORModal').classList.remove('show');
}

// Delete student function
async function deleteStudent(studentId) {
    if (!canAccessStudent(studentId)) { showNotification('لا تملك صلاحية لحذف هذا الطالب', 'error'); return; }
    if (!confirm('هل أنت متأكد من حذف هذا الطالب؟')) return;
    
    try {
        // First delete the student
        const response = await fetch(`/backend/api.php?action=delete_student&id=${studentId}`, {
            method: 'DELETE'
        });
        
        const result = await response.json();
        if (result.success) {
            // Also delete the corresponding client if it exists
            const student = students.find(s => s.id == studentId);
            if (student && student.clientId) {
                try {
                    await fetch(`/backend/api.php?action=delete_client&id=${student.clientId}`, {
                        method: 'DELETE'
                    });
                } catch (clientError) {
                    console.error('Error deleting client:', clientError);
                }
            }
            
            showNotification('تم حذف الطالب والعملاء المرتبطين بنجاح', 'success');
            loadStudents(); // Refresh the list
        } else {
            showNotification('فشل في حذف الطالب: ' + result.message, 'error');
        }
    } catch (error) {
        showNotification('خطأ في حذف الطالب: ' + error.message, 'error');
    }
}

// Get current student from modal
function getCurrentStudent() {
    // Try to get from currentStudent variable if it exists
    if (typeof currentStudent !== 'undefined' && currentStudent) {
        return currentStudent;
    }
    
    // Try to get from students array using the student ID from the modal
    const studentIdElement = document.querySelector('#studentDetailsModal .student-id');
    if (studentIdElement) {
        const studentId = studentIdElement.textContent.replace('رقم الطالب: ', '').trim();
        return students.find(s => s.id == studentId);
    }
    
    return null;
}

// Delete file function
async function deleteFile(fileId) {
    try {
        // Find the file object to get more details
        const currentStudent = getCurrentStudent();
        if (!currentStudent || !currentStudent.files) {
            showNotification('لم يتم العثور على بيانات الطالب أو الملفات', 'error');
            return;
        }
        
        const file = currentStudent.files.find(f => f.id == fileId);
        if (!file) {
            showNotification('لم يتم العثور على الملف', 'error');
            return;
        }
        
        // Confirm deletion
        if (!confirm(`هل أنت متأكد من حذف الملف "${file.name}"؟`)) {
            return;
        }
        
        // Get current user email for authorization
        const userEmail = localStorage.getItem('currentUser');
        if (!userEmail) {
            showNotification('يجب تسجيل الدخول أولاً', 'error');
            return;
        }
        
        // Call API to delete file (EXACT COPY from working test page)
        const response = await fetch('backend/api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'delete_file',
                studentId: currentStudent.id,
                fileName: file.name
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification(result.message, 'success');
            
            // Refresh files from filesystem after successful deletion
            await loadStudentFilesFromFilesystem(currentStudent.id);
            
        } else {
            showNotification(result.message, 'error');
        }
        
    } catch (error) {
        console.error('Error deleting file:', error);
        showNotification('خطأ في حذف الملف: ' + error.message, 'error');
    }
}

// ===== ADMIN MANAGEMENT SYSTEM =====

// Check user role and permissions
function checkUserRole() {
    const userEmail = localStorage.getItem('currentUser');
    const isMainAdmin = localStorage.getItem('isMainAdmin') === 'true';
    
    console.log('=== CHECKING USER ROLE ===');
    console.log('User email:', userEmail);
    console.log('Is main admin:', isMainAdmin);
    console.log('Available admins:', admins);
    
    // Find current user in admins array
    const currentAdmin = admins.find(admin => admin.email === userEmail);
    console.log('Current admin found:', currentAdmin);
    
    // Set current user role and info based on isMainAdmin
    if (currentAdmin) {
        currentUser.id = currentAdmin.id;
        currentUser.username = currentAdmin.email;
        currentUser.role = currentAdmin.isMainAdmin ? 'manager' : 'admin';
        currentUser.isMainAdmin = currentAdmin.isMainAdmin;
        console.log('Current user role set to:', currentUser.role);
        console.log('Current user isMainAdmin:', currentUser.isMainAdmin);
    } else {
        // Fallback to localStorage
        currentUser.role = isMainAdmin ? 'manager' : 'admin';
        currentUser.isMainAdmin = isMainAdmin;
        currentUser.username = userEmail;
        console.log('Fallback: Current user role set to:', currentUser.role);
        console.log('Fallback: Current user isMainAdmin:', currentUser.isMainAdmin);
    }
    
    console.log('Final current user object:', currentUser);
    
    // Show/hide admin page based on role
    const adminNavItem = document.querySelector('[data-page="admins"]');
    if (adminNavItem) {
        if (currentUser.role === 'manager') {
            adminNavItem.style.display = 'block';
            console.log('✅ Admin page SHOWN for manager');
        } else {
            adminNavItem.style.display = 'none';
            console.log('❌ Admin page HIDDEN for admin');
        }
    } else {
        console.log('❌ Admin nav item not found!');
    }
    
    // Show/hide admin buttons based on role
    const viewLogsBtn = document.getElementById('viewLogsBtn');
    const distributeStudentsBtn = document.getElementById('distributeStudentsBtn');
    
    if (viewLogsBtn) {
        viewLogsBtn.style.display = currentUser.role === 'manager' ? 'flex' : 'none';
        console.log('View logs button display:', viewLogsBtn.style.display);
    } else {
        console.log('❌ View logs button not found!');
    }
    
    if (distributeStudentsBtn) {
        distributeStudentsBtn.style.display = (currentUser && currentUser.username === 'stroogar@gmail.com') ? 'flex' : 'none';
        console.log('Distribute students button display:', distributeStudentsBtn.style.display);
    } else {
        console.log('❌ Distribute students button not found!');
    }
    
    // Show/hide column mapping button based on user
    const columnMappingBtn = document.getElementById('columnMappingBtn');
    if (columnMappingBtn) {
        const shouldShow = currentUser && currentUser.username === 'stroogar@gmail.com';
        columnMappingBtn.style.display = shouldShow ? 'flex' : 'none';
        console.log('Column mapping button display:', columnMappingBtn.style.display, 'for user:', currentUser.username, 'should show:', shouldShow);
        
        // Force hide if not owner (extra security)
        if (!shouldShow) {
            columnMappingBtn.style.visibility = 'hidden';
            columnMappingBtn.style.opacity = '0';
        } else {
            columnMappingBtn.style.visibility = 'visible';
            columnMappingBtn.style.opacity = '1';
        }
    } else {
        console.log('❌ Column mapping button not found!');
    }
    
    // Show/hide change Google Sheet ID button for owner only
    const changeSheetIdBtn = document.getElementById('changeSheetIdBtn');
    if (changeSheetIdBtn) {
        const shouldShow = currentUser && currentUser.username === 'stroogar@gmail.com';
        changeSheetIdBtn.style.display = shouldShow ? 'inline-block' : 'none';
        console.log('Change sheet ID button display:', changeSheetIdBtn.style.display, 'for user:', currentUser.username, 'should show:', shouldShow);
        
        // Force hide if not owner (extra security)
        if (!shouldShow) {
            changeSheetIdBtn.style.visibility = 'hidden';
            changeSheetIdBtn.style.opacity = '0';
        } else {
            changeSheetIdBtn.style.visibility = 'visible';
            changeSheetIdBtn.style.opacity = '1';
        }
    } else {
        console.log('❌ Change sheet ID button not found!');
    }
    
    // Load admin analytics if manager
    if (currentUser.role === 'manager') {
        console.log('🚀 Loading admin analytics for manager');
        loadAdminAnalytics();
    } else {
        console.log('⏸️ Skipping admin analytics for admin');
    }
    
    console.log('=== USER ROLE CHECK COMPLETED ===');
}

// Load admin analytics
async function loadAdminAnalytics() {
    try {
        console.log('🚀 Loading admin analytics...');
        console.log('Current user role:', currentUser.role);
        
        const response = await fetch('/backend/api.php?action=get_admin_analytics');
        const result = await response.json();
        
        console.log('📡 Admin analytics response:', result);
        
        if (result.success) {
            const totalAdminsEl = document.getElementById('totalAdmins');
            const distributedStudentsEl = document.getElementById('distributedStudents');
            const avgStudentsPerAdminEl = document.getElementById('avgStudentsPerAdmin');
            const helpRequestsEl = document.getElementById('helpRequests');
            
            console.log('🔍 Looking for analytics elements...');
            console.log('totalAdmins element:', totalAdminsEl);
            console.log('distributedStudents element:', distributedStudentsEl);
            console.log('avgStudentsPerAdmin element:', avgStudentsPerAdminEl);
            console.log('helpRequests element:', helpRequestsEl);
            
            if (totalAdminsEl) {
                totalAdminsEl.textContent = result.data.totalAdmins;
                console.log('✅ Updated totalAdmins:', result.data.totalAdmins);
            }
            if (distributedStudentsEl) {
                distributedStudentsEl.textContent = result.data.distributedStudents;
                console.log('✅ Updated distributedStudents:', result.data.distributedStudents);
            }
            if (avgStudentsPerAdminEl) {
                avgStudentsPerAdminEl.textContent = result.data.avgStudentsPerAdmin;
                console.log('✅ Updated avgStudentsPerAdmin:', result.data.avgStudentsPerAdmin);
            }
            if (helpRequestsEl) {
                helpRequestsEl.textContent = result.data.helpRequests;
                console.log('✅ Updated helpRequests:', result.data.helpRequests);
            }
            
            console.log('🎉 Admin analytics updated successfully');
        } else {
            console.error('❌ Failed to load admin analytics:', result.message);
        }
    } catch (error) {
        console.error('❌ Error loading admin analytics:', error);
    }
}

// Show student distribution modal
function showStudentDistribution() {
    if (currentUser.role !== 'manager') {
        showNotification('فقط المدير يمكنه الوصول لهذه الصفحة', 'error');
        return;
    }
    
    loadDistributionData();
    document.getElementById('studentDistributionModal').classList.add('show');
    centerModal('studentDistributionModal');
}

// Load distribution data
async function loadDistributionData() {
    try {
        console.log('Loading distribution data...');
        
        const response = await fetch('/backend/api.php?action=get_student_distribution');
        const result = await response.json();
        
        console.log('Distribution data response:', result);
        
        if (result.success) {
            studentDistribution = result.data.distribution;
            
            const totalStudentsCountEl = document.getElementById('totalStudentsCount');
            const totalAdminsCountEl = document.getElementById('totalAdminsCount');
            const avgStudentsDisplayEl = document.getElementById('avgStudentsDisplay');
            
            if (totalStudentsCountEl) totalStudentsCountEl.textContent = result.data.totalStudents;
            if (totalAdminsCountEl) totalAdminsCountEl.textContent = result.data.totalAdmins;
            if (avgStudentsDisplayEl) avgStudentsDisplayEl.textContent = result.data.avgStudents;
            
            console.log('Distribution data loaded:', studentDistribution);
            renderDistributionList();
        } else {
            console.error('Failed to load distribution data:', result.message);
        }
    } catch (error) {
        console.error('Error loading distribution data:', error);
    }
}

// Load student distribution from API (can be called independently)
async function loadStudentDistributionFromAPI() {
    try {
        console.log('🔄 Refreshing student distribution from API...');
        
        const response = await fetch('/backend/api.php?action=get_student_distribution');
        const result = await response.json();
        
        console.log('🔍 API Response:', result);
        
        if (result.success) {
            // Check if the response has the expected structure
            if (result.data && result.data.distribution) {
                studentDistribution = result.data.distribution;
                console.log('✅ Student distribution refreshed from data.distribution:', studentDistribution);
            } else if (result.distribution) {
                studentDistribution = result.distribution;
                console.log('✅ Student distribution refreshed from result.distribution:', studentDistribution);
            } else if (result.data) {
                // If result.data exists but doesn't have distribution, use result.data directly
                studentDistribution = result.data;
                console.log('✅ Student distribution refreshed from result.data:', studentDistribution);
            } else {
                console.error('❌ Unexpected response structure:', result);
                return;
            }
            
            // Force refresh access control for all students
            console.log('🔄 Applying global access control...');
            applyGlobalAccessControl();
            console.log('✅ Access control applied');
        } else {
            console.error('❌ Failed to refresh distribution:', result.message);
        }
    } catch (error) {
        console.error('❌ Error refreshing distribution:', error);
    }
}

// Render distribution list
function renderDistributionList() {
    const container = document.getElementById('distributionList');
    if (!container) return;
    
    console.log('Rendering distribution list:', studentDistribution);
    console.log('Available admins:', admins);
    
    if (Object.keys(studentDistribution).length === 0) {
        container.innerHTML = '<p style="text-align: center; color: #6b7280; padding: 20px;">لا يوجد توزيع حالياً. اضغط "توزيع تلقائي" لبدء التوزيع.</p>';
        return;
    }
    
    container.innerHTML = Object.entries(studentDistribution).map(([adminId, students]) => {
        const admin = admins.find(a => a.id == adminId);
        const adminName = admin ? admin.fullName : 'مشرف غير معروف';
        
        return `
            <div class="admin-card" data-admin-id="${adminId}">
                <div class="admin-info">
                    <h4>${adminName}</h4>
                    <p>${admin ? admin.email : ''}</p>
                    <span class="student-count">${students.length} طالب</span>
                </div>
                <div class="students-list">
                    ${students.map(student => `
                        <div class="student-item">
                            <span>${student.fullName}</span>
                            <button onclick="removeStudentFromAdmin('${student.id}', '${adminId}')" class="btn-remove">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }).join('');
    
    // Add drop zones for drag and drop functionality
    if (currentUser.role === 'manager') {
        addDropZonesToAdmins();
    }
}

// Auto distribute students
function autoDistributeStudents() {
	// Get students from clients (since students are stored in clients.json)
	const totalStudents = clients.length;
	
	console.log('🔄 Auto distribute students called');
	console.log('📚 Clients array:', clients);
	console.log('👥 Admins array:', admins);
	console.log('📊 Total students (clients):', totalStudents);
	console.log('👥 Total admins:', admins.length);
	
	if (admins.length === 0 || totalStudents === 0) {
		return;
	}
	
	let allAdmins = admins;
	if (allAdmins.length === 0) return;
	
	// Reset distribution
	studentDistribution = {};
	
	// Distribute students evenly
	clients.forEach((student, index) => {
		const adminIndex = index % allAdmins.length;
		const adminId = allAdmins[adminIndex].id;
		if (!studentDistribution[adminId]) studentDistribution[adminId] = [];
		studentDistribution[adminId].push(student);
	});
	
    	// Persist silently - but only if this is a manual change, not auto-reconciliation
    saveDistributionSilently();
	renderDistributionList();
}

// Reconcile distribution when admins list changes (added/removed)
function reconcileDistribution() {
	if (!admins || admins.length === 0) return;
	if (!studentDistribution) studentDistribution = {};
	
	// Map current admins
	const adminIds = new Set(admins.map(a => String(a.id)));
	
	// Collect all students from existing distribution
	const collected = [];
	Object.entries(studentDistribution).forEach(([adminId, list]) => {
		list.forEach(s => collected.push(s));
	});
	
	// Also include any clients that are not in distribution yet
	const distributedIds = new Set(collected.map(s => s.id));
	clients.forEach(s => { if (!distributedIds.has(s.id)) collected.push(s); });
	
	// Rebuild distribution only with valid admins
	const newDist = {};
	const validAdmins = admins.slice();
	if (validAdmins.length === 0) return;
	
	collected.forEach((student, idx) => {
		const adminIndex = idx % validAdmins.length;
		const adminId = validAdmins[adminIndex].id;
		if (!newDist[adminId]) newDist[adminId] = [];
		newDist[adminId].push(student);
	});
	
    studentDistribution = newDist;
    
    // DON'T save automatically - this overwrites manual transfers!
    // saveDistributionSilently();
    
	renderDistributionList();
}

// Silent distribution save (no toasts/no modal close)
async function saveDistributionSilently() {
    try {
        await fetch('/backend/api.php?action=save_student_distribution', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ distribution: studentDistribution })
        });
    } catch (e) {
        console.error('Silent distribution save failed:', e);
    }
}

// Save distribution
async function saveDistribution() {
    try {
        const response = await fetch('/backend/api.php?action=save_student_distribution', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                distribution: studentDistribution
            })
        });
        
        const result = await response.json();
        if (result.success) {
            // Add log entry
            addLogEntry('student_assignment', 'حفظ توزيع الطلاب', 
                `تم حفظ توزيع جديد للطلاب على المشرفين`);
            
            showNotification('تم حفظ التوزيع بنجاح', 'success');
            closeStudentDistributionModal();
        } else {
            showNotification('فشل في حفظ التوزيع: ' + result.message, 'error');
        }
    } catch (error) {
        showNotification('خطأ في حفظ التوزيع: ' + error.message, 'error');
    }
}

// Show admin logs
function showAdminLogs() {
    if (currentUser.role !== 'manager') {
        showNotification('فقط المدير يمكنه الوصول لهذه الصفحة', 'error');
        return;
    }
    loadAdminLogs().then(() => {
        const modal = document.getElementById('adminLogsModal');
        modal.classList.add('show');
        centerModal('adminLogsModal');
        if (!adminLogs || adminLogs.length === 0) {
            const list = document.getElementById('logsList');
            if (list) list.innerHTML = '<p style="text-align:center;color:#6b7280">لا توجد سجلات بعد</p>';
        }
    });
}

async function loadAdminLogs() {
    try {
        const response = await fetch('/backend/api.php?action=get_admin_logs');
        const result = await response.json();
        if (result.success) {
            adminLogs = result.data.logs || [];
            renderAdminLogs();
        } else {
            adminLogs = [];
            renderAdminLogs();
        }
    } catch (error) {
        console.error('Error loading admin logs:', error);
        adminLogs = [];
        renderAdminLogs();
    }
}

// Render admin logs
function renderAdminLogs() {
    const container = document.getElementById('logsList');
    if (!container) return;
    
    container.innerHTML = adminLogs.map(log => `
        <div class="log-item">
            <div class="log-icon ${log.type}">
                <i class="fas ${getLogIcon(log.type)}"></i>
            </div>
            <div class="log-content">
                <div class="log-action">${log.action}</div>
                <div class="log-details">${log.details}</div>
            </div>
            <div class="log-time">${formatTime(log.timestamp)}</div>
        </div>
    `).join('');
}

// Get log icon based on type
function getLogIcon(type) {
    const icons = {
        'file_upload': 'fa-file-upload',
        'status_change': 'fa-exchange-alt',
        'student_assignment': 'fa-user-plus',
        'help_request': 'fa-handshake'
    };
    return icons[type] || 'fa-info-circle';
}

// Format time
function formatTime(timestamp) {
    const date = new Date(timestamp);
    return date.toLocaleString('ar-EG');
}

// Close modals
function closeStudentDistributionModal() {
    document.getElementById('studentDistributionModal').classList.remove('show');
}

function closeAdminLogsModal() {
    document.getElementById('adminLogsModal').classList.remove('show');
}

function closeHelpRequestModal() {
    document.getElementById('helpRequestModal').classList.remove('show');
}

function closeAdminDetailsModal() {
    document.getElementById('adminDetailsModal').classList.remove('show');
    // Clear current admin ID
    window.currentAdminId = null;
}

// Add log entry
function addLogEntry(type, action, details) {
    const logEntry = {
        id: Date.now(),
        type: type,
        action: action,
        details: details,
        adminId: currentUser.id || 'unknown',
        adminName: currentUser.username || 'unknown',
        timestamp: new Date().toISOString()
    };
    
    adminLogs.unshift(logEntry);
    
    // Send to backend
    fetch('/backend/api.php?action=add_log_entry', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(logEntry)
    }).catch(error => console.error('Error saving log:', error));
    
    console.log('Log entry added:', logEntry);
}

// View admin details
function viewAdminDetails(adminId) {
    console.log('🔄 Viewing admin details for ID:', adminId);
    
    const admin = admins.find(a => a.id == adminId);
    if (!admin) {
        console.error('❌ Admin not found:', adminId);
        showNotification('المشرف غير موجود', 'error');
        return;
    }
    
    console.log('✅ Admin found:', admin);
    
    // Load distribution data if not already loaded
    if (Object.keys(studentDistribution).length === 0) {
        console.log('📥 Loading distribution data...');
        loadDistributionData().then(() => {
            // After loading, show admin details
            showAdminDetailsAfterLoad(admin, adminId);
        });
    } else {
        // Show admin details immediately
        showAdminDetailsAfterLoad(admin, adminId);
    }
}

// Show admin details after distribution data is loaded
function showAdminDetailsAfterLoad(admin, adminId) {
    console.log('🎯 Showing admin details after data load');
    
    // Get students assigned to this admin
    const assignedStudents = studentDistribution[adminId] || [];
    console.log('📚 Assigned students:', assignedStudents);
    
    // Calculate statistics with better error handling
    const totalStudents = assignedStudents.length;
    
    // Check for different possible status values
    const completedStudents = assignedStudents.filter(s => {
        const status = s.applicationStatus || s.status || 'لم يتم التقديم';
        return status === 'مكتمل' || status === 'completed' || status === 'تم التقديم';
    }).length;
    
    const pendingStudents = assignedStudents.filter(s => {
        const status = s.applicationStatus || s.status || 'لم يتم التقديم';
        return status === 'معلق' || status === 'pending' || status === 'قيد المراجعة';
    }).length;
    
    const totalFiles = assignedStudents.reduce((total, student) => {
        const files = student.files || student.uploadedFiles || [];
        return total + files.length;
    }, 0);
    
    console.log('📊 Statistics calculated:', {
        totalStudents,
        completedStudents,
        pendingStudents,
        totalFiles,
        sampleStudent: assignedStudents[0] ? {
            id: assignedStudents[0].id,
            name: assignedStudents[0].fullName,
            status: assignedStudents[0].applicationStatus || assignedStudents[0].status,
            files: assignedStudents[0].files || assignedStudents[0].uploadedFiles
        } : 'No students'
    });
    
    // Update modal content with error handling
    try {
        document.getElementById('adminDetailsName').textContent = admin.fullName || 'غير محدد';
        document.getElementById('adminDetailsEmail').textContent = admin.email || 'غير محدد';
        document.getElementById('adminDetailsRole').textContent = admin.role || 'غير محدد';
        document.getElementById('adminDetailsDate').textContent = `تاريخ الإضافة: ${admin.createdAt || 'غير محدد'}`;
        
        // Update statistics with error handling
        const totalStudentsEl = document.getElementById('adminTotalStudents');
        const completedStudentsEl = document.getElementById('adminCompletedStudents');
        const pendingStudentsEl = document.getElementById('adminPendingStudents');
        const totalFilesEl = document.getElementById('adminTotalFiles');
        
        if (totalStudentsEl) totalStudentsEl.textContent = totalStudents;
        if (completedStudentsEl) completedStudentsEl.textContent = completedStudents;
        if (pendingStudentsEl) pendingStudentsEl.textContent = pendingStudents;
        if (totalFilesEl) totalFilesEl.textContent = totalFiles;
        
        console.log('✅ All statistics updated successfully');
    } catch (error) {
        console.error('❌ Error updating admin details:', error);
    }
    
    // Render students list
    renderAdminStudentsList(assignedStudents);
    
    // Store current admin ID for refresh functionality
    window.currentAdminId = adminId;
    
    // Show modal
    document.getElementById('adminDetailsModal').classList.add('show');
    centerModal('adminDetailsModal');
    
    // Add log entry
    addLogEntry('admin_view', 'عرض تفاصيل المشرف', 
        `تم عرض تفاصيل المشرف ${admin.fullName}`);
    
    // Debug: Log all assigned students for verification
    console.log('🔍 Debug: All assigned students data:', assignedStudents.map(s => ({
        id: s.id,
        name: s.fullName,
        status: s.applicationStatus || s.status,
        files: s.files || s.uploadedFiles || [],
        filesCount: (s.files || s.uploadedFiles || []).length
    })));
}

// Function to refresh admin statistics (can be called manually for debugging)
function refreshAdminStatistics(adminId) {
    console.log('🔄 Refreshing admin statistics for ID:', adminId);
    
    const admin = admins.find(a => a.id == adminId);
    if (!admin) {
        console.error('❌ Admin not found for statistics refresh');
        return;
    }
    
    const assignedStudents = studentDistribution[adminId] || [];
    console.log('📚 Found assigned students:', assignedStudents.length);
    
    // Recalculate and update statistics
    const totalStudents = assignedStudents.length;
    const completedStudents = assignedStudents.filter(s => {
        const status = s.applicationStatus || s.status || 'لم يتم التقديم';
        return status === 'مكتمل' || status === 'completed' || status === 'تم التقديم';
    }).length;
    
    const pendingStudents = assignedStudents.filter(s => {
        const status = s.applicationStatus || s.status || 'لم يتم التقديم';
        return status === 'معلق' || status === 'pending' || status === 'قيد المراجعة';
    }).length;
    
    const totalFiles = assignedStudents.reduce((total, student) => {
        const files = student.files || student.uploadedFiles || [];
        return total + files.length;
    }, 0);
    
    console.log('📊 Refreshed statistics:', {
        totalStudents,
        completedStudents,
        pendingStudents,
        totalFiles
    });
    
    // Update UI elements
    try {
        const totalStudentsEl = document.getElementById('adminTotalStudents');
        const completedStudentsEl = document.getElementById('adminCompletedStudents');
        const pendingStudentsEl = document.getElementById('adminPendingStudents');
        const totalFilesEl = document.getElementById('adminTotalFiles');
        
        if (totalStudentsEl) totalStudentsEl.textContent = totalStudents;
        if (completedStudentsEl) completedStudentsEl.textContent = completedStudents;
        if (pendingStudentsEl) pendingStudentsEl.textContent = pendingStudents;
        if (totalFilesEl) totalFilesEl.textContent = totalFiles;
        
        console.log('✅ Statistics refreshed successfully');
    } catch (error) {
        console.error('❌ Error refreshing statistics:', error);
    }
}

// Function to refresh admin statistics from the modal (button click)
function refreshAdminStatisticsFromModal() {
    console.log('🔄 Refreshing admin statistics from modal button');
    
    // Get the current admin ID from the modal
    const modal = document.getElementById('adminDetailsModal');
    if (!modal || !modal.classList.contains('show')) {
        console.error('❌ Admin details modal is not open');
        return;
    }
    
    // Try to find admin ID from the current context
    // We'll need to store this when opening the modal
    if (window.currentAdminId) {
        refreshAdminStatistics(window.currentAdminId);
        showNotification('تم تحديث الإحصائيات', 'success');
    } else {
        console.error('❌ No current admin ID found');
        showNotification('لا يمكن تحديث الإحصائيات - افتح تفاصيل المشرف مرة أخرى', 'error');
    }
}

// ===== STUDENT TRANSFER FUNCTIONS =====

// Show student transfer modal
function showStudentTransferModal() {
    console.log('🔄 Opening student transfer modal');
    
    // Check if admin details modal is open
    if (!window.currentAdminId) {
        showNotification('يرجى فتح تفاصيل المشرف أولاً', 'error');
        return;
    }
    
    // Load current admin's students
    loadCurrentAdminStudents();
    
    // Load available admins
    loadAvailableAdminsForTransfer();
    
    // Show modal
    document.getElementById('studentTransferModal').classList.add('show');
    centerModal('studentTransferModal');
}

// Load current admin's students
function loadCurrentAdminStudents() {
    const currentAdminId = window.currentAdminId;
    const assignedStudents = studentDistribution[currentAdminId] || [];
    
    const studentSelect = document.getElementById('transferStudentSelect');
    if (!studentSelect) return;
    
    studentSelect.innerHTML = '<option value="">اختر الطالب</option>';
    
    assignedStudents.forEach(student => {
        const option = document.createElement('option');
        option.value = student.id;
        option.textContent = `${student.fullName} (${student.email})`;
        studentSelect.appendChild(option);
    });
    
    console.log(`📚 Loaded ${assignedStudents.length} students for transfer`);
}

// Load available admins for transfer (excluding current admin)
function loadAvailableAdminsForTransfer() {
    const currentAdminId = window.currentAdminId;
    const adminSelect = document.getElementById('transferToAdmin');
    if (!adminSelect) return;
    
    adminSelect.innerHTML = '<option value="">اختر المشرف الجديد</option>';
    
    admins.forEach(admin => {
        if (admin.id != currentAdminId) {
            const option = document.createElement('option');
            option.value = admin.id;
            option.textContent = admin.fullName || admin.username;
            adminSelect.appendChild(option);
        }
    });
    
    console.log(`👥 Loaded ${admins.length - 1} available admins for transfer`);
}

// Transfer student to new admin
async function transferStudent() {
    const studentId = document.getElementById('transferStudentSelect').value;
    const toAdminId = document.getElementById('transferToAdmin').value;
    const confirmTransfer = document.getElementById('confirmTransfer').checked;
    
    if (!studentId) {
        showNotification('يرجى اختيار الطالب', 'error');
        return;
    }
    
    if (!toAdminId) {
        showNotification('يرجى اختيار المشرف الجديد', 'error');
        return;
    }
    
    if (!confirmTransfer) {
        showNotification('يرجى تأكيد عملية النقل', 'error');
        return;
    }
    
    try {
        console.log('🔄 Transferring student...', { studentId, fromAdminId: window.currentAdminId, toAdminId });
        
        const response = await fetch('/backend/api.php?action=transfer_student', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-User-Email': currentUser.email || ''
            },
            body: JSON.stringify({
                studentId: studentId,
                fromAdminId: window.currentAdminId,
                toAdminId: toAdminId
            })
        });
        
        const result = await response.json();
        console.log('📥 Transfer response:', result);
        
        if (result.success) {
            showNotification('تم نقل الطالب بنجاح', 'success');
            closeStudentTransferModal();
            
            // Refresh admin details
            if (window.currentAdminId) {
                refreshAdminStatistics(window.currentAdminId);
                renderAdminStudentsList(studentDistribution[window.currentAdminId] || []);
            }
            
            // Refresh distribution list if it exists
            if (typeof renderDistributionList === 'function') {
                renderDistributionList();
            }
            
            // Update global distribution data
            if (studentDistribution[window.currentAdminId]) {
                studentDistribution[window.currentAdminId] = studentDistribution[window.currentAdminId].filter(s => s.id != studentId);
            }
            
            if (!studentDistribution[toAdminId]) {
                studentDistribution[toAdminId] = [];
            }
            
            const student = (studentDistribution[window.currentAdminId] || []).find(s => s.id == studentId) || 
                           clients.find(s => s.id == studentId);
            
            if (student) {
                studentDistribution[toAdminId].push(student);
            }
            
            // CRITICAL: Refresh distribution from server to ensure consistency
            console.log('🔄 Refreshing distribution after transfer...');
            await loadStudentDistributionFromAPI();
            console.log('✅ Distribution refreshed after transfer');
            console.log('🔍 New studentDistribution:', studentDistribution);
            
        } else {
            showNotification('فشل في نقل الطالب: ' + result.message, 'error');
        }
    } catch (error) {
        console.error('❌ Error transferring student:', error);
        showNotification('خطأ في نقل الطالب', 'error');
    }
}

// Close student transfer modal
function closeStudentTransferModal() {
    document.getElementById('studentTransferModal').classList.remove('show');
    
    // Clear form
    document.getElementById('transferStudentSelect').value = '';
    document.getElementById('transferToAdmin').value = '';
    document.getElementById('confirmTransfer').checked = false;
}

// ===== GOOGLE SHEET ID MANAGEMENT =====

// Show change Google Sheet ID modal
function showChangeSheetIdModal() {
    console.log('🔑 Opening change Google Sheet ID modal');
    
    // Check if user is owner
    if (currentUser.email !== 'stroogar@gmail.com') {
        showNotification('فقط المالك يمكنه تغيير Google Sheet ID', 'error');
        return;
    }
    
    // Load current sheet ID
    loadCurrentSheetId();
    
    // Show modal
    document.getElementById('changeSheetIdModal').classList.add('show');
    centerModal('changeSheetIdModal');
}

// Close change Google Sheet ID modal
function closeChangeSheetIdModal() {
    document.getElementById('changeSheetIdModal').classList.remove('show');
    
    // Clear form
    document.getElementById('newSheetId').value = '';
    document.getElementById('confirmPassword').value = '';
}

// Load current Google Sheet ID
async function loadCurrentSheetId() {
    try {
        const response = await fetch('/backend/api.php?action=get_google_sheet_id');
        const result = await response.json();
        
        if (result.success) {
            document.getElementById('currentSheetId').value = result.sheetId || 'غير محدد';
        } else {
            document.getElementById('currentSheetId').value = 'خطأ في التحميل';
            console.error('❌ Failed to load sheet ID:', result.message);
        }
    } catch (error) {
        console.error('❌ Error loading sheet ID:', error);
        document.getElementById('currentSheetId').value = 'خطأ في التحميل';
    }
}

// Update Google Sheet ID
async function updateGoogleSheetId() {
    const newSheetId = document.getElementById('newSheetId').value.trim();
    const confirmPassword = document.getElementById('confirmPassword').value.trim();
    
    if (!newSheetId) {
        showNotification('يرجى إدخال Google Sheet ID الجديد', 'error');
        return;
    }
    
    if (!confirmPassword) {
        showNotification('يرجى إدخال كلمة المرور للتأكيد', 'error');
        return;
    }
    
    // Validate sheet ID format (basic validation)
    if (!/^[a-zA-Z0-9_-]+$/.test(newSheetId)) {
        showNotification('Google Sheet ID غير صحيح', 'error');
        return;
    }
    
    try {
        console.log('🔄 Updating Google Sheet ID...');
        
        const response = await fetch('/backend/api.php?action=update_google_sheet_id', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-User-Email': currentUser.email || ''
            },
            body: JSON.stringify({
                newSheetId: newSheetId,
                confirmPassword: confirmPassword
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('تم تحديث Google Sheet ID بنجاح', 'success');
            closeChangeSheetIdModal();
            
            // Update current sheet ID display
            document.getElementById('currentSheetId').value = newSheetId;
            
            // Log the change
            addLogEntry('sheet_id_update', 'تحديث Google Sheet ID', 
                `تم تحديث Google Sheet ID إلى: ${newSheetId}`);
        } else {
            showNotification('فشل في تحديث Google Sheet ID: ' + result.message, 'error');
        }
    } catch (error) {
        console.error('❌ Error updating sheet ID:', error);
        showNotification('خطأ في تحديث Google Sheet ID', 'error');
    }
}

// Render admin students list
function renderAdminStudentsList(students) {
    const container = document.getElementById('adminStudentsList');
    if (!container) {
        console.error('❌ Admin students list container not found');
        return;
    }
    
    if (students.length === 0) {
        container.innerHTML = '<p style="text-align: center; color: #6b7280; padding: 20px;">لا يوجد طلاب مسؤول عنهم حالياً</p>';
        return;
    }
    
    container.innerHTML = students.map(student => {
        const statusClass = getStudentStatusClass(student.applicationStatus || student.status);
        const statusText = student.applicationStatus || student.status || 'لم يتم التقديم';
        const filesCount = (student.files || student.uploadedFiles || []).length;
        
        console.log('🎯 Rendering student card:', {
            id: student.id,
            name: student.fullName,
            status: statusText,
            statusClass: statusClass,
            filesCount: filesCount
        });
        
        return `
            <div class="student-card">
                <div class="student-header">
                    <span class="student-name">${student.fullName || 'اسم غير محدد'}</span>
                    <span class="student-status ${statusClass}">${statusText}</span>
                </div>
                <div class="student-info">
                    <p><i class="fas fa-envelope"></i> ${student.email || 'بريد غير محدد'}</p>
                    <p><i class="fas fa-phone"></i> ${student.phone || 'هاتف غير محدد'}</p>
                    <p><i class="fas fa-graduation-cap"></i> ${student.scholarshipType || 'نوع منحة غير محدد'}</p>
                    ${student.university ? `<p><i class="fas fa-university"></i> ${student.university}</p>` : ''}
                    ${student.specialization ? `<p><i class="fas fa-book"></i> ${student.specialization}</p>` : ''}
                </div>
                <div class="student-files">
                    <span><i class="fas fa-file"></i> ${filesCount} ملف</span>
                </div>
            </div>
        `;
    }).join('');
    
    console.log(`✅ Rendered ${students.length} student cards`);
}

// Get student status class for CSS styling
function getStudentStatusClass(status) {
    if (!status) return 'not-applied';
    
    const normalizedStatus = status.toString().toLowerCase();
    
    switch (normalizedStatus) {
        case 'مكتمل':
        case 'completed':
        case 'تم التقديم':
            return 'completed';
        case 'معلق':
        case 'pending':
        case 'قيد المراجعة':
            return 'pending';
        case 'لم يتم التقديم':
        case 'not applied':
        default:
            return 'not-applied';
    }
}

// Remove student from admin
function removeStudentFromAdmin(studentId, adminId) {
    if (confirm('هل أنت متأكد من إزالة هذا الطالب من المشرف؟')) {
        if (studentDistribution[adminId]) {
            studentDistribution[adminId] = studentDistribution[adminId].filter(s => s.id != studentId);
            
            // Add log entry
            const student = clients.find(s => s.id == studentId);
            const admin = admins.find(a => a.id == adminId);
            if (student && admin) {
                addLogEntry('student_assignment', 'إزالة طالب من مشرف', 
                    `تم إزالة الطالب ${student.fullName} من المشرف ${admin.username}`);
            }
            
            renderDistributionList();
            showNotification('تم إزالة الطالب من المشرف', 'success');
        }
    }
}

// Reset distribution
function resetDistribution() {
    if (confirm('هل أنت متأكد من إعادة تعيين التوزيع؟')) {
        studentDistribution = {};
        renderDistributionList();
        showNotification('تم إعادة تعيين التوزيع', 'success');
    }
}

// Request help for a student (Be an assistant)
function requestHelp(studentId) {
    console.log('🤝 Requesting to be helper for student:', studentId);
    
    const student = students.find(s => s.id == studentId) || clients.find(s => s.id == studentId);
    if (!student) {
        showNotification('الطالب غير موجود', 'error');
        return;
    }
    
    // Show help request modal with pre-filled student
    showHelpRequestModal();
    
    // Pre-select the student and lock the selection
    setTimeout(() => {
        const studentSelect = document.getElementById('helpStudentSelect');
        if (studentSelect) {
            studentSelect.value = studentId;
            studentSelect.disabled = true; // Lock the selection
        }
    }, 100);
    
    // Add log entry
    addLogEntry('help_request', 'طلب أن يكون مساعداً', 
        `تم طلب أن يكون مساعداً للطالب ${student.fullName}`);
}

// Show help request modal
function showHelpRequestModal() {
    console.log('🤝 Showing helper request modal');
    
    // Reset form
    document.getElementById('helpStudentSelect').value = '';
    document.getElementById('helpStudentSelect').disabled = false;
    document.getElementById('helpTypeSelect').value = '';
    document.getElementById('helpDetails').value = '';
    
    // Populate student select with all students (for managers) or assigned students (for admins)
    const studentSelect = document.getElementById('helpStudentSelect');
    studentSelect.innerHTML = '<option value="">اختر الطالب</option>';
    
    if (currentUser.isMainAdmin === true) {
        // Main admins can request to help any student
        clients.forEach(student => {
            const option = document.createElement('option');
            option.value = student.id;
            option.textContent = student.fullName;
            studentSelect.appendChild(option);
        });
    } else {
        // Regular admins can only request to help their assigned students
        if (studentDistribution && studentDistribution[currentUser.id]) {
            studentDistribution[currentUser.id].forEach(student => {
                const option = document.createElement('option');
                option.value = student.id;
                option.textContent = student.fullName;
                studentSelect.appendChild(option);
            });
        }
    }
    
    // Show modal
    document.getElementById('helpRequestModal').classList.add('show');
    centerModal('helpRequestModal');
}

// Send help request
async function sendHelpRequest() {
    const studentId = document.getElementById('helpStudentSelect').value;
    const helpType = document.getElementById('helpTypeSelect').value;
    const helpDetails = document.getElementById('helpDetails').value;
    
    if (!studentId || !helpType || !helpDetails.trim()) {
        showNotification('يرجى ملء جميع الحقول المطلوبة', 'warning');
        return;
    }
    
    const student = students.find(s => s.id == studentId) || clients.find(s => s.id == studentId);
    if (!student) {
        showNotification('الطالب غير موجود', 'error');
        return;
    }
    
    try {
        // Determine assigned admin for this student (if any)
        let assignedAdminId = null;
        for (const [adminId, list] of Object.entries(studentDistribution || {})) {
            if ((list || []).some(s => String(s.id) === String(studentId))) { assignedAdminId = Number(adminId); break; }
        }

        // Create help request object
        const helpRequest = {
            id: Date.now(),
            studentId: studentId,
            studentName: student.fullName,
            requesterId: currentUser.id,
            requesterName: currentUser.username,
            helpType: helpType,
            helpDetails: helpDetails,
            status: 'pending', // pending, accepted, rejected
            timestamp: new Date().toISOString(),
            assignedAdminId: assignedAdminId,
            readBy: []
        };
        
        // Add to help requests array
        if (!helpRequests) helpRequests = [];
        helpRequests.push(helpRequest);
        
        const response = await fetch('/backend/api.php?action=add_help_request', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(helpRequest)
        });
        
        if (response.ok) {
            // reload from API to ensure persistence
            await loadHelpRequestsFromAPI();
            updateHelpBadge();
            showNotification('تم إرسال طلب المساعدة بنجاح', 'success');
            closeHelpRequestModal();
            
            // Add log entry
            addLogEntry('help_request', 'إرسال طلب أن يكون مساعداً', 
                `تم إرسال طلب أن يكون مساعداً للطالب ${student.fullName} - النوع: ${helpType}`);
        } else {
            showNotification('فشل في إرسال طلب المساعدة', 'error');
        }
        
    } catch (error) {
        console.error('Error sending help request:', error);
        showNotification('خطأ في إرسال طلب المساعدة', 'error');
    }
}

// Show helper modal pre-populated with unassigned-to-me students
function showHelperForUnassigned() {
	const myList = (studentDistribution && studentDistribution[currentUser.id]) ? studentDistribution[currentUser.id].map(s=>s.id) : [];
	const unassignedToMe = clients.filter(s => !myList.includes(s.id));
	showHelpRequestModal();
	const select = document.getElementById('helpStudentSelect');
	if (!select) return;
	select.innerHTML = '<option value="">اختر الطالب</option>';
	unassignedToMe.forEach(s => {
		const opt = document.createElement('option');
		opt.value = s.id; opt.textContent = s.fullName; select.appendChild(opt);
	});
}

// Help inbox (simple render of helpRequests + unread badge)
async function openHelpInbox() {
    // Ensure we have latest from disk
    await loadHelpRequestsFromAPI();
    // Load assigned list safely
    const mine = (studentDistribution && studentDistribution[currentUser.id]) ? studentDistribution[currentUser.id] : [];
    const assignedIds = new Set(mine.map(s=>Number(s.id)));
    // Match either by assigned student OR explicitly targeted admin id
    let myIncoming = (helpRequests || []).filter(r => {
        const sid = Number(r.studentId);
        const targeted = (Number(r.assignedAdminId) === Number(currentUser.id));
        const assignedMatch = assignedIds.has(sid);
        return (targeted || assignedMatch) && r.requesterId !== currentUser.id && (r.status === 'pending');
    });
    // Sort: newest first
    try {
        myIncoming.sort((a,b) => new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime());
    } catch(e) {}
    const unread = myIncoming.filter(r => !r.readBy || !r.readBy.includes(currentUser.id));
    const badge = document.getElementById('helpUnreadBadge');
    if (badge) {
        if (unread.length > 0) { badge.style.display='inline-block'; badge.textContent = unread.length; } else { badge.style.display='none'; }
    }
    // Render into tabs
    renderHelpPending(myIncoming);
    const myAccepted = (helpRequests || []).filter(r => (Number(r.assignedAdminId) === Number(currentUser.id)) && r.status === 'accepted');
    renderHelpPermissions(myAccepted);
    const modal = document.getElementById('helpInboxModal');
    if (modal) { modal.classList.add('show'); centerModal('helpInboxModal'); }
    // mark as read
    myIncoming.forEach(r => { r.readBy = Array.from(new Set([...(r.readBy||[]), currentUser.id])); });
}

function renderHelpPending(items){
    const list = document.getElementById('helpPendingList');
    if (!list) return;
    if (!items || items.length===0) { list.innerHTML = '<p style="text-align:center;color:#6b7280">لا توجد إشعارات حالياً</p>'; return; }
    list.innerHTML = items.map(r => `
        <div class="help-request-item">
            <div class="help-request-header">
                <strong>${r.requesterName}</strong>
                <span class="help-request-type">${r.helpType}</span>
            </div>
            <div class="help-request-body">
                <div>الطالب: ${r.studentName}</div>
                <div style="color:#64748b;margin-top:6px">${r.helpDetails}</div>
            </div>
            <div class="help-request-actions">
                <button class="btn-primary" onclick="acceptHelp(${r.id})">قبول</button>
                <button class="btn-secondary" onclick="rejectHelp(${r.id})">رفض</button>
            </div>
        </div>
    `).join('');
}

function renderHelpPermissions(items){
    const list = document.getElementById('helpPermissionsList');
    if (!list) return;
    if (!items || items.length===0) { list.innerHTML = '<p style="text-align:center;color:#6b7280">لا توجد أذونات حالياً</p>'; return; }
    list.innerHTML = items.map(r => `
        <div class="help-request-item">
            <div class="help-request-header">
                <strong>${r.requesterName}</strong>
                <span class="help-request-type">${r.helpType}</span>
            </div>
            <div class="help-request-body">
                <div>الطالب: ${r.studentName}</div>
                <div class="muted">${r.timestamp || ''}</div>
            </div>
            <div class="help-request-actions">
                <button class="btn-danger" onclick="revokeHelp(${r.id})">إلغاء الإذن</button>
            </div>
        </div>
    `).join('');
}

function switchHelpTab(which){
    const pending = document.getElementById('helpPendingList');
    const perms = document.getElementById('helpPermissionsList');
    const tabP = document.getElementById('tabPending');
    const tabR = document.getElementById('tabPermissions');
    if (!pending || !perms) return;
    if (which==='permissions'){ pending.style.display='none'; perms.style.display='block'; tabP.classList.remove('btn-primary'); tabR.classList.add('btn-primary'); }
    else { pending.style.display='block'; perms.style.display='none'; tabR.classList.remove('btn-primary'); tabP.classList.add('btn-primary'); }
}

function revokeHelp(requestId) {
    const req = helpRequests.find(r => r.id == requestId);
    if (!req) return;
    fetch('/backend/api.php?action=update_help_request_status', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: req.id, status: 'revoked' })
    }).then(async ()=>{
        req.status = 'revoked';
        await loadHelpRequestsFromAPI();
        updateHelpBadge();
        showNotification('تم إلغاء الإذن', 'success');
        openHelpInbox();
    });
}

function closeHelpInboxModal() {
    document.getElementById('helpInboxModal').classList.remove('show');
}

function acceptHelp(requestId) {
    const req = helpRequests.find(r => r.id == requestId);
    if (!req) return;
    fetch('/backend/api.php?action=update_help_request_status', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: req.id, status: 'accepted' })
    }).then(async ()=>{
        req.status = 'accepted';
        // Reload latest requests to ensure access checks use fresh data
        await loadHelpRequestsFromAPI();
        showNotification('تم قبول المساعدة', 'success');
        openHelpInbox();
    });
}
function rejectHelp(requestId) {
    const req = helpRequests.find(r => r.id == requestId);
    if (!req) return;
    fetch('/backend/api.php?action=update_help_request_status', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: req.id, status: 'rejected' })
    }).then(async ()=>{
        req.status = 'rejected';
        await loadHelpRequestsFromAPI();
        showNotification('تم رفض المساعدة', 'success');
        openHelpInbox();
    });
}

// BRAND NEW VIEW FUNCTION - SIMPLE AND WORKING
function viewFile(filePath, fileName, fileType, source = 'uploaded') {
    console.log('🔍 NEW VIEW FUNCTION CALLED');
    console.log('🔍 File path:', filePath);
    console.log('🔍 File name:', fileName);
    console.log('🔍 File type:', fileType);
    console.log('🔍 File source:', source);
    
    // SIMPLE APPROACH: Just construct the path manually
    let webPath = '';
    
    // Extract student folder from the path
    // Example: C:\Users\StormBtw\Desktop\Project\backend\..\files\students\4_يوسف محمد عوض عبدالعاطي هيكل\why.jpg
    // We want: files/students/4_يوسف محمد عوض عبدالعاطي هيكل/why.jpg
    
    if (filePath.includes('students\\')) {
        // Find the students folder part
        const studentsIndex = filePath.indexOf('students\\');
        const afterStudents = filePath.substring(studentsIndex + 'students\\'.length);
        
        // Split by backslash to get folder and filename
        const parts = afterStudents.split('\\');
        if (parts.length >= 2) {
            const studentFolder = parts[0]; // 4_يوسف محمد عوض عبدالعاطي هيكل
            webPath = `files/students/${studentFolder}/${fileName}`;
            console.log('✅ SUCCESS: Constructed web path:', webPath);
        } else {
            webPath = `files/students/${fileName}`;
            console.log('⚠️ FALLBACK: Using simple path:', webPath);
        }
    } else {
        // If no students folder found, just use the filename
        webPath = fileName;
        console.log('⚠️ NO STUDENTS FOLDER: Using filename:', webPath);
    }
    
    console.log('🔍 FINAL WEB PATH:', webPath);
    
    // SIMPLE LOGIC: Always view images, download others
    console.log('🔍 File type check:', fileType);
    console.log('🔍 Is image?', fileType.includes('image'));
    console.log('🔍 Is jpeg?', fileType.includes('jpeg'));
    console.log('🔍 Is jpg?', fileType.includes('jpg'));
    
    if (fileType.includes('image') || fileType.includes('jpeg') || fileType.includes('jpg') || fileType.includes('png') || fileType.includes('gif')) {
        // ALWAYS VIEW IMAGES
        console.log('🖼️ IMAGE DETECTED - Opening in modal:', webPath);
        showImageModal(webPath, fileName);
    } else if (fileType.includes('pdf')) {
        console.log('📄 PDF detected - Opening in new tab:', webPath);
        window.open(webPath, '_blank');
    } else {
        // Everything else downloads
        console.log('📁 Other file type - Downloading:', webPath);
        const link = document.createElement('a');
        link.href = webPath;
        link.download = fileName;
        link.click();
    }
}

// Show image modal