<?php
/*
Plugin Name: Paid Memberships Pro
Plugin URI: http://www.paidmembershipspro.com
Description: Plugin to Handle Memberships
Version: 1.3.17
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
*/
/*
	Copyright 2011	Stranger Studios	(email : jason@strangerstudios.com)
	GPLv2 Full license details in license.txt
*/

//if the session has been started yet, start it (ignore if running from command line)
if(defined('STDIN') )
{
	//command line
}
else
{
	if(!session_id())
		session_start();
}

//require_once(ABSPATH . '/wp-admin/upgrade-functions.php');
require_once(ABSPATH . "/wp-content/plugins/paid-memberships-pro/includes/lib/name-parser.php");
require_once(ABSPATH . "/wp-content/plugins/paid-memberships-pro/includes/functions.php");
require_once(ABSPATH . "/wp-content/plugins/paid-memberships-pro/includes/upgradecheck.php");
require_once(ABSPATH . "/wp-content/plugins/paid-memberships-pro/scheduled/crons.php");
require_once(ABSPATH . "/wp-content/plugins/paid-memberships-pro/classes/class.memberorder.php");
require_once(ABSPATH . "/wp-content/plugins/paid-memberships-pro/classes/class.pmproemail.php");
require_once(ABSPATH . "/wp-includes/class-phpmailer.php");

//setup the DB
global $wpdb;
pmpro_checkForUpgrades();

define("SITENAME", str_replace("&#039;", "'", get_bloginfo("name")));
$urlparts = explode("//", home_url());
define("SITEURL", $urlparts[1]);
define("SECUREURL", str_replace("http://", "https://", get_bloginfo("wpurl")));
define("PMPRO_URL", WP_PLUGIN_URL . "/paid-memberships-pro");
define("PMPRO_VERSION", "1.3.17");

global $gateway_environment;
$gateway_environment = pmpro_getOption("gateway_environment");

global $all_membership_levels; //when checking levels, we save the info here for caching

function pmpro_memberslist()
{
	require_once(dirname(__FILE__) . "/adminpages/memberslist.php");
}

function pmpro_discountcodes()
{
	require_once(dirname(__FILE__) . "/adminpages/discountcodes.php");
}


function pmpro_membershiplevels()
{
	require_once(dirname(__FILE__) . "/adminpages/membershiplevels.php");
}

function pmpro_pagesettings()
{
	require_once(dirname(__FILE__) . "/adminpages/pagesettings.php");
}

function pmpro_paymentsettings()
{
	require_once(dirname(__FILE__) . "/adminpages/paymentsettings.php");
}

function pmpro_emailsettings()
{
	require_once(dirname(__FILE__) . "/adminpages/emailsettings.php");
}

function pmpro_advancedsettings()
{
	require_once(dirname(__FILE__) . "/adminpages/advancedsettings.php");
}

function pmpro_set_current_user()
{
	//this code runs at the beginning of the plugin
	global $current_user, $wpdb;
	get_currentuserinfo();
	$id = intval($current_user->ID);
	if($id)
	{
		$current_user->membership_level = pmpro_getMembershipLevelForUser($current_user->ID);
		if(!empty($current_user->membership_level->ID))
		{
			$user_pricing = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_memberships_users WHERE user_id = '" . $current_user->ID . "' LIMIT 1");
			if($user_pricing->billing_amount !== NULL)
			{
				$current_user->membership_level->billing_amount = $user_pricing->billing_amount;
				$current_user->membership_level->cycle_number = $user_pricing->cycle_number;
				$current_user->membership_level->cycle_period = $user_pricing->cycle_period;
				$current_user->membership_level->billing_limit = $user_pricing->billing_limit;
				$current_user->membership_level->trial_amount = $user_pricing->trial_amount;
				$current_user->membership_level->trial_limit = $user_pricing->trial_limit;
			}

			$categories = $wpdb->get_results("SELECT c.category_id
												FROM {$wpdb->pmpro_memberships_categories} AS c
												WHERE c.membership_id = '" . $current_user->membership_level->ID . "'", ARRAY_N);

			$current_user->membership_level->categories = array();
			if(is_array($categories))
			{
				foreach ( $categories as $cat )
				{
				  $current_user->membership_level->categories[] = $cat;
				}
			}
		}
	}

	//hiding ads?
	$hideads = pmpro_getOption("hideads");
	$hideadslevels = explode(",", pmpro_getOption("hideadslevels"));
	if($hideads && $hideadslevels)
	{
		if(in_array($current_user->membership_level->ID, $hideadslevels))
		{
			//disable ads in ezAdsense
			if(class_exists("ezAdSense"))
			{
				global $ezCount, $urCount;
				$ezCount = 100;
				$urCount = 100;
			}

			//set a global variable to hide ads
			global $pmpro_display_ads;
			$pmpro_display_ads = false;
		}
	}

	do_action("pmpro_after_set_current_user");
}
add_action('set_current_user', 'pmpro_set_current_user');

//init code
function pmpro_is_ready()
{
	global $wpdb, $pmpro_pages, $pmpro_level_ready, $pmpro_gateway_ready, $pmpro_pages_ready;

	//check if there is at least one level
	$pmpro_level_ready = (bool)$wpdb->get_var("SELECT id FROM $wpdb->pmpro_membership_levels LIMIT 1");

	//check if the gateway settings are good. first check if it's needed (is there paid membership level)
	$paid_membership_level = $wpdb->get_var("SELECT id FROM $wpdb->pmpro_membership_levels WHERE allow_signups = 1 AND (initial_payment > 0 OR billing_amount > 0 OR trial_amount > 0) LIMIT 1");
	$paid_user_subscription = $wpdb->get_var("SELECT user_id FROM $wpdb->pmpro_memberships_users WHERE initial_payment > 0 OR billing_amount > 0 OR trial_amount > 0 LIMIT 1");

	if(empty($paid_membership_level) && empty($paid_user_subscription))
	{
		//no paid membership level now or attached to a user. we don't need the gateway setup
		$pmpro_gateway_ready = true;
	}
	else
	{
		$gateway = pmpro_getOption("gateway");
		if($gateway == "authorizenet")
		{
			if(pmpro_getOption("gateway_environment") && pmpro_getOption("loginname") && pmpro_getOption("transactionkey"))
				$pmpro_gateway_ready = true;
			else
				$pmpro_gateway_ready = false;
		}
		elseif($gateway == "paypal" || $gateway == "paypalexpress")
		{
			if(pmpro_getOption("gateway_environment") && pmpro_getOption("gateway_email") && pmpro_getOption("apiusername") && pmpro_getOption("apipassword") && pmpro_getOption("apisignature"))
				$pmpro_gateway_ready = true;
			else
				$pmpro_gateway_ready = false;
		}
		else
		{
			$pmpro_gateway_ready = false;
		}
	}

	//check if we have all pages
	if($pmpro_pages["account"] &&
	   $pmpro_pages["billing"] &&
	   $pmpro_pages["cancel"] &&
	   $pmpro_pages["checkout"] &&
	   $pmpro_pages["confirmation"] &&
	   $pmpro_pages["invoice"] &&
	   $pmpro_pages["levels"])
		$pmpro_pages_ready = true;
	else
		$pmpro_pages_ready = false;

	//now check both
	if($pmpro_gateway_ready && $pmpro_pages_ready)
		return true;
	else
		return false;
}
function pmpro_init()
{
	require_once(ABSPATH . "/wp-content/plugins/paid-memberships-pro/includes/countries.php");
	require_once(ABSPATH . "/wp-content/plugins/paid-memberships-pro/includes/currencies.php");

	wp_enqueue_script('ssmemberships_js', '/wp-content/plugins/paid-memberships-pro/js/paid-memberships-pro.js', array('jquery'));

	global $pmpro_pages, $pmpro_ready, $pmpro_currency, $pmpro_currency_symbol;
	$pmpro_pages = array();
	$pmpro_pages["account"] = pmpro_getOption("account_page_id");
	$pmpro_pages["billing"] = pmpro_getOption("billing_page_id");
	$pmpro_pages["cancel"] = pmpro_getOption("cancel_page_id");
	$pmpro_pages["checkout"] = pmpro_getOption("checkout_page_id");
	$pmpro_pages["confirmation"] = pmpro_getOption("confirmation_page_id");
	$pmpro_pages["invoice"] = pmpro_getOption("invoice_page_id");
	$pmpro_pages["levels"] = pmpro_getOption("levels_page_id");

	$pmpro_ready = pmpro_is_ready();

	//set currency
	$pmpro_currency = pmpro_getOption("currency");
	if(!$pmpro_currency)
	{
		global $pmpro_default_currency;
		$pmpro_currency = $pmpro_default_currency;
	}

	//figure out what symbol to show for currency
	if(in_array($pmpro_currency, array("USD", "AUD", "BRL", "CAD", "HKD", "MXN", "NZD", "SGD")))
		$pmpro_currency_symbol = "&#36;";
	elseif($pmpro_currency == "EUR")
		$pmpro_currency_symbol = "&euro;";
	elseif($pmpro_currency == "GBP")
		$pmpro_currency_symbol = "&pound;";
	elseif($pmpro_currency == "JPY")
		$pmpro_currency_symbol = "&yen;";
	else
		$pmpro_currency_symbol = $pmpro_currency . " ";	//just use the code
}
add_action("init", "pmpro_init");

//this code runs after $post is set, but before template output
function pmpro_wp()
{
	if(!is_admin())
	{
		global $post, $pmpro_pages, $pmpro_page_name, $pmpro_page_id;

		//run the appropriate preheader function
		foreach($pmpro_pages as $pmpro_page_name => $pmpro_page_id)
		{
			if($pmpro_page_id == $post->ID)
			{
				include(ABSPATH . "/wp-content/plugins/paid-memberships-pro/preheaders/" . $pmpro_page_name . ".php");

				function pmpro_pages_shortcode($atts, $content=null, $code="")
				{
					global $pmpro_page_name;
					ob_start();
					include(plugin_dir_path(__FILE__) . "/pages/" . $pmpro_page_name . ".php");
					$temp_content = ob_get_contents();
					ob_end_clean();
					return $temp_content;
				}
				add_shortcode("pmpro_" . $pmpro_page_name, "pmpro_pages_shortcode");
				break;	//only the first page found gets a shortcode replacement
			}
		}
	}
}
add_action("wp", "pmpro_wp");

function pmpro_membership_level_profile_fields($user)
{
	global $current_user;
	if(!current_user_can("administrator"))
		return false;

	global $wpdb;
	$user->membership_level = $wpdb->get_row("SELECT l.id AS ID, l.name AS name
														FROM {$wpdb->pmpro_membership_levels} AS l
														JOIN {$wpdb->pmpro_memberships_users} AS mu ON (l.id = mu.membership_id)
														WHERE mu.user_id = " . $user->ID . "
														LIMIT 1");

	$levels = $wpdb->get_results( "SELECT * FROM {$wpdb->pmpro_membership_levels}", OBJECT );

	if(!$levels)
		return "";
?>
<h3><?php _e("Membership Level", "blank"); ?></h3>
<table class="form-table">
    <?php
		$show_membership_level = true;
		$show_membership_level = apply_filters("pmpro_profile_show_membership_level", $show_membership_level, $user);
		if($show_membership_level)
		{
		?>
		<tr>
			<th><label for="membership_level"><?php _e("Current Level"); ?></label></th>
			<td>
				<select name="membership_level" onchange="pmpro_mchange_warning();">
					<option value="" <?php if(!$user->membership_level->ID) { ?>selected="selected"<?php } ?>>-- None --</option>
				<?php
					foreach($levels as $level)
					{
						$current_level = ($user->membership_level->ID == $level->id);
				?>
					<option value="<?php echo $level->id?>" <?php if($current_level) { ?>selected="selected"<?php } ?>><?php echo $level->name?></option>
				<?php
					}
				?>
				</select>
				<script>
					var pmpro_mchange_once = 0;
					function pmpro_mchange_warning()
					{
						if(pmpro_mchange_once == 0)
						{
							alert('Warning: The existing membership will be canceled, and the new membership will be free.');
							pmpro_mchange_once = 1;
						}
					}
				</script>
				<?php
					$membership_values = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_memberships_users WHERE user_id = '" . $user->ID . "' LIMIT 1");
					if($membership_values->billing_amount > 0 || $membership_values->trial_amount > 0)
					{
					?>
						<?php if($membership_values->billing_amount > 0) { ?>
							at $<?php echo $membership_values->billing_amount?>
							<?php if($membership_values->cycle_number > 1) { ?>
								per <?php echo $membership_values->cycle_number?> <?php echo sornot($membership_values->cycle_period,$membership_values->cycle_number)?>
							<?php } elseif($membership_values->cycle_number == 1) { ?>
								per <?php echo $membership_values->cycle_period?>
							<?php } ?>
						<?php } ?>

						<?php if($membership_values->billing_limit) { ?> for <?php echo $membership_values->billing_limit.' '.sornot($membership_values->cycle_period,$membership_values->billing_limit)?><?php } ?>.

						<?php if($membership_values->trial_limit) { ?>
							The first <?php echo $membership_values->trial_limit?> <?php echo sornot("payments",$membership_values->trial_limit)?> will cost $<?php echo $membership_values->trial_amount?>.
						<?php } ?>
					<?php
					}
					else
					{
					?>
						User is not paying.
					<?php
					}
				?>
			</td>
		</tr>
		<?php
		}
		
		$show_expiration = true;
		$show_expiration = apply_filters("pmpro_profile_show_expiration", $show_expiration, $user);
		if($show_expiration)
		{					
			//is there an end date?
			$user->membership_level = pmpro_getMembershipLevelForUser($user->ID);
			$end_date = !empty($user->membership_level->enddate);
			
			//some vars for the dates
			$current_day = date("j");			
			if($end_date)
				$selected_expires_day = date("j", $user->membership_level->enddate);
			else
				$selected_expires_day = $current_day;
				
			$current_month = date("M");			
			if($end_date)
				$selected_expires_month = date("m", $user->membership_level->enddate);
			else
				$selected_expires_month = date("m");
				
			$current_year = date("Y");									
			if($end_date)
				$selected_expires_year = date("Y", $user->membership_level->enddate);
			else
				$selected_expires_year = (int)$current_year + 1;
		?>
		<tr>
			<th><label for="expiration"><?php _e("Expires"); ?></label></th>
			<td>
				<select id="expires" name="expires">
					<option value="0" <?php if(!$end_date) { ?>selected="selected"<?php } ?>>No</option>
					<option value="1" <?php if($end_date) { ?>selected="selected"<?php } ?>>Yes</option>
				</select>
				<span id="expires_date" <?php if(!$end_date) { ?>style="display: none;"<?php } ?>>
					on
					<select name="expires_month">
						<?php																
							for($i = 1; $i < 13; $i++)
							{
							?>
							<option value="<?php echo $i?>" <?php if($i == $selected_expires_month) { ?>selected="selected"<?php } ?>><?php echo date("M", strtotime($i . "/1/" . $current_year))?></option>
							<?php
							}
						?>
					</select>
					<input name="expires_day" type="text" size="2" value="<?php echo $selected_expires_day?>" />
					<input name="expires_year" type="text" size="4" value="<?php echo $selected_expires_year?>" />
				</span>
				<script>
					jQuery('#expires').change(function() {
						if(jQuery(this).val() == 1)
							jQuery('#expires_date').show();
						else
							jQuery('#expires_date').hide();
					});
				</script>
			</td>
		</tr>
		<?php
		}
		?>
</table>
<?php
}

function pmpro_membership_level_profile_fields_update()
{
	//get the user id
	global $wpdb, $current_user, $user_ID;
	get_currentuserinfo();
	
	if(!empty($_REQUEST['user_id'])) 
		$user_ID = $_REQUEST['user_id'];

	if(!current_user_can( 'edit_user', $user_ID))
		return false;
		
	//level change
	if(isset($_REQUEST['membership_level']))
	{
		if(pmpro_changeMembershipLevel($_REQUEST['membership_level'], $user_ID))
		{
			//it changed. send email
			$level_changed = true;
		}		
	}
	
	//expiration change
	if(!empty($_REQUEST['expires']))
	{
		//update the expiration date
		$expiration_date = intval($_REQUEST['expires_year']) . "-" . intval($_REQUEST['expires_month']) . "-" . intval($_REQUEST['expires_day']);
		$sqlQuery = "UPDATE $wpdb->pmpro_memberships_users SET enddate = '" . $expiration_date . "' WHERE user_id = '" . $user_ID . "' LIMIT 1";
		if($wpdb->query($sqlQuery))
			$expiration_changed = true;
	}
	else
	{
		//null out the expiration
		$sqlQuery = "UPDATE $wpdb->pmpro_memberships_users SET enddate = NULL WHERE user_id = '" . $user_ID . "' LIMIT 1";
		if($wpdb->query($sqlQuery))
			$expiration_changed = true;
	}
	
	//send email
	if(!empty($level_changed) || !empty($expiration_changed))
	{
		$pmproemail = new PMProEmail();
		if(!empty($expiration_changed))
			$pmproemail->expiration_changed = true;
		$pmproemail->sendAdminChangeEmail(get_userdata($user_ID));
	}
}
add_action( 'show_user_profile', 'pmpro_membership_level_profile_fields' );
add_action( 'edit_user_profile', 'pmpro_membership_level_profile_fields' );
add_action( 'profile_update', 'pmpro_membership_level_profile_fields_update' );

function pmpro_has_membership_access($post_id = NULL, $user_id = NULL, $return_membership_levels = false)
{
	global $post, $wpdb, $current_user;
	//use globals if no values supplied
	if(!$post_id)
		$post_id = $post->ID;
	if(!$user_id)
		$user_id = $current_user->ID;

	//no post, return false
	if(!$post_id)
		return false;

	//if no post or current_user object, set them up
	if($post_id == $post->ID)
		$mypost = $post;
	else
		$mypost = get_post($post_id);

	if($user_id == $current_user->ID)
		$myuser = $current_user;
	else
		$myuser = get_user($user_id);

	//for these post types, we want to check the parent
	if($mypost->post_type == "attachment" || $mypost->post_type == "revision")
	{
		$mypost = get_post($mypost->post_parent);
	}

	if($mypost->post_type == "post")
	{
		$post_categories = wp_get_post_categories($mypost->ID);

		if(!$post_categories)
		{
			//just check for entries in the memberships_pages table
			$sqlQuery = "SELECT m.id, m.name FROM $wpdb->pmpro_memberships_pages mp LEFT JOIN $wpdb->pmpro_membership_levels m ON mp.membership_id = m.id WHERE mp.page_id = '" . $mypost->ID . "'";
		}
		else
		{
			//are any of the post categories associated with membership levels? also check the memberships_pages table
			$sqlQuery = "(SELECT m.id, m.name FROM $wpdb->pmpro_memberships_categories mc LEFT JOIN $wpdb->pmpro_membership_levels m ON mc.membership_id = m.id WHERE mc.category_id IN(" . implode(",", $post_categories) . ") AND m.id IS NOT NULL) UNION (SELECT m.id, m.name FROM $wpdb->pmpro_memberships_pages mp LEFT JOIN $wpdb->pmpro_membership_levels m ON mp.membership_id = m.id WHERE mp.page_id = '" . $mypost->ID . "')";
		}
	}
	else
	{
		//are any membership levels associated with this page?
		$sqlQuery = "SELECT m.id, m.name FROM $wpdb->pmpro_memberships_pages mp LEFT JOIN $wpdb->pmpro_membership_levels m ON mp.membership_id = m.id WHERE mp.page_id = '" . $mypost->ID . "'";
	}


	$post_membership_levels = $wpdb->get_results($sqlQuery);

	$post_membership_levels_ids = array();
	$post_membership_levels_names = array();

	if(!$post_membership_levels)
	{
		$hasaccess = true;
	}
	else
	{
		//we need to see if the user has access
		foreach($post_membership_levels as $level)
		{
			$post_membership_levels_ids[] = $level->id;
			$post_membership_levels_names[] = $level->name;
		}

		//levels found. check if this is in a feed or if the current user is in at least one of those membership levels
		if(is_feed())
		{
			//always block restricted feeds
			$hasaccess = false;
		}
		elseif($myuser->id)
		{
			if(in_array($myuser->membership_level->ID, $post_membership_levels_ids))
			{
				//the users membership id is one that will grant access
				$hasaccess = true;
			}
			else
			{
				//user isn't a member of a level with access
				$hasaccess = false;
			}
		}
		else
		{
			//user is not logged in and this content requires membership
			$hasaccess = false;
		}
	}

	/*
		Filters
		The generic filter is run first. Then if there is a filter for this post type, that is run.
	*/
	//general filter for all posts
	$hasaccess = apply_filters("pmpro_has_membership_access_filter", $hasaccess, $mypost, $myuser, $post_membership_levels);
	//filter for this post type
	if(has_filter("pmpro_has_membership_access_action_" . $mypost->post_type))
		$hasaccess = apply_filters("pmpro_has_membership_access_filter_" . $mypost->post_type, $hasaccess, $mypost, $myuser, $post_membership_levels);

	//return
	if($return_membership_levels)
		return array($hasaccess, $post_membership_levels_ids, $post_membership_levels_names);
	else
		return $hasaccess;
}

function pmpro_search_filter($query)
{
	global $current_user, $wpdb, $pmpro_pages;

	//hide pmpro pages from search results
	if(!$query->is_admin && $query->is_search)
	{
		$query->set('post__not_in', $pmpro_pages ); // id of page or post
	}

	//hide member pages from non-members (make sure they aren't hidden from members)
	if(!$query->is_admin && $query->is_search)
	{
		//get pages that are in levels, but not in mine
		$sqlQuery = "SELECT page_id FROM $wpdb->pmpro_memberships_pages ";
		if($current_user->membership_level->ID)
			$sqlQuery .= "WHERE membership_id <> '" . $current_user->membership_level->ID . "' ";
		$hidden_page_ids = $wpdb->get_col($sqlQuery);
		if($hidden_page_ids)
			$query->set('post__not_in', $hidden_page_ids ); // id of page or post

		//get categories that are filtered by level, but not my level
		$sqlQuery = "SELECT category_id FROM $wpdb->pmpro_memberships_categories ";
		if($current_user->membership_level->ID)
			$sqlQuery .= "WHERE membership_id <> '" . $current_user->membership_level->ID . "' ";
		$hidden_post_cats = $wpdb->get_col($sqlQuery);

		//make this work
		if($hidden_post_cats)
			$query->set('category__not_in', $hidden_post_cats);
	}

	return $query;
}
add_filter( 'pre_get_posts', 'pmpro_search_filter' );

function pmpro_membership_content_filter($content, $skipcheck = false)
{
	global $post, $current_user;

	if(!$skipcheck)
	{
		$hasaccess = pmpro_has_membership_access(NULL, NULL, true);
		if(is_array($hasaccess))
		{
			//returned an array to give us the membership level values
			$post_membership_levels_ids = $hasaccess[1];
			$post_membership_levels_names = $hasaccess[2];
			$hasaccess = $hasaccess[0];
		}
	}

	if($hasaccess)
	{
		//all good, return content
		return $content;
	}
	else
	{
		//if show excerpts is set, return just the excerpt
		if(pmpro_getOption("showexcerpts"))
		{
			//show excerpt
			global $post;
			if($post->post_excerpt)
			{
				//defined exerpt
				$content = wpautop($post->post_excerpt);
			}
			elseif(strpos($content, "<span id=\"more-" . $post->ID . "\"></span>") !== false)
			{
				//more tag
				$pos = strpos($content, "<span id=\"more-" . $post->ID . "\"></span>");
				$content = wpautop(substr($content, 0, $pos));
			}
			else
			{
				//auto generated excerpt. pulled from wp_trim_excerpt
				$content = strip_shortcodes( $content );
				$content = str_replace(']]>', ']]&gt;', $content);
				$content = strip_tags($content);
				$excerpt_length = apply_filters('excerpt_length', 55);
				$words = preg_split("/[\n\r\t ]+/", $content, $excerpt_length + 1, PREG_SPLIT_NO_EMPTY);
				if ( count($words) > $excerpt_length ) {
					array_pop($words);
					$content = implode(' ', $words);
					$content = $content . "... ";
				} else {
					$content = implode(' ', $words) . "... ";
				}

				$content = wpautop($content);
			}
		}
		else
		{
			//else hide everything
			$content = "";
		}

		if(!$post_membership_levels_ids)
			$post_membership_levels_ids = array();

		if(!$post_membership_levels_names)
			$post_membership_levels_names = array();

		$pmpro_content_message_pre = '<div class="pmpro_content_message">';
		$pmpro_content_message_post = '</div>';

		$sr_search = array("!!levels!!", "!!referrer!!");
		$sr_replace = array(pmpro_implodeToEnglish($post_membership_levels_names), $_SERVER['REQUEST_URI']);

		//get the correct message to show at the bottom
		if(is_feed())
		{
			$newcontent = apply_filters("pmpro_rss_text_filter", stripslashes(pmpro_getOption("rsstext")));
			$content .= $pmpro_content_message_pre . str_replace($sr_search, $sr_replace, $newcontent) . $pmpro_content_message_post;
		}
		elseif($current_user->ID)
		{
			//not a member
			$newcontent = apply_filters("pmpro_non_member_text_filter", stripslashes(pmpro_getOption("nonmembertext")));
			$content .= $pmpro_content_message_pre . str_replace($sr_search, $sr_replace, $newcontent) . $pmpro_content_message_post;
		}
		else
		{
			//not logged in!
			$newcontent = apply_filters("pmpro_not_logged_in_text_filter", stripslashes(pmpro_getOption("notloggedintext")));
			$content .= $pmpro_content_message_pre . str_replace($sr_search, $sr_replace, $newcontent) . $pmpro_content_message_post;
		}
	}

	return $content;
}
add_filter('the_content', 'pmpro_membership_content_filter', 5);
add_filter('the_content_rss', 'pmpro_membership_content_filter', 5);
add_filter('the_excerpt', 'pmpro_membership_content_filter', 5);
add_filter('comment_text_rss', 'pmpro_membership_content_filter', 5);

function pmpro_comments_filter($comments, $post_id = NULL)
{
	global $post, $wpdb, $current_user;
	if(!$post_id)
		$post_id = $post->ID;

	if(!$comments)
		return $comments;	//if they are closed anyway, we don't need to check

	global $post, $current_user;

	$hasaccess = pmpro_has_membership_access(NULL, NULL, true);
	if(is_array($hasaccess))
	{
		//returned an array to give us the membership level values
		$post_membership_levels_ids = $hasaccess[1];
		$post_membership_levels_names = $hasaccess[2];
		$hasaccess = $hasaccess[0];
	}

	if($hasaccess)
	{
		//all good, return content
		return $comments;
	}
	else
	{
		if(!$post_membership_levels_ids)
			$post_membership_levels_ids = array();

		if(!$post_membership_levels_names)
			$post_membership_levels_names = array();

		//get the correct message
		if(is_feed())
		{
			if(is_array($comments))
				return array();
			else
				return false;
		}
		elseif($current_user->ID)
		{
			//not a member
			if(is_array($comments))
				return array();
			else
				return false;
		}
		else
		{
			//not logged in!
			if(is_array($comments))
				return array();
			else
				return false;
		}
	}

	return $comments;
}
add_filter("comments_array", "pmpro_comments_filter");
add_filter("comments_open", "pmpro_comments_filter");

global $membership_levels;
$membership_levels = $wpdb->get_results( "SELECT * FROM {$wpdb->pmpro_membership_levels}", OBJECT );

function pmpro_page_meta()
{
	global $membership_levels, $post, $wpdb;
	$page_levels = $wpdb->get_col("SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = '{$post->ID}'");
?>
    <ul id="membershipschecklist" class="list:category categorychecklist form-no-clear">
    <input type="hidden" name="pmpro_noncename" id="pmpro_noncename" value="<?php echo wp_create_nonce( plugin_basename(__FILE__) )?>" />
	<?php
		foreach($membership_levels as $level)
		{
	?>
    	<li id="membership-level-<?php echo $level->id?>">
        	<label class="selectit">
            	<input id="in-membership-level-<?php echo $level->id?>" type="checkbox" <?php if(in_array($level->id, $page_levels)) { ?>checked="checked"<?php } ?> name="page_levels[]" value="<?php echo $level->id?>" /> <?php echo $level->name?>
            </label>
        </li>
    <?php
		}
    ?>
    </ul>
	<?php if('post' == get_post_type($post)) { ?>
		<p class="pmpro_meta_notice">This post may also require membership if it is within a category that requires membership.</p>
	<?php } ?>
<?php
}

function pmpro_page_save($post_id)
{
	global $wpdb;

	if ( !wp_verify_nonce( $_POST['pmpro_noncename'], plugin_basename(__FILE__) )) {
		return $post_id;
	}

	// verify if this is an auto save routine. If it is our form has not been submitted, so we dont want
	// to do anything
	if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE )
		return $post_id;

	// Check permissions
	if ( 'page' == $_POST['post_type'] )
	{
		if ( !current_user_can( 'edit_page', $post_id ) )
			return $post_id;
	}
	else
	{
		if ( !current_user_can( 'edit_post', $post_id ) )
			return $post_id;
	}

	// OK, we're authenticated: we need to find and save the data
	$mydata = $_POST['page_levels'];

	//remove all memberships for this page
	$wpdb->query("DELETE FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = '$post_id'");

	//add new memberships for this page
	if(is_array($mydata))
	{
		foreach($mydata as $level)
			$wpdb->query("INSERT INTO {$wpdb->pmpro_memberships_pages} (membership_id, page_id) VALUES('" . $wpdb->escape($level) . "', '" . $post_id . "')");
	}

	return $mydata;
}

function pmpro_page_meta_wrapper()
{
	add_meta_box('pmpro_page_meta', 'Require Membership', 'pmpro_page_meta', 'page', 'side');
	add_meta_box('pmpro_page_meta', 'Require Membership', 'pmpro_page_meta', 'post', 'side');
}
if (is_admin())
{
	add_action('admin_menu', 'pmpro_page_meta_wrapper');
	add_action('save_post', 'pmpro_page_save');

	require_once(ABSPATH . "wp-content/plugins/paid-memberships-pro/adminpages/dashboard.php");
}

function pmpro_add_pages()
{
	global $wpdb;

	add_menu_page('Memberships', 'Memberships', 'manage_options', 'pmpro-membershiplevels', 'pmpro_membershiplevels', PMPRO_URL . '/images/menu_users.png');
	add_submenu_page('pmpro-membershiplevels', 'Page Settings', 'Page Settings', 'manage_options', 'pmpro-pagesettings', 'pmpro_pagesettings');
	add_submenu_page('pmpro-membershiplevels', 'Payment Settings', 'Payment Settings', 'manage_options', 'pmpro-paymentsettings', 'pmpro_paymentsettings');
	add_submenu_page('pmpro-membershiplevels', 'Email Settings', 'Email Settings', 'manage_options', 'pmpro-emailsettings', 'pmpro_emailsettings');
	add_submenu_page('pmpro-membershiplevels', 'Advanced Settings', 'Advanced Settings', 'manage_options', 'pmpro-advancedsettings', 'pmpro_advancedsettings');
	add_submenu_page('pmpro-membershiplevels', 'Members List', 'Members List', 'manage_options', 'pmpro-memberslist', 'pmpro_memberslist');
	add_submenu_page('pmpro-membershiplevels', 'Discount Codes', 'Discount Codes', 'manage_options', 'pmpro-discountcodes', 'pmpro_discountcodes');

	//rename the automatically added Memberships submenu item
	global $submenu;
	if($submenu['pmpro-membershiplevels'])
	{
		$submenu['pmpro-membershiplevels'][0][0] = "Membership Levels";
		$submenu['pmpro-membershiplevels'][0][3] = "Membership Levels";
	}
}
add_action('admin_menu', 'pmpro_add_pages');

function pmpro_admin_bar_menu() {
	global $wp_admin_bar;
	if ( !is_super_admin() || !is_admin_bar_showing() )
		return;
	$wp_admin_bar->add_menu( array(
	'id' => 'paid-memberships-pro',
	'title' => __( 'Memberships'),
	'href' => home_url('/wp-admin/admin.php?page=pmpro-membershiplevels') ) );
	$wp_admin_bar->add_menu( array(
	'id' => 'paid-memberships-pro-membershiplevels',
	'parent' => 'paid-memberships-pro',
	'title' => __( 'Membership Levels'),
	'href' => home_url('/wp-admin/admin.php?page=pmpro-membershiplevels') ) );
	$wp_admin_bar->add_menu( array(
	'id' => 'paid-memberships-pro-pagesettings',
	'parent' => 'paid-memberships-pro',
	'title' => __( 'Page Settings'),
	'href' => home_url('/wp-admin/admin.php?page=pmpro-pagesettings') ) );
	$wp_admin_bar->add_menu( array(
	'id' => 'paid-memberships-pro-paymentsettings',
	'parent' => 'paid-memberships-pro',
	'title' => __( 'Payment Settings'),
	'href' => home_url('/wp-admin/admin.php?page=pmpro-paymentsettings') ) );
	$wp_admin_bar->add_menu( array(
	'id' => 'paid-memberships-pro-emailsettings',
	'parent' => 'paid-memberships-pro',
	'title' => __( 'Email Settings'),
	'href' => home_url('/wp-admin/admin.php?page=pmpro-emailsettings') ) );
	$wp_admin_bar->add_menu( array(
	'id' => 'paid-memberships-pro-advancedsettings',
	'parent' => 'paid-memberships-pro',
	'title' => __( 'Advanced Settings'),
	'href' => home_url('/wp-admin/admin.php?page=pmpro-advancedsettings') ) );
	$wp_admin_bar->add_menu( array(
	'id' => 'paid-memberships-pro-memberslist',
	'parent' => 'paid-memberships-pro',
	'title' => __( 'Members List'),
	'href' => home_url('/wp-admin/admin.php?page=pmpro-memberslist') ) );
	$wp_admin_bar->add_menu( array(
	'id' => 'paid-memberships-pro-discountcodes',
	'parent' => 'paid-memberships-pro',
	'title' => __( 'Discount Codes'),
	'href' => home_url('/wp-admin/admin.php?page=pmpro-discountcodes') ) );

}
add_action('admin_bar_menu', 'pmpro_admin_bar_menu', 1000);

//css
function pmpro_addFrontendHeaderCode()
{
	global $besecure;
	$besecure = apply_filters('pmpro_besecure', $besecure);
	$url = get_bloginfo('wpurl');
	if($besecure)
		$url = str_replace("http:", "https:", $url);
	else
		$url = str_replace("https:", "http:", $url);

	echo '<link type="text/css" rel="stylesheet" href="' . $url . '/wp-content/plugins/paid-memberships-pro/css/frontend.css" media="screen" />' . "\n";
	echo '<link type="text/css" rel="stylesheet" href="' . $url . '/wp-content/plugins/paid-memberships-pro/css/print.css" media="print" />' . "\n";
}
add_action('wp_head', 'pmpro_addFrontendHeaderCode');

//css
function pmpro_addAdminHeaderCode()
{
	$url = get_bloginfo('wpurl');
	echo '<link type="text/css" rel="stylesheet" href="' . $url . '/wp-content/plugins/paid-memberships-pro/css/admin.css" media="screen" />' . "\n";
}
add_action('admin_head', 'pmpro_addAdminHeaderCode');

//redirect control
function pmpro_login_redirect($redirect_to, $request, $user)
{
	global $wpdb;

	//is a user logging in?
	if(!empty($user->ID))
	{
		//logging in, let's figure out where to send them

		//admins go to dashboard
		if(pmpro_isAdmin($user->ID))
			return apply_filters("pmpro_login_redirect", get_bloginfo("url") . "/wp-admin/");

		//if the redirect url includes the word checkout, go there
		if(strpos($redirect_to, "checkout") !== false)
			return $redirect_to;

		//if logged in and a member, send to wherever they were going
		if($wpdb->get_var("SELECT membership_id FROM $wpdb->pmpro_memberships_users WHERE user_id = '" . $user->ID . "' LIMIT 1"))
		{
			return apply_filters("pmpro_login_redirect", $redirect_to, $request, $user);
		}

		//not a member, send to subscription page
		return pmpro_url("levels");
	}
	else
	{
		//not logging in (login form) so return what was given
		return $redirect_to;
	}
}
add_filter('login_redirect','pmpro_login_redirect', 10, 3);

//this function checks if we have set the $isapage variable, and if so prevents WP from sending a 404
function pmpro_status_filter($s)
{
	global $isapage;
	if($isapage && strpos($s, "404"))
		return false;	//don't send the 404
	else
		return $s;
}
function pmpro_https_filter($s)
{
	global $besecure;
	$besecure = apply_filters('pmpro_besecure', $besecure);
	if($besecure)
		return str_replace("http:", "https:", $s);
	else
		return str_replace("https:", "http:", $s);
}
add_filter('status_header', 'pmpro_status_filter');
add_filter('bloginfo_url', 'pmpro_https_filter');
add_filter('wp_list_pages', 'pmpro_https_filter');
add_filter('option_home', 'pmpro_https_filter');
add_filter('option_siteurl', 'pmpro_https_filter');
add_filter('logout_url', 'pmpro_https_filter');
add_filter('login_url', 'pmpro_https_filter');
add_filter('home_url', 'pmpro_https_filter');

function pmpro_besecure()
{
	global $besecure, $post;

	//check the post option
	if(!empty($post->ID) && !$besecure)
		$besecure = get_post_meta($post->ID, "besecure", true);

	if(!$besecure && (force_ssl_admin() || force_ssl_login()))
		$besecure = true;

	$besecure = apply_filters("pmpro_besecure", $besecure);

	if($besecure && empty($_SERVER['HTTPS']))
	{
		//need to be secure
		wp_redirect("https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
		exit;
	}
	elseif(!$besecure && !empty($_SERVER['HTTPS']))
	{
		//don't need to be secure
		wp_redirect("http://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
		exit;
	}
}
add_action('wp', 'pmpro_besecure');
add_action('login_head', 'pmpro_besecure');

//capturing case where a user links to https admin without admin over https
function pmpro_admin_https_handler()
{
	if(!empty($_SERVER['HTTPS']))
	{
		if($_SERVER['HTTPS'] && is_admin())
		{
			if(substr(get_option("siteurl"), 0, 5) == "http:" && !force_ssl_admin())
			{
				//need to redirect to non https
				wp_redirect("http://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
				exit;
			}
		}
	}
}
add_action('init', 'pmpro_admin_https_handler');

//keep non-members from getting to certain pages (attachments, etc)
function pmpro_hide_pages_redirect()
{
	global $post;

	if(!is_admin())
	{
		if($post->post_type == "attachment")
		{
			//check if the user has access to the parent
			if(!pmpro_has_membership_access($post->ID))
			{
				wp_redirect(pmpro_url("levels"));
				exit;
			}
		}
	}
}
add_action('wp', 'pmpro_hide_pages_redirect');

//deleting a user? remove their account info.
function pmpro_delete_user($user_id = NULL)
{
	global $wpdb;

	//changing their membership level to 0 will cancel any subscription and remove their membership level entry
	//we don't remove the orders because it would affect reporting
	if(pmpro_changeMembershipLevel(0, $user_id))
	{
		//okay
	}
	else
	{
		//couldn't delete the subscription
		//we should probably notify the admin
		$pmproemail = new PMProEmail();
		$pmproemail->data = array("body"=>"<p>There was an error canceling the subscription for user with ID=" . $user_id . ". You will want to check your payment gateway to see if their subscription is still active.</p>");
		$last_order = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_orders WHERE user_id = '" . $user_id . "' ORDER BY timestamp DESC LIMIT 1");
		if($last_order)
			$pmproemail->data["body"] .= "<p>Last Invoice:<br />" . nl2br(var_export($last_order, true)) . "</p>";
		$pmproemail->sendEmail(get_bloginfo("admin_email"));
	}
}
add_action('delete_user', 'pmpro_delete_user');
add_action('wpmu_delete_user', 'pmpro_delete_user');

//deleting a category? remove any level associations
function pmpro_delete_category($cat_id = NULL)
{
	global $wpdb;
	$sqlQuery = "DELETE FROM $wpdb->pmpro_memberships_categories WHERE category_id = '" . $cat_id . "'";
	$wpdb->query($sqlQuery);
}
add_action('delete_category', 'pmpro_delete_category');

//deleting a post? remove any level associations
function pmpro_delete_post($post_id = NULL)
{
	global $wpdb;
	$sqlQuery = "DELETE FROM $wpdb->pmpro_memberships_pages WHERE page_id = '" . $post_id . "'";
	$wpdb->query($sqlQuery);
}
add_action('delete_post', 'pmpro_delete_post');

function pmpro_shortcode($atts, $content=null, $code="")
{
	// $atts    ::= array of attributes
	// $content ::= text within enclosing form of shortcode element
	// $code    ::= the shortcode found, when == callback name
	// examples: [membership level="3"]...[/membership]

	extract(shortcode_atts(array(
		'level' => NULL
	), $atts));

	global $current_user;

	if($level || $level === "0")
	{
	   //they specified a level(s)
	   if(strpos($level, ","))
	   {
		   //they specified many levels
		   $levels = explode(",", $level);
	   }
	   else
	   {
		   //they specified just one level
		   $levels = array($level);
	   }

	   if(pmpro_hasMembershipLevel($levels))
	   {
		   return apply_filters("the_content", $content);
	   }
	}
	else
	{
		//didn't specify a membership level, so check for any
		if($current_user->membership_level->ID)
			return apply_filters("the_content", $content);
	}

	//must not be a member
	return "";	//just hide it
}
add_shortcode("membership", "pmpro_shortcode");

function pmpro_wp_signup_location($location)
{
	if(is_multisite() && pmpro_getOption("redirecttosubscription"))
	{
		return pmpro_url("levels");
	}
	else
		return $location;
}
add_filter('wp_signup_location', 'pmpro_wp_signup_location');

function pmpro_login_head()
{
	$login_redirect = apply_filters("pmpro_login_redirect", true);
	if((pmpro_is_login_page() || is_page("login")) && $login_redirect)
	{
		//redirect registration page to levels page
		if(isset($_REQUEST['action']) && $_REQUEST['action'] == "register" || isset($_REQUEST['registration']) && $_REQUEST['registration'] == "disabled")
		{
			wp_redirect(pmpro_url("levels"));
			exit;
		}

		//if theme my login is installed, redirect all logins to the login page
		if(pmpro_is_plugin_active("theme-my-login/theme-my-login.php"))
		{
			//check for the login page id and redirect there if we're not there already
			global $post;
			if(is_array($GLOBALS['theme_my_login']->options))
			{
				if($GLOBALS['theme_my_login']->options['page_id'] !== $post->ID)
				{
					//redirect to the real login page
					$link = get_permalink($GLOBALS['theme_my_login']->options['page_id']);
					if($_SERVER['QUERY_STRING'])
						$link .= "?" . $_SERVER['QUERY_STRING'];
					wp_redirect($link);
					exit;
				}
			}
			elseif(!empty($GLOBALS['theme_my_login']->options))
			{
				if($GLOBALS['theme_my_login']->options->options['page_id'] !== $post->ID)
				{
					//redirect to the real login page
					$link = get_permalink($GLOBALS['theme_my_login']->options->options['page_id']);
					if($_SERVER['QUERY_STRING'])
						$link .= "?" . $_SERVER['QUERY_STRING'];
					wp_redirect($link);
					exit;
				}
			}

			//make sure users are only getting to the profile when logged in
			global $current_user;
			if($_REQUEST['action'] == "profile" && !$current_user->ID)
			{
				$link = get_permalink($GLOBALS['theme_my_login']->options->options['page_id']);
				wp_redirect($link);
			}
		}
	}
}
add_action('wp', 'pmpro_login_head');
add_action('login_init', 'pmpro_login_head');

//use recaptcha?
global $recaptcha;
$recaptcha = pmpro_getOption("recaptcha");
if($recaptcha)
{
	global $recaptcha_publickey, $recaptcha_privatekey;
	if(!function_exists("recaptcha_get_html"))
	{
		require_once(dirname(__FILE__) . "/includes/lib/recaptchalib.php");
	}
	$recaptcha_publickey = pmpro_getOption("recaptcha_publickey");
	$recaptcha_privatekey = pmpro_getOption("recaptcha_privatekey");
}

/*
	Nicer default emails
*/
function pmpro_wp_mail_from_name($from_name)
{
	$pmpro_from_name = pmpro_getOption("from_name");
	if ($pmpro_from_name)
		return $pmpro_from_name;
	return $from_name;
}
function pmpro_wp_mail_from($from_email)
{
	$pmpro_from_email = pmpro_getOption("from_email");
	if ($pmpro_from_email && is_email( $pmpro_from_email ) )
		return $pmpro_from_email;
	return $from_email;
}
add_filter('wp_mail_from_name', 'pmpro_wp_mail_from_name');
add_filter('wp_mail_from', 'pmpro_wp_mail_from');

function pmpro_send_html( $phpmailer ) {
	// Set the original plain text message
	$phpmailer->AltBody = wp_specialchars_decode($phpmailer->Body, ENT_QUOTES);
	// Clean < and > around text links in WP 3.1
	$phpmailer->Body = preg_replace('#<(http://[^*]+)>#', '$1', $phpmailer->Body);
	// Convert line breaks & make links clickable
	$phpmailer->Body = nl2br ( make_clickable ($phpmailer->Body) );
	
	// Add template to message
	if(file_exists(TEMPLATEPATH . "/email_header.html"))
	{
		$phpmailer->Body = file_get_contents(TEMPLATEPATH . "/email_header.html") . "\n" . $phpmailer->Body;
	}
	if(file_exists(TEMPLATEPATH . "/email_footer.html"))
	{
		$phpmailer->Body = $phpmailer->Body . "\n" . file_get_contents(TEMPLATEPATH . "/email_footer.html");
	}
	
	// Replace variables in email
	global $current_user;
	$data = array(
				"name" => $current_user->display_name,
				"sitename" => get_option("blogname"),
				"login_link" => pmpro_url("account"),
				"display_name" => $current_user->display_name,
				"user_email" => $current_user->user_email,
				"subject" => $phpmailer->Subject
			);
	foreach($data as $key => $value)
	{
		$phpmailer->Body = str_replace("!!" . $key . "!!", $value, $phpmailer->Body);
	}

	do_action("pmpro_after_phpmailer_init", $phpmailer);
	do_action("pmpro_after_pmpmailer_init", $phpmailer);	//typo left in for backwards compatibility
}
function pmpro_wp_mail_content_type( $content_type ) {	
	
	//check for template
	if(file_exists(TEMPLATEPATH . "/email_header.html") || file_exists(TEMPLATEPATH . "/email_footer.html"))
	{
		add_action('phpmailer_init', 'pmpro_send_html');
		
		//change to html if not already
		if( $content_type == 'text/plain')
		{			
			$content_type = 'text/html';
		}
	}
	return $content_type;
}
add_filter('wp_mail_content_type', 'pmpro_wp_mail_content_type');

function pmpro_link()
{
?>
Memberships powered by <a href="http://www.paidmembershipspro.com">Paid Memberships Pro</a>.
<?php
}

function pmpro_footer_link()
{
	if(!pmpro_getOption("hide_footer_link"))
	{
		?>
		<!-- <?php echo pmpro_link()?> -->
		<?php
	}
}
add_action("wp_footer", "pmpro_footer_link");

function pmpro_activation()
{
	wp_schedule_event(time(), 'daily', 'pmpro_cron_expiration_warnings');
	wp_schedule_event(time(), 'daily', 'pmpro_cron_trial_ending_warnings');
	wp_schedule_event(time(), 'daily', 'pmpro_cron_expire_memberships');
}
function pmpro_deactivation()
{
	wp_clear_scheduled_hook('pmpro_cron_expiration_warnings');
	wp_clear_scheduled_hook('pmpro_cron_trial_ending_warnings');
	wp_clear_scheduled_hook('pmpro_cron_expire_memberships');
}
register_activation_hook(__FILE__, 'pmpro_activation');
register_deactivation_hook(__FILE__, 'pmpro_deactivation');

/*
This code calls the server at www.memberlitetheme.com to see if there are any notifications to display to the user.
*/
function pmpro_notifications()
{
	if(current_user_can("manage_options"))
	{			
		delete_transient("pmpro_notification_" . PMPRO_VERSION);
		
		$pmpro_notification = get_transient("pmpro_notification_" . PMPRO_VERSION);
		if(empty($pmpro_notification))
		{
			if(is_ssl())
				$pmpro_notification = wp_remote_retrieve_body(wp_remote_get("https://www.paidmembershipspro.com/notifications/?v=" . PMPRO_VERSION));
			else
				$pmpro_notification = wp_remote_retrieve_body(wp_remote_get("http://www.paidmembershipspro.com/notifications/?v=" . PMPRO_VERSION));
				
			set_transient("pmpro_notification_" . PMPRO_VERSION, $pmpro_notification, 86400);
		}
		
		if($pmpro_notification && $pmpro_notification != "NULL")
		{
		?>
		<div id="pmpro_notifications">
			<?php echo $pmpro_notification; ?>
		</div>
		<?php
		}
	}
	
	//exit so we just show this content
	exit;
}
add_action('wp_ajax_pmpro_notifications', 'pmpro_notifications');	
?>