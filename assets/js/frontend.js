/* global storylabData */
(function ($) {
	'use strict';

	var $input = $('#storylab_price');
	if (!$input.length) return;

	var minPrice = parseFloat(storylabData.minPrice) || 0;
	var $form    = $input.closest('form.cart');
	var $error   = $('<p class="storylab-price-error"></p>').insertAfter($input.closest('.storylab-nyp-input-wrap'));

	function validate() {
		var val = parseFloat($input.val());
		if (isNaN(val) || val <= 0) {
			$input.addClass('storylab-error');
			$error.text('Please enter a price.').show();
			return false;
		}
		if (minPrice > 0 && val < minPrice) {
			$input.addClass('storylab-error');
			$error.text(storylabData.minMsg).show();
			return false;
		}
		$input.removeClass('storylab-error');
		$error.hide();
		return true;
	}

	$input.on('input blur', validate);

	if ($form.length) {
		$form.on('submit', function (e) {
			if (!validate()) {
				e.preventDefault();
				$input.focus();
			}
		});
	}

}(jQuery));
