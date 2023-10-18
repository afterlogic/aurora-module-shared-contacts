'use strict';

const
	TextUtils = require('%PathToCoreWebclientModule%/js/utils/Text.js'),

	Ajax = require('%PathToCoreWebclientModule%/js/Ajax.js'),
	Api = require('%PathToCoreWebclientModule%/js/Api.js'),
	ConfirmPopup = require('%PathToCoreWebclientModule%/js/popups/ConfirmPopup.js'),
	Popups = require('%PathToCoreWebclientModule%/js/Popups.js'),

	AddressbookSharePopup = require('modules/%ModuleName%/js/popups/AddressbookSharePopup.js')
;

function CShareAddressbookControlView()
{
	
}

CShareAddressbookControlView.prototype.ViewTemplate = '%ModuleName%_ShareAddressbookControlView';

CShareAddressbookControlView.prototype.openAddressbookSharePopup = function (addressbook)
{
	if (AddressbookSharePopup && addressbook) {
		Popups.showPopup(AddressbookSharePopup, [addressbook]);
	}
};

CShareAddressbookControlView.prototype.leaveAddressbookShare = function (addressbook)
{
	const
		addressbookName = addressbook.DisplayName || '',
		confirm = TextUtils.i18n('%MODULENAME%/CONFIRM_LEAVE_SHARE', {'NAME': addressbookName}),
		confirmedLeaveShare = this.confirmedLeaveShare.bind(this, addressbook),
		okButtonText = TextUtils.i18n('%MODULENAME%/ACTION_LEAVE_SHARE')
	;
	Popups.showPopup(ConfirmPopup, [confirm, confirmedLeaveShare, '', okButtonText]);
};

CShareAddressbookControlView.prototype.confirmedLeaveShare = function (addressbook, confirmed)
{
	if (confirmed) {
		const parameters = {
			'Id': addressbook.Id
		};
		Ajax.send('%ModuleName%', 'LeaveShare', parameters, this.onLeaveShareResponse, this);
	}
};

CShareAddressbookControlView.prototype.onLeaveShareResponse = function (response, request)
{
	if (response && response.Result) {
		Ajax.send('Contacts', 'GetStorages');
	} else {
		Api.showErrorByCode(response);
	}
};

module.exports = new CShareAddressbookControlView();
