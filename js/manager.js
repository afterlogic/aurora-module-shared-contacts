'use strict';

module.exports = function (appData) {
	const App = require('%PathToCoreWebclientModule%/js/App.js');

	if (App.isUserNormalOrTenant()) {
		return {
			start: function () {
				const allowAddressBooksManagement = !!(appData.Contacts && appData.Contacts.AllowAddressBooksManagement);
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
