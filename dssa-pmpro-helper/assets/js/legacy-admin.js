// assets/js/legacy-admin.js
jQuery(document).ready(function($) {
    // Drag and drop for CSV upload
    var uploadBox = $('.dssa-upload-box');
    
    uploadBox.on('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('drag-over');
    });
    
    uploadBox.on('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('drag-over');
    });
    
    uploadBox.on('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('drag-over');
        
        var files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            $('#dssa-legacy-csv-file')[0].files = files;
        }
    });
    
    // File input change
    $('#dssa-legacy-csv-file').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        if (fileName) {
            uploadBox.html('<p>Selected file: <strong>' + fileName + '</strong></p>' +
                          '<p class="description">Click "Upload CSV" to process the file.</p>');
        }
    });
});