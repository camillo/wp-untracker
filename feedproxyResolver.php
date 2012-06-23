<?php
/* {
Plugin Name: Feedproxy Resolver
Plugin URI: https://github.com/camillo/wp-untracker
Description: Replace google feedproxy links from posts with 'normal' ones.
Author: Daniel Marohn <daniel.marohn@googlemail.com>
Author URI: https://github.com/camillo/
Version: 0.1
License: public domain
} */
//require_once ('./options.php');

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

// register settings related staff
include_once dirname( __FILE__ ) . '/options.php';

/**
 * Do a GET request against given url and returns the redirect_url header, if exists.
 * @return redirect_url if exists, $url otherwise; leave $url unmodified if something went wrong.
 * @param string $url
 */
function resolveUrl($url)
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
 * Removes a single known tracking parameters from given GET parameters.
 * @param string $parameter the parameter's part of an url.
 * @param string $wellKnownParameter a single parameter name to remove from $parameter
 * @return given $parameter without $wellKnownParameter
 */
function removeWellKnownParameter($parameter, $wellKnownParameter)
{
	try
	{
		$pattern ="~&?" . $wellKnownParameter ."=[^&/]+~";
		$ret = preg_replace($pattern, "", $parameter);
		return $ret;
	} catch (Exception $ex)
	{
		_log("error, removing single parameter [$parameter] :" . $ex->getMessage());
		return $parameter;
	}	
}

/**
 * Removes all known tracking parameters from given GET parameters.
 * @param string $parameter the parameter's part of an url.
 * @param string $wellKnownParameter space separated list of parameters to remove from $parameter
 * @return string given parameters without known tracking ones.
 */
function removeWellKnownParameters($parameter, $wellKnownParameter)
{
	$ret = $parameter;
	try 
	{
		foreach (explode(" ", $wellKnownParameter) as $currentParameter)
		{
			$ret = removeWellKnownParameter($ret, $currentParameter);
		}
		_log("remove known parameters [$parameter] -> [$ret]");
		return $ret;
	} catch (Exception $ex)
	{
		_log("error removing well known parameter: " . $ex -> getMessage());
		return $parameter;
	}
}

/**
 * Removes single ? in case that all parameter got removed.
 * @param string $parameter parameter part from an url
 * @return $parameter if there are any; empty string otherwise
 */
function cleanupParameters($parameter)
{
	try
	{
		$ret = $parameter;
		if ($parameter == "?")
		{
			$ret = "";
		}elseif ($parameter == "?/")
		{
			$ret = "/";
		}
		return $ret;
	} catch (Exception $ex)
	{
		_log("error in cleanupParameter for parameter [$parameter]: " . $ex->getMessage());
		return $parameter;
	}
}

/**
 * Cut knwon parameters from target url.
 * @param string $url target url
 * @return url, without any known feedproxy parameters
 */
function doSoftcore($url)
{
	try 
	{
		$ret = $url;
		$parameterStart = strpos($ret, "?");
		if ($parameterStart === false)
		{
			_log("no GET parameter; nothing to do.");
		} else
		{
			$parameter = substr($ret, $parameterStart);
			$wellKnownParameter = get_option('wellKnownParameter');
			$newParameter = removeWellKnownParameters($parameter, $wellKnownParameter);
			$newParameter = cleanupParameters($newParameter);
			$ret = substr($ret, 0, $parameterStart) . $newParameter;
		}
		_log("do softcore: [$url] -> [$ret]");
		return $ret;
	} catch (Exception $ex)
	{
		_log("error in softcore: " . $ex->getMessage());
		return $url;
	}
}

/**
 * Remove all GET parameters from url.
 * @param string $url target url
 * @return Url, without any GET parameters.
 */
function doHardcore($url)
{
	try 
	{
		$ret = $url;
		$parts = split("[?]", $ret);
		$ret = $parts[0];
		_log("do hardcore: [$url] -> [$ret]");
		return $ret;
	} catch (Exception $ex)
	{
		_log("error in hardcore: " . $ex->getMessage());
		return $url;
	}
}

/**
 * Remove google feedproxy link from given $url.
 * 1. get new url
 * 2. short url in case of hardcore or softcore modus.
 * 
 * softcore:
 * cut knwon parameters from target url
 * 
 * hardcore:
 * remove all GET parameters from url
 * 
 * @param string $url the url to free
 * @return 'real' url		

 */
function freeUrl($url)
{
	$ret = resolveUrl($url);
	try
	{
		$replacementMode = get_option('replacementMode');
		if ($replacementMode == "softcore")
		{
			$ret = doSoftcore($ret);
		} else if ($replacementMode == "hardcore")
		{
			$ret = doHardcore($ret);
		}
	} catch (Exception $ex)
	{
		_log("error in cutter: " . $ex->getMessage());
	}
	
	return $ret;
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

?>
