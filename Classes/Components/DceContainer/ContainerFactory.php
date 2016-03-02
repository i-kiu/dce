<?php
namespace ArminVieweg\Dce\Components\DceContainer;

/*  | This extension is part of the TYPO3 project. The TYPO3 project is
 *  | free software and is licensed under GNU General Public License.
 *  |
 *  | (c) 2012-2016 Armin Ruediger Vieweg <armin@v.ieweg.de>
 */
use ArminVieweg\Dce\Domain\Model\Dce;
use ArminVieweg\Dce\Utility\DatabaseUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * ContainerFactory
 * Builds DceContainers, which wrap grouped DCEs
 *
 * @package ArminVieweg\Dce
 */
class ContainerFactory
{
    /**
     * Contains uids of content elements which can be skipped
     *
     * @var array
     */
    protected static $contentElementsToSkip = array();

    /**
     * @param Dce $dce
     * @return Container
     */
    public static function makeContainer(Dce $dce)
    {
        $contentObject = $dce->getContentObject();
        static::$contentElementsToSkip[] = $contentObject['uid'];

        /** @var Container $container */
        $container = GeneralUtility::makeInstance(
            'ArminVieweg\Dce\Components\DceContainer\Container',
            $dce
        );

        $contentElements = static::getContentElementsInContainer($dce);
        foreach ($contentElements as $contentElement) {
            try {
                /** @var \ArminVieweg\Dce\Domain\Model\Dce $dce */
                $dceInstance = clone \ArminVieweg\Dce\Utility\Extbase::bootstrapControllerAction(
                    'ArminVieweg',
                    'Dce',
                    'Dce',
                    'renderDce',
                    'Dce',
                    array(
                        'contentElementUid' => $contentElement['uid'],
                        'dceUid' => $dce->getUid()
                    ),
                    true
                );
            } catch (\Exception $exception) {
                continue;
            }
            $container->addDce($dceInstance);
            static::$contentElementsToSkip[] = $contentElement['uid'];
        }
        return $container;
    }

    /**
     * Get content elements rows of following content elements in current row
     *
     * @param Dce $dce
     * @return array
     */
    protected static function getContentElementsInContainer(Dce $dce)
    {
        $contentObject = $dce->getContentObject();
        $sortColumn = $GLOBALS['TCA']['tt_content']['ctrl']['sortby'];
        $where = 'pid = ' . $contentObject['pid'] .
                 ' AND colPos = ' . $contentObject['colPos'] .
                 ' AND ' . $sortColumn . ' > ' . $contentObject[$sortColumn] .
                 ' AND uid != ' . $contentObject['uid'] .
                 DatabaseUtility::getEnabledFields('tt_content');

        $rawContentElements = DatabaseUtility::getDatabaseConnection()->exec_SELECTgetRows(
            '*',
            'tt_content',
            $where,
            '',
            $sortColumn . ' asc',
            $dce->getContainerItemLimit() ? $dce->getContainerItemLimit() - 1 : ''
        );
        array_unshift($rawContentElements, $contentObject);

        $resolvedContentElements = static::resolveShortcutElements($rawContentElements);

        $contentElementsInContainer = array();
        foreach ($resolvedContentElements as $rawContentElement) {
            if ($rawContentElement['CType'] !== 'dce_dceuid' . $dce->getUid() ||
                ($contentObject['uid'] !== $rawContentElement['uid'] &&
                    $rawContentElement['tx_dce_new_container'] === '1')
            ) {
                return $contentElementsInContainer;
            }
            $contentElementsInContainer[] = $rawContentElement;
        }
        return $contentElementsInContainer;
    }

    /**
     * Checks if DCE content element should be skipped instead of rendered.
     *
     * @param array $contentElementRow
     * @return bool Returns true when this content element has been rendered already
     */
    public static function checkContentElementForBeingRendered(array $contentElementRow)
    {
        return in_array($contentElementRow['uid'], static::$contentElementsToSkip);
    }

    /**
     * Clears the content elements to skip. This might be necessary if one page
     * should render the same content element twice (using reference e.g.).
     *
     * @return void
     */
    public static function clearContentElementsToSkip()
    {
        static::$contentElementsToSkip = array();
    }

    /**
     * Returns the first contentObject in container of given DCE.
     *
     * If $dce->getContentObject() === static::getFirstContentObjectInContainer() then
     * the given DCE instance is already the first item in container.
     *
     * @param Dce $dce
     * @return array
     */
    public static function getFirstContentObjectInContainer(Dce $dce)
    {
        $contentObject = $dce->getContentObject();
        if ($contentObject['tx_dce_new_container'] === '1') {
            return $contentObject;
        }

        $sortColumn = $GLOBALS['TCA']['tt_content']['ctrl']['sortby'];
        $where = 'pid = ' . $contentObject['pid'] .
            ' AND colPos = ' . $contentObject['colPos'] .
            ' AND ' . $sortColumn . ' < ' . $contentObject[$sortColumn] .
            DatabaseUtility::getEnabledFields('tt_content');

        $rawContentElements = DatabaseUtility::getDatabaseConnection()->exec_SELECTgetRows(
            '*',
            'tt_content',
            $where,
            '',
            $sortColumn . ' desc'
        );

        $rawContentElementsRespectingNewContainerFlag = static::checkForContainerFlag($rawContentElements);
        $resolvedContentElements = static::resolveShortcutElements($rawContentElementsRespectingNewContainerFlag);

        $lastContentObject = $dce->getContentObject();
        foreach ($resolvedContentElements as $index => $contentElement) {
            if ($dce->getContainerItemLimit() && $dce->getContainerItemLimit() - 1 == $index) {
                return $lastContentObject;
            }
            if ($contentElement['CType'] !== 'dce_dceuid' . $dce->getUid()) {
                return $lastContentObject;
            }
            $lastContentObject = $contentElement;
        }
        return $lastContentObject;
    }

    /**
     * Resolves CType="shortcut" content elements
     *
     * @param array $rawContentElements array with tt_content rows
     * @return array
     */
    protected static function resolveShortcutElements(array $rawContentElements)
    {
        $resolvedContentElements = array();
        foreach ($rawContentElements as $rawContentElement) {
            if ($rawContentElement['CType'] === 'shortcut') {
                $linkedContentElements = DatabaseUtility::getDatabaseConnection()->exec_SELECTgetRows(
                    '*',
                    'tt_content',
                    'uid IN (' . $rawContentElement['records'] . ')',
                    '',
                    $GLOBALS['TCA']['tt_content']['ctrl']['sortby'] . ' asc'
                );
                foreach ($linkedContentElements as $linkedContentElement) {
                    $resolvedContentElements[] = $linkedContentElement;
                }
            } else {
                $resolvedContentElements[] = $rawContentElement;
            }
        }
        return $resolvedContentElements;
    }

    /**
     * @param array $rawContentElements
     * @return array
     */
    private static function checkForContainerFlag(array $rawContentElements)
    {
        $filteredContentElements = array();
        foreach ($rawContentElements as $rawContentElement) {
            $filteredContentElements[] = $rawContentElement;
            if ($rawContentElement['tx_dce_new_container'] === '1') {
                break;
            }
        }
        return $filteredContentElements;
    }
}
