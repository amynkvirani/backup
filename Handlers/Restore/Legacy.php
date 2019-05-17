<?php
/**
* Copyright Sangoma Technologies, Inc 2018
* Handle legacy backup files
*/
namespace FreePBX\modules\Backup\Handlers\Restore;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use PDO;
use FreePBX\modules\Backup\Handlers\FreePBXModule;
class Legacy extends Common {
	private $data;
	private $inMemory = true; //use in memory sqlite (much faster)

	public function process(){
		$this->extractFile();
		$this->buildData();
		$this->parseSQL();
	}

	/**
	 * Build out Manifest
	 *
	 * @return void
	 */
	private function buildData(){
		$this->data['manifest'] = [];
		$this->data['astdb'] = [];
		if(file_exists($this->tmp . '/manifest')){
			$this->log(_("Loading manifest to memory"));
			$this->data['manifest'] = unserialize(file_get_contents($this->tmp.'/manifest'));
		}
		if(file_exists($this->tmp . '/astdb')){
			$this->log(_("Loading astdb to memory"));
			$this->data['astdb'] = unserialize(file_get_contents($this->tmp.'/astdb'));
		}
	}

	/**
	 * Parse MySQL
	 *
	 * @return void
	 */
	private function parseSQL(){
		$this->log(_("Parsing out SQL tables. This may take a moment depending on backup size."));
		$tables = $this->getModuleTables();
		$files = [];
		$tableMap = ['unknown' => []];
		foreach (glob($this->tmp."/*.sql.gz") as $filename) {
			$files[] = $filename;
		}
		foreach (glob($this->tmp."/*.sql") as $filename) {
			$files[] = $filename;
		}
		$amodules = $this->freepbx->Modules->getActiveModules();
		foreach ($amodules as $key => $value) {
			$tableMap[$key] = [];
		}
		$this->log(sprintf(_("Found %s database files in the backup."),count($files)));
		foreach($files as $file){
			$this->log(sprintf(_("File named: %s"),$file));
			$dt = $this->data['manifest']['fpbx_cdrdb'];
			$scndCndtn = preg_match("/$dt/i",$file);
			if(!empty($dt) && $scndCndtn){
				//$this->processLegacyCdr($data);
				$this->log(sprintf(_("Detected file %s as legacy CDR which are not supported at this time. You will have to manually restore it"),$file));
			}else{
				$this->log(sprintf(_("Detected file %s as the PBX (Asterisk) database. Attempting restore"),$file));
				$dbh = $this->setupTempDb($file);
				$loadedTables = $dbh->query("SELECT name FROM sqlite_master WHERE type='table'");
				$versions = $dbh->query("SELECT modulename, version FROM modules")->fetchAll(\PDO::FETCH_KEY_PAIR);
				while ($current = $loadedTables->fetch(PDO::FETCH_COLUMN)) {
					if(!isset($tables[$current])){
						$tableMap['unknown'][] = $current;
						continue;
					}
					$tableMap[$tables[$current]][] = $current;
				}
				$this->processLegacyNormal($dbh, $tableMap, $versions);
			}
		}
	}

	/**
	 * Get Module Tables from XML
	 *
	 * @return array
	 */
	private function getModuleTables(){
		$amodules = $this->freepbx->Modules->getActiveModules();
		foreach ($amodules as $mod => $data) {
			$modTables = $this->parseModuleTables($mod);
			foreach ($modTables as $table) {
				$this->moduleData['tables'][$table] = $mod;
			}
		}
		return $this->moduleData['tables'];
	}

	/**
	 * Setup SQLite Tables
	 *
	 * @param string $file
	 * @return PDO
	 */
	public function setupTempDb($file){
		$info = new \SplFileInfo($file);

		if($info->getExtension() === 'gz') {
			$this->log(sprintf(_("Extracting supplied database file %s"), $info->getBasename()));
			$process = new Process(['gunzip', $file]);
			$process->mustRun();
			$extracted = $info->getPath().'/'.$info->getBasename('.' . $info->getExtension());
		} else {
			$extracted = $file;
		}

		$this->log(sprintf(_("Loading supplied database file %s"), $info->getBasename('.' . $info->getExtension())));
		$tempDB = $this->mysql2sqlite($extracted);
		$this->log(sprintf(_("%s is now loaded into memory"), $info->getBasename('.' . $info->getExtension())));

		return $tempDB;
	}

	/**
	 * Process Legacy CDR
	 *
	 * @param array $info
	 * @return void
	 */
	public function processLegacyCdr($info){
		foreach ($info['final'] as $key => $value) {
			if($key === 'cdr' || $key === 'cel' || $key === 'queuelog'){

			}else{
				continue;
			}
		}
	}

	/**
	 * Process Legacy Module
	 *
	 * @param string $module
	 * @param string $version
	 * @param PDO $dbh
	 * @param array $tables
	 * @param array $tableMap
	 * @return void
	 */
	public function processLegacyModule($module, $version, $dbh, $tables, $tableMap) {
		if(strtolower($module) === 'framework') {
			$className = 'FreePBX\Builtin\Restore';
		} else {
			$className = sprintf('\\FreePBX\\modules\\%s\\Restore', ucfirst($module));
		}
		if(!class_exists($className)) {
			$this->log(sprintf(_("The module %s does not support restores"), $module),'WARNING');
			if($module === 'framework' || !$this->defaultFallback) {
				return;
			}
			$this->log(_("Using default restore strategy"),'WARNING');
			$className = 'FreePBX\modules\Backup\RestoreBase';
		}

		$modData = [
			'module' => $module,
			'version' => $version,
			'pbx_version' => $this->data['manifest']['pbx_version'],
			'configs' => [
				'settings' => $dbh->query("SELECT `keyword`, `value` FROM freepbx_settings WHERE module = ".$dbh->quote($module))->fetchAll(PDO::FETCH_KEY_PAIR),
				'features' => $dbh->query("SELECT `featurename`, `customcode`, `enabled` FROM featurecodes WHERE modulename = ".$dbh->quote($module))->fetchAll(\PDO::FETCH_GROUP|\PDO::FETCH_ASSOC|\PDO::FETCH_UNIQUE)
			]
		];
		$class = new $className($this->freepbx, $this->backupModVer, $this->getLogger(), $this->transactionId, $modData, $this->tmp, $this->defaultFallback);
		$this->log(sprintf(_("Resetting %s"), $module));
		$class->reset();
		$this->log(sprintf(_("Restoring from %s [%s]"), $module, get_class($class)));
		$class->processLegacy($dbh, $this->data, $tables, $tableMap['unknown']);
		$this->log(_("Done"));
	}

	/**
	 * Process Legacy Normal
	 *
	 * @param PDO $dbh
	 * @param array $tableMap
	 * @param array $versions
	 * @return void
	 */
	public function processLegacyNormal($dbh, $tableMap, $versions){
		$this->data['settings'] = $dbh->query("SELECT `keyword`, `value` FROM freepbx_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
		$this->data['features'] = $dbh->query("SELECT `featurename`, `customcode`, `enabled` FROM featurecodes")->fetchAll(\PDO::FETCH_GROUP|\PDO::FETCH_ASSOC|\PDO::FETCH_UNIQUE);

		$moduleList = $tableMap;
		if(!is_null($this->specificRestores)) {
			$this->log(sprintf(_("Only Restoring %s"),implode(",",$this->specificRestores)),'WARNING');
			$moduleList = array_filter($moduleList, function($m){
				return in_array($m,$this->specificRestores);
			}, ARRAY_FILTER_USE_KEY);
		}
		foreach ($moduleList as $module => $tables) {
			if($module === 'unknown' || $module === 'cdr' || $module === 'cel' || $module === 'queuelog'){
				continue;
			}
			if($module === 'backup') {
				$this->log(_('Skipping backup'),'WARNING');
				continue;
			}
			$this->log(sprintf(_("Processing %s"),$module),'INFO');
			try {
				$this->processLegacyModule($module, $versions[$module], $dbh, $tables, $tableMap);
			} catch(\Exception $e) {
				$this->log($e->getMessage(). ' on line '.$e->getLine().' of file '.$e->getFile(),'ERROR');
				$this->log($e->getTraceAsString(),'ERROR');
				$this->addError($e->getMessage(). ' on line '.$e->getLine().' of file '.$e->getFile(),'ERROR');
				continue;
			}
			$this->log("",'INFO');
		}
	}

	/**
	 * Convert a MySQL Dump to SQLite
	 *
	 * @param string $file
	 * @param string $delimiter
	 * @return void
	 */
	private function mysql2sqlite($file, $delimiter = ';') {
		if($this->inMemory) {
			$db = new PDO('sqlite::memory:');
		} else {
			$f = pathinfo($file,PATHINFO_FILENAME);
			$dbpath = '/tmp/'.$this->transactionId.'.db';
			if(file_exists($dbpath)) {
				$this->log(sprintf(_('Utilizing cached based sqlite at %s. The data might be stale!'),$dbpath),'WARNING');
				$db = new PDO('sqlite:'.$dbpath);
				return $db;
			} else {
				$this->log(sprintf(_('Utilizing file based sqlite at %s, This is SLOW'),$dbpath),'WARNING');
				$db = new PDO('sqlite:'.$dbpath);
			}
		}

		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		set_time_limit(0);

		if (is_file($file) === true) {
			$file = fopen($file, 'r');

			if (is_resource($file) === true) {
				$query = array();

				while (feof($file) === false) {
					$query[] = fgets($file);

					if (preg_match('~' . preg_quote($delimiter, '~') . '\s*$~iS', end($query)) === 1) {
						$query = trim(implode('', $query));

						if(preg_match('/^DROP TABLE/',$query) || preg_match('/^\/\*.*\*\/;$/',$query)) {
							$query = array();
							continue;
						}

						$aInc = false;

						if(preg_match('/CREATE TABLE `([^`]*)` \(/',$query, $matches1)) {
							$oquery = $query;
							//create table
							if(preg_match('/[^"`]AUTO_INCREMENT|auto_increment[^"`]/',$query)) {
								$aInc = true;
								$query = preg_replace("/AUTO_INCREMENT|auto_increment/", "",$query);
							}

							//discard keys we dont need them for this
							$query = preg_replace('/((?:PRIMARY|UNIQUE)?\s+KEY .*),?/', "", $query);
							$query = preg_replace('/(CHARACTER SET|character set) [^ ]+[ ,]/', "",$query);
							$query = preg_replace("/(ON|on) (UPDATE|update) (CURRENT_TIMESTAMP|current_timestamp)(\(\))?/", "",$query);
							$query = preg_replace("/(DEFAULT|default) (CURRENT_TIMESTAMP|current_timestamp)(\(\))?/", "DEFAULT current_timestamp",$query);
							$query = preg_replace("/(COLLATE|collate) [a-z0-9_]*/", "" ,$query);
							$query = preg_replace("/\s+(ENUM|enum)[^)]+\)/", "text " ,$query);
							$query = preg_replace("/(SET|set)\([^)]+\)/", "text " ,$query);
							$query = preg_replace("/UNSIGNED|unsigned/", "" ,$query);
							$query = preg_replace("/` [^ ]*(INT|int|BIT|bit)[^ ]*/", "` integer" ,$query);
							$query = preg_replace('/" [^ ]*(INT|int|BIT|bit)[^ ]*/', "\" integer" ,$query);
							$ere_bit_field = "[bB]'[10]+'";
							if(preg_match('/'.$ere_bit_field.'/', $query)) {
								throw new \Exception("Ere_bit_field");
							}
							$query = preg_replace('/ (COMMENT|comment).+$/', "",$query);
							$query = preg_replace('/\)\s*ENGINE=.*;/', ');', $query);
							$query = preg_replace('/,\s*\n\);/', "\n);", $query);
							try {
								$db->query($query);
							} catch(\Exception $e) {
								print_r($e->getMessage());
								die();
							}

						} elseif(preg_match('/INSERT INTO `([^`]*)` VALUES (.*)/',$query)) {
							//$query = preg_replace('/\\\\/', "\\_", $query);

							# single quotes are escaped by another single quote
							$query = preg_replace("/\\\'/", "\\\''", $query);
							$query = preg_replace('/\\"/', "\"", $query);
							$query = preg_replace('/\\n/', "\n", $query);
							$query = preg_replace('/\\r/', "\r", $query);
							//$query = preg_replace('/\\\032/', "\032" );  # substitute char

							//$query = preg_replace('/\\_/', "\\", $query);
							//insert

							preg_match('/INSERT INTO `([^`]*)` VALUES (.*)/',$query, $matches1);

							$splits = preg_split('/(\),\()/',$matches1[2]);
							if(count($splits) > 400) {
								$offset = 0;
								$amount = 1;
								while($rows = array_slice($splits, $offset, $amount)) {
									$values = array();
									foreach($rows as $row) {
										$row = preg_replace('/(^\(|\);$)/', '', $row);
										$values[] = '('.$row.')';
									}
									$insert = 'INSERT INTO `'.$matches1[1].'` VALUES '.implode(",",$values).";";
									try {
										$db->query($insert);
									} catch(\Exception $e) {
										print_r($insert);
										print_r($e->getMessage());
										die();
									}
									$offset = $offset + $amount;
								}

							} else {
								try {
									$db->query($query);
								} catch(\Exception $e) {
									print_r($query);
									print_r($e->getMessage());
									die();
								}
							}
						}
					}

					if (is_string($query) === true) {
						$query = array();
					}
				}

				return $db;
			}
		}

		return $db;
	}

	/**
	 * Extract the module tables from module.xml
	 *
	 * @param string $module
	 * @return array
	 */
	public function parseModuleTables($module){
		$tables = [];
		$xml = $this->loadModuleXML($module);
		if (!$xml) {
			return [];
		}
		$moduleTables = $xml->database->table;
		if(!$moduleTables){
			return [];
		}
		foreach ($moduleTables as $table) {
			$tname = (string)$table->attributes()->name;
			$tables[] = $tname;
		}
		return $tables;
	}

	/**
	 * Load module XML
	 *
	 * @param string $module
	 * @return SimpleXML
	 */
	public function loadModuleXML($module){
		if($this->ModuleXML){
			return $this;
		}
		$dir = $this->freepbx->Config->get('AMPWEBROOT') . '/admin/modules/' . $module;
		if(!file_exists($dir.'/module.xml')){
			$this->moduleXML = false;
			return $this;
		}
		return simplexml_load_file($dir . '/module.xml');
	}
}