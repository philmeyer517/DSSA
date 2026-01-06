jQuery(document).ready(function($) {
    /**
     * Assign Number Modal
     */
    $(document).on('click', '.assign-number-btn', function(e) {
        e.preventDefault();
        var userId = $(this).data('user-id');
        var userName = $(this).data('user-name');
        
        // Set form values
        $('#assign-number-user-id').val(userId);
        $('#assign-number-user-name').text(userName);
        $('#membership-number').val(''); // Clear previous value
        
        // Show modal with proper overlay
        $('#dssa-assign-number-modal').fadeIn();
        $('body').addClass('modal-open'); // Prevent scrolling
    });

    /**
     * Assign Branch Modal
     */
    $(document).on('click', '.assign-branch-btn', function(e) {
        e.preventDefault();
        var userId = $(this).data('user-id');
        var userName = $(this).data('user-name');
        
        // Set form values
        $('#assign-branch-user-id').val(userId);
        $('#assign-branch-user-name').text(userName);
        $('#branch-select').val(''); // Clear previous selection
        
        // Show modal with proper overlay
        $('#dssa-assign-branch-modal').fadeIn();
        $('body').addClass('modal-open'); // Prevent scrolling
    });

    /**
     * Close modals
     */
    $(document).on('click', '.dssa-modal-close, .dssa-modal', function(e) {
        if ($(e.target).hasClass('dssa-modal') || $(e.target).hasClass('dssa-modal-close')) {
            $('.dssa-modal').fadeOut();
            $('body').removeClass('modal-open');
        }
    });

    /**
     * Prevent modal close when clicking inside content
     */
    $('.dssa-modal-content').on('click', function(e) {
        e.stopPropagation();
    });

    /**
     * Handle Assign Number form submission
     */
    $('#dssa-assign-number-form').on('submit', function(e) {
        e.preventDefault();
        
        var submitBtn = $(this).find('button[type="submit"]');
        var userId = $('#assign-number-user-id').val();
        var membershipNumber = $('#membership-number').val().trim();
        var nonce = $('#dssa_assign_number_nonce').val();
        
        // Validation
        if (!membershipNumber) {
            alert(dssa_admin_js.strings.enter_number);
            $('#membership-number').focus();
            return false;
        }
        
        // Disable button and show loading
        var originalText = submitBtn.text();
        submitBtn.prop('disabled', true).text(dssa_admin_js.strings.assigning);
        
        // AJAX request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'dssa_assign_membership_number',
                nonce: nonce,
                user_id: userId,
                membership_number: membershipNumber
            },
            success: function(response) {
                if (response.success) {
                    alert(dssa_admin_js.strings.success_number);
                    $('.dssa-modal-close').trigger('click');
                    location.reload(); // Reload page to show changes
                } else {
                    alert(dssa_admin_js.strings.error + ': ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                alert(dssa_admin_js.strings.ajax_error);
            },
            complete: function() {
                submitBtn.prop('disabled', false).text(originalText);
            }
        });
    });

    /**
     * Handle Assign Branch form submission
     */
    $('#dssa-assign-branch-form').on('submit', function(e) {
        e.preventDefault();
        
        var submitBtn = $(this).find('button[type="submit"]');
        var userId = $('#assign-branch-user-id').val();
        var branch = $('#branch-select').val();
        var nonce = $('#dssa_assign_branch_nonce').val();
        
        // Validation
        if (!branch) {
            alert(dssa_admin_js.strings.select_branch);
            $('#branch-select').focus();
            return false;
        }
        
        // Disable button and show loading
        var originalText = submitBtn.text();
        submitBtn.prop('disabled', true).text(dssa_admin_js.strings.assigning);
        
        // AJAX request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'dssa_assign_branch',
                nonce: nonce,
                user_id: userId,
                branch: branch
            },
            success: function(response) {
                if (response.success) {
                    alert(dssa_admin_js.strings.success_branch);
                    $('.dssa-modal-close').trigger('click');
                    location.reload(); // Reload page to show changes
                } else {
                    alert(dssa_admin_js.strings.error + ': ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                alert(dssa_admin_js.strings.ajax_error);
            },
            complete: function() {
                submitBtn.prop('disabled', false).text(originalText);
            }
        });
    });

    /**
     * Improve UX: Close modal with Escape key
     */
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('.dssa-modal:visible').length) {
            $('.dssa-modal-close').trigger('click');
        }
    });
});