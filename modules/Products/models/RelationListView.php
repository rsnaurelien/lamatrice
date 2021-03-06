<?php
/*+***********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 *************************************************************************************/

class Products_RelationListView_Model extends Vtiger_RelationListView_Model {

	/**
	 * Function to get the links for related list
	 * @return <Array> List of action models <Vtiger_Link_Model>
	 */
	public function getLinks() {
		$relationModel = $this->getRelationModel();
		$parentModel = $this->getParentRecordModel();
		
		$isSubProduct = false;
		if($parentModel->getModule()->getName() == $relationModel->getRelationModuleModel()->getName()) {
			$isSubProduct = $relationModel->isSubProduct($parentModel->getId());
		}
		
		if(!$isSubProduct){
			return parent::getLinks();
		}
	}
	
	//ED150630 buttons add for each potype
	public function getAddRelationLinks() {
		$parentRecordModel = $this->parentRecordModel;
		if($parentRecordModel && $parentRecordModel->getGestionError()){
			echo '<code>'.htmlspecialchars($parentRecordModel->getGestionError()).'</code>';
			return array();
		}
		
		$addLinkModels = parent::getAddRelationLinks();
		
		$relationModel = $this->getRelationModel();
		if($relationModel->getRelationModuleModel()->getName() === 'PurchaseOrder') {
			$addUrl = $this->getCreateViewUrl();
			foreach($addLinkModels as $index => $addLinkModel){
				if($addLinkModel->get('linkurl') == $addUrl){
					unset($addLinkModels[$index]);
					$found = true;
					break;
				}
			}
			if(isset($found)){
				foreach(array('order', 'receipt', 'invoice') as $potype){
					$newLink = clone $addLinkModel;
					$newLink->set('linklabel', vtranslate('LBL_POTYPE_' . $potype, 'PurchaseOrder'));
					$newLink->set('linkurl', $addUrl . '&potype=' . $potype);
					$addLinkModels[] = $newLink;
				}
			}
		}
		return $addLinkModels;
	}
	public function getHeaders(){
		$relationModel = $this->getRelationModel();
		$relatedModuleModel = $relationModel->getRelationModuleModel();
		
		if($relatedModuleModel->getName() === 'PriceBooks'){
			$headerFields = parent::getHeaders();
			
			//Added to support List Price
			$field = new Vtiger_Field_Model();
			$field->set('name', 'listprice');
			$field->set('column', 'listprice');
			$field->set('label', 'List Price');	
			array_push($headerFields, $field);
			
			//Added to support List Price Unit
			$field = new Vtiger_Field_Model();
			$field->set('name', 'listpriceunit');
			$field->set('column', 'listpriceunit');
			$field->set('label', '');	
			array_push($headerFields, $field);
			
			return $headerFields;
		}
		return parent::getHeaders();
	}
}
