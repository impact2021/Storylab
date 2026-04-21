/* global storylabData */
(function ($) {
	'use strict';

	// -------------------------------------------------------------------------
	// Price validation
	// -------------------------------------------------------------------------

	var $input = $('#storylab_price');
	if (!$input.length) return;

	var minPrice = parseFloat(storylabData.minPrice) || 0;
	var $form    = $input.closest('form.cart');
	var $error   = $('<p class="storylab-price-error"></p>').insertAfter($input.closest('.storylab-nyp-input-wrap'));

	function validatePrice() {
		var val = parseFloat($input.val());
		if (isNaN(val) || val <= 0) {
			$input.addClass('storylab-error');
			$error.text('Please enter a price.').show();
			return false;
		}
		if (minPrice > 0 && val < minPrice) {
			$input.addClass('storylab-error');
			$error.html(storylabData.minMsg).show();
			return false;
		}
		$input.removeClass('storylab-error');
		$error.hide();
		return true;
	}

	$input.on('input blur', validatePrice);

	// -------------------------------------------------------------------------
	// Name-on-ticket fields
	// -------------------------------------------------------------------------

	var $namesWrap = $('#storylab-names-wrap');

	function makeNameRow(n, total) {
		var labelText       = total > 1 ? 'Name on ticket ' + n : 'Name on ticket';
		var placeholderText = total > 1 ? 'Name for ticket ' + n : 'Your name';
		return $(
			'<div class="storylab-name-row">' +
				'<label class="storylab-name-label">' + labelText + '</label>' +
				'<input type="text" name="storylab_ticket_names[]"' +
				       ' class="storylab-name-input"' +
				       ' placeholder="' + placeholderText + '"' +
				       ' required />' +
			'</div>'
		);
	}

	function syncNameFields(qty) {
		if (!$namesWrap.length) return;
		qty = Math.max(1, parseInt(qty) || 1);
		var current = $namesWrap.find('.storylab-name-row').length;

		if (qty > current) {
			for (var i = current + 1; i <= qty; i++) {
				$namesWrap.append(makeNameRow(i, qty));
			}
		} else if (qty < current) {
			$namesWrap.find('.storylab-name-row').slice(qty).remove();
		}

		// Refresh labels/placeholders after add or remove.
		var total = $namesWrap.find('.storylab-name-row').length;
		$namesWrap.find('.storylab-name-row').each(function (idx) {
			var n = idx + 1;
			$(this).find('.storylab-name-label').text(total > 1 ? 'Name on ticket ' + n : 'Name on ticket');
			$(this).find('.storylab-name-input').attr('placeholder', total > 1 ? 'Name for ticket ' + n : 'Your name');
		});
	}

	// Initialise with the current quantity value.
	syncNameFields($('input[name="quantity"]').val() || 1);

	// Keep in sync when the shopper changes quantity.
	$(document).on('change input', 'input[name="quantity"], input.qty', function () {
		syncNameFields($(this).val());
	});

	// -------------------------------------------------------------------------
	// Form submission: validate price + names
	// -------------------------------------------------------------------------

	if ($form.length) {
		$form.on('submit', function (e) {
			var ok = validatePrice();

			$namesWrap.find('.storylab-name-input').each(function () {
				if (!$(this).val().trim()) {
					$(this).addClass('storylab-error');
					ok = false;
				} else {
					$(this).removeClass('storylab-error');
				}
			});

			if (!ok) {
				e.preventDefault();
				if (!validatePrice()) {
					$input.focus();
				}
			}
		});
	}

}(jQuery));
