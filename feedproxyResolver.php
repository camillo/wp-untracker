<?php
/* {
Plugin Name: Feedproxy Resolver
Plugin URI: https://github.com/camillo/wp-untracker
Description: Replace google feedproxy links from posts with 'normal' ones.
Author: Daniel Marohn <daniel.marohn@googlemail.com>
Author URI: https://github.com/camillo/
Version: 0.2
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

// register settings related staff
include_once dirname( __FILE__ ) . '/options.php';

/**
 * Do a GET request against given url (with nobody option enabled).
 * @param string $url
 * @return dict with response headern
 * @throws everything, that curls throws. No exception is catched here.
 */
function doCurlRequest($url)
{
	$curlSession = curl_init($url);
	curl_setopt($curlSession, CURLOPT_NOBODY, 1);
	$curlResponse = curl_exec($curlSession);
	$header = curl_getinfo($curlSession);
	curl_close($curlSession);
	
	return $header;
}

/**
 * Do a GET request against given url and returns the redirect_url header, if exists.
 * @return redirect_url if exists, $url otherwise; leave $url unmodified if something went wrong.
 * @param string $url
 */
function resolveUrl($url)
{
	try 
	{
		$header = doCurlRequest($url);
		return $header['redirect_url'];
	} catch (Exception $ex)
	{
		_log("error freeing url $url: " . $ex->getMessage());
		return $url;
	}
}

/**
 * Check if newUrl is reachable (200 OK from server)
 * @param string $newUrl the url to check
 * @param string $originalUrl the url, without parameter replacing
 * @return $newUrl, if valid; $originalUrl otherwise
 */
function checkUrl($newUrl, $originalUrl)
{
	$ret = $newUrl;
	try
	{
		$header = doCurlRequest($newUrl);
		$httpStatus = $header['http_code'];
		if($httpStatus == "200")
		{
			_log("url ok: [$newUrl]");
		} else{
			_log("[$httpStatus] url failed: [$newUrl]");
			$ret = $originalUrl;
		}
	} catch (Exception $ex)
	{
		_log("error, checking url: " . $ex->getMessage());
		$ret = $originalUrl;
	}
	return $ret;
}

/**
 * Removes a single known tracking parameter from given GET parameters.
 * @param string $parameter the parameter's part of an url.
 * @param string $wellKnownParameter a single parameter name to remove from $parameter
 * @return given $parameter without $wellKnownParameter
 */
function removeWellKnownParameter($parameter, $wellKnownParameter)
{
	try
	{
		$pattern ="~&?" . $wellKnownParameter ."=[^&/]*~";
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
		$parts = explode("?", $url);
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
 * remove GET parameter, if configured
 * short url in case of hardcore or softcore modus.
 *   check url in case of paranoia
 * 
 * softcore:
 * cut knwon parameters from target url
 * 
 * hardcore:
 * cut all GET parameter
 * @param string $resolvedUrl allready resolved feedproxy url
 * @return string url with cutted parameter
 */
function handleGetParameter($resolvedUrl)
{
	$ret = $resolvedUrl;
	try
	{
		$replacementMode = get_option('replacementMode');
		$paranoia = get_option('paranoia');
		if ((!empty($replacementMode)) && ($replacementMode != "full"))
		{
			if ($replacementMode == "softcore")
			{
				$ret = doSoftcore($ret);
			} elseif ($replacementMode == "hardcore")
			{
				$ret = doHardcore($ret);
			} else
			{
				throw new Exception("unknown replacement mode: [$replacementMode]");
			}
			if ($paranoia == "on")
			{
				$ret = checkUrl($ret, $resolvedUrl);
			}
		}
	} catch (Exception $ex)
	{
		_log("error in cutter: " . $ex->getMessage());
		$ret = $resolvedUrl;
	}
	return $ret;	
}

/**
 * Remove google feedproxy link from given $url.
 * 1. get new url
 * 2. remove configured get parameter
 * 
 * @param string $url the url to free
 * @return 'real' url		

 */
function freeUrl($url)
{
	$resolvedUrl = resolveUrl($url);
	if ($resolvedUrl == $url)
	{
		_log("was not able to resolve url [$url]; keep feedproxy url!");
		return $url;
	}
	return handleGetParameter($resolvedUrl);
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
				 * @return string resolved and ready to use link
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

// register hook, to get called every time, just before the content of a post is saved.
// This is, where Feedproxy Resolver does his work. 
add_filter('content_save_pre','untrackPost');

?>
