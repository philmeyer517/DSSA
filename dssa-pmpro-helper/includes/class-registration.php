<?php
// ... file header and other methods unchanged ...

// Example excerpts where keys were standardized (ensure you replace the full file in repo)

        // No payment needed for legacy members
        update_user_meta($user_id, 'dssa_payment_status', 'not_required');
        
        // Get membership number (standardized key)
        $member_number = get_user_meta($user_id, 'dssa_membership_number', true);
        
        // Legacy members don't need number assignment
        update_user_meta($user_id, 'dssa_number_assigned', 'legacy');
        update_user_meta($user_id, 'dssa_number_assigned_by', 'system');
        update_user_meta($user_id, 'dssa_number_assigned_date', current_time('mysql'));
        
        // Set renewal date based on annual renewal configuration
        $this->set_membership_renewal_date($user_id);
    }

    // ...

    private function set_pending_approval($user_id, $card_payments) {
        // Set status to pending
        update_user_meta($user_id, 'dssa_membership_status', 'pending');
        
        // Set payment status based on card payments selection
        if ($card_payments) {
            update_user_meta($user_id, 'dssa_payment_status', 'card_selected');
        } else {
            update_user_meta($user_id, 'dssa_payment_status', 'eft_required');
            
            // Save calculated amount for EFT display
            if (class_exists('DSSA_PMPro_Helper_Membership_Levels')) {
                DSSA_PMPro_Helper_Membership_Levels::save_calculated_amount_to_user($user_id);
            }
        }
        
        // Number not assigned yet
        update_user_meta($user_id, 'dssa_number_assigned', 'pending');
        
        // Branch not assigned yet
        $current_branch = get_user_meta($user_id, 'dssa_branch', true);
        if (empty($current_branch)) {
            update_user_meta($user_id, 'dssa_branch', 'unassigned');
        }
        
        // Set renewal date
        $this->set_membership_renewal_date($user_id);
    }

// ... remainder of file unchanged ...