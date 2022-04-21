'use strict';

module.exports = function (oAppData) {
	const App = require('%PathToCoreWebclientModule%/js/App.js');

	if (App.isUserNormalOrTenant()) {
		return {
			getAddressbookSharePopup: function () {
				return require('modules/%ModuleName%/js/popups/AddressbookSharePopup.js');
			}
		};
	}

	return null;
};
