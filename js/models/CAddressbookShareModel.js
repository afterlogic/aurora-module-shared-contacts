'use strict';

var
	ko = require('knockout'),

	TextUtils = require('%PathToCoreWebclientModule%/js/utils/Text.js'),
	Types = require('%PathToCoreWebclientModule%/js/utils/Types.js')
;

/**
 * @constructor
 * @param {object} oData
 */
function CAddressbookShareModel(oData)
{
	this.publicId = Types.pString(oData.PublicId);
	this.groupId = Types.pInt(oData.GroupId);
	this.isAllUsersGroup = Types.pBool(oData.IsAll);

	this.access = ko.observable(Types.pInt(oData.Access));
	this.accessText = ko.computed(function () {
		switch (this.access()) {
			case Enums.SharedAddressbookAccess.Write: return TextUtils.i18n('%MODULENAME%/LABEL_WRITE_ACCESS');
			case Enums.SharedAddressbookAccess.Read: return TextUtils.i18n('%MODULENAME%/LABEL_READ_ACCESS');
			case Enums.SharedAddressbookAccess.NoAccess: return TextUtils.i18n('%MODULENAME%/LABEL_NOSHARE_ACCESS');
		}
	}, this);
}

module.exports = CAddressbookShareModel;
