/* Fahad AI Shopping Assistant — admin settings provider toggle */
(function () {
	'use strict';

	// Each provider's fields live in a <tbody id="fahad-ai-{provider}">. Show only the
	// selected provider's block and hide the rest. Catalog-driven: any number of
	// providers (including filter-registered add-ons) is handled with no JS edits.
	function toggle(val) {
		var blocks = document.querySelectorAll('[id^="fahad-ai-"]');
		for (var i = 0; i < blocks.length; i++) {
			var el = blocks[i];
			// Only act on the per-provider field groups (TBODY), not unrelated ids.
			if (el.tagName !== 'TBODY') {
				continue;
			}
			el.style.display = el.id === 'fahad-ai-' + val ? '' : 'none';
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var select = document.getElementById('provider');
		if (!select) return;
		toggle(select.value);
		select.addEventListener('change', function () {
			toggle(this.value);
		});
	});
})();
