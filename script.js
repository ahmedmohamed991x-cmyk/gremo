// ---------- Installments & Withdrawals frontend helpers ----------
const INSTALLMENTS_API = 'backend/installments.php';

async function loadInstallmentOverview() {
    try {
        const res = await fetch(`${INSTALLMENTS_API}?action=get_overview_installments`);
        const json = await res.json();
        if (!json.success) return;

        const data = json.data || {};
        const expected = data.expectedRevenueAfterInstallments || 0;
        const elExpected = document.getElementById('expectedRevenueAfterInstallments');
        if (elExpected) elExpected.textContent = Number(expected).toLocaleString() + ' ج.م';

        const karim = (data.profitDistribution && data.profitDistribution.karim && data.profitDistribution.karim.totalShare) || 0;
        const mahmoud = (data.profitDistribution && data.profitDistribution.mahmoud && data.profitDistribution.mahmoud.totalShare) || 0;
        const elKT = document.getElementById('karimTotalShare'); if (elKT) elKT.textContent = Number(karim).toLocaleString() + ' ج.م';
        const elMT = document.getElementById('mahmoudTotalShare'); if (elMT) elMT.textContent = Number(mahmoud).toLocaleString() + ' ج.م';

        const withdrawalsTotals = data.withdrawalTotals || { karim:0, mahmoud:0 };
        const elKW = document.getElementById('karimTotalWithdrawn'); if (elKW) elKW.textContent = Number(withdrawalsTotals.karim || 0).toLocaleString() + ' ج.م';
        const elMW = document.getElementById('mahmoudTotalWithdrawn'); if (elMW) elMW.textContent = Number(withdrawalsTotals.mahmoud || 0).toLocaleString() + ' ج.م';

        const elKR = document.getElementById('karimRemainingBalance'); if (elKR) elKR.textContent = Number((karim || 0) - (withdrawalsTotals.karim || 0)).toLocaleString() + ' ج.م';
        const elMR = document.getElementById('mahmoudRemainingBalance'); if (elMR) elMR.textContent = Number((mahmoud || 0) - (withdrawalsTotals.mahmoud || 0)).toLocaleString() + ' ج.م';

        const elKA = document.getElementById('karimShareAfterInstallments'); if (elKA) elKA.textContent = Number(karim || 0).toLocaleString() + ' ج.م';
        const elMA = document.getElementById('mahmoudShareAfterInstallments'); if (elMA) elMA.textContent = Number(mahmoud || 0).toLocaleString() + ' ج.م';
    } catch (e) {
        console.error('Failed to load installment overview', e);
    }
}

async function loadClientInstallmentSection(clientId) {
    const section = document.getElementById('clientInstallmentSection');
    if (!section) return;
    section.style.display = 'none';
    const derived = document.getElementById('installmentDerived');
    if (derived) derived.style.display = 'none';

    try {
        const res = await fetch(`${INSTALLMENTS_API}?action=get_installment&id=${clientId}`);
        const json = await res.json();
        const inst = json.success ? json.data : null;

        const en = document.getElementById('installmentEnabled'); if (en) en.checked = !!(inst && inst.enabled);
        const tot = document.getElementById('installmentTotalAmount'); if (tot) tot.value = inst ? (inst.total_amount || '') : '';
        const months = document.getElementById('installmentMonths'); if (months) months.value = inst ? (inst.months || '') : '';
        const weekly = document.getElementById('installmentWeeklyAmount'); if (weekly) weekly.value = inst ? (inst.weekly_amount || '') : '';
        const sd = document.getElementById('installmentStartDate'); if (sd) sd.value = inst ? (inst.start_date ? inst.start_date.split('T')[0] : '') : '';

        if (inst && inst.derived) {
            const aPaid = document.getElementById('derivedAmountPaid'); if (aPaid) aPaid.textContent = (inst.derived.amount_paid || 0).toLocaleString() + ' ج.م';
            const rBal = document.getElementById('derivedRemainingBalance'); if (rBal) rBal.textContent = (inst.derived.remaining_balance || 0).toLocaleString() + ' ج.م';
            const cPay = document.getElementById('derivedCompletedPayments'); if (cPay) cPay.textContent = inst.derived.completed_payments || 0;
            const remPay = document.getElementById('derivedRemainingPayments'); if (remPay) remPay.textContent = inst.derived.remaining_payments || 0;
            const st = inst.derived.status || 'No Installment';
            let statusText = 'لا يوجد قسط';
            if (st === 'Active' || st === 'active') statusText = 'نشط';
            if (st === 'Completed' || st === 'completed') statusText = 'اكتمل';
            const stEl = document.getElementById('derivedInstallmentStatus'); if (stEl) stEl.textContent = statusText;
            if (derived) derived.style.display = 'block';
        } else {
            if (derived) derived.style.display = 'none';
        }

        section.style.display = 'block';

        const saveBtn = document.getElementById('saveInstallmentBtn');
        if (saveBtn) {
            saveBtn.onclick = async () => {
                const payload = {
                    action: 'update_installment',
                    clientId: clientId,
                    enabled: document.getElementById('installmentEnabled').checked ? 1 : 0,
                    total_amount: parseFloat(document.getElementById('installmentTotalAmount').value || 0),
                    months: parseInt(document.getElementById('installmentMonths').value || 0),
                    weekly_amount: parseFloat(document.getElementById('installmentWeeklyAmount').value || 0),
                    start_date: document.getElementById('installmentStartDate').value || null
                };
                try {
                    const p = await fetch(INSTALLMENTS_API, {
                        method: 'POST',
                        headers: {'Content-Type':'application/json'},
                        body: JSON.stringify(payload)
                    });
                    const r = await p.json();
                    if (r.success) {
                        showNotification('تم حفظ بيانات الأقساط', 'success');
                        await loadClientInstallmentSection(clientId);
                        await loadInstallmentOverview();
                    } else {
                        showNotification('فشل في حفظ الأقساط', 'error');
                    }
                } catch (err) {
                    console.error(err);
                    showNotification('خطأ أثناء الحفظ', 'error');
                }
            };
        }
    } catch (e) {
        console.error('Error loading client installment', e);
    }
}

function openAddWithdrawalModal(owner) {
    const ow = document.getElementById('withdrawOwner'); if (ow) ow.value = owner;
    const a = document.getElementById('withdrawAmount'); if (a) a.value = '';
    const n = document.getElementById('withdrawNote'); if (n) n.value = '';
    const modal = document.getElementById('addWithdrawalModal'); if (modal) modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

async function submitAddWithdrawal(e) {
    e.preventDefault();
    const owner = document.getElementById('withdrawOwner').value;
    const amount = parseFloat(document.getElementById('withdrawAmount').value || 0);
    const note = document.getElementById('withdrawNote').value || '';
    if (!owner || amount <= 0) { showNotification('يرجى إدخال المالك والمبلغ الصحيح', 'error'); return false; }

    try {
        const res = await fetch(INSTALLMENTS_API, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'add_withdrawal', owner: owner, amount: amount, note: note })
        });
        const json = await res.json();
        if (json.success) {
            showNotification('تم إضافة السحب', 'success');
            await loadInstallmentOverview();
            closeModal('addWithdrawalModal');
            return true;
        } else {
            showNotification('فشل في إضافة السحب', 'error');
            return false;
        }
    } catch (err) {
        console.error('submitAddWithdrawal error', err);
        showNotification('خطأ في الشبكة', 'error');
        return false;
    }
}

// Wire overview load when payments page is shown
(function wirePaymentsOverview(){
    document.addEventListener('DOMContentLoaded', function() {
        const paymentsNav = document.querySelector('[data-page="payments"]');
        if (paymentsNav) {
            paymentsNav.addEventListener('click', function() {
                setTimeout(() => {
                    loadInstallmentOverview();
                }, 120);
            });
        }

        // Also attempt initial load in case payments page is active on start
        if (document.getElementById('payments') && document.getElementById('payments').classList.contains('active')) {
            setTimeout(loadInstallmentOverview, 200);
        }
    });
})();
