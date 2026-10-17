(function () {
	'use strict';

	/**
	 * Single global timer ID for updating all visible countdown timers.
	 */
	var globalTimerInterval = null;

	/**
	 * Pads single digits with leading zero.
	 */
	function padZero(num) {
		return num < 10 ? '0' + num : String(num);
	}

	/**
	 * Updates the timer header label.
	 */
	function updateTimerTitle(wrapper, title) {
		if (!wrapper) {
			return;
		}
		var titleEl = wrapper.querySelector('.mppw-countdown-title');
		if (titleEl && title) {
			titleEl.textContent = title;
		}
	}

	/**
	 * Updates an individual countdown wrapper.
	 */
	function updateTimer(wrapper, now) {
		var expireTimestamp = parseInt(wrapper.getAttribute('data-expire'), 10);
		if (!expireTimestamp || isNaN(expireTimestamp)) {
			wrapper.style.display = 'none';
			return;
		}

		var diff = expireTimestamp - now;

		if (diff <= 0) {
			wrapper.style.display = 'none';
			// If on single product page and main timer expires, refresh after 1 second
			if (!wrapper.classList.contains('mppw-timer-loop')) {
				setTimeout(function () {
					window.location.reload();
				}, 1000);
			}
			return;
		}

		wrapper.style.display = '';

		var days = Math.floor(diff / 86400);
		var hours = Math.floor((diff % 86400) / 3600);
		var mins = Math.floor((diff % 3600) / 60);
		var secs = Math.floor(diff % 60);

		var daysEl = wrapper.querySelector('[data-unit="days"]');
		var hoursEl = wrapper.querySelector('[data-unit="hours"]');
		var minsEl = wrapper.querySelector('[data-unit="mins"]');
		var secsEl = wrapper.querySelector('[data-unit="secs"]');

		if (daysEl) daysEl.textContent = padZero(days);
		if (hoursEl) hoursEl.textContent = padZero(hours);
		if (minsEl) minsEl.textContent = padZero(mins);
		if (secsEl) secsEl.textContent = padZero(secs);
	}

	/**
	 * Global tick function that runs every second across all wrappers.
	 */
	function tick() {
		var wrappers = document.querySelectorAll('.mppw-countdown-wrapper');
		if (!wrappers.length) {
			return;
		}
		var now = Math.floor(Date.now() / 1000);
		for (var i = 0; i < wrappers.length; i++) {
			updateTimer(wrappers[i], now);
		}
	}

	/**
	 * Starts the global tick interval if not already running.
	 */
	function ensureGlobalInterval() {
		if (!globalTimerInterval) {
			tick();
			globalTimerInterval = setInterval(tick, 1000);
		}
	}

	/**
	 * Handles variable products changes on single product pages.
	 */
	function bindVariationEvents(wrapper) {
		if (!wrapper || wrapper.classList.contains('mppw-timer-loop')) {
			return;
		}

		var form = document.querySelector('form.variations_form');
		if (!form || typeof jQuery === 'undefined') {
			return;
		}

		jQuery(form).on('found_variation', function (event, variation) {
			if (variation && variation.mppw_expire_timestamp) {
				wrapper.setAttribute('data-expire', variation.mppw_expire_timestamp);
				if (variation.mppw_countdown_title) {
					updateTimerTitle(wrapper, variation.mppw_countdown_title);
				}
				tick();
			} else {
				wrapper.setAttribute('data-expire', '');
				wrapper.style.display = 'none';
			}
		});

		jQuery(form).on('reset_data', function () {
			var defaultExpire = wrapper.getAttribute('data-default-expire') || '';
			var defaultTitle  = wrapper.getAttribute('data-default-title') || '';

			wrapper.setAttribute('data-expire', defaultExpire);
			if (defaultTitle) {
				updateTimerTitle(wrapper, defaultTitle);
			}

			if (defaultExpire) {
				tick();
			} else {
				wrapper.style.display = 'none';
			}
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var wrappers = document.querySelectorAll('.mppw-countdown-wrapper');
		wrappers.forEach(function (wrapper) {
			bindVariationEvents(wrapper);
		});
		ensureGlobalInterval();
	});
})();
