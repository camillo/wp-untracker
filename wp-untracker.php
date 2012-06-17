<?php
/* {
Plugin Name: WordPress Untracker
Description: Replace google tracking links from posts with 'normal' ones.
Author: Daniel Marohn <daniel.marohn@googlemail.com>
Version: 0.1 Alpha - prove of concept
} */

/**
 * Do a GET request against given url and returns the redirect_url header, if exists.
 * @return redirect_url if exists, $url otherwise; leave $url unmodified if something went wrong.
 * @param string $url
 */
function freeUrl($url)
{
	try 
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_NOBODY, 1);
		$c = curl_exec($ch);
		$info = curl_getinfo($ch);
		curl_close($ch);
		
		return $info['redirect_url'];		
	} catch (Exception $ex)
	{
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
	$ret = $content;
	try 
	{
		preg_match_all('|href=\\\"(http://feedproxy.google.com/[^"\\\]*)\\\"|',$content, $feedLinkMatches);
		foreach ($feedLinkMatches[1] as $feedLink)
		{
			$freedUrl = freeUrl($feedLink);
			if (!empty($freedUrl))
			{
				$ret = str_replace("href=\\\"" . $feedLink . "\\\"", "href=\\\"" . $freedUrl . "\\\"", $ret);	
			}
		}
		return $ret;
	} catch (Exception $ex)
	{
		return $content;
	}
}

add_filter('content_save_pre','untrackPost');
