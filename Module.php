<?php
/**
 * @copyright Copyright (c) 2017, Afterlogic Corp.
 * @license AGPL-3.0 or AfterLogic Software License
 *
 * This code is licensed under AGPLv3 license or AfterLogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\SharedContacts;

/**
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractLicensedModule
{
	public function init() 
	{
		$this->subscribeEvent('Contacts::GetStorage', array($this, 'onGetStorage'));
		$this->subscribeEvent('Contacts::GetContacts::before', array($this, 'prepareFiltersFromStorage'));
		$this->subscribeEvent('Contacts::Export::before', array($this, 'prepareFiltersFromStorage'));
		$this->subscribeEvent('Contacts::GetContactsByEmails::before', array($this, 'prepareFiltersFromStorage'));
		
		$this->subscribeEvent('Contacts::UpdateSharedContacts::after', array($this, 'onAfterUpdateSharedContacts'));
	}
	
	public function onGetStorage(&$aStorages)
	{
		$aStorages[] = 'shared';
	}
	
	public function prepareFiltersFromStorage(&$aArgs, &$mResult)
	{
		if (isset($aArgs['Storage']) && ($aArgs['Storage'] === 'shared' || $aArgs['Storage'] === 'all'))
		{
			if (!isset($aArgs['Filters']) || !is_array($aArgs['Filters']))
			{
				$aArgs['Filters'] = array();
			}
			$oUser = \Aurora\System\Api::getAuthenticatedUser();
			
			$aArgs['Filters'][]['$AND'] = [
				'IdTenant' => [$oUser->IdTenant, '='],
				'Storage' => ['shared', '='],
			];
		}
	}
	
	public function onAfterUpdateSharedContacts($aArgs, &$mResult)
	{
		$oContacts = \Aurora\System\Api::GetModuleDecorator('Contacts');
		{
			$aUUIDs = isset($aArgs['UUIDs']) ? $aArgs['UUIDs'] : [];
			foreach ($aUUIDs as $sUUID)
			{
				$oContact = $oContacts->GetContact($sUUID);
				if ($oContact)
				{
					if ($oContact->Storage === 'shared')
					{
						$oContact->Storage = 'personal';
					}
					else if ($oContact->Storage === 'personal')
					{
						$oContact->Storage = 'shared';
					}
					$mResult = $oContacts->UpdateContact($oContact->toArray());
				}
			}
		}
	}
}
