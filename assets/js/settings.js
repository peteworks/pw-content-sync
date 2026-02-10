(function () {
	'use strict';

	var $result = document.getElementById('sf-sync-test-result');
	var $btn = document.getElementById('sf-sync-test-connection');
	if (!$result || !$btn) return;

	$btn.addEventListener('click', function () {
		$result.textContent = '';
		$result.classList.remove('sf-sync-error', 'sf-sync-success');
		$btn.disabled = true;

		// Send current form values so we can test without saving (uses IDs to avoid selector issues).
		var $url = document.getElementById('sf_sync_source_url');
		var $user = document.getElementById('sf_sync_source_username');
		var $pass = document.getElementById('sf_sync_source_app_password');
		var data = new FormData();
		data.append('action', 'sf_sync_test_connection');
		data.append('nonce', typeof sfContentSyncSettings !== 'undefined' ? sfContentSyncSettings.nonce : '');
		if ($url && $url.value) data.append('source_url', $url.value.trim());
		if ($user && $user.value) data.append('source_username', $user.value.trim());
		if ($pass && $pass.value) data.append('source_app_password', $pass.value);
		var ajaxUrl = typeof sfContentSyncSettings !== 'undefined' ? sfContentSyncSettings.ajaxUrl : '';

		fetch(ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		})
			.then(function (res) { return res.json(); })
			.then(function (json) {
				$result.classList.remove('sf-sync-error', 'sf-sync-success');
				if (json.success) {
					$result.textContent = json.data && json.data.message ? json.data.message : 'Connection successful.';
					$result.classList.add('sf-sync-success');
				} else {
					var msg = (json.data && json.data.message) || 'Connection failed.';
					if (json.data && json.data.tried_url) {
						msg += ' Tried: ' + json.data.tried_url;
					}
					$result.textContent = msg;
					$result.classList.add('sf-sync-error');
				}
			})
			.catch(function () {
				$result.textContent = 'Request failed.';
				$result.classList.add('sf-sync-error');
			})
			.finally(function () {
				$btn.disabled = false;
			});
	});
})();
