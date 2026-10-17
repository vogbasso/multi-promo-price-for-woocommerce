(function () {
	'use strict';

	/**
	 * Pads single digits with leading zero.
	 */
	function padZero(num) {
		return num < 10 ? '0' + num : num;
	}

	/**
	 * Initializes timer element.
	 */
	function initTimer(wrapper) {
		if (!wrapper) {
			return;
		}

		var daysEl = wrapper.querySelector('[data-unit="days"]');
		var hoursEl = wrapper.querySelector('[data-unit="hours"]');
		var minsEl = wrapper.querySelector('[data-unit="mins"]');
		var secsEl = wrapper.querySelector('[data-unit="secs"]');

		var intervalId = null;

		function update() {
			var expireTimestamp = parseInt(wrapper.getAttribute('data-expire'), 10);
			if (!expireTimestamp || isNaN(expireTimestamp)) {
				wrapper.style.display = 'none';
				if (intervalId) {
					clearInterval(intervalId);
				}
				return;
			}

			// Current Unix timestamp in seconds
			var now = Math.floor(Date.now() / 1000);
			var diff = expireTimestamp - now;

			if (diff <= 0) {
				wrapper.style.display = 'none';
				if (intervalId) {
					clearInterval(intervalId);
				}
				// Reload the page smoothly when promotion expires
				setTimeout(function () {
					window.location.reload();
				}, 1000);
				return;
			}

			wrapper.style.display = '';

			var days = Math.floor(diff / 86400);
			var hours = Math.floor((diff % 86400) / 3600);
			var mins = Math.floor((diff % 3600) / 60);
			var secs = Math.floor(diff % 60);

			if (daysEl) daysEl.textContent = padZero(days);
			if (hoursEl) hoursEl.textContent = padZero(hours);
			if (minsEl) minsEl.textContent = padZero(mins);
			if (secsEl) secsEl.textContent = padZero(secs);
		}

		update();
		intervalId = setInterval(update, 1000);
		wrapper._mppwIntervalId = intervalId;
	}

	/**
	 * Handles variable products changes.
	 */
	function bindVariationEvents(wrapper) {
		if (!wrapper) {
			return;
		}

		var form = document.querySelector('form.variations_form');
		if (!form || typeof jQuery === 'undefined') {
			return;
		}

		jQuery(form).on('found_variation', function (event, variation) {
			if (variation && variation.mppw_expire_timestamp) {
				wrapper.setAttribute('data-expire', variation.mppw_expire_timestamp);
				initTimer(wrapper);
			} else {
				wrapper.setAttribute('data-expire', '');
				wrapper.style.display = 'none';
			}
		});

		jQuery(form).on('reset_data', function () {
			var defaultExpire = wrapper.getAttribute('data-default-expire') || '';
			wrapper.setAttribute('data-expire', defaultExpire);
			if (defaultExpire) {
				initTimer(wrapper);
			} else {
				wrapper.style.display = 'none';
			}
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var wrappers = document.querySelectorAll('.mppw-countdown-wrapper');
		wrappers.forEach(function (wrapper) {
			initTimer(wrapper);
			bindVariationEvents(wrapper);
		});
	});
})();
