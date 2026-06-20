<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
	var focusStateKey = 'adminLiveFilterFocusState';
	var saveFocusState = function (payload) {
		try {
			sessionStorage.setItem(focusStateKey, JSON.stringify(payload));
		} catch (e) {
			// Ignore storage errors in strict browser modes.
		}
	};

	var readFocusState = function () {
		try {
			var raw = sessionStorage.getItem(focusStateKey);
			if (!raw) {
				return null;
			}
			sessionStorage.removeItem(focusStateKey);
			return JSON.parse(raw);
		} catch (e) {
			return null;
		}
	};

	var focusState = readFocusState();
	if (focusState && focusState.fieldName && focusState.path === window.location.pathname) {
		var escapedName = (window.CSS && typeof window.CSS.escape === 'function') ? window.CSS.escape(focusState.fieldName) : focusState.fieldName.replace(/"/g, '\\"');
		var targetField = document.querySelector('form[data-live-filter="true"][method="get" i] [name="' + escapedName + '"]');
		if (targetField && typeof targetField.focus === 'function') {
			targetField.focus({ preventScroll: true });
			if (typeof targetField.setSelectionRange === 'function') {
				var cursor = Number.isInteger(focusState.cursor) ? focusState.cursor : String(targetField.value || '').length;
				targetField.setSelectionRange(cursor, cursor);
			}
		}
	}

	var forms = document.querySelectorAll('form[data-live-filter="true"][method="get" i]');
	forms.forEach(function (form) {
		var debounceTimer = null;
		var scheduleSubmit = function (delay, target) {
			window.clearTimeout(debounceTimer);
			debounceTimer = window.setTimeout(function () {
				if (!form.checkValidity()) {
					return;
				}
				if (target && target.name) {
					var cursorPos = (typeof target.selectionStart === 'number') ? target.selectionStart : String(target.value || '').length;
					saveFocusState({
						path: window.location.pathname,
						fieldName: target.name,
						cursor: cursorPos,
					});
				}
				form.requestSubmit();
			}, delay);
		};

		form.addEventListener('input', function (event) {
			var target = event.target;
			if (!target || typeof target.matches !== 'function') {
				return;
			}
			if (target.matches('input[type="text"], input[type="search"], input[type="number"], textarea')) {
				scheduleSubmit(350, target);
			}
		});

		form.addEventListener('change', function (event) {
			var target = event.target;
			if (!target || typeof target.matches !== 'function') {
				return;
			}
			if (target.matches('select, input[type="checkbox"], input[type="radio"], input[type="date"], input[type="datetime-local"], input[type="month"], input[type="week"]')) {
				scheduleSubmit(100, target);
			}
		});
	});
});
</script>
</body>
</html>
