=== FG PrestaShop to WooCommerce ===
Contributors: Frédéric GILLES
Plugin Uri: https://wordpress.org/plugins/fg-prestashop-to-woocommerce/
Tags: prestashop, woocommerce, wordpress, convert prestashop to woocommerce, migrate prestashop to woocommerce, prestashop to woocommerce migration, migrator, converter, import
Requires at least: 4.0
Tested up to: 4.1.1
Stable tag: 1.13.0
License: GPLv2
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=fred%2egilles%40free%2efr&lc=FR&item_name=fg-prestashop-to-woocommerce&currency_code=EUR&bn=PP%2dDonationsBF%3abtn_donateCC_LG%2egif%3aNonHosted

A plugin to migrate PrestaShop e-commerce solution to WooCommerce

== Description ==

This plugin migrates products, categories, tags, images and CMS from PrestaShop to WooCommerce/WordPress.

It has been tested with **PrestaShop version 1.3, 1.4, 1.5 and 1.6** and **Wordpress 4.1**. It is compatible with multisite installations.

Major features include:

* migrates PrestaShop products
* migrates PrestaShop product images
* migrates PrestaShop product categories
* migrates PrestaShop product tags
* migrates PrestaShop CMS (as posts or pages)

No need to subscribe to an external web site.

= Premium version =

The **Premium version** includes these extra features:

* migrates PrestaShop product features
* migrates PrestaShop product attributes
* migrates PrestaShop product attribute images
* migrates PrestaShop product combinations
* migrates PrestaShop employees
* migrates PrestaShop customers
* migrates PrestaShop orders
* migrates PrestaShop ratings and reviews
* migrates PrestaShop discounts/vouchers (cart rules)
* SEO: Redirect the PrestaShop URLs to the new WordPress URLs
* SEO: Import meta data (browser title, description, keywords, robots) to WordPress SEO
* the employees and customers can authenticate to WordPress using their PrestaShop passwords
* ability to do a partial import

The Premium version can be purchased on: http://www.fredericgilles.net/fg-prestashop-to-woocommerce/

= Add-ons =

The Premium version allows the use of add-ons that enhance functionality:

* Brands : imports the manufacturers

== Installation ==

= Requirements =
WooCommerce must be installed and activated before running the migration.

= Installation =
1.  Install the plugin in the Admin => Plugins menu => Add New => Upload => Select the zip file => Install Now
2.  Activate the plugin in the Admin => Plugins menu
3.  Run the importer in Tools > Import > PrestaShop
4.  Configure the plugin settings. You can find the PrestaShop database parameters in the PrestaShop file settings.inc.php (PrestaShop 1.5+) or in the PrestaShop Preferences > Database tab (PrestaShop 1.4)
5.  Test the database connection
6.  Click on the import button

== Frequently Asked Questions ==

= I get the message: "[fgj2wp] Couldn't connect to the PrestaShop database. Please check your parameters. And be sure the WordPress server can access the PrestaShop database. SQLSTATE[28000] [1045] Access denied for user 'xxx'@'localhost' (using password: YES)" =

* First verify your login and password to your PrestaShop database.
* If PrestaShop and WordPress are not installed on the same host, you can do this:
- export the PrestaShop database to a SQL file (with phpMyAdmin for example)
- import this SQL file on the same database as WordPress
- run the migration by using WordPress database credentials (host, user, password, database) instead of the PrestaShop ones in the plugin settings.

= The import is not complete =

* You can run the migration again and it will continue where it left off.
* You can add: `define('WP_MEMORY_LIMIT', '512M');` in your wp-config.php file to increase the memory allowed by WordPress
* You can also increase the memory limit in php.ini if you have write access to this file (ie: memory_limit = 1G).

= The images aren't being imported =

* Please check the URL field. It must contain the URL of the PrestaShop home page
* Check that the maintenance mode is disabled in PrestaShop
* Use http instead of https in the URL field

= Are the product combinations/attributes imported? =

* This is a Premium feature available on: http://www.fredericgilles.net/fg-prestashop-to-woocommerce/

= Is there a log file to show the information from the import? =

* Yes since version 1.10.0. First you must put these lines in wp-config.php:<br />
define('WP_DEBUG', true);<br />
define('WP_DEBUG_LOG', true);<br />
And the messages will be logged to wp-content/debug.log.

Don't hesitate to let a comment on the forum or to report bugs if you found some.
https://wordpress.org/support/plugin/fg-prestashop-to-woocommerce

== Screenshots ==

1. Parameters screen

== Translations ==
* English (default)
* French (fr_FR)
* other can be translated

== Changelog ==

= 1.13.0 =
* Fixed: Don't import twice the same medias

= 1.12.0 =
* Fixed: Wrong hook used after product insert (fgp2wc_post_insert_product and not fgp2wc_post_insert_post)

= 1.11.0 =
* Fixed: Change the default database prefix to ps_
* FAQ updated

= 1.10.1 =
* Fixed: Wrong images imported when the image legends are not unique

= 1.10.0 =
* New: Log the messages to wp-content/debug.log
* FAQ updated

= 1.9.1 =
* New: Test the presence of WooCommerce before importing
* Tested with WordPress 4.1.1

= 1.9.0 =
* Fixed: Duplicate products when using more than one shop (PrestaShop 1.5+)
* Fixed: Wrong categories assigned to products when there are category slugs duplicates
* Fixed: the prestashop_query() function was returning only one row

= 1.8.2 =
* Fixed: Some images were imported as question marks
* Fixed: Wrong storage directory for the images without a date

= 1.8.1 =
* Tweak: Optimize the speed of images transfer. Don't try to guess the images location for each image.
* Fixed: The products count didn't include the inactive products

= 1.8.0 =
* New: Compatible with PrestaShop 1.3

= 1.7.0 =
* Tested with WordPress 4.1

= 1.6.0 =
* Tweak: Don't display the timeout field if the medias are skipped

= 1.5.0 =
* FAQ updated
* Tested with WordPress 4.0.1

= 1.4.0 =
* Fixed: WordPress database error: [Duplicate entry 'xxx-yyy' for key 'PRIMARY']

= 1.3.1 =
* Fixed: Some images were not imported on PrestaShop 1.4

= 1.3.0 =
* Fixed: Set the products with a null quantity as "Out of stock"
* New: Import the product supplier reference as SKU if the product reference is empty

= 1.2.0 =
* Update the FAQ

= 1.1.1 =
* Fixed: Some images were not imported

= 1.1.0 =
* Compatible with WooCommerce 2.2
* Fixed: Remove the shop_order_status taxonomy according to WooCommerce 2.2
* Fixed: The cover image was not imported as featured image if it was not the first image
* Fixed: Category image path fixed
* Fixed: The product category images were imported even when the "Skip media" option was checked
* Tweak: Simplify the posts count function

= 1.0.0 =
* Initial version: Import PrestaShop products, categories, tags, images and CMS

== Upgrade Notice ==

= 1.12.0 =
Fixed: Wrong hook used after product insert (fgp2wc_post_insert_product and not fgp2wc_post_insert_post)

= 1.11.0 =
Fixed: Change the default database prefix to ps_

= 1.10.1 =
Fixed: Wrong images imported when the image legends are not unique

= 1.10.0 =
New: Log the messages to wp-content/debug.log
FAQ updated

= 1.9.1 =
New: Test the presence of WooCommerce before importing
Tested with WordPress 4.1.1

= 1.9.0 =
Fixed: Duplicate products when using more than one shop (PrestaShop 1.5+)
Fixed: Wrong categories assigned to products when there are category slugs duplicates
Fixed: the prestashop_query() function was returning only one row

= 1.8.2 =
Fixed: Some images were imported as question marks
Fixed: Wrong storage directory for the images without a date

= 1.8.1 =
Tweak: Optimize the speed of images transfer. Don't try to guess the images location for each image.
Fixed: The products count didn't include the inactive products

= 1.8.0 =
New: Compatible with PrestaShop 1.3

= 1.7.0 =
Tested with WordPress 4.1

= 1.6.0 =
Tweak: Don't display the timeout field if the medias are skipped

= 1.5.0 =
Tested with WordPress 4.0.1

= 1.4.0 =
Fixed: WordPress database error: [Duplicate entry 'xxx-yyy' for key 'PRIMARY']

= 1.3.1 =
Fixed: Some images were not imported on PrestaShop 1.4

= 1.3.0 =
Fixed: Set the products with a null quantity as "Out of stock"
New: Import the product supplier reference as SKU if the product reference is empty

= 1.2.0 =
Update the FAQ

= 1.1.1 =
Fixed: Some images were not imported

= 1.1.0 =
Compatible with WooCommerce 2.2
Fixed: Remove the shop_order_status taxonomy according to WooCommerce 2.2
Fixed: The cover image was not imported as featured image if it was not the first image
Fixed: Category image path fixed
Fixed: The product category images were imported even when the "Skip media" option was checked

= 1.0.0 =
Initial version
