<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;
?>
<script type="text/javascript">
	jQuery(document).ready(function($) {
		function hide_unhide_media() {
			$("#media_import_box").toggle(!$("#skip_media").is(':checked'));
		}
		$("#skip_media").bind('click', hide_unhide_media);
		hide_unhide_media();
	});
</script>
<div class="wrap" style="float: left;">
	<?php screen_icon(); ?>
	<h2><?php print $data['title'] ?></h2>
	
	<p><?php print $data['description'] ?></p>
	
	<div style="float:left; max-width:724px;">
		<div style="border: 1px solid #cccccc; background: #faebd7; margin: 10px; padding: 2px 10px;">
			<h3><?php _e('WordPress database', 'fgp2wc') ?></h3>
			<?php foreach ( $data['database_info'] as $data_row ): ?>
				<?php print $data_row; ?><br />
			<?php endforeach; ?>
		</div>
		
		<form action="" method="post" onsubmit="return check_empty_content_option()">
			<?php wp_nonce_field( 'empty', 'fgp2wc_nonce' ); ?>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php _e('If you want to restart the import from scratch, you must empty the WordPress content with the button hereafter.', 'fgp2wc'); ?></th>
					<td><input type="radio" name="empty_action" id="empty_action_newposts" value="newposts" /> <label for="empty_action_newposts"><?php _e('Remove only new imported posts', 'fgp2wc'); ?></label><br />
					<input type="radio" name="empty_action" id="empty_action_all" value="all" /> <label for="empty_action_all"><?php _e('Remove all WordPress content', 'fgp2wc'); ?></label><br />
					<?php submit_button( __('Empty WordPress content', 'fgp2wc'), 'primary', 'empty' ); ?></td>
				</tr>
			</table>
		</form>
		
		<form action="" method="post">

			<?php wp_nonce_field( 'parameters_form', 'fgp2wc_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><?php _e('Automatic removal:', 'fgp2wc'); ?></th>
					<td><input id="automatic_empty" name="automatic_empty" type="checkbox" value="1" <?php checked($data['automatic_empty'], 1); ?> /> <label for="automatic_empty" ><?php _e('Automatically remove all the WordPress content before each import', 'fgp2wc'); ?></label></td>
				</tr>
				<tr>
					<th scope="row" colspan="2"><h3><?php _e('PrestaShop web site parameters', 'fgp2wc'); ?></h3></th>
				</tr>
				<tr>
					<th scope="row"><label for="url"><?php _e('URL (beginning with http://)', 'fgp2wc'); ?></label></th>
					<td><input id="url" name="url" type="text" size="50" value="<?php echo $data['url']; ?>" /></td>
				</tr>
				<tr>
					<th scope="row" colspan="2"><h3><?php _e('PrestaShop database parameters', 'fgp2wc'); ?></h3></th>
				</tr>
				<tr>
					<th scope="row"><label for="hostname"><?php _e('Hostname', 'fgp2wc'); ?></label></th>
					<td><input id="hostname" name="hostname" type="text" size="50" value="<?php echo $data['hostname']; ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="port"><?php _e('Port', 'fgp2wc'); ?></label></th>
					<td><input id="port" name="port" type="text" size="50" value="<?php echo $data['port']; ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="database"><?php _e('Database', 'fgp2wc'); ?></label></th>
					<td><input id="database" name="database" type="text" size="50" value="<?php echo $data['database']; ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="username"><?php _e('Username', 'fgp2wc'); ?></label></th>
					<td><input id="username" name="username" type="text" size="50" value="<?php echo $data['username']; ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="password"><?php _e('Password', 'fgp2wc'); ?></label></th>
					<td><input id="password" name="password" type="password" size="50" value="<?php echo $data['password']; ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="prefix"><?php _e('PrestaShop Table Prefix', 'fgp2wc'); ?></label></th>
					<td><input id="prefix" name="prefix" type="text" size="50" value="<?php echo $data['prefix']; ?>" /></td>
				</tr>
				<tr>
					<th scope="row">&nbsp;</th>
					<td><?php submit_button( __('Test the connection', 'fgp2wc'), 'secondary', 'test' ); ?></td>
				</tr>
				<tr>
					<th scope="row" colspan="2"><h3><?php _e('Behavior', 'fgp2wc'); ?></h3></th>
				</tr>
				<tr>
					<th scope="row"><?php _e('Medias:', 'fgp2wc'); ?></th>
					<td><input id="skip_media" name="skip_media" type="checkbox" value="1" <?php checked($data['skip_media'], 1); ?> /> <label for="skip_media" ><?php _e('Skip media', 'fgp2wc'); ?></label>
					<br />
					<div id="media_import_box">
						<?php _e('Import first image:', 'fgp2wc'); ?>&nbsp;
						<input id="first_image_as_is" name="first_image" type="radio" value="as_is" <?php checked($data['first_image'], 'as_is'); ?> /> <label for="first_image_as_is" title="<?php _e('The first image will be kept in the post content', 'fgp2wc'); ?>"><?php _e('as is', 'fgp2wc'); ?></label>&nbsp;&nbsp;
						<input id="first_image_as_featured" name="first_image" type="radio" value="as_featured" <?php checked($data['first_image'], 'as_featured'); ?> /> <label for="first_image_as_featured" title="<?php _e('The first image will be removed from the post content and imported as the featured image only', 'fgp2wc'); ?>"><?php _e('as featured only', 'fgp2wc'); ?></label>&nbsp;&nbsp;
						<input id="first_image_as_is_and_featured" name="first_image" type="radio" value="as_is_and_featured" <?php checked($data['first_image'], 'as_is_and_featured'); ?> /> <label for="first_image_as_is_and_featured" title="<?php _e('The first image will be kept in the post content and imported as the featured image', 'fgp2wc'); ?>"><?php _e('as is and as featured', 'fgp2wc'); ?></label>
						<br />
						<input id="import_external" name="import_external" type="checkbox" value="1" <?php checked($data['import_external'], 1); ?> /> <label for="import_external"><?php _e('Import external media', 'fgp2wc'); ?></label>
						<br />
						<input id="import_duplicates" name="import_duplicates" type="checkbox" value="1" <?php checked($data['import_duplicates'], 1); ?> /> <label for="import_duplicates" title="<?php _e('Checked: download the media with their full path in order to import media with identical names.', 'fgp2wc'); ?>"><?php _e('Import media with duplicate names', 'fgp2wc'); ?></label>
						<br />
						<input id="force_media_import" name="force_media_import" type="checkbox" value="1" <?php checked($data['force_media_import'], 1); ?> /> <label for="force_media_import" title="<?php _e('Checked: download the media even if it has already been imported. Unchecked: Download only media which were not already imported.', 'fgp2wc'); ?>" ><?php _e('Force media import. Keep unchecked except if you had previously some media download issues.', 'fgp2wc'); ?></label>
						<br />
						<input id="first_image_not_in_gallery" name="first_image_not_in_gallery" type="checkbox" value="1" <?php checked($data['first_image_not_in_gallery'], 1, 1); ?> /> <label for="first_image_not_in_gallery"><?php _e("Don't include the first image into the product gallery", 'fgp2wc'); ?></label>
						<br />
						<?php _e('Timeout for each media:', 'fgp2wc'); ?>&nbsp;
						<input id="timeout" name="timeout" type="text" size="5" value="<?php echo $data['timeout']; ?>" /> <?php _e('seconds', 'fgp2wc'); ?>
					</div></td>
				</tr>
				<tr><th><?php _e('Import prices:', 'fgp2wc'); ?></th>
					<td>
						<input type="radio" name="price" id="price_without_tax" value="without_tax" <?php checked($data['price'], 'without_tax', 1); ?> /> <label for="price_without_tax"><?php _e('excluding tax', 'fgp2wc'); ?></label>
						<input type="radio" name="price" id="price_with_tax" value="with_tax" <?php checked($data['price'], 'with_tax', 1); ?> /> <label for="price_with_tax"><?php _e('including tax <small>in this case, you must define a default tax rate before running the import</small>', 'fgp2wc'); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e('Meta keywords:', 'fgp2wc'); ?></th>
					<td><input id="meta_keywords_in_tags" name="meta_keywords_in_tags" type="checkbox" value="1" <?php checked($data['meta_keywords_in_tags'], 1); ?> /> <label for="meta_keywords_in_tags" ><?php _e('Import meta keywords as tags', 'fgp2wc'); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php _e('Create pages:', 'fgp2wc'); ?></th>
					<td><input id="import_as_pages" name="import_as_pages" type="checkbox" value="1" <?php checked($data['import_as_pages'], 1); ?> /> <label for="import_as_pages" ><?php _e('Import the CMS as pages instead of posts (without categories)', 'fgp2wc'); ?></label></td>
				</tr>
				<tr>
					<th scope="row">&nbsp;</th>
					<td><?php submit_button( __('Save settings', 'fgp2wc'), 'secondary', 'save' ); ?>
					<?php submit_button( __('Import content from PrestaShop to WordPress', 'fgp2wc'), 'primary', 'import' ); ?></td>
				</tr>
			</table>
		</form>
		
	</div>
	
	<div style="float:left; width:300px;">
		<h3><?php _e('Do you need extra features?', 'fgp2wc'); ?></h3>
		<ul style="list-style:disc inside">
			<li><?php _e('Product features import', 'fgp2wc'); ?></li>
			<li><?php _e('Product combinations import', 'fgp2wc'); ?></li>
			<li><?php _e('Employees import', 'fgp2wc'); ?></li>
			<li><?php _e('Customers import', 'fgp2wc'); ?></li>
			<li><?php _e('Orders import', 'fgp2wc'); ?></li>
			<li><?php _e('Ratings and reviews import', 'fgp2wc'); ?></li>
			<li><?php _e('Discounts/vouchers import', 'fgp2wc'); ?></li>
			<li><?php _e('SEO: Prestashop URLs redirect', 'fgp2wc'); ?></li>
			<li><?php _e('SEO: Meta data import (title, description and keywords)', 'fgp2wc'); ?></li>
			<li><?php _e('Manufacturers import', 'fgp2wc'); ?><sup>*</sup></li>
		</ul>
		<div style="text-align: center;">
			<a href="http://www.fredericgilles.net/fg-prestashop-to-woocommerce/" target="_blank"><img src="http://www.fredericgilles.net/wp-content/uploads/premium-version.png" alt="Buy Premium Version" /></a>
		</div>
		<p><sup>*</sup><?php _e('This feature needs an add-on in addition to the Premium version.', 'fgj2wp'); ?></p>
		<hr />
		<p><?php _e('If you found this plugin useful and it saved you many hours or days, please rate it on <a href="https://wordpress.org/plugins/fg-prestashop-to-woocommerce/">FG PrestaShop to WooCommerce</a>. You can also make a donation using the button below.', 'fgp2wc'); ?></p>
		
		<div style="text-align: center; margin-top:20px;">
			<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_blank">
				<input type="hidden" name="cmd" value="_s-xclick">
				<input type="hidden" name="hosted_button_id" value="HBQNNBW89W9KS">
				<input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">
				<img alt="" border="0" src="https://www.paypalobjects.com/fr_FR/i/scr/pixel.gif" width="1" height="1">
			</form>
		</div>
	</div>	
</div>
<script type="text/javascript">
	function check_empty_content_option() {
		var confirm_message;
		var action = jQuery('input:radio[name=empty_action]:checked').val();
		switch ( action ) {
			case 'newposts':
				confirm_message = '<?php _e('All new imported posts or pages and their comments will be deleted from WordPress.', 'fgp2wc'); ?>';
				break;
			case 'all':
				confirm_message = '<?php _e('All content will be deleted from WordPress.', 'fgp2wc'); ?>';
				break;
			default:
				alert('<?php _e('Please select a remove option.', 'fgp2wc'); ?>');
				return false;
				break;
		}
		return confirm(confirm_message);
	}
</script>
