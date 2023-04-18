<?php
/**
 * This code is licensed under Afterlogic Software License.
 * For full statements of the license see LICENSE file.
 */

namespace Aurora\Modules\SharedContacts;

use Afterlogic\DAV\Constants;
use Aurora\Api;
use Aurora\Modules\Contacts\Enums\Access;
use Aurora\Modules\Contacts\Enums\SortField;
use Aurora\Modules\Contacts\Enums\StorageType;
use Aurora\Modules\Contacts\Models\AddressBook;
use Aurora\Modules\Contacts\Models\Contact;
use Aurora\Modules\Contacts\Module as ContactsModule;
use Aurora\Modules\Core\Models\Group;
use Aurora\Modules\Core\Models\User;
use Aurora\System\Enums\UserRole;
use Aurora\System\Exceptions\InvalidArgumentException;
use Aurora\System\Notifications;
use Illuminate\Database\Capsule\Manager as Capsule;
use Sabre\DAV\UUIDUtil;
use Aurora\Modules\Core\Module as CoreModule;

/**
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
    protected static $iStorageOrder = 10;

    protected $oBeforeDeleteUser = null;

    public function init()
    {
        $this->subscribeEvent('Contacts::GetStorages', array($this, 'onGetStorages'));
        $this->subscribeEvent('Contacts::PrepareFiltersFromStorage', array($this, 'prepareFiltersFromStorage'));

        $this->subscribeEvent('Contacts::UpdateSharedContacts::after', array($this, 'onAfterUpdateSharedContacts'));

        $this->subscribeEvent('Contacts::CheckAccessToObject::after', array($this, 'onAfterCheckAccessToObject'));
        $this->subscribeEvent('Contacts::GetContactSuggestions', array($this, 'onGetContactSuggestions'));
        $this->subscribeEvent('Contacts::GetAddressBooks::after', array($this, 'onAfterGetAddressBooks'), 1000);
        $this->subscribeEvent('Contacts::PopulateContactModel', array($this, 'onPopulateContactModel'));

        $this->subscribeEvent('Core::AddUsersToGroup::after', [$this, 'onAfterAddUsersToGroup']);
        $this->subscribeEvent('Core::RemoveUsersFromGroup::after', [$this, 'onAfterRemoveUsersFromGroup']);
        $this->subscribeEvent('Core::CreateUser::after', [$this, 'onAfterCreateUser']);
        $this->subscribeEvent('Core::UpdateUser::after', [$this, 'onAfterUpdateUser']);
        $this->subscribeEvent('Core::DeleteUser::before', [$this, 'onBeforeDeleteUser']);
        $this->subscribeEvent('Core::DeleteUser::after', [$this, 'onAfterDeleteUser']);
        $this->subscribeEvent('Core::DeleteGroup::after', [$this, 'onAfterDeleteGroup']);
    }

    /**
     *
     * @return Module
     */
    public static function Decorator()
    {
        return parent::Decorator();
    }

    /**
     *
     * @return Settings
     */
    protected function GetModuleSettings()
    {
        return $this->oModuleSettings;
    }

    public function GetAddressbooks($UserId)
    {
        $mResult = [];

        Api::checkUserRoleIsAtLeast(UserRole::NormalUser);
        Api::CheckAccess($UserId);

        $dBPrefix = Api::GetSettings()->DBPrefix;
        $stmt = Api::GetPDO()->prepare("
		select ab.*, sab.access, sab.group_id, ca.Id as addressbook_id, cu.Id as UserId from " . $dBPrefix . "adav_shared_addressbooks sab 
		left join " . $dBPrefix . "adav_addressbooks ab on sab.addressbook_id = ab.id
			left join " . $dBPrefix . "core_users cu on ab.principaluri = CONCAT('principals/', cu.PublicId)
				left join " . $dBPrefix . "contacts_addressbooks ca on ca.UUID = ab.uri
					where sab.principaluri = ?
		");

        $principalUri = Constants::PRINCIPALS_PREFIX . Api::getUserPublicIdById($UserId);
        $stmt->execute([
            $principalUri
        ]);

        $abooks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($abooks as $abook) {
            if ($abook['principaluri'] !== $principalUri) {
                if (isset($abook['addressbook_id'])) {
                    $storage =  StorageType::Shared . '-' . $abook['UserId'] . '-' . $abook['addressbook_id'];
                } else {
                    $storage =  StorageType::Shared . '-' . $abook['UserId'] . '-' . StorageType::Personal;
                }

                if (count($mResult) > 0) {
                    foreach ($mResult as $key => $val) {
                        if ($val['Id'] === $storage) {
                            if ($val['GroupId'] != 0) { //group sharing
                                if ($abook['access'] !== Access::Read) {
                                    if ($val['Access'] > (int) $abook['access'] || (int) $abook['access'] === Access::NoAccess) {
                                        $mResult[$key]['Access'] = (int) $abook['access'];
                                    }
                                } elseif ($val['Access'] !== Access::Write) {
                                    $mResult[$key]['Access'] = (int) $abook['access'];
                                }
                            }
                            continue 2;
                        }
                    }
                }

                $prevState = Api::skipCheckUserRole(true);
                $ctag = ContactsModule::Decorator()->GetCTag($abook['UserId'], $storage);
                Api::skipCheckUserRole($prevState);

                $mResult[] = [
                    'Id' => $storage,
                    'EntityId' => isset($abook['addressbook_id']) ? (int) $abook['addressbook_id'] : null,
                    'CTag' => $ctag,
                    'Display' => true,
                    'Order' => 1,
                    'DisplayName' => $abook['displayname'] . ' (' . basename($abook['principaluri']) . ')',
                    'Shared' => true,
                    'Access' => (int) $abook['access'],
                    'Owner' => basename($abook['principaluri']),
                    'GroupId' => (int) $abook['group_id']
                ];
            }
        }

        return array_filter($mResult, function ($item) {
            return ($item['Access'] !== Access::NoAccess);
        });
    }

    public function GetSharesForAddressbook($UserId, $Id)
    {
        Api::checkUserRoleIsAtLeast(UserRole::NormalUser);
        Api::CheckAccess($UserId);

        $aResult = [];

        $shares = $this->_getSharesForAddressbook($UserId, $Id);
        if (count($shares) > 0) {
            $oUser = Api::getUserById($UserId);
            $groups = [];
            foreach ($shares as $share) {
                if ($share['group_id'] != 0) {
                    if (!in_array($share['group_id'], $groups)) {
                        $oGroup = CoreModule::Decorator()->GetGroup($oUser->IdTenant, (int) $share['group_id']);
                        if ($oGroup) {
                            $groups[] = $share['group_id'];
                            $aResult[] = [
                                'PublicId' => $oGroup->getName(),
                                'Access' => (int) $share['access'],
                                'IsGroup' => true,
                                'IsAll' => !!$oGroup->IsAll,
                                'GroupId' => (int) $share['group_id']
                            ];
                        }
                    }
                } else {
                    $aResult[] = [
                        'PublicId' => basename($share['principaluri']),
                        'Access' => (int) $share['access']
                    ];
                }
            }
        }

        return $aResult;
    }

    protected function _getSharesForAddressbook($iUserId, $abookComplexId)
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

    protected function getShareForAddressbook($iUserId, $abookComplexId, $principalUri, $groupId = 0)
    {
        $dBPrefix = Api::GetSettings()->DBPrefix;
        $stmt = Api::GetPDO()->prepare("
		select * from (select sab.*, CASE WHEN ca.Id is null THEN ? ELSE CONCAT(?, ca.Id) END as storage
		from " . $dBPrefix . "adav_shared_addressbooks sab 
		left join " . $dBPrefix . "adav_addressbooks ab on sab.addressbook_id = ab.id
			left join " . $dBPrefix . "core_users cu on ab.principaluri = CONCAT('principals/', cu.PublicId)
				left join " . $dBPrefix . "contacts_addressbooks ca on ca.UUID = ab.uri
					where cu.Id = ? and sab.principaluri = ? and sab.group_id = ?) as sub_select where storage = ?
		");

        $stmt->execute([
            StorageType::Personal,
            StorageType::AddressBook . '-',
            $iUserId,
            $principalUri,
            $groupId,
            $abookComplexId
        ]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    protected function deleteShareByPublicIds($userId, $abookComplexId, $publicIds)
    {
        $dBPrefix = Api::GetSettings()->DBPrefix;

        $sharesIds = [];
        foreach ($publicIds as $publicId) {
            $publicId = \json_decode($publicId);
            $share = $this->getShareForAddressbook($userId, $abookComplexId, Constants::PRINCIPALS_PREFIX . $publicId[0], $publicId[1]);
            if ($share) {
                $sharesIds[] = $share['id'];
            }
        }
        if (count($sharesIds) > 0) {
            $stmt = Api::GetPDO()->prepare("delete from " . $dBPrefix . "adav_shared_addressbooks where id in (" . \implode(',', $sharesIds) . ")");
            $stmt->execute();
        }
    }

    protected function getAddressbookByComplexId($iUserId, $abookComplexId)
    {
        $mResult = false;

        $dBPrefix = Api::GetSettings()->DBPrefix;

        $abookId = \explode('-', $abookComplexId);

        if (count($abookId) === 1 && $abookId[0] === StorageType::Personal) {
            $abookId[] = StorageType::Personal;
        }

        if (count($abookId) > 1) {
            if (count($abookId) < 3) {
                $abookId[2] = $abookId[1];
            }

            $iUserId  = $abookId[0] === StorageType::Shared ? $abookId[1] : $iUserId;
            if ($abookId[2] === StorageType::Personal) {
                $addressbookUri = Constants::ADDRESSBOOK_DEFAULT_NAME;
            } else {
                $abook = AddressBook::where('UserId', $iUserId)->where('Id', $abookId[2])->first();
                if ($abook) {
                    $addressbookUri = $abook->UUID;
                }
            }
            $userPublicId = Api::getUserPublicIdById($iUserId);
            if (!empty($addressbookUri) && $userPublicId) {
                $stmt = Api::GetPDO()->prepare("select * from " . $dBPrefix . "adav_addressbooks where principaluri = ? and uri = ?");
                $stmt->execute([Constants::PRINCIPALS_PREFIX . $userPublicId, $addressbookUri]);
                $mResult = $stmt->fetch(\PDO::FETCH_ASSOC);
            }
        }

        return $mResult;
    }

    protected function createShare($iUserId, $abookComplexId, $share)
    {
        $dBPrefix = Api::GetSettings()->DBPrefix;

        $book = $this->getAddressbookByComplexId($iUserId, $abookComplexId);
        if ($book) {
            $shareePublicId = $share['PublicId'];
            $access = $share['Access'];
            $groupId = $share['GroupId'];
            $stmt = Api::GetPDO()->prepare("insert into " . $dBPrefix . "adav_shared_addressbooks
			(principaluri, access, addressbook_id, addressbookuri, group_id)
			values (?, ?, ?, ?, ?)");
            $stmt->execute([Constants::PRINCIPALS_PREFIX . $shareePublicId, $access, $book['id'], UUIDUtil::getUUID(), $groupId]);
        }
    }

    protected function updateShare($iUserId, $abookComplexId, $share)
    {
        $dBPrefix = Api::GetSettings()->DBPrefix;
        $book = $this->getAddressbookByComplexId($iUserId, $abookComplexId);
        if ($book) {
            $shareePublicId = $share['PublicId'];
            $access = $share['Access'];
            $groupId = $share['GroupId'];
            $stmt = Api::GetPDO()->prepare("update " . $dBPrefix . "adav_shared_addressbooks 
			set access = ? where principaluri = ? and addressbook_id = ? and group_id = ?");
            $stmt->execute([$access, Constants::PRINCIPALS_PREFIX . $shareePublicId, $book['id'], $groupId]);
        }
    }

    public function onGetStorages(&$aStorages)
    {
        $aStorages[self::$iStorageOrder] = StorageType::Shared;
    }

    public function prepareFiltersFromStorage(&$aArgs, &$mResult)
    {
        if (!isset($mResult)) {
            $mResult = \Aurora\Modules\Contacts\Models\Contact::query();
        }
        if (isset($aArgs['Storage']) && ($aArgs['Storage'] === StorageType::Shared || $aArgs['Storage'] === StorageType::All)) {
            $aArgs['IsValid'] = true;

            $oUser = \Aurora\System\Api::getAuthenticatedUser();
            $mResult = $mResult->orWhere(function ($query) use ($oUser) {
                $query = $query->where('IdTenant', $oUser->IdTenant)
                    ->where('Storage', StorageType::Shared)
                    ->where(
                        function ($query) {
                            $query->where('Auto', false)->orWhereNull('Auto');
                        }
                    );

                // if (isset($aArgs['SortField']) && $aArgs['SortField'] === SortField::Frequency) {
                //     $query->whereNotNull('DateModified');
                // }
            });
        } else {
            $storageArray = \explode('-', $aArgs['Storage']);
            if (count($storageArray) === 3 && $storageArray[0] === StorageType::Shared) {
                $aArgs['IsValid'] = true;
                $storage = $storageArray[2];

                $iAddressBookId = 0;
                if ($storage) {
                    if ($storage === StorageType::Personal) {
                        $sStorage = StorageType::Personal;
                    } else {
                        $iAddressBookId = (int) $storage;
                        $sStorage = StorageType::AddressBook;
                    }

                    $mResult = $mResult->orWhere(function ($query) use ($storageArray, $sStorage, $iAddressBookId) {
                        $query = $query->where('IdUser', $storageArray[1])
                            ->where('Storage', $sStorage)
                            ->where(
                                function ($query) {
                                    $query->where('Auto', false)->orWhereNull('Auto');
                                }
                            );

                        if ($iAddressBookId > 0) {
                            $query = $query->where('AddressBookId', $iAddressBookId);
                        }
                        // if (isset($aArgs['SortField']) && $aArgs['SortField'] === SortField::Frequency) {
                        //     $query->whereNotNull('DateModified');
                        // }
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
                    if ($storage) {
                        if ($storage !== StorageType::Personal) {
                            $iAddressBookId = (int) $storage;
                            $storage = StorageType::AddressBook;
                        }

                        $mResult = $mResult->orWhere(function ($query) use ($storageArray, $storage, $iAddressBookId, $aBook, &$aWhen) {
                            $query = $query->where('IdUser', $storageArray[1])
                                ->where('Storage', $storage)
                                ->where(
                                    function ($query) {
                                        $query->where('Auto', false)->orWhereNull('Auto');
                                    }
                                );

                            if ($iAddressBookId > 0) {
                                $query = $query->where('AddressBookId', $iAddressBookId);
                            }
                            // if (isset($aArgs['SortField']) && $aArgs['SortField'] === SortField::Frequency) {
                            //     $query->whereNotNull('DateModified');
                            // }
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

        foreach ($aUUIDs as $sUUID) {
            $oContact = $oContacts->GetContact($sUUID, $aArgs['UserId']);
            if ($oContact instanceof Contact) {
                $sOldStorage = $oContact->getStorageWithId();
                $iUserId = -1;

                if ($oContact->Storage === StorageType::Shared) {
                    $oContact->Storage = StorageType::Personal;
                    $iUserId = $oContact->IdTenant;
                    $oContact->IdUser = $aArgs['UserId'];
                } elseif ($oContact->Storage === StorageType::Personal) {
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
            if ($oContact->IdUser === $oUser->Id) {
                $mResult = true;
                return true; // break other subscriptions
            }
            if ($oContact->Storage === StorageType::Shared) {
                if ($oUser->Role !== \Aurora\System\Enums\UserRole::SuperAdmin && $oUser->IdTenant !== $oContact->IdTenant) {
                    $mResult = false;
                } else {
                    $mResult = true;
                }
            } elseif ($oContact->Storage === StorageType::Personal || $oContact->Storage === StorageType::AddressBook) {
                $dBPrefix = Api::GetSettings()->DBPrefix;
                $sql = "select ab.*, sab.access, ca.Id as addressbook_id, cu.Id as UserId from " . $dBPrefix . "adav_shared_addressbooks sab 
				left join " . $dBPrefix . "adav_addressbooks ab on sab.addressbook_id = ab.id
					left join " . $dBPrefix . "core_users cu on ab.principaluri = CONCAT('principals/', cu.PublicId)
						left join " . $dBPrefix . "contacts_addressbooks ca on ca.UUID = ab.uri
							where sab.principaluri = ? and cu.Id = ?";
                if ($oContact->Storage === StorageType::AddressBook) {
                    $sql .= ' and ca.Id = ' . $oContact->AddressBookId;
                } elseif ($oContact->Storage === StorageType::Personal) {
                    $sql .= ' and ca.Id is null';
                }

                $stmt = Api::GetPDO()->prepare($sql);

                $stmt->execute([
                    Constants::PRINCIPALS_PREFIX . $oUser->PublicId,
                    $oContact->IdUser
                ]);

                $abook = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($abook) {
                    if ((int) $abook['access'] === Access::NoAccess) {
                        $mResult = false;
                    } elseif (isset($Access)) {
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
        if ($aArgs['Storage'] === 'all' || $aArgs['Storage'] === StorageType::Shared) {
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
            $mResult[$key]['Shares'] = self::Decorator()->GetSharesForAddressbook($aArgs['UserId'], $abook['Id']);
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
            $oUser = Api::getUserById($UserId);
            $currentABookShares = $this->_getSharesForAddressbook($UserId, $Id);

            $newABookShares = [];
            foreach ($Shares as $share) {
                if (isset($share['GroupId'])) {
                    $aUsers = CoreModule::Decorator()->GetGroupUsers($oUser->IdTenant, (int) $share['GroupId']);
                    foreach ($aUsers as $aUser) {
                        $newABookShares[] = [
                            'PublicId' => $aUser['PublicId'],
                            'Access' => (int) $share['Access'],
                            'GroupId' => (int) $share['GroupId'],
                        ];
                    }
                } else {
                    $share['GroupId'] = 0;
                    $newABookShares[] = $share;
                }
            }

            $currentShares = array_map(function ($share) {
                return \json_encode([
                    basename($share['principaluri']),
                    $share['group_id']
                ]);
            }, $currentABookShares);

            $newShares = array_map(function ($share) {
                return \json_encode([
                    $share['PublicId'],
                    $share['GroupId']
                ]);
            }, $newABookShares);

            $sharesToDelete = array_diff($currentShares, $newShares);
            $sharesToCreate = array_diff($newShares, $currentShares);
            $sharesToUpdate = array_intersect($currentShares, $newShares);

            if (count($sharesToDelete) > 0) {
                $this->deleteShareByPublicIds($UserId, $Id, $sharesToDelete);
            }

            foreach ($newABookShares as $share) {
                $sharePublicIdAndGroupId = \json_encode([
                    $share['PublicId'],
                    $share['GroupId']
                ]);
                if (in_array($sharePublicIdAndGroupId, $sharesToCreate)) {
                    $this->createShare($UserId, $Id, $share);
                }
                if (in_array($sharePublicIdAndGroupId, $sharesToUpdate)) {
                    $this->updateShare($UserId, $Id, $share);
                }
            }

            $mResult = true;
        } catch (\Exception $oException) {
            Api::LogException($oException);
            $mResult = false;
        }

        return $mResult;
    }

    public function LeaveShare($UserId, $Id)
    {
        $mResult = false;
        Api::checkUserRoleIsAtLeast(UserRole::NormalUser);
        Api::CheckAccess($UserId);

        $abook = $this->getAddressbookByComplexId($UserId, $Id);

        if ($abook) {
            $principalUri = Constants::PRINCIPALS_PREFIX . Api::getUserPublicIdById($UserId);
            $dBPrefix = Api::GetSettings()->DBPrefix;

            $stmt = Api::GetPDO()->prepare("select count(*) from " . $dBPrefix . "adav_shared_addressbooks 
			where principaluri = ? and addressbook_id = ? and group_id = 0");
            $stmt->execute([$principalUri, $abook['id']]);
            $cnt = $stmt->fetch();

            if ((int) $cnt[0] > 0) { //persona sharing
                $stmt = Api::GetPDO()->prepare("update " . $dBPrefix . "adav_shared_addressbooks
				set access = ?
				where principaluri = ? and addressbook_id = ? and group_id = 0");
                $mResult = $stmt->execute([Access::NoAccess, $principalUri, $abook['id']]);
            } else {
                $stmt = Api::GetPDO()->prepare("insert into " . $dBPrefix . "adav_shared_addressbooks
				(principaluri, access, addressbook_id, addressbookuri, group_id)
				values (?, ?, ?, ?, ?)");
                $mResult = $stmt->execute([$principalUri, Access::NoAccess, $abook['id'], UUIDUtil::getUUID(), 0]);
            }
        }

        return $mResult;
    }

    public function onAfterDeleteGroup($aArgs, &$mResult)
    {
        if ($mResult) {
            $dBPrefix = Api::GetSettings()->DBPrefix;
            $stmt = Api::GetPDO()->prepare("delete from " . $dBPrefix . "adav_shared_addressbooks where group_id = ?");
            $stmt->execute([$aArgs['GroupId']]);
        }
    }

    /**
     * @ignore
     * @param array $aArgs Arguments of event.
     * @param mixed $mResult Is passed by reference.
     */
    public function onBeforeDeleteUser($aArgs, &$mResult)
    {
        if (isset($aArgs['UserId'])) {
            $this->oBeforeDeleteUser = Api::getUserById($aArgs['UserId']);
        }
    }

        /**
     * @ignore
     * @param array $aArgs Arguments of event.
     * @param mixed $mResult Is passed by reference.
     */
    public function onAfterDeleteUser($aArgs, $mResult)
    {
        if ($mResult && $this->oBeforeDeleteUser instanceof User) {
            $dBPrefix = Api::GetSettings()->DBPrefix;
            $stmt = Api::GetPDO()->prepare("delete from " . $dBPrefix . "adav_shared_addressbooks where principaluri = ?");
            $stmt->execute([Constants::PRINCIPALS_PREFIX . $this->oBeforeDeleteUser->PublicId]);
        }
    }

    public function onAfterAddUsersToGroup($aArgs, &$mResult)
    {
        if ($mResult) {
            foreach ($aArgs['UserIds'] as $iUserId) {
                $userPublicId = Api::getUserPublicIdById($iUserId);
                $sUserPrincipalUri = Constants::PRINCIPALS_PREFIX . $userPublicId;

                $dBPrefix = Api::GetSettings()->DBPrefix;
                $stmt = Api::GetPDO()->prepare("select distinct addressbook_id, access from " . $dBPrefix . "adav_shared_addressbooks where group_id = ?");
                $stmt->execute([$aArgs['GroupId']]);
                $shares = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($shares as $share) {
                    if (is_array($share)) {
                        $stmt = Api::GetPDO()->prepare("insert into " . $dBPrefix . "adav_shared_addressbooks
						(principaluri, access, addressbook_id, addressbookuri, group_id)
						values (?, ?, ?, ?, ?)");
                        $stmt->execute([$sUserPrincipalUri, $share['access'], $share['addressbook_id'], UUIDUtil::getUUID(), $aArgs['GroupId']]);
                    }
                }
            }
        }
    }

    public function onAfterCreateUser($aArgs, &$mResult)
    {
        if ($mResult) {
            $oUser = User::find($mResult);
            if ($oUser) {
                $oGroup = CoreModule::getInstance()->GetAllGroup($oUser->IdTenant);
                if ($oGroup) {
                    $newArgs = [
                        'GroupId' => $oGroup->Id,
                        'UserIds' => [$mResult]
                    ];
                    $newResult = true;
                    $this->onAfterAddUsersToGroup($newArgs, $newResult);
                }
            }
        }
    }

    public function onAfterUpdateUser($aArgs, &$mResult)
    {
        if ($mResult) {
            $groupIds = $aArgs['GroupIds'];
            $userId = $aArgs['UserId'];

            if ($groupIds !== null) {
                $userPublicId = Api::getUserPublicIdById($userId);
                $sUserPrincipalUri = Constants::PRINCIPALS_PREFIX . $userPublicId;

                $dBPrefix = Api::GetSettings()->DBPrefix;
                $stmt = Api::GetPDO()->prepare("select * from " . $dBPrefix . "adav_shared_addressbooks where group_id <> 0 and principaluri = ?");
                $stmt->execute([$sUserPrincipalUri]);
                $shares = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                $currentGroupsIds = [];
                if (is_array($shares)) {
                    $currentGroupsIds = array_map(function ($share) {
                        return $share['group_id'];
                    }, $shares);
                }

                $groupsIdsToDelete = array_diff($currentGroupsIds, $groupIds);
                $groupsIdsToCreate = array_diff($groupIds, $currentGroupsIds);

                if (count($groupsIdsToDelete) > 0) {
                    $stmt = Api::GetPDO()->prepare("delete from " . $dBPrefix . "adav_shared_addressbooks 
					where group_id in (" . \implode(',', $groupsIdsToDelete) . ") and principaluri = ?");
                    $stmt->execute([$sUserPrincipalUri]);
                }

                if (count($groupsIdsToCreate) > 0) {
                    $stmt = Api::GetPDO()->prepare("select distinct addressbook_id, access, group_id from " . $dBPrefix . "adav_shared_addressbooks where group_id in (" . \implode(',', $groupsIdsToCreate) . ")");
                    $stmt->execute();
                    $shares = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    foreach ($shares as $share) {
                        if (is_array($share)) {
                            $stmt = Api::GetPDO()->prepare("insert into " . $dBPrefix . "adav_shared_addressbooks
							(principaluri, access, addressbook_id, addressbookuri, group_id)
							values (?, ?, ?, ?, ?)");
                            $stmt->execute([$sUserPrincipalUri, $share['access'], $share['addressbook_id'], UUIDUtil::getUUID(), $share['group_id']]);
                        }
                    }
                }
            }
        }
    }

    public function onAfterRemoveUsersFromGroup($aArgs, &$mResult)
    {
        if ($mResult) {
            $principals = [];
            foreach ($aArgs['UserIds'] as $iUserId) {
                $oUser = Api::getUserById($iUserId);
                $principals[] = Constants::PRINCIPALS_PREFIX . $oUser->PublicId;
            }

            if (count($principals) > 0) {
                $dBPrefix = Api::GetSettings()->DBPrefix;
                $stmt = Api::GetPDO()->prepare("delete from " . $dBPrefix . "adav_shared_addressbooks where principaluri in (" . \implode(',', $principals) . ") and group_id = ?");
                $stmt->execute([$aArgs['GroupId']]);
            }
        }
    }
}
