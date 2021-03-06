<?php

use Scalr\DependencyInjection\Container;
use Scalr\Model\Entity\Image;
use Scalr\Model\Entity\WebhookConfig;
use Scalr\Model\Entity\WebhookHistory;
use Scalr\Modules\PlatformFactory;
use Scalr\Modules\Platforms\Cloudstack\Observers\CloudstackObserver;
use Scalr\Modules\Platforms\Ec2\Observers\EbsObserver;
use Scalr\Modules\Platforms\Ec2\Observers\Ec2Observer;
use Scalr\Modules\Platforms\Ec2\Observers\EipObserver;
use Scalr\Modules\Platforms\Ec2\Observers\ElbObserver;
use Scalr\Modules\Platforms\Openstack\Observers\OpenstackObserver;
use Scalr\Stats\CostAnalytics\Entity\ProjectEntity;

class Scalr
{
    private static $observersSetuped = false;
    private static $EventObservers = array();
    private static $ConfigsCache = array();
    private static $InternalObservable;

    /**
     * Gets DI container
     *
     * @return \Scalr\DependencyInjection\Container
     */
    public static function getContainer()
    {
        return Container::getInstance();
    }

    /**
     * Gets an ADO Database Connection as singleton
     *
     * @param   bool    $forceNewConnection optional Force new connection. (false by default)
     * @return  \ADODB_mysqli
     */
    public static function getDb($forceNewConnection = null)
    {
        return Container::getInstance()->adodb($forceNewConnection);
    }

    /**
     * Gets config value
     *
     * @param  string $name An option name
     * @return mixed  Returns configuration value for the specified key
     */
    public static function config($name)
    {
        //This is only working with yaml config.
        //If you get error here looks like "Call to a member function get() on a non-object",
        //you probably have not migrated your config.ini to config.yml.
        //Please run php app/bin/upgrade_20130624_migrate_config.php
        return Container::getInstance()->config->get($name);
    }

    /**
     * Performs preliminary initialization of the DI container
     */
    public static function initializeContainer()
    {
        Container::reset();
        $container = self::getContainer();

        //Dependency injection container config
        require __DIR__ . '/di.php';
    }

    private static function setupObservers()
    {
        Scalr::AttachObserver(new DBEventObserver());
        Scalr::AttachObserver(new DNSEventObserver());

        Scalr::AttachObserver(new EbsObserver());
        Scalr::AttachObserver(new CloudstackObserver());

        Scalr::AttachObserver(new ScalarizrEventObserver());
        Scalr::AttachObserver(new MessagingEventObserver());
        Scalr::AttachObserver(new BehaviorEventObserver());

        Scalr::AttachObserver(new Ec2Observer());
        Scalr::AttachObserver(new EipObserver());
        Scalr::AttachObserver(new ElbObserver());

        Scalr::AttachObserver(new OpenstackObserver());

        self::$observersSetuped = true;
    }

    /**
     * Attach observer
     *
     * @param EventObserver $observer
     */
    public static function AttachObserver ($observer)
    {
        $list = & self::$EventObservers;

        if (array_search($observer, $list) !== false)
            throw new Exception(_('Observer already attached to class <Scalr>'));

        $list[] = $observer;
    }

    /**
     * Method for multiprocess scripts. We must recreate DB connection created in constructor
     */
    public static function ReconfigureObservers()
    {
        if (!self::$observersSetuped)
            self::setupObservers();

        foreach (self::$EventObservers as &$observer) {
            if (method_exists($observer, "__construct"))
                $observer->__construct();
        }
    }

    /**
     * File event in database
     *
     * @param  integer $farmid
     * @param  string  $event_name
     */
    public static function FireEvent($farmid, Event $event)
    {
        if (!self::$observersSetuped) {
            self::setupObservers();
        }

        $startTime = microtime(true);

        try {
            $event->SetFarmID($farmid);
            $handledObservers = array();

            // Notify class observers
            foreach (self::$EventObservers as $observer) {
                $observerStartTime = microtime(true);

                $observer->SetFarmID($farmid);
                Logger::getLogger(__CLASS__)->info(sprintf("Event %s. Observer: %s", "On{$event->GetName()}", get_class($observer)));

                if ($event instanceof CustomEvent) {
                    call_user_func(array($observer, "OnCustomEvent"), $event);
                } else {
                    call_user_func(array($observer, "On{$event->GetName()}"), $event);
                }

                $handledObservers[substr(strrchr(get_class($observer), "\\"), 1)] = microtime(true) - $observerStartTime;
            }
        } catch (Exception $e) {
            Logger::getLogger(__CLASS__)->fatal(
                sprintf("Exception thrown in Scalr::FireEvent(%s:%s, %s:%s): %s",
                    @get_class($observer),
                    $event->GetName(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getMessage()
                ));
            throw new Exception($e->getMessage());
        }

        $event->handledObservers = $handledObservers;

        self::StoreEvent($farmid, $event, microtime(true) - $startTime);
    }

    /**
     * Store event in database
     *
     * @param integer $farmid
     * @param string $event_name
     */
    public static function StoreEvent($farmid, Event $event, $eventTime = null)
    {
        if ($event->DBServer)
            $eventServerId = $event->DBServer->serverId;

        try {
            $DB = self::getDb();

            // Generate event message
            $message = $event->getTextDetails();

            //short_message temporary used for time tracking
            // Store event in database
            $DB->Execute("INSERT INTO events SET
                farmid	= ?,
                type	= ?,
                dtadded	= NOW(),
                message	= ?,
                event_object = '',
                event_id	 = ?,
                event_server_id = ?,
                short_message = ?,
                msg_expected = ?,
                msg_created = ?,
                scripts_total = ?,
                is_suspend = ?",
                array($farmid, $event->GetName(), $message, $event->GetEventID(), $eventServerId, $eventTime,
                    $event->msgExpected,
                    $event->msgCreated,
                    $event->scriptsCount,
                    $event->isSuspended ? 1 : 0
                )
            );
        }
        catch(Exception $e) {
            Logger::getLogger(__CLASS__)->fatal(sprintf(_("Cannot store event in database: %s"), $e->getMessage()));
        }

        try {
            if ($eventServerId) {
                $dbServer = DBServer::LoadByID($eventServerId);

                if (!$dbServer->farmRoleId)
                    return true;

                $dt = new DateTime('now', new DateTimeZone("UTC"));
                $timestamp = $dt->format("D d M Y H:i:s e");

                $payload = new stdClass();
                $payload->eventName = $event->GetName();
                $payload->eventId = $event->GetEventID();
                $payload->timestamp = $timestamp;

                $globalVars = Scalr_Scripting_GlobalVariables::listServerGlobalVariables(
                    $dbServer,
                    true,
                    $event
                );

                $webhooks = WebhookConfig::findByEvent(
                    $event->GetName(),
                    $farmid,
                    $dbServer->clientId,
                    $dbServer->envId
                );

                $count = 0;
                foreach ($webhooks as $webhook) {
                    /* @var $webhook \Scalr\Model\Entity\WebhookConfig */

                    $payload->configurationId = $webhook->webhookId;
                    $payload->data = array();
                    foreach ($globalVars as $gv) {
                        if ($gv->private && $webhook->skipPrivateGv == 1 && !$gv->system)
                            continue;

                        $payload->data[$gv->name] = $gv->value;
                    }

                    if ($webhook->postData)
                        $payload->userData = $dbServer->applyGlobalVarsToValue($webhook->postData);
                    else
                        $payload->userData = '';

                    foreach ($webhook->getEndpoints() as $ce) {
                        /* @var $ce \Scalr\Model\Entity\WebhookConfigEndpoint */

                        $endpoint = $ce->getEndpoint();
                        if (!$endpoint->isValid)
                            continue;

                        $payload->endpointId = $endpoint->endpointId;
                        $encPayload = json_encode($payload);

                        $history = new WebhookHistory();
                        $history->eventId = $event->GetEventID();
                        $history->eventType = $event->GetName();
                        $history->payload = $encPayload;
                        $history->serverId = ($event->DBServer) ? $event->DBServer->serverId : null;
                        $history->endpointId = $endpoint->endpointId;
                        $history->webhookId = $webhook->webhookId;
                        $history->farmId = $farmid;

                        $history->save();

                        $count++;
                    }
                }

                if ($count != 0)
                    $DB->Execute("UPDATE events SET wh_total = ? WHERE event_id = ?", array($count, $event->GetEventID()));
            }
        } catch (Exception $e) {
            Logger::getLogger(__CLASS__)->fatal(sprintf(_("WebHooks: %s"), $e->getMessage()));
        }
    }

    /**
     * Checks whether current install is hosted scalr
     *
     * @return   boolean  Returns true if current install is a hosted Scalr
     */
    public static function isHostedScalr()
    {
        $hosted = self::config('scalr.hosted.enabled');

        return !empty($hosted);
    }

    /**
     * Checks whether specified account is allowed to manage Cost centers and Projects on hosted scalr account
     *
     * @param    int      $accountId  Identifier of the client's account
     * @return   boolean  Returns true if it is allowed of false otherwise
     */
    public static function isAllowedAnalyticsOnHostedScalrAccount($accountId)
    {
        if (!self::isHostedScalr()) return true;

        $accounts = self::config('scalr.hosted.analytics.managed_accounts');

        return !empty($accounts) && is_array($accounts) && in_array($accountId, $accounts) ? true : false;
    }

    /**
     * Launches server
     *
     * @param   \ServerCreateInfo       $ServerCreateInfo optional The server create info
     * @param   \DBServer               $DBServer         optional The DBServer object
     * @param   bool                    $delayed          optional
     * @param   integer|array            $reason           optional
     * @param   \Scalr_Account_User|int $user             optional The Scalr_Account_User object or its unique identifier
     * @return  DBServer|null           Returns the DBServer object on cussess or null otherwise
     */
    public static function LaunchServer(ServerCreateInfo $ServerCreateInfo = null, DBServer $DBServer = null,
                                        $delayed = false, $reason = 0, $user = null)
    {
        $db = self::getDb();
        $farm = null;

        //Ensures handling identifier of the user instead of the object
        if ($user !== null && !($user instanceof \Scalr_Account_User)) {
            try {
                $user = Scalr_Account_User::init()->loadById(intval($user));
            } catch (\Exception $e) {
            }
        }

        if (!$DBServer && $ServerCreateInfo) {
            $ServerCreateInfo->SetProperties(array(
                SERVER_PROPERTIES::SZR_KEY => Scalr::GenerateRandomKey(40),
                SERVER_PROPERTIES::SZR_KEY_TYPE => SZR_KEY_TYPE::ONE_TIME
            ));

            $DBServer = DBServer::Create($ServerCreateInfo, false, true);
        } elseif (!$DBServer && !$ServerCreateInfo) {
            // incorrect arguments
            Logger::getLogger(LOG_CATEGORY::FARM)->error(sprintf("Cannot create server"));
            return null;
        }

        $propsToSet = array();
        if ($user instanceof \Scalr_Account_User) {
            $propsToSet[SERVER_PROPERTIES::LAUNCHED_BY_ID] = $user->id;
            $propsToSet[SERVER_PROPERTIES::LAUNCHED_BY_EMAIL] = $user->getEmail();
        }

        //We should keep role_id and farm_role_id in server properties to use in cost analytics
        if (!empty($DBServer->farmRoleId)) {
            $propsToSet[SERVER_PROPERTIES::FARM_ROLE_ID] = $DBServer->farmRoleId;
            $propsToSet[SERVER_PROPERTIES::ROLE_ID] = $DBServer->farmRoleId ? $DBServer->GetFarmRoleObject()->RoleID : 0;
        }

        try {
            // Ensures the farm object will be fetched as correctly as possible
            $farm = $DBServer->farmId ? $DBServer->GetFarmObject() : null;
            $farmRole = $DBServer->farmRoleId ? $DBServer->GetFarmRoleObject() : null;

            if (!($farmRole instanceof DBFarmRole)) {
                $farmRole = null;
            } else if (!($farm instanceof DBFarm)) {
                // Gets farm through FarmRole object in this case
                $farm = $farmRole->GetFarmObject();
            }

            if ($farm instanceof DBFarm) {
                $propsToSet[SERVER_PROPERTIES::FARM_CREATED_BY_ID] = $farm->createdByUserId;
                $propsToSet[SERVER_PROPERTIES::FARM_CREATED_BY_EMAIL] = $farm->createdByUserEmail;
                $projectId = $farm->GetSetting(DBFarm::SETTING_PROJECT_ID);

                if (!empty($projectId)) {
                    try {
                        $projectEntity = ProjectEntity::findPk($projectId);

                        if ($projectEntity instanceof ProjectEntity) {
                            /* @var $projectEntity ProjectEntity */
                            $ccId = $projectEntity->ccId;
                        } else {
                            $projectId = null;
                        }
                    } catch (Exception $e) {
                        $projectId = null;
                    }
                }

                $propsToSet[SERVER_PROPERTIES::FARM_PROJECT_ID] = $projectId;
            }

            if ($farmRole instanceof DBFarmRole) {
                $propsToSet[SERVER_PROPERTIES::INFO_INSTANCE_TYPE_NAME] = $farmRole->GetSetting(DBFarmRole::SETTING_INFO_INSTANCE_TYPE_NAME);
            }

            if (!empty($ccId)) {
                $propsToSet[SERVER_PROPERTIES::ENV_CC_ID] = $ccId;
            } elseif ($DBServer->envId && (($environment = $DBServer->GetEnvironmentObject()) instanceof Scalr_Environment)) {
                $propsToSet[SERVER_PROPERTIES::ENV_CC_ID] = $environment->getPlatformConfigValue(Scalr_Environment::SETTING_CC_ID);
            }
        } catch (Exception $e) {
            Logger::getLogger(LOG_CATEGORY::FARM)->error(sprintf(
                "Could not load related object for recently created server %s. It says: %s",
                $DBServer->serverId, $e->getMessage()
            ));
        }

        if (!empty($propsToSet)) {
            $DBServer->SetProperties($propsToSet);
        }

        $fnGetReason = function ($reasonId) {
            $args = func_get_args();
            $args[0] = DBServer::getLaunchReason($reasonId);
            return [call_user_func_array('sprintf', $args), $reasonId];
        };

        if ($delayed) {
            $DBServer->status = SERVER_STATUS::PENDING_LAUNCH;
            list($reasonMsg, $reasonId) = is_array($reason) ? call_user_func_array($fnGetReason, $reason) : $fnGetReason($reason);
            $DBServer->SetProperties([
                SERVER_PROPERTIES::LAUNCH_REASON    => $reasonMsg,
                SERVER_PROPERTIES::LAUNCH_REASON_ID => $reasonId
            ]);
            $DBServer->Save();
            return $DBServer;
        }

        if ($ServerCreateInfo && $ServerCreateInfo->roleId) {
            $dbRole = DBRole::loadById($ServerCreateInfo->roleId);
            if ($dbRole->generation == 1) {
                $DBServer->status = SERVER_STATUS::PENDING_LAUNCH;
                $DBServer->Save();

                $DBServer->SetProperties([
                    SERVER_PROPERTIES::LAUNCH_ERROR => "ami-scripts servers no longer supported",
                    SERVER_PROPERTIES::LAUNCH_ATTEMPT => $DBServer->GetProperty(SERVER_PROPERTIES::LAUNCH_ATTEMPT) + 1,
                    SERVER_PROPERTIES::LAUNCH_LAST_TRY => (new DateTime())->format('Y-m-d H:i:s')
                ]);

                return $DBServer;
            }
        }

        // Limit amount of pending servers
        if ($DBServer->isOpenstack()) {
            $config = \Scalr::getContainer()->config;
            if ($config->defined("scalr.{$DBServer->platform}.pending_servers_limit")) {
                $pendingServersLimit = $config->get("scalr.{$DBServer->platform}.pending_servers_limit");
                $pendingServers = $db->GetOne("SELECT COUNT(*) FROM servers WHERE platform=? AND status=? AND server_id != ?", array(
                    $DBServer->platform, SERVER_STATUS::PENDING, $DBServer->serverId
                ));
                if ($pendingServers >= $pendingServersLimit) {
                    Logger::getLogger("SERVER_LAUNCH")->warn("{$pendingServers} servers in PENDING state on {$DBServer->platform}. Limit is: {$pendingServersLimit}. Waiting.");

                    $DBServer->status = SERVER_STATUS::PENDING_LAUNCH;
                    $DBServer->Save();

                    $DBServer->SetProperties([
                        SERVER_PROPERTIES::LAUNCH_ATTEMPT => $DBServer->GetProperty(SERVER_PROPERTIES::LAUNCH_ATTEMPT) + 1,
                        SERVER_PROPERTIES::LAUNCH_LAST_TRY => (new DateTime())->format('Y-m-d H:i:s')
                    ]);

                    return $DBServer;
                } else {
                    Logger::getLogger("SERVER_LAUNCH")->warn("{$pendingServers} servers in PENDING state on {$DBServer->platform}. Limit is: {$pendingServersLimit}. Launching server.");
                }
            }
        }

        try {
            $account = Scalr_Account::init()->loadById($DBServer->clientId);
            $account->validateLimit(Scalr_Limits::ACCOUNT_SERVERS, 1);

            PlatformFactory::NewPlatform($DBServer->platform)->LaunchServer($DBServer);

            $DBServer->status = SERVER_STATUS::PENDING;
            $DBServer->Save();

            try {
                if ($reason) {
                    list($reasonMsg, $reasonId) = is_array($reason) ? call_user_func_array($fnGetReason, $reason) : $fnGetReason($reason);
                } else {
                    $reasonMsg = $DBServer->GetProperty(SERVER_PROPERTIES::LAUNCH_REASON);
                    $reasonId = $DBServer->GetProperty(SERVER_PROPERTIES::LAUNCH_REASON_ID);
                }

                $DBServer->getServerHistory()->markAsLaunched($reasonMsg, $reasonId);
                $DBServer->updateTimelog('ts_launched');

                if ($DBServer->imageId) {
                    //Update Image last used date
                    $image = Image::findOne([['id' => $DBServer->imageId], ['envId' => $DBServer->envId], ['platform' => $DBServer->platform], ['cloudLocation' => $DBServer->cloudLocation]]);
                    if (!$image)
                        $image = Image::findOne([['id' => $DBServer->imageId], ['envId' => NULL], ['platform' => $DBServer->platform], ['cloudLocation' => $DBServer->cloudLocation]]);

                    if ($image) {
                        $image->dtLastUsed = new DateTime();
                        $image->save();
                    }

                    //Update Role last used date
                    if ($DBServer->farmRoleId) {
                        $dbRole = $DBServer->GetFarmRoleObject()->GetRoleObject();
                        $dbRole->dtLastUsed = date("Y-m-d H:i:s");
                        $dbRole->save();
                    }
                }

            } catch (Exception $e) {
                Logger::getLogger('SERVER_HISTORY')->error(sprintf("Cannot update servers history: {$e->getMessage()}"));
            }
        } catch (Exception $e) {
            Logger::getLogger(LOG_CATEGORY::FARM)->error(new FarmLogMessage($DBServer->farmId,
                sprintf("Cannot launch server on '%s' platform: %s",
                    $DBServer->platform,
                    $e->getMessage()
                )
            ));

            $existingLaunchError = $DBServer->GetProperty(SERVER_PROPERTIES::LAUNCH_ERROR);

            $DBServer->status = SERVER_STATUS::PENDING_LAUNCH;
            $DBServer->SetProperties([
                SERVER_PROPERTIES::LAUNCH_ERROR => $e->getMessage(),
                SERVER_PROPERTIES::LAUNCH_ATTEMPT => $DBServer->GetProperty(SERVER_PROPERTIES::LAUNCH_ATTEMPT) + 1,
                SERVER_PROPERTIES::LAUNCH_LAST_TRY => (new DateTime())->format('Y-m-d H:i:s')
            ]);

            $DBServer->Save();

            if ($DBServer->farmId && !$existingLaunchError)
                Scalr::FireEvent($DBServer->farmId, new InstanceLaunchFailedEvent($DBServer, $e->getMessage()));
        }

        if ($DBServer->status == SERVER_STATUS::PENDING) {
            Scalr::FireEvent($DBServer->farmId, new BeforeInstanceLaunchEvent($DBServer));
            $DBServer->SetProperty(SERVER_PROPERTIES::LAUNCH_ERROR, "");
        }

        return $DBServer;
    }

    public static function GenerateAPIKeys()
    {
        $key = Scalr::GenerateRandomKey();

        $sault = abs(crc32($key));
        $keyid = dechex($sault).dechex(time());

        $ScalrKey = $key;
        $ScalrKeyID = $keyid;

        return array("id" => $ScalrKeyID, "key" => $ScalrKey);
    }

    /**
     * Generates random key of specified length
     *
     * @param   int    $length optional The length of the key
     * @return  string Returns the random string of specified length
     */
    public static function GenerateRandomKey($length = 128)
    {
        //Is there windows os?
        if (DIRECTORY_SEPARATOR === '\\') {
            //windows os
            $rnd = '';
            $t = ceil($length / 40);
            for ($i = 0; $i < $t; ++$i) {
                $rnd .= sha1(uniqid());
            }
        } else {
            //unix os
            $rnd = file_get_contents('/dev/urandom', null, null, 0, $length);
        }

        $key = substr(base64_encode($rnd), 0, $length);

        return $key;
    }

    public static function GenerateUID($short = false, $startWithLetter = false)
    {
        $pr_bits = false;
        if (!$pr_bits) {
            if (DIRECTORY_SEPARATOR !== '\\' && ($fp = @fopen('/dev/urandom', 'rb')) !== false) {
                $pr_bits .= fread($fp, 16);
                fclose($fp);
            } else {
                // If /dev/urandom isn't available (eg: in non-unix systems), use mt_rand().
                $pr_bits = "";
                for ($cnt = 0; $cnt < 16; $cnt++) {
                    $pr_bits .= chr(mt_rand(0, 255));
                }
            }
        }
        $time_low = bin2hex(substr($pr_bits, 0, 4));
        $time_mid = bin2hex(substr($pr_bits, 4, 2));
        $time_hi_and_version = bin2hex(substr($pr_bits, 6, 2));
        $clock_seq_hi_and_reserved = bin2hex(substr($pr_bits, 8, 2));
        $node = bin2hex(substr($pr_bits, 10, 6));

        /**
         * Set the four most significant bits (bits 12 through 15) of the
         * time_hi_and_version field to the 4-bit version number from
         * Section 4.1.3.
         * @see http://tools.ietf.org/html/rfc4122#section-4.1.3
         */
        $time_hi_and_version = hexdec ( $time_hi_and_version );
        $time_hi_and_version = $time_hi_and_version >> 4;
        $time_hi_and_version = $time_hi_and_version | 0x4000;

        /**
         * Set the two most significant bits (bits 6 and 7) of the
         * clock_seq_hi_and_reserved to zero and one, respectively.
         */
        $clock_seq_hi_and_reserved = hexdec ( $clock_seq_hi_and_reserved );
        $clock_seq_hi_and_reserved = $clock_seq_hi_and_reserved >> 2;
        $clock_seq_hi_and_reserved = $clock_seq_hi_and_reserved | 0x8000;

        if ($short) return sprintf('%012s', $node);

        if ($startWithLetter) {
            if (!preg_match("/^[a-z]+[a-z0-9]*$/", $time_low)) {
                $rand_char = chr(rand(97, 102));
                $time_low = $rand_char . substr($time_low, 1);
            }
        }

        return sprintf('%08s-%04s-%04x-%04x-%012s', $time_low, $time_mid, $time_hi_and_version, $clock_seq_hi_and_reserved, $node);
    }

    /**
     * Scalr error handler
     *
     * @param   int        $errno
     * @param   string     $errstr
     * @param   string     $errfile
     * @param   int        $errline
     * @throws  \Exception
     */
    public static function errorHandler($errno, $errstr, $errfile, $errline)
    {
        // Handles error suppression.
        if (0 === error_reporting()) {
            return false;
        }

        $date = date("Y-m-d H:i:s");

        //Friendly error name
        switch ($errno) {
            case E_NOTICE:
                $errname = 'E_NOTICE';
                break;
            case E_WARNING:
                $errname = 'E_WARNING';
                break;
            case E_USER_DEPRECATED:
                $errname = 'E_USER_DEPRECATED';
                break;
            case E_STRICT:
                $errname = 'E_STRICT';
                break;
            case E_USER_NOTICE:
                $errname = 'E_USER_NOTICE';
                break;
            case E_USER_WARNING:
                $errname = 'E_USER_WARNING';
                break;
            case E_COMPILE_ERROR:
                $errname = 'E_COMPILE_ERROR';
                break;
            case E_COMPILE_WARNING:
                $errname = 'E_COMPILE_WARNING';
                break;
            case E_CORE_ERROR:
                $errname = 'E_CORE_ERROR';
                break;
            case E_CORE_WARNING:
                $errname = 'E_CORE_WARNING';
                break;
            case E_DEPRECATED:
                $errname = 'E_DEPRECATED';
                break;
            case E_ERROR:
                $errname = 'E_ERROR';
                break;
            case E_PARSE:
                $errname = 'E_PARSE';
                break;
            case E_RECOVERABLE_ERROR:
                $errname = 'E_RECOVERABLE_ERROR';
                break;
            case E_USER_ERROR:
                $errname = 'E_USER_ERROR';
                break;
            default:
                $errname = $errno;
        }

        $message = "Error {$errname} {$errstr}, in {$errfile}:{$errline}\n";

        switch ($errno) {
            case E_USER_NOTICE:
            case E_NOTICE:
                //Ignore for a while.
                break;

            case E_CORE_ERROR:
            case E_ERROR:
            case E_USER_ERROR:
                $exception = new \Exception($message, $errno);
                $message = $date . " " . $message . "Backtrace:\n " . str_replace("\n#", "\n  #", $exception->getTraceAsString()) . "\n\n";
                @error_log($message);
                throw $exception;
                break;

            case E_WARNING:
            case E_USER_WARNING:
            case E_RECOVERABLE_ERROR:
                $exception = new \Exception($message, $errno);
                $message = $message . "Backtrace:\n  " . str_replace("\n#", "\n  #", $exception->getTraceAsString()) . "\n\n";
            default:
                @error_log($date . " " . $message);
                break;
        }
    }

    /**
     * Camelizes string
     *
     * @param   string   $input  A string to camelize
     * @return  string   Returns Camelized string
     */
    public static function camelize($input)
    {
        $u = preg_replace_callback('/(_|^)([^_]+)/', function($c){
            return ucfirst(strtolower($c[2]));
        }, $input);
        return $u;
    }

    /**
     * Decamelizes a string
     *
     * @param   string    $str A string
     * @return  string    Returns decamelized string
     */
    public static function decamelize($str)
    {
        return strtolower(preg_replace_callback('/([a-z])([A-Z]+)/', function ($m) {
            return $m[1] . '_' . $m[2];
        }, $str));
    }
}
