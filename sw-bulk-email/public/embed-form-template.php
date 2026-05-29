<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body>
	<?php
	// Since this is a bare template, we need to manually instantiate the
	// SW_Optin class if it hasn't been already.
	if ( ! class_exists( 'SW_Optin' ) ) {
		require_once SW_BULK_EMAIL_DIR . 'includes/class-sw-optin.php';
		new SW_Optin();
	}
	echo do_shortcode( '[sw_optin_form]' );
	?>
	<?php wp_footer(); ?>
</body>
</html>
