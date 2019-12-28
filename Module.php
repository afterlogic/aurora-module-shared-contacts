<?php
/**
 * This code is licensed under Afterlogic Software License.
 * For full statements of the license see LICENSE file.
 */

namespace Aurora\Modules\SharedContacts;

use \Aurora\Modules\Contacts\Enums\StorageType;

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
		$this->subscribeEvent('Contacts::GetContactsInfo::before', array($this, 'prepareFiltersFromStorage'));
		
		$this->subscribeEvent('Contacts::UpdateSharedContacts::after', array($this, 'onAfterUpdateSharedContacts'));

		$this->subscribeEvent('Contacts::CheckAccessToObject::after', array($this, 'onAfterCheckAccessToObject'));
	}
	
	public function onGetStorages(&$aStorages)
	{
		$aStorages[] = StorageType::Shared;
	}
	
	public function prepareFiltersFromStorage(&$aArgs, &$mResult)
	{
		if (isset($aArgs['Storage']) && ($aArgs['Storage'] === StorageType::Shared || $aArgs['Storage'] === StorageType::All))
		{
			if (!isset($aArgs['Filters']) || !is_array($aArgs['Filters']))
			{
				$aArgs['Filters'] = array();
			}
			$oUser = \Aurora\System\Api::getAuthenticatedUser();
			
			$aArgs['Filters'][]['$AND'] = [
				'IdTenant' => [$oUser->IdTenant, '='],
				'Storage' => [StorageType::Shared, '='],
			];
		}
	}
	
	public function onAfterUpdateSharedContacts($aArgs, &$mResult)
	{
		$oContacts = \Aurora\Modules\Contacts\Module::Decorator();
		$aUUIDs = isset($aArgs['UUIDs']) ? $aArgs['UUIDs'] : [];

		foreach ($aUUIDs as $sUUID)
		{
			$oContact = $oContacts->GetContact($sUUID, $aArgs['UserId']);
			if ($oContact instanceof \Aurora\Modules\Contacts\Classes\Contact)
			{
				$sOldStorage = $oContact->Storage;
				$iUserId = -1;

				if ($oContact->Storage === StorageType::Shared)
				{
					$oContact->Storage = StorageType::Personal;
					$iUserId = $oContact->IdTenant;
					$oContact->IdUser = $aArgs['UserId'];
				}
				else if ($oContact->Storage === StorageType::Personal)
				{
					$oContact->Storage = StorageType::Shared;
					$iUserId = $oContact->IdUser;
				}
				// update CTag for previous storage
				\Aurora\Modules\Contacts\Module::getInstance()->getManager()->updateCTag($iUserId, $sOldStorage);					
				$mResult = $oContacts->UpdateContact($aArgs['UserId'], $oContact->toArray());
			}
		}
	}

	public function onAfterCheckAccessToObject(&$aArgs, &$mResult)
	{
		$oUser = $aArgs['User'];
		$oContact = isset($aArgs['Contact']) ? $aArgs['Contact'] : null;

		if ($oContact instanceof \Aurora\Modules\Contacts\Classes\Contact && $oContact->Storage === StorageType::Shared)
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
