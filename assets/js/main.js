document.addEventListener('DOMContentLoaded', function () {
    // Mobile Navbar Toggle
    const hamburgerBtn = document.getElementById('hamburgerBtn');
    const navMenu = document.getElementById('navMenu');

    if (hamburgerBtn && navMenu) {
        hamburgerBtn.addEventListener('click', function () {
            navMenu.classList.toggle('open');
        });
    }

    // EMI Calculator Logic
    const loanAmtInput = document.getElementById('loanAmt');
    const interestRateInput = document.getElementById('interestRate');
    const tenureInput = document.getElementById('tenureMonths');

    const loanAmtVal = document.getElementById('loanAmtVal');
    const interestRateVal = document.getElementById('interestRateVal');
    const tenureVal = document.getElementById('tenureVal');

    const emiResultDisplay = document.getElementById('emiResultDisplay');
    const principalDisplay = document.getElementById('principalDisplay');
    const interestDisplay = document.getElementById('interestDisplay');
    const totalPayableDisplay = document.getElementById('totalPayableDisplay');

    function calculateEMI() {
        if (!loanAmtInput || !interestRateInput || !tenureInput) return;

        const P = parseFloat(loanAmtInput.value) || 0;
        const annualRate = parseFloat(interestRateInput.value) || 0;
        const N = parseInt(tenureInput.value) || 0;

        if (loanAmtVal) loanAmtVal.textContent = '₹' + P.toLocaleString('en-IN');
        if (interestRateVal) interestRateVal.textContent = annualRate + '% p.a.';
        if (tenureVal) tenureVal.textContent = N + ' Months';

        if (P <= 0 || N <= 0) return;

        let monthlyEMI = 0;
        let totalInterest = 0;
        let totalPayable = 0;

        if (annualRate > 0) {
            const R = (annualRate / 12) / 100;
            const emi = (P * R * Math.pow(1 + R, N)) / (Math.pow(1 + R, N) - 1);
            monthlyEMI = Math.round(emi);
            totalPayable = Math.round(monthlyEMI * N);
            totalInterest = Math.round(totalPayable - P);
        } else {
            monthlyEMI = Math.round(P / N);
            totalPayable = P;
            totalInterest = 0;
        }

        if (emiResultDisplay) emiResultDisplay.textContent = '₹' + monthlyEMI.toLocaleString('en-IN');
        if (principalDisplay) principalDisplay.textContent = '₹' + P.toLocaleString('en-IN');
        if (interestDisplay) interestDisplay.textContent = '₹' + totalInterest.toLocaleString('en-IN');
        if (totalPayableDisplay) totalPayableDisplay.textContent = '₹' + totalPayable.toLocaleString('en-IN');
    }

    if (loanAmtInput && interestRateInput && tenureInput) {
        loanAmtInput.addEventListener('input', calculateEMI);
        interestRateInput.addEventListener('input', calculateEMI);
        tenureInput.addEventListener('input', calculateEMI);
        calculateEMI();
    }
});
