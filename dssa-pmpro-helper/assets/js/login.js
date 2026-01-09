/* 
 * DSSA PMPro Helper - Login System JavaScript
 */

jQuery(document).ready(function($) {
    
    // Password show/hide toggle
    $('.pmpro_show_password').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var input = button.siblings('input');
        var icon = button.find('.dashicons');
        
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
            button.attr('aria-label', dssa_login.strings.hide || 'Hide password');
        } else {
            input.attr('type', 'password');
            icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
            button.attr('aria-label', dssa_login.strings.show || 'Show password');
        }
    });
    
    // Login form submission
    $('#dssa-login-form').on('submit', function(e) {
        var form = $(this);
        var submitBtn = $('#dssa-login-submit');
        var membershipNumber = $('#dssa_membership_number').val().trim();
        var password = $('#dssa_password').val();
        
        // Basic validation
        if (!membershipNumber) {
            alert('Please enter your membership number.');
            $('#dssa_membership_number').focus();
            return false;
        }
        
        if (!password) {
            alert('Please enter your password.');
            $('#dssa_password').focus();
            return false;
        }
        
        // Show loading state
        var originalText = submitBtn.text();
        submitBtn.addClass('loading')
                 .prop('disabled', true)
                 .text(dssa_login.strings.loading || 'Logging in...');
        
        // Form is valid, allow submission
        return true;
    });
    
    // Password reset form submission
    $('#dssa-reset-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var submitBtn = $('#dssa-reset-submit');
        var messageDiv = $('#dssa-reset-message');
        var membershipNumber = $('#dssa_reset_membership_number').val().trim();
        
        // Basic validation
        if (!membershipNumber) {
            messageDiv.removeClass('pmpro_success')
                      .addClass('pmpro_error')
                      .text('Please enter your membership number.')
                      .show();
            return false;
        }
        
        // Show loading state
        var originalText = submitBtn.text();
        submitBtn.addClass('loading')
                 .prop('disabled', true)
                 .text(dssa_login.strings.loading || 'Processing...');
        
        // Hide any previous messages
        messageDiv.hide().empty();
        
        // AJAX request
        $.ajax({
            url: dssa_login.ajax_url,
            type: 'POST',
            data: {
                action: 'dssa_reset_password',
                nonce: dssa_login.nonce,
                membership_number: membershipNumber
            },
            success: function(response) {
                if (response.success) {
                    // Success message
                    messageDiv.removeClass('pmpro_error')
                              .addClass('pmpro_success')
                              .text(response.data)
                              .show();
                    
                    // Reset form
                    form[0].reset();
                    
                    // Auto-hide success message after 10 seconds
                    setTimeout(function() {
                        messageDiv.fadeOut();
                    }, 10000);
                    
                } else {
                    // Error message
                    messageDiv.removeClass('pmpro_success')
                              .addClass('pmpro_error')
                              .text(response.data)
                              .show();
                }
            },
            error: function(xhr, status, error) {
                console.error('Reset password error:', error);
                messageDiv.removeClass('pmpro_success')
                          .addClass('pmpro_error')
                          .text(dssa_login.strings.error || 'An error occurred. Please try again.')
                          .show();
            },
            complete: function() {
                // Reset button state
                submitBtn.removeClass('loading')
                         .prop('disabled', false)
                         .text(originalText);
            }
        });
    });
    
    // Form validation styling on blur
    $('.pmpro_form .input').on('blur', function() {
        var input = $(this);
        var isRequired = input.hasClass('pmpro_required');
        var isEmpty = !input.val().trim();
        
        if (isRequired && isEmpty) {
            input.addClass('pmpro_invalid');
        } else {
            input.removeClass('pmpro_invalid');
        }
    });
    
    // Real-time validation for required fields
    $('.pmpro_required').on('input', function() {
        var input = $(this);
        if (input.val().trim()) {
            input.removeClass('pmpro_invalid');
        }
    });
    
    // Auto-focus first field on page load
    if ($('#dssa_membership_number').length) {
        $('#dssa_membership_number').focus();
    } else if ($('#dssa_reset_membership_number').length) {
        $('#dssa_reset_membership_number').focus();
    }
    
    // Add CSS for invalid fields
    if (!$('style#dssa-invalid-styles').length) {
        $('<style id="dssa-invalid-styles">')
            .text('.pmpro_invalid { border-color: #e74c3c !important; } .pmpro_invalid:focus { box-shadow: 0 0 0 3px rgba(231, 76, 60, 0.1) !important; }')
            .appendTo('head');
    }
});