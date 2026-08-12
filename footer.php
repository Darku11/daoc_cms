<?php
// SPDX-License-Identifier: GPL-3.0-only
?>
<?php echo cms_run_hook('hook_footer'); ?>
<footer class="main-footer">
    <div class="footer-realm-bar" aria-hidden="true"></div>
    <div class="footer-container">
        <div class="status-bar">
            <p>
                <a href="https://aldhran-server.eu" class="footer-link">DAoC CMS 1.0 RC2 created by Aldhran</a><br />
                <?php if (($GLOBALS['cms_settings']['mod_imprint'] ?? '1') === '1'): ?>
                    <a href="index.php?p=imprint" class="footer-link">Imprint</a>
                <?php endif; ?>
            </p>
        </div>
    </div>
</footer>
</div><script>
document.addEventListener('DOMContentLoaded', function() {
    const nav = document.querySelector('.sidebar-nav-container');
    if (window.innerWidth <= 768 && nav) {
        setTimeout(() => {
            nav.scrollTo({ left: 40, behavior: 'smooth' });
            setTimeout(() => { nav.scrollTo({ left: 0, behavior: 'smooth' }); }, 600);
        }, 800);
    }
});
</script>
</body>
</html>
