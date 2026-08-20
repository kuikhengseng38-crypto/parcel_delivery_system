<?php
/**
 * Shared HTML Footer
 *
 * Closes tags opened by header.php and loads shared JavaScript.
 * Page-specific scripts should be set in $extraScripts[] before including.
 */

$extraScripts = $extraScripts ?? [];
?>
    </main><!-- /.page-content -->
</div><!-- /.main-wrapper -->

<!-- =========================================================
     Toast Notification Container
     ========================================================= -->
<div id="toastContainer" class="toast-container" aria-live="polite"></div>

<!-- Leaflet JS (only on map pages) -->
<?php if (!empty($usesMap)): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>

<!-- Core application script -->
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>

<!-- Page-specific scripts -->
<?php foreach ($extraScripts as $src): ?>
<?php $version = is_file(__DIR__ . '/..' . $src) ? filemtime(__DIR__ . '/..' . $src) : time(); ?>
<script src="<?= BASE_URL . e($src) ?>?v=<?= $version ?>"></script>
<?php endforeach; ?>

<!-- Initialise Feather icons -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof feather !== 'undefined') {
            feather.replace({ 'stroke-width': 1.75 });
        }
    });
</script>

</body>
</html>
