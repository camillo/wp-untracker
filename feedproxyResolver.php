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
 * Remove GET parameters by configured setting replacementMode.
 * @param string $replacementMode "softcore" or "hardcore"
 * @param string $parameter the GET parameter
 * @param string $wellKnownParameter list of known exceptions
 * @throws Exception in case of invalid $replacementMode
 */
function replaceGetParameter($parameter)
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
		return $parameter;
	} else
	{
		throw new Exception("unknown replacement mode: [$replacementMode]");
	}
	$newParameter = cleanupParameters($newParameter);
	return $newParameter;
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
		$parameterStart = strpos($ret, "?");
		if ($parameterStart === false)
		{
			_log("no GET parameter; nothing to do.");
		} else
		{
			$parameter = substr($ret, $parameterStart);
			$newParameter = replaceGetParameter($parameter);
			$ret = substr($ret, 0, $parameterStart) . $newParameter;

			if (get_option('paranoia') == "on")
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
