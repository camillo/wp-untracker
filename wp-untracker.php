<?php
/* {
Plugin Name: WordPress Untracker
Plugin URI: https://github.com/camillo/wp-untracker
Description: Replace google feedproxy links from posts with 'normal' ones.
Author: Daniel Marohn <daniel.marohn@googlemail.com>
Author URI: https://github.com/camillo/
Version: 0.1
License: public domain
} */

// take care for logging helper function _log
if(!function_exists('_log'))
{
	/**
	 * Write message to logfile, if debug is enabled.
	 * @see http://fuelyourcoding.com/simple-debugging-with-wordpress/
	 * @param any $message support for strings, arrays and custom objects
	 */
	function _log( $message )
	{
		if( WP_DEBUG === true )
		{
			if( is_array( $message ) || is_object( $message ) )
			{
				error_log( print_r( $message, true ) );
			} else
			{
				error_log( $message );
			}
		}
	}
}

/**
 * Do a GET request against given url and returns the redirect_url header, if exists.
 * @return redirect_url if exists, $url otherwise; leave $url unmodified if something went wrong.
 * @param string $url
 */
function freeUrl($url)
{
	try 
	{
		$curlSession = curl_init($url);
		curl_setopt($curlSession, CURLOPT_NOBODY, 1);
		$curlResponse = curl_exec($curlSession);
		$header = curl_getinfo($curlSession);
		curl_close($curlSession);
		
		return $header['redirect_url'];
	} catch (Exception $ex)
	{
		_log("error freeing url $url: " . $ex->getMessage());
		return $url;
	}
}

/**
 * Called by WP before a post is saved.
 * Resolve and replace google feedproxy links.
 * @return $content with replaced feedproxy links, if possible; or unmodified $content if something went wrong. 
 * @param string $content
 */
function untrackPost($content)
{
	try 
	{
		return preg_replace_callback('~href=\\\"(http://feedproxy.google.com/[^"\\\]*)\\\"~',
				/**
				 * replace the feedproxy url from found link with the 'real' url.
				 * @param regexMatch $match
				 */
				function($match)
				{
					$link = $match[0];
					$originalUrl = $match[1];
					$newUrl = freeUrl($originalUrl);
					_log("replacing link [$link] -> [$newUrl]");
					return str_replace($originalUrl, $newUrl, $link);
				}, $content);
	} catch (Exception $ex)
	{
		_log("error untracking post: " . $ex->getMessage());
		return $content;
	}
}

// register plugin
add_filter('content_save_pre','untrackPost');
