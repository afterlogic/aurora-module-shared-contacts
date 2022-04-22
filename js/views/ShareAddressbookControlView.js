'use strict';

const
	Popups = require('%PathToCoreWebclientModule%/js/Popups.js'),

	AddressbookSharePopup = require('modules/%ModuleName%/js/popups/AddressbookSharePopup.js')
;

function CShareAddressbookControlView()
{
	
}

CShareAddressbookControlView.prototype.ViewTemplate = '%ModuleName%_ShareAddressbookControlView';

CShareAddressbookControlView.prototype.openAddressbookSharePopup = function (addressbook)
{
	console.log('openAddressbookSharePopup', addressbook);
	if (AddressbookSharePopup && addressbook) {
		Popups.showPopup(AddressbookSharePopup, [addressbook]);
	}
};

CShareAddressbookControlView.prototype.leaveAddressbookShare = function (addressbook)
{
	console.log('leaveAddressbookShare', addressbook);
};

module.exports = new CShareAddressbookControlView();
