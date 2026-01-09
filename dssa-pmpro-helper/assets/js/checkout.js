(function ($) {

    function updateFieldVisibility() {

        const $existMember = $('#exist_member');

        /* if (!$existMember.length) {
            console.warn('exist_member checkbox not found');
            return;
        } */

        const isExisting = $existMember.is(':checked');

        const $memberNumberDiv = $('#member_number_div');
        const $branchDiv       = $('#branch_div');
        const $cardPaymentsDiv = $('#card_payments_div');

        const $memberNumber = $('#member_number');
        const $branch       = $('#branch');
        const $cardPayments = $('#card_payments');

        if (isExisting) {
            // Existing member
            $memberNumberDiv.show();
            $branchDiv.show();
            $cardPaymentsDiv.hide();

            $memberNumber.prop('required', true);
            $branch.prop('required', true);
            $cardPayments.prop('required', false).prop('checked', false);
        } else {
            // New member
            $memberNumberDiv.hide();
            $branchDiv.hide();
            $cardPaymentsDiv.show();

            $memberNumber.prop('required', false).val('');
            $branch.prop('required', false).val('');
            $cardPayments.prop('required', true);
        }
    }

    // 🔁 PMPro replaces the checkout DOM → delegated + rebind
    $(document).on('change', '#exist_member', updateFieldVisibility);
    $(document).on('pmpro_checkout_loaded', updateFieldVisibility);

    // Initial run (after DOM ready)
    $(document).ready(updateFieldVisibility);

})(jQuery);
