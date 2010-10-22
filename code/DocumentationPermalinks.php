<?php

/**
 * A mapping store of given permalinks to the full documentation path or useful for customizing the
 * shortcut URLs used in the viewer.
 *
 * Redirects the user from example.com/foo to example.com/en/module/foo
 *
 * @module sapphiredocs
 */

class DocumentationPermalinks {
	
	/**
	 * @var array
	 */
	private static $mapping = array();
	
	/**
	 * Add a mapping of nice short permalinks to a full long path
	 *
	 * 		DocumentationPermalinks::add(array(
	 *			'debugging' => 'sapphire/topics/debugging.md'
	 *		));
	 *
	 *
	 * You do not need to include the language or the version current as it will add it
	 * based off the language or version in the session
	 */
	public static function add($map = array()) {
		if(ArrayLib::is_associative($map)) {
			self::$mapping = array_merge(self::$mapping, $map);
		}
		else {
			user_error("DocumentationPermalinks::add() requires an associative array", E_USER_ERROR);
		}
	}
	
	/**
	 * Return the location for a given short value.
	 *
	 * @return String|false
	 */
	public static function map($url) {
		return (isset(self::$mapping[$url])) ? self::$mapping[$url] : false;
	}
}