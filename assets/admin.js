(function () {
	'use strict';

	// Confirm dangerous actions.
	document.addEventListener('click', function (e) {
		var el = e.target.closest('.icp-confirm');
		if (el && !window.confirm(el.getAttribute('data-msg') || 'Are you sure?')) {
			e.preventDefault();
		}
	});

	// Status tester.
	var buttons = document.querySelectorAll('.icp-test');
	if (!buttons.length || typeof ICP === 'undefined') { return; }
	var box = document.querySelector('.icp-progress');
	var bar = box ? box.querySelector('.icp-bar span') : null;
	var txt = box ? box.querySelector('.icp-progress-text') : null;

	function run(scope) {
		var after = 0, done = 0, total = 0, tally = { ok: 0, bad: 0, blocked: 0 };
		buttons.forEach(function (b) { b.disabled = true; });
		box.hidden = false;
		txt.textContent = 'Starting…';
		bar.style.width = '0%';

		function step() {
			var body = new URLSearchParams();
			body.append('action', 'icp_test_batch');
			body.append('nonce', ICP.nonce);
			body.append('after', after);
			body.append('scope', scope);
			fetch(ICP.ajax, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res || !res.success) { throw new Error('Request failed'); }
					var d = res.data;
					if (!total) { total = d.total; }
					after = d.after;
					done += d.count;
					tally.ok += d.results.ok;
					tally.bad += d.results.bad;
					tally.blocked += d.results.blocked;
					var pct = total ? Math.min(100, Math.round(100 * done / total)) : 100;
					bar.style.width = pct + '%';
					txt.textContent = done + ' / ' + total + ' tested — ' + tally.ok + ' OK (410), ' + tally.bad + ' issues, ' + tally.blocked + ' blocked by host';
					if (d.done) {
						txt.textContent += '. Done — see the URLs tab (filter "Test failing") for details.';
						buttons.forEach(function (b) { b.disabled = false; });
					} else {
						step();
					}
				})
				.catch(function (err) {
					txt.textContent = 'Stopped: ' + err.message + '. Reload and try again.';
					buttons.forEach(function (b) { b.disabled = false; });
				});
		}
		step();
	}

	buttons.forEach(function (b) {
		b.addEventListener('click', function () { run(b.getAttribute('data-scope')); });
	});
})();
