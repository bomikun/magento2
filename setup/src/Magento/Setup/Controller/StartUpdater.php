<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Setup\Controller;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Setup\Model\Cron\JobComponentUninstall;
use Zend\Json\Json;
use Zend\Mvc\Controller\AbstractActionController;
use Magento\Setup\Model\Updater as ModelUpdater;
use Zend\View\Model\JsonModel;
use Zend\View\Model\ViewModel;
use Magento\Framework\Module\FullModuleList;

/**
 * Controller for updater tasks
 */
class StartUpdater extends AbstractActionController
{
    /**#@+
     * Keys in Post payload
     */
    const KEY_POST_JOB_TYPE = 'type';
    const KEY_POST_PACKAGES = 'packages';
    const KEY_POST_HEADER_TITLE = 'headerTitle';
    const KEY_POST_DATA_OPTION = 'dataOption';
    const KEY_POST_PACKAGE_NAME = 'name';
    const KEY_POST_PACKAGE_VERSION = 'version';
    /**#@- */

    /**
     * @var \Magento\Framework\Filesystem
     */
    private $filesystem;

    /**
     * @var \Magento\Setup\Model\Navigation
     */
    private $navigation;

    /**
     * @var ModelUpdater
     */
    private $updater;

    /**
     * @var FullModuleList
     */
    private $moduleList;

    /**
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Setup\Model\Navigation $navigation
     * @param ModelUpdater $updater
     * @param FullModuleList $moduleList
     */
    public function __construct(
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Setup\Model\Navigation $navigation,
        ModelUpdater $updater,
        FullModuleList $moduleList
    ) {
        $this->filesystem = $filesystem;
        $this->navigation = $navigation;
        $this->updater = $updater;
        $this->moduleList = $moduleList;
    }

    /**
     * Index page action
     *
     * @return ViewModel
     */
    public function indexAction()
    {
        $view = new ViewModel();
        $view->setTerminal(true);
        return $view;
    }

    /**
     * Update action
     *
     * @return JsonModel
     */
    public function updateAction()
    {
        $postPayload = Json::decode($this->getRequest()->getContent(), Json::TYPE_ARRAY);
        $errorMessage = '';
        if (isset($postPayload[self::KEY_POST_PACKAGES])
            && is_array($postPayload[self::KEY_POST_PACKAGES])
            && isset($postPayload[self::KEY_POST_JOB_TYPE])
        ) {
            $errorMessage .= $this->validatePayload($postPayload);
            if (empty($errorMessage)) {
                $packages = $postPayload[self::KEY_POST_PACKAGES];
                $jobType = $postPayload[self::KEY_POST_JOB_TYPE];
                $this->createTypeFlag($jobType, $postPayload[self::KEY_POST_HEADER_TITLE]);

                $additionalOptions = [];
                $cronTaskType = '';
                $this->getCronTaskConfigInfo($jobType, $postPayload, $additionalOptions, $cronTaskType);

                $errorMessage .= $this->updater->createUpdaterTask(
                    $packages,
                    $cronTaskType,
                    $additionalOptions
                );

                // for module enable job types, we need to follow up with 'setup:upgrade' task to
                // make sure enabled modules are properly registered
                if ($jobType == 'enable') {
                    $errorMessage .= $this->updater->createUpdaterTask(
                        [],
                        \Magento\Setup\Model\Cron\JobFactory::JOB_UPGRADE,
                        []
                    );
                }
            }
        } else {
            $errorMessage .= 'Invalid request';
        }
        $success = empty($errorMessage) ? true : false;
        return new JsonModel(['success' => $success, 'message' => $errorMessage]);
    }

    /**
     * Validate POST request payload
     *
     * @param array $postPayload
     * @return string
     */
    private function validatePayload(array $postPayload)
    {
        $errorMessage = '';
        $packages = $postPayload[self::KEY_POST_PACKAGES];
        $jobType = $postPayload[self::KEY_POST_JOB_TYPE];
        if ($jobType == 'uninstall' && !isset($postPayload[self::KEY_POST_DATA_OPTION])) {
            $errorMessage .= 'Missing dataOption' . PHP_EOL;
        }
        foreach ($packages as $package) {
            if (!isset($package[self::KEY_POST_PACKAGE_NAME])
                || ($jobType == 'update' && !isset($package[self::KEY_POST_PACKAGE_VERSION]))
            ) {
                $errorMessage .= 'Missing package information' . PHP_EOL;
                break;
            }

            // enable or disable job types expect magento module name
            if(($jobType == 'enable') || ($jobType == 'disable')) {
                //check to make sure that the passed in magento module name is valid
                if(!$this->moduleList->has($package[self::KEY_POST_PACKAGE_NAME])) {
                    $errorMessage .= 'Invalid Magento module name: ' . $package[self::KEY_POST_PACKAGE_NAME] . PHP_EOL;
                    break;
                }
            }
        }
        return $errorMessage;
    }

    /**
     * Create flag to be used in Updater
     *
     * @param string $type
     * @param string $title
     * @return void
     */
    private function createTypeFlag($type, $title)
    {
        $data = [];
        $data[self::KEY_POST_JOB_TYPE] = $type;
        $data[self::KEY_POST_HEADER_TITLE] = $title;

        $menuItems = $this->navigation->getMenuItems();
        $titles = [];
        foreach ($menuItems as $menuItem) {
            if (isset($menuItem['type']) && $menuItem['type'] === $type) {
                $titles[] = str_replace("\n", '<br />', $menuItem['title']);
            }
        }
        $data['titles'] = $titles;
        $directoryWrite = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $directoryWrite->writeFile('.type.json', Json::encode($data));
    }

    /**
     * Returns cron config info based on passed in job type
     *
     * @param string $jobType
     * @param array $postPayload
     * @param array $addtionalOptions
     * @param string $cronTaskType
     * @return void
     */
    private function getCronTaskConfigInfo($jobType, $postPayload, &$additionalOptions, &$cronTaskType)
    {
        $additionalOptions = [];
        switch($jobType) {
            case 'uninstall':
                $additionalOptions = [
                    JobComponentUninstall::DATA_OPTION => $postPayload[self::KEY_POST_DATA_OPTION]
                ];
                $cronTaskType = \Magento\Setup\Model\Cron\JobFactory::JOB_COMPONENT_UNINSTALL;
                break;

            case 'upgrade':
            case 'update':
                $cronTaskType = ModelUpdater::TASK_TYPE_UPDATE;
                break;

            case 'enable':
                $cronTaskType = \Magento\Setup\Model\Cron\JobFactory::JOB_MODULE_ENABLE;
                break;

            case 'disable':
                $cronTaskType = \Magento\Setup\Model\Cron\JobFactory::JOB_MODULE_DISABLE;
                break;
        }
    }
}
