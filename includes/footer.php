    <!-- FOOTER -->
    <footer>
        <div class="footer-container">
            <!-- COL 1: BRAND -->
            <div class="footer-col">
                <div class="brand-link" style="margin-bottom: 16px;">
                    <img src="assets/images/logo.png" alt="Go4 Finance" style="height: 44px; width: 44px; border-radius: 50%; object-fit: cover; box-shadow: 0 4px 12px rgba(0,0,0,0.15); background: #fff;">
                    <div class="brand-title-box">
                        <span class="brand-title-main">GO4<span>FIN</span></span>
                        <span class="brand-title-sub">Go4 Finance Pvt. Ltd.</span>
                    </div>
                </div>
                <p style="color: var(--text-muted); font-size: 0.88rem; line-height: 1.6; margin-bottom: 14px;">
                    Empowering store purchases with affordable EMI options and zero cash-lending model. Buy your favorite mobiles, ACs, refrigerators & smart TVs upfront with low down payment and flexible monthly repayments.
                </p>
                <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.25); padding: 6px 12px; border-radius: 6px; font-size: 0.8rem; color: #93c5fd;">
                    <i class="fa-solid fa-file-shield" style="color: var(--primary);"></i>
                    <span>Govt. Regd. TAN: <strong style="color: #fff; font-family: monospace; letter-spacing: 0.5px;"><?=e(get_setting('cms_tan_no', 'SHLG03876F'))?></strong></span>
                </div>
            </div>

            <!-- COL 2: QUICK LINKS -->
            <div class="footer-col">
                <h4>Quick Navigation</h4>
                <ul class="footer-links">
                    <li><a href="index.php"><i class="fa-solid fa-chevron-right"></i> Home</a></li>
                    <li><a href="services.php"><i class="fa-solid fa-chevron-right"></i> Products & Services</a></li>
                    <li><a href="calculator.php"><i class="fa-solid fa-chevron-right"></i> EMI Calculator</a></li>
                    <li><a href="about.php"><i class="fa-solid fa-chevron-right"></i> About Company</a></li>
                    <li><a href="contact.php"><i class="fa-solid fa-chevron-right"></i> Contact Us</a></li>
                    <li><a href="apply.php"><i class="fa-solid fa-chevron-right"></i> Apply Installment</a></li>
                </ul>
            </div>

            <!-- COL 3: LEGAL & POLICIES -->
            <div class="footer-col">
                <h4>Legal & Policies</h4>
                <ul class="footer-links">
                    <li><a href="terms.php"><i class="fa-solid fa-file-contract"></i> Terms & Conditions</a></li>
                    <li><a href="privacy.php"><i class="fa-solid fa-user-shield"></i> Privacy Policy</a></li>
                    <li><a href="refund-policy.php"><i class="fa-solid fa-rotate-left"></i> Refund & Cancellation</a></li>
                    <li><a href="contact.php"><i class="fa-solid fa-headset"></i> Contact & Support</a></li>
                    <li><a href="soft/login.php"><i class="fa-solid fa-right-to-bracket"></i> Merchant Portal Login</a></li>
                </ul>
            </div>

            <!-- COL 4: CONTACT INFO -->
            <div class="footer-col">
                <h4>Contact Info</h4>
                <ul class="footer-links">
                    <li><i class="fa-solid fa-phone" style="color:var(--primary); margin-right: 8px;"></i> <?=e(get_setting('cms_phone', '+91 60005 47615'))?></li>
                    <li><i class="fa-solid fa-envelope" style="color:var(--primary); margin-right: 8px;"></i> <?=e(get_setting('cms_email', 'contact@go4fin.com'))?></li>
                    <li><i class="fa-solid fa-location-dot" style="color:var(--primary); margin-right: 8px;"></i> <?=e(get_setting('cms_address', 'Barpeta Road, New Manas Road, Assam - 781315'))?></li>
                    <li><i class="fa-solid fa-clock" style="color:var(--primary); margin-right: 8px;"></i> <?=e(get_setting('cms_hours', 'Mon–Sat: 9:00 AM – 7:00 PM'))?></li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div>© <?=date('Y')?> GO4FIN (Go4 Finance Private Limited). All Rights Reserved.</div>
            <div style="display:flex; gap:16px; font-size:0.82rem;">
                <a href="terms.php" style="color:var(--text-muted);">Terms & Conditions</a>
                <a href="privacy.php" style="color:var(--text-muted);">Privacy Policy</a>
                <a href="refund-policy.php" style="color:var(--text-muted);">Refund Policy</a>
                <a href="contact.php" style="color:var(--text-muted);">Contact Us</a>
            </div>
        </div>
    </footer>

    <!-- WHATSAPP FLOATING BUTTON -->
    <a href="https://wa.me/<?=preg_replace('/\D/','',get_setting('cms_whatsapp','916000547615'))?>?text=Hello%20GO4FIN%2C%20I%20want%20to%20apply%20for%20product%20financing." class="wa-float" target="_blank" rel="noopener" aria-label="Chat on WhatsApp">
        <i class="fa-brands fa-whatsapp"></i>
    </a>

    <script src="assets/js/main.js"></script>
</body>
</html>
