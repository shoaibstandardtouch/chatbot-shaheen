<?php
/**
 * Plugin Name: MxChat Bulk PDF Upload Add-on
 * Description: Adds bulk PDF upload capabilities to the MxChat plugin's Knowledge Base.
 * Version: 1.0.0
 * Author: StandardTouch Developer
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueue the custom JS script on the MxChat Knowledge page.
 */
function mxchat_bulk_pdf_enqueue_scripts() {
    // Check if we are on the MxChat Prompts/Knowledge page
    if (isset($_GET['page']) && $_GET['page'] === 'mxchat-prompts') {
        wp_enqueue_script(
            'mxchat-bulk-pdf-addon',
            plugins_url('js/addon.js', __FILE__),
            array('jquery'),
            '1.0.0',
            true
        );
    }
}
add_action('admin_enqueue_scripts', 'mxchat_bulk_pdf_enqueue_scripts');

/**
 * Handle bulk PDF file submission.
 */
function mxchat_bulk_handle_pdf_file_submission() {
    if (!isset($_POST['submit_pdf_file']) || !current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized access', 'mxchat'));
    }

    check_admin_referer('mxchat_submit_pdf_file_action', 'mxchat_submit_pdf_file_nonce');

    $redirect_url = admin_url('admin.php?page=mxchat-prompts');

    if (empty($_FILES['pdf_files']) || !is_array($_FILES['pdf_files']['name'])) {
        set_transient('mxchat_admin_notice_error', esc_html__('No files were uploaded. Please select one or more PDF files.', 'mxchat'), 30);
        wp_safe_redirect(esc_url($redirect_url));
        exit;
    }

    $files = $_FILES['pdf_files'];
    $bot_id = isset($_POST['bot_id']) ? sanitize_key($_POST['bot_id']) : 'default';
    
    $upload_dir = wp_upload_dir();
    if (isset($upload_dir['error']) && $upload_dir['error'] !== false) {
        set_transient('mxchat_admin_notice_error', esc_html__('WordPress upload directory is not writable.', 'mxchat'), 30);
        wp_safe_redirect(esc_url($redirect_url));
        exit;
    }

    // Load Smalot parser if defined in MxChat
    if (function_exists('mxchat_load_pdf_parser')) {
        mxchat_load_pdf_parser();
    }

    $knowledge_manager = null;
    if (class_exists('MxChat_Knowledge_Manager')) {
        $knowledge_manager = MxChat_Knowledge_Manager::get_instance();
    }

    $pages_by_file = array();
    $processed_files = array();
    $failed_files = array();
    $pdf_paths = array();
    $total_queued_pages = 0;
    
    $file_count = count($files['name']);

    for ($idx = 0; $idx < $file_count; $idx++) {
        if (empty($files['name'][$idx])) {
            continue;
        }

        if ($files['error'][$idx] !== UPLOAD_ERR_OK) {
            $failed_files[] = sprintf('%s (Upload error: %d)', $files['name'][$idx], $files['error'][$idx]);
            continue;
        }

        $tmp_name = $files['tmp_name'][$idx];
        $original_filename = sanitize_file_name($files['name'][$idx]);

        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $tmp_name);
        finfo_close($finfo);

        if ($mime_type !== 'application/pdf') {
            $failed_files[] = sprintf('%s (Invalid file type. Only PDF files are accepted.)', $original_filename);
            continue;
        }

        // Validate extension
        $ext = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            $failed_files[] = sprintf('%s (Invalid extension. Only .pdf files are accepted.)', $original_filename);
            continue;
        }

        $pdf_filename = sanitize_file_name('mxchat_kb_' . time() . '_' . $idx . '.pdf');
        $pdf_path = trailingslashit($upload_dir['path']) . $pdf_filename;

        if (!wp_mkdir_p(dirname($pdf_path))) {
            $failed_files[] = sprintf('%s (WordPress upload directory is not writable.)', $original_filename);
            continue;
        }

        // Move uploaded file
        if (!move_uploaded_file($tmp_name, $pdf_path)) {
            $failed_files[] = sprintf('%s (Failed to save uploaded PDF file.)', $original_filename);
            continue;
        }

        // Count PDF pages
        $total_pages = 0;

        // Try Smalot PDF Parser
        try {
            if (class_exists('\\Smalot\\PdfParser\\Parser')) {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($pdf_path);
                $pages = $pdf->getPages();
                $total_pages = count($pages);
            }
        } catch (Exception $e) {
            // error_log('Addon Smalot PDF parser failed: ' . $e->getMessage());
        }

        // Fallback to Reflection on private method
        if ($total_pages === 0 && $knowledge_manager) {
            try {
                $ref = new ReflectionMethod('MxChat_Knowledge_Manager', 'mxchat_validate_and_count_pdf_pages');
                $ref->setAccessible(true);
                $total_pages = $ref->invoke($knowledge_manager, $pdf_path);
            } catch (Exception $e) {
                // error_log('Addon reflection fallback failed: ' . $e->getMessage());
            }
        }

        if ($total_pages === false || $total_pages < 1) {
            wp_delete_file($pdf_path);
            $failed_files[] = sprintf('%s (Invalid PDF: Unable to parse or no pages found)', $original_filename);
            continue;
        }

        $pdf_paths[] = $pdf_path;
        $processed_files[] = $original_filename;

        $source_label = 'upload://' . $original_filename;
        
        for ($i = 1; $i <= $total_pages; $i++) {
            $pages_by_file[] = array(
                'pdf_path'    => $pdf_path,
                'pdf_url'     => $source_label,
                'page_number' => $i,
                'total_pages' => $total_pages,
            );
        }
        
        $total_queued_pages += $total_pages;
    }

    if (empty($pages_by_file)) {
        $error_msg = __('Failed to process uploaded PDF files: ', 'mxchat') . implode(', ', $failed_files);
        set_transient('mxchat_admin_notice_error', $error_msg, 30);
        wp_safe_redirect(esc_url($redirect_url));
        exit;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_processing_queue';
    $meta_table = $wpdb->prefix . 'mxchat_queue_meta';

    // Create a single queue ID for all items
    $queue_id = 'pdf_' . md5(implode('_', $processed_files) . time());

    // Add items to queue using the database schema directly
    $queued_count = 0;
    $priority = 0;
    
    foreach ($pages_by_file as $item) {
        $result = $wpdb->insert(
            $table_name,
            array(
                'queue_id'     => $queue_id,
                'item_type'    => 'pdf_page',
                'item_data'    => wp_json_encode($item),
                'status'       => 'pending',
                'bot_id'       => $bot_id,
                'priority'     => $priority,
                'attempts'     => 0,
                'max_attempts' => 3,
                'created_at'   => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s')
        );
        if ($result) {
            $queued_count++;
        }
        $priority++;
    }

    if ($queued_count === 0) {
        foreach ($pdf_paths as $path) {
            wp_delete_file($path);
        }
        set_transient('mxchat_admin_notice_error', esc_html__('Failed to add PDF pages to processing queue', 'mxchat'), 30);
        wp_safe_redirect(esc_url($redirect_url));
        exit;
    }

    // Set queue metadata
    $source_url_label = count($processed_files) === 1 
        ? 'upload://' . $processed_files[0] 
        : sprintf('upload://%s and %d other files', $processed_files[0], count($processed_files) - 1);

    // Save metadata key helper function
    $set_meta = function($q_id, $key, $val) use ($wpdb, $meta_table) {
        $wpdb->insert(
            $meta_table,
            array(
                'queue_id'   => $q_id,
                'meta_key'   => $key,
                'meta_value' => maybe_serialize($val)
            ),
            array('%s', '%s', '%s')
        );
    };

    $set_meta($queue_id, 'source_url', $source_url_label);
    $set_meta($queue_id, 'queue_type', 'pdf');
    $set_meta($queue_id, 'total_items', $total_queued_pages);
    $set_meta($queue_id, 'bot_id', $bot_id);
    $set_meta($queue_id, 'pdf_path', $pdf_paths); // Store array of paths
    $set_meta($queue_id, 'created_at', current_time('mysql'));

    set_transient('mxchat_active_queue_pdf', $queue_id, DAY_IN_SECONDS);
    set_transient('mxchat_last_pdf_url', $source_url_label, DAY_IN_SECONDS);

    // Prepare notice message
    $success_msg = sprintf(
        esc_html__('Successfully queued %d files (%d total pages) for background processing. Processing will start automatically.', 'mxchat'),
        count($processed_files),
        $total_queued_pages
    );

    if (!empty($failed_files)) {
        $success_msg .= ' ' . sprintf(
            esc_html__('Some files failed: %s', 'mxchat'),
            implode(', ', $failed_files)
        );
    }

    set_transient('mxchat_admin_notice_success', $success_msg, 30);
    wp_safe_redirect(esc_url($redirect_url));
    exit;
}
add_action('admin_post_mxchat_bulk_submit_pdf_file', 'mxchat_bulk_handle_pdf_file_submission');

/**
 * Hook into active PDF queue transient deletion to clean up the stored PDF files.
 */
function mxchat_bulk_clean_up_pdfs_on_complete() {
    $queue_id = get_transient('mxchat_active_queue_pdf');
    if (!$queue_id) {
        return;
    }

    global $wpdb;
    $meta_table = $wpdb->prefix . 'mxchat_queue_meta';
    
    $pdf_paths_raw = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM $meta_table WHERE queue_id = %s AND meta_key = 'pdf_path'",
        $queue_id
    ));

    if ($pdf_paths_raw) {
        $pdf_paths = maybe_unserialize($pdf_paths_raw);
        if (is_array($pdf_paths)) {
            foreach ($pdf_paths as $path) {
                if ($path && file_exists($path)) {
                    wp_delete_file($path);
                }
            }
        } else if (is_string($pdf_paths)) {
            // Check if it's single file path or JSON string
            $decoded = json_decode($pdf_paths, true);
            if (is_array($decoded)) {
                foreach ($decoded as $path) {
                    if ($path && file_exists($path)) {
                        wp_delete_file($path);
                    }
                }
            } else if ($pdf_paths && file_exists($pdf_paths)) {
                wp_delete_file($pdf_paths);
            }
        }
    }
}
add_action('delete_transient_mxchat_active_queue_pdf', 'mxchat_bulk_clean_up_pdfs_on_complete');
