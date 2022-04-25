'use strict';

var
	_ = require('underscore'),
	ko = require('knockout'),

	TextUtils = require('%PathToCoreWebclientModule%/js/utils/Text.js'),
	Types = require('%PathToCoreWebclientModule%/js/utils/Types.js'),

	Ajax = require('%PathToCoreWebclientModule%/js/Ajax.js'),
	AlertPopup = require('%PathToCoreWebclientModule%/js/popups/AlertPopup.js'),
	Api = require('%PathToCoreWebclientModule%/js/Api.js'),
	App = require('%PathToCoreWebclientModule%/js/App.js'),
	CAbstractPopup = require('%PathToCoreWebclientModule%/js/popups/CAbstractPopup.js'),
	ConfirmPopup = require('%PathToCoreWebclientModule%/js/popups/ConfirmPopup.js'),
	ModulesManager = require('%PathToCoreWebclientModule%/js/ModulesManager.js'),
	Popups = require('%PathToCoreWebclientModule%/js/Popups.js'),
	Screens = require('%PathToCoreWebclientModule%/js/Screens.js'),

	CAddressbookShareModel = require('modules/%ModuleName%/js/models/CAddressbookShareModel.js')
;

/**
 * @constructor
 */
function CAddressbookSharePopup()
{
	CAbstractPopup.call(this);

	this.addressbook = null;
	this.accessList = ko.computed(function () {
		return [
			{ value: Enums.SharedAddressbookAccess.Read, label: TextUtils.i18n('%MODULENAME%/LABEL_READ_ACCESS') },
			{ value: Enums.SharedAddressbookAccess.Write, label: TextUtils.i18n('%MODULENAME%/LABEL_WRITE_ACCESS') },
			{ value: Enums.SharedAddressbookAccess.NoAccess, label: TextUtils.i18n('%MODULENAME%/LABEL_NOSHARE_ACCESS') }
		];
	}, this);

	this.shares = ko.observableArray([]);
	this.sharesScrollAreaDom = ko.observable(null);

	this.selectedTeammateDom = ko.observable(null);
	this.selectedTeammateDom.subscribe(function () {
		this.selectedTeammateDom().on('click', function() {
			if (this.selectedTeammateEmail() !== '') {
				if (!$(this.selectedTeammateDom().autocomplete('widget')).is(':visible')) {
					this.selectedTeammateDom().autocomplete('search');
				}
			}
		}.bind(this));
	}, this);
	this.selectedTeammateEmail = ko.observable('');
	this.selectedTeammateData = ko.observable(null);
	this.selectedTeammateData.subscribe(function () {
		if (this.selectedTeammateData()) {
			this.selectedTeammateEmail(this.selectedTeammateData().email);
		}
	}, this);

	this.selectAccessDom = ko.observable(null);
	this.lastRecievedSuggestList = [];

	this.isSaving = ko.observable(false);
	this.loadingAddressbookShares = ko.observable(false);
}

_.extendOwn(CAddressbookSharePopup.prototype, CAbstractPopup.prototype);

CAddressbookSharePopup.prototype.PopupTemplate = '%ModuleName%_AddressbookSharePopup';

/**
 * @param {object} addressbook
 */
CAddressbookSharePopup.prototype.onOpen = function (addressbook = null)
{
	if (addressbook === null) {
		this.closePopup();
		return;
	}

	this.addressbook = addressbook;

	this.selectedTeammateEmail('');
	this.selectedTeammateData(null);

	this.fillUpShares();
	this.requestAddressbookShares(function (shares) {
		this.updateAddressbookShares(shares);
		this.fillUpShares();
	}.bind(this));
};

CAddressbookSharePopup.prototype.fillUpShares = function ()
{
	const sharesData = Types.pArray(this.addressbook && this.addressbook.Shares);
	this.shares(_.map(sharesData, function (shareData) {
		return new CAddressbookShareModel(shareData);
	}));
};

CAddressbookSharePopup.prototype.requestAddressbookShares = function (callback)
{
	const addressbook = this.addressbook;
	const parameters = {
		'Id': addressbook.Id
	};
	this.loadingAddressbookShares(true);
	Ajax.send(
		'%ModuleName%',
		'GetSharesForAddressbook',
		parameters,
		function (response, request) {
			this.loadingAddressbookShares(false);
			const shares = response && response.Result;
			if (shares) {
				callback(shares);
			} else {
				callback([]);
			}
		}.bind(this)
	);
};

CAddressbookSharePopup.prototype.updateAddressbookShares = function (newShares)
{
	if (!this.addressbook) {
		return;
	}
	this.addressbook.Shares = Types.pArray(newShares);
};

CAddressbookSharePopup.prototype.getCurrentShares = function ()
{
	return _.map(this.shares(), function (share) {
		const access = share.access();
		if (share.groupId) {
			return {
				PublicId: share.publicId,
				Access: access,
				IsAll: share.isAllUsersGroup,
				IsGroup: true,
				GroupId: share.groupId
			};
		} else {
			const state = {
				PublicId: share.publicId,
				Access: access
			};
			return state;
		}
	}, this);
};

CAddressbookSharePopup.prototype.hasChanges = function ()
{
	var
		addressbook = this.addressbook,
		savedShares = Types.pArray(addressbook && addressbook.Shares),
		currentShares = this.getCurrentShares()
	;
	savedShares = _.sortBy(savedShares, 'PublicId');
	currentShares = _.sortBy(currentShares, 'PublicId');
	return addressbook && (!_.isEqual(savedShares, currentShares) || this.selectedTeammateEmail());
};

CAddressbookSharePopup.prototype.onEscHandler = function ()
{
	this.cancelPopup();
};

CAddressbookSharePopup.prototype.cancelPopup = function ()
{
	if (this.isSaving()) {
		return;
	}

	if (this.hasChanges()) {
		Popups.showPopup(ConfirmPopup, [TextUtils.i18n('COREWEBCLIENT/CONFIRM_DISCARD_CHANGES'), function (discardConfirmed) {
			if (discardConfirmed) {
				this.closePopup();
			}
		}.bind(this)]);
	} else {
		this.closePopup();
	}
};

CAddressbookSharePopup.prototype.autocompleteCallback = function (request, response)
{
	const addressbook = this.addressbook;
	if (!this.addressbook) {
		fResponse([]);
		return;
	}

	const
		owner = App.getUserPublicId(),
		suggestParameters = {
			storage: 'team',
			addContactGroups: false,
			addUserGroups: true,
			exceptEmail: owner
		},
		autocompleteCallback = ModulesManager.run(
			'ContactsWebclient', 'getSuggestionsAutocompleteCallback', [suggestParameters]
		),
		markRecipientsWithKeyCallback = function (recipientList) {
			const ownerLowerCase = owner.toLowerCase();
			const filteredList = recipientList.filter(suggestion => {
				const emailLowerCase = suggestion.email.toLowerCase();
				return ownerLowerCase !== emailLowerCase &&
						!this.shares().find(share => share.publicId.toLowerCase() === emailLowerCase);
			});
			this.lastRecievedSuggestList = filteredList;

			if (filteredList.length > 0) {
				response(filteredList);
			} else {
				response([{label: TextUtils.i18n('%MODULENAME%/INFO_NO_SUGGESTED_CONTACTS'), disabled: true}]);
			}
		}.bind(this)
	;
	if (_.isFunction(autocompleteCallback)) {
		this.selectedTeammateData(null);
		autocompleteCallback(request, markRecipientsWithKeyCallback);
	}
};

CAddressbookSharePopup.prototype.selectAccess = function (hasExpandClass, control)
{
	var hasExpandClass = this.selectAccessDom().hasClass('expand');
	if (hasExpandClass) {
		this.selectAccessDom().removeClass('expand');
	} else {
		if (this.selectedTeammateData() === null) {
			var
				enteredTeammate = this.selectedTeammateEmail(),
				enteredTeammateLower = enteredTeammate.toLowerCase()
			;
			if (enteredTeammate === '') {
				var
					alertText = TextUtils.i18n('%MODULENAME%/WARNING_SELECT_TEAMMATE'),
					alertCallback = function () {
						this.selectedTeammateDom().focus();
						this.selectedTeammateDom().autocomplete('option', 'minLength', 0); //for triggering search on empty field
						this.selectedTeammateDom().autocomplete('search');
						this.selectedTeammateDom().autocomplete('option', 'minLength', 1);
					}.bind(this)
				;
				Popups.showPopup(AlertPopup, [alertText, alertCallback]);
			} else {
				var teammateData = _.find(this.lastRecievedSuggestList, function (data) {
					return data.value.toLowerCase() === enteredTeammateLower
							|| data.email.toLowerCase() === enteredTeammateLower
							|| data.name.toLowerCase() === enteredTeammateLower;
				}.bind(this));
				if (teammateData) {
					this.selectedTeammateData(teammateData);
				} else {
					teammateData = _.find(this.lastRecievedSuggestList, function (data) {
						return data.value.toLowerCase().indexOf(enteredTeammateLower) !== -1;
					}.bind(this));
					if (teammateData) {
						var
							confirmText = TextUtils.i18n('%MODULENAME%/CONFIRM_ADD_TEAMMATE', {'EMAIL': teammateData.email}),
							confirmCallback = function (addConfirmed) {
								if (addConfirmed) {
									this.selectedTeammateEmail(teammateData.email);
									this.selectedTeammateData(teammateData);
									this.selectAccessDom().addClass('expand');
								} else {
									this.selectedTeammateDom().focus();
									this.selectedTeammateDom().autocomplete('search');
								}
							}.bind(this),
							yesButtonText = TextUtils.i18n('%MODULENAME%/ACTION_YES'),
							noButtonText = TextUtils.i18n('%MODULENAME%/ACTION_NO')
						;
						Popups.showPopup(ConfirmPopup, [confirmText, confirmCallback, '', yesButtonText, noButtonText]);
					} else {
						var
							alertText = TextUtils.i18n('%MODULENAME%/WARNING_NO_TEAMMATE_SELECTED', {'EMAIL': enteredTeammate}),
							alertCallback = function () {
								this.selectedTeammateDom().focus();
								this.selectedTeammateDom().autocomplete('search');
							}.bind(this)
						;
						Popups.showPopup(AlertPopup, [alertText, alertCallback]);
					}
				}
			}
		}
		if (this.selectedTeammateData() !== null) {
			this.selectAccessDom().addClass('expand');
		}
	}
};

CAddressbookSharePopup.prototype.addNewShare = function (access)
{
	if (!this.selectedTeammateData()) {
		this.selectedTeammateDom().focus();
		this.selectedTeammateDom().autocomplete('search');
		return;
	}

	this.shares.push(new CAddressbookShareModel({
		PublicId: this.selectedTeammateData().email,
		GroupId: this.selectedTeammateData().groupId,
		IsAll: this.selectedTeammateData().isAllUsersGroup,
		Access: access
	}));

	this.selectedTeammateData(null);
	this.selectedTeammateEmail('');
	var
		scrollArea = this.sharesScrollAreaDom(),
		listArea = scrollArea !== null ? scrollArea.find('.shares_list') : null
	;
	if (listArea !== null) {
		scrollArea.scrollTop(listArea.height() - scrollArea.height());
	}
};

CAddressbookSharePopup.prototype.deleteShare = function (publicId, groupId)
{
	if (groupId) {
		this.shares(_.filter(this.shares(), function (share) {
			return share.groupId !== groupId;
		}));
	} else {
		this.shares(_.filter(this.shares(), function (share) {
			return share.publicId !== publicId;
		}));
	}
};

CAddressbookSharePopup.prototype.checkAndSaveShares = function ()
{
	if (this.isSaving()) {
		return;
	}

	this.isSaving(true);
	this.requestAddressbookShares(function (sharesFromServer) {
		this.isSaving(false);
		const addressbook = this.addressbook;
		let savedShares = Types.pArray(addressbook && addressbook.Shares);
		sharesFromServer = _.sortBy(sharesFromServer, 'PublicId');
		savedShares = _.sortBy(savedShares, 'PublicId');
		if (_.isEqual(savedShares, sharesFromServer)) {
			this.saveShares();
		} else {
			const
				alertText = TextUtils.i18n('%MODULENAME%/WARNING_SHARES_CHANGED_BY_OTHER_USER'),
				alertCallback = function () {
					this.updateAddressbookShares(sharesFromServer);
					this.fillUpShares();
				}.bind(this)
			;
			Popups.showPopup(AlertPopup, [alertText, alertCallback]);
		}
	}.bind(this));
};

CAddressbookSharePopup.prototype.saveShares = function ()
{
	if (this.isSaving()) {
		return;
	}

	if (this.selectedTeammateEmail()) {
		var
			confirmText = TextUtils.i18n('%MODULENAME%/CONFIRM_SAVE_SHARES_WITHOUT_LAST_EMAIL', { 'EMAIL': this.selectedTeammateEmail() }),
			confirmCallback = function (saveConfirmed) {
				if (saveConfirmed) {
					this.confirmedSaveShares();
				} else {
					setTimeout(this.selectAccess.bind(this));
				}
			}.bind(this)
		;
		Popups.showPopup(ConfirmPopup, [confirmText, confirmCallback]);
	} else {
		this.confirmedSaveShares();
	}

};

CAddressbookSharePopup.prototype.confirmedSaveShares = function ()
{
	if (this.isSaving()) {
		return;
	}

	const
		shares = this.getCurrentShares(),
		parameters = {
			'Id': this.addressbook.Id,
			'Shares': shares
		}
	;

	this.isSaving(true);
	Ajax.send(
		'%ModuleName%',
		'UpdateAddressbookShare',
		parameters,
		_.bind(this.onUpdateShareResponse, this)
	);
};

CAddressbookSharePopup.prototype.onUpdateShareResponse = function (response, request)
{
	this.isSaving(false);
	if (response.Result) {
		this.requestAddressbookShares(function (shares) {
			this.updateAddressbookShares(shares);
			Screens.showReport(TextUtils.i18n('%MODULENAME%/INFO_SHARING_STATUS_UPDATED'));
			this.addressbook = null;
			this.closePopup();
		}.bind(this));
	} else {
		Api.showErrorByCode(response);
	}
};

module.exports = new CAddressbookSharePopup();
