<?php
/**
 * Legacy member management for DSSA PMPro Helper
 */

if (!defined('ABSPATH')) {
    exit;
}

class DSSA_PMPro_Helper_Legacy_Members {
    
    /**
     * Initialize the legacy members class
     */
    public static function init() {
        // AJAX handlers for admin
        add_action('wp_ajax_dssa_upload_legacy_csv', [__CLASS__, 'ajax_upload_legacy_csv']);
        add_action('wp_ajax_dssa_upload_branches_csv', [__CLASS__, 'ajax_upload_branches_csv']);
        add_action('wp_ajax_dssa_get_legacy_numbers', [__CLASS__, 'ajax_get_legacy_numbers']);
        add_action('wp_ajax_dssa_update_legacy_number', [__CLASS__, 'ajax_update_legacy_number']);
        add_action('wp_ajax_dssa_delete_legacy_number', [__CLASS__, 'ajax_delete_legacy_number']);
        add_action('wp_ajax_dssa_export_legacy_numbers', [__CLASS__, 'ajax_export_legacy_numbers']);
        
        // Add admin menu
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);
    }
    
    /**
     * Add admin menu for legacy members
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'pmpro-membershiplevels',
            __('Legacy Members', 'dssa-pmpro-helper'),
            __('Legacy Members', 'dssa-pmpro-helper'),
            'manage_dssa',
            'dssa-legacy-members',
            [__CLASS__, 'render_legacy_members_page']
        );
    }
    
    /**
     * Render the legacy members admin page
     */
    public static function render_legacy_members_page() {
        if (!current_user_can('manage_dssa') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'dssa-pmpro-helper'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('DSSA Legacy Members', 'dssa-pmpro-helper'); ?></h1>
            
            <div class="dssa-admin-container">
                <!-- CSV Upload Section -->
                <div class="dssa-card">
                    <h2><?php _e('Upload Legacy Numbers CSV', 'dssa-pmpro-helper'); ?></h2>
                    <p class="description">
                        <?php _e('Upload a CSV file with one column containing membership numbers. Each number will be added to the legacy database for members to claim.', 'dssa-pmpro-helper'); ?>
                    </p>
                    
                    <div class="dssa-csv-upload-area" id="dssa-legacy-csv-upload">
                        <form id="dssa-legacy-upload-form" enctype="multipart/form-data">
                            <?php wp_nonce_field('dssa_upload_legacy_csv', 'dssa_legacy_csv_nonce'); ?>
                            
                            <div class="dssa-upload-box">
                                <input type="file" 
                                       id="dssa-legacy-csv-file" 
                                       name="legacy_csv" 
                                       accept=".csv,text/csv,.txt,text/plain" 
                                       required>
                                <p class="description">
                                    <?php _e('CSV format: One column with header "membership_number"', 'dssa-pmpro-helper'); ?>
                                </p>
                                <p class="description">
                                    <?php _e('Maximum file size: 10MB', 'dssa-pmpro-helper'); ?>
                                </p>
                            </div>
                            
                            <div class="dssa-upload-options">
                                <label>
                                    <input type="checkbox" name="overwrite_existing" value="1">
                                    <?php _e('Overwrite existing numbers', 'dssa-pmpro-helper'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('If checked, duplicate numbers will be updated. Otherwise, they will be skipped.', 'dssa-pmpro-helper'); ?>
                                </p>
                            </div>
                            
                            <button type="submit" class="button button-primary" id="dssa-upload-legacy-csv-btn">
                                <?php _e('Upload CSV', 'dssa-pmpro-helper'); ?>
                            </button>
                        </form>
                        
                        <div class="dssa-upload-progress" id="dssa-legacy-upload-progress" style="display: none;">
                            <div class="progress-bar">
                                <div class="progress-fill"></div>
                            </div>
                            <p class="progress-status"></p>
                            <div class="upload-results" style="display: none;"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Legacy Numbers Management -->
                <div class="dssa-card">
                    <h2><?php _e('Manage Legacy Numbers', 'dssa-pmpro-helper'); ?></h2>
                    
                    <!-- Filters -->
                    <div class="dssa-filters">
                        <div class="filter-group">
                            <label for="dssa-legacy-search"><?php _e('Search:', 'dssa-pmpro-helper'); ?></label>
                            <input type="text" 
                                   id="dssa-legacy-search" 
                                   placeholder="<?php esc_attr_e('Search membership numbers...', 'dssa-pmpro-helper'); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="dssa-legacy-status"><?php _e('Status:', 'dssa-pmpro-helper'); ?></label>
                            <select id="dssa-legacy-status">
                                <option value=""><?php _e('All', 'dssa-pmpro-helper'); ?></option>
                                <option value="unclaimed"><?php _e('Unclaimed', 'dssa-pmpro-helper'); ?></option>
                                <option value="claimed"><?php _e('Claimed', 'dssa-pmpro-helper'); ?></option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <button type="button" class="button" id="dssa-apply-filters">
                                <?php _e('Apply Filters', 'dssa-pmpro-helper'); ?>
                            </button>
                            <button type="button" class="button button-secondary" id="dssa-reset-filters">
                                <?php _e('Reset', 'dssa-pmpro-helper'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Table -->
                    <div class="dssa-table-container">
                        <table class="wp-list-table widefat fixed striped" id="dssa-legacy-numbers-table">
                            <thead>
                                <tr>
                                    <th class="column-id"><?php _e('ID', 'dssa-pmpro-helper'); ?></th>
                                    <th class="column-membership_number"><?php _e('Membership Number', 'dssa-pmpro-helper'); ?></th>
                                    <th class="column-status"><?php _e('Status', 'dssa-pmpro-helper'); ?></th>
                                    <th class="column-claimed_by"><?php _e('Claimed By', 'dssa-pmpro-helper'); ?></th>
                                    <th class="column-claimed_date"><?php _e('Claimed Date', 'dssa-pmpro-helper'); ?></th>
                                    <th class="column-date_created"><?php _e('Date Created', 'dssa-pmpro-helper'); ?></th>
                                    <th class="column-actions"><?php _e('Actions', 'dssa-pmpro-helper'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="dssa-legacy-numbers-body">
                                <!-- Loaded via AJAX -->
                                <tr>
                                    <td colspan="7" class="loading">
                                        <?php _e('Loading legacy numbers...', 'dssa-pmpro-helper'); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <!-- Pagination -->
                        <div class="dssa-pagination" id="dssa-legacy-pagination"></div>
                    </div>
                    
                    <!-- Bulk Actions -->
                    <div class="dssa-bulk-actions">
                        <select id="dssa-bulk-action">
                            <option value=""><?php _e('Bulk Actions', 'dssa-pmpro-helper'); ?></option>
                            <option value="export"><?php _e('Export Selected', 'dssa-pmpro-helper'); ?></option>
                            <option value="mark_unclaimed"><?php _e('Mark as Unclaimed', 'dssa-pmpro-helper'); ?></option>
                            <option value="mark_claimed"><?php _e('Mark as Claimed', 'dssa-pmpro-helper'); ?></option>
                            <option value="delete"><?php _e('Delete', 'dssa-pmpro-helper'); ?></option>
                        </select>
                        <button type="button" class="button" id="dssa-apply-bulk-action">
                            <?php _e('Apply', 'dssa-pmpro-helper'); ?>
                        </button>
                        <button type="button" class="button button-primary" id="dssa-export-all">
                            <?php _e('Export All', 'dssa-pmpro-helper'); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Statistics -->
                <div class="dssa-card">
                    <h2><?php _e('Statistics', 'dssa-pmpro-helper'); ?></h2>
                    <div class="dssa-stats-grid" id="dssa-legacy-stats">
                        <div class="stat-box">
                            <h3><?php _e('Total Numbers', 'dssa-pmpro-helper'); ?></h3>
                            <p class="stat-number" id="stat-total">0</p>
                        </div>
                        <div class="stat-box">
                            <h3><?php _e('Unclaimed', 'dssa-pmpro-helper'); ?></h3>
                            <p class="stat-number" id="stat-unclaimed">0</p>
                        </div>
                        <div class="stat-box">
                            <h3><?php _e('Claimed', 'dssa-pmpro-helper'); ?></h3>
                            <p class="stat-number" id="stat-claimed">0</p>
                        </div>
                        <div class="stat-box">
                            <h3><?php _e('Claim Rate', 'dssa-pmpro-helper'); ?></h3>
                            <p class="stat-number" id="stat-claim-rate">0%</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Edit Modal -->
        <div id="dssa-edit-legacy-modal" class="dssa-modal" style="display: none;">
            <div class="dssa-modal-content">
                <div class="dssa-modal-header">
                    <h3><?php _e('Edit Legacy Number', 'dssa-pmpro-helper'); ?></h3>
                    <button type="button" class="dssa-modal-close">&times;</button>
                </div>
                <div class="dssa-modal-body">
                    <form id="dssa-edit-legacy-form">
                        <?php wp_nonce_field('dssa_update_legacy_number', 'dssa_update_nonce'); ?>
                        <input type="hidden" id="edit-legacy-id" name="id">
                        
                        <div class="dssa-form-group">
                            <label for="edit-membership-number"><?php _e('Membership Number', 'dssa-pmpro-helper'); ?></label>
                            <input type="text" 
                                   id="edit-membership-number" 
                                   name="membership_number" 
                                   class="regular-text" 
                                   required>
                        </div>
                        
                        <div class="dssa-form-group">
                            <label for="edit-status"><?php _e('Status', 'dssa-pmpro-helper'); ?></label>
                            <select id="edit-status" name="status">
                                <option value="unclaimed"><?php _e('Unclaimed', 'dssa-pmpro-helper'); ?></option>
                                <option value="claimed"><?php _e('Claimed', 'dssa-pmpro-helper'); ?></option>
                            </select>
                        </div>
                        
                        <div class="dssa-form-group" id="edit-claimed-by-group" style="display: none;">
                            <label for="edit-claimed-by"><?php _e('Claimed By User ID', 'dssa-pmpro-helper'); ?></label>
                            <input type="number" 
                                   id="edit-claimed-by" 
                                   name="claimed_by_user_id" 
                                   class="regular-text"
                                   min="0">
                            <p class="description">
                                <?php _e('Leave empty or set to 0 to unclaim this number.', 'dssa-pmpro-helper'); ?>
                            </p>
                        </div>
                        
                        <div class="dssa-modal-footer">
                            <button type="submit" class="button button-primary">
                                <?php _e('Save Changes', 'dssa-pmpro-helper'); ?>
                            </button>
                            <button type="button" class="button button-secondary dssa-modal-close">
                                <?php _e('Cancel', 'dssa-pmpro-helper'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Confirmation Modal -->
        <div id="dssa-confirm-modal" class="dssa-modal" style="display: none;">
            <div class="dssa-modal-content">
                <div class="dssa-modal-header">
                    <h3><?php _e('Confirm Action', 'dssa-pmpro-helper'); ?></h3>
                    <button type="button" class="dssa-modal-close">&times;</button>
                </div>
                <div class="dssa-modal-body">
                    <p id="dssa-confirm-message"></p>
                </div>
                <div class="dssa-modal-footer">
                    <button type="button" class="button button-primary" id="dssa-confirm-yes">
                        <?php _e('Yes, Continue', 'dssa-pmpro-helper'); ?>
                    </button>
                    <button type="button" class="button button-secondary dssa-modal-close">
                        <?php _e('Cancel', 'dssa-pmpro-helper'); ?>
                    </button>
                </div>
            </div>
        </div>
        
        <style>
        .dssa-admin-container {
            display: grid;
            gap: 20px;
            margin-top: 20px;
        }
        
        .dssa-card {
            background: white;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        
        .dssa-card h2 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .dssa-csv-upload-area {
            margin: 20px 0;
        }
        
        .dssa-upload-box {
            border: 2px dashed #ccd0d4;
            padding: 30px;
            text-align: center;
            margin: 20px 0;
            transition: border-color 0.3s;
        }
        
        .dssa-upload-box.drag-over {
            border-color: #0073aa;
            background: #f0f8ff;
        }
        
        .dssa-filters {
            display: flex;
            gap: 15px;
            margin: 20px 0;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-group label {
            font-weight: 600;
        }
        
        .dssa-table-container {
            margin: 20px 0;
            overflow-x: auto;
        }
        
        .dssa-pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin: 20px 0;
        }
        
        .dssa-pagination .page-numbers {
            padding: 5px 10px;
            border: 1px solid #ddd;
            text-decoration: none;
        }
        
        .dssa-pagination .current {
            background: #0073aa;
            color: white;
            border-color: #0073aa;
        }
        
        .dssa-bulk-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            align-items: center;
        }
        
        .dssa-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .stat-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 4px;
            text-align: center;
            border-left: 4px solid #0073aa;
        }
        
        .stat-box h3 {
            margin-top: 0;
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #0073aa;
            margin: 10px 0 0;
        }
        
        .dssa-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        
        .dssa-modal-content {
            background: white;
            border-radius: 4px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .dssa-modal-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .dssa-modal-header h3 {
            margin: 0;
        }
        
        .dssa-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }
        
        .dssa-modal-body {
            padding: 20px;
        }
        
        .dssa-modal-footer {
            padding: 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .dssa-form-group {
            margin-bottom: 15px;
        }
        
        .dssa-form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .loading {
            text-align: center;
            padding: 40px !important;
            color: #666;
            font-style: italic;
        }
        
        .status-unclaimed {
            color: #46b450;
            font-weight: bold;
        }
        
        .status-claimed {
            color: #dc3232;
            font-weight: bold;
        }
        
        @media (max-width: 768px) {
            .dssa-filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .dssa-bulk-actions {
                flex-wrap: wrap;
            }
            
            .dssa-stats-grid {
                grid-template-columns: 1fr;
            }
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Initialize
            var currentPage = 1;
            var currentFilters = {
                search: '',
                status: '',
                per_page: 20
            };
            
            // Load initial data
            loadLegacyNumbers();
            loadStatistics();
            
            // CSV Upload
            $('#dssa-legacy-upload-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = new FormData(this);
                formData.append('nonce', $('#dssa_legacy_csv_nonce').val());
                formData.append('action', 'dssa_upload_legacy_csv');
                
                var uploadBtn = $('#dssa-upload-legacy-csv-btn');
                var progress = $('#dssa-legacy-upload-progress');
                var progressFill = progress.find('.progress-fill');
                var progressStatus = progress.find('.progress-status');
                var results = progress.find('.upload-results');
                
                uploadBtn.prop('disabled', true).text('Uploading...');
                progress.show();
                progressFill.css('width', '0%');
                progressStatus.text('Preparing upload...');
                results.hide().empty();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    xhr: function() {
                        var xhr = new XMLHttpRequest();
                        xhr.upload.addEventListener('progress', function(e) {
                            if (e.lengthComputable) {
                                var percent = (e.loaded / e.total) * 100;
                                progressFill.css('width', percent + '%');
                                progressStatus.text('Uploading: ' + Math.round(percent) + '%');
                            }
                        }, false);
                        return xhr;
                    },
                    success: function(response) {
                        console.log('Upload response:', response);
                        
                        if (response.success) {
                            progressFill.css('width', '100%');
                            progressStatus.text('Upload complete!');
                            
                            // Show results
                            var html = '<div class="notice notice-success"><p>' + 
                                'Upload completed successfully!</p><ul>' +
                                '<li>Imported: ' + response.data.imported + '</li>' +
                                '<li>Skipped: ' + response.data.skipped + '</li>' +
                                '<li>Duplicates: ' + response.data.duplicates + '</li>';
                            
                            if (response.data.errors && response.data.errors.length > 0) {
                                html += '<li>Errors: ' + response.data.errors.length + '</li></ul>';
                                html += '<p><strong>Errors:</strong></p><ul>';
                                $.each(response.data.errors.slice(0, 5), function(i, error) {
                                    html += '<li>' + error + '</li>';
                                });
                                if (response.data.errors.length > 5) {
                                    html += '<li>... and ' + (response.data.errors.length - 5) + ' more errors</li>';
                                }
                                html += '</ul>';
                            } else {
                                html += '</ul>';
                            }
                            
                            html += '</div>';
                            results.html(html).show();
                            
                            // Reload data
                            setTimeout(function() {
                                loadLegacyNumbers();
                                loadStatistics();
                            }, 1000);
                        } else {
                            progressStatus.text('Upload failed');
                            results.html('<div class="notice notice-error"><p>' + response.data + '</p></div>').show();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Upload error:', error, xhr.responseText);
                        progressStatus.text('Upload failed');
                        var errorMsg = 'An error occurred during upload.';
                        
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.data) {
                                errorMsg = response.data;
                            }
                        } catch (e) {
                            if (xhr.responseText) {
                                errorMsg = xhr.responseText.substring(0, 200);
                            }
                        }
                        
                        results.html('<div class="notice notice-error"><p>' + errorMsg + '</p></div>').show();
                    },
                    complete: function() {
                        uploadBtn.prop('disabled', false).text('Upload CSV');
                        setTimeout(function() {
                            progressFill.css('width', '0%');
                            progressStatus.text('');
                        }, 3000);
                    }
                });
            });
            
            // Filter handling
            $('#dssa-apply-filters').on('click', function() {
                currentFilters.search = $('#dssa-legacy-search').val();
                currentFilters.status = $('#dssa-legacy-status').val();
                currentPage = 1;
                loadLegacyNumbers();
            });
            
            $('#dssa-reset-filters').on('click', function() {
                $('#dssa-legacy-search').val('');
                $('#dssa-legacy-status').val('');
                currentFilters.search = '';
                currentFilters.status = '';
                currentPage = 1;
                loadLegacyNumbers();
            });
            
            $('#dssa-legacy-search').on('keyup', function(e) {
                if (e.keyCode === 13) {
                    $('#dssa-apply-filters').click();
                }
            });
            
            // Pagination
            $(document).on('click', '.dssa-page-link', function(e) {
                e.preventDefault();
                currentPage = $(this).data('page');
                loadLegacyNumbers();
            });
            
            // Edit modal
            $(document).on('click', '.dssa-edit-legacy', function() {
                var id = $(this).data('id');
                var number = $(this).data('number');
                var status = $(this).data('status');
                var claimedBy = $(this).data('claimed-by') || '';
                
                $('#edit-legacy-id').val(id);
                $('#edit-membership-number').val(number);
                $('#edit-status').val(status);
                $('#edit-claimed-by').val(claimedBy);
                
                // Show/hide claimed by field
                if (status === 'claimed') {
                    $('#edit-claimed-by-group').show();
                } else {
                    $('#edit-claimed-by-group').hide();
                }
                
                $('#dssa-edit-legacy-modal').show();
            });
            
            $('#edit-status').on('change', function() {
                if ($(this).val() === 'claimed') {
                    $('#edit-claimed-by-group').show();
                } else {
                    $('#edit-claimed-by-group').hide();
                }
            });
            
			$('#dssa-edit-legacy-form').on('submit', function(e) {
				e.preventDefault();
				
				console.log('Edit form submitted');
				console.log('Form data:', $(this).serialize());
				
				var formData = $(this).serialize();
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'dssa_update_legacy_number',
						nonce: $('#dssa_update_nonce').val(),
						data: formData
					},
					success: function(response) {
						console.log('Update AJAX success:', response);
						if (response.success) {
							alert('Legacy number updated successfully!');
							$('.dssa-modal-close').click();
							loadLegacyNumbers();
							loadStatistics();
						} else {
							console.error('Update failed:', response.data);
							alert('Error: ' + response.data);
						}
					},
					error: function(xhr, status, error) {
						console.error('Update AJAX error:', error, xhr.responseText);
						alert('Error updating legacy number: ' + error);
					}
				});
			});
            
            // Delete action
            $(document).on('click', '.dssa-delete-legacy', function() {
                var id = $(this).data('id');
                var number = $(this).data('number');
                
                $('#dssa-confirm-message').html('Are you sure you want to delete legacy number <strong>' + 
                    number + '</strong>? This action cannot be undone.');
                
                $('#dssa-confirm-yes').off('click').on('click', function() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'dssa_delete_legacy_number',
                            nonce: '<?php echo wp_create_nonce('dssa_delete_legacy_number'); ?>',
                            id: id
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('Legacy number deleted successfully!');
                                $('.dssa-modal-close').click();
                                loadLegacyNumbers();
                                loadStatistics();
                            } else {
                                alert('Error: ' + response.data);
                            }
                        }
                    });
                });
                
                $('#dssa-confirm-modal').show();
            });
            
            // Bulk actions
            $('#dssa-apply-bulk-action').on('click', function() {
                var action = $('#dssa-bulk-action').val();
                var selected = $('.legacy-checkbox:checked').map(function() {
                    return $(this).val();
                }).get();
                
                if (selected.length === 0) {
                    alert('Please select at least one legacy number.');
                    return;
                }
                
                if (action === 'export') {
                    exportLegacyNumbers(selected);
                } else if (action === 'delete') {
                    $('#dssa-confirm-message').html('Are you sure you want to delete ' + selected.length + 
                        ' legacy number(s)? This action cannot be undone.');
                    
                    $('#dssa-confirm-yes').off('click').on('click', function() {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'dssa_delete_legacy_number',
                                nonce: '<?php echo wp_create_nonce('dssa_delete_legacy_number'); ?>',
                                ids: selected
                            },
                            success: function(response) {
                                if (response.success) {
                                    alert('Legacy numbers deleted successfully!');
                                    $('.dssa-modal-close').click();
                                    loadLegacyNumbers();
                                    loadStatistics();
                                } else {
                                    alert('Error: ' + response.data);
                                }
                            }
                        });
                    });
                    
                    $('#dssa-confirm-modal').show();
                } else if (action === 'mark_unclaimed' || action === 'mark_claimed') {
                    var newStatus = action === 'mark_unclaimed' ? 'unclaimed' : 'claimed';
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'dssa_update_legacy_number',
                            nonce: '<?php echo wp_create_nonce('dssa_update_legacy_number'); ?>',
                            ids: selected,
                            status: newStatus
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('Legacy numbers updated successfully!');
                                loadLegacyNumbers();
                                loadStatistics();
                            } else {
                                alert('Error: ' + response.data);
                            }
                        }
                    });
                }
            });
            
            // Export all
            $('#dssa-export-all').on('click', function() {
                exportLegacyNumbers('all');
            });
            
            // Modal close
            $('.dssa-modal-close').on('click', function() {
                $(this).closest('.dssa-modal').hide();
            });
            
            // Load legacy numbers via AJAX
            function loadLegacyNumbers() {
                $('#dssa-legacy-numbers-body').html('<tr><td colspan="7" class="loading">Loading legacy numbers...</td></tr>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dssa_get_legacy_numbers',
                        nonce: '<?php echo wp_create_nonce('dssa_get_legacy_numbers'); ?>',
                        page: currentPage,
                        search: currentFilters.search,
                        status: currentFilters.status,
                        per_page: currentFilters.per_page
                    },
                    success: function(response) {
                        console.log('Load legacy numbers response:', response);
                        if (response.success) {
                            renderLegacyNumbers(response.data);
                        } else {
                            $('#dssa-legacy-numbers-body').html('<tr><td colspan="7" class="loading">Error loading data</td></tr>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Load legacy numbers error:', error, xhr.responseText);
                        $('#dssa-legacy-numbers-body').html('<tr><td colspan="7" class="loading">Error loading data: ' + error + '</td></tr>');
                    }
                });
            }
            
            // Render legacy numbers table
            function renderLegacyNumbers(data) {
                var html = '';
                
                if (data.items.length === 0) {
                    html = '<tr><td colspan="7" class="loading">No legacy numbers found</td></tr>';
                } else {
                    $.each(data.items, function(i, item) {
                        var claimedBy = '';
                        if (item.claimed_by_user_id) {
                            claimedBy = '<a href="' + ajaxurl.replace('admin-ajax.php', 'user-edit.php?user_id=' + item.claimed_by_user_id) + '">' +
                                       'User #' + item.claimed_by_user_id + '</a>';
                        }
                        
                        html += '<tr>' +
                            '<td><input type="checkbox" class="legacy-checkbox" value="' + item.id + '"> ' + item.id + '</td>' +
                            '<td>' + item.membership_number + '</td>' +
                            '<td><span class="status-' + item.status + '">' + 
                                (item.status === 'claimed' ? 'Claimed' : 'Unclaimed') + 
                            '</span></td>' +
                            '<td>' + claimedBy + '</td>' +
                            '<td>' + (item.claimed_date || '') + '</td>' +
                            '<td>' + item.date_created + '</td>' +
                            '<td>' +
                                '<button type="button" class="button button-small dssa-edit-legacy" ' +
                                       'data-id="' + item.id + '" ' +
                                       'data-number="' + item.membership_number + '" ' +
                                       'data-status="' + item.status + '" ' +
                                       'data-claimed-by="' + (item.claimed_by_user_id || '') + '">' +
                                    'Edit' +
                                '</button> ' +
                                '<button type="button" class="button button-small button-link-delete dssa-delete-legacy" ' +
                                       'data-id="' + item.id + '" ' +
                                       'data-number="' + item.membership_number + '">' +
                                    'Delete' +
                                '</button>' +
                            '</td>' +
                        '</tr>';
                    });
                }
                
                $('#dssa-legacy-numbers-body').html(html);
                renderPagination(data);
            }
            
            // Render pagination
            function renderPagination(data) {
                if (data.pages <= 1) {
                    $('#dssa-legacy-pagination').empty();
                    return;
                }
                
                var html = '';
                
                // Previous
                if (currentPage > 1) {
                    html += '<a href="#" class="page-numbers dssa-page-link" data-page="' + (currentPage - 1) + '">&laquo; Previous</a>';
                }
                
                // Page numbers
                var start = Math.max(1, currentPage - 2);
                var end = Math.min(data.pages, start + 4);
                
                if (start > 1) {
                    html += '<a href="#" class="page-numbers dssa-page-link" data-page="1">1</a>';
                    if (start > 2) html += '<span class="page-numbers dots">...</span>';
                }
                
                for (var i = start; i <= end; i++) {
                    if (i === currentPage) {
                        html += '<span class="page-numbers current">' + i + '</span>';
                    } else {
                        html += '<a href="#" class="page-numbers dssa-page-link" data-page="' + i + '">' + i + '</a>';
                    }
                }
                
                if (end < data.pages) {
                    if (end < data.pages - 1) html += '<span class="page-numbers dots">...</span>';
                    html += '<a href="#" class="page-numbers dssa-page-link" data-page="' + data.pages + '">' + data.pages + '</a>';
                }
                
                // Next
                if (currentPage < data.pages) {
                    html += '<a href="#" class="page-numbers dssa-page-link" data-page="' + (currentPage + 1) + '">Next &raquo;</a>';
                }
                
                $('#dssa-legacy-pagination').html(html);
            }
            
            // Load statistics
            function loadStatistics() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dssa_get_legacy_numbers',
                        nonce: '<?php echo wp_create_nonce('dssa_get_legacy_numbers'); ?>',
                        per_page: -1,
                        get_stats_only: true
                    },
                    success: function(response) {
                        console.log('Statistics response:', response);
                        if (response.success) {
                            var total = response.data.total || response.data.items.length;
                            var claimed = response.data.claimed || response.data.items.filter(function(item) {
                                return item.status === 'claimed';
                            }).length;
                            var unclaimed = total - claimed;
                            var claimRate = total > 0 ? Math.round((claimed / total) * 100) : 0;
                            
                            $('#stat-total').text(total);
                            $('#stat-unclaimed').text(unclaimed);
                            $('#stat-claimed').text(claimed);
                            $('#stat-claim-rate').text(claimRate + '%');
                        } else {
                            console.error('Statistics error:', response.data);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Statistics AJAX error:', error, xhr.responseText);
                    }
                });
            }
            
            // Export legacy numbers
            function exportLegacyNumbers(ids) {
                window.location.href = ajaxurl + '?action=dssa_export_legacy_numbers&nonce=' + 
                    '<?php echo wp_create_nonce('dssa_export_legacy_numbers'); ?>' +
                    '&ids=' + (Array.isArray(ids) ? ids.join(',') : ids);
            }
        });
        </script>
        <?php
    }
    
    /**
     * AJAX: Upload legacy numbers CSV
     */
    public static function ajax_upload_legacy_csv() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dssa_upload_legacy_csv')) {
            wp_send_json_error(__('Security check failed. Please refresh the page and try again.', 'dssa-pmpro-helper'));
        }
        
        // Check permissions
        if (!current_user_can('manage_dssa') && !current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to upload CSV files.', 'dssa-pmpro-helper'));
        }
        
        // Check if file was uploaded
        if (empty($_FILES['legacy_csv'])) {
            wp_send_json_error(__('No file was uploaded. Please select a CSV file.', 'dssa-pmpro-helper'));
        }
        
        $file = $_FILES['legacy_csv'];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => __('The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'dssa-pmpro-helper'),
                UPLOAD_ERR_FORM_SIZE => __('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.', 'dssa-pmpro-helper'),
                UPLOAD_ERR_PARTIAL => __('The uploaded file was only partially uploaded.', 'dssa-pmpro-helper'),
                UPLOAD_ERR_NO_FILE => __('No file was uploaded.', 'dssa-pmpro-helper'),
                UPLOAD_ERR_NO_TMP_DIR => __('Missing a temporary folder.', 'dssa-pmpro-helper'),
                UPLOAD_ERR_CANT_WRITE => __('Failed to write file to disk.', 'dssa-pmpro-helper'),
                UPLOAD_ERR_EXTENSION => __('A PHP extension stopped the file upload.', 'dssa-pmpro-helper'),
            ];
            
            $error_message = isset($error_messages[$file['error']]) 
                ? $error_messages[$file['error']] 
                : sprintf(__('Upload error code: %d', 'dssa-pmpro-helper'), $file['error']);
            
            wp_send_json_error($error_message);
        }
        
        // Check file size (limit to 10MB)
        $max_file_size = 10 * 1024 * 1024; // 10MB
        if ($file['size'] > $max_file_size) {
            wp_send_json_error(__('File size exceeds the maximum limit of 10MB.', 'dssa-pmpro-helper'));
        }
        
        // Check if file is readable
        if (!is_readable($file['tmp_name'])) {
            wp_send_json_error(__('Could not read the uploaded file.', 'dssa-pmpro-helper'));
        }
        
        // Open the file
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            wp_send_json_error(__('Could not open the uploaded file.', 'dssa-pmpro-helper'));
        }
        
        // Parse CSV with minimal validation
        $numbers = [];
        $errors = [];
        $row = 0;
        
        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            $row++;
            
            // Skip empty rows
            if (empty($data[0]) || trim($data[0]) === '') {
                continue;
            }
            
            // Get the first column
            $number = trim($data[0]);
            
            // Remove any quotes
            $number = trim($number, '"\'');
            
            // Skip if empty after cleaning
            if (empty($number)) {
                continue;
            }
            
            // Skip header row (case insensitive check)
            $lower_number = strtolower($number);
            if ($row === 1 && (
                strpos($lower_number, 'member') !== false || 
                strpos($lower_number, 'number') !== false ||
                strpos($lower_number, 'id') !== false
            )) {
                continue;
            }
            
            // Basic validation
            if (!empty($number)) {
                // Remove any non-printable characters
                $clean_number = preg_replace('/[^\x20-\x7E]/', '', $number);
                
                // Trim again
                $clean_number = trim($clean_number);
                
                // Check minimum length
                if (strlen($clean_number) < 1) {
                    $errors[] = sprintf(__('Row %d: Number is too short', 'dssa-pmpro-helper'), $row);
                    continue;
                }
                
                // Check maximum length
                if (strlen($clean_number) > 100) {
                    $errors[] = sprintf(__('Row %d: Number "%s" is too long (max 100 characters)', 'dssa-pmpro-helper'), $row, $clean_number);
                    continue;
                }
                
                if (!empty($clean_number)) {
                    $numbers[] = $clean_number;
                }
            }
        }
        
        fclose($handle);
        
        // Remove duplicates
        $numbers = array_unique($numbers);
        
        if (empty($numbers)) {
            if (empty($errors)) {
                wp_send_json_error(__('No valid membership numbers found in the CSV file.', 'dssa-pmpro-helper'));
            } else {
                wp_send_json_error(implode("\n", array_slice($errors, 0, 5)));
            }
        }
        
        // Check if we're not importing too many rows at once
        $max_rows = 10000;
        if (count($numbers) > $max_rows) {
            wp_send_json_error(sprintf(__('CSV file contains too many rows. Maximum allowed is %d.', 'dssa-pmpro-helper'), $max_rows));
        }
        
        // Get overwrite option
        $overwrite = isset($_POST['overwrite_existing']) && $_POST['overwrite_existing'] == '1';
        
        // Import numbers
        if (class_exists('DSSA_PMPro_Helper_Database')) {
            $results = DSSA_PMPro_Helper_Database::import_legacy_numbers($numbers, $overwrite);
            
            // Add validation errors to results
            if (!empty($errors)) {
                $results['errors'] = array_merge($results['errors'] ?? [], $errors);
            }
            
            // Log the import
            if (class_exists('DSSA_PMPro_Helper_Audit_Log')) {
                DSSA_PMPro_Helper_Audit_Log::add_entry(
                    get_current_user_id(),
                    'legacy_numbers_imported',
                    $results
                );
            }
            
            wp_send_json_success($results);
        } else {
            wp_send_json_error(__('Database class not found. Please check if the plugin is properly installed.', 'dssa-pmpro-helper'));
        }
    }
    
    /**
     * AJAX: Upload branches CSV
     */
    public static function ajax_upload_branches_csv() {
        // Verify nonce
        if (!check_ajax_referer('dssa_upload_branches_csv', 'nonce', false)) {
            wp_send_json_error(__('Security check failed.', 'dssa-pmpro-helper'));
        }
        
        // Check permissions
        if (!current_user_can('manage_dssa') && !current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to upload CSV files.', 'dssa-pmpro-helper'));
        }
        
        wp_send_json_success(__('Branch CSV upload will be implemented in the branch management section.', 'dssa-pmpro-helper'));
    }
    
    /**
     * AJAX: Get legacy numbers
     */
    public static function ajax_get_legacy_numbers() {
        // Verify nonce
        if (!check_ajax_referer('dssa_get_legacy_numbers', 'nonce', false)) {
            wp_send_json_error(__('Security check failed.', 'dssa-pmpro-helper'));
        }
        
        // Check permissions
        if (!current_user_can('manage_dssa') && !current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to view legacy numbers.', 'dssa-pmpro-helper'));
        }
        
        $args = [
            'search' => isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '',
            'status' => isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '',
            'per_page' => isset($_POST['per_page']) ? intval($_POST['per_page']) : 20,
            'page' => isset($_POST['page']) ? intval($_POST['page']) : 1,
        ];
        
        // Validate per_page
        if ($args['per_page'] < -1 || $args['per_page'] > 100) {
            $args['per_page'] = 20;
        }
        
        // Check if we only need statistics
        $get_stats_only = isset($_POST['get_stats_only']) && $_POST['get_stats_only'] == 'true';
        
        if (class_exists('DSSA_PMPro_Helper_Database')) {
            if ($get_stats_only && $args['per_page'] == -1) {
                // Get statistics directly
                global $wpdb;
                $table_name = $wpdb->prefix . 'dssa_legacy_numbers';
                
                $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
                $claimed = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE status = %s",
                    'claimed'
                ));
                
                $data = [
                    'total' => (int)$total,
                    'claimed' => (int)$claimed,
                    'items' => [] // Empty array since we only need stats
                ];
            } else {
                $data = DSSA_PMPro_Helper_Database::get_legacy_numbers($args);
            }
            
            wp_send_json_success($data);
        } else {
            wp_send_json_error(__('Database class not found.', 'dssa-pmpro-helper'));
        }
    }
    
	/**
	 * AJAX: Update legacy number
	 */
	public static function ajax_update_legacy_number() {
		global $wpdb;
		
		// Start debugging
		error_log('=== DSSA Update Legacy Number Debug ===');
		error_log('POST data: ' . print_r($_POST, true));
		
		// Verify nonce
		if (!check_ajax_referer('dssa_update_legacy_number', 'nonce', false)) {
			error_log('Nonce verification failed');
			wp_send_json_error(__('Security check failed.', 'dssa-pmpro-helper'));
		}
		
		// Check permissions
		if (!current_user_can('manage_dssa') && !current_user_can('manage_options')) {
			error_log('Permission check failed');
			wp_send_json_error(__('You do not have permission to update legacy numbers.', 'dssa-pmpro-helper'));
		}
		
		$table_name = $wpdb->prefix . 'dssa_legacy_numbers';
		
		// Handle bulk update
		if (isset($_POST['ids']) && is_array($_POST['ids'])) {
			$ids = array_map('intval', $_POST['ids']);
			$ids = array_filter($ids);
			
			if (empty($ids)) {
				wp_send_json_error(__('No valid IDs provided.', 'dssa-pmpro-helper'));
			}
			
			$status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
			
			if (empty($status) || !in_array($status, ['unclaimed', 'claimed'])) {
				wp_send_json_error(__('Invalid status.', 'dssa-pmpro-helper'));
			}
			
			// Update all selected IDs
			$placeholders = implode(',', array_fill(0, count($ids), '%d'));
			$query = $wpdb->prepare(
				"UPDATE {$table_name} SET status = %s WHERE id IN ({$placeholders})",
				array_merge([$status], $ids)
			);
			
			$updated = $wpdb->query($query);
			
			if ($updated !== false) {
				// Log the bulk update
				if (class_exists('DSSA_PMPro_Helper_Audit_Log')) {
					DSSA_PMPro_Helper_Audit_Log::add_entry(
						get_current_user_id(),
						'legacy_numbers_bulk_updated',
						[
							'count' => count($ids),
							'status' => $status,
						]
					);
				}
				
				wp_send_json_success(__('Legacy numbers updated successfully.', 'dssa-pmpro-helper'));
			} else {
				wp_send_json_error(__('Failed to update legacy numbers.', 'dssa-pmpro-helper'));
			}
		}
		
		// Handle single update
		if (!isset($_POST['data'])) {
			error_log('No data parameter in POST');
			wp_send_json_error(__('No data provided.', 'dssa-pmpro-helper'));
		}
		
		// Parse and sanitize form data
		$data = [];
		parse_str($_POST['data'], $data);
		error_log('Parsed form data: ' . print_r($data, true));
		
		$id = isset($data['id']) ? intval($data['id']) : 0;
		$membership_number = isset($data['membership_number']) ? sanitize_text_field($data['membership_number']) : '';
		$status = isset($data['status']) ? sanitize_text_field($data['status']) : '';
		$claimed_by_user_id = isset($data['claimed_by_user_id']) ? intval($data['claimed_by_user_id']) : 0;
		
		error_log("ID: $id, Number: '$membership_number', Status: '$status', User ID: $claimed_by_user_id");
		
		if (!$id) {
			error_log('Invalid ID: ' . $id);
			wp_send_json_error(__('Invalid legacy number ID.', 'dssa-pmpro-helper'));
		}
		
		if (empty($membership_number)) {
			error_log('Empty membership number');
			wp_send_json_error(__('Membership number is required.', 'dssa-pmpro-helper'));
		}
		
		// Check length
		if (strlen($membership_number) > 100) {
			error_log('Number too long: ' . strlen($membership_number) . ' characters');
			wp_send_json_error(__('Membership number is too long (max 100 characters).', 'dssa-pmpro-helper'));
		}
		
		if (!in_array($status, ['unclaimed', 'claimed'])) {
			error_log('Invalid status: ' . $status);
			wp_send_json_error(__('Invalid status.', 'dssa-pmpro-helper'));
		}
		
		// Check if number already exists (excluding current record)
		$existing = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE membership_number = %s AND id != %d",
			$membership_number, $id
		));
		
		error_log('Duplicate check result: ' . ($existing ? 'Duplicate found' : 'No duplicate'));
		
		if ($existing) {
			wp_send_json_error(__('This membership number already exists.', 'dssa-pmpro-helper'));
		}
		
		// Prepare update data
		$update_data = [
			'membership_number' => $membership_number,
			'status' => $status,
		];
		
		$update_format = ['%s', '%s'];
		
		if ($status === 'claimed' && $claimed_by_user_id > 0) {
			// Verify user exists
			$user = get_user_by('id', $claimed_by_user_id);
			if (!$user) {
				error_log('Invalid user ID: ' . $claimed_by_user_id);
				wp_send_json_error(__('Invalid user ID.', 'dssa-pmpro-helper'));
			}
			
			$update_data['claimed_by_user_id'] = $claimed_by_user_id;
			$update_data['claimed_date'] = current_time('mysql');
			$update_format[] = '%d';
			$update_format[] = '%s';
		} else {
			$update_data['claimed_by_user_id'] = null;
			$update_data['claimed_date'] = null;
			$update_format = ['%s', '%s', null, null];
		}
		
		error_log('Update data: ' . print_r($update_data, true));
		error_log('Update format: ' . print_r($update_format, true));
		
		// Update the record
		$updated = $wpdb->update(
			$table_name,
			$update_data,
			['id' => $id],
			$update_format,
			['%d']
		);
		
		error_log('Update result: ' . ($updated !== false ? 'Success' : 'Failed'));
		
		if ($updated !== false) {
			// Log the update
			if (class_exists('DSSA_PMPro_Helper_Audit_Log')) {
				DSSA_PMPro_Helper_Audit_Log::add_entry(
					get_current_user_id(),
					'legacy_number_updated',
					[
						'id' => $id,
						'membership_number' => $membership_number,
						'status' => $status,
					]
				);
			}
			
			error_log('=== Update successful ===');
			wp_send_json_success(__('Legacy number updated successfully.', 'dssa-pmpro-helper'));
		} else {
			error_log('=== Update failed ===');
			error_log('Last WPDB error: ' . $wpdb->last_error);
			wp_send_json_error(__('Failed to update legacy number.', 'dssa-pmpro-helper'));
		}
	}
    
    /**
     * AJAX: Delete legacy number
     */
    public static function ajax_delete_legacy_number() {
        global $wpdb;
        
        // Verify nonce
        if (!check_ajax_referer('dssa_delete_legacy_number', 'nonce', false)) {
            wp_send_json_error(__('Security check failed.', 'dssa-pmpro-helper'));
        }
        
        // Check permissions
        if (!current_user_can('manage_dssa') && !current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to delete legacy numbers.', 'dssa-pmpro-helper'));
        }
        
        $table_name = $wpdb->prefix . 'dssa_legacy_numbers';
        
        // Handle bulk delete
        if (isset($_POST['ids']) && is_array($_POST['ids'])) {
            $ids = array_map('intval', $_POST['ids']);
            $ids = array_filter($ids);
            
            if (empty($ids)) {
                wp_send_json_error(__('No valid IDs provided.', 'dssa-pmpro-helper'));
            }
            
            // Get numbers before deletion for logging
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $numbers = $wpdb->get_col($wpdb->prepare(
                "SELECT membership_number FROM {$table_name} WHERE id IN ({$placeholders})",
                $ids
            ));
            
            // Delete records
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table_name} WHERE id IN ({$placeholders})",
                $ids
            ));
            
            if ($deleted !== false) {
                // Log the bulk deletion
                if (class_exists('DSSA_PMPro_Helper_Audit_Log')) {
                    DSSA_PMPro_Helper_Audit_Log::add_entry(
                        get_current_user_id(),
                        'legacy_numbers_bulk_deleted',
                        [
                            'count' => count($ids),
                            'numbers' => $numbers,
                        ]
                    );
                }
                
                wp_send_json_success(__('Legacy numbers deleted successfully.', 'dssa-pmpro-helper'));
            } else {
                wp_send_json_error(__('Failed to delete legacy numbers.', 'dssa-pmpro-helper'));
            }
        }
        
        // Handle single delete
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if (!$id) {
            wp_send_json_error(__('Invalid legacy number ID.', 'dssa-pmpro-helper'));
        }
        
        // Get number before deletion for logging
        $number = $wpdb->get_var($wpdb->prepare(
            "SELECT membership_number FROM {$table_name} WHERE id = %d",
            $id
        ));
        
        // Delete the record
        $deleted = $wpdb->delete(
            $table_name,
            ['id' => $id],
            ['%d']
        );
        
        if ($deleted) {
            // Log the deletion
            if (class_exists('DSSA_PMPro_Helper_Audit_Log')) {
                DSSA_PMPro_Helper_Audit_Log::add_entry(
                    get_current_user_id(),
                    'legacy_number_deleted',
                    [
                        'id' => $id,
                        'membership_number' => $number,
                    ]
                );
            }
            
            wp_send_json_success(__('Legacy number deleted successfully.', 'dssa-pmpro-helper'));
        } else {
            wp_send_json_error(__('Failed to delete legacy number.', 'dssa-pmpro-helper'));
        }
    }
    
    /**
     * AJAX: Export legacy numbers
     */
    public static function ajax_export_legacy_numbers() {
        global $wpdb;
        
        // Verify nonce
        if (!check_ajax_referer('dssa_export_legacy_numbers', 'nonce', false)) {
            wp_die(__('Security check failed.', 'dssa-pmpro-helper'));
        }
        
        // Check permissions
        if (!current_user_can('manage_dssa') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to export legacy numbers.', 'dssa-pmpro-helper'));
        }
        
        $table_name = $wpdb->prefix . 'dssa_legacy_numbers';
        
        // Get IDs to export
        $ids = [];
        if (isset($_GET['ids']) && $_GET['ids'] !== 'all') {
            $ids = array_map('intval', explode(',', $_GET['ids']));
            $ids = array_filter($ids);
        }
        
        // Build query safely
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $query = $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE id IN ({$placeholders}) ORDER BY id",
                $ids
            );
        } else {
            $query = "SELECT * FROM {$table_name} ORDER BY id";
        }
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        if (empty($results)) {
            wp_die(__('No legacy numbers to export.', 'dssa-pmpro-helper'));
        }
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=dssa-legacy-numbers-' . date('Y-m-d-H-i-s') . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8 compatibility
        fwrite($output, "\xEF\xBB\xBF");
        
        // Write header
        fputcsv($output, [
            'ID',
            'Membership Number',
            'Status',
            'Claimed By User ID',
            'Claimed Date',
            'Date Created',
            'Date Modified',
        ]);
        
        // Write data
        foreach ($results as $row) {
            fputcsv($output, [
                $row['id'],
                $row['membership_number'],
                $row['status'],
                $row['claimed_by_user_id'] ?? '',
                $row['claimed_date'] ?? '',
                $row['date_created'],
                $row['date_modified'],
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Enqueue admin scripts
     */
    public static function enqueue_admin_scripts($hook) {
        if ($hook !== 'memberships_page_dssa-legacy-members') {
            return;
        }
        
        wp_enqueue_script(
            'dssa-pmpro-legacy-admin',
            DSSA_PMPRO_HELPER_URL . 'assets/js/legacy-admin.js',
            ['jquery'],
            DSSA_PMPRO_HELPER_VERSION,
            true
        );
        
        wp_localize_script('dssa-pmpro-legacy-admin', 'dssa_pmpro_helper_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonces' => [
                'upload_legacy_csv' => wp_create_nonce('dssa_upload_legacy_csv'),
                'get_legacy_numbers' => wp_create_nonce('dssa_get_legacy_numbers'),
                'update_legacy_number' => wp_create_nonce('dssa_update_legacy_number'),
                'delete_legacy_number' => wp_create_nonce('dssa_delete_legacy_number'),
                'export_legacy_numbers' => wp_create_nonce('dssa_export_legacy_numbers'),
            ],
            'strings' => [
                'confirm_delete' => __('Are you sure you want to delete this legacy number?', 'dssa-pmpro-helper'),
                'confirm_bulk_delete' => __('Are you sure you want to delete the selected legacy numbers?', 'dssa-pmpro-helper'),
                'no_selection' => __('Please select at least one legacy number.', 'dssa-pmpro-helper'),
            ],
        ]);
        
        wp_enqueue_style(
            'dssa-pmpro-admin',
            DSSA_PMPRO_HELPER_URL . 'assets/css/admin.css',
            [],
            DSSA_PMPRO_HELPER_VERSION
        );
    }
}