<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Chỉ xoá cài đặt. Bài đã đăng vẫn giữ nguyên nội dung; CSS của bài nằm trong meta _smc_css nên cũng giữ lại.
delete_option( 'smc_settings' );
