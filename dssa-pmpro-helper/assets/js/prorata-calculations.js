/**
 * DSSA PMPro Helper - Pro-rata Calculations JavaScript
 * Real-time calculation display for checkout page
 */

jQuery(document).ready(function($) {
    
    // Cache elements
    const $levelSelect = $('#level');
    const $cardPayments = $('#card_payments');
    const $existMember = $('#exist_member');
    const $checkoutForm = $('#pmpro_form');
    let currentCalculations = null;
    
    /**
     * Initialize pro-rata calculations
     */
    function initProrataCalculations() {
        // Wait for PMPro to load level data
        setTimeout(() => {
            // Initial calculation
            updateProrataDisplay();
            
            // Bind events
            $levelSelect.on('change', updateProrataDisplay);
            $cardPayments.on('change', updateProrataDisplay);
            $existMember.on('change', updateProrataDisplay);
            
            // Also update when fields show/hide (for conditional logic)
            $(document).on('fieldVisibilityChanged', updateProrataDisplay);
            
        }, 1000); // Give PMPro time to initialize
    }
    
    /**
     * Update pro-rata calculation display
     */
    function updateProrataDisplay() {
        // Skip if legacy member
        if ($existMember.is(':checked')) {
            hideProrataDisplay();
            return;
        }
        
        // Get selected level
        const levelId = $levelSelect.val();
        if (!levelId || !dssa_prorata_settings.membership_levels[levelId]) {
            hideProrataDisplay();
            return;
        }
        
        const level = dssa_prorata_settings.membership_levels[levelId];
        const calculations = calculateProrataAmounts(level.annual_fee);
        
        // Store for form submission
        currentCalculations = calculations;
        
        // Update display
        displayProrataCalculations(level, calculations);
        
        // Update hidden fields if they exist
        updateHiddenCalculationFields(calculations);
    }
    
    /**
     * Calculate pro-rata amounts
     */
    function calculateProrataAmounts(annualFee) {
        const settings = dssa_prorata_settings;
        const currentDate = new Date();
        
        // Get next renewal date
        const renewalYear = currentDate.getFullYear();
        let renewalDate = new Date(renewalYear, settings.annual_renewal_month - 1, settings.annual_renewal_day);
        
        // If renewal date has passed, use next year
        if (currentDate > renewalDate) {
            renewalDate.setFullYear(renewalYear + 1);
        }
        
        // Calculate days remaining
        const timeDiff = renewalDate.getTime() - currentDate.getTime();
        const daysRemaining = Math.ceil(timeDiff / (1000 * 3600 * 24));
        
        // Check if within threshold
        const withinThreshold = daysRemaining <= settings.threshold_days;
        
        // Calculate months remaining (approximate)
        const monthsRemaining = daysRemaining / 30.44;
        
        // Calculate pro-rata amount
        let proRataAmount = 0;
        if (!withinThreshold && monthsRemaining > 0) {
            proRataAmount = (annualFee / 12) * monthsRemaining;
            proRataAmount = Math.ceil(proRataAmount * 100) / 100; // Round up to cents
        }
        
        // Calculate Paystack fees if card payments selected
        let feeAmount = 0;
        let totalWithFees = proRataAmount;
        
        if ($cardPayments.is(':checked') && proRataAmount > 0) {
            const p = settings.paystack_percentage / 100;
            const f = settings.paystack_fixed;
            
            // T = (M + f) / (1 - p)
            totalWithFees = (proRataAmount + f) / (1 - p);
            totalWithFees = Math.ceil(totalWithFees * 100) / 100;
            feeAmount = totalWithFees - proRataAmount;
        }
        
        return {
            annualFee: annualFee,
            proRataAmount: proRataAmount,
            feeAmount: feeAmount,
            totalWithFees: totalWithFees,
            monthsRemaining: Math.ceil(monthsRemaining),
            daysRemaining: daysRemaining,
            withinThreshold: withinThreshold,
            renewalDate: settings.next_renewal_date
        };
    }
    
    /**
     * Display pro-rata calculations
     */
    function displayProrataCalculations(level, calculations) {
        // Remove existing display
        $('#dssa-prorata-display').remove();
        
        // Create display container
        const $display = $('<div id="dssa-prorata-display" class="dssa-prorata-display"></div>');
        
        // Build content
        let content = `
            <h4>${dssa_pmpro_helper.strings.fee_calculation || 'Membership Fee Calculation'}</h4>
            <table class="dssa-prorata-breakdown">
                <tr>
                    <td>${dssa_pmpro_helper.strings.annual_fee || 'Annual Membership Fee:'}</td>
                    <td class="dssa-amount">${dssa_prorata_settings.currency_symbol} ${calculations.annualFee.toFixed(2)}</td>
                </tr>
        `;
        
        if (calculations.monthsRemaining > 0 && !calculations.withinThreshold) {
            content += `
                <tr>
                    <td>${dssa_pmpro_helper.strings.prorata_for || 'Pro-rata for'} ${calculations.monthsRemaining} ${dssa_pmpro_helper.strings.months_until || 'months until'} ${calculations.renewalDate}:</td>
                    <td class="dssa-amount">${dssa_prorata_settings.currency_symbol} ${calculations.proRataAmount.toFixed(2)}</td>
                </tr>
            `;
        }
        
        if ($cardPayments.is(':checked') && calculations.feeAmount > 0) {
            content += `
                <tr>
                    <td>${dssa_pmpro_helper.strings.paystack_fees || 'Paystack transaction fees (2.9% + R1):'}</td>
                    <td class="dssa-amount">${dssa_prorata_settings.currency_symbol} ${calculations.feeAmount.toFixed(2)}</td>
                </tr>
            `;
        }
        
        if (calculations.proRataAmount > 0) {
            const totalAmount = $cardPayments.is(':checked') ? calculations.totalWithFees : calculations.proRataAmount;
            content += `
                <tr class="dssa-total">
                    <td><strong>${dssa_pmpro_helper.strings.total_due || 'Total Amount Due:'}</strong></td>
                    <td class="dssa-amount"><strong>${dssa_prorata_settings.currency_symbol} ${totalAmount.toFixed(2)}</strong></td>
                </tr>
            `;
        }
        
        content += '</table>';
        
        // Add threshold message if applicable
        if (calculations.withinThreshold && calculations.proRataAmount === 0) {
            content += `
                <div class="dssa-threshold-message dssa-info">
                    <p><strong>${dssa_pmpro_helper.strings.no_payment_now || 'No Payment Required Now'}</strong></p>
                    <p>${dssa_pmpro_helper.strings.membership_starts_soon || 'Your membership starts soon! No payment is required at this time.'}</p>
                    <p><small>${dssa_pmpro_helper.strings.card_for_future || 'Card details will be used for future renewals starting'} ${calculations.renewalDate}.</small></p>
                </div>
            `;
        }
        
        $display.html(content);
        
        // Insert after level selection or before payment section
        const $paymentSection = $('.pmpro_checkout_box-payment_information');
        if ($paymentSection.length) {
            $paymentSection.before($display);
        } else {
            $levelSelect.closest('.pmpro_checkout-field').after($display);
        }
        
        // Animate display
        $display.hide().slideDown(300);
    }
    
    /**
     * Hide pro-rata display
     */
    function hideProrataDisplay() {
        $('#dssa-prorata-display').slideUp(300, function() {
            $(this).remove();
        });
    }
    
    /**
     * Update hidden calculation fields
     */
    function updateHiddenCalculationFields(calculations) {
        // Create or update hidden fields for form submission
        $('#dssa_calculated_amount').remove();
        $('#dssa_within_threshold').remove();
        
        if (calculations) {
            $checkoutForm.append(
                `<input type="hidden" id="dssa_calculated_amount" name="dssa_calculated_amount" value="${calculations.proRataAmount}">`
            );
            $checkoutForm.append(
                `<input type="hidden" id="dssa_within_threshold" name="dssa_within_threshold" value="${calculations.withinThreshold ? '1' : '0'}">`
            );
        }
    }
    
    /**
     * Initialize form validation with pro-rata checks
     */
    function initEnhancedFormValidation() {
        $checkoutForm.on('submit', function(e) {
            // For legacy members, ensure no payment is attempted
            if ($existMember.is(':checked')) {
                // Double-check that payment fields are disabled
                $('.pmpro_checkout_box-payment_information input, .pmpro_checkout_box-payment_information select').prop('disabled', true);
            }
            
            // For new members within threshold, ensure zero amount
            if (currentCalculations && currentCalculations.withinThreshold) {
                const $initialPayment = $('#initial_payment');
                if ($initialPayment.length && parseFloat($initialPayment.val()) > 0) {
                    $initialPayment.val('0');
                }
            }
            
            return true;
        });
    }
    
    /**
     * Add localization strings
     */
    function addLocalizationStrings() {
        if (!dssa_pmpro_helper.strings) {
            dssa_pmpro_helper.strings = {};
        }
        
        // Add pro-rata specific strings
        Object.assign(dssa_pmpro_helper.strings, {
            fee_calculation: 'Membership Fee Calculation',
            annual_fee: 'Annual Membership Fee:',
            prorata_for: 'Pro-rata for',
            months_until: 'months until',
            paystack_fees: 'Paystack transaction fees (2.9% + R1):',
            total_due: 'Total Amount Due:',
            no_payment_now: 'No Payment Required Now',
            membership_starts_soon: 'Your membership starts soon! No payment is required at this time.',
            card_for_future: 'Card details will be used for future renewals starting'
        });
    }
    
    /**
     * Initialize all functionality
     */
    function init() {
        // Check if we're on checkout page
        if (!$checkoutForm.length || !$levelSelect.length) {
            return;
        }
        
        // Add localization strings
        addLocalizationStrings();
        
        // Initialize pro-rata calculations
        initProrataCalculations();
        
        // Initialize enhanced form validation
        initEnhancedFormValidation();
        
        // Trigger initial update after a short delay
        setTimeout(updateProrataDisplay, 1500);
    }
    
    // Initialize when document is ready
    init();
    
});