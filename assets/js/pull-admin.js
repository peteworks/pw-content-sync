(function () {
	'use strict';

	var config = typeof sfContentSyncPull !== 'undefined' ? sfContentSyncPull : {};
	var ajaxUrl = config.ajaxUrl || '';
	var nonce = config.nonce || '';
	var postId = config.postId || 0;
	var postType = config.postType || 'post';
	var i18n = config.i18n || {};

	var $source = document.getElementById('sf-sync-source-page');
	var $btn = document.getElementById('sf-sync-pull-button');
	var $result = document.getElementById('sf-sync-pull-result');
	if (!$source || !$btn || !$result) return;

	$btn.addEventListener('click', function () {
		var sourcePage = $source.value.trim();
		if (!sourcePage) {
			$result.textContent = (i18n.error || 'Error:') + ' Enter a source page ID or slug.';
			$result.className = 'sf-sync-result sf-sync-error';
			return;
		}

		$btn.disabled = true;
		$result.textContent = i18n.loading || 'Pulling…';
		$result.className = 'sf-sync-result';

		var data = new FormData();
		data.append('action', 'sf_sync_pull_page');
		data.append('nonce', nonce);
		data.append('dest_post_id', String(postId));
		data.append('source_page', sourcePage);
		data.append('post_type', postType);

		fetch(ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		})
			.then(function (res) { return res.json(); })
			.then(function (json) {
				$result.classList.remove('sf-sync-error', 'sf-sync-success');
				if (json.success) {
					$result.textContent = (json.data && json.data.message) || (i18n.success || 'Content pulled successfully.');
					$result.classList.add('sf-sync-success');
					setTimeout(function () {
						window.location.reload();
					}, 800);
				} else {
					var msg = (json.data && json.data.message) || 'Unknown error';
					if (json.data && json.data.tried_url) {
						msg += ' Tried: ' + json.data.tried_url;
					}
					$result.textContent = (i18n.error || 'Error:') + ' ' + msg;
					$result.classList.add('sf-sync-error');
				}
			})
			.catch(function () {
				$result.textContent = (i18n.error || 'Error:') + ' Request failed.';
				$result.classList.add('sf-sync-error');
			})
			.finally(function () {
				$btn.disabled = false;
			});
	});
})();
