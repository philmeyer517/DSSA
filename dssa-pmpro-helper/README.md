# DSSA PMPro Helper - Phase 1 Testing Guide

## 📋 Prerequisites
1. WordPress 6.0+ with PHP 8.0+
2. Paid Memberships Pro (Free version) activated
3. PMPro checkout page accessible at `/membership-checkout/`

## 🚀 Installation Steps

### Option A: Manual Installation
1. Download the plugin folder
2. Upload to `/wp-content/plugins/dssa-pmpro-helper/`
3. Activate plugin in WordPress admin
4. Check for any activation errors

### Option B: ZIP Installation
1. Create a ZIP of the plugin folder
2. Upload via WordPress Plugin → Add New → Upload Plugin
3. Activate the plugin

## 🧪 Testing Checklist

### Phase 1A: Basic Functionality
- [ ] Plugin activates without errors
- [ ] Database tables are created (check phpMyAdmin)
- [ ] Settings page loads at Settings → DSSA PMPro Helper
- [ ] Legacy Members page loads at Memberships → Legacy Members

### Phase 1B: Settings Configuration
- [ ] All settings tabs load without errors
- [ ] Settings can be saved and persist
- [ ] Email settings accept multiple addresses
- [ ] Date calculations show correct next renewal date
- [ ] Paystack fee calculator shows correct amounts
- [ ] Validation messages can be edited

### Phase 1C: Legacy Members Management
1. **CSV Upload Test:**
   - Download `test-legacy-numbers.csv`
   - Go to Memberships → Legacy Members
   - Upload the CSV file
   - Verify 30 numbers imported successfully

2. **Table Functionality:**
   - Search for "DSSA2024015"
   - Filter by "Unclaimed" status
   - Test pagination (if more than 20 items)
   - Edit a legacy number via the Edit button
   - Delete a test number

3. **Export Test:**
   - Select a few numbers with checkboxes
   - Choose "Export Selected" from bulk actions
   - Download should start with CSV file

### Phase 1D: Checkout Page Testing
1. **As a New User:**
   - Go to `/membership-checkout/`
   - Verify bilingual field labels appear
   - First/Last Name should be with account fields
   - DSSA Membership Information section appears

2. **Conditional Field Logic:**
   - Check "Existing Member" → Shows Member Number & Branch
   - Uncheck → Shows Card Payments
   - Branch dropdown shows all 28 DSSA branches

3. **Legacy Number Validation:**
   - Check "Existing Member"
   - Enter "DSSA2024001" (from test CSV)
   - Should show green success message
   - Enter invalid number → red error message

### Phase 1E: Registration Testing
1. **Legacy Member Registration:**
   - Complete checkout with valid legacy number
   - Registration should complete without payment
   - Check user meta has legacy flags
   - Legacy number should show as "claimed" in admin

2. **New Member Registration:**
   - Complete checkout without checking "Existing Member"
   - With/without "Card Payments" checked
   - Verify fields saved to user meta

### Phase 1F: User Profile Testing
1. **As Admin:**
   - Go to Users → Edit a test user
   - DSSA Membership Information section appears
   - Should be able to edit all fields
   - Conditional logic works (shows/hides based on legacy status)

2. **As Regular User:**
   - Go to your profile page
   - DSSA fields should be read-only
   - Cannot edit the fields

## 🐛 Troubleshooting

### Common Issues:
1. **JavaScript errors:** Check browser console (F12)
2. **AJAX failures:** Enable debug logging in DSSA settings
3. **CSV upload fails:** Check file permissions and PHP limits
4. **Fields not showing:** Clear cache, test with default theme

### Debug Steps:
1. Enable WP_DEBUG in wp-config.php
2. Check WordPress debug.log
3. Test with all other plugins deactivated
4. Test with default WordPress theme

## 📊 Expected Results

### Database After Installation:
- 3 new tables: `wp_dssa_legacy_numbers`, `wp_dssa_audit_log`, `wp_dssa_branches`
- 28 branches automatically inserted
- 30+ settings in wp_options

### User Experience:
- Smooth checkout with conditional fields
- Real-time legacy number validation
- Admin-friendly CSV management
- Comprehensive audit logging

## 🔜 Next Phase Features (Not Yet Implemented)
- Payment calculations (pro-rata, Paystack fees)
- Membership level assignment logic
- New member approval workflow
- Custom login/password reset system
- Main DSSA Admin menu structure
- Reports and analytics

## 📞 Support
For issues during testing, check:
1. WordPress error logs
2. Browser console errors
3. DSSA debug logging (if enabled)
4. Plugin compatibility with your theme/other plugins