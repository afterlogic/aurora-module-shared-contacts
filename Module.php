<?php
/**
 * This code is licensed under Afterlogic Software License.
 * For full statements of the license see LICENSE file.
 */

namespace Aurora\Modules\SharedContacts;

/**
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2019, Afterlogic Corp.
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractLicensedModule
{
	public function init() 
	{
		$this->subscribeEvent('Contacts::GetStorages', array($this, 'onGetStorages'));
		$this->subscribeEvent('Contacts::GetContacts::before', array($this, 'prepareFiltersFromStorage'));
		$this->subscribeEvent('Contacts::Export::before', array($this, 'prepareFiltersFromStorage'));
		$this->subscribeEvent('Contacts::GetContactsByEmails::before', array($this, 'prepareFiltersFromStorage'));
		
		$this->subscribeEvent('Contacts::UpdateSharedContacts::after', array($this, 'onAfterUpdateSharedContacts'));

		$this->subscribeEvent('Contacts::CheckAccessToObject::after', array($this, 'onAfterCheckAccessToObject'));
	}
	
	public function onGetStorages(&$aStorages)
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
				$oContact = $oContacts->GetContact($sUUID, $aArgs['UserId']);
				if ($oContact)
				{
					if ($oContact->Storage === 'shared')
					{
						$oContact->Storage = 'personal';
						$oContact->IdUser = $aArgs['UserId'];
					}
					else if ($oContact->Storage === 'personal')
					{
						$oContact->Storage = 'shared';
					}
					$mResult = $oContacts->UpdateContact($aArgs['UserId'], $oContact->toArray());
				}
			}
		}
	}

	public function onAfterCheckAccessToObject(&$aArgs, &$mResult)
	{
		$oUser = $aArgs['User'];
		$oContact = isset($aArgs['Contact']) ? $aArgs['Contact'] : null;

		if ($oContact instanceof \Aurora\Modules\Contacts\Classes\Contact && $oContact->Storage === 'shared')
		{
			if ($oUser->Role !== \Aurora\System\Enums\UserRole::SuperAdmin && $oUser->IdTenant !== $oContact->IdTenant)
			{
				$mResult = false;
			}
			else
			{
				$mResult = true;
			}
		}
	}
}
