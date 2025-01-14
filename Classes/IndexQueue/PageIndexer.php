<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2009-2015 Ingo Renner <ingo@typo3.org>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;


/**
 * A special purpose indexer to index pages.
 *
 * In the case of pages we can't directly index the page records, we need to
 * retrieve the content that belongs to a page from tt_content, too. Also
 * plugins may be included on a page and thus may need to be executed.
 *
 * @author Ingo Renner <ingo@typo3.org>
 * @package TYPO3
 * @subpackage solr
 */
class Tx_Solr_IndexQueue_PageIndexer extends Tx_Solr_IndexQueue_Indexer {

	/**
	 * Indexes an item from the indexing queue.
	 *
	 * @param Tx_Solr_IndexQueue_Item $item An index queue item
	 * @return Apache_Solr_Response The Apache Solr response
	 */
	public function index(Tx_Solr_IndexQueue_Item $item) {
		$this->setLogging($item);

			// check whether we should move on at all
		if (!$this->isPageIndexable($item)) {
			return FALSE;
		}

		$solrConnections = $this->getSolrConnectionsByItem($item);
		foreach ($solrConnections as $systemLanguageUid => $solrConnection) {
			$contentAccessGroups = $this->getAccessGroupsFromContent($item, $systemLanguageUid);

			if (empty($contentAccessGroups)) {
					// might be an empty page w/no content elements or some TYPO3 error / bug
					// FIXME logging needed
				continue;
			}

			foreach ($contentAccessGroups as $userGroup) {
				$this->indexPage($item, $systemLanguageUid, $userGroup);
			}
		}

		return TRUE;
	}

	/**
	 * Creates a single Solr Document for a page in a specific language and for
	 * a specific frontend user group.
	 *
	 * @param Tx_Solr_IndexQueue_Item $item The index queue item representing the page.
	 * @param integer $language The language to use.
	 * @param integer $userGroup The frontend user group to use.
	 * @return Tx_Solr_IndexQueue_PageIndexerResponse Page indexer response
	 */
	protected function indexPage(Tx_Solr_IndexQueue_Item $item, $language = 0, $userGroup = 0) {
		$accessRootline = $this->getAccessRootline($item, $language, $userGroup);

		$request = $this->buildBasePageIndexerRequest();
		$request->setIndexQueueItem($item);
		$request->addAction('indexPage');
		$request->setParameter('accessRootline', (string) $accessRootline);

		$indexRequestUrl   = $this->getDataUrl($item, $language);
		$response          = $request->send($indexRequestUrl);
		$indexActionResult = $response->getActionResult('indexPage');

		if ($this->loggingEnabled) {
			$logSeverity = 0;
			$logStatus   = 'Info';
			if ($indexActionResult['pageIndexed']) {
				$logSeverity = -1;
				$logStatus   = 'Success';
			}

			GeneralUtility::devLog('Page Indexer: ' . $logStatus, 'solr', $logSeverity, array(
				'item'                => (array) $item,
				'language'            => $language,
				'user group'          => $userGroup,
				'index request url'   => $indexRequestUrl,
				'request'             => (array) $request,
				'request headers'     => $request->getHeaders(),
				'response'            => (array) $response
			));
		}

		if (!$indexActionResult['pageIndexed']) {
			throw new RuntimeException(
				'Failed indexing page Index Queue item ' . $item->getIndexQueueUid(),
				1331837081
			);
		}

		return $response;
	}

	/**
	 * Builds a base page indexer request with configured headers and other
	 * parameters.
	 *
	 * @return Tx_Solr_IndexQueue_PageIndexerRequest Base page indexer request
	 */
	protected function buildBasePageIndexerRequest() {
		$request = GeneralUtility::makeInstance('Tx_Solr_IndexQueue_PageIndexerRequest');
		$request->setParameter('loggingEnabled', $this->loggingEnabled);

		if (!empty($this->options['authorization.'])) {
			$request->setAuthorizationCredentials(
				$this->options['authorization.']['username'],
				$this->options['authorization.']['password']
			);
		}

		if (!empty($this->options['frontendDataHelper.']['headers.'])) {
			foreach ($this->options['frontendDataHelper.']['headers.'] as $headerValue) {
				$request->addHeader($headerValue);
			}
		}

		if (!empty($this->options['frontendDataHelper.']['requestTimeout'])) {
			$request->setTimeout((float) $this->options['frontendDataHelper.']['requestTimeout']);
		}

		return $request;
	}

	/**
	 * Checks whether we can index this page.
	 *
	 * @param Tx_Solr_IndexQueue_Item $item The page we want to index encapsulated in an index queue item
	 * @return boolean True if we can index this page, FALSE otherwise
	 */
	protected function isPageIndexable(Tx_Solr_IndexQueue_Item $item) {

			// TODO do we still need this?
			// shouldn't those be sorted out by the record monitor / garbage collector already?

		$isIndexable = TRUE;
		$record = $item->getRecord();

		if (isset($GLOBALS['TCA']['pages']['ctrl']['enablecolumns']['disabled'])
		&& $record[$GLOBALS['TCA']['pages']['ctrl']['enablecolumns']['disabled']]) {
			$isIndexable = FALSE;
		}

		return $isIndexable;
	}


	// Utility methods


	/**
	 * Determines a page ID's URL.
	 *
	 * Tries to find a domain record to use to build an URL for a given page ID
	 * and then actually build and return the page URL.
	 *
	 * @param Tx_Solr_IndexQueue_Item $item Item to index
	 * @param integer $language The language id
	 * @return string URL to send the index request to
	 */
	protected function getDataUrl(Tx_Solr_IndexQueue_Item $item, $language = 0) {
		$scheme = 'http';
		$host   = $item->getSite()->getDomain();
		$path   = '/';
		$pageId = $item->getRecordUid();

			// deprecated
		if (!empty($this->options['scheme'])) {
			GeneralUtility::devLog(
				'Using deprecated option "scheme" to set the scheme (http / https) for the page indexer frontend helper. Use plugin.tx_solr.index.queue.pages.indexer.frontendDataHelper.scheme instead',
				'solr',
				2
			);
			$scheme = $this->options['scheme'];
		}

			// check whether we should use ssl / https
		if (!empty($this->options['frontendDataHelper.']['scheme'])) {
			$scheme = $this->options['frontendDataHelper.']['scheme'];
		}

			// overwriting the host
		if (!empty($this->options['frontendDataHelper.']['host'])) {
			$host = $this->options['frontendDataHelper.']['host'];
		}

			// setting a path if TYPO3 is installed in a sub directory
		if (!empty($this->options['frontendDataHelper.']['path'])) {
			$path = $this->options['frontendDataHelper.']['path'];
		}

		$mountPointParameter = $this->getMountPageDataUrlParameter($item);
		$dataUrl = $scheme . '://' . $host . $path . 'index.php?id=' . $pageId;
		$dataUrl .= ($mountPointParameter !== '') ? '&MP=' . $mountPointParameter : '';
		$dataUrl .= '&L=' . $language;

		if (!GeneralUtility::isValidUrl($dataUrl)) {
			GeneralUtility::devLog(
				'Could not create a valid URL to get frontend data while trying to index a page.',
				'solr',
				3,
				array(
					'item'            => (array) $item,
					'constructed URL' => $dataUrl,
					'scheme'          => $scheme,
					'host'            => $host,
					'path'            => $path,
					'page ID'         => $pageId,
					'indexer options' => $this->options
				)
			);

			throw new RuntimeException(
				'Could not create a valid URL to get frontend data while trying to index a page. Created URL: ' . $dataUrl,
				1311080805
			);
		}

		if ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['IndexQueuePageIndexer']['dataUrlModifier']) {
			$dataUrlModifier = GeneralUtility::getUserObj($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['IndexQueuePageIndexer']['dataUrlModifier']);

			if ($dataUrlModifier instanceof Tx_Solr_IndexQueuePageIndexerDataUrlModifier) {
				$dataUrl = $dataUrlModifier->modifyDataUrl($dataUrl, array(
					'item'     => $item,
					'scheme'   => $scheme,
					'host'     => $host,
					'path'     => $path,
					'pageId'   => $pageId,
					'language' => $language
				));
			} else {
				throw new RuntimeException(
					$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['IndexQueuePageIndexer']['dataUrlModifier']
						. ' is not an implementation of Tx_Solr_IndexQueuePageIndexerDataUrlModifier',
					1290523345
				);
			}
		}

		return $dataUrl;
	}

	/**
	 * Generates the MP URL parameter needed to access mount pages. If the item
	 * is identified as being a mounted page, the &MP parameter is generated.
	 *
	 * @param Tx_Solr_IndexQueue_Item $item Item to get an &MP URL parameter for
	 * @return string &MP URL parameter if $item is a mounted page
	 */
	protected function getMountPageDataUrlParameter(Tx_Solr_IndexQueue_Item $item) {
		$mountPageUrlParameter = '';

		if ($item->hasIndexingProperty('isMountedPage')) {
			$mountPageUrlParameter =
				$item->getIndexingProperty('mountPageSource')
				. '-'
				. $item->getIndexingProperty('mountPageDestination');
		}

		return $mountPageUrlParameter;
	}

	/**
	 * Gets the Solr connections applicaple for a page.
	 *
	 * The connections include the default connection and connections to be used
	 * for translations of a page.
	 *
	 * @param Tx_Solr_IndexQueue_Item $item An index queue item
	 * @return array An array of Tx_Solr_SolrService connections, the array's keys are the sys_language_uid of the language of the connection
	 */
	protected function getSolrConnectionsByItem(Tx_Solr_IndexQueue_Item $item) {
		$solrConnections = parent::getSolrConnectionsByItem($item);

		$page = $item->getRecord();
			// may use \TYPO3\CMS\Core\Utility\GeneralUtility::hideIfDefaultLanguage($page['l18n_cfg']) with TYPO3 4.6
		if ($page['l18n_cfg'] & 1) {
				// page is configured to hide the default translation -> remove Solr connection for default language
			unset($solrConnections[0]);
		}

		if (GeneralUtility::hideIfNotTranslated($page['l18n_cfg'])) {
			$accessibleSolrConnections = array();
			if (isset($solrConnections[0])) {
				$accessibleSolrConnections[0] = $solrConnections[0];
			}

			$translationOverlays = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
				'pid, sys_language_uid',
				'pages_language_overlay',
				'pid = ' . $page['uid']
					. BackendUtility::deleteClause('pages_language_overlay')
					. BackendUtility::BEenableFields('pages_language_overlay')
			);

			foreach ($translationOverlays as $overlay) {
				$languageId = $overlay['sys_language_uid'];
				$accessibleSolrConnections[$languageId] = $solrConnections[$languageId];
			}

			$solrConnections = $accessibleSolrConnections;
		}

		return $solrConnections;
	}


	#
	# Frontend User Groups Access
	#

	/**
	 * Generates a page document's "Access Rootline".
	 *
	 * The Access Rootline collects frontend user group access restrcitions set
	 * for pages up in a page's rootline extended to sub-pages.
	 *
	 * The format is like this:
	 * pageId1:group1,group2|groupId2:group3|c:group1,group4,groupN
	 *
	 * The single elements of the access rootline are separated by a pipe
	 * character. All but the last elements represent pages, the last element
	 * defines the access restrictions applied to the page's content elements
	 * and records shown on the page.
	 * Each page element is composed by the page ID of the page setting frontend
	 * user access restrictions, a colon, and a comma separated list of frontend
	 * user group IDs restricting access to the page.
	 * The content access element does not have a page ID, instead it replaces
	 * the ID by a lower case C.
	 *
	 * @param Tx_Solr_IndexQueue_Item $item Index queue item representing the current page
	 * @param integer $language The sys_language_uid language ID
	 * @param integer $contentAccessGroup The user group to use for the content access rootline element. Optional, will be determined automatically if not set.
	 * @return string An Access Rootline.
	 */
	protected function getAccessRootline(Tx_Solr_IndexQueue_Item $item, $language = 0, $contentAccessGroup = null) {
		static $accessRootlineCache;

		$mountPointParameter = $this->getMountPageDataUrlParameter($item);

		$accessRootlineCacheEntryId = $item->getRecordUid() . '|' . $language;
		if ($mountPointParameter !== '') {
			$accessRootlineCacheEntryId .= '|' . $mountPointParameter;
		}
		if (!is_null($contentAccessGroup)) {
			$accessRootlineCacheEntryId .= '|' . $contentAccessGroup;
		}

		if (!isset($accessRootlineCache[$accessRootlineCacheEntryId])) {
			$accessRootline = Tx_Solr_Access_Rootline::getAccessRootlineByPageId(
				$item->getRecordUid(),
				$mountPointParameter
			);

				// current page's content access groups
			$contentAccessGroups = array($contentAccessGroup);
			if (is_null($contentAccessGroup)) {
				$contentAccessGroups = $this->getAccessGroupsFromContent($item, $language);
			}
			$accessRootline->push(GeneralUtility::makeInstance(
				'Tx_Solr_Access_RootlineElement',
				'c:' . implode(',', $contentAccessGroups)
			));

			$accessRootlineCache[$accessRootlineCacheEntryId] = $accessRootline;
		}

		return $accessRootlineCache[$accessRootlineCacheEntryId];
	}

	/**
	 * Finds the FE user groups used on a page including all groups of content
	 * elements and groups of records of extensions that have correctly been
	 * pushed through ContentObjectRenderer during rendering.
	 *
	 * @param Tx_Solr_IndexQueue_Item $item Index queue item representing the current page to get the user groups from
	 * @param integer $language The sys_language_uid language ID
	 * @return array Array of user group IDs
	 */
	protected function getAccessGroupsFromContent(Tx_Solr_IndexQueue_Item $item, $language = 0) {
		static $accessGroupsCache;

		$accessGroupsCacheEntryId = $item->getRecordUid() . '|' . $language;
		if (!isset($accessGroupsCache[$accessGroupsCacheEntryId])) {
			$request = $this->buildBasePageIndexerRequest();
			$request->setIndexQueueItem($item);
			$request->addAction('findUserGroups');

			$indexRequestUrl = $this->getDataUrl($item, $language);
			$response        = $request->send($indexRequestUrl);

			$groups = $response->getActionResult('findUserGroups');
			if (is_array($groups)) {
				$accessGroupsCache[$accessGroupsCacheEntryId] = $groups;
			}

			if ($this->loggingEnabled) {
				GeneralUtility::devLog('Page Access Groups', 'solr', 0, array(
					'item'              => (array) $item,
					'language'          => $language,
					'index request url' => $indexRequestUrl,
					'request'           => (array) $request,
					'response'          => (array) $response,
					'groups'            => $groups
				));
			}
		}

		return $accessGroupsCache[$accessGroupsCacheEntryId];
	}

}

