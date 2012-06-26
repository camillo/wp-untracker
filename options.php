<?php

/**
 * check that option ReplaceMode is valid
 * @param string $input
 * @return $input, if ok; default value otherwise;
 */
function validateReplaceMode($input)
{
	$ret = "full";
	if (($input == "hardcore") || ($input == "softcore"))
	{
		$ret = $input;
	}
	_log("validate replace mode [$input]: [$ret]");
	return $ret;
}

/**
 * do nothing but log
 * @param string $input new value
 * @return string $input
 */
function validateWellKnownParameter($input)
{
	_log("validate well known parameter [$input]: ok");
	return $input;
}

/**
 * make sure that value is empty or the string "on"
 * @param string $input new value
 * @return unmodified $input if empty or "on; empty string otherwise
 */
function validateParanoiaParameter($input)
{
	$ret = $input;
	if ((empty($ret)) || ($ret == "on"))
	{
		_log("validate paranoia parameter [$input]: ok");
	} else
	{
		_log("validate paranoia parameter [$input]: unknown value; turn paranoia off");
		$ret = "";		
	}

	return $ret;
}

/**
 * Register the needed settings for feedproxy resolver. 
 */
function registerFeedproxySettings()
{
	register_setting( 'feedproxyResolver', 'replacementMode', 'validateReplaceMode');
	register_setting( 'feedproxyResolver', 'wellKnownParameter', 'validateWellKnownParameter');
	register_setting( 'feedproxyResolver', 'paranoia', 'validateParanoiaParameter');
}

/**
 * Do all settings related stuff to wordpress.
 * 1. Register the menu entry.
 * 2. Hook into admin init to register our settings.
 */
function initFeedproxySettings()
{
	add_submenu_page( 'options-general.php', 'Feedp Resolver', 'Feedp Resolver', 'administrator' , __FILE__, 'feedproxyOptionDrawPage');
	add_action('admin_init', 'registerFeedproxySettings');
}

/**
 * Draw the menu page itself
 */
function feedproxyOptionDrawPage()
{
?>
    <div class="wrap">
        <h2>Feedproxy Resolver Options</h2>
        <form method="post" action="options.php">
            <?php settings_fields('feedproxyResolver'); ?>
            <?php $replacementMode = get_option('replacementMode'); ?>
			<?php $wellKnownParameter = get_option('wellKnownParameter'); ?>
			<?php $paranoia = get_option('paranoia'); ?>
            <p>Choose the parameter replacement mode</p>
			<p>
				<input type="radio" name="replacementMode" value="full" <?php checked(  $replacementMode == "full" ); ?> > no replacement<br>
				<input type="radio" name="replacementMode" value="softcore" <?php checked(  $replacementMode == "softcore" ); ?>> softcore<br>
				<input type="radio" name="replacementMode" value="hardcore" <?php checked(  $replacementMode == "hardcore" ); ?>> hardcore
			</p>
			<p>Blacklist for softcore (leave empty for default)<br>Whitelist for hardcore</p>
			<p>
				<input type="text" name ="wellKnownParameter" style="width:500px" value="<?php echo $wellKnownParameter;?>" />
			</p>
			<p>
				<input type="checkbox" name ="paranoia" <?php if($paranoia == 'on') echo 'checked="checked"';?> >check links after parameter replacing (paranoia)</input>
			</p>
            <p class="submit">
            <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
            </p>
        </form>
		<hr/>
		<h3>Help</h3>
			After the resolving of a feedproxy url, the url may contains ugly feedproxy GET parameters. You can specify, how to handle these.
		<h4>No replacement</h4>
			This is the savest option. The url will be used, exacly as received, containing all GET parameters.
		<h4>softcore</h4>
			Some known GET parameters will be removed from url, rest of them are not touched. Leave parameter textbox empty to use default settings (utm_medium, utm_source and utm_campaign). 
		<h4>hardcore (naming creds goes to daMax)</h4>
			All GET parameters are removed from url, except the whitelisted ones from the parameter textbox.
		<h4>paranoia</h4>
			Activating this option, let Feedproxy Resolver check the url after parameter cutting. If no 200 OK is returned from server, The original url with all parameters will be used.  
	</div>
    <?php
}

// register our admin page to wordpress
add_action('admin_menu', 'initFeedproxySettings');

?>