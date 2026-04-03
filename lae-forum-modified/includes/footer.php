    </div><!-- .page-wrapper -->

    <footer class="site-footer">
        <div class="footer-inner">
            <div class="footer-brand">
                <div class="footer-logo">
                    <img src="<?= SITE_URL ?>/public/images/LARPWhite.png" alt="<?= e($siteName ?? SITE_NAME) ?>" class="footer-logo-image">
                </div>
                <p>Your West Coast Roleplay Destination</p>
                <div class="social-links">
                    <a href="<?= e($discordUrl ?? '#') ?>" class="social-btn discord" title="Discord"><i class="fab fa-discord"></i></a>
                    <a href="<?= e($twitterUrl ?? '#') ?>" class="social-btn" title="Twitter"><i class="fab fa-x-twitter"></i></a>
                    <a href="<?= e($youtubeUrl ?? '#') ?>" class="social-btn" title="YouTube"><i class="fab fa-youtube"></i></a>
                    <a href="<?= e($tiktokUrl ?? '#') ?>" class="social-btn" title="TikTok"><i class="fab fa-tiktok"></i></a>
                </div>
            </div>
            <div class="footer-links">
                <div class="footer-col">
                    <h4>Community</h4>
                    <a href="<?= SITE_URL ?>/">Home</a>
                    <a href="<?= SITE_URL ?>/forum.php">Forums</a>
                    <a href="<?= SITE_URL ?>/staff.php">Staff Team</a>
                    <a href="<?= SITE_URL ?>/rules.php">Server Rules</a>
                </div>
                <div class="footer-col">
                    <h4>Support</h4>
                    <a href="<?= SITE_URL ?>/forum.php?cat=bugs">Bug Reports</a>
                    <a href="<?= SITE_URL ?>/forum.php?cat=appeals">Ban Appeals</a>
                    <a href="<?= SITE_URL ?>/forum.php?cat=applications">Applications</a>
                    <a href="<?= SITE_URL ?>/forum.php?cat=suggestions">Suggestions</a>
                </div>
                <div class="footer-col">
                    <h4>Server</h4>
                    <a href="#">Connect to FiveM</a>
                    <a href="#">Server IP</a>
                    <a href="#">Donation Store</a>
                    <a href="#">Patch Notes</a>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> <?= e($siteName ?? SITE_NAME) ?>. All rights reserved. | Built for FiveM Roleplay</p>
            <p>Not affiliated with Rockstar Games or FiveM.</p>
        </div>
    </footer>

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>
</html>
