<?php
/**
 * This code is licensed under Afterlogic Software License.
 * For full statements of the license see LICENSE file.
 */

namespace Aurora\Modules\SharedContacts;

use \Aurora\Modules\Contacts\Enums\StorageType;
use Aurora\Modules\Contacts\Models\Contact;

/**
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2019, Afterlogic Corp.
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
	protected static $iStorageOrder = 10;

	public function init()
	{
		$this->subscribeEvent('Contacts::GetStorages', array($this, 'onGetStorages'));
		$this->subscribeEvent('Contacts::PrepareFiltersFromStorage', array($this, 'prepareFiltersFromStorage'));

		$this->subscribeEvent('Contacts::UpdateSharedContacts::after', array($this, 'onAfterUpdateSharedContacts'));

		$this->subscribeEvent('Contacts::CheckAccessToObject::after', array($this, 'onAfterCheckAccessToObject'));
		$this->subscribeEvent('Contacts::GetContactSuggestions', array($this, 'onGetContactSuggestions'));
	}

	public function onGetStorages(&$aStorages)
	{
		$aStorages[self::$iStorageOrder] = StorageType::Shared;
	}

	public function prepareFiltersFromStorage(&$aArgs, &$mResult)
	{
		if (isset($aArgs['Storage']) && ($aArgs['Storage'] === StorageType::Shared || $aArgs['Storage'] === StorageType::All))
		{
			$aArgs['IsValid'] = true;

			if (!isset($mResult))
			{
				$mResult = \Aurora\Modules\Contacts\Models\Contact::query();
			}
			$oUser = \Aurora\System\Api::getAuthenticatedUser();
			$mResult = $mResult->orWhere(function($query) use ($oUser) {
				$query = $query->where('IdTenant', $oUser->IdTenant)
					->where('Storage', StorageType::Shared);
				if (isset($aArgs['SortField']) && $aArgs['SortField'] === \Aurora\Modules\Contacts\Enums\SortField::Frequency)
				{
					$query->where('Frequency', '!=', -1)
						->whereNotNull('DateModified');
				}
		    });
		}
	}

	public function onAfterUpdateSharedContacts($aArgs, &$mResult)
	{
		$oContacts = \Aurora\Modules\Contacts\Module::Decorator();
		$aUUIDs = isset($aArgs['UUIDs']) ? $aArgs['UUIDs'] : [];

		foreach ($aUUIDs as $sUUID)
		{
			$oContact = $oContacts->GetContact($sUUID, $aArgs['UserId']);
			if ($oContact instanceof Contact)
			{
				$sOldStorage = $oContact->getStorageWithId();
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

		if ($oContact instanceof \Aurora\Modules\Contacts\Models\Contact && $oContact->Storage === StorageType::Shared)
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

	public function onGetContactSuggestions(&$aArgs, &$mResult)
	{
		if ($aArgs['Storage'] === 'all' || $aArgs['Storage'] === StorageType::Shared)
		{
			$mResult[StorageType::Shared] = \Aurora\Modules\Contacts\Module::Decorator()->GetContacts(
				$aArgs['UserId'],
				StorageType::Shared,
				0,
				$aArgs['Limit'],
				$aArgs['SortField'],
				$aArgs['SortOrder'],
				$aArgs['Search']
			);
		}

	}
}
