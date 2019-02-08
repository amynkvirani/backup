<?php
namespace FreePBX\modules\Backup;
use PDO;
use Exception;
/**
 * This is a base class used when creating your modules "Restore.php" class
 */
class RestoreBase extends \FreePBX\modules\Backup\Models\Restore{

	public function getAMPConf($database){
		$sql = "SELECT keyword, value FROM freepbx_settings";
		try{
			return $database->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
		} catch (Exception $e){
			return [];
		}
	}

	public function getAstDb($path){
		if(!file_exists($path)){
			return [];
		}
		$data = @unserialize(file_get_contents($path));
		if($data === false){
			return [];
		}
		return $data;
	}

	public function getUnderscoreClass($module){
		$module = ucfirst($module);
		$namespace = get_class($this->FreePBX->$module);
		return str_replace('\\','_',$namespace);
	}

	public function transformLegacyKV($pdo, $module){
		$oldkv = NULL;
		$module = ucfirst($module);
		$kvsql = "SELECT * FROM kvstore WHERE `module` = :module";
		try {
			$stmt = $pdo->prepare($kvsql);
			$stmt->execute([':module' => $module]);
			$oldkv = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			if ($e->getCode() != '42S02') {
				throw $e;
			}
		}
		(!isset($oldkv) || !is_array($oldkv)) ? : $oldkv = [];
		$this->insertKV($module, $oldkv);
		return $this;
	}

	public function transformNamespacedKV($pdo, $module){
		$module = ucfirst($module);
		$newkvsql = "SELECT * FROM " . $this->getUnderscoreClass($module);
		try {
			$stmt = $pdo->prepare($newkvsql);
			$stmt->execute();
			$newkv = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			if ($e->getCode() != '42S02') {
				throw $e;
			}
		}
		(!isset($newkv) || !is_array($newkv)) ? : $newkv = [];
		$this->insertKV($module, $newkv);
		return $this;
	}

	public function insertKV($module, $data){
		$module = ucfirst($module);
		if ($this->FreePBX->Modules->checkStatus(strtolower($module))) {
			return $this;
		}
		if (!is_null($data) ) {
			foreach ($data as $entry) {
				if ($entry['type'] === 'json-arr') {
					$entry['val'] = json_decode($entry['val'], true);
				}
				$this->FreePBX->$module->setConfig($entry['key'], $entry['val'], $entry['id']);
			}
		}
		return $this;
	}

	public function addDataDB($data){
		foreach( $data['tables'] as $table){
			try{
				$loadedTables = $data['pdo']->query("SELECT * FROM $table");
			}catch(\Exception $e){
				continue;
			}
			$results = $loadedTables->fetchAll(\PDO::FETCH_ASSOC);
			foreach ($results as $key => $value) {
				$truncate = "TRUNCATE TABLE $table";
				$this->FreePBX->Database->query($truncate);
				$first = $results[0];
				$params = $columns = array_keys($first);
				array_walk($params, function(&$v, $k) {
				$v = ':'.preg_replace("/[^a-z0-9]/i", "", $v);
				});
				$dbCdr = "asteriskcdrdb.";
				if( ($data['case'] == "insert") || (!isset($data['case'])) ){
					$sqlstm = "INSERT";
				}elseif($data['case'] == "replace"){
					$sqlstm = "REPLACE";
				}
				$sql = "$sqlstm INTO ";
				if(preg_match("/^$dbCdr/i",$table)){
					$sql .= "$table";
				}else{
					$sql .= "`$table`";
				}
				$sql .= " (`".implode('`,`',$columns)."`) VALUES (".implode(',',$params).")";
				$sth = $this->FreePBX->Database->prepare($sql);
				foreach($results as $row) {
				$insertable = [];
				foreach($row as $k => $v) {
					$k = preg_replace("/[^a-z0-9]/i", "", $k);
					$insertable[':'.$k] = $v;
				}
				$sth->execute($insertable);
				}
			}
			}
	}

	public function loadDbentries($table, $data){
		$oldData = "SELECT * FROM $table";
		$truncate = "TRUNCATE TABLE $table";
		try{
			$stmnt = $this->FreePBX->Database->query($oldData)->fetchAll(\PDO::FETCH_ASSOC);
			$before = count($stmnt);
		}catch (\Exception $e) {
			dbug("Cannot execute the after SELECT query.");
		}
		try{
			$this->FreePBX->Database->query($truncate);
		}catch (\Exception $e) {
			if ($e->getCode() != '42S02') {
				throw $e;
			}
		}
		foreach($data as $value){
			$params = $columns = array_keys($value);
			array_walk($params, function(&$v, $k) {
				$v = ':'.preg_replace("/[^a-z0-9]/i", "", $v);
			});
			foreach($value as $k => $v){
				$k = preg_replace("/[^a-z0-9]/i", "", $k);
				$insertable[':'.$k] = $v;
			}
			$dbCdr = "asteriskcdrdb.";
			$sql = "INSERT INTO ";
			if(preg_match("/^$dbCdr/i",$table)){
				$sql .= "$table";
			}else{
				$sql .= "`$table`";
			}
			$sql .= " (`".implode('`,`',$columns)."`) VALUES (".implode(',',$params).")";
			try{
				$sth = $this->FreePBX->Database->prepare($sql);
				$sth->execute($insertable);
			}catch (\Exception $e) {
				if ($e->getCode() != '42S02') {
					throw $e;
				}
			}
		}
		try {
			$sth = $this->FreePBX->Database->prepare("SELECT count(*) as total FROM $table");
			$sth->execute();
			$after = $sth->fetch(\PDO::FETCH_ASSOC);
			$infotables = "$table had $before rows, now it has {$after['total']} rows.\n";
			return $infotables;
		}catch (\Exception $e) {
			dbug("Cannot execute the before SELECT query.");
		}
	}
}
