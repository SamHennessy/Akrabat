<?php
class Akrabat_Tool_DatabaseSchemaProvider extends Zend_Tool_Project_Provider_Abstract
{
    /**
     * @var Zend_Db_Adapter_Interface
     */
    protected $_db;

    /**
     * @var string
     */
    protected $_tablePrefix;

    /**
     * Application config
     * @var Zend_Config
     */
    protected $_config;

    /**
     * Section name to load from config
     * @var string
     */
    protected $_appConfigSectionName;

    public function update($env='development', $dir='./scripts/migrations')
    {
        return $this->updateTo(null, $env, $dir);
    }

    public function updateTo($version, $env='development', $dir='./scripts/migrations')
    {
        $this->_init($env);
        $response = $this->_registry->getResponse();
        try {
            $db             = $this->_getDbAdapter();
            $manager        = new Akrabat_Db_Schema_Manager(
                $dir, $db, $this->getTablePrefix()
            );
            $startVersion   = $manager->getCurrentSchemaVersion();
            $requestVersion = $manager->processVersion($version);
            $result         = $manager->updateTo($requestVersion);

            switch ($result) {
                case Akrabat_Db_Schema_Manager::RESULT_AT_CURRENT_VERSION:
                    $response->appendContent("Already at version $requestVersion");
                    break;

                case Akrabat_Db_Schema_Manager::RESULT_NO_MIGRATIONS_FOUND :
                    $displayVersion = $version;
                    if ($displayVersion === null) {
                        $displayVersion = $requestVersion;
                    }
                    $response->appendContent(
                        "No migration files found to migrate from {$manager->getCurrentSchemaVersion()} to $displayVersion"
                    );
                    break;

                default:
                    $response->appendContent('Schema updated to version ' . $manager->getCurrentSchemaVersion());

                    if ($startVersion > $requestVersion
                        && $manager->getCurrentSchemaVersion() < $requestVersion
                    ) {
                        $response->appendContent(
                            "No migration file was found to migrate from $startVersion "
                            ."to $requestVersion so next lowest was run"
                        );
                    } else if ($manager->getCurrentSchemaVersion() != $requestVersion) {
                        $response->appendContent(
                            "No migration files found to migrate from {$manager->getCurrentSchemaVersion()} to $requestVersion"
                        );
                    }
            }

            return true;
        } catch (Exception $e) {
            $response->appendContent('AN ERROR HAS OCCURED:');
            $response->appendContent($e->getMessage());
            return false;
        }
    }

    /**
     * Run the next migration script
     *
     * @param string $env Configuration environment
     * @param string $dir The folder the migrations are in
     * @return void
     */
    public function next($env='development', $dir='./scripts/migrations')
    {
        $this->updateTo('next', $env, $dir);
    }

    /**
     * Run the previous migration script
     *
     * @param string $env Configuration environment
     * @param string $dir The folder the migrations are in
     * @return void
     */
    public function prev($env='development', $dir='./scripts/migrations')
    {
        $this->updateTo('prev', $env, $dir);
    }

    /**
     * Decrement the migration scripts by the passed number of steps
     *
     * @param string $env Configuration environment
     * @param string $dir The folder the migrations are in
     * @return void
     */
    public function dec($steps, $env='development', $dir='./scripts/migrations')
    {
        $this->updateTo('-'.$steps, $env, $dir);
    }

    /**
     * Increment the migration scripts by the passed number of steps
     *
     * @param string $env Configuration environment
     * @param string $dir The folder the migrations are in
     * @return void
     */
    public function inc($steps, $env='development', $dir='./scripts/migrations')
    {
        $this->updateTo('+'.$steps, $env, $dir);
    }

    /**
     * Provide the current schema version number
     *
     * @param string $env Configuration environment
     * @param string $dir The folder the migrations are in
     * @return void
     */
    public function current($env='development', $dir='./migrations')
    {
        $this->_init($env);
        try {

            // Initialize and retrieve DB resource
            $db = $this->_getDbAdapter();
            $manager = new Akrabat_Db_Schema_Manager($dir, $db, $this->getTablePrefix());
            echo 'Current schema version is ' . $manager->getCurrentSchemaVersion() . PHP_EOL;

            return true;
        } catch (Exception $e) {
            echo 'AN ERROR HAS OCCURED:' . PHP_EOL;
            echo $e->getMessage() . PHP_EOL;
            return false;
        }
    }

    protected function _getDirectory()
    {
        $dir = './scripts/migrations';
        return realpath($dir);
    }

    protected function _init($env)
    {
        $profile = $this->_loadProfile(self::NO_PROFILE_THROW_EXCEPTION);
        $appConfigFileResource = $profile->search('applicationConfigFile');

        if ($appConfigFileResource == false) {
            throw new Zend_Tool_Project_Exception('A project with an application config file is required to use this provider.');
        }
        $appConfigFilePath = $appConfigFileResource->getPath();

        // Base config, normally the application.ini in the configs dir of your app
        $this->_config = $this->_createConfig($appConfigFilePath, $env, true);

        // Are there any override config files?
        foreach($this->_getAppConfigOverridePathList($appConfigFilePath) as $path) {
            $overrideConfig = $this->_createConfig($path);
            if (isset($overrideConfig->$env)) {
                $this->_config->merge($overrideConfig->$env);
            }
        }

        require_once 'Zend/Loader/Autoloader.php';
        $autoloader = Zend_Loader_Autoloader::getInstance();
        $autoloader->registerNamespace('Akrabat_');
    }

    /**
     * Pull the akrabat section of the zf.ini
     *
     *  @return Zend_Config_Ini|false Fasle if not set
     */
    protected function _getUserConfig()
    {
        $userConfig = false;
        if (isset($this->_registry->getConfig()->akrabat)) {
            $userConfig = $this->_registry->getConfig()->akrabat;
        }
        return $userConfig;
    }

    /**
     * Create new Zend_Config object based on a filename
     *
     * Mostly a copy and paste from Zend_Application::_loadConfig
     *
     * @param string $filename           File to create the object from
     * @param string $section            If not null, pull this sestion of the config
     * file only. Dosn't apply to .php and .inc file
     * @param string $allowModifications Should the object be mutable or not
     * @throws Akrabat_Db_Schema_Exception
     * @return Zend_Config
     */
    protected function _createConfig($filename, $section = null, $allowModifications = false) {

        $options = false;
        if ($allowModifications) {
            $options = array('allowModifications' => true);
        }

        $suffix = pathinfo($filename, PATHINFO_EXTENSION);
        $suffix  = ($suffix === 'dist')
                 ? pathinfo(basename($filename, ".$suffix"), PATHINFO_EXTENSION)
                 : $suffix;

        switch (strtolower($suffix)) {
            case 'ini':
                $config = new Zend_Config_Ini($filename, $section, $options);
                break;

            case 'xml':
                $config = new Zend_Config_Xml($filename, $section, $options);
                break;

            case 'json':
                $config = new Zend_Config_Json($filename, $section, $options);
                break;

            case 'yaml':
            case 'yml':
                $config = new Zend_Config_Yaml($filename, $section, $options);
                break;

            case 'php':
            case 'inc':
                $config = include $filename;
                if (!is_array($config)) {
                    throw new Akrabat_Db_Schema_Exception(
                        'Invalid configuration file provided; PHP file does not return array value'
                    );
                }
                $config = new Zend_Config($config, $allowModifications);
                break;

            default:
                throw new Akrabat_Db_Schema_Exception(
                    'Invalid configuration file provided; unknown config type'
                );
        }
        return $config;
    }

    /**
     * Will pull a list of file paths to application config overrides
     *
     * There is a deliberate attempt to be very forgiving. If a file doesn’t exist,
     * it won't be included in the list. If a the file doesn’t have a section that
     * corresponds the current target environment it don't be merged.
     *
     * The config files should be standalone, they will not be able to extend
     * sections from the base config file.
     *
     * The ini, xml, json, yaml and php config file types are supported
     *
     * By default we will look for a "local.ini" in the applications configs
     * directory.
     *
     * Config files are added with an order, the order run from lowest to highest.
     * The "local.ini" file in this case will be given the order of 100
     *
     * This can be disabled with the following in your .zf.ini:
     *
     * akrabat.appConfigOverride.skipLocal = true
     *
     * You can have add to the list of file names to look for in the configs
     * directory by adding the following to the .zf.ini:
     *
     * akrabat.appConfigOverride.name = 'override.ini'
     *
     * You can only add one name with this approach and it will be added with the
     * order of 200
     *
     * To add mutiple names to be checked use the following in the .zf.ini:
     *
     * akrabat.appConfigOverride.name.60 = 'dev.ini'
     * akrabat.appConfigOverride.name.50 = 'override.ini.ini'
     *
     * Where the last part of the config key is the order to merge the files.
     *
     * To add a path to be include, do the following in your .zf.ini:
     *
     * akrabat.appConfigOverride.path = '/home/user/projects/account/configs/local.ini'
     *
     * You can only add one path with this approach and it will be added with the
     * order of 300
     *
     * To add mutiple path use the following in the .zf.ini:
     *
     * akrabat.appConfigOverride.path.1 = './application/configs/dev.ini'
     * akrabat.appConfigOverride.path.4 = '/home/user/projects/account/configs/local.ini'
     *
     * Where the last part of the config key is the order to merge the files.
     *
     * If a path is added that's order clashes with another file then the path will
     * be added the end of the queue
     *
     * @param string $appConfigFilePath
     * @return array
     */
    protected function _getAppConfigOverridePathList($appConfigFilePath)
    {
        $pathList     = array();
        $appConfigDir = dirname($appConfigFilePath);
        $userConfig   = false;

        if ($this->_getUserConfig() !== false
            && isset($this->_getUserConfig()->appConfigOverride)
        ) {
            $userConfig = $this->_getUserConfig()->appConfigOverride;
        }

        $skipLocal = false;
        if ($userConfig !== false && isset($userConfig->skipLocal)) {
            $skipLocal = (bool)$userConfig->skipLocal;
        }

        // The convention over configuration option
        if ($skipLocal === false) {
            $appConfigFilePathLocal = realpath($appConfigDir.'/local.ini');
            if ($appConfigFilePathLocal) {
                $pathList[100] = $appConfigFilePathLocal;
            }
        }

        if ($userConfig === false) {
            return $pathList;
        }

        // Look for file names in the app configs dir
        if (isset($userConfig->name)) {
            if ($userConfig->name instanceof Zend_Config) {
                $fileNameList = $userConfig->name->toArray();
            } else {
                $fileNameList = array(200 => $userConfig->name);
            }

            foreach($fileNameList as $order => $fileName) {
                $path = realpath($appConfigDir.'/'.$fileName);
                if ($path) {
                    $pathList[$order] = $appConfigDir.'/'.$fileName;
                }
            }
        }

        // A full or relative path, app dir will not be prefixed
        if (isset($userConfig->path)) {
            if ($userConfig->path instanceof Zend_Config) {
                $filePathList = $userConfig->path->toArray();
            } else {
                $filePathList = array(300 => $userConfig->path);
            }

            foreach($filePathList as $order => $filePath) {
                if (file_exists($filePath) === false) {
                    continue;
                }
                if (isset($pathList[$order])) {
                    $pathList[] = $filePath;
                } else {
                    $pathList[$order] = $filePath;
                }
            }
        }

        ksort($pathList);
        return $pathList;
    }

    /**
     * Retrieve initialized DB connection
     *
     * @return Zend_Db_Adapter_Interface
     */
    protected function _getDbAdapter()
    {
        if ((null === $this->_db)) {
            if($this->_config->resources->db){
                $dbConfig = $this->_config->resources->db;
                $this->_db = Zend_Db::factory($dbConfig->adapter, $dbConfig->params);
            } elseif($this->_config->resources->multidb){
                foreach ($this->_config->resources->multidb as $db) {
                    if($db->default){
                        $this->_db = Zend_Db::factory($db->adapter, $db);
                    }
                }
            }
            if($this->_db instanceof Zend_Db_Adapter_Interface) {
                throw new Akrabat_Db_Schema_Exception('Database was not initialized');
            }
        }
        return $this->_db;
    }

    /**
     * Retrieve table prefix
     *
     * @return string
     */
    public function getTablePrefix()
    {
        if ((null === $this->_tablePrefix)) {
            $prefix = '';
            if (isset($this->_config->resources->db->table_prefix)) {
                $prefix = $this->_config->resources->db->table_prefix . '_';
            }
            $this->_tablePrefix = $prefix;
        }
        return $this->_tablePrefix;
    }

}
