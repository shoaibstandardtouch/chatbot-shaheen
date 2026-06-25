jQuery(document).ready(function($) {
    function initBulkPdfUpload() {
        const $form = $('#mxchat-pdf-upload-form');
        const $input = $('#mxchat-pdf-file-input');
        
        if ($form.length && $input.length) {
            // Modify input to support multiple files
            if ($input.attr('multiple') !== 'multiple') {
                $input.attr('multiple', 'multiple');
                $input.attr('name', 'pdf_files[]');
            }
            
            // Update the action URL to hit our custom bulk endpoint
            const currentAction = $form.attr('action');
            if (currentAction && currentAction.includes('action=mxchat_submit_pdf_file')) {
                const newAction = currentAction.replace('action=mxchat_submit_pdf_file', 'action=mxchat_bulk_submit_pdf_file');
                $form.attr('action', newAction);
            }
            
            // Update description text under the input
            const $description = $form.find('.mxch-field-description');
            if ($description.length && !$description.hasClass('mxchat-addon-updated')) {
                $description.text('Select one or more PDF files from your computer to import into the knowledge base in bulk. Maximum file size depends on your server settings.');
                $description.addClass('mxchat-addon-updated');
            }
            
            // Update submit button text
            const $submitBtn = $form.find('button[type="submit"]');
            if ($submitBtn.length && $submitBtn.text() === 'Import PDF') {
                $submitBtn.text('Import PDF(s)');
            }
        }
    }
    
    // Run immediately
    initBulkPdfUpload();
    
    // Also run on dynamic content updates (just in case the DOM gets re-rendered via AJAX)
    $(document).ajaxComplete(function(event, xhr, settings) {
        if (settings.data && (typeof settings.data === 'string') && (settings.data.includes('action=mxchat_get_knowledge') || settings.data.includes('action=mxchat_refresh_table') || settings.data.includes('action=mxchat_get_status_updates'))) {
            setTimeout(initBulkPdfUpload, 500);
        }
    });
});
