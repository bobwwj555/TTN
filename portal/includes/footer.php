<?php
/**
 * TTN Shared Footer
 * LOCATION: /home/obdswlpx/dev.ttn.radio/includes/footer.php
 */
$_site_url = s('site_url', 'https://dev.ttn.radio');
?>
<footer>
    <span>TTN · <?= htmlspecialchars(s('org_name')) ?> · <?= htmlspecialchars(s('org_callsign')) ?> · EIN <?= htmlspecialchars(s('org_ein')) ?> · TN <?= htmlspecialchars(s('org_nonprofit')) ?></span>
    <span>
        <a href="<?= $_site_url ?>/">ttn.radio</a> ·
        <a href="mailto:<?= htmlspecialchars(s('contact_email')) ?>"><?= htmlspecialchars(s('contact_email')) ?></a> ·
        <?= htmlspecialchars(s('contact_phone')) ?>
    </span>
    <span>
        <?php if (s('social_facebook')): ?><a href="<?= htmlspecialchars(s('social_facebook')) ?>" target="_blank">Facebook</a> · <?php endif; ?>
        <?php if (s('social_github')): ?><a href="<?= htmlspecialchars(s('social_github')) ?>" target="_blank">GitHub</a> · <?php endif; ?>
        <a href="<?= $_site_url ?>/donate/">Donate</a>
    </span>
</footer>
</body>
</html>
