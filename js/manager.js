'use strict';

module.exports = function (appData) {
	const App = require('%PathToCoreWebclientModule%/js/App.js');

	if (App.isUserNormalOrTenant()) {
		return {
			start: function () {
				console.log('appData', appData);
				console.log('appData.Contacts', appData.Contacts);
				console.log('appData.ContactsWebclient', appData.ContactsWebclient);
				const allowAddressBooksManagement = !!(appData.Contacts && appData.Contacts.AllowAddressBooksManagement);
				console.log('allowAddressBooksManagement', allowAddressBooksManagement);
				if (allowAddressBooksManagement) {
					$('html').addClass('shared-addressbooks');
				}
			},
			getShareAddressbookControlView: function () {
				return require('modules/%ModuleName%/js/views/ShareAddressbookControlView.js');
			}
		};
	}

	return null;
};
