/* global DSSA_Admin */

console.log('DSSA admin.js loaded');

jQuery(function ($) {

    console.log('DSSA DOM ready');
	
	const dssaDebounceTimers = {};
	
	function saveField($span, field, value) {

		const userId = $span.data('user-id');
		if (!userId) {
			return;
		}

		const key = userId + ':' + field;

		// Store previous value for rollback
		const previousValue = $span.data('previous-value');
		const previousText  = $span.data('previous-text');

		// Clear existing debounce timer
		if (dssaDebounceTimers[key]) {
			clearTimeout(dssaDebounceTimers[key]);
		}

		dssaDebounceTimers[key] = setTimeout(function () {

			$span
				.removeClass('is-success is-error')
				.addClass('is-saving');

			$.post(dssa_admin_js.ajax_url, {
				action: 'dssa_assign_member_field',
				nonce: dssa_admin_js.nonce,
				user_id: userId,
				field: field,
				value: value
			})
			.done(function (response) {

				if (response && response.success) {

					$span
						.removeClass('is-saving')
						.addClass('is-success')
						.attr('title', 'Saved');

					setTimeout(function () {
						$span.removeClass('is-success');
					}, 1200);

				} else {
					revertField($span, previousText);
				}
			})
			.fail(function () {
				revertField($span, previousText);
			});

		}, 350); // debounce delay
	}
	
	function revertField($span, text) {

		$span
			.removeClass('is-saving')
			.addClass('is-error')
			.text(text)
			.attr('title', 'Save failed – reverted');

		setTimeout(function () {
			$span.removeClass('is-error');
		}, 2000);
	}

    $(document).on('click', '.dssa-inline-edit', function () {

        const $span = $(this);

        // Prevent double activation
        if ($span.hasClass('editing')) {
            return;
        }

        $span.addClass('editing');
		$span.data('previous-text', $span.text().trim());

        const field = $span.data('field');
        const currentValue = $span.text().trim();
        const isEmpty = currentValue === '— Click to assign —';

        /**
         * Branch field → dropdown
         */
        if (field === 'branch' && typeof dssa_admin_js !== 'undefined') {

            const select = $('<select>', {
                class: 'dssa-inline-select',
                css: {
                    width: '100%',
                    boxSizing: 'border-box'
                }
            });

            select.append(
                $('<option>', {
                    value: '',
                    text: '— Select branch —'
                })
            );

            const options = dssa_admin_js.branch_options || {};

            $.each(options, function (value, label) {

                const option = $('<option>', {
                    value: value,
                    text: label
                });

                if (label === currentValue || value === currentValue) {
                    option.prop('selected', true);
                }

                select.append(option);
            });

            $span.empty().append(select);
            select.focus();

			const commitValue = function () {

				const selectedOption = select.find('option:selected');
				const selectedText  = selectedOption.text();
				const selectedValue = selectedOption.val();

				$span
					.removeClass('editing')
					.text(newValue || '— Click to assign —');

				saveField($span, field, newValue);
			};

            select.on('change blur', commitValue);
            return;
        }

        /**
         * Default → text input (existing behaviour)
         */
        const input = $('<input>', {
            type: 'text',
            class: 'dssa-inline-input',
            val: isEmpty ? '' : currentValue,
            css: {
                width: '100%',
                boxSizing: 'border-box'
            }
        });

        $span.empty().append(input);
        input.focus();

        input.on('blur keydown', function (e) {

            if (e.type === 'keydown' && e.key !== 'Enter') {
                return;
            }

            const newValue = input.val().trim();

            $span
                .removeClass('editing')
                .text(newValue || '— Click to assign —');
        });
    });
});
