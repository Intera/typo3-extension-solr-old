<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2011-2015 Michael Knoll <knoll@punkt.de>
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
use TYPO3\CMS\Core\Utility\GeneralUtility;


/**
 * This Processor takes a PID, and resolves its rootline in solr notation.
 *
 * Format of this field corresponds to http://wiki.apache.org/solr/HierarchicalFaceting
 *
 * Let's say we have a record indexed on page 111 which is a sub page like shown in this page tree:
 *
 * 1
 * |-10
 *   |-100
 *     |-111
 *
 * then we get a rootline 1/10/100/111
 *
 * In Solr hierarchy notation, we get
 *
 * 0-1
 * 1-1/10
 * 2-1/10/100
 * 3-1/10/100/11
 *
 * which is finally saved in a multi-value field.
 *
 * @author Michael Knoll <knoll@punkt.de>
 * @package TYPO3
 * @subpackage solr
 */
class Tx_Solr_FieldProcessor_PageUidToHierarchy extends Tx_Solr_FieldProcessor_AbstractHierarchyProcessor implements Tx_Solr_FieldProcessor {

	/**
	 * Expects a page ID of a page. Returns a Solr hierarchy notation for the
	 * rootline of the page ID.
	 *
	 * @param array Array of values, an array because of multivalued fields
	 * @return array Modified array of values
	 */
	public function process(array $values) {
		$results = array();

		foreach ($values as $value) {
			list($rootPageUid, $mountPoint) = t3lib_div::trimExplode(',', $value, TRUE, 2);
			$results[] = $this->getSolrRootlineForPageId($rootPageUid, $mountPoint);
		}

		return $results;
	}

	/**
	 * Returns a Solr hierarchy notation string for rootline of given PID.
	 *
	 * @param integer $pageId Page ID to get a rootline as Solr hierarchy for
	 * @param string $mountPoint The mount point parameter that will be used for building the rootline.
	 * @return string Rootline as Solr hierarchy
	 */
	protected function getSolrRootlineForPageId($pageId, $mountPoint = '') {
		$pageIdRootline = $this->buildPageIdRootline($pageId, $mountPoint);
		$solrRootline   = $this->buildSolrHierarchyFromIdRootline($pageIdRootline);

		return $solrRootline;
	}

	/**
	 * Builds a page's rootline of parent page Ids
	 *
	 * @param integer $pageId The page Id to build the rootline for
	 * @param string $mountPoint The mount point parameter that will be passed to getRootline().
	 * @return array Page Id rootline as array
	 */
	protected function buildPageIdRootline($pageId, $mountPoint = '') {
		$rootlinePageIds = array();

		$pageSelector = GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\Page\\PageRepository');
		$rootline = $pageSelector->getRootLine($pageId, (string)$mountPoint);

		foreach($rootline as $page) {
			if ($page['is_siteroot']) {
				break;
			}

			array_unshift($rootlinePageIds, $page['pid']);
		}

		$rootlinePageIds[] = $pageId;

		return $rootlinePageIds;
	}

}

