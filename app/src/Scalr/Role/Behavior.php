<?php

use Scalr\Farm\Role\FarmRoleStorage;
use Scalr\Modules\PlatformFactory;
use Scalr\Util\CryptoTool;

class Scalr_Role_Behavior
{
    const ROLE_DM_APPLICATION_ID = 'dm.application_id';
    const ROLE_DM_REMOTE_PATH = 'dm.remote_path';

    const ROLE_BASE_KEEP_SCRIPTING_LOGS_TIME    = 'base.keep_scripting_logs_time';
    const ROLE_BASE_ABORT_INIT_ON_SCRIPT_FAIL   = 'base.abort_init_on_script_fail';
    const ROLE_BASE_DISABLE_FIREWALL_MANAGEMENT = 'base.disable_firewall_management';
    const ROLE_BASE_HOSTNAME_FORMAT             = 'base.hostname_format';
    const ROLE_BASE_CUSTOM_TAGS                 = 'base.custom_tags';
    const ROLE_INSTANCE_NAME_FORMAT             = 'base.instance_name_format';

    const ROLE_BASE_TERMINATE_STRATEGY =       'base.terminate_strategy';
    const ROLE_BASE_CONSIDER_SUSPENDED =       'base.consider_suspended';

    const ROLE_BASE_SZR_UPD_REPOSITORY		= 'base.upd.repository';
    const ROLE_BASE_SZR_UPD_SCHEDULE	    = 'base.upd.schedule';

    const ROLE_BASE_API_PORT = 'base.api_port';
    const ROLE_BASE_MESSAGING_PORT = 'base.messaging_port';

    const SERVER_BASE_HOSTNAME = 'base.hostname';

    const RESUME_STRATEGY_REBOOT = 'reboot';
    const RESUME_STRATEGY_INIT = 'init';
    const RESUME_STRATEGY_NOT_SUPPORTED = 'not-supported';

    protected $behavior;

    /**
     * @var \ADODB_mysqli
     */
    public $db;

    /**
     * @var CryptoTool
     */
    protected $crypto;

    /**
     * @return Scalr_Role_Behavior
     * @param unknown_type $name
     */
    static public function loadByName($name)
    {
        switch ($name)
        {
            case ROLE_BEHAVIORS::CF_CLOUD_CONTROLLER:
                $obj = 'Scalr_Role_Behavior_CfCloudController';
                break;

            case ROLE_BEHAVIORS::CF_ROUTER:
                $obj = 'Scalr_Role_Behavior_CfRouter';
                break;

            case ROLE_BEHAVIORS::RABBITMQ:
                $obj = 'Scalr_Role_Behavior_RabbitMQ';
                break;

            case ROLE_BEHAVIORS::CHEF:
                $obj = 'Scalr_Role_Behavior_Chef';
                break;

            case ROLE_BEHAVIORS::MONGODB:
                $obj = 'Scalr_Role_Behavior_MongoDB';
                break;

            case ROLE_BEHAVIORS::MYSQLPROXY:
                $obj = 'Scalr_Role_Behavior_MysqlProxy';
                break;

            case ROLE_BEHAVIORS::APACHE:
                $obj = 'Scalr_Role_Behavior_Apache';
                break;

            case ROLE_BEHAVIORS::NGINX:
                $obj = 'Scalr_Role_Behavior_Nginx';
                break;

            case ROLE_BEHAVIORS::MYSQL2:
                $obj = 'Scalr_Role_Behavior_Mysql2';
                break;

            case ROLE_BEHAVIORS::PERCONA:
                $obj = 'Scalr_Role_Behavior_Percona';
                break;

            case ROLE_BEHAVIORS::MARIADB:
                $obj = 'Scalr_Role_Behavior_MariaDB';
                break;

            case ROLE_BEHAVIORS::POSTGRESQL:
                $obj = 'Scalr_Role_Behavior_Postgresql';
                break;

            case ROLE_BEHAVIORS::REDIS:
                $obj = 'Scalr_Role_Behavior_Redis';
                break;

            case ROLE_BEHAVIORS::HAPROXY:
                $obj = 'Scalr_Role_Behavior_HAProxy';
                break;

            case ROLE_BEHAVIORS::VPC_ROUTER:
                $obj = 'Scalr_Role_Behavior_Router';
                break;


            default:
                $obj = 'Scalr_Role_Behavior';
                break;
        }

        return new $obj($name);
    }

    /**
     *
     * Enter description here ...
     * @param DBRole $dbRole
     * @return Scalr_Role_Behavior
     */
    static public function getListForRole(DBRole $role)
    {
        $list = array();
        foreach ($role->getBehaviors() as $behavior)
            $list[] = self::loadByName($behavior);

        return $list;
    }

    /**
     *
     * Enter description here ...
     * @param DBFarmRole $dbFarmRole
     * @return Scalr_Role_Behavior
     */
    static public function getListForFarmRole(DBFarmRole $farmRole)
    {
        return self::getListForRole($farmRole->GetRoleObject());
    }

    public function __construct($behavior = ROLE_BEHAVIORS::BASE)
    {
        $this->behavior = $behavior;
        $this->logger = Logger::getLogger(__CLASS__);
        $this->db = \Scalr::getDb();
    }

    public function getConfiguration(DBServer $dbServer) {

    }

    /**
     * Handle message from scalarizr
     *
     * @param Scalr_Messaging_Msg $message
     * @param DBServer $dbServer
     */
    public function handleMessage(Scalr_Messaging_Msg $message, DBServer $dbServer) {

        switch (get_class($message))
        {
            case "Scalr_Messaging_Msg_HostUpdate":

                if ($message->base->apiPort) {
                    $currentApiPort = $dbServer->getPort(DBServer::PORT_API);
                    $this->logger->warn(new FarmLogMessage(
                        $dbServer->farmId, "Scalarizr API port was changed from {$currentApiPort} to {$message->base->apiPort}"
                    ));
                    $dbServer->SetProperty(SERVER_PROPERTIES::SZR_API_PORT, $message->base->apiPort);
                }

                if ($message->base->messagingPort) {
                    $currentCtrlPort = $dbServer->getPort(DBServer::PORT_CTRL);
                    $this->logger->warn(new FarmLogMessage(
                        $dbServer->farmId, "Scalarizr CTRL port was changed from {$currentCtrlPort} to {$message->base->messagingPort}"
                    ));
                    $dbServer->SetProperty(SERVER_PROPERTIES::SZR_CTRL_PORT, $message->base->messagingPort);
                }

                break;


            case "Scalr_Messaging_Msg_HostUp":
                try {
                    if (!empty($message->volumes) && $dbServer->farmRoleId) {
                        $storage = new FarmRoleStorage($dbServer->GetFarmRoleObject());
                        $storage->setVolumes($dbServer, $message->volumes);
                    }
                } catch (Exception $e) {
                    $this->logger->error(new FarmLogMessage($dbServer->farmId, "Error in role message handler: {$e->getMessage()}"));
                }

                if (isset($message->base) && isset($message->base->hostname))
                    $dbServer->SetProperty(self::SERVER_BASE_HOSTNAME, $message->base->hostname);
                break;
        }
    }

    public function makeUpscaleDecision(DBFarmRole $dbFarmRole)
    {
        return false;
    }

    public function getSecurityRules()
    {
        return array();
    }

    /**
     * @return CryptoTool
     */
    protected function getCrypto()
    {
        if (!$this->crypto) {
            $this->crypto = \Scalr::getContainer()->crypto;
        }

        return $this->crypto;
    }

    public function getDnsRecords(DBServer $dbServer)
    {
        return array();
    }

    public function getBaseConfiguration(DBServer $dbServer, $isHostInit = false)
    {
        $configuration = new stdClass();
        $dbFarmRole = $dbServer->GetFarmRoleObject();

        //Storage
        try {
            if ($dbFarmRole) {
                $storage = new FarmRoleStorage($dbFarmRole);
                $volumes = $storage->getVolumesConfigs($dbServer, $isHostInit);
                if (!empty($volumes))
                    $configuration->volumes = $volumes;
            }
        } catch (Exception $e) {
            $this->logger->error(new FarmLogMessage($dbServer->farmId, "Cannot init storage: {$e->getMessage()}"));
        }

        // Base
        try {
            if ($dbFarmRole) {
                $scriptingLogTimeout = $dbFarmRole->GetSetting(self::ROLE_BASE_KEEP_SCRIPTING_LOGS_TIME);
                if (!$scriptingLogTimeout)
                    $scriptingLogTimeout = 3600;

                $configuration->base = new stdClass();
                $configuration->base->keepScriptingLogsTime = $scriptingLogTimeout;
                $configuration->base->abortInitOnScriptFail = (int)$dbFarmRole->GetSetting(self::ROLE_BASE_ABORT_INIT_ON_SCRIPT_FAIL);
                $configuration->base->disableFirewallManagement = (int)$dbFarmRole->GetSetting(self::ROLE_BASE_DISABLE_FIREWALL_MANAGEMENT);

                $configuration->base->resumeStrategy = PlatformFactory::NewPlatform($dbFarmRole->Platform)->getResumeStrategy();

                $governance = new Scalr_Governance($dbFarmRole->GetFarmObject()->EnvID);
                if ($governance->isEnabled(Scalr_Governance::CATEGORY_GENERAL, Scalr_Governance::GENERAL_HOSTNAME_FORMAT)) {
                    $hostNameFormat = $governance->getValue(Scalr_Governance::CATEGORY_GENERAL, Scalr_Governance::GENERAL_HOSTNAME_FORMAT);
                } else {
                    $hostNameFormat = $dbFarmRole->GetSetting(self::ROLE_BASE_HOSTNAME_FORMAT);
                }
                $configuration->base->hostname = (!empty($hostNameFormat)) ? $dbServer->applyGlobalVarsToValue($hostNameFormat) : '';

                $apiPort = $dbFarmRole->GetSetting(self::ROLE_BASE_API_PORT);
                $messagingPort = $dbFarmRole->GetSetting(self::ROLE_BASE_MESSAGING_PORT);
                $configuration->base->apiPort = ($apiPort) ? $apiPort : 8010;
                $configuration->base->messagingPort = ($messagingPort) ? $messagingPort : 8013;
            }

            //Update settings
            $updateSettings = $dbServer->getScalarizrRepository();
            $configuration->base->update = new stdClass();
            foreach ($updateSettings as $k => $v)
                $configuration->base->update->{$k} = $v;

        } catch (Exception $e) {}

        return $configuration;
    }

    public function extendMessage(Scalr_Messaging_Msg $message, DBServer $dbServer)
    {
        if (in_array(ROLE_BEHAVIORS::BASE, $message->handlers))
            return $message;

        if ($dbServer->farmRoleId) {
            $dbFarmRole = DBFarmRole::LoadByID($dbServer->farmRoleId);
        }

        switch (get_class($message))
        {
            case "Scalr_Messaging_Msg_BeforeHostTerminate":

                //Storage
                try {
                    if ($dbFarmRole) {
                        $storage = new FarmRoleStorage($dbFarmRole);
                        $volumes = $storage->getVolumesConfigs($dbServer, false);
                        if (!empty($volumes))
                            $message->volumes = $volumes;
                    }
                } catch (Exception $e) {
                    $this->logger->error(new FarmLogMessage($dbServer->farmId, "Cannot init storage: {$e->getMessage()}"));
                }

                break;

            case "Scalr_Messaging_Msg_HostInitResponse":

                //Deployments
                try {
                    if ($dbFarmRole) {
                        $appId = $dbFarmRole->GetSetting(self::ROLE_DM_APPLICATION_ID);
                        if ($appId) {
                            $application = Scalr_Dm_Application::init()->loadById($appId);
                            $deploymentTask = Scalr_Dm_DeploymentTask::init();
                            $deploymentTask->create(
                                    $dbServer->farmRoleId,
                                    $appId,
                                    $dbServer->serverId,
                                    Scalr_Dm_DeploymentTask::TYPE_AUTO,
                                    $dbFarmRole->GetSetting(self::ROLE_DM_REMOTE_PATH),
                                    $dbServer->envId,
                                    Scalr_Dm_DeploymentTask::STATUS_DEPLOYING
                            );
                            $message->deploy = $deploymentTask->getDeployMessageProperties();
                        }
                    }
                } catch (Exception $e) {
                    $this->logger->error(new FarmLogMessage($dbServer->farmId, "Cannot init deployment: {$e->getMessage()}"));
                }

                $configuration = $this->getBaseConfiguration($dbServer, true);
                if ($configuration->volumes)
                    $message->volumes = $configuration->volumes;

                $message->base = $configuration->base;

                break;
        }

        $message->handlers[] = ROLE_BEHAVIORS::BASE;

        return $message;
    }

    public function onFarmSave(DBFarm $dbFarm, DBFarmRole $dbFarmRole)
    {

    }

    public function onBeforeHostTerminate(DBServer $dbServer)
    {

    }

    public function onFarmTerminated(DBFarmRole $dbFarmRole)
    {

    }

    public function onHostDown(DBServer $dbServer)
    {
        
    }

    public function onBeforeInstanceLaunch(DBServer $dbServer)
    {

    }

    public function setSnapshotConfig($snapshotConfig, DBFarmRole $dbFarmRole, DBServer $dbServer)
    {
        try {
            $storageSnapshot = Scalr_Storage_Snapshot::init();

            try {
                $storageSnapshot->loadById($snapshotConfig->id);
                $storageSnapshot->setConfig($snapshotConfig);
                $storageSnapshot->save();
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'not found')) {
                    $storageSnapshot->loadBy(array(
                        'id'			=> $snapshotConfig->id,
                        'client_id'		=> $dbServer->clientId,
                        'farm_id'		=> $dbServer->farmId,
                        'farm_roleid'	=> $dbServer->farmRoleId,
                        'env_id'		=> $dbServer->envId,
                        'name'			=> sprintf(_("%s data bundle #%s"), $this->behavior, $snapshotConfig->id),
                        'type'			=> $dbFarmRole->GetSetting(static::ROLE_DATA_STORAGE_ENGINE),
                        'platform'		=> $dbServer->platform,
                        'description'	=> sprintf(_("{$this->behavior} data bundle created on Farm '%s' -> Role '%s'"),
                            $dbFarmRole->GetFarmObject()->Name,
                            $dbFarmRole->GetRoleObject()->name
                        ),
                        'service'		=> $this->behavior
                    ));
                    $storageSnapshot->setConfig($snapshotConfig);
                    $storageSnapshot->save(true);
                }
                else
                    throw $e;
            }

            $dbFarmRole->SetSetting(static::ROLE_SNAPSHOT_ID, $storageSnapshot->id, DBFarmRole::TYPE_LCL);
        }
        catch(Exception $e) {
            $this->logger->error(new FarmLogMessage($dbFarmRole->FarmID, "Cannot save storage volume: {$e->getMessage()}"));
        }
    }

    public function setVolumeConfig($volumeConfig, DBFarmRole $dbFarmRole, DBServer $dbServer)
    {
        try {
            $storageVolume = Scalr_Storage_Volume::init();
            try {
                $storageVolume->loadById($volumeConfig->id);
                $storageVolume->setConfig($volumeConfig);
                $storageVolume->save();
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'not found')) {
                    $storageVolume->loadBy(array(
                        'id'			=> $volumeConfig->id,
                        'client_id'		=> $dbFarmRole->GetFarmObject()->ClientID,
                        'env_id'		=> $dbFarmRole->GetFarmObject()->EnvID,
                        'name'			=> sprintf("'%s' data volume", $this->behavior),
                        'type'			=> $dbFarmRole->GetSetting(static::ROLE_DATA_STORAGE_ENGINE),
                        'platform'		=> $dbFarmRole->Platform,
                        'size'			=> $volumeConfig->size,
                        'fstype'		=> $volumeConfig->fstype,
                        'purpose'		=> $this->behavior,
                        'farm_roleid'	=> $dbFarmRole->ID,
                        'server_index'	=> $dbServer->index
                    ));
                    $storageVolume->setConfig($volumeConfig);
                    $storageVolume->save(true);
                }
                else
                    throw $e;
            }

            $dbFarmRole->SetSetting(static::ROLE_VOLUME_ID, $storageVolume->id, DBFarmRole::TYPE_LCL);
        }
        catch(Exception $e) {
            $this->logger->error(new FarmLogMessage($dbFarmRole->FarmID, "Cannot save storage volume: {$e->getMessage()}"));
        }
    }

    public function getSnapshotConfig(DBFarmRole $dbFarmRole, DBServer $dbServer)
    {
        $r = new ReflectionClass($this);
        if ($r->hasConstant("ROLE_SNAPSHOT_ID")) {
            if ($dbFarmRole->GetSetting(static::ROLE_SNAPSHOT_ID))
            {
                try {
                    $snapshot = Scalr_Storage_Snapshot::init()->loadById(
                        $dbFarmRole->GetSetting(static::ROLE_SNAPSHOT_ID)
                    );

                    return $snapshot->getConfig();
                } catch (Exception $e) {}
            }
        }

        return false;
    }

    public function getVolumeConfig(DBFarmRole $dbFarmRole, DBServer $dbServer)
    {
        $r = new ReflectionClass($this);
        if ($r->hasConstant("ROLE_VOLUME_ID")) {
            if ($dbFarmRole->GetSetting(static::ROLE_VOLUME_ID))
            {
                try {
                    $volume = Scalr_Storage_Volume::init()->loadById(
                        $dbFarmRole->GetSetting(static::ROLE_VOLUME_ID)
                    );

                    $volumeConfig = $volume->getConfig();
                } catch (Exception $e) {}
            }

            if (!$volumeConfig)
            {
                $volumeConfig = new stdClass();
                $volumeConfig->type = $dbFarmRole->GetSetting(static::ROLE_DATA_STORAGE_ENGINE);

                if (in_array($dbFarmRole->GetSetting(static::ROLE_DATA_STORAGE_ENGINE), array(MYSQL_STORAGE_ENGINE::EBS, MYSQL_STORAGE_ENGINE::CSVOL))) {
                    $volumeConfig->size = $dbFarmRole->GetSetting(static::ROLE_DATA_STORAGE_EBS_SIZE);
                }
                // For RackSpace
                //TODO:
                elseif ($dbFarmRole->GetSetting(static::ROLE_DATA_STORAGE_ENGINE) == MYSQL_STORAGE_ENGINE::EPH) {
                    $volumeConfig->snap_backend = sprintf("cf://scalr-%s-%s/data-bundles/%s/%s",
                        $dbServer->envId,
                        $dbServer->GetCloudLocation(),
                        $dbFarmRole->FarmID,
                        $this->behavior
                    );
                    $volumeConfig->vg = $this->behavior;
                    $volumeConfig->disk = new stdClass();
                    $volumeConfig->disk->type = 'loop';
                    $volumeConfig->disk->size = '75%root';
                }
            }

            return $volumeConfig;

        } else
            return false;
    }
}