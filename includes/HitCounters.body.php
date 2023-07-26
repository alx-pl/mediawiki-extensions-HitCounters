<?php

namespace HitCounters;

use MWNamespace;
use Parser;
use PPFrame;
use Title;

class HitCounters {
	protected static $mViews;

	protected static function cacheStore( $cache, $key, $views ) {
		if ( $views < 100 ) {
			// Only cache for a minute
			$cache->set( $key, $views, 60 );
		} else {
			/* update only once a day */
			$cache->set( $key, $views, 24 * 3600 );
		}
	}

	/**
	 * @return int The view count for the page
	 */
	public static function getCount( Title $title ) {
		if ( $title->isSpecialPage() ) {
			return null;
		}

		/*
		 * Use the cache to avoid hitting the DB if available since
		 * page views are pretty common and this is a tiny bit of
		 * information.
		 */
		$cache = wfGetMainCache();
		$key = wfMemcKey( 'viewcount', $title->getPrefixedDBkey() );
		$views = $cache->get( $key );

		if ( !$views || $views == 1 ) {
			$dbr = wfGetDB( DB_REPLICA );
			$hits = $dbr->selectField(
				[ 'hit_counter' ],
				[ 'hits' => 'page_counter' ],
				[ 'page_id' => $title->getArticleID() ],
				__METHOD__ );

			if ( $hits !== false ) {
				$views = $hits;
				self::cacheStore( $cache, $key, $views );
			}
		}

		return $views;
	}

	public static function views() {
		# Should check for MiserMode here
		$cache = wfGetCache( CACHE_ANYTHING );
		$key = wfMemcKey( 'sitestats', 'activeusers-updated' );
		// Re-calculate the count if the last tally is old...
		if ( !self::$mViews ) {
			self::$mViews = $cache->get( $key );
			wfDebugLog( "HitCounters", __METHOD__
				. ": got " . var_export( self::$mViews, true ) .
				" from cache." );
			if ( !self::$mViews || self::$mViews == 1 ) {
				$dbr = wfGetDB( DB_REPLICA );
				self::$mViews = $dbr->selectField(
					'hit_counter', 'SUM(page_counter)', '', __METHOD__
				);
				wfDebugLog( "HitCounters", __METHOD__ . ": got " .
					var_export( self::$mViews, true ) .
					" from select." );
				self::cacheStore( $cache, $key, self::$mViews );
			}
		}
		return self::$mViews;
	}

	/**
	 * {{NUMBEROFVIEWS}} - number of total views of the site
	 *
	 * We can't choose our parameters since this is a hook and we
	 * don't really need to use the $parser and $cache parameters.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public static function numberOfViews(
		Parser $parser, PPFrame $frame, $args
	) {
		return self::views();
	}

	/**
	 * {{NUMBEROFPAGEVIEWS}} - number of total views of the page
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public static function numberOfPageViews(
		Parser $parser, PPFrame $frame, $args
	) {
		return self::getCount( $frame->title );
	}

	public static function getQueryInfo() {
		global $wgDBprefix;
		global $wgShyUsers;

		return [
			'tables' => [ 'page', 'hit_counter' ],
			'fields' => [
				'namespace' => 'page_namespace',
				'title'  => 'page_title',
				'value'  => 'page_counter',
				'length' => 'page_len'
			],
			'conds' => [
				'page_is_redirect' => 0,
				'page_namespace' => MWNamespace::getSubjectNamespaces(),
				'not page_title' => array_map(fn($value): string => str_replace(" ", "_", $value), $wgShyUsers),
			],
			'join_conds' => [
				'page' => [
					'INNER JOIN',
					$wgDBprefix . 'page.page_id = ' .
					$wgDBprefix . 'hit_counter.page_id' ]
			]
		];
	}
}
