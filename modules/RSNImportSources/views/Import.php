<?php

define('ASSIGNEDTO_ALL', '7');

define('CURRENCY_ID', 1);
define('CONVERSION_RATE', 1);

define('IMPORTCHECKER_CACHE_MAX', 1024);

class RSNImportSources_Import_View extends Vtiger_View_Controller{

	var $request;
	var $user;
	/*ED150826*/
	var $scheduledId;

	public function __construct($request = FALSE, $user = FALSE) {
		parent::__construct();
		$this->request = $request;
		$this->user = $user;
		$this->exposeMethod('showConfiguration');
	}

	/**
	 * Method to check if current user has permission to import in the concerned module.
	 * @param Vtiger_Request $request: the current request.
	 */
	function checkPermission(Vtiger_Request $request) {
		// TODO: use rsnimport permission
		$moduleName = $request->get('for_module');
		$moduleModel = Vtiger_Module_Model::getInstance($moduleName);

		$currentUserPriviligesModel = Users_Privileges_Model::getCurrentUserPrivilegesModel();
		if(!$currentUserPriviligesModel->hasModuleActionPermission($moduleModel->getId(), 'Import')) {
			throw new AppException('LBL_PERMISSION_DENIED');
		}
	}
	
	/**
	 * Method to catch request and redirect to right method.
	 * @param Vtiger_Request $request: the current request.
	 */
	function process(Vtiger_Request $request) {
		$mode = $request->getMode();
		if(!empty($mode)) {
			$this->invokeExposedMethod($mode, $request);
		}

		return;
	}

	/**
	 * Method to show the configuration template of the import for the first step.
	 *  By default it show the nothingToConfigure template.
	 *  If you want same configuration parameter, you need to overload this method in the child class.
	 * @param Vtiger_Request $request: the current request.
	 */
	function showConfiguration(Vtiger_Request $request) {
		$viewer = $this->getViewer($request);
		$viewer->assign('MODULE', $request->getModule());
		return $viewer->view('NothingToConfigure.tpl', 'RSNImportSources');
	}

	/**
	 * Method to get the modules that are concerned by the import.
	 *  By default it return only the current module.
	 * @return array - An array containing concerned module names.
	 */
	function getImportModules() {
		return array($this->request->get('for_module'));
	}

	/**
	 * Method to get the modules that are concerned by the import.
	 * Used to lock any other import in other modules than the one where data will be insterted
	 * @return array - An array containing concerned module names.
	 */
	public function getLockModules() {
		return $this->getImportModules();
	}

	/**
	 * Method to get the main module for this import.
	 * @return string - the name of the main import module.
	 */
	function getMainImportModule() {
		return $this->request->get('for_module');
	}

	/**
	 * Method to get the imported fields of a specific module.
	 *  It call the get<<Module>>Fields method (These methodes must be implemented in the child class).
	 * @param string $module: the module name.
	 * @return array - the imported fields for the specified module.
	 */
	function getFieldsFor($module) {
		$methode = "get" . ucfirst($module) . "Fields";
		if (method_exists($this, $methode)) {
			return $this->$methode();
		}

		return array();
	}

	/**
	 * Method to get the mapping of the fields for a specific module in order to retrieve them from the pre-import table.
	 * @param string $module: the module name.
	 * @return array - the fields mapping.
	 */
	function getMappingFor($module) {
		$fields = $this->getFieldsFor($module);
		$maping = array();
		for ($i = 0; $i < sizeof($fields); ++$i) {
			$maping[$fields[$i]] = $i;
		}

		return $maping;
	}

	/**
	 * Method to pre-import data in the temporary table.
	 *  By default, this method do nothing. The child class must overload this method.
	 * @return bool - false if the preimport failed.
	 */
	function preImportData() {
		return false;
	}

	/**
	 * Method to get the pre Imported data in order to preview them.
	 *  By default, it return the values in the pre-imported table.
	 *  This method can be overload in the child class.
	 * @return array - the pre-imported values group by module.
	 */
	public function getPreviewData() {
		$adb = PearDatabase::getInstance();
		$importModules = $this->getImportModules();
		$previewData = array();

		foreach($importModules as $module) {
			$previewData[$module] = array();
			$fields = $this->getFieldsFor($module);

			$tableName = RSNImportSources_Utils_Helper::getDbTableName($this->user, $module);
			$sql = 'SELECT ';

			for($i = 0; $i < sizeof($fields); ++$i) {
				if ($i != 0) {
					$sql .= ', ';
				}

				$sql .= $fields[$i];
			}

			// TODO: do not hardcode display limit ?
			$sql .= ' FROM ' . $tableName . ' WHERE status = '. RSNImportSources_Data_Action::$IMPORT_RECORD_NONE . ' LIMIT 12';
			$result = $adb->query($sql);
			$numberOfRecords = $adb->num_rows($result);

			for ($i = 0; $i < $numberOfRecords; ++$i) {
				$data = array();
				for($j = 0; $j < sizeof($fields); ++$j) {
					$data[$fields[$j]] = $adb->query_result($result, $i, $fields[$j]);
				}

				array_push($previewData[$module], $data);
			}
		}

		return $previewData;
	}

	/**
	 * Method to get the number of pre-imported records.
	 * @return int - the number of pre-imported records.
	 */
	function getNumberOfRecords() {
		$numberOfRecords = 0;
		$importModules = $this->getImportModules();

		foreach($importModules as $module) {
			$numberOfRecords += RSNImportSources_Utils_Helper::getNumberOfRecords($this->user, $module);
		}

		return $numberOfRecords;
	}

	/**
	 * Method to display the preview of the preimported data.
	 *  this method can be overload in the child class.
	 */
	function displayDataPreview() {
		$viewer = $this->getViewer($this->request);
		$moduleName = $this->request->get('for_module');
		
		//ED150826 Show rows count
		$viewer->assign('IMPORTABLE_ROWS_COUNT', $this->getNumberOfRecords());
		
		$viewer->assign('FOR_MODULE', $moduleName);
		$viewer->assign('MODULE', 'RSNImportSources');
		$viewer->assign('PREVIEW_DATA', $this->getPreviewData());
		$viewer->assign('IMPORT_SOURCE', $this->request->get('ImportSource'));
		$viewer->assign('ERROR_MESSAGE', $this->request->get('error_message'));

		return $viewer->view('ImportPreview.tpl', 'RSNImportSources');
	}

	/**
	 * Method to check if the import must be scheduled.
	 *  It schedule import if the number of pre-imported record is greater than the imediat import limit (in the config model file).
	 */
	function checkImportIsScheduled() {
		$configReader = new RSNImportSources_Config_Model();
		$immediateImportRecordLimit = $configReader->get('immediateImportLimit');

		$numberOfRecordsToImport = $this->getNumberOfRecords();

		if($numberOfRecordsToImport > $immediateImportRecordLimit) {
			$this->request->set('is_scheduled', true);
		}
	}

	/**
	 * Method to clear and re-create temporary pre-import tables.
	 */
	function clearPreImportTable() {
		$modules = $this->getImportModules();

		foreach ($modules as $module) {
			RSNImportSources_Utils_Helper::clearUserImportInfo($this->user, $module);
			RSNImportSources_Utils_Helper::createTable($this->getFieldsFor($module), $this->user, $module);
		}
	}

	/**
	 * Method to add a import in the import queue table for a specific module.
	 * @param string $module : the module name.
	 */
	public function queueDataImport($module) {
		RSNImportSources_Queue_Action::add($this->request, $this->user, $module, $this->getMappingFor($module));
	}

	/**
	 * Method to begin the import from the temporary pre-import table for a specific module.
	 * @param string $module : the module name.
	 * @param boolean $batchImport
	 */
	public function triggerImport($module, $batchImport=false) {
		$importInfo = RSNImportSources_Queue_Action::getImportInfo($module, $this->user);
		$importDataController = new RSNImportSources_Data_Action($importInfo, $this->user);

		if(!$batchImport) {
			if(!$importDataController->initializeImport()) {
				RSNImportSources_Utils_Helper::showErrorPage(vtranslate('ERR_FAILED_TO_LOCK_MODULE', 'Import'));
				exit;
			}
		}

		$this->doImport($importDataController, $module);
		RSNImportSources_Queue_Action::updateStatus($importInfo['id'], RSNImportSources_Queue_Action::$IMPORT_STATUS_HALTED);
	}

	/**
	 * Method to process to the import of a specific module.
	 *  It call the import<<Module>> method if exist. Else it call the default import method.
	 * @param RSNImportSources_Data_Action $importDataController : an instance of the import data controller.
	 * @param string $module: the module name
	 */
	public function doImport($importDataController, $module) {
		$this->updateStatus(Import_Queue_Action::$IMPORT_STATUS_RUNNING);
		try{
			$methode = "import" . ucfirst($module);
			if (method_exists($this, $methode)) {
				$this->$methode($importDataController);
			} else {
				$importDataController->importData();
			}
			$this->updateStatus(Import_Queue_Action::$IMPORT_STATUS_SCHEDULED);
		}
		catch(Exception $ex){
			$this->updateStatus(Import_Queue_Action::$IMPORT_STATUS_HALTED);
			throw ($ex);
		}
	}

	/**
	 * Method to process to the third step (the import step).
	 *  It check if the import must be scheduled. If not, it trigger the import.
	 */
	public function import() {
		$importModules = $this->getImportModules();
		$this->checkImportIsScheduled();
		$isImportScheduled = $this->request->get('is_scheduled');

		foreach($importModules as $module) {
			$this->queueDataImport($module);

			if(!$isImportScheduled) {
				$this->triggerImport($module);
			}
			//ED150829
			if($this->skipNextScheduledImports)
				break;
		}

		$importInfos = RSNImportSources_Queue_Action::getUserCurrentImportInfos($this->user);
		RSNImportSources_Import_View::showImportStatus($importInfos, $this->user, $this->request->get("for_module"));
	}

	/**
	 * Method to undo the last import for a specific user.
	 *  this method call the doUndoImport method for each module to manage.
	 * @param int $userId : the id of the user who made the import to undo.
	 */
	public function undoImport($userId) {
		global $VTIGER_BULK_SAVE_MODE;
		$user = Users_Record_Model::getInstanceById($userId, 'Users');
		$previousBulkSaveMode = $VTIGER_BULK_SAVE_MODE;
		$VTIGER_BULK_SAVE_MODE = false;
		$modules = $this->getImportModules();
		
		$viewer = new Vtiger_Viewer();
		$viewer->view('ImportHeader.tpl', 'RSNImportSources');
		
		for ($i = sizeof($modules)-1; $i >=0; --$i) {
			$noOfRecords = $this->doUndoImport($modules[$i], $user);//tmp noOfrecord !!
		}
		
		$viewer->assign('MODULE', $this->getMainImportModule());
		$viewer->view('okButton.tpl', 'RSNImportSources');
			$viewer->view('ImportFooter.tpl', 'RSNImportSources');
		
		$VTIGER_BULK_SAVE_MODE = $previousBulkSaveMode;
	}

	/**
	 * Method to undo the last import for a specific user and a specific module.
	 *  It call the undo<<Module>>Import method if exist. Else it undo import using the default way.
	 * @param string $module : the module name.
	 * @param int $userId : the id of the user who made the import to undo.
	 */
	function doUndoImport($module, $user) {
		$methode = "undo" . ucfirst($module) . "Import";
		if (method_exists($this, $methode)) {
			$this->$methode($user);
		} else {
			$db = PearDatabase::getInstance();
			$tableName = RSNImportSources_Utils_Helper::getDbTableName($user, $module);
			$query = "SELECT recordid FROM " . $tableName . " WHERE status = " . RSNImportSources_Data_Action::$IMPORT_RECORD_CREATED . " AND recordid IS NOT NULL";

			//For inventory modules
			$inventoryModules = getInventoryModules();
			if(in_array($module, $inventoryModules)){
				$query .=' GROUP BY subject';
			}

			$result = $db->pquery($query, array());

			$noOfRecords = $db->num_rows($result);
			$noOfRecordsDeleted = 0;
			$entityData = array();

			for($i=0; $i<$noOfRecords; $i++) {
				$recordId = $db->query_result($result, $i, 'recordid');
				if(isRecordExists($recordId) && isPermitted($module, 'Delete', $recordId) == 'yes') {
					$recordModel = Vtiger_Record_Model::getCleanInstance($module);
	                $recordModel->setId($recordId);
	                $recordModel->delete();
	                $focus = $recordModel->getEntity();
	                $focus->id = $recordId;
	                $entityData[] = VTEntityData::fromCRMEntity($focus);
					$noOfRecordsDeleted++;
				}
			}

			//TODO: Check for what is used commented line ???
			// $entity = new VTEventsManager($db);        
			// $entity->triggerEvent('vtiger.batchevent.delete',$entityData);
			$viewer = new Vtiger_Viewer();
			$viewer->assign('FOR_MODULE', $module);
			$viewer->assign('MODULE', 'RSNImportSources');
			$viewer->assign('TOTAL_RECORDS', $noOfRecords);
			$viewer->assign('DELETED_RECORDS_COUNT', $noOfRecordsDeleted);
			$viewer->view('ImportUndoResult.tpl', 'RSNImportSources');
		}
	}

	public function getEntryId($moduleName, $recordId) {
		$moduleHandler = vtws_getModuleHandlerFromName($moduleName, $this->user);
		$moduleMeta = $moduleHandler->getMeta();
		$moduleObjectId = $moduleMeta->getEntityId();
		$moduleFields = $moduleMeta->getModuleFields();

		return vtws_getId($moduleObjectId, $recordId);
	}

	/**
	 * Method to display the current import status for a specific user.
	 * @param $importInfos : the informations of the import.
	 * @param $user : the user.
	 * @param string $module : the main import module name.
	 */
	public static function showImportStatus($importInfos, $user, $moduleName = "") {
		if($importInfos == null || sizeof($importInfos) == 0) {
			RSNImportSources_Utils_Helper::showErrorPage(vtranslate('ERR_IMPORT_INTERRUPTED', 'RSNImportSources'));
			exit;
		}
			
		$viewer = new Vtiger_Viewer();
		
		/* ED150827 */
		$importController = self::getRecordModelByClassName($moduleName, $importInfos[0]['importsourceclass']);
		$viewer->assign('IMPORT_RECORD_MODEL', $importController);
		
		$viewer->assign('FOR_MODULE', $moduleName);
		$viewer->assign('MODULE', 'RSNImportSources');
		$viewer->assign('IMPORT_SOURCE', $importInfos[0]['importsourceclass']);
		$viewer->view('ImportHeader.tpl', 'RSNImportSources');
		$importEnded = true;

		foreach($importInfos as $importInfo) {

			$importDataController = new RSNImportSources_Data_Action($importInfo, $user);
			if($importInfo['status'] == RSNImportSources_Queue_Action::$IMPORT_STATUS_HALTED ||
					$importInfo['status'] == RSNImportSources_Queue_Action::$IMPORT_STATUS_NONE) {
				$continueImport = true;
			} else {
				$continueImport = false;
			}
			
			$importStatusCount = $importDataController->getImportStatusCount();
			$totalRecords = $importStatusCount['TOTAL'];

			if($totalRecords > ($importStatusCount['IMPORTED'] + $importStatusCount['FAILED'])) {
				$importEnded = false;
				if($importInfo['status'] == Import_Queue_Action::$IMPORT_STATUS_SCHEDULED) {
					self::showScheduledStatus($importInfo, $importStatusCount);
					continue;
				}
				self::showCurrentStatus($importInfo, $importStatusCount, $continueImport);
				continue;
			} else {
				$viewer->assign('OWNER_ID', $importInfo['user_id']);
				$importDataController->finishImport();
				self::showResult($importInfo, $importStatusCount);
				continue;
			}
		}

		if ($importEnded) {
			$viewer->view('EndedImportButtons.tpl', 'RSNImportSources');
		} else {
			$viewer->assign('IMPORT_SOURCE', $importInfos[0]['importsourceclass']);
			$viewer->view('ImportDoneButtons.tpl', 'RSNImportSources');
		}

		$viewer->view('ImportFooter.tpl', 'RSNImportSources');
	}

	/**
	 * Method called by the showImportStatus method.
	 */
	public static function showCurrentStatus($importInfo, $importStatusCount, $continueImport) {
		$moduleName = $importInfo['module'];
		$importId = $importInfo['id'];
		$viewer = new Vtiger_Viewer();

		$viewer->assign('FOR_MODULE', $moduleName);
		$viewer->assign('MODULE', 'RSNImportSources');
		$viewer->assign('IMPORT_ID', $importId);
		$viewer->assign('IMPORT_RESULT', $importStatusCount);
		$viewer->assign('IMPORT_STATUS', $importInfo['status']);
		$viewer->assign('INVENTORY_MODULES', getInventoryModules());
		$viewer->assign('CONTINUE_IMPORT', $continueImport);

		$viewer->view('ImportStatus.tpl', 'RSNImportSources');
	}

	/**
	 * Method called by the showImportStatus method.
	 */
	public static function showResult($importInfo, $importStatusCount) {
		$viewer = new Vtiger_Viewer();
        self::prepareShowResult($importInfo, $importStatusCount, $viewer);

		$viewer->view('ImportResult.tpl', 'RSNImportSources');
	}
	
	/**
	 * Method called by the showImportStatus method.
	 */
	public static function prepareShowResult($importInfo, $importStatusCount, $viewer = false) {
		$moduleName = $importInfo['module'];
		$ownerId = $importInfo['user_id'];
		if(!$viewer)
			$viewer = new Vtiger_Viewer();
		
		$viewer->assign('SKIPPED_RECORDS',$skippedRecords);
		$viewer->assign('FOR_MODULE', $moduleName);
		$viewer->assign('MODULE', 'RSNImportSources');
		$viewer->assign('OWNER_ID', $ownerId);
		$viewer->assign('IMPORT_RESULT', $importStatusCount);
		$viewer->assign('INVENTORY_MODULES', getInventoryModules());
		$viewer->assign('MERGE_ENABLED', $importInfo['merge_type']);
	}

	/**
	 * Method called by the showImportStatus method.
	 */
	public static function showScheduledStatus($importInfo, $importStatusCount = false) {
		// TODO: $importInfo['module'] should be the current main module !!
		$moduleName = $importInfo['module'];
		$importId = $importInfo['id'];

		$viewer = new Vtiger_Viewer();
		
		if($importStatusCount){
			self::prepareShowResult($importInfo, $importStatusCount, $viewer);
			$viewer->assign('RESULT_DETAILS', true);
		}			
		
		$viewer->assign('FOR_MODULE', $moduleName);
		$viewer->assign('MODULE', 'RSNImportSources');
		$viewer->assign('IMPORT_ID', $importId);
		$viewer->view('ImportSchedule.tpl', 'RSNImportSources');
	}
	
	
	static $allTaxes;
	
	static function getTax($rate){
		if(!$rate)
			return false;
		$rate = self::str_to_float($rate);
		if(!self::$allTaxes)
			self::$allTaxes = getAllTaxes();
		foreach(self::$allTaxes as $tax)
			if($tax['percentage'] == $rate)
				return $tax;
		return false;
	}
	
	/**
	 * Method that return the product id using his code.
	 * @param $productcode : the code of the product.
	 * @return int - the product id | null.
	 */
	function getProductId($productcode, &$isProduct = NULL, &$name = NULL) {
        //TODO cache
        
		$db = PearDatabase::getInstance();
		if($isProduct !== TRUE){
			$query = 'SELECT serviceid, label
				FROM vtiger_service s
				JOIN vtiger_crmentity e
					ON s.serviceid = e.crmid
				WHERE s.productcode = ?
				AND e.deleted = FALSE
				AND discontinued = 1
				LIMIT 1';
			$result = $db->pquery($query, array($productcode));
	
			if ($db->num_rows($result) == 1) {
				$row = $db->fetch_row($result, 0);
				$isProduct = false;
				$name = $row['label'];
				return $row['serviceid'];
			}
		}
		//produits
		if($isProduct !== FALSE){
			$query = 'SELECT productid, label
				FROM vtiger_products p
				JOIN vtiger_crmentity e
					ON p.productid = e.crmid
				WHERE p.productcode = ?
				AND e.deleted = FALSE
				AND discontinued = 1
				LIMIT 1';
			$result = $db->pquery($query, array($productcode));
	
			if ($db->num_rows($result) == 1) {
				$row = $db->fetch_row($result, 0);
				$isProduct = true;
				$name = $row['label'];
				return $row['productid'];
			}
		}

		return null;
	}
	
	static function str_to_float($str){
		if(!is_string($str))
			return $str;
		try {
			if(!is_numeric($str[0]) && $str[0] != '-' && $str[0] != '+')//TODO ".50"
				return false;
			return (float)str_replace(',', '.', $str);
		}
		catch(Exception $ex){
			var_dump($ex, $str);
			die("str_to_float");
		}
	}
	
	/** ABSTRACT
	 * Method that returns a formatted date for mysql (Y-m-d).
	 * @param string $string : the string to format.
	 * @return string - formated date.
	 */
	function getMySQLDate($string) {
		if($string == '00/00/00')
			return '1999-12-31';
		$dateArray = preg_split('/[-\/]/', $string);
		return ($dateArray[2].length > 2 ? '' : '20').$dateArray[2] . '-' . $dateArray[1] . '-' . $dateArray[0];
	}
	
	/**
	 * Method that returns a DateTime object
	 * @param string $string : the string to format.
	 * @return string - formated date.
	 */
	function getDateTime($string) {
		return new DateTime($this->getMySQLDate($string));
	}
	
	/**
	 * Method that retrieve a contact id.
	 * @param string $firstname : the firstname of the contact.
	 * @param string $lastname : the lastname of the contact.
	 * @param string $email : the email of the contact.
	 * @return the id of the contact | null if the contact is not found.
	 */
	function getContactId($firstname, $lastname, $email) {
		
		if($this->checkPreImportInCache('Contacts', $firstname, $lastname, $email))
			return $this->checkPreImportInCache('Contacts', $firstname, $lastname, $email);
		
		$query = "SELECT crmid
			FROM vtiger_contactdetails
                        JOIN vtiger_crmentity
                            ON vtiger_contactdetails.contactid = vtiger_crmentity.crmid
			WHERE deleted = FALSE
			AND ((UPPER(firstname) = ?
				AND UPPER(lastname) = ?)
			    OR UPPER(lastname) IN (?,?)
			)
			AND LOWER(email) = ?
			LIMIT 1
		";
		$db = PearDatabase::getInstance();
		
		$fullName = strtoupper(trim(remove_accent($lastname) . ' ' . $firstname));
		if(!$firstname)
			$fullNameReverse = implode(' ', array_reverse(explode(' ', $fullName)));
		else
			$fullNameReverse = strtoupper(trim($firstname . ' ' . remove_accent($lastname)));
		
		if(!$fullNameReverse){
			echo_callstack();
			return false;
		}
		
		$result = $db->pquery($query, array(strtoupper($firstname), strtoupper(remove_accent($lastname))
						    , $fullName
						    , $fullNameReverse
						    , strtolower($email)));

		if($db->num_rows($result)){
			$id = $db->query_result($result, 0, 0);
			
			$this->setPreImportInCache($id, 'Contacts', $firstname, $lastname, $email);
		
			return $id;
		}

		return null;
	}
		/**
	 * Method that retrieve a contact id.
	 * @param string $ref4D : the reference in 4D
	 * @return the id of the contact | null if the contact is not found.
	 */
	function getContactIdFromRef4D($ref4D) {
		
		if($this->checkPreImportInCache('Contacts', '4d', $ref4D))
			return $this->checkPreImportInCache('Contacts', '4d', $ref4D);
		
		$query = "SELECT vtiger_crmentity.crmid
			FROM vtiger_contactdetails
			JOIN vtiger_crmentity
				ON vtiger_contactdetails.contactid = vtiger_crmentity.crmid
			WHERE vtiger_crmentity.deleted = 0
			AND vtiger_contactdetails.ref4d = ?
			LIMIT 1
		";
		$db = PearDatabase::getInstance();
				
		$result = $db->pquery($query, array($ref4D));

		if($db->num_rows($result)){
			$id = $db->query_result($result, 0, 0);
			
			$this->setPreImportInCache($id, 'Contacts', '4d', $ref4D);
		
			return $id;
		}

		return null;
	}
	
	

	/**
	 * Gestion du cache
	 * Teste si les paramètres de la fonction ont déjà fait l'objet d'une recherche
	 * @param $moduleName : module de la recherche
	 * @param suivants : autant de paramètres qu'on veut. On construit un identifiant unique à partir de l'ensemble des valeurs
	 */
	var $preImportChecker_cache = array();
	
	/**
	 * Teste si les paramètres de la fonction ont déjà fait l'objet d'une recherche
	 * @param $moduleName : module de la recherche
	 * @param suivants : autant de paramètres qu'on veut. On construit un identifiant unique à partir de l'ensemble des valeurs
	 */
	public function checkPreImportInCache($moduleName, $arg1, $arg2 = false){
		$parameters = func_get_args();
		$cacheKey = implode( '|#|', $parameters);
		//var_dump('checkPreImportInCache', $cacheKey);
		if(array_key_exists( $cacheKey, $this->preImportChecker_cache)){
			return $this->preImportChecker_cache[$cacheKey];
		}
		return false;		
	}
	
	/**
	 * Complète le cache
	 * @param $value : valeur à stocker
	 * @param $moduleName : module de la recherche
	 * @param suivants : autant de paramètres qu'on veut. On construit un identifiant unique à partir de l'ensemble des valeurs
	 */
	public function setPreImportInCache($value, $moduleName, $arg1, $arg2 = false){
		$parameters = func_get_args();
		$value = array_shift( $parameters);
		$cacheKey = implode( '|#|', $parameters);
		//var_dump('setPreImportInCache', $cacheKey);
		if(!$value)
			$value = true;
		
		if(count($this->preImportChecker_cache) > IMPORTCHECKER_CACHE_MAX)
			array_splice($this->preImportChecker_cache, 0, IMPORTCHECKER_CACHE_MAX / 2);
		$this->preImportChecker_cache[$cacheKey] = $value;
		return false;		
	}

	
	public function updateStatus($status, $importId = false) {
		if(!$importId)
			$importId = $this->scheduledId;
		if($importId){
			//var_dump('updateStatus',$this->scheduledId, $status);
			RSNImportSources_Queue_Action::updateStatus($importId, $status);
		}
		else{
			//echo_callstack();
			//var_dump('updateStatus NO scheduledId ', $status);
		}
	}
	
	//ED150827
	public function getRecordModel(){
		$moduleName = $this->request->get('for_module');
		$className = $this->request->get('ImportSource');
		if($this->checkPreImportInCache($moduleName, 'getRecordModel', $className))
			return $this->checkPreImportInCache($moduleName, 'getRecordModel', $className);
		
		$recordModel = self::getRecordModelByClassName($moduleName, $className);
		if($recordModel)
			$this->setPreImportInCache($recordModel, $moduleName, 'getRecordModel', $className);
		return $recordModel;
	}
	
	public static function getRecordModelByClassName($moduleName, $className){
		global $adb;
		$query = 'SELECT vtiger_crmentity.crmid
			FROM vtiger_crmentity
			JOIN vtiger_rsnimportsources
				ON vtiger_crmentity.crmid = vtiger_rsnimportsources.rsnimportsourcesid
			WHERE vtiger_crmentity.deleted = 0
			AND vtiger_rsnimportsources.`class` = ?
			AND vtiger_rsnimportsources.disabled = 0
			'/*AND (vtiger_rsnimportsources.`modules` LIKE CONCAT(\'%\', ?, \'%\') pblm de traduction, ou de changement de traduction
				OR vtiger_rsnimportsources.`modules` LIKE CONCAT(\'%\', ?, \'%\'))
			*/.' LIMIT 1';
		$result = $adb->pquery($query, array($className/*, vtranslate($moduleName, $moduleName), $moduleName*/));
		if(!$result){
			$adb->echoError($query);
			return false;
		}
		$id = $adb->query_result($result, 0);
		/*var_dump($query, array($className, vtranslate($moduleName, $moduleName), $moduleName));
		var_dump($moduleName, $id);
		var_dump($query, array($className, $moduleName));*/
		if(!$id)
			return false;
		$recordModel = Vtiger_Record_Model::getInstanceById($id, 'RSNImportSources');
		return $recordModel;
	}
	
	//ED150827
	public function updateLastImportField(){
		$fileName = $this->request->get('import_file_name');
		if($fileName){
			$recordModel = $this->getRecordModel();
			if(!$recordModel){
				echo 'updateLastImportField : $recordModel non defini';
				return false;
			}
			$recordModel->set('mode', 'edit');
			$recordModel->set('lastimport', $fileName . ' (' . date('d/m/Y H:i:s') . ')');
			$recordModel->save();
			//echo 'updateLastImportField : lastimport = '. $fileName . ' (' . date('d/m/Y') . ')';
		}
	}
	
}

?>