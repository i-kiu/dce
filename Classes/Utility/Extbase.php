<?php
namespace T3\Dce\Utility;

/*  | This extension is made with love for TYPO3 CMS and is licensed
 *  | under GNU General Public License.
 *  |
 *  | (c) 2012-2020 Armin Vieweg <armin@v.ieweg.de>
 */
use T3\Dce\Domain\Repository\DceRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Core\Bootstrap;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * Utility class for the greatest and only existing extension-framework for TYPO3
 */
class Extbase
{
    /**
     * Initializes and runs an extbase controller
     *
     * @param string $vendorName Name of vendor
     * @param string $extensionName Name of extension, in UpperCamelCase
     * @param string $controller Name of controller, in UpperCamelCase
     * @param string $action Optional name of action, in lowerCamelCase (without 'Action' suffix). Default is 'index'.
     * @param string $pluginName Optional name of plugin. Default is 'Pi1'.
     * @param array $settings Optional array of settings to use in controller and fluid template. Default is array.
     * @param bool $compressedObject When true a compressed, serialized object is expected from Extbase return value.
     * @return mixed output of controller's action
     */
    public static function bootstrapControllerAction(
        string $vendorName,
        string $extensionName,
        string $controller,
        string $action = 'index',
        string $pluginName = 'Pi1',
        array $settings = [],
        bool $compressedObject = false
    ) {
        $bootstrap = GeneralUtility::makeInstance(Bootstrap::class);
        $bootstrap->cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $configuration = [
            'vendorName' => $vendorName,
            'extensionName' => $extensionName,
            'controller' => $controller,
            'action' => $action,
            'pluginName' => $pluginName,
            'settings' => $settings
        ];

        if (TYPO3_MODE !== 'FE') {
            $_POST['tx_dce_tools_dcedcemodule']['controller'] = $controller;
            $_POST['tx_dce_tools_dcedcemodule']['action'] = $action;
            $_POST['tx_dce_dce']['controller'] = $controller;
            $_POST['tx_dce_dce']['action'] = $action;
        }

        $previousValue = $GLOBALS['TYPO3_CONF_VARS']['FE']['pageNotFoundOnCHashError'];
        $GLOBALS['TYPO3_CONF_VARS']['FE']['pageNotFoundOnCHashError'] = false;
        if ($settings['returnFromCache']) {
            $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
            /** @var DceRepository $dceRepository */
            $dceRepository = $objectManager->get(DceRepository::class);
            $extbaseReturnValue = $dceRepository->findInCacheByContentObjectUid($settings['contentElementUid']);
        }
        if (!$settings['returnFromCache'] || $extbaseReturnValue === null) {
            $extbaseReturnValue = $bootstrap->run('', $configuration);
        }
        $GLOBALS['TYPO3_CONF_VARS']['FE']['pageNotFoundOnCHashError'] = $previousValue;
        unset($bootstrap);


        if ($compressedObject) {
            return unserialize(gzuncompress($extbaseReturnValue));
        }
        return $extbaseReturnValue;
    }
}
