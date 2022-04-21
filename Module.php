<?php
/**
 * This code is licensed under Afterlogic Software License.
 * For full statements of the license see LICENSE file.
 */

namespace Aurora\Modules\SharedContacts;

use Afterlogic\DAV\Constants;
use Aurora\Api;
use Aurora\Modules\Contacts\Enums\Access;
use \Aurora\Modules\Contacts\Enums\StorageType;
use Aurora\Modules\Contacts\Models\AddressBook;
use Aurora\Modules\Contacts\Models\Contact;
use Aurora\Modules\Contacts\Module as ContactsModule;
use Aurora\System\Enums\UserRole;
use Aurora\System\Exceptions\InvalidArgumentException;
use Aurora\System\Notifications;
use Illuminate\Database\Capsule\Manager as Capsule;
use Sabre\DAV\UUIDUtil;

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
		$this->subscribeEvent('Contacts::GetAddressBooks::after', array($this, 'onAfterGetAddressBooks'), 1000);
		$this->subscribeEvent('Contacts::PopulateContactModel', array($this, 'onPopulateContactModel'));
	}

	public function GetAddressbooks($UserId) 
	{
		$mResult = [];

		Api::checkUserRoleIsAtLeast(UserRole::NormalUser);
		Api::CheckAccess($UserId);

		$dBPrefix = Api::GetSettings()->DBPrefix;
		$stmt = Api::GetPDO()->prepare("
		select ab.*, sab.access, ca.Id as addressbook_id, cu.Id as UserId from " . $dBPrefix . "adav_shared_addressbooks sab 
		left join " . $dBPrefix . "adav_addressbooks ab on sab.addressbook_id = ab.id
			left join " . $dBPrefix . "core_users cu on ab.principaluri = CONCAT('principals/', cu.PublicId)
				left join " . $dBPrefix . "contacts_addressbooks ca on ca.UUID = ab.uri
					where sab.principaluri = ?
		");

		$stmt->execute([
			'principals/' . Api::getUserPublicIdById($UserId)
		]);

		$abooks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		foreach ($abooks as $abook) {

			if (isset($abook['addressbook_id'])) {
				$storage =  StorageType::Shared . '-' . $abook['UserId'] . '-' . $abook['addressbook_id'];
			} else {
				$storage =  StorageType::Shared . '-' . $abook['UserId'] . '-' . StorageType::Personal;
			}
			$prevState = Api::skipCheckUserRole(true);
			$ctag = ContactsModule::Decorator()->GetCTag($abook['UserId'], $storage);
			Api::skipCheckUserRole($prevState);

			$mResult[] = [
				'Id' => $storage,
				'EntityId' => isset($abook['addressbook_id']) ? (int) $abook['addressbook_id'] : '',
				'CTag' => $ctag,
				'Display' => true,
				'Order' => 1,
				'DisplayName' => $abook['displayname'] . ' (' . basename($abook['principaluri']) . ')',
				'Shared' => true,
				'Access' => (int) $abook['access'],
				'Owner' => basename($abook['principaluri'])
			];
		}

		return $mResult;
	}

	protected function getShareesForAddressbook($iUserId, $abookComplexId)
	{
		$dBPrefix = Api::GetSettings()->DBPrefix;
		$stmt = Api::GetPDO()->prepare("
		select * from (select sab.*, CASE WHEN ca.Id is null THEN ? ELSE CONCAT(?, ca.Id) END as storage
		from " . $dBPrefix . "adav_shared_addressbooks sab 
		left join " . $dBPrefix . "adav_addressbooks ab on sab.addressbook_id = ab.id
			left join " . $dBPrefix . "core_users cu on ab.principaluri = CONCAT('principals/', cu.PublicId)
				left join " . $dBPrefix . "contacts_addressbooks ca on ca.UUID = ab.uri
					where cu.Id = ?) as sub_select where storage = ?
		");

		$stmt->execute([
			StorageType::Personal,
			StorageType::AddressBook . '-',
			$iUserId,
			$abookComplexId
		]);

		return $stmt->fetchAll(\PDO::FETCH_ASSOC);		
	}

	protected function getShareeForAddressbook($iUserId, $abookComplexId, $principalUri)
	{
		$dBPrefix = Api::GetSettings()->DBPrefix;
		$stmt = Api::GetPDO()->prepare("
		select * from (select sab.*, CASE WHEN ca.Id is null THEN ? ELSE CONCAT(?, ca.Id) END as storage
		from " . $dBPrefix . "adav_shared_addressbooks sab 
		left join " . $dBPrefix . "adav_addressbooks ab on sab.addressbook_id = ab.id
			left join " . $dBPrefix . "core_users cu on ab.principaluri = CONCAT('principals/', cu.PublicId)
				left join " . $dBPrefix . "contacts_addressbooks ca on ca.UUID = ab.uri
					where cu.Id = ? and sab.principaluri = ?) as sub_select where storage = ?
		");

		$stmt->execute([
			StorageType::Personal,
			StorageType::AddressBook . '-',
			$iUserId,
			$principalUri,
			$abookComplexId
		]);

		return $stmt->fetch(\PDO::FETCH_ASSOC);		
	}

	protected function deleteShareeByPublicIds($userId, $abookComplexId, $publicIds)
	{
		$dBPrefix = Api::GetSettings()->DBPrefix;

		$shareesIds = [];
		foreach ($publicIds as $publicId) {
			$sharee = $this->getShareeForAddressbook($userId, $abookComplexId, 'principals/' . $publicId);
			if ($sharee) {
				$shareesIds[] = $sharee['id'];
			}
		}
		if (count($shareesIds) > 0) {
			$stmt = Api::GetPDO()->prepare("delete from " . $dBPrefix . "adav_shared_addressbooks where id in (" . \implode(',', $shareesIds) . ")");
			$stmt->execute();
		}
	}

	protected function getAddressbookByComplexId($iUserId, $abookComplexId)
	{
		$mResult = false;

		$dBPrefix = Api::GetSettings()->DBPrefix;

		$abookId = \explode('-', $abookComplexId);
		if ($abookId[0] === StorageType::Personal) {
			$addressbookUri = Constants::ADDRESSBOOK_DEFAULT_NAME;
		} else {
			$abook = AddressBook::where('UserId', $iUserId)->where('Id', $abookId[1]);
			if ($abook) {
				$addressbookUri = $abook->UUID;
			}
		}
		$userPublicId = Api::getUserPublicIdById($iUserId);
		if (!empty($addressbookUri) && $userPublicId) {
			$stmt = Api::GetPDO()->prepare("select * from " . $dBPrefix . "adav_addressbooks where principaluri = ? and uri = ?");
			$stmt->execute(['principals/' . $userPublicId, $addressbookUri]);
			$mResult = $stmt->fetch();
		}

		return $mResult;
	}

	protected function createSharee($iUserId, $abookComplexId, $shareePublicId, $access)
	{
		$dBPrefix = Api::GetSettings()->DBPrefix;
		
		$book = $this->getAddressbookByComplexId($iUserId, $abookComplexId);
		if ($book) {
			$stmt = Api::GetPDO()->prepare("insert into " . $dBPrefix . "adav_shared_addressbooks
			(principaluri, access, addressbook_id, addressbookuri)
			values (?, ?, ?, ?)");
			$stmt->execute(['principals/' . $shareePublicId, $access, $book['id'], UUIDUtil::getUUID()]);
		}
	}

	protected function updateSharee($iUserId, $abookComplexId, $shareePublicId, $access)
	{
		$dBPrefix = Api::GetSettings()->DBPrefix;
		$book = $this->getAddressbookByComplexId($iUserId, $abookComplexId);
		if ($book) {
			$stmt = Api::GetPDO()->prepare("update " . $dBPrefix . "adav_shared_addressbooks 
			set access = ? where principaluri = ? and addressbook_id = ?");
			$stmt->execute([$access, 'principals/' . $shareePublicId, $book['id']]);
		}
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
		} else {
			$storageArray = \explode('-', $aArgs['Storage']);
			if (count($storageArray) === 3 && $storageArray[0] === StorageType::Shared) {

				$aArgs['IsValid'] = true;
				$storage = $storageArray[2];

				$iAddressBookId = 0;
				if (isset($storage)) {
					if ($storage === StorageType::Personal) {
						$sStorage = StorageType::Personal;
					} else {
						$iAddressBookId = (int) $storage;
						$sStorage = StorageType::AddressBook;
					}

					$mResult = $mResult->orWhere(function($query) use ($storageArray, $sStorage, $iAddressBookId) {
						$query = $query->where('IdUser', $storageArray[1])
							->where('Storage', $sStorage);
						if ($iAddressBookId > 0) {
							$query = $query->where('AddressBookId', $iAddressBookId);
						}
						if (isset($aArgs['SortField']) && $aArgs['SortField'] === \Aurora\Modules\Contacts\Enums\SortField::Frequency)
						{
							$query->where('Frequency', '!=', -1)
								->whereNotNull('DateModified');
						}
					});
				}
			}
		}

		if (isset($aArgs['Storage']) && $aArgs['Storage'] === StorageType::All) {
			$aBooks = $this->GetAddressbooks($aArgs['UserId']);

			if (is_array($aBooks) && count($aBooks) > 0) {

				$aArgs['IsValid'] = true;

				$aWhen = [];
				foreach ($aBooks as $aBook) {
					$storageArray = \explode('-', $aBook['Id']);
					$storage = $storageArray[2];

					$iAddressBookId = 0;
					if (isset($storage)) {
						if ($storage !== StorageType::Personal) {
							$iAddressBookId = (int) $storage;
							$storage = StorageType::AddressBook;
						}

						$mResult = $mResult->orWhere(function($query) use ($storageArray, $storage, $iAddressBookId, $aArgs, $aBook, &$aWhen) {
							$query = $query->where('IdUser', $storageArray[1])
								->where('Storage', $storage);

							if ($iAddressBookId > 0) {
								$query = $query->where('AddressBookId', $iAddressBookId);
							}
							if (isset($aArgs['SortField']) && $aArgs['SortField'] === \Aurora\Modules\Contacts\Enums\SortField::Frequency)
							{
								$query->where('Frequency', '!=', -1)->whereNotNull('DateModified');
							}
							if ($iAddressBookId > 0) {
								$aWhen[] = "WHEN IdUser = ". $storageArray[1] . " AND Storage = '" . $storage . "' AND AddressBookId = " . $iAddressBookId . " THEN '" . $aBook['Id'] . "'";
							} else {
								$aWhen[] = "WHEN IdUser = ". $storageArray[1] . " AND Storage = '" . $storage . "' THEN '" . $aBook['Id'] . "'";
							}
						});
					}
				}

				$rawSql = Capsule::connection()->raw("*, CASE " . \implode("\r\n", $aWhen) . " ELSE Storage END as Storage");
				$mResult->addSelect($rawSql);
			}
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
		$Access = isset($aArgs['Access']) ? (int) $aArgs['Access'] : null;

		if ($oContact instanceof \Aurora\Modules\Contacts\Models\Contact) {
			if ($oContact->Storage === StorageType::Shared) {
				if ($oUser->Role !== \Aurora\System\Enums\UserRole::SuperAdmin && $oUser->IdTenant !== $oContact->IdTenant) {
					$mResult = false;
				} else {
					$mResult = true;
				}
			} else if ($oContact->Storage === StorageType::Personal || $oContact->Storage === StorageType::AddressBook) {
				$dBPrefix = Api::GetSettings()->DBPrefix;
				$sql = "select ab.*, sab.access, ca.Id as addressbook_id, cu.Id as UserId from " . $dBPrefix . "adav_shared_addressbooks sab 
				left join " . $dBPrefix . "adav_addressbooks ab on sab.addressbook_id = ab.id
					left join " . $dBPrefix . "core_users cu on ab.principaluri = CONCAT('principals/', cu.PublicId)
						left join " . $dBPrefix . "contacts_addressbooks ca on ca.UUID = ab.uri
							where sab.principaluri = ? and cu.Id = ?";
				if ($oContact->Storage === StorageType::AddressBook) {
					$sql .= ' and ca.Id = ' . $oContact->AddressBookId;
				} else if ($oContact->Storage === StorageType::Personal) {
					$sql .= ' and ca.Id is null';
				}

				$stmt = Api::GetPDO()->prepare($sql);
		
				$stmt->execute([
					'principals/' . $oUser->PublicId, 
					$oContact->IdUser
				]);
		
				$abook = $stmt->fetch(\PDO::FETCH_ASSOC);
				if ($abook) {
					if (isset($Access)) {
						if ($Access === (int) $abook['access'] && $Access === Access::Write) {
							$mResult = true;
						} else {
							$mResult = false;
						}
					} else {
						$mResult = true;
					}

					return true; // break other subscriptions
				}
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

	public function onAfterGetAddressBooks(&$aArgs, &$mResult)
	{
		if (!is_array($mResult)) {
			$mResult = [];
		}
		foreach ($mResult as $key => $abook) {
			$aParts = \explode('-', $abook['Id']);
			if (count($aParts) === 2 && $aParts[0] === StorageType::AddressBook) {
				$sharees = $this->getShareesForAddressbook($aArgs['UserId'], $abook['Id']);
				$mResult[$key]['Shares'] = array_map(function ($saree) {
					return [
						'PublicId' => basename($saree['principaluri']),
						'Access' => (int) $saree['access'],
					];
				}, $sharees);
			}
		}
		$mResult = array_merge(
			$mResult, 
			$this->GetAddressbooks($aArgs['UserId'])
		);
	}

	public function onPopulateContactModel(&$oContact, &$mResult)
	{
		if ($oContact instanceof Contact) {
			$aStorageParts = \explode('-', $oContact->Storage);
			if (is_array($aStorageParts) && count($aStorageParts) === 3 && $aStorageParts[0] === StorageType::Shared) {
				$abooks = $this->GetAddressbooks($oContact->IdUser);
				foreach ($abooks as $abook) {
					if ($abook['Id'] === $oContact->Storage) {
						if ($aStorageParts[2] === StorageType::Personal) {
							$oContact->Storage = StorageType::Personal;
						} else {
							$oContact->Storage = StorageType::AddressBook;
							$oContact->AddressBookId = (int) $aStorageParts[2];
						}

						$oContact->IdUser = (int) $aStorageParts[1];
						break;
					}
				}
			}
		}
	}

	public function UpdateAddressBookShare($UserId, $Id, $Shares)
	{
		$mResult = true;

		Api::checkUserRoleIsAtLeast(UserRole::NormalUser);
		Api::CheckAccess($UserId);

		if (!isset($Id) || !is_array($Shares)) {
			throw new InvalidArgumentException("", Notifications::InvalidInputParameter);
		}

		try {
			$currentABookSharees = $this->getShareesForAddressbook($UserId, $Id);

			$currentSharees = array_map(function($sharee) {
				return basename($sharee['principaluri']);
			}, $currentABookSharees);

			$newSharees = array_map(function($sharee) {
				return $sharee['PublicId'];
			}, $Shares);

			$shareesToDelete = array_diff($currentSharees, $newSharees);
			$shareesToCreate = array_diff($newSharees, $currentSharees);
			$shareesToUpdate = array_intersect($currentSharees, $newSharees);

			if (count($shareesToDelete) > 0) {
				$this->deleteShareeByPublicIds($UserId, $Id, $shareesToDelete);
			}

			foreach ($Shares as $share) {
				if (in_array($share['PublicId'], $shareesToCreate)) {
					$this->createSharee($UserId, $Id, $share['PublicId'], $share['Access']);
				}
				if (in_array($share['PublicId'], $shareesToUpdate)) {
					$this->updateSharee($UserId, $Id, $share['PublicId'], $share['Access']);
				}
			}

			$mResult = true;
		} catch (\Exception $oException) {
			Api::LogException($oException);
			$mResult = false;
		}

		return $mResult;
	}
}
