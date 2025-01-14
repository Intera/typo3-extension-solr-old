<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Alexander Stehlik <astehlik.deleteme@intera.de>
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
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/


/**
 * GarbageCollector delete query pre-processor interface.
 */
interface Tx_Solr_GarbageCollectorDeleteQueryPreProcessor {

	/**
	 * Modifies the query for deleting documents from the solr index.
	 *
	 * @param string $query The original query.
	 * @param string $table The record's table name.
	 * @param integer $uid The record's uid.
	 * @param \Tx_Solr_GarbageCollector $gargabeCollector The calling garbage collector instance.
	 * @return string The updated query that should be used for document deletion.
	 * @see Tx_Solr_GarbageCollector->deleteIndexDocuments()
	 */
	public function getGarbageCollectorDeleteQuery($query, $table, $uid, $gargabeCollector);
}