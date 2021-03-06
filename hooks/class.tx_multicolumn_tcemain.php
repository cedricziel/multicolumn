<?php

/*
 * This file is part of the TYPO3 Multicolumn project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read
 * LICENSE file that was distributed with this source code.
 */

class tx_multicolumn_tcemain
{
    /** @var \TYPO3\CMS\Core\DataHandling\DataHandler */
    protected $pObj;

    /**
     * - Copy children of a multicolumn container
     * - Delete children of a multicolumn container
     * - Check if a seedy releation to a multicolumn container exits
     * - Check if pasteinto multicolumn container is requested
     *
     * @param string $command
     * @param string $table
     * @param string $id
     * @param int $value
     * @param \TYPO3\CMS\Core\DataHandling\DataHandler $pObj
     * @param array $pasteUpdate
     * @param array $pasteDatamap
     *
     * @return void
     */
    public function processCmdmap_postProcess($command, $table, $id, $value, \TYPO3\CMS\Core\DataHandling\DataHandler $pObj, $pasteUpdate, $pasteDatamap)
    {
        if ($table == 'tt_content') {
            $this->pObj = $pObj;

            $copyId = (int)$pObj->copyMappingArray[$table][$id];
            // if pasteinto multicolumn container is requested?
            if ($this->getMulticolumnGetAction() == 'pasteInto') {
                $moveOrCopy = $copyId ? 'copy' : 'move';
                $updateId = ($moveOrCopy == 'copy') ? $copyId : $id;

                $this->pasteIntoMulticolumnContainer($moveOrCopy, $updateId, $id);
            } else {
                $containerChildren = tx_multicolumn_db::getContainerChildren($id);

                switch ($command) {
                    case 'copy':
                        // copy children of a multicolumn container too
                        if ($containerChildren) {
                            // the only way from here without db request to get the destinationPid?
                            $destinationPid = key($this->pObj->cachedTSconfig);

                            if (isset($pasteUpdate['sys_language_uid'])) {
                                $sysLanguageUid = $pasteUpdate['sys_language_uid'];
                            } elseif (isset($pasteDatamap[$table][$copyId]['sys_language_uid'])) {
                                $sysLanguageUid = $pasteDatamap[$table][$copyId]['sys_language_uid'];
                            } else {
                                $contentElement = tx_multicolumn_db::getContentElement($copyId, 'sys_language_uid');
                                $sysLanguageUid = $contentElement['sys_language_uid'];
                            }

                            $this->copyMulticolumnContainer($id, $containerChildren, $destinationPid, $sysLanguageUid);
                            // check if content element has a seedy relation to multicolumncontainer?
                        } elseif (($newUid = $copyId)) {
                            $row = \TYPO3\CMS\Backend\Utility\BackendUtility::getRecordWSOL('tt_content', $newUid);

                            if (is_array($row)) {
                                $elementBeforeData = [
                                    'tx_multicolumn_parentid' => 0,
                                    'colPos' => 0,
                                ];

                                if ($pObj->cmdmap['tt_content'][$id]['copy'] < 0) {
                                    // Copying after another element
                                    $elementBeforeUid = abs($pObj->cmdmap['tt_content'][$id]['copy']);
                                    $elementBeforeData = tx_multicolumn_db::getContentElement($elementBeforeUid, 'uid,tx_multicolumn_parentid,colPos');
                                }

                                if ($row['tx_multicolumn_parentid'] || $elementBeforeData['tx_multicolumn_parentid']) {
                                    // Update column position if:
                                    // (1) was in the multicolumn before
                                    //    or
                                    // (2) copied after the element in the multicolumn
                                    $updateRecordFields = [
                                        'tx_multicolumn_parentid' => $elementBeforeData['tx_multicolumn_parentid'],
                                        'colPos' => $elementBeforeData['colPos'],
                                    ];
                                    tx_multicolumn_db::updateContentElement($newUid, $updateRecordFields);
                                }
                            }
                        }
                        break;
                    case 'delete':
                        // delete children too
                        if ($containerChildren) {
                            $this->deleteMulticolumnContainer($containerChildren);
                        }
                        break;
                    case 'localize':
                        $localizeToSysLanguageUid = $value;

                        // get new uid
                        $multiColCeUid = $this->pObj->copyMappingArray[$table][$id];
                        if ($containerChildren) {
                            $this->localizeMulticolumnChildren($containerChildren, $multiColCeUid, $localizeToSysLanguageUid);
                        }

                        // reset remap stack record for multicolumn item (prevents double call of processDatamap_afterDatabaseOperations)
                        unset($pObj->remapStackRecords['tt_content'][$id]);
                        break;
                }
            }
        }
    }

    /**
     * Paste an element into multicolumn container
     *
     * @param    string $action : copy or move
     * @param    int $updateId : content element to update
     * @param    int $orginalId : orginal id of content element (copy from)
     */
    protected function pasteIntoMulticolumnContainer($action, $updateId, $orginalId = null)
    {
        $multicolumnId = intval(\TYPO3\CMS\Core\Utility\GeneralUtility::_GET('tx_multicolumn_parentid'));
        // stop if someone is trying to cut the multicolumn container inside the container
        if ($multicolumnId == $updateId) {
            return;
        }

        $updateRecordFields = [
            'colPos' => intval(\TYPO3\CMS\Core\Utility\GeneralUtility::_GET('colPos')),
            'tx_multicolumn_parentid' => $multicolumnId,
        ];

        tx_multicolumn_db::updateContentElement($updateId, $updateRecordFields);

        $containerChildren = tx_multicolumn_db::getContainerChildren($orginalId);
        // copy children too
        if ($containerChildren) {
            $pid = $this->pObj->pageCache ? key($this->pObj->pageCache) : key($this->pObj->cachedTSconfig);

            // copy or move
            ($action == 'copy') ? $this->copyMulticolumnContainer($updateId, $containerChildren, $pid) : $this->moveContainerChildren($containerChildren, $pid);
        }
    }

    /**
     * Delete multicolumn container with children elements (recursive)
     *
     * @param array $containerChildren Content elements of multicolumn container
     *
     * @return void
     */
    protected function deleteMulticolumnContainer(array $containerChildren)
    {
        foreach ($containerChildren as $child) {
            $this->pObj->deleteRecord('tt_content', $child['uid']);

            // if is element a multicolumn element ? delete children too (recursive)
            if ($child['CType'] == 'multicolumn') {
                $containerChildrenChildren = tx_multicolumn_db::getContainerChildren($child['uid']);
                if ($containerChildrenChildren) {
                    $this->deleteMulticolumnContainer($containerChildrenChildren);
                }
            }
        }
    }

    /**
     * Localize multicolumn children
     *
     * @param array $elementsToBeLocalized
     * @param int $multicolumnParentId
     * @param int $sysLanguageUid
     *
     * @return void
     */
    protected function localizeMulticolumnChildren(array $elementsToBeLocalized, $multicolumnParentId, $sysLanguageUid)
    {
        foreach ($elementsToBeLocalized as $element) {
            //create localization
            $newUid = $this->pObj->localize('tt_content', $element['uid'], $sysLanguageUid);
            if ($newUid) {
                $fields_values = [
                    'tx_multicolumn_parentid' => $multicolumnParentId,
                ];

                tx_multicolumn_db::updateContentElement($newUid, $fields_values);

                // if is element a multicolumn element ? localize children too (recursive)
                if ($element['CType'] == 'multicolumn') {
                    $containerChildrenChildren = tx_multicolumn_db::getContainerChildren($element['uid']);
                    if (!empty($containerChildrenChildren)) {
                        $this->localizeMulticolumnChildren($containerChildrenChildren, $newUid, $sysLanguageUid);
                    }
                }
            }
        }
    }

    /**
     * If an elements get copied outside from a multicontainer inside a multicolumncontainer add multicolumn parent id
     * to content element
     *
     * @param string $status
     * @param string $table
     * @param mixed $id
     * @param array $fieldArray
     * @param \TYPO3\CMS\Core\DataHandling\DataHandler $pObj
     *
     * @return void
     */
    public function processDatamap_postProcessFieldArray($status, $table, $id, &$fieldArray, \TYPO3\CMS\Core\DataHandling\DataHandler $pObj)
    {
        if ($status == 'new' && $table == 'tt_content' && $fieldArray['CType'] == 'multicolumn') {
            $this->pObj = $pObj;

            // get multicolumn status of element before?
            $fieldArray = $this->checkIfElementGetsCopiedOrMovedInsideOrOutsideAMulticolumnContainer($this->pObj->checkValue_currentRecord['pid'], $fieldArray);
        }
    }

    /**
     * If an elements get moved outside from a multicontainer inside to a multicolumncontainer
     * add tx_multicolumn_parentid to moved record
     *
     * @param string $table
     * @param int $uid
     * @param int $destPid
     * @param int $origDestPid
     * @param array $moveRec
     * @param array $updateFields
     * @param \TYPO3\CMS\Core\DataHandling\DataHandler $pObj
     *
     * @return void
     */
    public function moveRecord_afterAnotherElementPostProcess($table, $uid, $destPid, $origDestPid, $moveRec, $updateFields, \TYPO3\CMS\Core\DataHandling\DataHandler $pObj)
    {
        // check if we must update the move record
        if ($table == 'tt_content' && ($this->isMulticolumnContainer($uid) || tx_multicolumn_db::contentElementHasAMulticolumnParentContainer($uid) || (($origDestPid < 0) && tx_multicolumn_db::contentElementHasAMulticolumnParentContainer(abs($origDestPid))))) {
            if (!$this->getMulticolumnGetAction() == 'pasteInto') {
                $updateRecordFields = [];
                $updateRecordFields = $this->checkIfElementGetsCopiedOrMovedInsideOrOutsideAMulticolumnContainer($origDestPid, $updateRecordFields);

                tx_multicolumn_db::updateContentElement($uid, $updateRecordFields);

                // check language
                if ($origDestPid < 0) {
                    $recordBeforeUid = abs($origDestPid);

                    $row = tx_multicolumn_db::getContentElement($recordBeforeUid, 'sys_language_uid');
                    $sysLanguageUid = $row['sys_language_uid'];

                    $containerChildren = tx_multicolumn_db::getContainerChildren($uid);
                    if (is_array($containerChildren)) {
                        $firstElement = $containerChildren[0];
                        // update only if destination has a diffrent langauge
                        if (!($firstElement['sys_language_uid'] == $sysLanguageUid)) {
                            $this->updateLanguage($containerChildren, $sysLanguageUid);
                        }
                    }
                }

                // update children (only if container is moved to a new page)
                if ($moveRec['pid'] != $destPid) {
                    $this->checkIfContainerHasChilds($table, $uid, $destPid, $pObj);
                }
            }
        }
    }

    /**
     * If an elements get moved – move child records from multicolumn container too
     *
     * @param string $table
     * @param int $uid The record uid currently processing
     * @param int $destPid The page id of the moved record
     * @param array $moveRec Record to move
     * @param array $updateFields Updated fields
     * @param \TYPO3\CMS\Core\DataHandling\DataHandler $pObj
     *
     * @return void
     */
    public function moveRecord_firstElementPostProcess($table, $uid, $destPid, array $moveRec, array $updateFields, \TYPO3\CMS\Core\DataHandling\DataHandler $pObj)
    {
        if ($table == 'tt_content' && $this->isMulticolumnContainer($uid)) {
            if (!$this->getMulticolumnGetAction() == 'pasteInto') {
                $this->checkIfContainerHasChilds($table, $uid, $destPid, $pObj);
            }
        }
    }

    /**
     * If an elements get moved – move child records from multicolumn container too
     *
     * @param string $table The table currently processing data for
     * @param int $uid The record uid currently processing
     * @param int $destPid The page id of the moved record
     * @param \TYPO3\CMS\Core\DataHandling\DataHandler $pObj
     *
     * @return void
     */
    protected function checkIfContainerHasChilds($table, $uid, $destPid, \TYPO3\CMS\Core\DataHandling\DataHandler $pObj)
    {
        $this->pObj = $pObj;

        $row = \TYPO3\CMS\Backend\Utility\BackendUtility::getRecordWSOL($table, $uid);
        if ($row['CType'] == 'multicolumn') {
            $containerChildren = tx_multicolumn_db::getContainerChildren($row['uid']);
            if ($containerChildren) {
                $this->moveContainerChildren($containerChildren, $destPid);
            }
            // if element is moved as first element on page ? set multicolumn_parentid and colPos to 0
        } elseif ($row['tx_multicolumn_parentid']) {
            $multicolumnContainerExists = tx_multicolumn_db::getContentElement($row['tx_multicolumn_parentid'], 'uid', 'AND pid=' . $row['pid']);
            if (!$multicolumnContainerExists) {
                $updateRecordFields = [
                    'tx_multicolumn_parentid' => 0,
                    'colPos' => 0,
                ];
                tx_multicolumn_db::updateContentElement($row['uid'], $updateRecordFields);
            }
        }
    }

    /**
     * Move container children (recursive)
     *
     * @param array $containerChildren Children of multicolumn container
     * @param int $destPid : Target pid of page
     */
    protected function moveContainerChildren(array $containerChildren, $destPid)
    {
        foreach ($containerChildren as $child) {
            $this->pObj->moveRecord_raw('tt_content', $child['uid'], $destPid);
        }
    }

    /**
     * Updates the language of container children
     *
     * @param array $containerChildren Children of multicolumn container
     * @param int $sysLanguageUid
     *
     * @return void
     */
    protected function updateLanguage(array $containerChildren, $sysLanguageUid)
    {
        foreach ($containerChildren as $child) {
            $updateRecordFields = [
                'sys_language_uid' => $sysLanguageUid,
            ];
            tx_multicolumn_db::updateContentElement($child['uid'], $updateRecordFields);
        }
    }

    /**
     * Set new multicolumn container id for content elements and copies children of multicolumn container (recursive)
     *
     * @param int $id new multicolumn element id
     * @param array $elementsToCopy Content element array with uid, and pid
     * @param int $pid Target pid of page
     * @param int $sysLanguageUid
     *
     * @return void
     */
    protected function copyMulticolumnContainer($id, array $elementsToCopy, $pid, $sysLanguageUid = 0)
    {
        $overrideValues = [
            'tx_multicolumn_parentid' => $id,
            'sys_language_uid' => $sysLanguageUid,
        ];

        foreach ($elementsToCopy as $element) {
            $newUid = $this->pObj->copyRecord_raw('tt_content', $element['uid'], $pid, $overrideValues);

            // if is element a multicolumn element ? copy children too (recursive)
            if ($element['CType'] == 'multicolumn') {
                $containerChildren = tx_multicolumn_db::getContainerChildren($element['uid']);

                if ($containerChildren) {
                    $copiedMulticolumncontainer = tx_multicolumn_db::getContentElement($newUid, 'uid,pid');

                    $this->copyMulticolumnContainer($newUid, $containerChildren, $copiedMulticolumncontainer['pid'], $sysLanguageUid);
                }
            }
        }
    }

    /**
     * If an elements get copied outside from a multicontainer inside a multicolumncontainer or inverse
     * add or remove multicolumn parent id to content element
     *
     * @param int $pidToCheck
     * @param array $fieldArray The field array of a record
     *
     * @return array Modified field array
     */
    protected function checkIfElementGetsCopiedOrMovedInsideOrOutsideAMulticolumnContainer($pidToCheck, array &$fieldArray)
    {
        if ($pidToCheck < 0) {
            $elementId = abs($pidToCheck);
            $elementBefore = \TYPO3\CMS\Backend\Utility\BackendUtility::getRecord('tt_content', $elementId, 'tx_multicolumn_parentid, colPos');

            if ($elementBefore['tx_multicolumn_parentid']) {
                $fieldArray['tx_multicolumn_parentid'] = $elementBefore['tx_multicolumn_parentid'];
            } else {
                $fieldArray['tx_multicolumn_parentid'] = 0;
            }
            $fieldArray['colPos'] = $elementBefore['colPos'];
        }

        return $fieldArray;
    }

    /**
     * Check uid if is a multicolumn container
     *
     * @param int $uid
     *
     * @return bool
     */
    protected function isMulticolumnContainer($uid)
    {
        return is_array(tx_multicolumn_db::getContainerFromUid($uid, 'uid'));
    }

    /**
     * Evaluates specific multicolumn get &tx_multicolumn[action]
     * currently the action as GET var is used only for paste into clickmenu action
     *
     * @return string Value of action
     */
    protected function getMulticolumnGetAction()
    {
        $gpVars = \TYPO3\CMS\Core\Utility\GeneralUtility::_GET('tx_multicolumn');

        return is_array($gpVars) && isset($gpVars['action']) ? $gpVars['action'] : '';
    }
}
