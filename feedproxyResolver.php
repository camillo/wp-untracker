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
 * @return dict with curl response, containing all headers
 * @throws everything, that curls throws. No exception is catched here.
 */
function doCurlRequest($url)
{
	$curlSession = curl_init($url);
	curl_setopt($curlSession, CURLOPT_NOBODY, 1);
	$curlResponse = curl_exec($curlSession);
	$ret = curl_getinfo($curlSession);
	curl_close($curlSession);
	
	return $ret;
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
		if (array_key_exists('redirect_url', $header) && !empty($header['redirect_url']))
		{
			return $header['redirect_url'];
		} else 
		{
			_log($header);
			throw new Exception("result from server does not contain redirect_url header");
		}
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
 * Generate a regex pattern, that match given parameter and value
 * @param string $parameter name of the parameter to match
 * @return string ready to use regex pattern for parameter
 */
function getParameterPattern($parameter, $includeSeparator)
{
	$ret = "~";
	if ($includeSeparator)
	{
		$ret = $ret . "&?";
	}
	$ret = $ret . $parameter ."=[^&/]*~";
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
		$pattern = getParameterPattern($wellKnownParameter, true);
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
 * @param string $wellKnownParameter space separated list of parameters to remove from $parameter; empty for default
 * @return string given parameters without known tracking ones.
 */
function removeWellKnownParameters($parameter, $wellKnownParameter)
{
	$ret = $parameter;
	if (empty($wellKnownParameter)) $wellKnownParameter = "utm_medium utm_source utm_campaign";
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
 * Removes leading & (?&utm_medium=foo for example)
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
		} elseif (substr($parameter, 0, 2) == "?&")
		{
			$ret = "?" . substr($parameter, 2);
		}
		return $ret;
	} catch (Exception $ex)
	{
		_log("error in cleanupParameter for parameter [$parameter]: " . $ex->getMessage());
		return $parameter;
	}
}

/**
 * Add the well known parameter to newParameter, if exists in parameter
 * @param string $parameter parameter part from url
 * @param string $wellknownParameter name of a single well known parameter
 * @param string $newParameter new parameter list build so far.
 * @return string $newParameter plus new parameter
 */
function addWellknownParameter($parameter, $wellknownParameter, $newParameter)
{
	$ret = $newParameter;
	$pattern = getParameterPattern($wellknownParameter, false);
	switch (preg_match($pattern, $parameter, $matches))
	{
		case 0:
			// parameter not set in url; nothing to do 
			break;
		case 1:
			$parameterAndValue = $matches[0];
			// if this is the first parameter, the & is wrong (?&foo=bar)
			// this will be handled by cleanupParameters
			$ret = $ret . "&" . $parameterAndValue;
			break;
		default:
			throw new Exception("parameter $wellknownParameter is found more than once.");
	}
	return $ret;	
}

/**
 * Remove all GET parameters from url except well known ones.
 * @param string $parameter GET parameters
 * @param string $wellKnownParameter list of exceptions
 * @return Url, without any GET parameters but the whitelisted ones
 */
function removeAllButWellKnownParameters($parameter, $wellKnownParameter)
{
	// if no parameter is added, the single ? will be removed by cleanupParameters
	$ret = "?";
	try 
	{
		foreach (explode(" ", $wellKnownParameter) as $currentParameter)
		{
			$ret = addWellknownParameter($parameter, $currentParameter, $ret);
		}
	} catch (Exception $ex)
	{
		_log("error in hardcore: " . $ex->getMessage());
		$ret = $parameter;
	}
	return $ret;
}
/**
 * remove GET parameter, if configured
 *
 * full:     remove no parameter(default)
 * softcore: cut knwon parameters from target url
 * hardcore: cut all but known parameters from target url
 * @param string $parameter the GET parameter part of an url
 * @throws Exception in case of invalid $replacementMode
 */
function removeGetParameters($parameter)
{
	$replacementMode = get_option('replacementMode');
	$wellKnownParameter = get_option('wellKnownParameter');
	$newParameter = "";
	if ($replacementMode == "softcore")
	{
		$newParameter = removeWellKnownParameters($parameter, $wellKnownParameter);
	} elseif ($replacementMode == "hardcore")
	{
		$newParameter = removeAllButWellKnownParameters($parameter, $wellKnownParameter);
	} elseif ($replacementMode == "full" || empty($replacementMode))
	{
		// skip cleanupParameters
		return $parameter;
	} else
	{
		throw new Exception("unknown replacement mode: [$replacementMode]");
	}
	$newParameter = cleanupParameters($newParameter);
	return $newParameter;
}

/**
 * Get new parameter if needed and replace them with old ones
 * @param string $resolvedUrl allready resolved feedproxy url
 * @return string url with new parameter
 */
function handleGetParameter($resolvedUrl)
{
	$ret = $resolvedUrl;
	try
	{
		$parameterStart = strpos($ret, "?");
		if ($parameterStart === false)
		{
			_log("no GET parameter; nothing to do.");
		} else
		{
			$parameter = substr($ret, $parameterStart);
			$newParameter = removeGetParameters($parameter);
			$ret = substr($ret, 0, $parameterStart) . $newParameter;

			if (get_option('paranoia') == "on")
			{
				$ret = checkUrl($ret, $resolvedUrl);
			}
		}
	} catch (Exception $ex)
	{
		_log("error in handleGetParameter: " . $ex->getMessage());
		$ret = $resolvedUrl;
	}
	return $ret;	
}

/**
 * Resolves given feedproxy url to the 'real' one.
 * 1. get new url
 * 2. remove configured get parameter
 * 
 * @param string $url the url to free
 * @return string 'real' url		
 */
function resolveFeedproxyUrl($url)
{
	$ret = resolveUrl($url);
	if ($ret == $url)
	{
		_log("was not able to resolve url [$url]; keep feedproxy url!");
		$ret = $url;
	} else
	{
		$ret = handleGetParameter($ret); 
	}
	return $ret;
}

/**
 * Called by WP before a post is saved.
 * Resolve and replace google feedproxy links.
 * @param string $content post's content as it would be written into database
 * @return $content with replaced feedproxy links; unmodified $content if something went wrong. 
 */
function resolveFeedproxyUrls($content)
{
	try 
	{
		// The pattern match against full href parameter of google's feedproxy links.
		// It does not match against feedproxy links in clear text.
		// The url itself is captured in first (and only) capture group.
		// Note: The \\\ are double escaped (for php and for regex pattern).
		return preg_replace_callback('~href=\\\"(http://feedproxy.google.com/[^\\\]+)\\\"~',
				/**
				 * replace the feedproxy url from found link with the resolved url.
				 * @param regexMatch $match
				 * @return string resolved and ready to use link
				 */
				function($match)
				{
					$link = $match[0];
					$originalUrl = $match[1];
					$newUrl = resolveFeedproxyUrl($originalUrl);
					_log("replacing link [$link] -> [$newUrl]");
					return str_replace($originalUrl, $newUrl, $link);
				}, $content);
	} catch (Exception $ex)
	{
		_log("error untracking post: " . $ex->getMessage());
		return $content;
	}
}

// Register hook, to get called every time, just before the content of a post is saved.
// This is, where Feedproxy Resolver does his work. 
add_filter('content_save_pre','resolveFeedproxyUrls');

?>
