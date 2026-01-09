// assets/js/profile.js
jQuery(document).ready(function($) {
    // Handle conditional field visibility in profile (admin view)
    function updateProfileFieldVisibility() {
        var isLegacyMember = $('#dssa_is_legacy_member').is(':checked') || 
                             ($('#dssa_is_legacy_member').length === 0 && 
                              $('input[name="dssa_exist_member"]').val() === '1');
        
        // For admin profile pages, we show/hide based on legacy status
        if (isLegacyMember) {
            // Show legacy-related fields
            $('.dssa-field-member_number, .dssa-field-branch').closest('tr').show();
            $('.dssa-field-card_payments').closest('tr').hide();
        } else {
            // Show new member fields
            $('.dssa-field-member_number, .dssa-field-branch').closest('tr').hide();
            $('.dssa-field-card_payments').closest('tr').show();
        }
    }
    
    // Initialize on page load
    updateProfileFieldVisibility();
    
    // If there's a legacy member checkbox in admin, watch for changes
    $('#dssa_exist_member').on('change', updateProfileFieldVisibility);
    
    // Make readonly fields visually distinct
    $('input[readonly], select[disabled]').each(function() {
        $(this).css({
            'background-color': '#f5f5f5',
            'border-color': '#ddd',
            'color': '#777'
        });
        
        // Add tooltip for readonly fields
        if ($(this).is('[readonly]') || $(this).is('[disabled]')) {
            $(this).attr('title', 'This field can only be edited by Membership Manager or Administrator');
        }
    });
});