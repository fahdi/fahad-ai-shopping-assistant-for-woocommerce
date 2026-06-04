/* Fahad AI Shopping Assistant — admin settings provider toggle */
(function () {
	'use strict';

	function toggle(val) {
		var anthropic = document.getElementById('fahad-ai-anthropic');
		var moonshot  = document.getElementById('fahad-ai-moonshot');
		if (anthropic) anthropic.style.display = val === 'anthropic' ? '' : 'none';
		if (moonshot)  moonshot.style.display  = val === 'moonshot'  ? '' : 'none';
	}

	document.addEventListener('DOMContentLoaded', function () {
		var select = document.getElementById('provider');
		if (!select) return;
		select.addEventListener('change', function () {
			toggle(this.value);
		});
	});
})();
