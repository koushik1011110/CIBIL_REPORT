<?php
$pageTitle = 'Contact';
require_once __DIR__ . '/includes/header.php';
?>

<div class="section-wrapper" style="padding-top: 130px;">
    <div class="section-header">
        <h2>Contact <span>GO4FIN</span></h2>
        <p>Have questions about installment plans, store eligibility, or partnership? Get in touch with us.</p>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px;">
        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 30px;">
            <h3 style="font-size: 1.25rem; font-weight: 800; color: #fff; margin-bottom: 20px;">Corporate Office</h3>
            
            <div style="margin-bottom: 18px; display: flex; gap: 14px;">
                <i class="fa-solid fa-location-dot" style="color: var(--primary); font-size: 1.3rem; margin-top: 4px;"></i>
                <div>
                    <strong style="color: #fff; display: block; font-size: 0.95rem;">Registered Address:</strong>
                    <span style="color: var(--text-muted); font-size: 0.88rem;">Barpeta Road, Near Attis Academy of Excellence, New Manas Road, Domani Gaon, PO Khairabari, Assam - 781315</span>
                </div>
            </div>

            <div style="margin-bottom: 18px; display: flex; gap: 14px;">
                <i class="fa-solid fa-phone" style="color: var(--primary); font-size: 1.3rem; margin-top: 4px;"></i>
                <div>
                    <strong style="color: #fff; display: block; font-size: 0.95rem;">Helpline Phone:</strong>
                    <span style="color: var(--text-muted); font-size: 0.88rem;">+91 60005 47615</span>
                </div>
            </div>

            <div style="margin-bottom: 18px; display: flex; gap: 14px;">
                <i class="fa-solid fa-envelope" style="color: var(--primary); font-size: 1.3rem; margin-top: 4px;"></i>
                <div>
                    <strong style="color: #fff; display: block; font-size: 0.95rem;">Email Support:</strong>
                    <span style="color: var(--text-muted); font-size: 0.88rem;">contact@go4fin.com</span>
                </div>
            </div>


            <div style="display: flex; gap: 14px;">
                <i class="fa-solid fa-clock" style="color: var(--primary); font-size: 1.3rem; margin-top: 4px;"></i>
                <div>
                    <strong style="color: #fff; display: block; font-size: 0.95rem;">Working Hours:</strong>
                    <span style="color: var(--text-muted); font-size: 0.88rem;">Monday – Saturday: 9:00 AM – 7:00 PM</span>
                </div>
            </div>
        </div>

        <form style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 30px;">
            <h3 style="font-size: 1.25rem; font-weight: 800; color: #fff; margin-bottom: 20px;">Send Us a Message</h3>
            
            <div style="margin-bottom: 14px;">
                <label style="display: block; font-weight: 700; font-size: 0.85rem; color: #fff; margin-bottom: 6px;">Your Name</label>
                <input placeholder="Enter full name" style="width: 100%; padding: 10px; background: rgba(15,23,42,0.8); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: #fff;">
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-weight: 700; font-size: 0.85rem; color: #fff; margin-bottom: 6px;">Mobile Number</label>
                <input placeholder="Enter phone number" style="width: 100%; padding: 10px; background: rgba(15,23,42,0.8); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: #fff;">
            </div>

            <div style="margin-bottom: 18px;">
                <label style="display: block; font-weight: 700; font-size: 0.85rem; color: #fff; margin-bottom: 6px;">Message / Inquiry</label>
                <textarea rows="4" placeholder="How can we help you?" style="width: 100%; padding: 10px; background: rgba(15,23,42,0.8); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: #fff;"></textarea>
            </div>

            <button type="submit" class="btn-cta btn-primary" style="width: 100%; justify-content: center;">Send Message</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
