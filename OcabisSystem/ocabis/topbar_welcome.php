<?php
if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

$__welcomeUser = isset($_SESSION['username']) ? $_SESSION['username'] : null;
if ($__welcomeUser):
?>
<script>
(function(){
	function injectWelcome(){
		if (document.getElementById('ocabis-welcome-banner')) return;
		var d = document.createElement('div');
		d.id = 'ocabis-welcome-banner';
		d.textContent = 'Welcome, <?php echo htmlspecialchars($__welcomeUser, ENT_QUOTES, 'UTF-8'); ?>';
		d.style.position = 'fixed';
		d.style.top = '12px';
		d.style.right = '12px';
		d.style.zIndex = '2147483000';
		d.style.background = 'rgba(255,255,255,0.95)';
		d.style.border = '1px solid #e5e7eb';
		d.style.borderRadius = '999px';
		d.style.boxShadow = '0 6px 20px rgba(0,0,0,0.08)';
		d.style.padding = '8px 14px';
		d.style.fontFamily = 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
		d.style.fontSize = '14px';
		d.style.color = '#111827';
		d.style.pointerEvents = 'none';
		d.style.userSelect = 'none';
		d.style.letterSpacing = '0.2px';
		d.style.backdropFilter = 'saturate(180%) blur(6px)';
		d.style.webkitBackdropFilter = 'saturate(180%) blur(6px)';
		d.style.transition = 'opacity 200ms ease';
		d.style.opacity = '0.98';
		d.setAttribute('aria-hidden', 'true');
		document.body.appendChild(d);
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', injectWelcome);
	} else {
		injectWelcome();
	}
})();
</script>
<?php endif; ?>


